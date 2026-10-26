
<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';

function respond($success, $message, $statusCode = 200) {
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message
    ]);

    exit;
}

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed.", 405);
}

// Read raw JSON request body
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    respond(false, "Invalid JSON request.", 400);
}

// Read fields dynamically
$full_name = trim($data["full_name"] ?? "");
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate full name
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
if (strlen($password) < 8) {
    respond(false, "Password must be at least 8 characters.", 400);
}

// Check whether this email already exists
$stmt = $pdo->prepare(
    "SELECT id FROM users WHERE email = :email LIMIT 1"
);

$stmt->execute([
    ":email" => $email
]);

if ($stmt->fetch()) {
    respond(false, "Email already registered.", 409);
}

    // Hash password securely
    $password_hash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    // Insert user into database
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

    respond(true, "Account created successfully.", 201);
?>
