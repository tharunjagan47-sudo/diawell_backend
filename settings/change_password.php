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

$current_password = $data["current_password"] ?? $data["currentPassword"] ?? "";
$new_password = $data["new_password"] ?? $data["newPassword"] ?? "";
$confirm_password = $data["confirm_password"] ?? $data["confirmPassword"] ?? "";

if ($current_password === "" || $new_password === "" || $confirm_password === "") {
    respond(false, "Please provide current password, new password, and password confirmation.", 400);
}

if (strlen($new_password) < 8) {
    respond(false, "New password must be at least 8 characters long.", 400);
}

if ($new_password !== $confirm_password) {
    respond(false, "New password and confirm password do not match.", 400);
}

try {
    // 1. Fetch user password hash
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "User account not found.", 404);
    }

    // 2. Verify current password
    if (!password_verify($current_password, $user["password_hash"])) {
        respond(false, "Incorrect current password.", 401);
    }

    // 3. Hash and update new password
    $newHash = password_hash($new_password, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
    $up->execute([":hash" => $newHash, ":id" => $userId]);

    respond(true, "Password changed successfully.", 200, [
        "user_id" => $userId,
        "updated_at" => date("Y-m-d H:i:s")
    ]);

} catch (PDOException $e) {
    error_log("Change password error: " . $e->getMessage());
    respond(false, "Database error changing password.", 500);
}

