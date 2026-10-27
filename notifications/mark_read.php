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

$notifId = (int) ($data["notification_id"] ?? $data["id"] ?? 0);

if ($notifId <= 0) {
    respond(false, "Valid notification_id is required.", 400);
}

try {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id");
    $stmt->execute([":id" => $notifId, ":user_id" => $userId]);

    respond(true, "Notification marked as read.", 200, [
        "notification_id" => $notifId,
        "is_read" => 1
    ]);

} catch (PDOException $e) {
    error_log("Notification mark read error: " . $e->getMessage());
    respond(false, "Database error updating notification.", 500);
}

