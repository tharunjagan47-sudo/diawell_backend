
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

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Use POST method.", 405);
}

// Check the logged-in user session
if (!isset($_SESSION["user_id"])) {
    respond(false, "Please sign up or log in first.", 401);
}

// Check whether a photo was selected
if (!isset($_FILES["profile_photo"])) {
    respond(false, "Please select a profile photo.", 400);
}

$file = $_FILES["profile_photo"];

// Check upload errors
if ($file["error"] !== UPLOAD_ERR_OK) {
    respond(false, "Photo upload failed. Error code: " . $file["error"], 400);
}

// Maximum file size: 5 MB
if ($file["size"] > 5 * 1024 * 1024) {
    respond(false, "Photo must be smaller than 5 MB.", 400);
}

// Validate the actual image type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file["tmp_name"]);

$allowedTypes = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp"
];

if (
    !isset($allowedTypes[$mimeType]) ||
    getimagesize($file["tmp_name"]) === false
) {
    respond(false, "Only JPG, PNG, and WEBP images are allowed.", 400);
}

// Store images outside the auth folder
$uploadDir = __DIR__ . "/../upload/profile_photos/";

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        respond(false, "Could not create the upload folder.", 500);
    }
}

// Generate a unique filename
$filename = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mimeType];
$destination = $uploadDir . $filename;

$userId = (int) $_SESSION["user_id"];
$newFileSaved = false;

try {
    // Find the user's existing profile photo
    $stmt = $pdo->prepare(
        "SELECT profile_photo FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute([":id" => $userId]);

    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "User not found.", 404);
    }

    // Move uploaded photo into the profile photos folder
    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        respond(false, "Could not save the uploaded photo.", 500);
    }

    $newFileSaved = true;

    // Save the relative path in the database
    $photoPath = "upload/profile_photos/" . $filename;

    $update = $pdo->prepare(
        "UPDATE users
         SET profile_photo = :photo
         WHERE id = :id"
    );

    $update->execute([
        ":photo" => $photoPath,
        ":id" => $userId
    ]);

    // Delete the old photo after the database update succeeds
    $oldPhoto = $user["profile_photo"] ?? "";

    if (
        $oldPhoto !== "" &&
        strpos($oldPhoto, "upload/profile_photos/") === 0
    ) {
        $oldFilename = basename($oldPhoto);
        $oldPath = $uploadDir . $oldFilename;

        if ($oldFilename !== $filename && is_file($oldPath)) {
            unlink($oldPath);
        }
    }

    // Build the photo URL for local XAMPP
    $photoUrl = "http://" . $_SERVER["HTTP_HOST"]
        . "/DiaWell_backend/" . $photoPath;

    respond(true, "Profile photo uploaded successfully.", 200, [
        "profile_photo" => $photoPath,
        "profile_photo_url" => $photoUrl
    ]);

} catch (Throwable $e) {
    // Remove the new file if saving to the database fails
    if ($newFileSaved && is_file($destination)) {
        unlink($destination);
    }

    error_log($e->getMessage());

    respond(false, "Upload failed. Please try again.", 500);
}
?>
