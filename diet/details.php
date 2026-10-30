<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$foodId = (int) ($_GET["food_id"] ?? $_GET["id"] ?? 0);
$dishName = trim($_GET["dish_name"] ?? $_GET["name"] ?? "");
$category = trim($_GET["category"] ?? $_GET["meal_category"] ?? "");

$pythonServiceUrl = getenv("AI_SERVICE_URL") ?: "http://127.0.0.1:8001";
$csvPath = __DIR__ . '/../data/Indian_Food_Nutrition_Processed.csv';

// Helper function to format Python or CSV food object into Android standard response
function respondWithFoodDetails($food, $foodId, $dishName) {
    $glycemic = $food["glycemic_details"] ?? [];

    $carbs = (float) ($food["carbs_g"] ?? $food["carbohydrates"] ?? 0.0);
    $protein = (float) ($food["protein_g"] ?? $food["protein"] ?? 0.0);
    $fat = (float) ($food["fat_g"] ?? $food["fats"] ?? 0.0);
    $fiber = (float) ($food["fibre_g"] ?? $food["fiber"] ?? 0.0);
    $sugar = (float) ($food["free_sugar_g"] ?? $food["free_sugar"] ?? 0.0);
    $calories = (int) round($food["calories"] ?? 0);

    $giLevel = $glycemic["level"] ?? ($carbs - $fiber <= 15.0 ? "Low" : ($carbs - $fiber <= 30.0 ? "Moderate" : "High"));
    $giBadge = $glycemic["badge"] ?? "{$giLevel} Glycemic Impact";
    $giColor = $glycemic["color"] ?? ($giLevel === "Low" ? "#2E7D32" : ($giLevel === "Moderate" ? "#E65100" : "#C62828"));
    $giBg = $glycemic["bg_color"] ?? ($giLevel === "Low" ? "#E8F5E9" : ($giLevel === "Moderate" ? "#FFF3E0" : "#FFEBEE"));
    $giDesc = $glycemic["description"] ?? "Evaluated based on dataset macronutrients.";

    $title = $food["dish_name"] ?? $food["title"] ?? ($dishName ?: "Dish #{$foodId}");
    $dietType = $food["diet_type"] ?? "Vegetarian";
    $icon = $food["icon"] ?? (strcasecmp($dietType, "Vegetarian") === 0 ? "🥗" : "🍗");

    respond(true, "Food details retrieved successfully from nutrition dataset.", 200, [
        "id" => (int) ($food["id"] ?? $foodId),
        "title" => $title,
        "category" => $food["category"] ?? "Lunch/Dinner",
        "diet_type" => $dietType,
        "portion" => $food["portion"] ?? "100 g portion basis",
        "calories" => $calories,
        "calories_label" => "{$calories} kcal",
        "macros" => [
            "carbs_g" => $carbs,
            "protein_g" => $protein,
            "fat_g" => $fat,
            "fiber_g" => $fiber,
            "summary" => "Carbs {$carbs}g • Protein {$protein}g • Fat {$fat}g • Fiber {$fiber}g"
        ],
        "micronutrients" => [
            "free_sugar_g" => $sugar,
            "sodium_mg" => (float) ($food["sodium_mg"] ?? $food["sodium"] ?? 0.0),
            "calcium_mg" => (float) ($food["calcium_mg"] ?? $food["calcium"] ?? 0.0),
            "iron_mg" => (float) ($food["iron_mg"] ?? $food["iron"] ?? 0.0),
            "vitamin_c_mg" => (float) ($food["vitamin_c_mg"] ?? $food["vitamin_c"] ?? 0.0),
            "folate_ug" => (float) ($food["folate_ug"] ?? $food["folate"] ?? 0.0)
        ],
        "glycemic_index" => $giLevel,
        "glycemic_details" => [
            "badge" => $giBadge,
            "color" => $giColor,
            "bg_color" => $giBg,
            "description" => $giDesc
        ],
        "icon" => $icon,
        "ingredients" => !empty($food["ingredients"]) ? $food["ingredients"] : [
            "Standard recipe formulation (Indian Food Composition Tables)",
            "Nutritional metrics measured per 100g basis"
        ],
        "health_benefits" => $food["recommendation_reason"] ?? "Dataset-verified nutrition: provides {$fiber}g fibre with {$sugar}g free sugar.",
        "diabetic_guidance" => ($sugar <= 2.0 ? "Ideal for diabetes management: very low free sugar." : "Monitor portion size to stay within carbohydrate budget.")
    ]);
}

