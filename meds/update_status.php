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

$medId = (int) ($data["medication_id"] ?? $data["id"] ?? 0);
$medName = trim((string) ($data["medication_name"] ?? $data["name"] ?? ""));
$snoozeMinutes = (int) ($data["snooze_minutes"] ?? 0);
$newReminderTime = trim((string) ($data["new_reminder_time"] ?? $data["reminder_time"] ?? ""));

$statusInput = strtolower(trim((string) ($data["status"] ?? "Taken")));
$statusMap = [
    "taken" => "Taken",
    "take" => "Taken",
    "completed" => "Taken",
    "skipped" => "Skipped",
    "skip" => "Skipped",
    "missed" => "Missed",
    "miss" => "Missed",
    "snoozed" => "Snoozed",
    "snooze" => "Snoozed",
    "upcoming" => "Upcoming"
];

$rawStatus = $statusMap[$statusInput] ?? ucfirst($statusInput);
$allowed = ["Taken", "Skipped", "Missed", "Snoozed", "Upcoming"];

if (!in_array($rawStatus, $allowed, true)) {
    respond(false, "Invalid status. Allowed values: Taken, Skipped, Missed, Snoozed, Upcoming.", 400);
}

if ($medId <= 0 && $medName === "") {
    respond(false, "Valid medication_id or medication_name is required.", 400);
}

try {
    $targetRow = null;

    // 1. Primary lookup by exact medication ID and user_id
    if ($medId > 0) {
        $findStmt = $pdo->prepare(
            "SELECT id, user_id, name, dose, reminder_time, reminder_date, status
             FROM medications
             WHERE id = :id AND user_id = :user_id
             LIMIT 1"
        );
        $findStmt->execute([":id" => $medId, ":user_id" => $userId]);
        $targetRow = $findStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 2. Fallback lookup by exact medication name for the user (handles legacy hash IDs / test alarms)
    if (!$targetRow && $medName !== "") {
        $nameStmt = $pdo->prepare(
            "SELECT id, user_id, name, dose, reminder_time, reminder_date, status
             FROM medications
             WHERE user_id = :user_id AND LOWER(TRIM(name)) = LOWER(TRIM(:name))
             ORDER BY reminder_date DESC, id DESC
             LIMIT 1"
        );
        $nameStmt->execute([":user_id" => $userId, ":name" => $medName]);
        $targetRow = $nameStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$targetRow) {
        respond(false, "Medication not found or unauthorized.", 404);
    }

    $targetMedId = (int) $targetRow["id"];
    $updatedTimeSql = $targetRow["reminder_time"];

    // Update reminder_time if snoozed and a valid new time or snooze_minutes was provided
    if ($rawStatus === "Snoozed") {
        if ($newReminderTime !== "" && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newReminderTime)) {
            $updatedTimeSql = (strlen($newReminderTime) === 5) ? ($newReminderTime . ":00") : $newReminderTime;
        } elseif ($snoozeMinutes > 0) {
            $baseTs = strtotime($targetRow["reminder_time"]);
            if ($baseTs !== false) {
                $updatedTimeSql = date("H:i:s", $baseTs + ($snoozeMinutes * 60));
            }
        }
    }

    $updateStmt = $pdo->prepare(
        "UPDATE medications
         SET status = :status,
             reminder_time = :reminder_time,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id"
    );
    $updateStmt->execute([
        ":status" => $rawStatus,
        ":reminder_time" => $updatedTimeSql,
        ":id" => $targetMedId,
        ":user_id" => $userId
    ]);

    $formattedTime = date("g:i A", strtotime($updatedTimeSql));

    respond(true, "Medication status updated to {$rawStatus}.", 200, [
        "medication_id" => $targetMedId,
        "name" => $targetRow["name"],
        "status" => $rawStatus,
        "reminder_time" => $formattedTime,
        "raw_time" => $updatedTimeSql,
        "updated_at" => date("Y-m-d H:i:s")
    ]);

} catch (PDOException $e) {
    error_log("Medication status update error: " . $e->getMessage());
    respond(false, "Database error updating medication status.", 500);
}
