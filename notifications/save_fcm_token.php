<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

$data = getRequestData();
$fcmToken = trim($data["fcm_token"] ?? $data["token"] ?? "");
$deviceType = trim($data["device_type"] ?? "Android");
$userId = $data["user_id"] ?? getUserId();

if (empty($fcmToken)) {
    respond(false, "FCM token is required.", 400);
}

try {
    // 1. Auto-create user_fcm_tokens table if not exists
    $createTableSql = "CREATE TABLE IF NOT EXISTS user_fcm_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        fcm_token VARCHAR(255) NOT NULL UNIQUE,
        device_type VARCHAR(50) DEFAULT 'Android',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($createTableSql);

    // 2. Insert or update token
    $stmt = $pdo->prepare(
        "INSERT INTO user_fcm_tokens (user_id, fcm_token, device_type, updated_at)
         VALUES (:user_id, :fcm_token, :device_type, NOW())
         ON DUPLICATE KEY UPDATE 
            user_id = IF(:user_id_up IS NOT NULL, :user_id_up2, user_id),
            device_type = :device_type_up,
            updated_at = NOW()"
    );

    $stmt->execute([
        ":user_id" => $userId ?: null,
        ":fcm_token" => $fcmToken,
        ":device_type" => $deviceType,
        ":user_id_up" => $userId ?: null,
        ":user_id_up2" => $userId ?: null,
        ":device_type_up" => $deviceType
    ]);

    respond(true, "FCM token saved successfully.", 200, [
        "user_id" => $userId,
        "device_type" => $deviceType
    ]);

} catch (PDOException $e) {
    respond(false, "Database error saving FCM token: " . $e->getMessage(), 500);
}