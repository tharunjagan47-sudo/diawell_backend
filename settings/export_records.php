<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$userId = getUserId();
if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

$format = strtolower(trim($_GET["format"] ?? "json"));
$range = strtolower(trim($_GET["range"] ?? "all"));

$days = 0;
if (strpos($range, "7") !== false) $days = 7;
elseif (strpos($range, "30") !== false) $days = 30;
elseif (strpos($range, "90") !== false) $days = 90;

try {
    // 1. Fetch user profile
    $uStmt = $pdo->prepare("SELECT id, full_name, email, diabetes_type, age, height_cm, weight_kg, current_glucose_mg_dl, target_glucose_mg_dl, created_at FROM users WHERE id = :id LIMIT 1");
    $uStmt->execute([":id" => $userId]);
    $user = $uStmt->fetch();

    if (!$user) {
        respond(false, "User not found.", 404);
    }

    $dateFilter = "";
    $params = [":user_id" => $userId];
    if ($days > 0) {
        $startDate = date("Y-m-d", strtotime("-{$days} days"));
        $dateFilter = " AND reading_date >= :start_date";
        $params[":start_date"] = $startDate;
    }

    // 2. Fetch Glucose readings
    $gSql = "SELECT reading_date, reading_time, glucose_value, reading_type, notes FROM glucose_readings WHERE user_id = :user_id" . ($days > 0 ? " AND reading_date >= :start_date" : "") . " ORDER BY reading_date DESC, reading_time DESC";
    $gStmt = $pdo->prepare($gSql);
    $gStmt->execute($params);
    $glucoseLogs = $gStmt->fetchAll();

    // 3. Fetch Food logs
    $fSql = "SELECT log_date, log_time, meal_category, food_name, `portion`, calories, carbs_g, protein_g, fat_g FROM food_logs WHERE user_id = :user_id" . ($days > 0 ? " AND log_date >= :start_date" : "") . " ORDER BY log_date DESC, log_time ASC";
    $fStmt = $pdo->prepare($fSql);
    $fStmt->execute($params);
    $foodLogs = $fStmt->fetchAll();

    // 4. Fetch Medications
    $mStmt = $pdo->prepare("SELECT name, dose, reminder_time, timing, frequency, status, notes FROM medications WHERE user_id = :user_id ORDER BY reminder_time ASC");
    $mStmt->execute([":user_id" => $userId]);
    $medLogs = $mStmt->fetchAll();

    // 5. Fetch Activities
    $aSql = "SELECT activity_date, activity_time, activity_type, duration_mins, distance_km, calories_kcal, glucose_before, glucose_after, notes FROM activity_logs WHERE user_id = :user_id" . ($days > 0 ? " AND activity_date >= :start_date" : "") . " ORDER BY activity_date DESC, activity_time DESC";
    $aStmt = $pdo->prepare($aSql);
    $aStmt->execute($params);
    $activityLogs = $aStmt->fetchAll();

    // Export CSV if requested
    if ($format === "csv") {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=\"DiaWell_Export_User_{$userId}_" . date("Ymd") . ".csv\"");
        
        $output = fopen("php://output", "w");
        fputcsv($output, ["=== DiaWell Patient Profile ==="]);
        fputcsv($output, ["Name", $user["full_name"]]);
        fputcsv($output, ["Email", $user["email"]]);
        fputcsv($output, ["Diabetes Type", $user["diabetes_type"]]);
        fputcsv($output, ["Export Date", date("Y-m-d H:i:s")]);
        fputcsv($output, []);

        fputcsv($output, ["=== Glucose Readings ==="]);
        fputcsv($output, ["Date", "Time", "Glucose (mg/dL)", "Type", "Notes"]);
        foreach ($glucoseLogs as $g) {
            fputcsv($output, [$g["reading_date"], $g["reading_time"], $g["glucose_value"], $g["reading_type"], $g["notes"]]);
        }
        fputcsv($output, []);

        fputcsv($output, ["=== Food Logs ==="]);
        fputcsv($output, ["Date", "Time", "Category", "Food Name", "Portion", "Calories (kcal)", "Carbs (g)", "Protein (g)", "Fat (g)"]);
        foreach ($foodLogs as $f) {
            fputcsv($output, [$f["log_date"], $f["log_time"], $f["meal_category"], $f["food_name"], $f["portion"], $f["calories"], $f["carbs_g"], $f["protein_g"], $f["fat_g"]]);
        }
        fputcsv($output, []);

        fputcsv($output, ["=== Medications ==="]);
        fputcsv($output, ["Name", "Dose", "Time", "Timing", "Frequency", "Status", "Notes"]);
        foreach ($medLogs as $m) {
            fputcsv($output, [$m["name"], $m["dose"], $m["reminder_time"], $m["timing"], $m["frequency"], $m["status"], $m["notes"]]);
        }
        fputcsv($output, []);

        fputcsv($output, ["=== Activities ==="]);
        fputcsv($output, ["Date", "Time", "Type", "Duration (mins)", "Distance (km)", "Calories Burned (kcal)", "Glucose Before", "Glucose After", "Notes"]);
        foreach ($activityLogs as $a) {
            fputcsv($output, [$a["activity_date"], $a["activity_time"], $a["activity_type"], $a["duration_mins"], $a["distance_km"], $a["calories_kcal"], $a["glucose_before"], $a["glucose_after"], $a["notes"]]);
        }
        fclose($output);
        exit;
    }

    // Default JSON export
    respond(true, "Health records exported successfully.", 200, [
        "export_metadata" => [
            "user_id" => $userId,
            "patient_name" => $user["full_name"],
            "email" => $user["email"],
            "diabetes_type" => $user["diabetes_type"],
            "exported_at" => date("Y-m-d H:i:s"),
            "range_filter" => $range
        ],
        "glucose_readings_count" => count($glucoseLogs),
        "glucose_readings" => $glucoseLogs,
        "food_logs_count" => count($foodLogs),
        "food_logs" => $foodLogs,
        "medications_count" => count($medLogs),
        "medications" => $medLogs,
        "activity_logs_count" => count($activityLogs),
        "activity_logs" => $activityLogs
    ]);

} catch (PDOException $e) {
    error_log("Export records error: " . $e->getMessage());
    respond(false, "Database error exporting health records.", 500);
}
