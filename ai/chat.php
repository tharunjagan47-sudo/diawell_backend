<?php
@ini_set("display_errors", "0");
@ini_set("html_errors", "0");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
if (empty($rawInput)) {
    $rawInput = @file_get_contents("php://stdin");
}
$data = json_decode($rawInput, true) ?: [];

// Extract user message across all common parameter names
$userMessage = trim($data["message"] ?? $data["prompt"] ?? $data["query"] ?? $_POST["message"] ?? $_POST["prompt"] ?? $_GET["message"] ?? "");
$userId = intval($data["user_id"] ?? $_POST["user_id"] ?? $_GET["user_id"] ?? $_SERVER["HTTP_X_USER_ID"] ?? 0);
$clientContext = is_array($data["patient_context"] ?? null) ? $data["patient_context"] : [];
$history = is_array($data["history"] ?? null) ? $data["history"] : [];

if (empty($userMessage)) {
    http_response_code(200);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "response" => "Message cannot be empty.",
        "reply" => "Message cannot be empty.",
        "message" => "Message cannot be empty.",
        "answer" => "Message cannot be empty.",
        "model" => "qwen2.5:0.5b"
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function outputResponse(string $reply): void {
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "status" => "success",
        "model" => "qwen2.5:0.5b",
        "response" => $reply,
        "reply" => $reply,
        "message" => $reply,
        "answer" => $reply,
        "created_at" => date('Y-m-d H:i:s'),
        "data" => [
            "response" => $reply,
            "reply" => $reply,
            "message" => $reply
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// 1. Natural handling for Greetings, Thankings, and Goodbyes
$lowerMsg = strtolower($userMessage);
$cleanAlpha = preg_replace('/[^a-z\s]/', '', $lowerMsg);
$cleanAlpha = trim(preg_replace('/\s+/', ' ', $cleanAlpha));

if (preg_match('/^(hi|hello|hey|hai|vanakkam|good\s*(morning|afternoon|evening)|halo|diva|hey\s*diva)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 25) {
    $patientGreetingName = !empty($clientContext["patient_name"]) ? $clientContext["patient_name"] : "";
    $namePart = $patientGreetingName ? " {$patientGreetingName}" : "";
    outputResponse("Hello{$namePart}! I am DIVA (DiaWell Intelligent Virtual Assistant), your DiaWell diabetes wellness companion. How can I assist you with your blood sugar, medications, diet, or hospital guidance today?");
    exit;
}

if (preg_match('/\b(who are you|what is your name|whats your name|your name|introduce yourself)\b/i', $cleanAlpha)) {
    $patientGreetingName = !empty($clientContext["patient_name"]) ? $clientContext["patient_name"] : "";
    $namePart = $patientGreetingName ? " {$patientGreetingName}" : "";
    outputResponse("Hello{$namePart}! I am DIVA (DiaWell Intelligent Virtual Assistant), your personal AI diabetes care companion in DiaWell. I can help you track glucose, manage medications, plan meals, and answer diabetes questions.");
    exit;
}

if (preg_match('/^(thank\s*you|thanks|thanku|thx|nandri|romba\s*nandri|thank\s*you\s*so\s*much|thanks\s*a\s*lot)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 30) {
    outputResponse("You're very welcome! Stay healthy, monitor your glucose levels regularly, and feel free to ask whenever you need lifestyle or diet tips. Take care!");
    exit;
}

if (preg_match('/^(bye|goodbye|see\s*you|tata|good\s*night)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 20) {
    outputResponse("Goodbye! Take great care of your health and reach out anytime you need support.");
    exit;
}

// 2. Fetch Live MySQL Records & Merge with client patient_context
$pdo = null;
try {
    require_once __DIR__ . '/../config/db_config.php';
    $pdo = new PDO(
        "mysql:host={$diawellDbConfig['host']};port={$diawellDbConfig['port']};dbname={$diawellDbConfig['db_name']};charset={$diawellDbConfig['charset']}",
        $diawellDbConfig["username"],
        $diawellDbConfig["password"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 2
        ]
    );
} catch (\Throwable $t) {
    $pdo = null;
}

$userRow = null;
$latestGlucoseRow = null;
$allGlucoseRows = [];
$medRows = [];
$foodRow = null;
$actRow = null;
$prefRow = null;
$today = date("Y-m-d");

if ($pdo && $userId > 0) {
    try {
        // User profile
        $uStmt = $pdo->prepare("SELECT id, full_name, email, diabetes_type, current_glucose_mg_dl, target_glucose_mg_dl FROM users WHERE id = :id LIMIT 1");
        $uStmt->execute([":id" => $userId]);
        $userRow = $uStmt->fetch();

        // Latest Glucose
        $gStmt = $pdo->prepare("SELECT glucose_value, reading_type, reading_date, reading_time FROM glucose_readings WHERE user_id = :uid ORDER BY reading_date DESC, reading_time DESC LIMIT 1");
        $gStmt->execute([":uid" => $userId]);
        $latestGlucoseRow = $gStmt->fetch();

        // Recent Glucose readings (for average calculation)
        $gAllStmt = $pdo->prepare("SELECT glucose_value FROM glucose_readings WHERE user_id = :uid ORDER BY reading_date DESC, reading_time DESC LIMIT 30");
        $gAllStmt->execute([":uid" => $userId]);
        $allGlucoseRows = $gAllStmt->fetchAll() ?: [];

        // Active Medications
        $mStmt = $pdo->prepare("SELECT name, dose, frequency, instructions, reminder_time, status FROM medications WHERE user_id = :uid AND (reminder_date = :today OR frequency = 'Daily' OR reminder_date IS NULL) ORDER BY reminder_time ASC");
        $mStmt->execute([":uid" => $userId, ":today" => $today]);
        $medRows = $mStmt->fetchAll() ?: [];

        // Food logs today
        $fStmt = $pdo->prepare("SELECT SUM(calories) as total_cal, GROUP_CONCAT(DISTINCT dish_name SEPARATOR ', ') as dishes FROM food_logs WHERE user_id = :uid AND log_date = :today");
        $fStmt->execute([":uid" => $userId, ":today" => $today]);
        $foodRow = $fStmt->fetch();

        // Activity logs today
        $aStmt = $pdo->prepare("SELECT SUM(calories_kcal) as total_cal, SUM(duration_mins) as total_mins, GROUP_CONCAT(DISTINCT activity_type SEPARATOR ', ') as activities FROM activity_logs WHERE user_id = :uid AND activity_date = :today");
        $aStmt->execute([":uid" => $userId, ":today" => $today]);
        $actRow = $aStmt->fetch();

        // Food Preferences
        $pStmt = $pdo->prepare("SELECT preferred_diet, allergies, foods_to_avoid FROM food_preferences WHERE user_id = :uid LIMIT 1");
        $pStmt->execute([":uid" => $userId]);
        $prefRow = $pStmt->fetch();
    } catch (\Throwable $t) {
        // Silently tolerate query failures and fallback smoothly to client context
    }
}

// Consolidated Patient Context
$patientName = !empty($clientContext["patient_name"]) 
    ? $clientContext["patient_name"] 
    : (!empty($userRow["full_name"]) ? $userRow["full_name"] : "User");

// Latest Glucose
if (!empty($clientContext["latest_glucose"]) && $clientContext["latest_glucose"] !== "No readings logged yet") {
    $latestGlucoseStr = $clientContext["latest_glucose"];
    preg_match('/(\d+(?:\.\d+)?)/', $latestGlucoseStr, $gMatches);
    $latestGlucoseVal = !empty($gMatches[1]) ? floatval($gMatches[1]) : null;
} elseif (!empty($latestGlucoseRow)) {
    $latestGlucoseVal = floatval($latestGlucoseRow["glucose_value"]);
    $gType = !empty($latestGlucoseRow["reading_type"]) ? $latestGlucoseRow["reading_type"] : "Reading";
    $gTime = !empty($latestGlucoseRow["reading_time"]) ? date("g:i A", strtotime($latestGlucoseRow["reading_time"])) : "";
    $timePart = $gTime ? " • {$gTime}" : "";
    $latestGlucoseStr = round($latestGlucoseVal) . " mg/dL ({$gType}{$timePart})";
} else {
    $latestGlucoseStr = "No readings logged yet";
    $latestGlucoseVal = null;
}

// Average Glucose & Estimated HbA1c
if (!empty($clientContext["avg_glucose"]) && floatval($clientContext["avg_glucose"]) > 0) {
    $avgGlucose = floatval($clientContext["avg_glucose"]);
    $eA1c = !empty($clientContext["estimated_hba1c"]) 
        ? floatval($clientContext["estimated_hba1c"]) 
        : round(($avgGlucose + 46.7) / 28.7, 1);
} elseif (!empty($allGlucoseRows)) {
    $vals = array_map(function($r) { return floatval($r["glucose_value"]); }, $allGlucoseRows);
    $avgGlucose = round(array_sum($vals) / count($vals), 1);
    $eA1c = round(($avgGlucose + 46.7) / 28.7, 1);
} else {
    $avgGlucose = $latestGlucoseVal ? round($latestGlucoseVal, 1) : null;
    $eA1c = $latestGlucoseVal ? round(($latestGlucoseVal + 46.7) / 28.7, 1) : null;
}

// Active Medications
if (!empty($clientContext["active_medications"]) && $clientContext["active_medications"] !== "No active medications scheduled") {
    $activeMedsStr = $clientContext["active_medications"];
} elseif (!empty($medRows)) {
    $medParts = [];
    foreach ($medRows as $m) {
        $mTime = !empty($m["reminder_time"]) ? date("g:i A", strtotime($m["reminder_time"])) : "Daily";
        $mInst = !empty($m["instructions"]) ? " • " . $m["instructions"] : "";
        $medParts[] = "{$m["name"]} {$m["dose"]} ({$mTime}{$mInst})";
    }
    $activeMedsStr = implode("; ", $medParts);
} else {
    $activeMedsStr = "No active medications scheduled";
}

// Water Intake
$waterStr = !empty($clientContext["today_water_glasses"]) 
    ? $clientContext["today_water_glasses"] 
    : "0 glasses (0 ml)";

// Today's Calories Consumed
if (!empty($clientContext["today_calories_in"]) && $clientContext["today_calories_in"] !== "0 kcal") {
    $calInStr = $clientContext["today_calories_in"];
} elseif (!empty($foodRow["total_cal"]) && intval($foodRow["total_cal"]) > 0) {
    $dishes = !empty($foodRow["dishes"]) ? " (Meals: {$foodRow["dishes"]})" : "";
    $calInStr = intval($foodRow["total_cal"]) . " kcal{$dishes}";
} else {
    $calInStr = "0 kcal";
}

// Today's Calories Burned
if (!empty($clientContext["today_calories_burned"]) && $clientContext["today_calories_burned"] !== "0 kcal (0 mins active)") {
    $calBurnedStr = $clientContext["today_calories_burned"];
} elseif (!empty($actRow["total_cal"]) && intval($actRow["total_cal"]) > 0) {
    $calBurnedStr = intval($actRow["total_cal"]) . " kcal (" . intval($actRow["total_mins"]) . " mins active)";
} else {
    $calBurnedStr = "0 kcal (0 mins active)";
}

// Food Preferences
if (!empty($clientContext["food_preferences"])) {
    $foodPrefStr = $clientContext["food_preferences"];
} elseif (!empty($prefRow)) {
    $diet = $prefRow["preferred_diet"] ?: "Balanced";
    $allergies = $prefRow["allergies"] ?: "None";
    $avoid = $prefRow["foods_to_avoid"] ?: "None";
    $foodPrefStr = "Diet: {$diet} | Allergies: {$allergies} | Avoid: {$avoid}";
} else {
    $foodPrefStr = "Diet: Balanced | Allergies: None | Avoid: None";
}

// Conversation History Context extraction
$lastAssistantTurn = "";
$lastUserTurn = "";
if (!empty($history) && is_array($history)) {
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $turn = $history[$i];
        $role = strtolower($turn["role"] ?? "");
        $content = strtolower($turn["content"] ?? "");
        if (empty($lastAssistantTurn) && ($role === "assistant" || $role === "ai")) {
            $lastAssistantTurn = $content;
        }
        if (empty($lastUserTurn) && $role === "user") {
            $lastUserTurn = $content;
        }
    }
}

// =========================================================================
// 3. VERIFIED CHENNAI HOSPITAL & SPECIALIST ROUTING (Zero Hallucinations)
// =========================================================================
$isHospitalQuery = preg_match('/\b(hospital|hospitals|clinic|clinics|doctor|doctors|specialist|specialists|diabetologist|diabetologists|chennai|saveetha|simats|mohan|apollo|mv\s*hospital|maruthuvamanai)\b/i', $userMessage);

if ($isHospitalQuery) {
    if (strpos($lowerMsg, "anna nagar") !== false) {
        outputResponse("In Anna Nagar, **Dr. Mohan's Diabetes Specialities Centre** (Plot No. 1221, 15th Main Rd, Anna Nagar West) and **Apollo Sugar Clinic** are the top verified choices for diabetes care.");
        exit;
    }
    if (strpos($lowerMsg, "royapuram") !== false || strpos($lowerMsg, "foot") !== false || strpos($lowerMsg, "wound") !== false || strpos($lowerMsg, "ulcer") !== false) {
        outputResponse("**M.V. Hospital for Diabetes** in Royapuram is internationally renowned for diabetic foot care, wound management, and limb salvage. Contact: 044-25954913.");
        exit;
    }
    if (strpos($lowerMsg, "saveetha") !== false || strpos($lowerMsg, "simats") !== false || strpos($lowerMsg, "thandalam") !== false || strpos($lowerMsg, "poonamallee") !== false) {
        outputResponse("**Saveetha Medical College Hospital (SIMATS)** in Thandalam offers comprehensive 24/7 diabetes super-speciality care, ICU, and affordable clinical consultations. Contact: 044-66726620.");
        exit;
    }
    if (strpos($lowerMsg, "contact") !== false || strpos($lowerMsg, "number") !== false || strpos($lowerMsg, "phone") !== false || strpos($lowerMsg, "call") !== false || strpos($lowerMsg, "emergency") !== false) {
        outputResponse("Verified Chennai Medical Contacts:\n• Emergency: **108**\n• Dr. Mohan's Diabetes: **044-43968888**\n• Saveetha Hospital (SIMATS): **044-66726620**\n• M.V. Hospital: **044-25954913**\n• Apollo Sugar: **1800-103-4460**");
        exit;
    }

    // Default top verified Chennai diabetes centres
    outputResponse("Top verified diabetes centres in Chennai:\n" .
                   "• **Dr. Mohan's Diabetes Specialities Centre** (Gopalapuram, Anna Nagar, Velachery - 044-43968888)\n" .
                   "• **Saveetha Medical College Hospital (SIMATS)** (Thandalam - 044-66726620)\n" .
                   "• **M.V. Hospital for Diabetes** (Royapuram - 044-25954913, Foot care specialist)\n" .
                   "• **Apollo Sugar Clinics** (Greams Road, OMR - 1800-103-4460)\n" .
                   "For medical emergencies, dial **108**.");
    exit;
}

// Multi-turn hospital follow-up handling
$wasHospitalContext = strpos($lastAssistantTurn, "hospital") !== false || strpos($lastAssistantTurn, "centre") !== false ||
                      strpos($lastAssistantTurn, "clinic") !== false || strpos($lastAssistantTurn, "chennai") !== false ||
                      strpos($lastAssistantTurn, "dr. mohan") !== false || strpos($lastUserTurn, "hospital") !== false;

if ($wasHospitalContext) {
    if (strpos($lowerMsg, "anna nagar") !== false || strpos($lowerMsg, "near me") !== false || strpos($lowerMsg, "which one is near") !== false) {
        outputResponse("In Anna Nagar, **Dr. Mohan's Diabetes Specialities Centre** (15th Main Rd, Anna Nagar West) and **Apollo Sugar Clinic** are the closest top options.");
        exit;
    }
    if (strpos($lowerMsg, "royapuram") !== false || strpos($lowerMsg, "foot") !== false || strpos($lowerMsg, "wound") !== false) {
        outputResponse("For diabetic foot care or North Chennai, visit **M.V. Hospital for Diabetes** (Royapuram). Contact: 044-25954913.");
        exit;
    }
    if (strpos($lowerMsg, "number") !== false || strpos($lowerMsg, "phone") !== false || strpos($lowerMsg, "contact") !== false || strpos($lowerMsg, "call") !== false) {
        outputResponse("Key Chennai Contacts:\n• Dr. Mohan's: 044-43968888\n• Saveetha Hospital: 044-66726620\n• M.V. Hospital: 044-25954913\n• Emergency: 108");
        exit;
    }
    if (strpos($lowerMsg, "eye") !== false || strpos($lowerMsg, "retina") !== false || strpos($lowerMsg, "vision") !== false) {
        outputResponse("For diabetic retinopathy and eye checks, visit **Dr. Mohan's Retinopathy Dept** (Gopalapuram / Anna Nagar) or **Sankara Nethralaya** (College Road).");
        exit;
    }
}

// Multi-turn diet follow-up handling
$wasDietContext = strpos($lastAssistantTurn, "diet") !== false || strpos($lastAssistantTurn, "food") !== false ||
                  strpos($lastAssistantTurn, "calories") !== false || strpos($lastUserTurn, "diet") !== false;

if ($wasDietContext || strpos($lowerMsg, "dinner") !== false || strpos($lowerMsg, "lunch") !== false || strpos($lowerMsg, "breakfast") !== false) {
    if (strpos($lowerMsg, "dinner") !== false || strpos($lowerMsg, "night") !== false || strpos($lowerMsg, "rathiri") !== false) {
        outputResponse("For dinner, choose low-glycemic, light options: 2 methi or multigrain rotis with dal and cucumber salad, or grilled paneer with sautéed veggies. Avoid heavy white rice at night.");
        exit;
    }
    if (strpos($lowerMsg, "lunch") !== false || strpos($lowerMsg, "afternoon") !== false || strpos($lowerMsg, "madhiyam") !== false) {
        outputResponse("For lunch, balance your plate with 50% green vegetables or poriyal, 1 cup of brown rice or foxtail millet, and a generous bowl of dal or sambar.");
        exit;
    }
    if (strpos($lowerMsg, "breakfast") !== false || strpos($lowerMsg, "morning") !== false || strpos($lowerMsg, "kalai") !== false) {
        outputResponse("For breakfast, opt for high-protein options: sprouted moong salad, vegetable oats upma, or 2 ragi dosas with mint chutney. Avoid sweetened tea or coffee.");
        exit;
    }
}

// Multi-turn glucose follow-up handling ("is that normal?", "is that bad?")
$wasSugarContext = strpos($lastAssistantTurn, "glucose") !== false || strpos($lastAssistantTurn, "blood sugar") !== false ||
                   strpos($lastUserTurn, "glucose") !== false || strpos($lastUserTurn, "sugar") !== false;

if ($wasSugarContext && (strpos($lowerMsg, "normal") !== false || strpos($lowerMsg, "good") !== false || strpos($lowerMsg, "bad") !== false || strpos($lowerMsg, "high") !== false)) {
    if ($latestGlucoseVal !== null) {
        $num = $latestGlucoseVal;
        if ($num < 70) {
            outputResponse("⚠️ At {$num} mg/dL, your blood sugar is low (hypoglycemia). Consume 15g fast-acting carbs immediately and re-test in 15 minutes.");
        } elseif ($num <= 130) {
            outputResponse("✅ Yes, {$num} mg/dL is within the target fasting range (80-130 mg/dL). Excellent control!");
        } elseif ($num <= 180) {
            outputResponse("📊 {$num} mg/dL is acceptable if measured 1-2 hours after a meal (target <180 mg/dL). Take a gentle 15-minute walk.");
        } else {
            outputResponse("⚠️ At {$num} mg/dL, it is elevated (target <130 fasting, <180 post-meal). Drink plenty of water and minimize refined carbs.");
        }
    } else {
        outputResponse("Recommended target ranges are 80-130 mg/dL for fasting and under 180 mg/dL post-meal. Log your reading to see how you are doing!");
    }
    exit;
}

// =========================================================================
// 4. INSTANT PERSONAL HEALTH DATA INTENTS (0.01s Execution)
// =========================================================================

// INTENT A: Blood Sugar / Glucose / HbA1c
if (preg_match('/\b(my\s*(glucose|sugar|blood\s*sugar|reading|readings|level|levels|hba1c|a1c)|en\s*sugar|enoda\s*sugar|sugar\s*evlo|check\s*my\s*sugar|what\s*(is|are)\s*my\s*(glucose|sugar|blood\s*sugar|hba1c|reading)|how\s*is\s*my\s*(glucose|sugar)|tell\s*me\s*my\s*(glucose|sugar)|sugar\s*level|glucose\s*level)\b/i', $userMessage)) {
    if ($latestGlucoseStr !== "No readings logged yet" && $latestGlucoseVal !== null) {
        $num = $latestGlucoseVal;
        if ($num < 70) {
            $interp = "⚠️ Low (<70 mg/dL). Take 15g fast carbs immediately.";
        } elseif ($num <= 130) {
            $interp = "🎯 In target fasting range (80-130 mg/dL).";
        } elseif ($num <= 180) {
            $interp = "📊 Post-meal range (target <180 mg/dL). Normal if tested after food.";
        } else {
            $interp = "⚠️ Elevated (>180 mg/dL). Drink water and take a light walk.";
        }

        $avgDisplay = $avgGlucose ? "{$avgGlucose} mg/dL" : round($num) . " mg/dL";
        $a1cDisplay = $eA1c ? "{$eA1c}%" : round(($num + 46.7) / 28.7, 1) . "%";

        $reply = "• Latest Blood Glucose: {$latestGlucoseStr}\n" .
                 "• Average Glucose: {$avgDisplay} | eA1c: {$a1cDisplay}\n" .
                 "• Status: {$interp}";
    } else {
        $reply = "You haven't logged any blood glucose readings yet today, {$patientName}. Log one on the Glucose screen or say **'Log my blood sugar 125'**.";
    }
    outputResponse($reply);
    exit;
}

// INTENT B: Medications / Tablets / Prescriptions
if (preg_match('/\b(my\s*(medication|medications|medicine|medicines|tablet|tablets|pills?|dose|doses|prescription|prescriptions)|what\s*(medication|medications|medicine|medicines|tablet|tablets|pill|pills)|what\s*(am\s*i\s*taking|do\s*i\s*take)|en\s*(tablet|marundhu)|next\s*dose|medication\s*schedule|what\s*(are|is)\s*my\s*(medicine|medication|tablet|tablets|pills))\b/i', $userMessage)) {
    if ($activeMedsStr !== "No active medications scheduled") {
        $reply = "Active Prescriptions for {$patientName}:\n• {$activeMedsStr}\n\nTake medications consistently with water after meals to prevent stomach upset.";
    } else {
        $reply = "You have no active medications scheduled in your DiaWell routine, {$patientName}. Say **'Remind me to take Metformin at 8 PM'** to set an alarm.";
    }
    outputResponse($reply);
    exit;
}

// INTENT C: Diet / Calories / Water / Exercise / Today's Health Summary
if (preg_match('/\b(my\s*(diet|calories|meals?|food|water|workout|activity|exercise|report|summary|health\s*today)|what\s*did\s*i\s*eat|how\s*am\s*i\s*doing|today\'?s?\s*(status|summary|health|report)|(how\s*much|how\s*many)\s*(water|calories|glasses)|summary\s*of\s*my\s*health)\b/i', $userMessage)) {
    $reply = "DiaWell Summary for {$patientName} today:\n" .
             "• Glucose: {$latestGlucoseStr}\n" .
             "• Medications: {$activeMedsStr}\n" .
             "• Water: {$waterStr} | Calories In: {$calInStr} | Burned: {$calBurnedStr}\n" .
             "Aim for a brisk 15-20 minute walk after your main meal to improve insulin sensitivity.";
    outputResponse($reply);
    exit;
}

// =========================================================================
// 5. GENERAL MEDICAL / DIET QUESTIONS (Multi-Turn Ollama Pipeline)
// =========================================================================

function normalizeTanglish(string $text): string {
    $replacements = [
        '/(thalai\s*suthal|mayakkam)/i' => 'dizziness (low sugar check)',
        '/(kammi\s*panna|kuraika|kuraiya|koraika)/i' => 'to lower',
        '/(kalaila|kaalaila|kaalai)/i' => 'morning breakfast',
        '/(madhiyam|madyam|mathiyam)/i' => 'afternoon lunch',
        '/(rathiri|naight|iravu)/i' => 'night dinner',
        '/(sapadu|saapadu|sapdalam|saapidalam|sapda|thinnuvathu)/i' => 'food to eat',
        '/(sarkarai|sarkkarai)/i' => 'blood sugar',
        '/(athigam|jasthi|adhigam)/i' => 'high',
        '/(nadai|nadakkalam)/i' => 'walking',
        '/(pasikudhu|pasi)/i' => 'feeling hungry',
        '/(epdi|eppadi|yeppadi)/i' => 'how',
        '/(enna|yenna)/i' => 'what'
    ];

    foreach ($replacements as $pat => $rep) {
        $text = preg_replace($pat, $rep, $text);
    }
    return $text;
}

$normalizedPrompt = normalizeTanglish($userMessage);

// Personal Health Context Injected System Prompt: Enforce 2-3 lines, zero hallucinations
$systemPrompt = "You are DIVA (DiaWell Intelligent Virtual Assistant), DiaWell's rapid AI Diabetes Specialist. " .
                "Always identify yourself as DIVA (DiaWell Intelligent Virtual Assistant) and never use any other assistant name. " .
                "Patient Profile: Name: {$patientName}, Latest Blood Sugar: {$latestGlucoseStr}, Food Preferences: {$foodPrefStr}, Active Meds: {$activeMedsStr}. " .
                "Give direct, concise medical advice in 2-3 short sentences (or max 3 brief bullet points). Focus strictly on what was asked. " .
                "Never write long essays or generic disclaimers. Never mention cities outside Chennai or Tamil Nadu. Understand English and Tanglish.";

$ollamaUrl = "http://127.0.0.1:11434/api/chat";
$model = "qwen2.5:0.5b";

$options = [
    "num_gpu" => 0,
    "num_ctx" => 512,
    "num_predict" => 90,
    "temperature" => 0.3
];

// Build multi-turn messages array
$messagesPayload = [
    ["role" => "system", "content" => $systemPrompt]
];

if (!empty($history) && is_array($history)) {
    $recentHistory = array_slice($history, -6);
    foreach ($recentHistory as $turn) {
        $turnRole = strtolower($turn["role"] ?? "");
        $turnContent = trim($turn["content"] ?? "");
        if (!empty($turnContent)) {
            $cleanRole = ($turnRole === "assistant" || $turnRole === "ai") ? "assistant" : "user";
            $messagesPayload[] = ["role" => $cleanRole, "content" => $turnContent];
        }
    }
}
$messagesPayload[] = ["role" => "user", "content" => $normalizedPrompt];

function queryOllama(string $url, string $model, array $messages, array $options): ?string {
    $payload = json_encode([
        "model" => $model,
        "messages" => $messages,
        "stream" => false,
        "keep_alive" => "24h",
        "options" => $options
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $httpOptions = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
            "content" => $payload,
            "timeout" => 10,
            "ignore_errors" => true
        ]
    ];
    $context = stream_context_create($httpOptions);
    $response = @file_get_contents($url, false, $context);

    if ($response !== false) {
        $decoded = json_decode($response, true);
        $content = trim($decoded["message"]["content"] ?? "");
        if (!empty($content)) {
            return $content;
        }
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);
        $curlResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlResponse !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($curlResponse, true);
            $content = trim($decoded["message"]["content"] ?? "");
            if (!empty($content)) {
                return $content;
            }
        }
    }

    return null;
}

$aiReply = queryOllama($ollamaUrl, $model, $messagesPayload, $options);

if (!empty($aiReply)) {
    // Post-process to remove Chinese characters and hallucinated city names
    $aiReply = preg_replace('/[\x{4e00}-\x{9fa5}]/u', '', $aiReply);
    $aiReply = preg_replace('/\b(chuwati|guwahati|assam)\b/i', 'Chennai', $aiReply);
    $aiReply = trim($aiReply);
}

if (empty($aiReply)) {
    // Intelligent clinical fallback personalized to patient (Concise: 2-3 lines max)
    $lowerPrompt = strtolower($userMessage);
    if (strpos($lowerPrompt, "diet") !== false || strpos($lowerPrompt, "eat") !== false || strpos($lowerPrompt, "food") !== false) {
        $aiReply = "Focus on low-glycemic, high-fiber foods: sprouted legumes, methi roti, green vegetables, and millets.\n" .
                   "Minimize polished white rice and sweets to avoid rapid glucose spikes.\n" .
                   "Ensure adequate lean protein (dal, paneer, eggs) with every meal.";
    } elseif (strpos($lowerPrompt, "walk") !== false || strpos($lowerPrompt, "exercise") !== false || strpos($lowerPrompt, "workout") !== false) {
        $aiReply = "Aim for a brisk 15 to 30-minute walk after meals to stimulate skeletal muscle glucose uptake.\n" .
                   "Add light resistance exercises or yoga 3 times a week to improve insulin sensitivity.";
    } else {
        $aiReply = "Hello {$patientName}! Maintain balanced low-glycemic nutrition, stay hydrated, and monitor your glucose regularly.\n" .
                   "Ask me about your sugar readings, medications, or meals anytime!";
    }
}

outputResponse($aiReply);
