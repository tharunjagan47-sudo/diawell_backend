<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];
$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in first.", 401);
}

// ==========================================
// DELETE or POST remove: Remove profile photo completely
// ==========================================
if (isRemovePhotoRequest($method, $data)) {
    try {
        if (clearUserProfilePhoto($pdo, $userId) === null) {
            respond(false, "User not found with ID: " . $userId, 404);
        }

        respond(true, "Profile photo removed successfully.", 200, [
            "user_id" => (int) $userId,
            "profile_photo" => null,
            "profile_photo_url" => null,
            "profile_pic" => null
        ]);
    } catch (Throwable $e) {
        error_log("Remove profile photo error: " . $e->getMessage());
        respond(false, "Failed to remove profile photo: " . $e->getMessage(), 500);
    }
}

// ==========================================
// GET: View full user profile with photo & preferences
// ==========================================
if ($method === "GET") {
    try {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.diabetes_type, u.age,
                    u.height_cm, u.weight_kg, u.profile_photo, u.profile_pic,
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
            $host = $_SERVER["HTTP_HOST"] ?? "127.0.0.1";
            $photoUrl = "http://" . $host . "/DiaWell_backend/" . ltrim($user["profile_photo"], "/");
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
            "profile_pic" => $user["profile_pic"] ?? null,
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
// POST: Upload Profile Photo (JSON Base64 or multipart)
// ==========================================
if ($method === "POST") {
    // 1. Check if Base64 JSON payload is passed
    $profilePic = trim($data["profile_pic"] ?? $data["profilePic"] ?? $data["image_base64"] ?? "");
    if (!empty($profilePic)) {
        if (preg_match('#^data:image/\w+;base64,#i', $profilePic)) {
            $profilePic = preg_replace('#^data:image/\w+;base64,#i', '', $profilePic);
        }
        try {
            $update = $pdo->prepare("UPDATE users SET profile_pic = :pic WHERE id = :id");
            $update->execute([":pic" => $profilePic, ":id" => $userId]);

            respond(true, "Profile photo updated successfully.", 200, [
                "user_id" => (int) $userId,
                "profile_pic" => $profilePic
            ]);
        } catch (Throwable $e) {
            respond(false, "Failed to update profile pic: " . $e->getMessage(), 500);
        }
    }

    // 2. Fallback to multipart file upload
    $file = null;
    $candidateKeys = ["profile_photo", "photo", "image", "file", "avatar", "picture"];
    foreach ($candidateKeys as $k) {
        if (isset($_FILES[$k]) && is_array($_FILES[$k]) && !empty($_FILES[$k]["tmp_name"])) {
            $file = $_FILES[$k];
            break;
        }
    }
    if (!$file && !empty($_FILES)) {
        $first = reset($_FILES);
        if (is_array($first) && !empty($first["tmp_name"])) {
            $file = $first;
        }
    }

    if (!$file) {
        respond(false, "Please provide 'profile_pic' (Base64) or upload a photo using key 'profile_photo'.", 400);
    }

    if ($file["error"] !== UPLOAD_ERR_OK) {
        respond(false, "File upload failed with error code: " . $file["error"], 400);
    }

    if ($file["size"] > 10 * 1024 * 1024) {
        respond(false, "Profile photo size must be smaller than 10 MB.", 400);
    }

    $uploadDir = __DIR__ . "/../upload/profile_photos/";
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        respond(false, "Could not create upload directory on server.", 500);
    }

    $extension = "jpg";
    $imageInfo = @getimagesize($file["tmp_name"]);
    if ($imageInfo !== false && !empty($imageInfo["mime"])) {
        $mime = strtolower($imageInfo["mime"]);
        if (strpos($mime, "png") !== false) {
            $extension = "png";
        } elseif (strpos($mime, "webp") !== false) {
            $extension = "webp";
        }
    }

    $filename = bin2hex(random_bytes(16)) . "." . $extension;
    $destination = $uploadDir . $filename;

    try {
        if (!move_uploaded_file($file["tmp_name"], $destination) && !copy($file["tmp_name"], $destination)) {
            respond(false, "Failed to save uploaded image on the server.", 500);
        }

        $photoRelPath = "upload/profile_photos/" . $filename;
        $fileBytes = @file_get_contents($destination);
        $base64Data = ($fileBytes !== false) ? base64_encode($fileBytes) : null;

        $update = $pdo->prepare("UPDATE users SET profile_photo = :photo, profile_pic = COALESCE(:pic, profile_pic) WHERE id = :id");
        $update->execute([
            ":photo" => $photoRelPath,
            ":pic" => $base64Data,
            ":id" => $userId
        ]);

        $host = $_SERVER["HTTP_HOST"] ?? "127.0.0.1";
        $photoFullUrl = "http://" . $host . "/DiaWell_backend/" . $photoRelPath;

        respond(true, "Profile photo uploaded successfully.", 200, [
            "user_id" => (int) $userId,
            "profile_photo" => $photoRelPath,
            "profile_photo_url" => $photoFullUrl,
            "profile_pic" => $base64Data
        ]);

    } catch (Throwable $e) {
        if (is_file($destination)) {
            @unlink($destination);
        }
        error_log("Photo upload error: " . $e->getMessage());
        respond(false, "Failed to upload photo due to server error: " . $e->getMessage(), 500);
    }
}

respond(false, "Method not allowed. Use GET to view profile or POST to upload photo.", 405);
