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

$filter = trim($_GET["filter"] ?? "All");

try {
    $sql = "SELECT id, activity_type, icon, duration_mins, distance_km, calories_kcal, glucose_before, glucose_after, activity_date, activity_time, notes, created_at
            FROM activity_logs
            WHERE user_id = :user_id";
    $params = [":user_id" => $userId];

    if ($filter !== "" && strcasecmp($filter, "All") !== 0) {
        $sql .= " AND activity_type = :type";
        $params[":type"] = $filter;
    }

    $sql .= " ORDER BY activity_date DESC, activity_time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    $totalDistance = 0.0;
    $totalCalories = 0;
    $formattedList = [];

    foreach ($records as $row) {
        $rDate = $row["activity_date"];
        $timeStr = date("g:i A", strtotime($row["activity_time"]));

        if ($rDate === $today) {
            $dateLabel = "Today, " . date("j M", strtotime($rDate)) . " • " . $timeStr;
        } elseif ($rDate === $yesterday) {
            $dateLabel = "Yesterday, " . date("j M", strtotime($rDate)) . " • " . $timeStr;
        } else {
            $dateLabel = date("j M", strtotime($rDate)) . " • " . $timeStr;
        }

        $dist = (float) $row["distance_km"];
        $cal = (int) $row["calories_kcal"];

        $totalDistance += $dist;
        $totalCalories += $cal;

        $formattedList[] = [
            "id" => (string) $row["id"],
            "type" => $row["activity_type"],
            "icon" => $row["icon"] ?? "🚶‍♂️",
            "date" => $dateLabel,
            "raw_date" => $row["activity_date"],
            "durationMins" => (int) $row["duration_mins"],
            "distanceKm" => $dist,
            "caloriesKcal" => $cal,
            "glucoseBefore" => $row["glucose_before"] !== null ? (int) $row["glucose_before"] : 0,
            "glucoseAfter" => $row["glucose_after"] !== null ? (int) $row["glucose_after"] : 0,
            "notes" => $row["notes"] ?? ""
        ];
    }

    $totalWorkouts = count($formattedList);

    respond(true, "Activity history retrieved successfully.", 200, [
        "user_id" => $userId,
        "filter" => $filter,
        "summary" => [
            "total_workouts" => $totalWorkouts,
            "total_distance_km" => round($totalDistance, 1),
            "total_calories_kcal" => $totalCalories,
            "average_duration_mins" => $totalWorkouts > 0 ? round(array_sum(array_column($formattedList, "durationMins")) / $totalWorkouts) : 0
        ],
        "workouts" => $formattedList
    ]);

} catch (PDOException $e) {
    error_log("Activity history error: " . $e->getMessage());
    respond(false, "Database error retrieving activity history.", 500);
}

