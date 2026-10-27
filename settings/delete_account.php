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

$password = $data["password"] ?? "";

if ($password === "") {
    respond(false, "Password confirmation is required to delete your account.", 400);
}

try {
    // 1. Fetch user record
    $stmt = $pdo->prepare("SELECT id, password_hash, profile_photo FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "User account not found.", 404);
    }

    // 2. Verify password
    if (!password_verify($password, $user["password_hash"])) {
        respond(false, "Invalid password. Account deletion aborted.", 401);
    }

    // 3. Remove profile photo file if present
    if (!empty($user["profile_photo"])) {
        $photoFile = __DIR__ . "/../" . ltrim($user["profile_photo"], "/");
        if (is_file($photoFile)) {
            @unlink($photoFile);
        }
    }

    // 4. Delete user (cascades to all child tables via foreign keys)
    $del = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $del->execute([":id" => $userId]);

    // 5. Destroy session
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    respond(true, "Your DiaWell account and all associated health records have been permanently deleted.", 200, [
        "deleted_user_id" => $userId
    ]);

} catch (PDOException $e) {
    error_log("Delete account error: " . $e->getMessage());
    respond(false, "Database error deleting account.", 500);
}