// -------------------------------------------------------------
// 1. Try querying the Python AI Microservice by ID or Name
// -------------------------------------------------------------
if ($foodId >= 1000) {
    $ch = curl_init($pythonServiceUrl . "/food/id/" . $foodId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody !== false && $httpCode === 200) {
        $parsed = json_decode($responseBody, true);
        if ($parsed && isset($parsed["success"]) && $parsed["success"] && isset($parsed["data"])) {
            respondWithFoodDetails($parsed["data"], $foodId, $dishName);
        }
    }
}

if ($dishName !== "") {
    $ch = curl_init($pythonServiceUrl . "/food/" . rawurlencode($dishName));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody !== false && $httpCode === 200) {
        $parsed = json_decode($responseBody, true);
        if ($parsed && isset($parsed["success"]) && $parsed["success"] && isset($parsed["data"])) {
            respondWithFoodDetails($parsed["data"], $foodId, $dishName);
        }
    }
}

// -------------------------------------------------------------
// 2. Direct CSV Dataset Lookup Fallback
// -------------------------------------------------------------
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

        $currentId = 1001;
        $matchedRow = null;
        $nonVegWords = ["chicken", "mutton", "fish", "egg", "prawn", "keema", "meat", "pork", "beef", "shrimp", "seafood"];

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim($row[$colMap["dish"] ?? 0] ?? "");
            if ($name === "") continue;

            $isMatch = false;
            if ($foodId > 0 && $currentId === $foodId) {
                $isMatch = true;
            } elseif ($dishName !== "" && (strcasecmp($name, $dishName) === 0 || stripos($name, $dishName) !== false)) {
                $isMatch = true;
            }

            if ($isMatch) {
                $nameLower = strtolower($name);
                $isNonVeg = false;
                foreach ($nonVegWords as $w) {
                    if (strpos($nameLower, $w) !== false) {
                        $isNonVeg = true;
                        break;
                    }
                }

                $matchedRow = [
                    "id" => $currentId,
                    "dish_name" => $name,
                    "title" => $name,
                    "calories" => isset($colMap["calories"]) && is_numeric($row[$colMap["calories"]]) ? (float)$row[$colMap["calories"]] : 0,
                    "carbs_g" => isset($colMap["carbs"]) && is_numeric($row[$colMap["carbs"]]) ? (float)$row[$colMap["carbs"]] : 0,
                    "protein_g" => isset($colMap["protein"]) && is_numeric($row[$colMap["protein"]]) ? (float)$row[$colMap["protein"]] : 0,
                    "fat_g" => isset($colMap["fat"]) && is_numeric($row[$colMap["fat"]]) ? (float)$row[$colMap["fat"]] : 0,
                    "free_sugar_g" => isset($colMap["sugar"]) && is_numeric($row[$colMap["sugar"]]) ? (float)$row[$colMap["sugar"]] : 0,
                    "fibre_g" => isset($colMap["fibre"]) && is_numeric($row[$colMap["fibre"]]) ? (float)$row[$colMap["fibre"]] : 0,
                    "sodium_mg" => isset($colMap["sodium"]) && is_numeric($row[$colMap["sodium"]]) ? (float)$row[$colMap["sodium"]] : 0,
                    "calcium_mg" => isset($colMap["calcium"]) && is_numeric($row[$colMap["calcium"]]) ? (float)$row[$colMap["calcium"]] : 0,
                    "iron_mg" => isset($colMap["iron"]) && is_numeric($row[$colMap["iron"]]) ? (float)$row[$colMap["iron"]] : 0,
                    "vitamin_c_mg" => isset($colMap["vit_c"]) && is_numeric($row[$colMap["vit_c"]]) ? (float)$row[$colMap["vit_c"]] : 0,
                    "folate_ug" => isset($colMap["folate"]) && is_numeric($row[$colMap["folate"]]) ? (float)$row[$colMap["folate"]] : 0,
                    "category" => "Lunch/Dinner",
                    "diet_type" => $isNonVeg ? "Non-Vegetarian" : "Vegetarian",
                    "portion" => "100 g portion basis",
                    "icon" => $isNonVeg ? "🍗" : "🥗"
                ];
                break;
            }
            $currentId++;
        }
        fclose($handle);

        if ($matchedRow !== null) {
            respondWithFoodDetails($matchedRow, $foodId, $dishName);
        }
    }
}

