<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$userId = getUserId();
if (!$userId) {
    respond(false, "User not specified. Please provide user_id or log in.", 401);
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, weight_kg, current_glucose_mg_dl, target_glucose_mg_dl 
         FROM users 
         WHERE id = :id 
         LIMIT 1"
    );
    $stmt->execute([":id" => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "User not found.", 404);
    }

    $glucose = (float) ($user["current_glucose_mg_dl"] ?? 128.0);
    $weight = (float) ($user["weight_kg"] ?? 68.0);

    // AI recommendation multiplier based on current glucose reading
    $baseGlucoseFactor = ($glucose > 140) ? 1.25 : (($glucose > 100) ? 1.1 : 1.0);

    $activities = [
        "Walking" => [
            "type" => "Walking",
            "icon" => "🚶‍♂️",
            "recommended_mins" => (int) round(30 * $baseGlucoseFactor),
            "target_distance_km" => round(2.5 * $baseGlucoseFactor, 1),
            "met_value" => 3.8,
            "estimated_calories_burned" => (int) round(3.8 * $weight * (30 / 60.0)),
            "clinical_benefit" => "Low impact; activates skeletal muscle GLUT4 transporters to clear circulating glucose safely."
        ],
        "Cycling" => [
            "type" => "Cycling",
            "icon" => "🚴",
            "recommended_mins" => (int) round(25 * $baseGlucoseFactor),
            "target_distance_km" => round(8.0 * $baseGlucoseFactor, 1),
            "met_value" => 6.8,
            "estimated_calories_burned" => (int) round(6.8 * $weight * (25 / 60.0)),
            "clinical_benefit" => "Moderate aerobic conditioning without excessive plantar stress; reduces post-prandial resistance."
        ],
        "Running" => [
            "type" => "Running",
            "icon" => "🏃",
            "recommended_mins" => (int) round(20 * $baseGlucoseFactor),
            "target_distance_km" => round(3.5 * $baseGlucoseFactor, 1),
            "met_value" => 9.8,
            "estimated_calories_burned" => (int) round(9.8 * $weight * (20 / 60.0)),
            "clinical_benefit" => "Vigorous metabolic workout; enhances cardiac output and sustains prolonged post-exercise glucose uptake."
        ]
    ];

    respond(true, "Activity recommendations generated successfully.", 200, [
        "user_id" => $userId,
        "current_glucose_mg_dl" => $glucose,
        "weight_kg" => $weight,
        "recommendation_basis" => "Target configured for Glucose {$glucose} mg/dL • {$weight} kg",
        "recommended_activities" => array_values($activities)
    ]);

} catch (PDOException $e) {
    error_log("Activity recommendations error: " . $e->getMessage());
    respond(false, "Database error generating recommendations.", 500);
}

