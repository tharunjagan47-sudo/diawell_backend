<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Read input
$data = getRequestData();

$email = trim($data["email"] ?? "");
$new_password = $data["new_password"] ?? $data["newPassword"] ?? $data["password"] ?? "";
$confirm_new_password = $data["confirm_new_password"] ?? $data["confirmPassword"] ?? $data["confirm_password"] ?? "";

// Validate email
if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, "Please enter a valid registered email address.", 400);
}

// Validate password
if (!is_string($new_password) || strlen($new_password) < 8) {
    respond(false, "New password must be at least 8 characters long.", 400);
}

if ($confirm_new_password === "") {
    respond(false, "Please confirm your new password.", 400);
}

// Passwords match check
if ($new_password !== $confirm_new_password) {
    respond(false, "New password and confirm password do not match.", 400);
}

try {
    // Find registered user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([":email" => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "No registered account found with this email address.", 404);
    }

    // Securely hash new password
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

    // Update in database
    $update = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :id");
    $update->execute([
        ":password_hash" => $password_hash,
        ":id" => $user["id"]
    ]);

    respond(true, "Password updated successfully! Please log in with your new password.", 200, [
        "email" => $email
    ]);

} catch (PDOException $e) {
    error_log("Password reset error: " . $e->getMessage());
    respond(false, "Database error during password reset. Please try again.", 500);
}
