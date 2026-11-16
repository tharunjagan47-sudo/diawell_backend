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

$endDate = trim($_GET["end_date"] ?? date("Y-m-d"));
$startDate = date("Y-m-d", strtotime($endDate . " -6 days"));

try {
    // 1. Glucose readings in 7-day window
    $gStmt = $pdo->prepare(
        "SELECT glucose_value, reading_type, reading_date 
         FROM glucose_readings 
         WHERE user_id = :user_id AND reading_date BETWEEN :start AND :end
         ORDER BY reading_date ASC"
    );
    $gStmt->execute([":user_id" => $userId, ":start" => $startDate, ":end" => $endDate]);
    $glucoseLogs = $gStmt->fetchAll();

    $gValues = array_column($glucoseLogs, "glucose_value");
    $gCount = count($gValues);

    if ($gCount > 0) {
        $avgGlucose = round(array_sum($gValues) / $gCount, 1);
        $avgGlucoseStr = $avgGlucose . " mg/dL";
        $avgGlucoseNum = $avgGlucose;

        // Time in range calculation (standard diabetic target: 70-140 mg/dL)
        $inRangeCount = 0;
        $highCount = 0;
        $lowCount = 0;
        foreach ($gValues as $v) {
            $val = (float) $v;
            if ($val >= 70 && $val <= 140) {
                $inRangeCount++;
            } elseif ($val > 140) {
                $highCount++;
            } else {
                $lowCount++;
            }
        }
        $inRangePercent = round(($inRangeCount / $gCount) * 100);
        $highPercent = round(($highCount / $gCount) * 100);
        $lowPercent = round(($lowCount / $gCount) * 100);
        $summaryTag = ($avgGlucose <= 130) ? "Optimal Fasting & Post-Meal Control" : "Attention Required";
    } else {
        $avgGlucose = 0.0;
        $avgGlucoseStr = "-- mg/dL";
        $avgGlucoseNum = 0.0;
        $inRangePercent = 0;
        $highPercent = 0;
        $lowPercent = 0;
        $summaryTag = "No Readings Logged";
    }

    // 2. Nutrition stats in 7-day window
    $nStmt = $pdo->prepare(
        "SELECT count(*) as meal_count, SUM(calories) as total_cal
         FROM food_logs 
         WHERE user_id = :user_id AND log_date BETWEEN :start AND :end"
    );
    $nStmt->execute([":user_id" => $userId, ":start" => $startDate, ":end" => $endDate]);
    $nutr = $nStmt->fetch();
    $totalMeals = (int) ($nutr["meal_count"] ?? 0);
    $totalCalories = (int) ($nutr["total_cal"] ?? 0);
    $avgDailyCalories = ($totalMeals > 0) ? round($totalCalories / 7) : 0;

    // 3. Medication adherence in 7-day window
    $mStmt = $pdo->prepare(
        "SELECT count(*) as total_meds, SUM(CASE WHEN status = 'Taken' THEN 1 ELSE 0 END) as taken_meds
         FROM medications 
         WHERE user_id = :user_id AND reminder_date BETWEEN :start AND :end"
    );
    $mStmt->execute([":user_id" => $userId, ":start" => $startDate, ":end" => $endDate]);
    $medAdherence = $mStmt->fetch();
    $totalMeds = (int) ($medAdherence["total_meds"] ?? 0);
    $takenMeds = (int) ($medAdherence["taken_meds"] ?? 0);
    $medAdherenceRate = ($totalMeds > 0) ? round(($takenMeds / $totalMeds) * 100) : 0;
    $medStatusLabel = ($totalMeds > 0) ? "{$medAdherenceRate}% Medication Adherence" : "No medications scheduled";

    // 4. Activity stats in 7-day window
    $aStmt = $pdo->prepare(
        "SELECT count(*) as workouts, SUM(duration_mins) as active_mins, SUM(calories_kcal) as burn_cals
         FROM activity_logs 
         WHERE user_id = :user_id AND activity_date BETWEEN :start AND :end"
    );
    $aStmt->execute([":user_id" => $userId, ":start" => $startDate, ":end" => $endDate]);
    $act = $aStmt->fetch();
    $activeMins = (int) ($act["active_mins"] ?? 0);
    $workoutCount = (int) ($act["workouts"] ?? 0);
    $activitySummaryLabel = ($workoutCount > 0) ? "{$activeMins} active minutes across {$workoutCount} workouts" : "No workouts logged";

    // 5. Daily trend points for 7-day chart
    $chartPoints = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date("Y-m-d", strtotime($endDate . " -{$i} days"));
        $dayValues = [];
        foreach ($glucoseLogs as $row) {
            if ($row["reading_date"] === $d) {
                $dayValues[] = (float) $row["glucose_value"];
            }
        }
        $dayAvg = count($dayValues) > 0 ? round(array_sum($dayValues) / count($dayValues)) : 0;
        $chartPoints[] = [
            "day" => date("D", strtotime($d)),
            "date" => date("j M", strtotime($d)),
            "average_glucose" => $dayAvg
        ];
    }

    $weekLabel = date("j", strtotime($startDate)) . "–" . date("j M Y", strtotime($endDate));

    respond(true, "Weekly report compiled successfully.", 200, [
        "user_id" => $userId,
        "week_label" => $weekLabel,
        "start_date" => $startDate,
        "end_date" => $endDate,
        "glucose" => [
            "average" => $avgGlucoseStr,
            "average_num" => $avgGlucoseNum,
            "time_in_range_percent" => $inRangePercent,
            "above_target_percent" => $highPercent,
            "below_target_percent" => $lowPercent,
            "summary_tag" => $summaryTag
        ],
        "nutrition" => [
            "total_meals_logged" => $totalMeals,
            "total_calories" => number_format($totalCalories) . " kcal",
            "daily_average_calories" => number_format($avgDailyCalories) . " kcal"
        ],
        "medication" => [
            "adherence_percent" => $medAdherenceRate,
            "status_label" => $medStatusLabel
        ],
        "activity" => [
            "total_active_mins" => $activeMins,
            "workout_count" => $workoutCount,
            "summary_label" => $activitySummaryLabel
        ],
        "chart_data" => $chartPoints
    ]);

} catch (PDOException $e) {
    error_log("Weekly report error: " . $e->getMessage());
    respond(false, "Database error compiling weekly report.", 500);
}

