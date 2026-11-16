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

try {
    // 1. Wipe glucose readings
    $pdo->prepare("DELETE FROM glucose_readings WHERE user_id = :uid")->execute([":uid" => $userId]);

    // 2. Wipe medications
    $pdo->prepare("DELETE FROM medications WHERE user_id = :uid")->execute([":uid" => $userId]);

    // 3. Wipe food logs
    $pdo->prepare("DELETE FROM food_logs WHERE user_id = :uid")->execute([":uid" => $userId]);

    // 4. Wipe activity logs
    $pdo->prepare("DELETE FROM activity_logs WHERE user_id = :uid")->execute([":uid" => $userId]);

    // 5. Reset user baseline glucose if set
    $pdo->prepare("UPDATE users SET current_glucose_mg_dl = NULL WHERE id = :uid")->execute([":uid" => $userId]);

    respond(true, "All patient health logs have been cleared successfully.", 200, [
        "cleared_user_id" => $userId
    ]);
} catch (PDOException $e) {
    error_log("Reset logs error: " . $e->getMessage());
    respond(false, "Database error clearing logs.", 500);
}

