
<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/db.php";

function respond($success, $message, $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message
    ]);

    exit;
}

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    respond(false, "Invalid JSON request.", 400);
}

// Get input fields
$email = trim($data["email"] ?? "");
$new_password = $data["new_password"] ?? "";
$confirm_new_password = $data["confirm_new_password"] ?? "";

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, "Please enter a valid email address.", 400);
}

// Validate new password
if (!is_string($new_password) || strlen($new_password) < 8) {
    respond(false, "Password must be at least 8 characters.", 400);
}

// Validate confirm password
if (!is_string($confirm_new_password) || $confirm_new_password === "") {
    respond(false, "Please confirm your new password.", 400);
}

// Check whether passwords match
if ($new_password !== $confirm_new_password) {
    respond(false, "New password and confirm password do not match.", 400);
}

try {
    // Find registered user
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE email = :email LIMIT 1"
    );

    $stmt->execute([":email" => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "Email address not found.", 404);
    }

    // Hash new password securely
    $password_hash = password_hash(
        $new_password,
        PASSWORD_DEFAULT
    );

    // Update password in database
    $update = $pdo->prepare(
        "UPDATE users
         SET password_hash = :password_hash
         WHERE id = :id"
    );

    $update->execute([
        ":password_hash" => $password_hash,
        ":id" => $user["id"]
    ]);

    respond(
        true,
        "Password reset successfully. Please log in.",
        200
    );

} catch (PDOException $e) {
    error_log($e->getMessage());

    respond(false, "Database error. Please try again.", 500);
}
?>
