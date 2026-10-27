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

$rawDate = trim($_GET["date"] ?? date("Y-m-d"));
$parsed = strtotime($rawDate);
$selectedDate = ($parsed !== false) ? date("Y-m-d", $parsed) : date("Y-m-d");

try {
    // 1. Fetch meals logged on this date
    $stmt = $pdo->prepare(
        "SELECT meal_category, calories, carbs_g, protein_g, fat_g
         FROM food_logs
         WHERE user_id = :user_id AND log_date = :date"
    );
    $stmt->execute([":user_id" => $userId, ":date" => $selectedDate]);
    $logs = $stmt->fetchAll();

    $consumedCalories = 0;
    $consumedCarbs = 0;
    $consumedProtein = 0;
    $consumedFat = 0;

    $categoryBreakdown = [
        "BREAKFAST" => 0,
        "LUNCH" => 0,
        "SNACKS" => 0,
        "DINNER" => 0
    ];

    foreach ($logs as $log) {
        $c = (int) $log["calories"];
        $consumedCalories += $c;
        $consumedCarbs += (float) $log["carbs_g"];
        $consumedProtein += (float) $log["protein_g"];
        $consumedFat += (float) $log["fat_g"];

        $cat = strtoupper($log["meal_category"]);
        if (isset($categoryBreakdown[$cat])) {
            $categoryBreakdown[$cat] += $c;
        }
    }

    $targetCalories = 1800; // Standard diabetic daily calorie baseline
    $remainingCalories = max(0, $targetCalories - $consumedCalories);
    $progressPercent = min(100, round(($consumedCalories / $targetCalories) * 100));

    respond(true, "Nutrition summary retrieved successfully.", 200, [
        "user_id" => $userId,
        "date" => $selectedDate,
        "date_label" => date("D, j M Y", strtotime($selectedDate)),
        "calories" => [
            "consumed" => $consumedCalories,
            "target" => $targetCalories,
            "remaining" => $remainingCalories,
            "consumed_label" => number_format($consumedCalories) . " kcal",
            "progress_percent" => $progressPercent
        ],
        "macros" => [
            "carbs_g" => round($consumedCarbs, 1),
            "protein_g" => round($consumedProtein, 1),
            "fat_g" => round($consumedFat, 1),
            "summary_label" => "Carbs " . round($consumedCarbs) . " g • Protein " . round($consumedProtein) . " g • Fat " . round($consumedFat) . " g"
        ],
        "meals_logged_count" => count($logs),
        "meals_breakdown" => [
            "breakfast" => $categoryBreakdown["BREAKFAST"] . " kcal",
            "lunch" => $categoryBreakdown["LUNCH"] . " kcal",
            "snacks" => $categoryBreakdown["SNACKS"] . " kcal",
            "dinner" => $categoryBreakdown["DINNER"] . " kcal"
        ]
    ]);

} catch (PDOException $e) {
    error_log("Nutrition summary error: " . $e->getMessage());
    respond(false, "Database error retrieving nutrition summary.", 500);
}

