<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Read input data (JSON or POST)
$data = getRequestData();

$full_name = trim($data["full_name"] ?? $data["fullName"] ?? $data["name"] ?? "");
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";
$confirm_password = $data["confirm_password"] ?? $data["confirmPassword"] ?? null;

// Validate full name
if ($full_name === "" || mb_strlen($full_name) > 100) {
    respond(false, "Please enter a valid full name (max 100 characters).", 400);
}

// Validate email
if ($email === "" || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, "Please enter a valid email address.", 400);
}

// Validate password
if (!is_string($password) || strlen($password) < 8) {
    respond(false, "Password must be at least 8 characters long.", 400);
}

// Check password match if confirm_password passed
if ($confirm_password !== null && $password !== $confirm_password) {
    respond(false, "Password and confirm password do not match.", 400);
}

try {
    // Check if email already registered
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([":email" => $email]);

    if ($stmt->fetch()) {
        respond(false, "This email is already registered. Please log in.", 409);
    }

    // Secure password hashing
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash) VALUES (:full_name, :email, :password_hash)"
    );
    $insert->execute([
        ":full_name" => $full_name,
        ":email" => $email,
        ":password_hash" => $password_hash
    ]);

    $user_id = (int) $pdo->lastInsertId();

    // Start session
    session_regenerate_id(true);
    $_SESSION["user_id"] = $user_id;

    respond(true, "Account created successfully. Continue to patient profile.", 201, [
        "user_id" => $user_id,
        "full_name" => $full_name,
        "email" => $email,
        "next_screen" => "patient_profile"
    ]);

} catch (PDOException $e) {
    error_log("Signup error: " . $e->getMessage());
    respond(false, "Database error occurred during registration. Please try again.", 500);
}
