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

$range = strtolower(trim($_GET["range"] ?? "7 days"));
$days = 7;
if (strpos($range, "30") !== false) {
    $days = 30;
} elseif (strpos($range, "3 month") !== false || strpos($range, "90") !== false) {
    $days = 90;
}

$startDate = date("Y-m-d", strtotime("-{$days} days"));

try {
    // 1. Fetch readings in range
    $stmt = $pdo->prepare(
        "SELECT id, glucose_value, reading_type, reading_date, reading_time, notes, created_at
         FROM glucose_readings
         WHERE user_id = :user_id AND reading_date >= :start_date
         ORDER BY reading_date DESC, reading_time DESC"
    );
    $stmt->execute([
        ":user_id" => $userId,
        ":start_date" => $startDate
    ]);
    $readings = $stmt->fetchAll();

    // 2. Compute statistics
    $totalCount = count($readings);
    $values = array_column($readings, "glucose_value");
    
    $avgValue = $totalCount > 0 ? round(array_sum($values) / $totalCount, 1) : 0;
    $lowestValue = $totalCount > 0 ? (float) min($values) : 0;
    $highestValue = $totalCount > 0 ? (float) max($values) : 0;

    // 3. Format reading items for UI
    $formattedList = [];
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    foreach ($readings as $row) {
        $rDate = $row["reading_date"];
        if ($rDate === $today) {
            $dateLabel = "Today, " . date("j M", strtotime($rDate));
        } elseif ($rDate === $yesterday) {
            $dateLabel = "Yesterday, " . date("j M", strtotime($rDate));
        } else {
            $dateLabel = date("D, j M", strtotime($rDate));
        }

        $formattedTime = date("g:i A", strtotime($row["reading_time"]));
        $timeLabel = "{$formattedTime} • {$row["reading_type"]}";

        $formattedList[] = [
            "id" => (int) $row["id"],
            "date" => $dateLabel,
            "raw_date" => $row["reading_date"],
            "time" => $timeLabel,
            "raw_time" => $row["reading_time"],
            "value" => (string) round((float) $row["glucose_value"]),
            "reading_type" => $row["reading_type"],
            "notes" => $row["notes"] ?? ""
        ];
    }

    // 4. Generate chronological chart points for UI graph
    $chronological = array_reverse($readings);
    $chartData = [];
    foreach ($chronological as $item) {
        $chartData[] = [
            "date" => date("j M", strtotime($item["reading_date"])),
            "time" => date("g:i A", strtotime($item["reading_time"])),
            "value" => (float) $item["glucose_value"],
            "type" => $item["reading_type"]
        ];
    }

    respond(true, "Glucose history retrieved successfully.", 200, [
        "user_id" => $userId,
        "range" => $range,
        "date_range_label" => date("j M", strtotime($startDate)) . " – " . date("j M Y"),
        "stats" => [
            "average" => $avgValue . " mg/dL",
            "average_num" => $avgValue,
            "lowest" => $lowestValue . " mg/dL",
            "lowest_num" => $lowestValue,
            "highest" => $highestValue . " mg/dL",
            "highest_num" => $highestValue,
            "readings_count" => $totalCount . " logged",
            "count" => $totalCount
        ],
        "chart_data" => $chartData,
        "readings" => $formattedList
    ]);

} catch (PDOException $e) {
    error_log("Glucose history error: " . $e->getMessage());
    respond(false, "Database error retrieving glucose history.", 500);
}

