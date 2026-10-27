```php
<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';

function respond($success, $message, $statusCode = 200, $data = null) {
    http_response_code($statusCode);

    $response = [
        "success" => $success,
        "message" => $message
    ];

    if ($data !== null) {
        $response["data"] = $data;
    }

    echo json_encode($response);
    exit;
}

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed.", 405);
}

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    respond(false, "Invalid JSON request.", 400);
}

// Read fields
$full_name = trim($data["full_name"] ?? "");
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate name
if ($full_name === "" || strlen($full_name) > 100) {
    respond(false, "Please enter a valid full name.", 400);
}

// Validate email
if (
    $email === "" ||
    strlen($email) > 255 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    respond(false, "Please enter a valid email address.", 400);
}

// Validate password
if (!is_string($password) || strlen($password) < 8) {
    respond(false, "Password must be at least 8 characters.", 400);
}

try {
    // Check duplicate email
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE email = :email LIMIT 1"
    );

    $stmt->execute([":email" => $email]);

    if ($stmt->fetch()) {
        respond(false, "Email already registered.", 409);
    }

    // Hash password
    $password_hash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    // Insert new user
    $stmt = $pdo->prepare(
        "INSERT INTO users
            (full_name, email, password_hash)
         VALUES
            (:full_name, :email, :password_hash)"
    );

    $stmt->execute([
        ":full_name" => $full_name,
        ":email" => $email,
        ":password_hash" => $password_hash
    ]);

    // Create a session for the newly registered user
    $user_id = (int) $pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION["user_id"] = $user_id;

    // Send success response
    respond(true, "Signup successful. Continue to patient profile.", 201, [
        "user_id" => $user_id,
        "full_name" => $full_name,
        "email" => $email,
        "next_screen" => "patient_profile"
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());
    respond(false, "Database error. Please try again.", 500);
}
?>
```