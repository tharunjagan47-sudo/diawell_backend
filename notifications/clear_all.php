<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

try {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = :user_id");
    $stmt->execute([":user_id" => $userId]);

    respond(true, "All notifications cleared successfully.", 200, [
        "user_id" => $userId,
        "cleared" => true
    ]);

} catch (PDOException $e) {
    error_log("Notifications clear error: " . $e->getMessage());
    respond(false, "Database error clearing notifications.", 500);
}

