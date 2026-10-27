<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

$activity_type = trim($data["activity_type"] ?? $data["type"] ?? "Walking");
$duration_mins = (int) parseNumericValue($data["duration_mins"] ?? $data["durationMins"] ?? 30);
$distance_km = parseNumericValue($data["distance_km"] ?? $data["distanceKm"] ?? 0.0);
$calories_kcal = (int) parseNumericValue($data["calories_kcal"] ?? $data["caloriesKcal"] ?? $data["calories"] ?? 0);
$glucose_before = parseNumericValue($data["glucose_before"] ?? $data["glucoseBefore"] ?? null);
$glucose_after = parseNumericValue($data["glucose_after"] ?? $data["glucoseAfter"] ?? null);
$rawDate = trim($data["activity_date"] ?? $data["date"] ?? date("Y-m-d"));
$rawTime = trim($data["activity_time"] ?? $data["time"] ?? date("H:i:s"));
$notes = trim($data["notes"] ?? "");

// Assign icon based on type
$icon = "🚶‍♂️";
if (strcasecmp($activity_type, "Cycling") === 0) {
    $icon = "🚴";
} elseif (strcasecmp($activity_type, "Running") === 0) {
    $icon = "🏃";
}

$parsedDate = strtotime($rawDate);
$activityDate = ($parsedDate !== false) ? date("Y-m-d", $parsedDate) : date("Y-m-d");

$parsedTime = strtotime($rawTime);
$activityTime = ($parsedTime !== false) ? date("H:i:s", $parsedTime) : date("H:i:s");

try {
    $stmt = $pdo->prepare(
        "INSERT INTO activity_logs (user_id, activity_type, icon, duration_mins, distance_km, calories_kcal, glucose_before, glucose_after, activity_date, activity_time, notes)
         VALUES (:user_id, :activity_type, :icon, :duration_mins, :distance_km, :calories_kcal, :glucose_before, :glucose_after, :activity_date, :activity_time, :notes)"
    );

    $stmt->execute([
        ":user_id" => $userId,
        ":activity_type" => $activity_type,
        ":icon" => $icon,
        ":duration_mins" => $duration_mins,
        ":distance_km" => (float) $distance_km,
        ":calories_kcal" => $calories_kcal,
        ":glucose_before" => $glucose_before !== null ? (float) $glucose_before : null,
        ":glucose_after" => $glucose_after !== null ? (float) $glucose_after : null,
        ":activity_date" => $activityDate,
        ":activity_time" => $activityTime,
        ":notes" => $notes
    ]);

    $activityId = (int) $pdo->lastInsertId();

    // If glucose_after was recorded, update current glucose in users table
    if ($glucose_after !== null && $glucose_after > 0) {
        $up = $pdo->prepare("UPDATE users SET current_glucose_mg_dl = :g WHERE id = :id");
        $up->execute([":g" => (float) $glucose_after, ":id" => $userId]);
    }

    respond(true, "Workout activity logged successfully.", 201, [
        "id" => $activityId,
        "user_id" => $userId,
        "type" => $activity_type,
        "icon" => $icon,
        "duration_mins" => $duration_mins,
        "distance_km" => (float) $distance_km,
        "calories_kcal" => $calories_kcal,
        "glucose_before" => $glucose_before,
        "glucose_after" => $glucose_after,
        "date" => $activityDate,
        "time" => date("g:i A", strtotime($activityTime))
    ]);

} catch (PDOException $e) {
    error_log("Activity log error: " . $e->getMessage());
    respond(false, "Database error saving activity log.", 500);
}

