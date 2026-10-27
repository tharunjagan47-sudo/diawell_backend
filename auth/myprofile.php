

<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/db.php";

function respond($success, $message, $statusCode = 200, $data = null)
{
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

// POST only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Require signup/login session
if (!isset($_SESSION["user_id"])) {
    respond(false, "Please sign up or log in first.", 401);
}

// Read JSON
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    respond(false, "Invalid JSON request.", 400);
}

// Required fields
$required = [
    "full_name",
    "email",
    "diabetes_type",
    "age",
    "height_cm",
    "weight_kg"
];

foreach ($required as $field) {
    if (!array_key_exists($field, $data)) {
        respond(false, "Missing required field: " . $field, 400);
    }
}

$full_name = trim($data["full_name"]);
$email = trim($data["email"]);
$diabetes_type = $data["diabetes_type"];
$age = $data["age"];
$height = $data["height_cm"];
$weight = $data["weight_kg"];

// Validate name
if (!is_string($full_name) || $full_name === "" || strlen($full_name) > 100) {
    respond(false, "Enter a valid full name.", 400);
}

// Validate email
if (
    !is_string($email) ||
    strlen($email) > 255 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    respond(false, "Enter a valid email address.", 400);
}

// Validate diabetes type
$allowedTypes = [
    "Type 1",
    "Type 2",
    "Gestational",
    "LADA/Other",
    "Pre-diabetes"
];

if (
    !is_string($diabetes_type) ||
    !in_array($diabetes_type, $allowedTypes, true)
) {
    respond(false, "Invalid diabetes type.", 400);
}

// Validate age: integer 18–80
if (
    !is_int($age) ||
    $age < 18 ||
    $age > 80
) {
    respond(false, "Age must be an integer from 18 to 80.", 400);
}

// Validate height: 140–210 cm
if (
    !is_numeric($height) ||
    !is_finite((float)$height) ||
    (float)$height < 140 ||
    (float)$height > 210
) {
    respond(false, "Height must be from 140 to 210 cm.", 400);
}

// Validate weight: 40–140 kg
if (
    !is_numeric($weight) ||
    !is_finite((float)$weight) ||
    (float)$weight < 40 ||
    (float)$weight > 140
) {
    respond(false, "Weight must be from 40 to 140 kg.", 400);
}

$user_id = (int) $_SESSION["user_id"];

try {
    // Check whether this email belongs to another user
    $check = $pdo->prepare(
        "SELECT id FROM users
         WHERE email = :email AND id != :user_id
         LIMIT 1"
    );

    $check->execute([
        ":email" => $email,
        ":user_id" => $user_id
    ]);

    if ($check->fetch()) {
        respond(false, "This email is already registered to another user.", 409);
    }

    // Update the current user's profile
    $stmt = $pdo->prepare(
        "UPDATE users SET
            full_name = :full_name,
            email = :email,
            diabetes_type = :diabetes_type,
            age = :age,
            height_cm = :height,
            weight_kg = :weight
         WHERE id = :id"
    );

    $stmt->execute([
        ":full_name" => $full_name,
        ":email" => $email,
        ":diabetes_type" => $diabetes_type,
        ":age" => $age,
        ":height" => (float)$height,
        ":weight" => (float)$weight,
        ":id" => $user_id
    ]);

    respond(true, "Patient profile updated successfully.", 200, [
        "full_name" => $full_name,
        "email" => $email,
        "diabetes_type" => $diabetes_type,
        "age" => $age,
        "height_cm" => (float)$height,
        "weight_kg" => (float)$weight
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());
    respond(false, "Database error. Please try again.", 500);
}
?>
