
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

// Only POST requests are allowed
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

// Require a logged-in user
if (!isset($_SESSION["user_id"])) {
    respond(false, "Please log in first.", 401);
}

// Read JSON request
$input = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    respond(false, "Invalid JSON request.", 400);
}

// Read patient details
$diabetes_type = $input["diabetes_type"] ?? "";
$age = $input["age"] ?? null;
$height = $input["height_cm"] ?? null;
$weight = $input["weight_kg"] ?? null;
$current_glucose = $input["current_glucose_mg_dl"] ?? null;
$target_glucose = $input["target_glucose_mg_dl"] ?? null;

// Validate diabetes type
$allowed_types = [
    "Type 1",
    "Type 2",
    "Gestational",
    "LADA/Other",
    "Pre-diabetes"
];

if (
    !is_string($diabetes_type) ||
    !in_array($diabetes_type, $allowed_types, true)
) {
    respond(false, "Please select a valid diabetes type.", 400);
}

// Validate age: integer 18-80
if (
    !is_int($age) ||
    $age < 18 ||
    $age > 80
) {
    respond(false, "Age must be an integer between 18 and 80.", 400);
}

// Validate height: 140-210 cm
if (
    !is_numeric($height) ||
    !is_finite((float) $height) ||
    (float) $height < 140 ||
    (float) $height > 210
) {
    respond(false, "Height must be between 140 and 210 cm.", 400);
}

// Validate weight: 40-140 kg
if (
    !is_numeric($weight) ||
    !is_finite((float) $weight) ||
    (float) $weight < 40 ||
    (float) $weight > 140
) {
    respond(false, "Weight must be between 40 and 140 kg.", 400);
}

// Validate current glucose: 70-220 mg/dL
if (
    !is_numeric($current_glucose) ||
    !is_finite((float) $current_glucose) ||
    (float) $current_glucose < 70 ||
    (float) $current_glucose > 220
) {
    respond(
        false,
        "Current glucose reading must be between 70 and 220 mg/dL.",
        400
    );
}

// Validate target glucose: 70-180 mg/dL
if (
    !is_numeric($target_glucose) ||
    !is_finite((float) $target_glucose) ||
    (float) $target_glucose < 70 ||
    (float) $target_glucose > 180
) {
    respond(
        false,
        "Target glucose reading must be between 70 and 180 mg/dL.",
        400
    );
}

try {
    $user_id = (int) $_SESSION["user_id"];

    // Save details for the logged-in user
    $stmt = $pdo->prepare(
        "UPDATE users SET
            diabetes_type = :diabetes_type,
            age = :age,
            height_cm = :height,
            weight_kg = :weight,
            current_glucose_mg_dl = :current_glucose,
            target_glucose_mg_dl = :target_glucose
         WHERE id = :id"
    );

    $stmt->execute([
        ":diabetes_type" => $diabetes_type,
        ":age" => $age,
        ":height" => (float) $height,
        ":weight" => (float) $weight,
        ":current_glucose" => (float) $current_glucose,
        ":target_glucose" => (float) $target_glucose,
        ":id" => $user_id
    ]);

    // Check that the logged-in user exists
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare(
            "SELECT id FROM users WHERE id = :id LIMIT 1"
        );
        $check->execute([":id" => $user_id]);

        if (!$check->fetch()) {
            respond(false, "User account not found.", 404);
        }
    }

    respond(true, "Patient details saved successfully.", 200, [
        "diabetes_type" => $diabetes_type,
        "age" => $age,
        "height_cm" => (float) $height,
        "weight_kg" => (float) $weight,
        "current_glucose_mg_dl" => (float) $current_glucose,
        "target_glucose_mg_dl" => (float) $target_glucose
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());

    respond(false, "Database error. Please try again.", 500);
}
?>
