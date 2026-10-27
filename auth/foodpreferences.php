<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];

// ==========================================
// GET: Retrieve Food Preferences
// ==========================================
if ($method === "GET") {
    $userId = getUserId();

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in.", 401);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, preferred_diet, allergies, preferred_styles, foods_to_avoid, updated_at 
             FROM food_preferences 
             WHERE user_id = :user_id 
             LIMIT 1"
        );
        $stmt->execute([":user_id" => $userId]);
        $pref = $stmt->fetch();

        if (!$pref) {
            // Return defaults matching FoodPreferencesSettingsScreen.kt initial UI state
            respond(true, "No saved food preferences found; returning default options.", 200, [
                "user_id" => $userId,
                "preferred_diet" => "Non-Vegetarian",
                "allergies" => ["Nuts"],
                "preferred_styles" => ["Whole grains", "Vegetables", "Fish", "Poultry"],
                "foods_to_avoid" => "",
                "is_configured" => false
            ]);
        }

        respond(true, "Food preferences retrieved successfully.", 200, [
            "id" => (int) $pref["id"],
            "user_id" => (int) $pref["user_id"],
            "preferred_diet" => $pref["preferred_diet"],
            "allergies" => decodeDbJson($pref["allergies"]),
            "preferred_styles" => decodeDbJson($pref["preferred_styles"]),
            "foods_to_avoid" => $pref["foods_to_avoid"] ?? "",
            "updated_at" => $pref["updated_at"],
            "is_configured" => true
        ]);

    } catch (PDOException $e) {
        error_log("Food preferences fetch error: " . $e->getMessage());
        respond(false, "Database error retrieving food preferences.", 500);
    }
}

// ==========================================
// POST: Save/Update Food Preferences
// ==========================================
if ($method === "POST") {
    $data = getRequestData();
    $userId = getUserId($data);

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in first.", 401);
    }

    $preferred_diet = trim($data["preferred_diet"] ?? $data["selectedDiet"] ?? "Non-Vegetarian");
    $allergies = $data["allergies"] ?? [];
    $preferred_styles = $data["preferred_styles"] ?? $data["preferredStyles"] ?? [];
    $foods_to_avoid = trim($data["foods_to_avoid"] ?? $data["foodsToAvoid"] ?? "");

    // Validate preferred diet
    $allowedDiets = ["Vegetarian", "Non-Vegetarian", "Vegan"];
    if (!in_array($preferred_diet, $allowedDiets, true)) {
        respond(false, "Invalid diet type. Allowed: Vegetarian, Non-Vegetarian, Vegan.", 400);
    }

    $allergiesJson = encodeDbJson($allergies);
    $stylesJson = encodeDbJson($preferred_styles);

    try {
        // Verify user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $check->execute([":id" => $userId]);
        if (!$check->fetch()) {
            respond(false, "User account not found with ID: " . $userId, 404);
        }

        // Upsert into food_preferences table
        $sql = "INSERT INTO food_preferences (user_id, preferred_diet, allergies, preferred_styles, foods_to_avoid)
                VALUES (:user_id, :preferred_diet, :allergies, :preferred_styles, :foods_to_avoid)
                ON DUPLICATE KEY UPDATE
                    preferred_diet = VALUES(preferred_diet),
                    allergies = VALUES(allergies),
                    preferred_styles = VALUES(preferred_styles),
                    foods_to_avoid = VALUES(foods_to_avoid),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":user_id" => $userId,
            ":preferred_diet" => $preferred_diet,
            ":allergies" => $allergiesJson,
            ":preferred_styles" => $stylesJson,
            ":foods_to_avoid" => $foods_to_avoid
        ]);

        respond(true, "Food preferences saved successfully.", 200, [
            "user_id" => $userId,
            "preferred_diet" => $preferred_diet,
            "allergies" => decodeDbJson($allergiesJson),
            "preferred_styles" => decodeDbJson($stylesJson),
            "foods_to_avoid" => $foods_to_avoid
        ]);

    } catch (PDOException $e) {
        error_log("Food preferences save error: " . $e->getMessage());
        respond(false, "Database error saving food preferences. Please try again.", 500);
    }
}

respond(false, "Method not allowed. Use GET or POST.", 405);

