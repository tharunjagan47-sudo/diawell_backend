<?php
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/db.php';

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Read input data (JSON or POST body)
$data = getRequestData();

// Extract user ID
$userId = null;
if (!empty($data["user_id"])) {
    $userId = (int) $data["user_id"];
} elseif (!empty($data["userId"])) {
    $userId = (int) $data["userId"];
} else {
    $userId = getUserId($data);
}

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in first.", 401);
}

// Extract profile picture base64 string
$profilePic = trim($data["profile_pic"] ?? $data["profilePic"] ?? $data["image_base64"] ?? "");

if (empty($profilePic)) {
    respond(false, "Profile picture data (Base64 JPEG) is required.", 400);
}

// Strip data URI scheme prefix if present
if (preg_match('#^data:image/\w+;base64,#i', $profilePic)) {
    $profilePic = preg_replace('#^data:image/\w+;base64,#i', '', $profilePic);
}

try {
    // Verify user exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $checkStmt->execute([":id" => $userId]);
    if (!$checkStmt->fetch()) {
        respond(false, "User account not found with ID: " . $userId, 404);
    }

    // Update profile_pic column in users table
    $updateStmt = $pdo->prepare("UPDATE users SET profile_pic = :pic WHERE id = :id");
    $updateStmt->execute([
        ":pic" => $profilePic,
        ":id" => $userId
    ]);

    respond(true, "Profile picture updated successfully.", 200, [
        "status" => "success",
        "user_id" => $userId,
        "profile_pic" => $profilePic
    ]);

} catch (Throwable $e) {
    error_log("update_profile_pic error: " . $e->getMessage());
    respond(false, "Database error updating profile picture: " . $e->getMessage(), 500);
}
