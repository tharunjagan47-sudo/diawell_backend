<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Allow GET and POST for fetching and adjusting inputs
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
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
        $dietPref = !empty($diets) ? implode(", ", $diets) : "Vegetarian";
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

    // =========================================================================
    // 5. If POST requested to update user profile preferences, persist to MySQL safely
    // =========================================================================
    if ($method === "POST" && (!empty($data["update_inputs"]) || !empty($data["save_preferences"]))) {
        try {
            if ($effectiveGlucose !== null) {
                // Update users table
                $updUser = $pdo->prepare("UPDATE users SET current_glucose_mg_dl = :g WHERE id = :id");
                $updUser->execute([":g" => $effectiveGlucose, ":id" => $userId]);

                // Insert into glucose_readings using VALID ENUM 'Random'
                $insG = $pdo->prepare(
                    "INSERT INTO glucose_readings (user_id, glucose_value, reading_type, reading_date, reading_time, notes) 
                     VALUES (:u, :v, 'Random', CURDATE(), CURTIME(), 'Updated via Diet Screen')"
                );
                $insG->execute([":u" => $userId, ":v" => $effectiveGlucose]);
            }

            if (!empty($dietPref)) {
                // Sanitize preferred_diet for MySQL ENUM ('Vegetarian', 'Non-Vegetarian', 'Vegan')
                $cleanPref = "Vegetarian";
                if (stripos($dietPref, "non") !== false) {
                    $cleanPref = "Non-Vegetarian";
                } elseif (stripos($dietPref, "vegan") !== false) {
                    $cleanPref = "Vegan";
                }

                $chkFp = $pdo->prepare("SELECT id FROM food_preferences WHERE user_id = :id LIMIT 1");
                $chkFp->execute([":id" => $userId]);
                if ($chkFp->fetch()) {
                    $updFp = $pdo->prepare("UPDATE food_preferences SET preferred_diet = :diet, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id");
                    $updFp->execute([":diet" => $cleanPref, ":id" => $userId]);
                } else {
                    $insFp = $pdo->prepare(
                        "INSERT INTO food_preferences (user_id, preferred_diet, allergies, preferred_styles, foods_to_avoid) 
                         VALUES (:id, :diet, '[]', '[]', '')"
                    );
                    $insFp->execute([":id" => $userId, ":diet" => $cleanPref]);
                }
            }

            // Save health_preferences
            $dietArr = array_values(array_filter(array_map('trim', explode(',', $dietPref))));
            $chkHp = $pdo->prepare("SELECT id FROM health_preferences WHERE user_id = :id LIMIT 1");
            $chkHp->execute([":id" => $userId]);
            if ($chkHp->fetch()) {
                $sqlHp = "UPDATE health_preferences SET updated_at = CURRENT_TIMESTAMP";
                $hpParams = [":id" => $userId];
                if (!empty($dietArr)) {
                    $sqlHp .= ", dietary_preferences = :diets";
                    $hpParams[":diets"] = json_encode($dietArr);
                }
                if (!empty($healthFocus)) {
                    $sqlHp .= ", health_focus = :hf";
                    $hpParams[":hf"] = $healthFocus;
                }
                $sqlHp .= " WHERE user_id = :id";
                $updHp = $pdo->prepare($sqlHp);
                $updHp->execute($hpParams);
            } else {
                $insHp = $pdo->prepare(
                    "INSERT INTO health_preferences (user_id, health_focus, dietary_preferences, allergies, activity_level) 
                     VALUES (:id, :hf, :diets, '[]', 'Moderate')"
                );
                $insHp->execute([
                    ":id" => $userId,
                    ":hf" => $healthFocus ?: "Glucose Control",
                    ":diets" => json_encode($dietArr)
                ]);
            }
        } catch (Exception $dbEx) {
            error_log("Warning updating user preferences from diet screen: " . $dbEx->getMessage());
        }
    }

    // Determine glucose status for clinical banner & scoring
    $glucoseStatus = "normal";
    $hypoAlert = null;
    if ($effectiveGlucose !== null) {
        if ($effectiveGlucose < 70.0) {
            $glucoseStatus = "hypoglycemia";
            $hypoAlert = "⚠️ Blood glucose below 70 mg/dL: Routine meal recommendations do not apply. Please consume 15g of fast-acting carbohydrates (e.g., 1/2 cup fruit juice or 3-4 glucose tablets) immediately and re-test in 15 minutes.";
        } elseif ($effectiveGlucose >= 180.0) {
            $glucoseStatus = "high";
        } elseif ($effectiveGlucose >= 140.0) {
            $glucoseStatus = "elevated";
        } else {
            $glucoseStatus = "normal";
        }
    }

    // Build payload in case microservice is active
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

    // =========================================================================
    // 6. Fast Microservice Connection Check (prevents 3-6s hangs on Windows)
    // =========================================================================
    if (isMicroserviceAvailable($pythonServiceUrl, 0.05)) {
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
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 1
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
            $parsed = json_decode($responseBody, true);
            if ($parsed && isset($parsed["success"]) && $parsed["success"]) {
                respond(true, $parsed["message"], 200, $parsed["data"]);
            }
        }
    }

    // =========================================================================
    // 7. HIGH-PERFORMANCE NATIVE DATASET EVALUATION ENGINE (<20ms)
    // Seamlessly evaluates 1000+ dishes using clinical diabetic guidelines
    // =========================================================================
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

            // Keyword definitions matching clinical recommender engine
            $nonVegWords = ["chicken", "mutton", "fish", "egg", "prawn", "keema", "meat", "pork", "beef", "shrimp", "seafood", "duck", "crab", "omlet", "omelette", "bhurji"];
            $eggWords = ["egg", "omlet", "omelette", "bhurji"];
            $dairyWords = ["paneer", "milk", "curd", "dahi", "cheese", "butter", "ghee", "malai", "lassi", "raita", "khoya", "cream", "kheer", "rabri"];
            $breakfastWords = ["oats", "idli", "dosa", "poha", "upma", "paratha", "pancake", "toast", "cornflakes", "porridge", "thepla", "cheela", "chilla", "puri", "upama"];
            $snackWords = ["snack", "chaat", "tikki", "pakoda", "pakora", "samosa", "cutlet", "biscuit", "cookie", "roll", "kebab", "vada", "bhel", "chivda", "sprouts", "salad", "soup"];
            $beverageWords = ["tea", "chai", "coffee", "cooler", "juice", "drink", "shake", "smoothie", "water", "punch", "lemonade", "cocoa"];

            $dietLower = strtolower($dietPref);
            $isVegUser = (strpos($dietLower, "non") === false && strpos($dietLower, "veg") !== false);
            $isVegan = (strpos($dietLower, "vegan") !== false);
            $isEggetarian = (strpos($dietLower, "eggetarian") !== false);
            $isLowCarb = (strpos($dietLower, "low-carb") !== false || strpos($dietLower, "keto") !== false);
            $focusLower = strtolower($healthFocus);

            $csvDishes = [];
            $itemId = 1001;

            while (($row = fgetcsv($handle)) !== false) {
                $dishName = trim($row[$colMap["dish"] ?? 0] ?? "");
                if ($dishName === "") continue;

                $dishLower = strtolower($dishName);

                // Classify Category
                $dishCat = "Lunch/Dinner";
                foreach ($beverageWords as $bw) {
                    if (strpos($dishLower, $bw) !== false) { $dishCat = "Beverage"; break; }
                }
                if ($dishCat === "Lunch/Dinner") {
                    foreach ($breakfastWords as $bw) {
                        if (strpos($dishLower, $bw) !== false) { $dishCat = "Breakfast"; break; }
                    }
                }
                if ($dishCat === "Lunch/Dinner") {
                    foreach ($snackWords as $sw) {
                        if (strpos($dishLower, $sw) !== false) { $dishCat = "Snack"; break; }
                    }
                }

                // Classify Diet Type
                $hasEgg = false;
                foreach ($eggWords as $ew) {
                    if (strpos($dishLower, $ew) !== false) { $hasEgg = true; break; }
                }
                $isNonVeg = false;
                foreach ($nonVegWords as $w) {
                    if (strpos($dishLower, $w) !== false) {
                        $isNonVeg = true;
                        break;
                    }
                }
                $dishDietType = $isNonVeg ? "Non-Vegetarian" : "Vegetarian";

                // Dietary preference filtering
                if ($isVegan) {
                    if ($isNonVeg) { $itemId++; continue; }
                    $hasDairy = false;
                    foreach ($dairyWords as $dw) {
                        if (strpos($dishLower, $dw) !== false) { $hasDairy = true; break; }
                    }
                    if ($hasDairy) { $itemId++; continue; }
                } elseif ($isEggetarian) {
                    if ($isNonVeg && !$hasEgg) { $itemId++; continue; }
                } elseif ($isVegUser && $dishDietType === "Non-Vegetarian") {
                    $itemId++;
                    continue;
                }

                // Allergen & foods to avoid exclusion
                $excluded = false;
                foreach (array_merge($allAllergies, $avoidList) as $kw) {
                    $kwClean = trim(preg_replace('/[^\w\s]/', '', strtolower($kw)));
                    if (strlen($kwClean) >= 3 && strpos($dishLower, $kwClean) !== false) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
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
                $vitC = isset($colMap["vit_c"]) && is_numeric($row[$colMap["vit_c"]]) ? (float)$row[$colMap["vit_c"]] : null;

                $highlights = [];
                $score = 70.0;

                // Rule 1: Free Sugar (Highest glycemic surge factor)
                if ($sugar == 0.0) {
                    $score += 14;
                    $highlights[] = "0g Free Sugar";
                } elseif ($sugar <= 2.0) {
                    $score += 8;
                    $highlights[] = "{$sugar}g Sugar";
                } elseif ($sugar <= 5.0) {
                    $score -= 4;
                } else {
                    $score -= min(25, 10 + intval($sugar * 2));
                }

                // Rule 2: Dietary Fibre (Slows absorption, flattens curve)
                if ($fibre >= 5.0) {
                    $score += 15;
                    $highlights[] = "{$fibre}g Fibre";
                } elseif ($fibre >= 2.5) {
                    $score += 8;
                    $highlights[] = "{$fibre}g Fibre";
                } elseif ($fibre < 1.0) {
                    $score -= 6;
                }

                // Rule 3: Carbohydrate Load
                if ($carbs <= 12.0) {
                    $score += 12;
                    $highlights[] = "{$carbs}g Carbs";
                } elseif ($carbs <= 25.0) {
                    $score += 6;
                    $highlights[] = "{$carbs}g Carbs";
                } elseif ($carbs <= 45.0) {
                    $score -= 4;
                } else {
                    $score -= min(22, 8 + intval(($carbs - 45) * 0.4));
                }

                // Rule 4: Protein Support
                if ($protein >= 15.0) {
                    $score += 12;
                    $highlights[] = "{$protein}g Protein";
                } elseif ($protein >= 7.0) {
                    $score += 6;
                    $highlights[] = "{$protein}g Protein";
                }

                // Rule 5: Fats & Calorie Density
                if ($fat > 22.0) $score -= 8;
                elseif ($fat >= 3.0 && $fat <= 12.0) $score += 4;
                if ($cal > 450) $score -= 8;
                elseif ($cal <= 250) $score += 4;

                // Rule 6: Sodium Moderation
                if ($sodium <= 140) {
                    $score += 5;
                    $highlights[] = "Low Sodium";
                } elseif ($sodium > 600) {
                    $score -= 8;
                }

                // Rule 7: DYNAMIC GLUCOSE-AWARE SCORING
                if ($effectiveGlucose !== null) {
                    if ($effectiveGlucose >= 180.0) {
                        // Hyperglycemia: Reward strict low-carb & low sugar
                        if ($carbs <= 10.0) {
                            $score += 18;
                            $highlights[] = "Glycemic Stabilizer";
                        } elseif ($carbs > 20.0) {
                            $score -= 20;
                        }
                        if ($sugar > 1.5) $score -= 22;
                        if ($fibre >= 3.0) $score += 12;
                    } elseif ($effectiveGlucose >= 140.0) {
                        // Mildly Elevated: Reward moderate-low carbs
                        if ($carbs <= 15.0) {
                            $score += 10;
                            $highlights[] = "Gentle Carbs";
                        }
                        if ($sugar > 3.0) $score -= 12;
                        if ($fibre >= 3.0) $score += 6;
                    } elseif ($effectiveGlucose < 70.0) {
                        // Hypoglycemia: Prioritize fast-acting carbohydrates
                        if ($carbs >= 15.0) $score += 15;
                        if ($sugar >= 2.0) $score += 10;
                    }
                }

                // Rule 8: Multi-Select Low-Carb / Keto Tailoring
                if ($isLowCarb) {
                    if ($carbs <= 12.0) {
                        $score += 14;
                        $highlights[] = "Low-Carb Match";
                    } elseif ($carbs > 30.0) {
                        $score -= 14;
                    }
                }

                // Rule 9: Multi-Select Health Focus Tailoring
                if (strpos($focusLower, "glucose") !== false || strpos($focusLower, "control") !== false) {
                    if ($sugar == 0.0) { $score += 10; $highlights[] = "Spike Protector"; }
                    if ($fibre >= 4.0) $score += 8;
                }
                if (strpos($focusLower, "weight") !== false) {
                    if ($cal <= 220) { $score += 8; $highlights[] = "Calorie-Light"; }
                    elseif ($cal > 360) $score -= 8;
                }
                if (strpos($focusLower, "heart") !== false || strpos($focusLower, "cardio") !== false) {
                    if ($sodium <= 140) { $score += 8; $highlights[] = "Heart-Friendly"; }
                    elseif ($sodium > 500) $score -= 8;
                }
                if (strpos($focusLower, "active") !== false || strpos($focusLower, "energy") !== false || strpos($focusLower, "fitness") !== false) {
                    if ($protein >= 12.0) { $score += 10; $highlights[] = "Muscle Recovery"; }
                }
                if (strpos($focusLower, "immunity") !== false || strpos($focusLower, "wellness") !== false) {
                    if ($vitC !== null && $vitC >= 5.0) { $score += 8; $highlights[] = "Immune Boost"; }
                }

                $sugarDesc = $sugar <= 1.0 ? "low free sugar ({$sugar}g)" : "moderate free sugar ({$sugar}g)";
                $carbDesc = $carbs <= 15.0 ? "low carbohydrate load ({$carbs}g)" : "manageable carbohydrates ({$carbs}g)";
                $fibreDesc = $fibre >= 3.0 ? "high dietary fibre ({$fibre}g)" : "fibre ({$fibre}g)";

                $netCarbs = max(0.0, $carbs - $fibre);
                $giLevel = ($netCarbs <= 15.0) ? "Low" : (($netCarbs <= 30.0) ? "Moderate" : "High");
                $giColor = ($giLevel === "Low") ? "#2E7D32" : (($giLevel === "Moderate") ? "#E65100" : "#C62828");
                $giBg = ($giLevel === "Low") ? "#E8F5E9" : (($giLevel === "Moderate") ? "#FFF3E0" : "#FFEBEE");

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
                    "vitamin_c_mg" => $vitC,
                    "folate_ug" => isset($colMap["folate"]) && is_numeric($row[$colMap["folate"]]) ? (float)$row[$colMap["folate"]] : null,
                    "category" => $dishCat,
                    "diet_type" => $dishDietType,
                    "portion" => "100 g portion basis",
                    "icon" => $isNonVeg ? "🍗" : "🥗",
                    "recommendation_score" => (int) min(98, max(20, round($score))),
                    "recommendation_reason" => "Recommended because this food provides {$sugarDesc}, {$carbDesc}, and {$fibreDesc} suitable for your diabetic targets.",
                    "highlights" => array_values(array_unique(array_slice($highlights, 0, 3))),
                    "limitations" => ["Formulation varies by preparation; verify full ingredients with recipe creator or clinician."],
                    "glycemic_impact" => [
                        "level" => $giLevel,
                        "badge" => "{$giLevel} Glycemic Impact",
                        "color" => $giColor,
                        "bg_color" => $giBg,
                        "description" => "Evaluated from CSV dataset macronutrients."
                    ]
                ];
                $itemId++;
            }
            fclose($handle);

            // Sort descending by recommendation score
            usort($csvDishes, function($a, $b) {
                return $b["recommendation_score"] <=> $a["recommendation_score"];
            });

            // Build Daily Plan (Breakfast, Lunch, Snack, Dinner)
            $dailyPlan = [
                "breakfast" => [],
                "lunch" => [],
                "snack" => [],
                "dinner" => []
            ];

            foreach ($csvDishes as $d) {
                if ($d["category"] === "Breakfast" && count($dailyPlan["breakfast"]) < 2) {
                    $dailyPlan["breakfast"][] = $d;
                } elseif ($d["category"] === "Snack" && count($dailyPlan["snack"]) < 1) {
                    $dailyPlan["snack"][] = $d;
                } elseif ($d["category"] === "Lunch/Dinner") {
                    if (count($dailyPlan["lunch"]) < 2) {
                        $dailyPlan["lunch"][] = $d;
                    } elseif (count($dailyPlan["dinner"]) < 2) {
                        $dailyPlan["dinner"][] = $d;
                    }
                }
            }

            // Category filter for list view if specified
            $filteredDishes = $csvDishes;
            if ($category !== "All" && !empty($category)) {
                $filteredDishes = array_values(array_filter($csvDishes, function($d) use ($category) {
                    return strcasecmp($d["category"], $category) === 0;
                }));
            }

            $topDishes = array_slice($filteredDishes, 0, $limit);

            respond(true, "Personalized food recommendations (CSV dataset direct)", 200, [
                "patient_context" => [
                    "diabetes_type" => $userRow["diabetes_type"] ?? "Type 2",
                    "current_glucose_mg_dl" => $effectiveGlucose,
                    "target_glucose_mg_dl" => (float) ($userRow["target_glucose_mg_dl"] ?? 120.0),
                    "reading_type" => $readingType,
                    "reading_date" => $readingDate,
                    "glucose_status" => $glucoseStatus,
                    "height_cm" => isset($userRow["height_cm"]) ? (float)$userRow["height_cm"] : null,
                    "weight_kg" => isset($userRow["weight_kg"]) ? (float)$userRow["weight_kg"] : null,
                    "age" => isset($userRow["age"]) ? (int)$userRow["age"] : null,
                    "dietary_preference" => $dietPref,
                    "allergies" => $allAllergies,
                    "health_focus" => $healthFocus,
                    "activity_level" => $hpRow["activity_level"] ?? "Moderate",
                    "category_filter" => $category
                ],
                "hypoglycemia_alert" => $hypoAlert,
                "recommendations_count" => count($topDishes),
                "recommendations" => $topDishes,
                "daily_plan" => $dailyPlan,
                "disclaimer" => "Medical Disclaimer: Suggested foods are calculated from your food nutrition dataset based on diabetic health guidelines."
            ]);
        }
    }

    // 8. Fallback to MySQL food_items table if CSV file was not readable
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
            "glucose_status" => $glucoseStatus,
            "dietary_preference" => $dietPref,
            "allergies" => $allAllergies,
            "health_focus" => $healthFocus,
            "activity_level" => $hpRow["activity_level"] ?? "Moderate",
            "category_filter" => $category
        ],
        "hypoglycemia_alert" => $hypoAlert,
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
    respond(false, "Database error retrieving recommendations: " . $e->getMessage(), 500);
}
