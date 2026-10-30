<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Allow GET and POST for fetching and adjusting inputs
$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET" && $method !== "POST") {
    respond(false, "Method not allowed. Use GET or POST.", 405);
}

$data = getRequestData();
$userId = getUserId($data);

if (!$userId) {
    respond(false, "Authentication required. Please log in to get personalized AI food recommendations.", 401);
}

// Configurable Python AI Microservice URL
$pythonServiceUrl = getenv("AI_SERVICE_URL") ?: "http://127.0.0.1:8001";
$csvPath = __DIR__ . '/../data/Indian_Food_Nutrition_Processed.csv';

try {
    // 1. Fetch patient profile
    $userStmt = $pdo->prepare(
        "SELECT id, full_name, diabetes_type, age, height_cm, weight_kg, current_glucose_mg_dl, target_glucose_mg_dl
         FROM users
         WHERE id = :id LIMIT 1"
    );
    $userStmt->execute([":id" => $userId]);
    $userRow = $userStmt->fetch();

    if (!$userRow) {
        respond(false, "User profile not found.", 404);
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

    if ($latestGlucose && isset($latestGlucose["glucose_value"])) {
        $effectiveGlucose = (float) $latestGlucose["glucose_value"];
    } elseif ($userRow["current_glucose_mg_dl"] !== null) {
        $effectiveGlucose = (float) $userRow["current_glucose_mg_dl"];
    }

    // Check if client explicitly passed an updated glucose reading
    if (isset($data["current_glucose"]) && is_numeric($data["current_glucose"])) {
        $effectiveGlucose = (float) $data["current_glucose"];
    } elseif (isset($_GET["current_glucose"]) && is_numeric($_GET["current_glucose"])) {
        $effectiveGlucose = (float) $_GET["current_glucose"];
    } elseif (isset($_GET["glucose"]) && is_numeric($_GET["glucose"])) {
        $effectiveGlucose = (float) $_GET["glucose"];
    }

    // 3. Fetch health preferences
    $hpStmt = $pdo->prepare("SELECT health_focus, dietary_preferences, allergies, activity_level FROM health_preferences WHERE user_id = :id LIMIT 1");
    $hpStmt->execute([":id" => $userId]);
    $hpRow = $hpStmt->fetch();

    // 4. Fetch food preferences
    $fpStmt = $pdo->prepare("SELECT preferred_diet, allergies, preferred_styles, foods_to_avoid FROM food_preferences WHERE user_id = :id LIMIT 1");
    $fpStmt->execute([":id" => $userId]);
    $fpRow = $fpStmt->fetch();

    // Helper to decode JSON string or comma-separated list into array
    $parseList = function($raw) {
        if (empty($raw)) return [];
        if (is_array($raw)) return $raw;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    };

    $allergies1 = $parseList($hpRow["allergies"] ?? "");
    $allergies2 = $parseList($fpRow["allergies"] ?? "");
    $allAllergies = array_values(array_unique(array_merge($allergies1, $allergies2)));
    $avoidList = $parseList($fpRow["foods_to_avoid"] ?? "");

    // Determine diet preference
    $dietPref = $fpRow["preferred_diet"] ?? "";
    if (empty($dietPref)) {
        $diets = $parseList($hpRow["dietary_preferences"] ?? "");
        $dietPref = !empty($diets) ? $diets[0] : "Non-Vegetarian";
    }

    // Support client overrides (array or comma-separated string)
    if (!empty($data["dietary_preferences"])) {
        if (is_array($data["dietary_preferences"])) {
            $dietPref = implode(", ", $data["dietary_preferences"]);
        } else {
            $dietPref = trim($data["dietary_preferences"]);
        }
    } elseif (!empty($_GET["diet_type"])) {
        $dietPref = trim($_GET["diet_type"]);
    }

    if (!empty($data["health_focus"])) {
        if (is_array($data["health_focus"])) {
            $healthFocus = implode(", ", $data["health_focus"]);
        } else {
            $healthFocus = trim($data["health_focus"]);
        }
    } elseif (!empty($_GET["health_focus"])) {
        $healthFocus = trim($_GET["health_focus"]);
    } else {
        $healthFocus = $hpRow["health_focus"] ?? "Glucose Control";
    }
    $category = trim($_GET["category"] ?? $data["category"] ?? "All");
    $limit = (int) ($data["limit"] ?? $_GET["limit"] ?? 10);

    // If POST requested to update user profile preferences, persist to MySQL
    if ($method === "POST" && (!empty($data["update_inputs"]) || !empty($data["save_preferences"]))) {
        if ($effectiveGlucose !== null) {
            $updUser = $pdo->prepare("UPDATE users SET current_glucose_mg_dl = :g WHERE id = :id");
            $updUser->execute([":g" => $effectiveGlucose, ":id" => $userId]);

            $insG = $pdo->prepare("INSERT INTO glucose_readings (user_id, glucose_value, reading_type, reading_date, reading_time, notes) VALUES (:u, :v, 'Diet Evaluation', CURDATE(), CURTIME(), 'Updated via Diet Screen')");
            $insG->execute([":u" => $userId, ":v" => $effectiveGlucose]);
        }
        if (!empty($dietPref)) {
            $updFp = $pdo->prepare("INSERT INTO food_preferences (user_id, preferred_diet) VALUES (:id, :diet) ON DUPLICATE KEY UPDATE preferred_diet = :diet2");
            $updFp->execute([":id" => $userId, ":diet" => $dietPref, ":diet2" => $dietPref]);

            $dietArr = array_values(array_filter(array_map('trim', explode(',', $dietPref))));
            $updHpDiet = $pdo->prepare("INSERT INTO health_preferences (user_id, dietary_preferences) VALUES (:id, :diets) ON DUPLICATE KEY UPDATE dietary_preferences = :diets2");
            $updHpDiet->execute([":id" => $userId, ":diets" => json_encode($dietArr), ":diets2" => json_encode($dietArr)]);
        }
        if (!empty($healthFocus)) {
            $updHp = $pdo->prepare("INSERT INTO health_preferences (user_id, health_focus) VALUES (:id, :hf) ON DUPLICATE KEY UPDATE health_focus = :hf2");
            $updHp->execute([":id" => $userId, ":hf" => $healthFocus, ":hf2" => $healthFocus]);
        }
    }

    // Build payload for Python AI service including patient health parameters
    $payload = [
        "user_id" => $userId,
        "diabetes_type" => $data["diabetes_type"] ?? $_GET["diabetes_type"] ?? $userRow["diabetes_type"] ?? "Type 2",
        "current_glucose_mg_dl" => $effectiveGlucose,
        "target_glucose_mg_dl" => isset($data["target_glucose_mg_dl"]) ? (float)$data["target_glucose_mg_dl"] : ($userRow["target_glucose_mg_dl"] ? (float)$userRow["target_glucose_mg_dl"] : 120.0),
        "reading_type" => $readingType,
        "reading_date" => $readingDate,
        "height_cm" => isset($userRow["height_cm"]) ? (float)$userRow["height_cm"] : null,
        "weight_kg" => isset($userRow["weight_kg"]) ? (float)$userRow["weight_kg"] : null,
        "age" => isset($userRow["age"]) ? (int)$userRow["age"] : null,
        "dietary_preferences" => $dietPref,
        "allergies" => $allAllergies,
        "foods_to_avoid" => $avoidList,
        "health_focus" => $healthFocus,
        "activity_level" => $data["activity_level"] ?? $hpRow["activity_level"] ?? "Moderate",
        "category" => ($category !== "All" && $category !== "") ? $category : null,
        "limit" => $limit
    ];

    // Call Python FastAPI service via cURL
    $ch = curl_init($pythonServiceUrl . "/recommend");
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
        }
    }

    // =========================================================================
    // RESILIENT CSV FALLBACK: If Python microservice is restarting,
    // evaluate foods directly from Indian_Food_Nutrition_Processed.csv!
    // Never return "Food Not Found" when dataset exists on disk.
    // =========================================================================
    error_log("Python AI service unavailable at {$pythonServiceUrl}/recommend: {$curlError} (HTTP {$httpCode}). Using direct CSV dataset reader.");

    if (file_exists($csvPath)) {
        $handle = fopen($csvPath, "r");
        if ($handle !== false) {
            $headers = fgetcsv($handle);
            $colMap = [];
            foreach ($headers as $idx => $h) {
                $clean = trim($h);
                if (stripos($clean, "Dish") !== false) $colMap["dish"] = $idx;
                elseif (stripos($clean, "Calorie") !== false) $colMap["calories"] = $idx;
                elseif (stripos($clean, "Carb") !== false) $colMap["carbs"] = $idx;
                elseif (stripos($clean, "Protein") !== false) $colMap["protein"] = $idx;
                elseif (stripos($clean, "Fat") !== false && stripos($clean, "Free") === false) $colMap["fat"] = $idx;
                elseif (stripos($clean, "Free Sugar") !== false || stripos($clean, "Sugar") !== false) $colMap["sugar"] = $idx;
                elseif (stripos($clean, "Fibre") !== false || stripos($clean, "Fiber") !== false) $colMap["fibre"] = $idx;
                elseif (stripos($clean, "Sodium") !== false) $colMap["sodium"] = $idx;
                elseif (stripos($clean, "Calcium") !== false) $colMap["calcium"] = $idx;
                elseif (stripos($clean, "Iron") !== false) $colMap["iron"] = $idx;
                elseif (stripos($clean, "Vitamin C") !== false) $colMap["vit_c"] = $idx;
                elseif (stripos($clean, "Folate") !== false) $colMap["folate"] = $idx;
            }

            $csvDishes = [];
            $itemId = 1001;
            $nonVegWords = ["chicken", "mutton", "fish", "egg", "prawn", "keema", "meat", "pork", "beef", "shrimp", "seafood"];
            $isVegUser = (stripos($dietPref, "non") === false && stripos($dietPref, "veg") !== false);

            while (($row = fgetcsv($handle)) !== false) {
                $dishName = trim($row[$colMap["dish"] ?? 0] ?? "");
                if ($dishName === "") continue;

                $dishLower = strtolower($dishName);
                $isNonVeg = false;
                foreach ($nonVegWords as $w) {
                    if (strpos($dishLower, $w) !== false) {
                        $isNonVeg = true;
                        break;
                    }
                }
                $dishDietType = $isNonVeg ? "Non-Vegetarian" : "Vegetarian";

                if ($isVegUser && $dishDietType === "Non-Vegetarian") {
                    $itemId++;
                    continue;
                }

                $cal = isset($colMap["calories"]) && is_numeric($row[$colMap["calories"]]) ? round((float)$row[$colMap["calories"]], 1) : 150.0;
                $carbs = isset($colMap["carbs"]) && is_numeric($row[$colMap["carbs"]]) ? round((float)$row[$colMap["carbs"]], 1) : 15.0;
                $sugar = isset($colMap["sugar"]) && is_numeric($row[$colMap["sugar"]]) ? round((float)$row[$colMap["sugar"]], 1) : 0.0;
                $fibre = isset($colMap["fibre"]) && is_numeric($row[$colMap["fibre"]]) ? round((float)$row[$colMap["fibre"]], 1) : 2.0;
                $protein = isset($colMap["protein"]) && is_numeric($row[$colMap["protein"]]) ? round((float)$row[$colMap["protein"]], 1) : 5.0;
                $fat = isset($colMap["fat"]) && is_numeric($row[$colMap["fat"]]) ? round((float)$row[$colMap["fat"]], 1) : 4.0;
                $sodium = isset($colMap["sodium"]) && is_numeric($row[$colMap["sodium"]]) ? round((float)$row[$colMap["sodium"]], 1) : 120.0;

                // Transparent diabetes scoring
                $score = 70;
                if ($sugar <= 1.0) $score += 12;
                elseif ($sugar > 5.0) $score -= 15;
                if ($fibre >= 4.0) $score += 14;
                elseif ($fibre >= 2.0) $score += 6;
                if ($carbs <= 15.0) $score += 10;
                elseif ($carbs > 40.0) $score -= 10;
                if ($protein >= 10.0) $score += 8;

                $sugarDesc = $sugar <= 1.0 ? "low free sugar ({$sugar}g)" : "moderate free sugar ({$sugar}g)";
                $carbDesc = $carbs <= 15.0 ? "low carb load ({$carbs}g)" : "moderate carbohydrates ({$carbs}g)";
                $fibreDesc = $fibre >= 3.0 ? "beneficial fibre ({$fibre}g)" : "fibre content ({$fibre}g)";

                $csvDishes[] = [
                    "id" => $itemId,
                    "title" => $dishName,
                    "dish_name" => $dishName,
                    "calories" => $cal,
                    "calories_label" => "{$cal} kcal",
                    "carbs_g" => $carbs,
                    "protein_g" => $protein,
                    "fat_g" => $fat,
                    "free_sugar_g" => $sugar,
                    "fibre_g" => $fibre,
                    "sodium_mg" => $sodium,
                    "calcium_mg" => isset($colMap["calcium"]) && is_numeric($row[$colMap["calcium"]]) ? (float)$row[$colMap["calcium"]] : null,
                    "iron_mg" => isset($colMap["iron"]) && is_numeric($row[$colMap["iron"]]) ? (float)$row[$colMap["iron"]] : null,
                    "vitamin_c_mg" => isset($colMap["vit_c"]) && is_numeric($row[$colMap["vit_c"]]) ? (float)$row[$colMap["vit_c"]] : null,
                    "folate_ug" => isset($colMap["folate"]) && is_numeric($row[$colMap["folate"]]) ? (float)$row[$colMap["folate"]] : null,
                    "category" => "Lunch/Dinner",
                    "diet_type" => $dishDietType,
                    "portion" => "100 g portion basis",
                    "icon" => $isNonVeg ? "🍗" : "🥗",
                    "recommendation_score" => min(98, max(20, $score)),
                    "recommendation_reason" => "Recommended because this food has {$sugarDesc}, {$carbDesc}, and provides {$fibreDesc} compared with other dataset options.",
                    "highlights" => ["{$carbs}g Carbs", "{$fibre}g Fibre"],
                    "limitations" => ["Ingredient formulation varies by preparation; verify full ingredients with recipe creator or clinician."],
                    "glycemic_impact" => [
                        "level" => ($carbs - $fibre <= 15.0) ? "Low" : "Moderate",
                        "badge" => ($carbs - $fibre <= 15.0) ? "Low Glycemic Impact" : "Moderate Glycemic Impact",
                        "color" => ($carbs - $fibre <= 15.0) ? "#2E7D32" : "#E65100",
                        "bg_color" => ($carbs - $fibre <= 15.0) ? "#E8F5E9" : "#FFF3E0",
                        "description" => "Evaluated from CSV dataset macronutrients."
                    ]
                ];
                $itemId++;
            }
            fclose($handle);

            usort($csvDishes, function($a, $b) {
                return $b["recommendation_score"] <=> $a["recommendation_score"];
            });

            $topDishes = array_slice($csvDishes, 0, $limit);

            respond(true, "Personalized food recommendations (CSV dataset direct)", 200, [
                "patient_context" => [
                    "diabetes_type" => $userRow["diabetes_type"] ?? "Type 2",
                    "current_glucose_mg_dl" => $effectiveGlucose,
                    "target_glucose_mg_dl" => (float) ($userRow["target_glucose_mg_dl"] ?? 120.0),
                    "reading_type" => $readingType,
                    "reading_date" => $readingDate,
                    "glucose_status" => ($effectiveGlucose && $effectiveGlucose > 180 ? "high" : ($effectiveGlucose && $effectiveGlucose < 70 ? "hypoglycemia" : "normal")),
                    "height_cm" => isset($userRow["height_cm"]) ? (float)$userRow["height_cm"] : null,
                    "weight_kg" => isset($userRow["weight_kg"]) ? (float)$userRow["weight_kg"] : null,
                    "age" => isset($userRow["age"]) ? (int)$userRow["age"] : null,
                    "dietary_preference" => $dietPref,
                    "allergies" => $allAllergies,
                    "health_focus" => $healthFocus,
                    "activity_level" => $hpRow["activity_level"] ?? "Moderate",
                    "category_filter" => $category
                ],
                "hypoglycemia_alert" => ($effectiveGlucose && $effectiveGlucose < 70 ? "Blood glucose below 70 mg/dL: consume fast-acting carbohydrates." : null),
                "recommendations_count" => count($topDishes),
                "recommendations" => $topDishes,
                "daily_plan" => [
                    "breakfast" => array_slice($topDishes, 0, 2),
                    "lunch" => array_slice($topDishes, 2, 2),
                    "snack" => array_slice($topDishes, 4, 1),
                    "dinner" => array_slice($topDishes, 5, 2)
                ],
                "disclaimer" => "Medical Disclaimer: Suggested foods are calculated from your food nutrition dataset based on diabetic health guidelines."
            ]);
        }
    }

    // 3. Fallback to MySQL food_items table if CSV file was not readable
    $fallbackStmt = $pdo->prepare(
        "SELECT id, name AS title, category, diet_type, `portion`, calories, carbs_g, protein_g, fat_g, fiber_g, glycemic_index, icon, health_benefits
         FROM food_items
         WHERE diet_type = :pref OR :is_non_veg = 1
         ORDER BY CASE WHEN fiber_g >= 3.0 THEN 0 ELSE 1 END, carbs_g ASC
         LIMIT :lim"
    );
    $isNonVeg = (strcasecmp($dietPref, "Non-Vegetarian") === 0) ? 1 : 0;
    $fallbackStmt->bindValue(":pref", $dietPref, PDO::PARAM_STR);
    $fallbackStmt->bindValue(":is_non_veg", $isNonVeg, PDO::PARAM_INT);
    $fallbackStmt->bindValue(":lim", $limit, PDO::PARAM_INT);
    $fallbackStmt->execute();
    $fallbackItems = $fallbackStmt->fetchAll();

    $fallbackResults = [];
    foreach ($fallbackItems as $item) {
        $fallbackResults[] = [
            "id" => (int) $item["id"],
            "title" => $item["title"],
            "dish_name" => $item["title"],
            "calories" => (float) $item["calories"],
            "calories_label" => "{$item["calories"]} kcal",
            "carbs_g" => (float) $item["carbs_g"],
            "protein_g" => (float) $item["protein_g"],
            "fat_g" => (float) $item["fat_g"],
            "free_sugar_g" => 0.0,
            "fibre_g" => (float) $item["fiber_g"],
            "sodium_mg" => 180.0,
            "category" => $item["category"],
            "diet_type" => $item["diet_type"],
            "portion" => $item["portion"],
            "icon" => $item["icon"] ?? "🥣",
            "recommendation_score" => 90,
            "recommendation_reason" => "High dietary fibre supports stable glycemic control.",
            "highlights" => ["Fiber " . $item["fiber_g"] . "g"],
            "limitations" => ["Formulations vary by preparation; verify ingredients."],
            "glycemic_impact" => [
                "level" => $item["glycemic_index"] ?? "Low",
                "badge" => ($item["glycemic_index"] ?? "Low") . " Glycemic Index",
                "color" => "#2E7D32",
                "bg_color" => "#E8F5E9",
                "description" => "Gradual glucose absorption."
            ]
        ];
    }

    respond(true, "Personalized food recommendations (fallback)", 200, [
        "patient_context" => [
            "diabetes_type" => $userRow["diabetes_type"] ?? "Type 2",
            "current_glucose_mg_dl" => $effectiveGlucose,
            "target_glucose_mg_dl" => (float) ($userRow["target_glucose_mg_dl"] ?? 120.0),
            "reading_type" => $readingType,
            "reading_date" => $readingDate,
            "glucose_status" => ($effectiveGlucose && $effectiveGlucose > 180 ? "high" : ($effectiveGlucose && $effectiveGlucose < 70 ? "hypoglycemia" : "normal")),
            "dietary_preference" => $dietPref,
            "allergies" => $allAllergies,
            "health_focus" => $healthFocus,
            "activity_level" => $hpRow["activity_level"] ?? "Moderate",
            "category_filter" => $category
        ],
        "hypoglycemia_alert" => ($effectiveGlucose && $effectiveGlucose < 70 ? "Blood glucose below 70 mg/dL: consume fast-acting carbohydrates." : null),
        "recommendations_count" => count($fallbackResults),
        "recommendations" => $fallbackResults,
        "daily_plan" => [
            "breakfast" => array_slice($fallbackResults, 0, 2),
            "lunch" => array_slice($fallbackResults, 2, 2),
            "snack" => array_slice($fallbackResults, 4, 1),
            "dinner" => array_slice($fallbackResults, 5, 2)
        ],
        "disclaimer" => "Medical Disclaimer: Suggested foods are based on your diabetes health profile."
    ]);

} catch (PDOException $e) {
    error_log("Food recommendations error: " . $e->getMessage());
    respond(false, "Database error retrieving recommendations.", 500);
}
