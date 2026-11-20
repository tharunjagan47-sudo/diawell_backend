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

$today = date("Y-m-d");

try {
    // 1. User profile
    $userStmt = $pdo->prepare(
        "SELECT id, full_name, email, diabetes_type, current_glucose_mg_dl, target_glucose_mg_dl, 
                target_fasting_glucose, target_postmeal_glucose, profile_photo, profile_pic 
         FROM users 
         WHERE id = :id 
         LIMIT 1"
    );
    $userStmt->execute([":id" => $userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        respond(false, "User not found.", 404);
    }

    $photoUrl = getProfilePhotoUrl($user["profile_photo"] ?? null);

    // 2. Latest Glucose reading
    $gStmt = $pdo->prepare(
        "SELECT glucose_value, reading_type, reading_date, reading_time 
         FROM glucose_readings 
         WHERE user_id = :user_id 
         ORDER BY reading_date DESC, reading_time DESC 
         LIMIT 1"
    );
    $gStmt->execute([":user_id" => $userId]);
    $latestGlucose = $gStmt->fetch();

    $targetFast = isset($user["target_fasting_glucose"]) && $user["target_fasting_glucose"] ? (float)$user["target_fasting_glucose"] : (float)($user["target_glucose_mg_dl"] ?? 100.0);
    $targetPost = isset($user["target_postmeal_glucose"]) && $user["target_postmeal_glucose"] ? (float)$user["target_postmeal_glucose"] : 140.0;
    $targetVal = $targetFast;

    if ($latestGlucose) {
        $glucoseVal = (float) $latestGlucose["glucose_value"];
        $glucoseStatus = ($glucoseVal <= ($targetVal + 20) && $glucoseVal >= ($targetVal - 30)) ? "In Target Range" : "Needs Attention";
        $glucoseTime = $latestGlucose["reading_type"] . " • " . date("g:i A", strtotime($latestGlucose["reading_time"]));
        $glucoseValStr = round($glucoseVal) . " mg/dL";
        $glucoseValNum = $glucoseVal;
        $glucoseStatusBg = ($glucoseStatus === "In Target Range") ? "#E8F5E9" : "#FFF3E0";
        $glucoseStatusColor = ($glucoseStatus === "In Target Range") ? "#2E7D32" : "#E65100";
        $targetBadge = ($glucoseStatus === "In Target Range") ? "🎯 Target: " . round($targetVal) . " mg/dL (Met)" : "🎯 Target: " . round($targetVal) . " mg/dL";
    } else {
        $glucoseVal = null;
        $glucoseStatus = "No Readings Logged";
        $glucoseTime = "Tap to log your first reading";
        $glucoseValStr = "-- mg/dL";
        $glucoseValNum = null;
        $glucoseStatusBg = "#F3F4F6";
        $glucoseStatusColor = "#6B7280";
        $targetBadge = "🎯 Target: " . round($targetVal) . " mg/dL";
    }

    // 3. Today's Nutrition stats
    $nStmt = $pdo->prepare(
        "SELECT count(*) as meal_count, SUM(calories) as total_cal, SUM(carbs_g) as total_carbs, SUM(protein_g) as total_protein 
         FROM food_logs 
         WHERE user_id = :user_id AND log_date = :today"
    );
    $nStmt->execute([":user_id" => $userId, ":today" => $today]);
    $nutrition = $nStmt->fetch();

    $mealCount = (int) ($nutrition["meal_count"] ?? 0);
    $totalCal = (int) ($nutrition["total_cal"] ?? 0);
    $totalCarbs = round((float) ($nutrition["total_carbs"] ?? 0));
    $totalProtein = round((float) ($nutrition["total_protein"] ?? 0));

    // 4. Today's medications summary (prioritize pending Upcoming/Snoozed, otherwise reflect latest Taken/Skipped/Missed)
    $mStmt = $pdo->prepare(
        "SELECT name, dose, reminder_time, status, updated_at
         FROM medications 
         WHERE user_id = :user_id AND (reminder_date = :today OR frequency = 'Daily')
         ORDER BY CASE 
             WHEN LOWER(status) = 'snoozed' THEN 0
             WHEN LOWER(status) = 'upcoming' THEN 1
             ELSE 2
         END ASC, reminder_time ASC, updated_at DESC"
    );
    $mStmt->execute([":user_id" => $userId, ":today" => $today]);
    $allTodayMeds = $mStmt->fetchAll() ?: [];

    $medMetricValue = "0 Active Medications";
    $medMetricSubtitle = "No medications scheduled";
    $medMetricStatusText = "No Reminders";
    $medMetricStatusBg = "#F3F4F6";
    $medMetricStatusColor = "#6B7280";

    if (!empty($allTodayMeds)) {
        // Count statuses
        $takenMedCount = 0;
        $skippedMedCount = 0;
        $pendingMed = null;
        $latestUpdatedMed = $allTodayMeds[0];

        foreach ($allTodayMeds as $m) {
            $st = ucfirst(strtolower(trim($m["status"] ?? "Upcoming")));
            if ($st === "Taken") {
                $takenMedCount++;
            } elseif ($st === "Skipped" || $st === "Missed") {
                $skippedMedCount++;
            } elseif ($pendingMed === null && ($st === "Upcoming" || $st === "Snoozed")) {
                $pendingMed = $m;
            }
        }

        if ($pendingMed !== null) {
            $pStatus = ucfirst(strtolower(trim($pendingMed["status"] ?? "Upcoming")));
            $medMetricValue = $pendingMed["name"];
            $medMetricSubtitle = $pendingMed["dose"] . " • " . date("g:i A", strtotime($pendingMed["reminder_time"]));
            if ($pStatus === "Snoozed") {
                $medMetricStatusText = "Snoozed Dose";
                $medMetricStatusBg = "#E3F2FD";
                $medMetricStatusColor = "#1565C0";
            } else {
                $medMetricStatusText = "Upcoming Dose";
                $medMetricStatusBg = "#FFF3E0";
                $medMetricStatusColor = "#E65100";
            }
        } else {
            // Find the most recently updated medication when none are pending
            usort($allTodayMeds, function ($a, $b) {
                return strcmp($b["updated_at"] ?? "", $a["updated_at"] ?? "");
            });
            $latestUpdatedMed = $allTodayMeds[0];
            $lStatus = ucfirst(strtolower(trim($latestUpdatedMed["status"] ?? "Taken")));
            $medMetricValue = $latestUpdatedMed["name"];
            $medMetricSubtitle = $latestUpdatedMed["dose"] . " • " . date("g:i A", strtotime($latestUpdatedMed["reminder_time"]));

            if ($skippedMedCount === 0 && $takenMedCount > 0) {
                $medMetricStatusText = "All Doses Taken";
                $medMetricStatusBg = "#E8F5E9";
                $medMetricStatusColor = "#2E7D32";
            } elseif ($lStatus === "Taken") {
                $medMetricStatusText = "Dose Taken ({$takenMedCount}/" . count($allTodayMeds) . ")";
                $medMetricStatusBg = "#E8F5E9";
                $medMetricStatusColor = "#2E7D32";
            } else {
                $medMetricStatusText = "Dose {$lStatus}";
                $medMetricStatusBg = "#FFEBEE";
                $medMetricStatusColor = "#C62828";
            }
        }
    }

    // 5. Calculate logging streak (consecutive days with glucose, meals, or activity)
    $streakDays = 0;
    for ($i = 0; $i < 30; $i++) {
        $checkDate = date("Y-m-d", strtotime("-{$i} days"));
        $sStmt = $pdo->prepare(
            "SELECT 1 FROM (
                SELECT reading_date AS d FROM glucose_readings WHERE user_id = :uid AND reading_date = :d
                UNION
                SELECT log_date AS d FROM food_logs WHERE user_id = :uid AND log_date = :d
                UNION
                SELECT activity_date AS d FROM activity_logs WHERE user_id = :uid AND activity_date = :d
            ) t LIMIT 1"
        );
        $sStmt->execute([":uid" => $userId, ":d" => $checkDate]);
        if ($sStmt->fetch()) {
            $streakDays++;
        } else {
            if ($i === 0) {
                // Today not yet logged, continue checking yesterday
                continue;
            }
            break;
        }
    }
    $streakBadge = ($streakDays > 0) ? "🔥 {$streakDays} Day Logging Streak" : "⚡ Start your streak today";

    // 6. Calculate 7-day weekly progress (days with either glucose or meal logs)
    $wStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT d) FROM (
            SELECT reading_date AS d FROM glucose_readings WHERE user_id = :uid AND reading_date >= DATE_SUB(:today, INTERVAL 6 DAY)
            UNION
            SELECT log_date AS d FROM food_logs WHERE user_id = :uid AND log_date >= DATE_SUB(:today, INTERVAL 6 DAY)
        ) t"
    );
    $wStmt->execute([":uid" => $userId, ":today" => $today]);
    $weeklyDays = (int) $wStmt->fetchColumn();

    $weeklyProgressVal = "{$weeklyDays}/7 Days";
    $weeklyProgressSub = ($weeklyDays > 0) ? "Glucose & Diet Logged" : "No logs this week";
    $weeklyProgressStatus = ($weeklyDays >= 5) ? "Great Routine" : (($weeklyDays > 0) ? "Keep Logging" : "Start logging today");
    $weeklyProgressBg = ($weeklyDays > 0) ? "#E3F2FD" : "#F3F4F6";
    $weeklyProgressColor = ($weeklyDays > 0) ? "#1565C0" : "#6B7280";

    // Format greeting
    $hour = (int) date("H");
    $timeGreeting = ($hour < 12) ? "Good morning" : (($hour < 17) ? "Good afternoon" : "Good evening");

    // Dynamic AI tip based on glucose value & diet
    $tipText = "Welcome to DiaWell! Start by logging your first blood glucose reading or meal.";
    if ($glucoseVal !== null) {
        if ($glucoseVal > 180) {
            $tipText = "Glucose is elevated. Drink water and take a gentle 15-minute walk to help insulin sensitivity.";
        } elseif ($glucoseVal < 70) {
            $tipText = "Glucose is low! Have 15g fast-acting carbs (half cup fruit juice) and re-test in 15 mins.";
        } elseif ($totalCarbs > 200) {
            $tipText = "Carb intake is high today. Consider balanced fiber-rich vegetables for your next meal.";
        } else {
            $tipText = "A 10-minute light walk after meals helps smooth out blood sugar levels effectively!";
        }
    }

    // Daily routine check
    $bStmt = $pdo->prepare("SELECT count(*) FROM food_logs WHERE user_id = :uid AND log_date = :d AND LOWER(meal_category) = 'breakfast'");
    $bStmt = $pdo->prepare(
        "SELECT count(*) FROM food_logs 
         WHERE user_id = :uid AND log_date = :d 
         AND (LOWER(meal_category) LIKE '%breakfast%' OR LOWER(meal_category) LIKE '%morning%')"
    );
    $bStmt->execute([":uid" => $userId, ":d" => $today]);
    $breakfastDone = ((int) $bStmt->fetchColumn()) > 0;

    $gCheckStmt = $pdo->prepare("SELECT count(*) FROM glucose_readings WHERE user_id = :uid AND reading_date = :d");
    $gCheckStmt = $pdo->prepare(
        "SELECT count(*) FROM glucose_readings 
         WHERE user_id = :uid AND reading_date = :d"
    );
    $gCheckStmt->execute([":uid" => $userId, ":d" => $today]);
    $glucoseDone = ((int) $gCheckStmt->fetchColumn()) > 0;

    $lStmt = $pdo->prepare("SELECT count(*) FROM food_logs WHERE user_id = :uid AND log_date = :d AND LOWER(meal_category) = 'lunch'");
    $lStmt = $pdo->prepare(
        "SELECT count(*) FROM food_logs 
         WHERE user_id = :uid AND log_date = :d 
         AND (
             LOWER(meal_category) LIKE '%lunch%' 
             OR (LOWER(meal_category) LIKE '%lunch/dinner%' AND (HOUR(log_time) < 16 OR log_time = '00:00:00'))
             OR (LOWER(meal_category) LIKE '%snack%' AND HOUR(log_time) BETWEEN 11 AND 15)
         )"
    );
    $lStmt->execute([":uid" => $userId, ":d" => $today]);
    $lunchDone = ((int) $lStmt->fetchColumn()) > 0;

    $dStmt = $pdo->prepare("SELECT count(*) FROM food_logs WHERE user_id = :uid AND log_date = :d AND LOWER(meal_category) = 'dinner'");
    $dStmt = $pdo->prepare(
        "SELECT count(*) FROM food_logs 
         WHERE user_id = :uid AND log_date = :d 
         AND (
             LOWER(meal_category) LIKE '%dinner%' 
             OR LOWER(meal_category) LIKE '%night%'
             OR (LOWER(meal_category) LIKE '%lunch/dinner%' AND HOUR(log_time) >= 16)
             OR (LOWER(meal_category) LIKE '%snack%' AND HOUR(log_time) >= 18)
         )"
    );
    $dStmt->execute([":uid" => $userId, ":d" => $today]);
    $dinnerDone = ((int) $dStmt->fetchColumn()) > 0;

    $mCheckStmt = $pdo->prepare("SELECT count(*) FROM medications WHERE user_id = :uid AND (reminder_date = :d OR frequency = 'Daily') AND status = 'Taken'");
    $mCheckStmt = $pdo->prepare(
        "SELECT count(*) FROM medications 
         WHERE user_id = :uid AND (reminder_date = :d OR frequency = 'Daily') AND status = 'Taken'"
    );
    $mCheckStmt->execute([":uid" => $userId, ":d" => $today]);
    $medsDone = ((int) $mCheckStmt->fetchColumn()) > 0;
    $dinnerMedsDone = $dinnerDone || $medsDone;

    // Routine checklist
    $routineItems = [
        ["icon" => "🥣", "label" => "Breakfast", "is_done" => $breakfastDone],
        ["icon" => "🩸", "label" => "Glucose", "is_done" => $glucoseDone],
        ["icon" => "🥗", "label" => "Lunch", "is_done" => $lunchDone],
        ["icon" => "💊", "label" => "Dinner/Meds", "is_done" => $dinnerMedsDone]
    ];

    $doneCount = ($breakfastDone ? 1 : 0) + ($glucoseDone ? 1 : 0) + ($lunchDone ? 1 : 0) + ($dinnerMedsDone ? 1 : 0);
    $adherencePercent = round(($doneCount / 4) * 100);
    $wellnessSubtitle = ($doneCount > 0) ? "Daily Wellness • {$adherencePercent}% On Track" : "Daily Wellness • Start your day";

    // Nutrition values
    $nutrValue = ($totalCal > 0) ? number_format($totalCal) . " kcal" : "0 kcal";
    $nutrSubtitle = ($mealCount > 0) ? "{$mealCount} meals logged" : "0 meals logged today";
    $nutrStatusText = ($totalCarbs > 0 || $totalProtein > 0) ? "C:{$totalCarbs}g • P:{$totalProtein}g" : "No meals logged";
    $nutrStatusBg = ($mealCount > 0) ? "#E8F5E9" : "#F3F4F6";
    $nutrStatusColor = ($mealCount > 0) ? "#2E7D32" : "#6B7280";

    respond(true, "Dashboard data retrieved successfully.", 200, [
        "user_id" => (int) $user["id"],
        "greeting" => "{$timeGreeting}, {$user["full_name"]} 👋",
        "wellness_subtitle" => $wellnessSubtitle,
        "streak_badge" => $streakBadge,
        "target_badge" => $targetBadge,
        "streak_days" => (int) $streakDays,
        "target_fasting_glucose" => (int) $targetFast,
        "target_postmeal_glucose" => (int) $targetPost,
        "ai_tip" => [
            "title" => "DIVA Tip Of The Day ⚡",
            "tip" => $tipText
        ],
        "routine" => $routineItems,
        "profile" => [
            "name" => $user["full_name"],
            "diabetes_type" => $user["diabetes_type"] ?? "Type 2",
            "photo_url" => $photoUrl,
            "profile_pic" => $user["profile_pic"] ?? null
        ],
        "metrics" => [
            "glucose" => [
                "title" => "Glucose",
                "value" => $glucoseValStr,
                "value_num" => $glucoseValNum,
                "subtitle" => $glucoseTime,
                "status_text" => $glucoseStatus,
                "status_bg" => $glucoseStatusBg,
                "status_color" => $glucoseStatusColor
            ],
            "nutrition" => [
                "title" => "Nutrition",
                "value" => $nutrValue,
                "subtitle" => $nutrSubtitle,
                "status_text" => $nutrStatusText,
                "status_bg" => $nutrStatusBg,
                "status_color" => $nutrStatusColor
            ],
            "medication" => [
                "title" => "Medication",
                "value" => $medMetricValue,
                "subtitle" => $medMetricSubtitle,
                "status_text" => $medMetricStatusText,
                "status_bg" => $medMetricStatusBg,
                "status_color" => $medMetricStatusColor
            ],
            "weekly_progress" => [
                "title" => "Weekly Report",
                "value" => $weeklyProgressVal,
                "subtitle" => $weeklyProgressSub,
                "status_text" => $weeklyProgressStatus,
                "status_bg" => $weeklyProgressBg,
                "status_color" => $weeklyProgressColor
            ]
        ]
    ]);

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    respond(false, "Database error loading dashboard.", 500);
}
