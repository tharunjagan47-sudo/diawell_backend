<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];
$userId = getUserId();

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in first.", 401);
}

// ==========================================
// GET: View full user profile with photo & preferences
// ==========================================
if ($method === "GET") {
    try {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.diabetes_type, u.age,
                    u.height_cm, u.weight_kg, u.profile_photo,
                    u.current_glucose_mg_dl, u.target_glucose_mg_dl, u.created_at,
                    hp.health_focus, hp.dietary_preferences, hp.allergies AS health_allergies, hp.activity_level,
                    fp.preferred_diet, fp.allergies AS food_allergies, fp.preferred_styles, fp.foods_to_avoid
             FROM users u
             LEFT JOIN health_preferences hp ON hp.user_id = u.id
             LEFT JOIN food_preferences fp ON fp.user_id = u.id
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute([":id" => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            respond(false, "User not found with ID: " . $userId, 404);
        }

        // Construct photo URL
        $photoUrl = null;
        if (!empty($user["profile_photo"])) {
            $photoUrl = "http://" . $_SERVER["HTTP_HOST"] . "/DiaWell_backend/" . ltrim($user["profile_photo"], "/");
        }

        $profileData = [
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
            "profile_photo_url" => $photoUrl,
            "created_at" => $user["created_at"],
            "health_preferences" => $user["health_focus"] ? [
                "health_focus" => $user["health_focus"],
                "dietary_preferences" => decodeDbJson($user["dietary_preferences"]),
                "allergies" => decodeDbJson($user["health_allergies"]),
                "activity_level" => $user["activity_level"]
            ] : null,
            "food_preferences" => $user["preferred_diet"] ? [
                "preferred_diet" => $user["preferred_diet"],
                "allergies" => decodeDbJson($user["food_allergies"]),
                "preferred_styles" => decodeDbJson($user["preferred_styles"]),
                "foods_to_avoid" => $user["foods_to_avoid"] ?? ""
            ] : null
        ];

        respond(true, "Profile retrieved successfully.", 200, $profileData);

    } catch (PDOException $e) {
        error_log("Get profile error: " . $e->getMessage());
        respond(false, "Database error retrieving profile.", 500);
    }
}

// ==========================================
// POST: Upload Profile Photo (multipart/form-data)
// ==========================================
if ($method === "POST") {
    if (!isset($_FILES["profile_photo"])) {
        respond(false, "Please select an image file using multipart/form-data key 'profile_photo'.", 400);
    }

    $file = $_FILES["profile_photo"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        respond(false, "File upload failed with error code: " . $file["error"], 400);
    }

    // Maximum file size: 5 MB
    if ($file["size"] > 5 * 1024 * 1024) {
        respond(false, "Profile photo size must be smaller than 5 MB.", 400);
    }

    // Verify MIME type using FileInfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file["tmp_name"]);

    $allowedMimes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    if (!isset($allowedMimes[$mime]) || @getimagesize($file["tmp_name"]) === false) {
        respond(false, "Only JPG, PNG, and WEBP images are allowed.", 400);
    }

    $uploadDir = __DIR__ . "/../upload/profile_photos/";

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        respond(false, "Could not create upload directory on server.", 500);
    }

    $extension = $allowedMimes[$mime];
    $filename = bin2hex(random_bytes(16)) . "." . $extension;
    $destination = $uploadDir . $filename;
    $newPhotoSaved = false;

    try {
        // Fetch existing user to find previous photo
        $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            respond(false, "User account not found with ID: " . $userId, 404);
        }

        // Move uploaded file
        if (!move_uploaded_file($file["tmp_name"], $destination)) {
            respond(false, "Failed to save uploaded image on the server.", 500);
        }

        $newPhotoSaved = true;
        $photoRelPath = "upload/profile_photos/" . $filename;

        // Update database
        $update = $pdo->prepare("UPDATE users SET profile_photo = :photo WHERE id = :id");
        $update->execute([
            ":photo" => $photoRelPath,
            ":id" => $userId
        ]);

        // Clean up previous photo file if it was in upload folder
        $oldPhoto = $user["profile_photo"] ?? "";
        if (strpos($oldPhoto, "upload/profile_photos/") === 0) {
            $oldFilename = basename($oldPhoto);
            $oldPath = $uploadDir . $oldFilename;
            if ($oldFilename !== $filename && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $photoFullUrl = "http://" . $_SERVER["HTTP_HOST"] . "/DiaWell_backend/" . $photoRelPath;

        respond(true, "Profile photo uploaded successfully.", 200, [
            "user_id" => $userId,
            "profile_photo" => $photoRelPath,
            "profile_photo_url" => $photoFullUrl
        ]);

    } catch (Throwable $e) {
        if ($newPhotoSaved && is_file($destination)) {
            @unlink($destination);
        }
        error_log("Photo upload error: " . $e->getMessage());
        respond(false, "Failed to upload photo due to server error.", 500);
    }
}

respond(false, "Method not allowed. Use GET to view profile or POST to upload photo.", 405);
