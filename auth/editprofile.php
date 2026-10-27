<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];

// ==========================================
// GET: Fetch editable profile data
// ==========================================
if ($method === "GET") {
    $userId = getUserId();

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in.", 401);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, diabetes_type, age, height_cm, weight_kg, 
                    profile_photo, current_glucose_mg_dl, target_glucose_mg_dl, created_at 
             FROM users 
             WHERE id = :id 
             LIMIT 1"
        );
        $stmt->execute([":id" => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            respond(false, "User not found.", 404);
        }

        $photoUrl = null;
        if (!empty($user["profile_photo"])) {
            $photoUrl = "http://" . $_SERVER["HTTP_HOST"] . "/DiaWell_backend/" . ltrim($user["profile_photo"], "/");
        }

        respond(true, "Profile details retrieved successfully.", 200, [
            "user_id" => (int) $user["id"],
            "full_name" => $user["full_name"],
            "email" => $user["email"],
            "diabetes_type" => $user["diabetes_type"] ?? "Type 2",
            "age" => $user["age"] !== null ? (int) $user["age"] : null,
            "height_cm" => $user["height_cm"] !== null ? (float) $user["height_cm"] : null,
            "weight_kg" => $user["weight_kg"] !== null ? (float) $user["weight_kg"] : null,
            "profile_photo" => $user["profile_photo"],
            "profile_photo_url" => $photoUrl
        ]);

    } catch (PDOException $e) {
        error_log("Edit profile GET error: " . $e->getMessage());
        respond(false, "Database error retrieving profile details.", 500);
    }
}

// ==========================================
// POST: Update personal profile
// ==========================================
if ($method === "POST" || $method === "PUT") {
    $data = getRequestData();
    $userId = getUserId($data);

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in first.", 401);
    }

    $full_name = trim($data["full_name"] ?? $data["fullName"] ?? "");
    $email = trim($data["email"] ?? "");
    $rawDiabetesType = $data["diabetes_type"] ?? $data["diabetesType"] ?? null;
    $diabetes_type = $rawDiabetesType ? normalizeDiabetesType($rawDiabetesType) : null;
    $age = parseNumericValue($data["age"] ?? null);
    $height = parseNumericValue($data["height_cm"] ?? $data["height"] ?? null);
    $weight = parseNumericValue($data["weight_kg"] ?? $data["weight"] ?? null);

    // Validate name if provided
    if ($full_name !== "" && mb_strlen($full_name) > 100) {
        respond(false, "Full name cannot exceed 100 characters.", 400);
    }

    // Validate email if provided
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, "Please provide a valid email address.", 400);
    }

    try {
        // Fetch existing user record
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $userId]);
        $currentUser = $stmt->fetch();

        if (!$currentUser) {
            respond(false, "User account not found with ID: " . $userId, 404);
        }

        // If email is changing, check uniqueness across other accounts
        if ($email !== "" && strcasecmp($email, $currentUser["email"]) !== 0) {
            $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $emailCheck->execute([":email" => $email, ":id" => $userId]);
            if ($emailCheck->fetch()) {
                respond(false, "The email address is already in use by another account.", 409);
            }
        }

        // Keep current values if new ones are blank/null
        $updatedName = ($full_name !== "") ? $full_name : $currentUser["full_name"];
        $updatedEmail = ($email !== "") ? $email : $currentUser["email"];
        $updatedDiabetes = $diabetes_type ?: $currentUser["diabetes_type"];
        $updatedAge = ($age !== null) ? (int) $age : $currentUser["age"];
        $updatedHeight = ($height !== null) ? (float) $height : $currentUser["height_cm"];
        $updatedWeight = ($weight !== null) ? (float) $weight : $currentUser["weight_kg"];

        // Update database
        $update = $pdo->prepare(
            "UPDATE users SET
                full_name = :full_name,
                email = :email,
                diabetes_type = :diabetes_type,
                age = :age,
                height_cm = :height_cm,
                weight_kg = :weight_kg
             WHERE id = :id"
        );

        $update->execute([
            ":full_name" => $updatedName,
            ":email" => $updatedEmail,
            ":diabetes_type" => $updatedDiabetes,
            ":age" => $updatedAge,
            ":height_cm" => $updatedHeight,
            ":weight_kg" => $updatedWeight,
            ":id" => $userId
        ]);

        $photoUrl = null;
        if (!empty($currentUser["profile_photo"])) {
            $photoUrl = "http://" . $_SERVER["HTTP_HOST"] . "/DiaWell_backend/" . ltrim($currentUser["profile_photo"], "/");
        }

        respond(true, "Profile updated successfully.", 200, [
            "user_id" => $userId,
            "full_name" => $updatedName,
            "email" => $updatedEmail,
            "diabetes_type" => $updatedDiabetes,
            "age" => $updatedAge !== null ? (int) $updatedAge : null,
            "height_cm" => $updatedHeight !== null ? (float) $updatedHeight : null,
            "weight_kg" => $updatedWeight !== null ? (float) $updatedWeight : null,
            "profile_photo" => $currentUser["profile_photo"],
            "profile_photo_url" => $photoUrl
        ]);

    } catch (PDOException $e) {
        error_log("Edit profile update error: " . $e->getMessage());
        respond(false, "Database error updating profile details.", 500);
    }
}

respond(false, "Method not allowed. Use GET or POST.", 405);

