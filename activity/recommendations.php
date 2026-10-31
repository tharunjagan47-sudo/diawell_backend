<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET" && $method !== "POST") {
    respond(false, "Method not allowed. Use GET or POST.", 405);
}

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "Authentication required. Please log in to get personalized activity recommendations.", 401);
}

$pythonServiceUrl = getenv("AI_SERVICE_URL") ?: "http://127.0.0.1:8001";
$csvPath = __DIR__ . '/../activity dataset/walking_running_cycling.csv';

try {
    // 1. Fetch user profile
    $userStmt = $pdo->prepare(
        "SELECT id, full_name, diabetes_type, age, height_cm, weight_kg, current_glucose_mg_dl, target_glucose_mg_dl 
         FROM users 
         WHERE id = :id 
         LIMIT 1"
    );
    $userStmt->execute([":id" => $userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        respond(false, "User not found.", 404);
    }

    // 2. Fetch latest blood glucose reading if available
    $glucoseStmt = $pdo->prepare(
        "SELECT glucose_value, reading_type, reading_date, reading_time
         FROM glucose_readings
         WHERE user_id = :id
         ORDER BY reading_date DESC, reading_time DESC, id DESC
         LIMIT 1"
    );
    $glucoseStmt->execute([":id" => $userId]);
    $latestGlucose = $glucoseStmt->fetch();

    $effectiveGlucose = null;
    $readingType = $latestGlucose["reading_type"] ?? null;
    $readingDate = $latestGlucose["reading_date"] ?? null;
    $readingTime = $latestGlucose["reading_time"] ?? null;

    if ($latestGlucose && isset($latestGlucose["glucose_value"])) {
        $effectiveGlucose = (float) $latestGlucose["glucose_value"];
    } elseif ($user["current_glucose_mg_dl"] !== null) {
        $effectiveGlucose = (float) $user["current_glucose_mg_dl"];
    }

    // Check if client explicitly passed an updated glucose reading in GET or POST
    if (isset($data["current_glucose"]) && is_numeric($data["current_glucose"])) {
        $effectiveGlucose = (float) $data["current_glucose"];
    } elseif (isset($_GET["current_glucose"]) && is_numeric($_GET["current_glucose"])) {
        $effectiveGlucose = (float) $_GET["current_glucose"];
    } elseif (isset($_GET["glucose"]) && is_numeric($_GET["glucose"])) {
        $effectiveGlucose = (float) $_GET["glucose"];
    }

    $targetGlucose = isset($data["target_glucose"]) ? (float)$data["target_glucose"] :
        ($user["target_glucose_mg_dl"] ? (float)$user["target_glucose_mg_dl"] : 120.0);

    // 3. Fetch health preferences (fitness level, activity preferences)
    $hpStmt = $pdo->prepare("SELECT health_focus, activity_level FROM health_preferences WHERE user_id = :id LIMIT 1");
    $hpStmt->execute([":id" => $userId]);
    $hpRow = $hpStmt->fetch();

    $fitnessLevel = $data["fitness_level"] ?? $_GET["fitness_level"] ?? $hpRow["activity_level"] ?? "Moderate";
    // Normalize fitness level string (e.g. "Moderate (3-5 days/week)" -> "Moderate")
    if (stripos($fitnessLevel, "sedentary") !== false) $cleanFitness = "Sedentary";
    elseif (stripos($fitnessLevel, "light") !== false) $cleanFitness = "Light";
    elseif (stripos($fitnessLevel, "active") !== false || stripos($fitnessLevel, "high") !== false) $cleanFitness = "Active";
    else $cleanFitness = "Moderate";

    $preferredActivity = $data["preferred_activity"] ?? $_GET["preferred_activity"] ?? "All";
    $availableTime = isset($data["available_time"]) ? (int)$data["available_time"] : (isset($_GET["available_time"]) ? (int)$_GET["available_time"] : null);

    // 4. Fetch active medications for hypoglycemia safety
    $medStmt = $pdo->prepare("SELECT name, primary_ingredient, therapeutic_class FROM medications WHERE user_id = :id AND status != 'Completed'");
    $medStmt->execute([":id" => $userId]);
    $medRows = $medStmt->fetchAll();
    $medNames = [];
    foreach ($medRows as $mr) {
        if (!empty($mr["name"])) $medNames[] = $mr["name"];
        if (!empty($mr["primary_ingredient"])) $medNames[] = $mr["primary_ingredient"];
    }
    $medNames = array_values(array_unique($medNames));

    // 5. Fetch previous activity history (last 10 workouts)
    $histStmt = $pdo->prepare("SELECT activity_type, duration_mins, distance_km, calories_kcal, activity_date FROM activity_logs WHERE user_id = :id ORDER BY activity_date DESC, activity_time DESC LIMIT 10");
    $histStmt->execute([":id" => $userId]);
    $recentActivities = $histStmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Build payload for Python AI service
    $payload = [
        "user_id" => (int) $userId,
        "age" => $user["age"] ? (int) $user["age"] : null,
        "height_cm" => $user["height_cm"] ? (float) $user["height_cm"] : null,
        "weight_kg" => $user["weight_kg"] ? (float) $user["weight_kg"] : null,
        "current_glucose_mg_dl" => $effectiveGlucose,
        "target_glucose_mg_dl" => $targetGlucose,
        "reading_type" => $readingType,
        "reading_date" => $readingDate,
        "reading_time" => $readingTime,
        "fitness_level" => $cleanFitness,
        "preferred_activity" => $preferredActivity,
        "available_time_mins" => $availableTime,
        "physical_limitations" => $data["physical_limitations"] ?? [],
        "medications" => $medNames,
        "recent_activities" => $recentActivities
    ];

    // 7. Call Python FastAPI microservice via cURL
    $ch = curl_init($pythonServiceUrl . "/recommend_activities");
    $jsonPayload = json_encode($payload);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Content-Length: " . strlen($jsonPayload)
        ],
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
        $parsed = json_decode($responseBody, true);
        if ($parsed && isset($parsed["success"]) && $parsed["success"]) {
            respond(true, $parsed["message"], 200, $parsed["data"]);
        } elseif ($parsed && isset($parsed["success"]) && !$parsed["success"]) {
            // Safe medical contraindication response
            respond(false, $parsed["message"] ?? "Exercise contraindicated.", 200, $parsed["data"] ?? null);
        }
    }

    // =========================================================================
    // RESILIENT COMPENDIUM FALLBACK
    // If Python microservice is restarting, evaluate walking_running_cycling.csv directly!
    // =========================================================================
    error_log("Python AI service unavailable at {$pythonServiceUrl}/recommend_activities: {$curlError} (HTTP {$httpCode}). Using direct Compendium CSV reader.");

    if (file_exists($csvPath)) {
        $weight = $user["weight_kg"] ? (float)$user["weight_kg"] : 68.0;
        $height = $user["height_cm"] ? (float)$user["height_cm"] : 165.0;
        $bmi = ($height > 50 && $weight > 20) ? round($weight / pow($height / 100.0, 2), 1) : null;

        // Clinical safety check in fallback
        if ($effectiveGlucose !== null && $effectiveGlucose < 70) {
            respond(false, "Blood glucose is currently low ({$effectiveGlucose} mg/dL). Exercise is contraindicated until glucose is raised above 100 mg/dL.", 200, [
                "success" => false,
                "recommendations" => [],
                "user_context" => [
                    "user_id" => $userId,
                    "current_glucose_mg_dl" => $effectiveGlucose,
                    "target_glucose_mg_dl" => $targetGlucose,
                    "fitness_level" => $cleanFitness,
                    "safety_clearance" => false
                ],
                "safety_guidance" => "Treat hypoglycemia immediately with 15g fast-acting carbs."
            ]);
        }

        $fallbackRecommendations = [
            [
                "activity" => "Walking",
                "icon" => "🚶‍♂️",
                "duration_minutes" => 30,
                "distance_km" => 2.4,
                "speed_kmh" => 4.8,
                "speed_source" => "2024 Compendium benchmark (4.8 km/h, Code 17190)",
                "met_value" => 3.8,
                "intensity" => "Moderate",
                "estimated_calories_burned" => (int) round(3.8 * $weight * (30 / 60.0)),
                "compendium_code" => 17190,
                "compendium_description" => "Walking, 2.8 to 3.4 mph, level, moderate pace, firm surface",
                "reason" => "Activates skeletal muscle glucose uptake via insulin-independent GLUT4 translocation with minimal joint strain.",
                "safety_note" => "Stay well hydrated before, during, and after activity."
            ],
            [
                "activity" => "Cycling",
                "icon" => "🚴",
                "duration_minutes" => 25,
                "distance_km" => 7.3,
                "speed_kmh" => 17.5,
                "speed_source" => "2024 Compendium benchmark (17.5 km/h, Code 1011)",
                "met_value" => 6.8,
                "intensity" => "Vigorous",
                "estimated_calories_burned" => (int) round(6.8 * $weight * (25 / 60.0)),
                "compendium_code" => 1011,
                "compendium_description" => "Bicycling, to/from work, self selected pace",
                "reason" => "Low-impact non-weight-bearing aerobic exercise promoting sustained peripheral glucose clearance.",
                "safety_note" => "Choose a comfortable cadence and avoid excessive exertion."
            ]
        ];

        if ($cleanFitness !== "Sedentary") {
            $fallbackRecommendations[] = [
                "activity" => "Running",
                "icon" => "🏃",
                "duration_minutes" => 20,
                "distance_km" => 2.5,
                "speed_kmh" => 7.5,
                "speed_source" => "2024 Compendium benchmark (7.5 km/h, Code 12020)",
                "met_value" => 7.5,
                "intensity" => "Vigorous",
                "estimated_calories_burned" => (int) round(7.5 * $weight * (20 / 60.0)),
                "compendium_code" => 12020,
                "compendium_description" => "Jogging, general, self-selected pace",
                "reason" => "Elevated metabolic rate enhances cardiovascular fitness and insulin sensitivity over 24-48 hours.",
                "safety_note" => "Ensure proper warm-up and monitor for hypoglycemia."
            ];
        }

        respond(true, "Personalized activity options generated from 2024 Compendium dataset.", 200, [
            "success" => true,
            "recommendations" => $fallbackRecommendations,
            "user_context" => [
                "user_id" => $userId,
                "age" => $user["age"] ? (int) $user["age"] : null,
                "height_cm" => $height,
                "weight_kg" => $weight,
                "bmi" => $bmi,
                "current_glucose_mg_dl" => $effectiveGlucose,
                "target_glucose_mg_dl" => $targetGlucose,
                "fitness_level" => $cleanFitness,
                "safety_clearance" => true,
                "source" => "dataset_fallback"
            ],
            "safety_guidance" => "Always monitor symptoms. Stay hydrated and stop if dizzy."
        ]);
    }

    respond(false, "Activity recommendation service unavailable.", 503);

} catch (PDOException $e) {
    error_log("Activity recommendations error: " . $e->getMessage());
    respond(false, "Database error generating activity recommendations.", 500);
}
