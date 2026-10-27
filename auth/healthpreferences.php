<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];

// ==========================================
// GET: Retrieve Health Preferences
// ==========================================
if ($method === "GET") {
    $userId = getUserId();

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in.", 401);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, health_focus, dietary_preferences, allergies, activity_level, updated_at 
             FROM health_preferences 
             WHERE user_id = :user_id 
             LIMIT 1"
        );
        $stmt->execute([":user_id" => $userId]);
        $pref = $stmt->fetch();

        if (!$pref) {
            // Return defaults matching HealthPreferencesScreen.kt UI initial state
            respond(true, "No saved health preferences found; returning default options.", 200, [
                "user_id" => $userId,
                "health_focus" => "Glucose Control",
                "dietary_preferences" => ["Non-Vegetarian", "Low-Carb / Keto"],
                "allergies" => ["Nuts"],
                "activity_level" => "Moderate (3-5 days/week)",
                "is_configured" => false
            ]);
        }

        respond(true, "Health preferences retrieved successfully.", 200, [
            "id" => (int) $pref["id"],
            "user_id" => (int) $pref["user_id"],
            "health_focus" => $pref["health_focus"],
            "dietary_preferences" => decodeDbJson($pref["dietary_preferences"]),
            "allergies" => decodeDbJson($pref["allergies"]),
            "activity_level" => $pref["activity_level"],
            "updated_at" => $pref["updated_at"],
            "is_configured" => true
        ]);

    } catch (PDOException $e) {
        error_log("Health preferences fetch error: " . $e->getMessage());
        respond(false, "Database error retrieving health preferences.", 500);
    }
}

// ==========================================
// POST: Save/Update Health Preferences
// ==========================================
if ($method === "POST") {
    $data = getRequestData();
    $userId = getUserId($data);

    if (!$userId) {
        respond(false, "User not specified. Please provide user_id or log in first.", 401);
    }

    $health_focus = trim($data["health_focus"] ?? $data["selectedHealthFocus"] ?? "Glucose Control");
    $dietary_preferences = $data["dietary_preferences"] ?? $data["selectedDiets"] ?? [];
    $allergies = $data["allergies"] ?? $data["allergiesList"] ?? [];
    $activity_level = trim($data["activity_level"] ?? $data["selectedActivityLevel"] ?? "Moderate (3-5 days/week)");

    // Convert array inputs to JSON strings for DB
    $dietaryJson = encodeDbJson($dietary_preferences);
    $allergiesJson = encodeDbJson($allergies);

    try {
        // Verify user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $check->execute([":id" => $userId]);
        if (!$check->fetch()) {
            respond(false, "User account not found with ID: " . $userId, 404);
        }

        // Upsert into health_preferences table
        $sql = "INSERT INTO health_preferences (user_id, health_focus, dietary_preferences, allergies, activity_level)
                VALUES (:user_id, :health_focus, :dietary_preferences, :allergies, :activity_level)
                ON DUPLICATE KEY UPDATE
                    health_focus = VALUES(health_focus),
                    dietary_preferences = VALUES(dietary_preferences),
                    allergies = VALUES(allergies),
                    activity_level = VALUES(activity_level),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":user_id" => $userId,
            ":health_focus" => $health_focus,
            ":dietary_preferences" => $dietaryJson,
            ":allergies" => $allergiesJson,
            ":activity_level" => $activity_level
        ]);

        respond(true, "Health preferences saved successfully.", 200, [
            "user_id" => $userId,
            "health_focus" => $health_focus,
            "dietary_preferences" => decodeDbJson($dietaryJson),
            "allergies" => decodeDbJson($allergiesJson),
            "activity_level" => $activity_level,
            "next_screen" => "home"
        ]);

    } catch (PDOException $e) {
        error_log("Health preferences save error: " . $e->getMessage());
        respond(false, "Database error saving health preferences. Please try again.", 500);
    }
}

respond(false, "Method not allowed. Use GET or POST.", 405);

