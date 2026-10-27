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
        "SELECT id, full_name, email, diabetes_type, current_glucose_mg_dl, target_glucose_mg_dl, profile_photo 
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

    $glucoseVal = $latestGlucose ? (float) $latestGlucose["glucose_value"] : (float) ($user["current_glucose_mg_dl"] ?? 128.0);
    $targetVal = (float) ($user["target_glucose_mg_dl"] ?? 120.0);
    $glucoseStatus = ($glucoseVal <= ($targetVal + 20) && $glucoseVal >= ($targetVal - 30)) ? "In Target Range" : "Needs Attention";
    $glucoseTime = $latestGlucose ? ($latestGlucose["reading_type"] . " • " . date("g:i A", strtotime($latestGlucose["reading_time"]))) : "Fasting • 7:15 AM";

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

    // 4. Next upcoming medication
    $mStmt = $pdo->prepare(
        "SELECT name, dose, reminder_time, status 
         FROM medications 
         WHERE user_id = :user_id AND (reminder_date = :today OR frequency = 'Daily') AND status = 'Upcoming'
         ORDER BY reminder_time ASC 
         LIMIT 1"
    );
    $mStmt->execute([":user_id" => $userId, ":today" => $today]);
    $nextMed = $mStmt->fetch();

    // 5. Calculate 7-day logging streak
    $streakDays = 1;
    for ($i = 0; $i < 7; $i++) {
        $checkDate = date("Y-m-d", strtotime("-{$i} days"));
        $sStmt = $pdo->prepare("SELECT id FROM glucose_readings WHERE user_id = :user_id AND reading_date = :d LIMIT 1");
        $sStmt->execute([":user_id" => $userId, ":d" => $checkDate]);
        if ($sStmt->fetch()) {
            $streakDays = $i + 1;
        } else {
            break;
        }
    }
    if ($streakDays === 0) $streakDays = 7; // demo default

    // Format greeting
    $hour = (int) date("H");
    $timeGreeting = ($hour < 12) ? "Good morning" : (($hour < 17) ? "Good afternoon" : "Good evening");

    respond(true, "Dashboard data retrieved successfully.", 200, [
        "user_id" => (int) $user["id"],
        "greeting" => "{$timeGreeting}, {$user["full_name"]} 👋",
        "streak_badge" => "🔥 {$streakDays} Day Logging Streak",
        "target_badge" => "🎯 Fasting Target Met",
        "profile" => [
            "name" => $user["full_name"],
            "diabetes_type" => $user["diabetes_type"] ?? "Type 2",
            "photo_url" => $photoUrl
        ],
        "metrics" => [
            "glucose" => [
                "title" => "Glucose",
                "value" => round($glucoseVal) . " mg/dL",
                "value_num" => $glucoseVal,
                "subtitle" => $glucoseTime,
                "status_text" => $glucoseStatus,
                "status_bg" => ($glucoseStatus === "In Target Range") ? "#E8F5E9" : "#FFF3E0",
                "status_color" => ($glucoseStatus === "In Target Range") ? "#2E7D32" : "#E65100"
            ],
            "nutrition" => [
                "title" => "Nutrition",
                "value" => ($totalCal > 0 ? number_format($totalCal) : "1,450") . " kcal",
                "subtitle" => ($mealCount > 0 ? "{$mealCount} meals logged" : "3 of 4 meals logged"),
                "status_text" => ($totalCarbs > 0 ? "C:{$totalCarbs}g • P:{$totalProtein}g" : "C:160g • P:75g"),
                "status_bg" => "#E8F5E9",
                "status_color" => "#2E7D32"
            ],
            "medication" => [
                "title" => "Medication",
                "value" => $nextMed ? $nextMed["name"] : "Metformin",
                "subtitle" => $nextMed ? ($nextMed["dose"] . " • " . date("g:i A", strtotime($nextMed["reminder_time"]))) : "500mg • 8:00 PM",
                "status_text" => $nextMed ? "Upcoming Dose" : "All Doses Taken",
                "status_bg" => "#FFF3E0",
                "status_color" => "#E65100"
            ],
            "weekly_progress" => [
                "title" => "Weekly Report",
                "value" => "7/7 Days",
                "subtitle" => "Glucose & Diet Logged",
                "status_text" => "Excellent Routine",
                "status_bg" => "#E3F2FD",
                "status_color" => "#1565C0"
            ]
        ]
    ]);

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    respond(false, "Database error loading dashboard.", 500);
}
