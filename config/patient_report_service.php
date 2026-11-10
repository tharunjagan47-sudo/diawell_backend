<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

/**
 * Shared patient clinical report builder for JSON exports, PDF generation, and CSV downloads.
 */
function buildAndRespondPatientReport(PDO $pdo): void {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        respond(false, "Method not allowed. Use GET.", 405);
    }

    $userId = getUserId();
    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in.", 401);
    }

    $rawDays = strtolower(trim((string) ($_GET["days"] ?? $_GET["range"] ?? "30")));
    $format = strtolower(trim((string) ($_GET["format"] ?? "json")));
    $rawDownload = isset($_GET["raw"]) && ($_GET["raw"] === "1" || $_GET["raw"] === "true");

    $days = 0;
    $rangeCode = "all";
    $periodLabel = "All Time";
    if (strpos($rawDays, "7") !== false) {
        $days = 7;
        $rangeCode = "7";
        $periodLabel = "Last 7 Days";
    } elseif (strpos($rawDays, "30") !== false) {
        $days = 30;
        $rangeCode = "30";
        $periodLabel = "Last 30 Days";
    } elseif (strpos($rawDays, "90") !== false) {
        $days = 90;
        $rangeCode = "90";
        $periodLabel = "Last 3 Months (90 Days)";
    }

    $endDate = date("Y-m-d");
    $startDate = ($days > 0) ? date("Y-m-d", strtotime("-{$days} days")) : "All Recorded";

    try {
        // 1. Inspect available columns on `users` table dynamically
        $userCols = [];
        $desc = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($desc as $col) {
            $userCols[strtolower($col["Field"])] = true;
        }

        $optionalUserCols = [];
        foreach (["phone", "gender", "blood_group"] as $optCol) {
            if (isset($userCols[$optCol])) {
                $optionalUserCols[] = $optCol;
            }
        }
        $optSql = !empty($optionalUserCols) ? (", " . implode(", ", $optionalUserCols)) : "";

        $uStmt = $pdo->prepare(
            "SELECT id, full_name, email, diabetes_type, age, height_cm, weight_kg,
                    current_glucose_mg_dl, target_glucose_mg_dl, created_at{$optSql}
             FROM users
             WHERE id = :id
             LIMIT 1"
        );
        $uStmt->execute([":id" => $userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            respond(false, "User not found.", 404);
        }

        $targetGlucose = ($user["target_glucose_mg_dl"] !== null && (float)$user["target_glucose_mg_dl"] > 0)
            ? (float)$user["target_glucose_mg_dl"]
            : 120.0;

        $patientProfile = [
            "name" => $user["full_name"] ?? "Patient",
            "email" => $user["email"] ?? "",
            "phone" => $user["phone"] ?? "",
            "age" => $user["age"] !== null ? (int) $user["age"] : null,
            "gender" => $user["gender"] ?? "",
            "diabetes_type" => !empty($user["diabetes_type"]) ? $user["diabetes_type"] : "Type 2",
            "weight_kg" => $user["weight_kg"] !== null ? (float) $user["weight_kg"] : null,
            "weight" => $user["weight_kg"] !== null ? (float) $user["weight_kg"] : null,
            "height_cm" => $user["height_cm"] !== null ? (float) $user["height_cm"] : null,
            "height" => $user["height_cm"] !== null ? (float) $user["height_cm"] : null,
            "blood_group" => $user["blood_group"] ?? "",
            "current_glucose" => $user["current_glucose_mg_dl"] !== null ? (float) $user["current_glucose_mg_dl"] : null,
            "target_glucose" => $targetGlucose
        ];

        $dateParams = [":user_id" => $userId];
        if ($days > 0) {
            $dateParams[":start_date"] = $startDate;
        }

        // 2. Blood Glucose Logs
        $glucoseLogs = [];
        try {
            $gSql = "SELECT id, reading_date, reading_time, glucose_value, reading_type, notes, created_at
                     FROM glucose_readings
                     WHERE user_id = :user_id" . ($days > 0 ? " AND reading_date >= :start_date" : "") . "
                     ORDER BY reading_date DESC, reading_time DESC";
            $gStmt = $pdo->prepare($gSql);
            $gStmt->execute($dateParams);
            $rawGlucose = $gStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rawGlucose as $g) {
                $val = (float) ($g["glucose_value"] ?? 0);
                $type = trim((string) ($g["reading_type"] ?? "Random"));
                if ($val < 70) {
                    $gStatus = "Low (Hypo)";
                } elseif (strcasecmp($type, "Post-Meal") === 0) {
                    $gStatus = ($val <= 180) ? "In Target" : (($val <= 220) ? "Elevated" : "High");
                } else {
                    $gStatus = ($val <= 140) ? "In Target" : (($val <= 180) ? "Elevated" : "High");
                }
                $loggedAt = trim(($g["reading_date"] ?? "") . " " . date("g:i A", strtotime($g["reading_time"] ?? "08:00:00")));
                $glucoseLogs[] = [
                    "id" => (int) ($g["id"] ?? 0),
                    "reading" => $val,
                    "glucose_level" => $val,
                    "glucose_value" => $val,
                    "test_type" => $type,
                    "reading_type" => $type,
                    "reading_date" => $g["reading_date"] ?? "",
                    "reading_time" => $g["reading_time"] ?? "",
                    "logged_at" => $loggedAt,
                    "status" => $gStatus,
                    "notes" => $g["notes"] ?? ""
                ];
            }
        } catch (Throwable $ge) {
            error_log("Report glucose query error: " . $ge->getMessage());
        }

        // 3. Active Medications
        $medLogs = [];
        $takenMeds = 0;
        try {
            $mSql = "SELECT id, name, dose, reminder_time, reminder_date, timing, frequency, status, notes
                     FROM medications
                     WHERE user_id = :user_id AND (is_active = 1 OR is_active IS NULL)" .
                     ($days > 0 ? " AND (reminder_date >= :start_date OR LOWER(frequency) = 'daily')" : "") . "
                     ORDER BY reminder_time ASC, id DESC";
            $mStmt = $pdo->prepare($mSql);
            $mStmt->execute($dateParams);
            $rawMeds = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rawMeds as $m) {
                $st = ucfirst(strtolower(trim((string) ($m["status"] ?? "Upcoming"))));
                if ($st === "Taken") {
                    $takenMeds++;
                }
                $fmtTime = date("g:i A", strtotime($m["reminder_time"] ?? "08:00:00"));
                $medLogs[] = [
                    "id" => (int) ($m["id"] ?? 0),
                    "medicine_name" => $m["name"] ?? "",
                    "name" => $m["name"] ?? "",
                    "dosage" => $m["dose"] ?? "",
                    "dose" => $m["dose"] ?? "",
                    "frequency" => $m["frequency"] ?? "Daily",
                    "reminder_time" => $fmtTime,
                    "reminder_date" => $m["reminder_date"] ?? "",
                    "meal_timing" => $m["timing"] ?? "After Meal",
                    "timing" => $m["timing"] ?? "After Meal",
                    "status" => $st,
                    "notes" => $m["notes"] ?? ""
                ];
            }
        } catch (Throwable $me) {
            error_log("Report meds query error: " . $me->getMessage());
        }

        // 4. Diet & Nutrition Logs
        $foodLogs = [];
        $totalCalories = 0;
        $totalCarbs = 0.0;
        $mealDates = [];
        try {
            $fSql = "SELECT id, log_date, log_time, meal_category, food_name, `portion`, calories, carbs_g, protein_g, fat_g
                     FROM food_logs
                     WHERE user_id = :user_id" . ($days > 0 ? " AND log_date >= :start_date" : "") . "
                     ORDER BY log_date DESC, log_time ASC";
            $fStmt = $pdo->prepare($fSql);
            $fStmt->execute($dateParams);
            $rawFood = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rawFood as $f) {
                $cal = (int) ($f["calories"] ?? 0);
                $carbs = (float) ($f["carbs_g"] ?? 0);
                $totalCalories += $cal;
                $totalCarbs += $carbs;
                if (!empty($f["log_date"])) {
                    $mealDates[$f["log_date"]] = true;
                }
                $loggedAt = trim(($f["log_date"] ?? "") . " " . date("g:i A", strtotime($f["log_time"] ?? "12:00:00")));
                $foodLogs[] = [
                    "id" => (int) ($f["id"] ?? 0),
                    "meal_type" => $f["meal_category"] ?? "",
                    "meal_category" => $f["meal_category"] ?? "",
                    "food_items" => $f["food_name"] ?? "",
                    "food_name" => $f["food_name"] ?? "",
                    "portion" => $f["portion"] ?? "",
                    "calories" => $cal,
                    "carbs" => $carbs,
                    "carbs_g" => $carbs,
                    "protein_g" => (float) ($f["protein_g"] ?? 0),
                    "fat_g" => (float) ($f["fat_g"] ?? 0),
                    "log_date" => $f["log_date"] ?? "",
                    "log_time" => $f["log_time"] ?? "",
                    "logged_at" => $loggedAt
                ];
            }
        } catch (Throwable $fe) {
            error_log("Report food query error: " . $fe->getMessage());
        }

        // 5. Physical Activity Logs
        $activityLogs = [];
        $totalActMins = 0;
        $totalActCals = 0;
        try {
            $aSql = "SELECT id, activity_date, activity_time, activity_type, duration_mins, distance_km, calories_kcal, glucose_before, glucose_after, notes
                     FROM activity_logs
                     WHERE user_id = :user_id" . ($days > 0 ? " AND activity_date >= :start_date" : "") . "
                     ORDER BY activity_date DESC, activity_time DESC";
            $aStmt = $pdo->prepare($aSql);
            $aStmt->execute($dateParams);
            $rawAct = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rawAct as $a) {
                $dur = (int) ($a["duration_mins"] ?? 0);
                $calB = (int) ($a["calories_kcal"] ?? 0);
                $totalActMins += $dur;
                $totalActCals += $calB;
                $notesStr = (string) ($a["notes"] ?? "");
                $metVal = 3.5;
                if (preg_match('/([\d.]+)\s*MET/i', $notesStr, $mMatch)) {
                    $metVal = (float) $mMatch[1];
                } elseif (stripos((string)($a["activity_type"] ?? ""), "run") !== false) {
                    $metVal = 7.0;
                } elseif (stripos((string)($a["activity_type"] ?? ""), "cycl") !== false) {
                    $metVal = 5.5;
                } elseif (stripos((string)($a["activity_type"] ?? ""), "yoga") !== false) {
                    $metVal = 2.8;
                }
                $loggedAt = trim(($a["activity_date"] ?? "") . " " . date("g:i A", strtotime($a["activity_time"] ?? "08:00:00")));
                $activityLogs[] = [
                    "id" => (int) ($a["id"] ?? 0),
                    "activity_name" => $a["activity_type"] ?? "",
                    "activity_type" => $a["activity_type"] ?? "",
                    "duration_mins" => $dur,
                    "distance_km" => (float) ($a["distance_km"] ?? 0),
                    "calories_burned" => $calB,
                    "calories_kcal" => $calB,
                    "met_value" => round($metVal, 1),
                    "glucose_before" => $a["glucose_before"] !== null ? (float) $a["glucose_before"] : null,
                    "glucose_after" => $a["glucose_after"] !== null ? (float) $a["glucose_after"] : null,
                    "activity_date" => $a["activity_date"] ?? "",
                    "activity_time" => $a["activity_time"] ?? "",
                    "logged_at" => $loggedAt,
                    "notes" => $notesStr
                ];
            }
        } catch (Throwable $ae) {
            error_log("Report activity query error: " . $ae->getMessage());
        }

        // 6. Compute Clinical Summary Metrics
        $gCount = count($glucoseLogs);
        $avgGlucose = 0.0;
        $minGlucose = 0.0;
        $maxGlucose = 0.0;
        $inTargetCount = 0;

        if ($gCount > 0) {
            $vals = array_column($glucoseLogs, "glucose_value");
            $avgGlucose = round(array_sum($vals) / $gCount, 1);
            $minGlucose = round(min($vals), 1);
            $maxGlucose = round(max($vals), 1);
            foreach ($glucoseLogs as $gItem) {
                if ($gItem["status"] === "In Target") {
                    $inTargetCount++;
                }
            }
        } elseif ($patientProfile["current_glucose"] !== null && $patientProfile["current_glucose"] > 0) {
            $avgGlucose = round((float) $patientProfile["current_glucose"], 1);
            $minGlucose = $avgGlucose;
            $maxGlucose = $avgGlucose;
        }

        $inTargetPercent = $gCount > 0 ? (int) round(($inTargetCount / $gCount) * 100) : 0;
        $estHba1c = $avgGlucose > 0 ? round(($avgGlucose + 46.7) / 28.7, 1) : 0.0;

        $medCount = count($medLogs);
        $adherencePercent = $medCount > 0 ? (int) round(($takenMeds / $medCount) * 100) : 0;

        $mealCount = count($foodLogs);
        $distinctMealDays = max(1, count($mealDates));
        $avgDailyCalories = $mealCount > 0 ? (int) round($totalCalories / $distinctMealDays) : 0;

        if ($gCount === 0 && $medCount === 0 && $mealCount === 0 && count($activityLogs) === 0) {
            $careStatus = "Baseline Profile • Start logging to track trends";
        } elseif ($gCount === 0) {
            $careStatus = ($medCount > 0 && $adherencePercent >= 70)
                ? "Active Care Plan • Good Medication Adherence"
                : "Active Routine • Log glucose readings for trend analysis";
        } elseif ($inTargetPercent >= 70 && ($medCount === 0 || $adherencePercent >= 80)) {
            $careStatus = "Well-Controlled • Consistent Care Routine";
        } elseif ($gCount > 0 && ($avgGlucose > 180 || $inTargetPercent < 50)) {
            $careStatus = "Needs Clinical Review • Elevated Glycemic Trend";
        } else {
            $careStatus = "Stable • Moderate Glycemic Control";
        }

        // Optional direct raw CSV stream ONLY when explicitly requested via ?raw=1&format=csv
        if ($rawDownload && $format === "csv") {
            header("Content-Type: text/csv");
            header("Content-Disposition: attachment; filename=\"DiaWell_Report_User_{$userId}_" . date("Ymd") . ".csv\"");
            $output = fopen("php://output", "w");
            fputcsv($output, ["=== DiaWell Clinical Health Report ==="]);
            fputcsv($output, ["Patient", $patientProfile["name"], "Diabetes Type", $patientProfile["diabetes_type"], "Period", $periodLabel]);
            fputcsv($output, []);
            fputcsv($output, ["=== Blood Glucose Logs ==="]);
            fputcsv($output, ["Logged At", "Reading (mg/dL)", "Test Type", "Status", "Notes"]);
            foreach ($glucoseLogs as $g) {
                fputcsv($output, [$g["logged_at"], $g["reading"], $g["test_type"], $g["status"], $g["notes"]]);
            }
            fputcsv($output, []);
            fputcsv($output, ["=== Medications ==="]);
            fputcsv($output, ["Medicine", "Dosage", "Frequency", "Time", "Meal Timing", "Status"]);
            foreach ($medLogs as $m) {
                fputcsv($output, [$m["medicine_name"], $m["dosage"], $m["frequency"], $m["reminder_time"], $m["meal_timing"], $m["status"]]);
            }
            fputcsv($output, []);
            fputcsv($output, ["=== Diet & Nutrition Logs ==="]);
            fputcsv($output, ["Logged At", "Meal Type", "Food Items", "Calories (kcal)", "Carbs (g)"]);
            foreach ($foodLogs as $f) {
                fputcsv($output, [$f["logged_at"], $f["meal_type"], $f["food_items"], $f["calories"], $f["carbs"]]);
            }
            fputcsv($output, []);
            fputcsv($output, ["=== Physical Activity Logs ==="]);
            fputcsv($output, ["Logged At", "Activity", "Duration (mins)", "Calories Burned", "MET"]);
            foreach ($activityLogs as $a) {
                fputcsv($output, [$a["logged_at"], $a["activity_name"], $a["duration_mins"], $a["calories_burned"], $a["met_value"]]);
            }
            fclose($output);
            exit;
        }

        // Standard JSON payload consumed by Android Clinical Report Generator
        respond(true, "Clinical patient report generated successfully.", 200, [
            "user_id" => (int) $userId,
            "export_date" => date("Y-m-d H:i:s"),
            "range" => $rangeCode,
            "days" => $rawDays,
            "patient" => $patientProfile,
            "summary" => [
                "period_label" => $periodLabel,
                "start_date" => $startDate,
                "end_date" => $endDate,
                "total_glucose_readings" => $gCount,
                "average_glucose" => $avgGlucose,
                "min_glucose" => $minGlucose,
                "max_glucose" => $maxGlucose,
                "in_target_percent" => $inTargetPercent,
                "estimated_hba1c" => $estHba1c,
                "total_meals_logged" => $mealCount,
                "total_calories" => $totalCalories,
                "average_daily_calories" => $avgDailyCalories,
                "total_carbs_g" => round($totalCarbs, 1),
                "total_medications" => $medCount,
                "taken_medications" => $takenMeds,
                "adherence_percent" => $adherencePercent,
                "total_workouts" => count($activityLogs),
                "total_activity_mins" => $totalActMins,
                "total_activity_calories" => $totalActCals,
                "care_status" => $careStatus
            ],
            "glucose_readings" => $glucoseLogs,
            "medications" => $medLogs,
            "food_logs" => $foodLogs,
            "activity_logs" => $activityLogs
        ]);

    } catch (PDOException $e) {
        error_log("Patient report export error: " . $e->getMessage());
        respond(false, "Database error exporting patient health report.", 500);
    }
}
