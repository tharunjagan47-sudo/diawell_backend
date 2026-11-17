<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true) ?: [];

// Extract user message across all common parameter names
$userMessage = trim($data["message"] ?? $data["prompt"] ?? $data["query"] ?? $_POST["message"] ?? $_POST["prompt"] ?? $_GET["message"] ?? "");
$userId = intval($data["user_id"] ?? $_POST["user_id"] ?? $_GET["user_id"] ?? $_SERVER["HTTP_X_USER_ID"] ?? 0);
$clientContext = is_array($data["patient_context"] ?? null) ? $data["patient_context"] : [];

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

if (preg_match('/^(hi|hello|hey|hai|vanakkam|good\s*(morning|afternoon|evening)|halo|dr\s*jarvis|jarvis)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 25) {
    $patientGreetingName = !empty($clientContext["patient_name"]) ? $clientContext["patient_name"] : "";
    $namePart = $patientGreetingName ? " {$patientGreetingName}" : "";
    outputResponse("Hello{$namePart}! I am Dr. Jarvis, your DiaWell diabetes wellness companion. How can I assist you with your blood sugar, medications, diet, or workout today?");
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
$dbConfigPath = file_exists(__DIR__ . '/../config/db.php') 
    ? __DIR__ . '/../config/db.php' 
    : __DIR__ . '/../../config/db.php';

$pdo = null;
if (file_exists($dbConfigPath)) {
    try {
        require_once $dbConfigPath;
    } catch (\Throwable $t) {
        $pdo = null;
    }
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

// =========================================================================
// 3. INSTANT PERSONAL HEALTH DATA INTENTS (0.01s Execution)
// =========================================================================

// INTENT A: Blood Sugar / Glucose / HbA1c
if (preg_match('/\b(my\s*(glucose|sugar|blood\s*sugar|reading|readings|level|levels|hba1c|a1c)|en\s*sugar|enoda\s*sugar|sugar\s*evlo|check\s*my\s*sugar|what\s*(is|are)\s*my\s*(glucose|sugar|blood\s*sugar|hba1c|reading)|how\s*is\s*my\s*(glucose|sugar)|tell\s*me\s*my\s*(glucose|sugar)|sugar\s*level|glucose\s*level)\b/i', $userMessage)) {
    if ($latestGlucoseStr !== "No readings logged yet" && $latestGlucoseVal !== null) {
        $num = $latestGlucoseVal;
        if ($num < 70) {
            $interp = "⚠️ **Low Blood Glucose (Hypoglycemia):** Blood sugar is critically below 70 mg/dL. Take 15g of fast-acting carbs (e.g., fruit juice, 3 glucose tablets, or a tablespoon of sugar in water) immediately and re-test in 15 minutes.";
        } elseif ($num <= 130) {
            $interp = "🎯 **In Target Range:** Excellent glucose control! Your reading is aligned with recommended fasting diabetes targets (80-130 mg/dL). Keep adhering to your nutrition and activity routine.";
        } elseif ($num <= 180) {
            $interp = "📊 **Mildly Elevated (Post-Meal Range):** Normal if taken within 1 to 2 hours after a meal. Stay well hydrated and consider a gentle 15-minute walk to help insulin clear glucose into muscles.";
        } else {
            $interp = "⚠️ **Elevated Glucose (Hyperglycemia):** Your reading is above 180 mg/dL. Drink ample plain water, avoid high-glycemic carbohydrates or sugary foods, and engage in light walking if feeling well.";
        }

        $avgDisplay = $avgGlucose ? "{$avgGlucose} mg/dL" : round($num) . " mg/dL";
        $a1cDisplay = $eA1c ? "{$eA1c}%" : round(($num + 46.7) / 28.7, 1) . "%";

        $reply = "Hello {$patientName}, based on your DiaWell logs:\n\n" .
                 "• **Latest Blood Glucose:** {$latestGlucoseStr}\n" .
                 "• **Average Glucose:** {$avgDisplay}\n" .
                 "• **Estimated HbA1c (eA1c):** {$a1cDisplay}\n" .
                 "• **Clinical Status:** {$interp}";
    } else {
        $reply = "You haven't logged any blood glucose readings yet today, {$patientName}. You can log one right now on the Glucose screen or just say **'Log my blood sugar 130'** and I will record it for you!";
    }
    outputResponse($reply);
    exit;
}

// INTENT B: Medications / Tablets / Prescriptions
if (preg_match('/\b(my\s*(medication|medications|medicine|medicines|tablet|tablets|pills?|dose|doses|prescription|prescriptions)|what\s*(medication|medications|medicine|medicines|tablet|tablets|pill|pills)|what\s*(am\s*i\s*taking|do\s*i\s*take)|en\s*(tablet|marundhu)|next\s*dose|medication\s*schedule|what\s*(are|is)\s*my\s*(medicine|medication|tablet|tablets|pills))\b/i', $userMessage)) {
    if ($activeMedsStr !== "No active medications scheduled") {
        $reply = "Hello {$patientName}, here are your scheduled DiaWell medications:\n\n" .
                 "• **Active Prescriptions:** {$activeMedsStr}\n\n" .
                 "⚡ **Doctor's Note:** Take all prescribed medications consistently with water. If taking Metformin, take it with or immediately after a meal to prevent stomach upset. Say **'Remind me to take [Medication] at [Time]'** anytime to add a new alarm!";
    } else {
        $reply = "You have no active medications scheduled in your DiaWell routine, {$patientName}. Would you like me to set a reminder? Just say **'Remind me to take Metformin at 8 PM'** or tap the Medication tab!";
    }
    outputResponse($reply);
    exit;
}

// INTENT C: Diet / Calories / Water / Exercise / Today's Health Summary
if (preg_match('/\b(my\s*(diet|calories|meals?|food|water|workout|activity|exercise|report|summary|health\s*today)|what\s*did\s*i\s*eat|how\s*am\s*i\s*doing|today\'?s?\s*(status|summary|health|report)|(how\s*much|how\s*many)\s*(water|calories|glasses)|summary\s*of\s*my\s*health)\b/i', $userMessage)) {
    $reply = "Hello {$patientName}, here is your DiaWell health summary for today:\n\n" .
             "• **Blood Glucose:** {$latestGlucoseStr}\n" .
             "• **Active Medications:** {$activeMedsStr}\n" .
             "• **Water Intake:** {$waterStr}\n" .
             "• **Calories Consumed:** {$calInStr}\n" .
             "• **Calories Burned:** {$calBurnedStr}\n" .
             "• **Food Preferences:** {$foodPrefStr}\n\n" .
             "⚡ **Doctor's Tip:** Maintain a balanced low-glycemic diet, drink water regularly, and aim for a 15-20 minute brisk walk after your largest meal to improve insulin sensitivity!";
    outputResponse($reply);
    exit;
}

// =========================================================================
// 4. GENERAL MEDICAL / DIET QUESTIONS (Injected Personal Context to Ollama)
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

// Personal Health Context Injected System Prompt
$systemPrompt = "You are Dr. Jarvis, DiaWell's rapid AI Diabetes Specialist. " .
                "Patient Profile: Name: {$patientName}, Latest Blood Sugar: {$latestGlucoseStr}, Food Preferences: {$foodPrefStr}, Active Meds: {$activeMedsStr}. " .
                "Tailor your medical advice specifically to this patient's diabetes status and food allergies. " .
                "Reply in 2-3 short, practical bullet points with Indian low-GI diet or exercise advice. Understand English and Tanglish.";

$ollamaUrl = "http://127.0.0.1:11434/api/chat";
$model = "qwen2.5:0.5b";

$options = [
    "num_gpu" => 0,
    "num_ctx" => 512,
    "num_predict" => 120,
    "temperature" => 0.5
];

function queryOllama(string $url, string $model, string $systemPrompt, string $userPrompt, array $options): ?string {
    $payload = json_encode([
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userPrompt]
        ],
        "stream" => false,
        "keep_alive" => "24h",
        "options" => $options
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $httpOptions = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
            "content" => $payload,
            "timeout" => 15,
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
            CURLOPT_TIMEOUT => 20,
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

$aiReply = queryOllama($ollamaUrl, $model, $systemPrompt, $normalizedPrompt, $options);

if (empty($aiReply)) {
    // Intelligent clinical fallback personalized to patient
    $lowerPrompt = strtolower($userMessage);
    if (strpos($lowerPrompt, "diet") !== false || strpos($lowerPrompt, "eat") !== false || strpos($lowerPrompt, "food") !== false) {
        $aiReply = "Hello {$patientName}, here are personalized dietary guidelines:\n" .
                   "• Prioritize high-fiber, low-glycemic foods such as sprouted green gram, methi (fenugreek) roti, and sautéed vegetables.\n" .
                   "• Minimize polished white rice, sweet snacks, and sugary drinks to prevent rapid post-meal spikes.\n" .
                   "• Maintain balanced portions with adequate lean protein to steady insulin release.";
    } elseif (strpos($lowerPrompt, "walk") !== false || strpos($lowerPrompt, "exercise") !== false || strpos($lowerPrompt, "workout") !== false) {
        $aiReply = "Hello {$patientName}, here is your tailored physical activity guidance:\n" .
                   "• Aim for a brisk 15 to 30-minute walk after lunch or dinner; this directly stimulates muscle glucose uptake.\n" .
                   "• Incorporate light bodyweight resistance or yoga 3 times a week to enhance insulin sensitivity.\n" .
                   "• Carry a small source of fast carbs if engaging in extended aerobic exercise.";
    } else {
        $aiReply = "Hello {$patientName}! I have reviewed your wellness query. " .
                   "Based on your profile, maintain balanced low-GI nutrition, track your glucose regularly, and stay hydrated with water. " .
                   "Feel free to ask me specifically about your glucose readings, medications, or meals anytime!";
    }
}

outputResponse($aiReply);
