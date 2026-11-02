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
    $diets = $parseList($hpRow["dietary_preferences"] ?? "");
    if (!empty($diets)) {
        $dietPref = implode(", ", $diets);
    } elseif (!empty($fpRow["preferred_diet"])) {
        $dietPref = $fpRow["preferred_diet"];
    } else {
        $dietPref = "Vegetarian";
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
                $updUser = $pdo->prepare("UPDATE users SET current_glucose_mg_dl = :g WHERE id = :id");
                $updUser->execute([":g" => $effectiveGlucose, ":id" => $userId]);

                $insG = $pdo->prepare(
                    "INSERT INTO glucose_readings (user_id, glucose_value, reading_type, reading_date, reading_time, notes) 
                     VALUES (:u, :v, 'Random', CURDATE(), CURTIME(), 'Updated via Diet Screen')"
                );
                $insG->execute([":u" => $userId, ":v" => $effectiveGlucose]);
            }

            if (!empty($dietPref)) {
                $cleanPref = "Vegetarian";
                if (stripos($dietPref, "non") !== false) {
                    $cleanPref = "Non-Vegetarian";
                } elseif (stripos($dietPref, "vegan") !== false) {
                    $cleanPref = "Vegan";
                } elseif (stripos($dietPref, "eggetarian") !== false) {
                    $cleanPref = "Eggetarian";
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
    // 6. Fast Microservice Connection Check
    // =========================================================================
    if (isMicroserviceAvailable($pythonServiceUrl, 0.2)) {
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

            // Enhanced keyword dictionaries
            $meatFishWords = ["chicken", "mutton", "fish", "prawn", "keema", "gosht", "meat", "crab", "pork", "beef", "shrimp", "duck", "pomfret", "salmon", "tuna", "seafood", "tikka (chicken)", "ham", "bacon", "lamb"];
            $eggWords = ["egg", "anda", "omlet", "omelette", "bhurji", "poached egg", "boiled egg", "fried egg"];
            $allNonVegWords = array_merge($meatFishWords, $eggWords);
            $dairyWords = ["paneer", "milk", "curd", "dahi", "cheese", "butter", "ghee", "malai", "lassi", "raita", "khoya", "cream", "kheer", "rabri"];
            $beverageWords = ["tea", "chai", "coffee", "cooler", "juice", "drink", "shake", "smoothie", "water", "punch", "lemonade", "cocoa", "sharbat", "squash", "soda", "lassi", "lem-o-gin", "cordial", "syrup"];
            $brothWords = ["brown stock", "vegetable stock", "mutton stock", "chicken stock", "beef stock", "meat stock"];
            $breakfastWords = ["oats", "idli", "dosa", "poha", "upma", "paratha", "parantha", "pancake", "toast", "cornflakes", "porridge", "thepla", "cheela", "chilla", "puri", "sandwich", "daliya", "omelet", "omlet", "bhurji", "dhokla", "egg sandwich"];
            $snackWords = ["soup", "chaat", "tikki", "pakoda", "pakora", "samosa", "cutlet", "biscuit", "cookie", "roll", "kebab", "vada", "bhel", "chivda", "salad", "raita", "dip", "chutney", "murukku", "snack"];

            $dietLower = strtolower($dietPref);
            $isVegan = (strpos($dietLower, "vegan") !== false);
            $isEggetarian = (strpos($dietLower, "eggetarian") !== false);
            $isVegUser = (strpos($dietLower, "veg") !== false && strpos($dietLower, "non") === false && !$isEggetarian);
            $isLowCarb = (strpos($dietLower, "low-carb") !== false || strpos($dietLower, "keto") !== false);
            $focusLower = strtolower($healthFocus);

            $gVal = $effectiveGlucose !== null ? $effectiveGlucose : 120.0;
            $isHypo = $gVal < 70.0;
            $isHigh = $gVal >= 180.0;
            $isElevated = ($gVal >= 140.0 && $gVal < 180.0);

            $csvDishes = [];
            $itemId = 1001;

            while (($row = fgetcsv($handle)) !== false) {
                $dishName = trim($row[$colMap["dish"] ?? 0] ?? "");
                if ($dishName === "") continue;

                $dishLower = strtolower($dishName);

                // 1. Precise Category Classification
                $dishCat = "Lunch/Dinner";
                foreach ($brothWords as $bw) {
                    if (stripos($dishLower, $bw) !== false) { $dishCat = "Broth"; break; }
                }
                if ($dishCat === "Lunch/Dinner") {
                    foreach ($beverageWords as $bw) {
                        if (stripos($dishLower, $bw) !== false) { $dishCat = "Beverage"; break; }
                    }
                }
                if ($dishCat === "Lunch/Dinner") {
                    foreach ($breakfastWords as $bw) {
                        if (stripos($dishLower, $bw) !== false) { $dishCat = "Breakfast"; break; }
                    }
                }
                if ($dishCat === "Lunch/Dinner") {
                    foreach ($snackWords as $sw) {
                        if (stripos($dishLower, $sw) !== false) { $dishCat = "Snack"; break; }
                    }
                }

                // 2. Classify Diet Type
                $isNonVeg = false;
                foreach ($allNonVegWords as $w) {
                    if (stripos($dishLower, $w) !== false) {
                        $isNonVeg = true;
                        break;
                    }
                }
                $dishDietType = $isNonVeg ? "Non-Vegetarian" : "Vegetarian";

                // 3. Dietary preference filtering
                if ($isVegan) {
                    if ($isNonVeg) { $itemId++; continue; }
                    $hasDairy = false;
                    foreach ($dairyWords as $dw) {
                        if (stripos($dishLower, $dw) !== false) { $hasDairy = true; break; }
                    }
                    if ($hasDairy) { $itemId++; continue; }
                } elseif ($isEggetarian) {
                    $hasMeat = false;
                    foreach ($meatFishWords as $mw) {
                        if (stripos($dishLower, $mw) !== false) { $hasMeat = true; break; }
                    }
                    if ($hasMeat) { $itemId++; continue; }
                } elseif ($isVegUser && $dishDietType === "Non-Vegetarian") {
                    $itemId++;
                    continue;
                }

                // 4. Allergen & foods to avoid exclusion
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

                // 5. Exclude Beverages & Broths from main food recommendations (unless specifically requested)
                $catLower = strtolower($category);
                if ($catLower === "beverage") {
                    if ($dishCat !== "Beverage") { $itemId++; continue; }
                } else {
                    if ($dishCat === "Beverage" || $dishCat === "Broth") { $itemId++; continue; }
                    if ($catLower !== "all" && !empty($catLower)) {
                        if (stripos($dishCat, $catLower) === false) { $itemId++; continue; }
                    }
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

                // 6. Clinical Glucose Scoring
                if ($isHypo) {
                    if ($carbs >= 12.0 && $carbs <= 28.0) {
                        $score += 25;
                        $highlights[] = "Hypo Rescue Carbs";
                    } elseif ($carbs < 5.0) {
                        $score -= 15;
                    }
                    if ($sugar >= 1.5) {
                        $score += 15;
                        $highlights[] = "Fast Glucose";
                    }
                } elseif ($isHigh) {
                    if ($carbs <= 8.0) {
                        $score += 25;
                        $highlights[] = "Ultra-Low Carb";
                    } elseif ($carbs <= 14.0) {
                        $score += 15;
                        $highlights[] = "Low Carbs";
                    } elseif ($carbs > 24.0) {
                        $score -= 30;
                    }
                    if ($sugar == 0.0) {
                        $score += 18;
                        $highlights[] = "0g Added Sugar";
                    } elseif ($sugar > 1.0) {
                        $score -= 25;
                    }
                    if ($fibre >= 3.0) {
                        $score += 16;
                        $highlights[] = "{$fibre}g Fibre";
                    }
                    if ($protein >= 8.0) {
                        $score += 12;
                        $highlights[] = "{$protein}g Protein";
                    }
                    if (strpos($dishLower, "aloo") !== false || strpos($dishLower, "potato") !== false) {
                        $score -= 35;
                    }
                    if (strpos($dishLower, "poori") !== false || strpos($dishLower, "puri") !== false || strpos($dishLower, "bhatura") !== false || strpos($dishLower, "kachori") !== false || strpos($dishLower, "pakoda") !== false) {
                        $score -= 20;
                    }
                } elseif ($isElevated) {
                    if ($carbs <= 15.0) {
                        $score += 16;
                        $highlights[] = "Gentle Carbs";
                    } elseif ($carbs > 30.0) {
                        $score -= 18;
                    }
                    if ($sugar == 0.0) {
                        $score += 12;
                        $highlights[] = "Zero Sugar";
                    }
                    if ($fibre >= 3.0) {
                        $score += 12;
                        $highlights[] = "{$fibre}g Fibre";
                    }
                    if ($protein >= 8.0) {
                        $score += 8;
                    }
                    if (strpos($dishLower, "aloo") !== false || strpos($dishLower, "potato") !== false) {
                        $score -= 20;
                    }
                } else {
                    if ($carbs <= 20.0) {
                        $score += 12;
                        $highlights[] = "{$carbs}g Carbs";
                    } elseif ($carbs > 40.0) {
                        $score -= 15;
                    }
                    if ($sugar == 0.0) {
                        $score += 12;
                        $highlights[] = "0g Sugar";
                    } elseif ($sugar > 3.0) {
                        $score -= 14;
                    }
                    if ($fibre >= 3.0) {
                        $score += 12;
                        $highlights[] = "{$fibre}g Fibre";
                    }
                    if ($protein >= 7.0) {
                        $score += 10;
                        $highlights[] = "{$protein}g Protein";
                    }
                }

                // Anti-Diabetic Ingredient Recognition
                if (strpos($dishLower, "karela") !== false || strpos($dishLower, "bitter gourd") !== false) {
                    $score += 18;
                    $highlights[] = "Karela Anti-Spike";
                } elseif (strpos($dishLower, "methi") !== false || strpos($dishLower, "fenugreek") !== false) {
                    $score += 14;
                    $highlights[] = "Methi Glucose Shield";
                } elseif (strpos($dishLower, "palak") !== false || strpos($dishLower, "spinach") !== false || strpos($dishLower, "saag") !== false) {
                    $score += 10;
                    $highlights[] = "Greens Fiber";
                } elseif (strpos($dishLower, "bhindi") !== false || strpos($dishLower, "okra") !== false) {
                    $score += 12;
                    $highlights[] = "Soluble Fiber";
                } elseif (strpos($dishLower, "moong") !== false || strpos($dishLower, "sprout") !== false || strpos($dishLower, "black bean") !== false) {
                    $score += 10;
                    $highlights[] = "Complex Legume";
                }

                // Low-Carb / Keto Bonus
                if ($isLowCarb) {
                    if ($carbs <= 10.0) {
                        $score += 18;
                        $highlights[] = "Keto Compliant";
                    } elseif ($carbs > 22.0) {
                        $score -= 22;
                    }
                }

                // Health Focus Tailoring
                if (strpos($focusLower, "weight") !== false) {
                    if ($cal <= 200) { $score += 12; $highlights[] = "Calorie-Light"; }
                    elseif ($cal > 340) $score -= 15;
                    if ($protein >= 10.0) { $score += 10; $highlights[] = "Satiating Protein"; }
                }
                if (strpos($focusLower, "heart") !== false || strpos($focusLower, "cardio") !== false) {
                    if ($sodium <= 140) { $score += 14; $highlights[] = "Heart-Friendly"; }
                    elseif ($sodium > 450) $score -= 18;
                }
                if (strpos($focusLower, "active") !== false || strpos($focusLower, "fitness") !== false || strpos($focusLower, "energy") !== false) {
                    if ($protein >= 12.0) { $score += 14; $highlights[] = "Muscle Recovery"; }
                }
                if (strpos($focusLower, "immunity") !== false || strpos($focusLower, "wellness") !== false) {
                    if ($vitC !== null && $vitC >= 5.0) { $score += 10; $highlights[] = "Vitamin C Rich"; }
                }

                $netCarbs = max(0.0, $carbs - $fibre);
                $giLevel = ($netCarbs <= 12.0 && $sugar <= 2.0) ? "Low" : (($netCarbs <= 28.0 && $sugar <= 5.0) ? "Moderate" : "High");
                $giColor = ($giLevel === "Low") ? "#2E7D32" : (($giLevel === "Moderate") ? "#E65100" : "#C62828");
                $giBg = ($giLevel === "Low") ? "#E8F5E9" : (($giLevel === "Moderate") ? "#FFF3E0" : "#FFEBEE");

                // Dynamic clinical explanation
                if ($isHypo) {
                    $reason = "Recommended for low blood glucose (" . round($gVal) . " mg/dL): provides gentle fast carbohydrates ({$carbs}g) to safely assist recovery.";
                } elseif ($isHigh) {
                    $reason = "Recommended for high glucose (" . round($gVal) . " mg/dL): provides ultra-low carbohydrates ({$carbs}g), {$sugar}g free sugar, and {$fibre}g fibre to stabilize blood sugar.";
                } elseif ($isElevated) {
                    $reason = "Recommended for mildly elevated glucose (" . round($gVal) . " mg/dL): balanced low-glycemic meal with {$carbs}g carbs and {$fibre}g fibre.";
                } else {
                    $reason = "Recommended for your diabetes targets: provides {$carbs}g carbs, {$sugar}g sugar, and {$fibre}g fibre to maintain steady energy.";
                }

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
                    "recommendation_score" => (int) min(99, max(20, round($score))),
                    "recommendation_reason" => $reason,
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

            // Fallback fill for any underfilled slot
            foreach ($csvDishes as $d) {
                if (count($dailyPlan["breakfast"]) < 2 && !in_array($d, $dailyPlan["breakfast"]) && !in_array($d, $dailyPlan["lunch"])) {
                    $dailyPlan["breakfast"][] = $d;
                }
                if (count($dailyPlan["dinner"]) < 2 && !in_array($d, $dailyPlan["lunch"]) && !in_array($d, $dailyPlan["dinner"])) {
                    $dailyPlan["dinner"][] = $d;
                }
                if (count($dailyPlan["snack"]) < 1 && !in_array($d, $dailyPlan["lunch"]) && !in_array($d, $dailyPlan["snack"])) {
                    $dailyPlan["snack"][] = $d;
                }
            }

            $topDishes = array_slice($csvDishes, 0, $limit);

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

    respond(false, "Food nutrition dataset file could not be loaded.", 500);

} catch (PDOException $e) {
    error_log("Food recommendations error: " . $e->getMessage());
    respond(false, "Database error retrieving recommendations: " . $e->getMessage(), 500);
}