// -------------------------------------------------------------
// 3. Query MySQL food_items table (database-seeded dishes)
// -------------------------------------------------------------
try {
    if ($foodId > 0 && $foodId < 1000) {
        $stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $foodId]);
        $food = $stmt->fetch();
    } elseif ($dishName !== "") {
        $stmt = $pdo->prepare("SELECT * FROM food_items WHERE name LIKE :name LIMIT 1");
        $stmt->execute([":name" => "%" . $dishName . "%"]);
        $food = $stmt->fetch();
    } elseif ($category !== "") {
        $stmt = $pdo->prepare("SELECT * FROM food_items WHERE category LIKE :cat LIMIT 1");
        $stmt->execute([":cat" => "%" . $category . "%"]);
        $food = $stmt->fetch();
    } else {
        $stmt = $pdo->query("SELECT * FROM food_items ORDER BY id ASC LIMIT 1");
        $food = $stmt->fetch();
    }

    if ($food) {
        $carbs = (float) $food["carbs_g"];
        $protein = (float) $food["protein_g"];
        $fat = (float) $food["fat_g"];
        $fiber = (float) $food["fiber_g"];
        $calories = (int) $food["calories"];
        $gi = $food["glycemic_index"] ?? "Low";

        if ($gi === "Low") {
            $glycemicColor = "#2E7D32";
            $glycemicBg = "#E8F5E9";
            $glycemicImpact = "Low Glycemic Index: Causes a slow and gradual rise in blood sugar.";
        } elseif ($gi === "Medium") {
            $glycemicColor = "#E65100";
            $glycemicBg = "#FFF3E0";
            $glycemicImpact = "Moderate Glycemic Index: Balanced portion recommended.";
        } else {
            $glycemicColor = "#C62828";
            $glycemicBg = "#FFEBEE";
            $glycemicImpact = "Higher Glycemic Load: Monitor post-prandial blood sugar 2 hours after intake.";
        }

        respond(true, "Food details retrieved successfully.", 200, [
            "id" => (int) $food["id"],
            "title" => $food["name"],
            "category" => $food["category"],
            "diet_type" => $food["diet_type"],
            "portion" => $food["portion"],
            "calories" => $calories,
            "calories_label" => "{$calories} kcal",
            "macros" => [
                "carbs_g" => $carbs,
                "protein_g" => $protein,
                "fat_g" => $fat,
                "fiber_g" => $fiber,
                "summary" => "Carbs {$carbs}g • Protein {$protein}g • Fat {$fat}g • Fiber {$fiber}g"
            ],
            "micronutrients" => [
                "free_sugar_g" => 0.0,
                "sodium_mg" => 150.0,
                "calcium_mg" => 0.0,
                "iron_mg" => 0.0,
                "vitamin_c_mg" => 0.0,
                "folate_ug" => 0.0
            ],
            "glycemic_index" => $gi,
            "glycemic_details" => [
                "badge" => "{$gi} Glycemic Impact",
                "color" => $glycemicColor,
                "bg_color" => $glycemicBg,
                "description" => $glycemicImpact
            ],
            "icon" => $food["icon"] ?? "🥣",
            "ingredients" => $food["ingredients"] ? array_map('trim', explode(',', $food["ingredients"])) : ["Ingredients list on packaging"],
            "health_benefits" => $food["health_benefits"] ?? "Rich in essential macronutrients tailored for diabetes management.",
            "diabetic_guidance" => "Maintain regular portion controls and stay hydrated."
        ]);
    }

} catch (PDOException $e) {
    error_log("Food details error: " . $e->getMessage());
}

// If truly not found in Python, CSV, or MySQL:
respond(false, "Food item not found for specified ID ({$foodId}) or name ('{$dishName}').", 404);
