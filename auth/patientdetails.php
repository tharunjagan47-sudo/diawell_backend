<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];

// ==========================================
// GET: Retrieve patient details
// ==========================================
if ($method === "GET") {
    $userId = getUserId();

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in.", 401);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, diabetes_type, age, height_cm, weight_kg, 
                    current_glucose_mg_dl, target_glucose_mg_dl, profile_photo 
             FROM users 
             WHERE id = :id 
             LIMIT 1"
        );
        $stmt->execute([":id" => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            respond(false, "User not found.", 404);
        }

        respond(true, "Patient details retrieved successfully.", 200, [
            "user_id" => (int) $user["id"],
            "full_name" => $user["full_name"],
            "email" => $user["email"],
            "diabetes_type" => $user["diabetes_type"],
            "age" => $user["age"] !== null ? (int) $user["age"] : null,
            "height_cm" => $user["height_cm"] !== null ? (float) $user["height_cm"] : null,
            "weight_kg" => $user["weight_kg"] !== null ? (float) $user["weight_kg"] : null,
            "current_glucose_mg_dl" => $user["current_glucose_mg_dl"] !== null ? (float) $user["current_glucose_mg_dl"] : null,
            "target_glucose_mg_dl" => $user["target_glucose_mg_dl"] !== null ? (float) $user["target_glucose_mg_dl"] : null
        ]);

    } catch (PDOException $e) {
        error_log("Patient details fetch error: " . $e->getMessage());
        respond(false, "Database error retrieving patient details.", 500);
    }
}

// ==========================================
// POST: Save/Update patient details
// ==========================================
if ($method === "POST") {
    $data = getRequestData();
    $userId = getUserId($data);

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in first.", 401);
    }

    $rawDiabetesType = $data["diabetes_type"] ?? "";
    $diabetes_type = normalizeDiabetesType($rawDiabetesType);

    if (!$diabetes_type) {
        respond(false, "Invalid diabetes type. Allowed: Type 1, Type 2, Gestational, Pre-diabetes, LADA / Other.", 400);
    }

    $age = parseNumericValue($data["age"] ?? null);
    $height = parseNumericValue($data["height_cm"] ?? $data["height"] ?? null);
    $weight = parseNumericValue($data["weight_kg"] ?? $data["weight"] ?? null);
    $current_glucose = parseNumericValue($data["current_glucose_mg_dl"] ?? $data["current_glucose"] ?? null);
    $target_glucose = parseNumericValue($data["target_glucose_mg_dl"] ?? $data["target_glucose"] ?? null);

    // Validations matching UI constraints
    if ($age === null || $age < 18 || $age > 100) {
        respond(false, "Age must be between 18 and 100 years.", 400);
    }

    if ($height === null || $height < 100 || $height > 250) {
        respond(false, "Height must be between 100 and 250 cm.", 400);
    }

    if ($weight === null || $weight < 30 || $weight > 250) {
        respond(false, "Weight must be between 30 and 250 kg.", 400);
    }

    if ($current_glucose === null || $current_glucose < 50 || $current_glucose > 400) {
        respond(false, "Current glucose reading must be between 50 and 400 mg/dL.", 400);
    }

    if ($target_glucose === null || $target_glucose < 50 || $target_glucose > 300) {
        respond(false, "Target glucose reading must be between 50 and 300 mg/dL.", 400);
    }

    try {
        // Verify user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $check->execute([":id" => $userId]);
        if (!$check->fetch()) {
            respond(false, "User account not found with ID: " . $userId, 404);
        }

        // Update user record
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
            ":age" => (int) $age,
            ":height" => (float) $height,
            ":weight" => (float) $weight,
            ":current_glucose" => (float) $current_glucose,
            ":target_glucose" => (float) $target_glucose,
            ":id" => $userId
        ]);

        respond(true, "Patient profile details saved successfully.", 200, [
            "user_id" => $userId,
            "diabetes_type" => $diabetes_type,
            "age" => (int) $age,
            "height_cm" => (float) $height,
            "weight_kg" => (float) $weight,
            "current_glucose_mg_dl" => (float) $current_glucose,
            "target_glucose_mg_dl" => (float) $target_glucose,
            "next_screen" => "health_preferences"
        ]);

    } catch (PDOException $e) {
        error_log("Patient details update error: " . $e->getMessage());
        respond(false, "Database error saving patient details. Please try again.", 500);
    }
}

respond(false, "Method not allowed. Use GET or POST.", 405);
