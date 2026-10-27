<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Read input data (JSON or POST)
$data = getRequestData();

$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate required fields
if ($email === "" || $password === "") {
    respond(false, "Please provide both email and password.", 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, "Please provide a valid email address.", 400);
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, password_hash, diabetes_type, age, 
                height_cm, weight_kg, profile_photo, current_glucose_mg_dl, target_glucose_mg_dl, created_at
         FROM users 
         WHERE email = :email 
         LIMIT 1"
    );
    $stmt->execute([":email" => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user["password_hash"])) {
        respond(false, "Invalid email or password.", 401);
    }

    // Set session
    session_regenerate_id(true);
    $_SESSION["user_id"] = (int) $user["id"];
    $_SESSION["user_name"] = $user["full_name"];

    // Format photo URL if present
    $profilePhotoUrl = null;
    if (!empty($user["profile_photo"])) {
        $profilePhotoUrl = "http://" . $_SERVER["HTTP_HOST"] . "/DiaWell_backend/" . ltrim($user["profile_photo"], "/");
    }

    $isProfileCompleted = !empty($user["diabetes_type"]);

    respond(true, "Login successful.", 200, [
        "user_id" => (int) $user["id"],
        "full_name" => $user["full_name"],
        "email" => $user["email"],
        "diabetes_type" => $user["diabetes_type"],
        "age" => $user["age"] !== null ? (int) $user["age"] : null,
        "height_cm" => $user["height_cm"] !== null ? (float) $user["height_cm"] : null,
        "weight_kg" => $user["weight_kg"] !== null ? (float) $user["weight_kg"] : null,
        "current_glucose_mg_dl" => $user["current_glucose_mg_dl"] !== null ? (float) $user["current_glucose_mg_dl"] : null,
        "target_glucose_mg_dl" => $user["target_glucose_mg_dl"] !== null ? (float) $user["target_glucose_mg_dl"] : null,
        "profile_photo" => $user["profile_photo"],
        "profile_photo_url" => $profilePhotoUrl,
        "is_profile_completed" => $isProfileCompleted,
        "next_screen" => $isProfileCompleted ? "dashboard" : "patient_profile"
    ]);

} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    respond(false, "Database error during login. Please try again.", 500);
}
