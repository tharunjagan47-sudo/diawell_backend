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

if (empty($userMessage)) {
    http_response_code(200);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "response" => "Message cannot be empty.",
        "reply" => "Message cannot be empty.",
        "message" => "Message cannot be empty.",
        "answer" => "Message cannot be empty.",
        "model" => "qwen2.5:0.5b",
        "data" => [
            "response" => "Message cannot be empty.",
            "reply" => "Message cannot be empty.",
            "message" => "Message cannot be empty."
        ]
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

// Greeting check
if (preg_match('/^(hi|hello|hey|hai|vanakkam|good\s*(morning|afternoon|evening)|halo|dr\s*jarvis|jarvis)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 25) {
    outputResponse("Hello! I am Dr. Jarvis, your DiaWell diabetes wellness companion. How can I assist you with your diet, blood sugar, or exercise routine today?");
    exit;
}

// Thanking check
if (preg_match('/^(thank\s*you|thanks|thanku|thx|nandri|romba\s*nandri|thank\s*you\s*so\s*much|thanks\s*a\s*lot)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 30) {
    outputResponse("You're very welcome! Stay healthy, monitor your glucose levels regularly, and feel free to ask whenever you need lifestyle or diet tips. Take care!");
    exit;
}

// Goodbye check
if (preg_match('/^(bye|goodbye|see\s*you|tata|good\s*night)($|\s)/i', $cleanAlpha) && strlen($cleanAlpha) < 20) {
    outputResponse("Goodbye! Take great care of your health and reach out anytime you need support.");
    exit;
}

/**
 * Instant Zero-Token Tanglish-to-English Normalization:
 * Direct word-boundary mapping in PHP before sending to Ollama.
 * Converts multi-token Tanglish terms to standard English in 0.1ms.
 */
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

// Ultra-Compact System Prompt (<40 tokens)
$systemPrompt = "You are Dr. Jarvis, DiaWell's rapid AI Diabetes Specialist. Understand English and Tanglish. Reply in 2-3 short, practical bullet points with Indian low-GI diet or exercise advice. Never generate reports, weekly summaries, or consultation notes.";

$ollamaUrl = "http://127.0.0.1:11434/api/chat";
$model = "qwen2.5:0.5b";

$options = [
    "num_gpu" => 0,
    "num_ctx" => 512,
    "num_predict" => 120,
    "temperature" => 0.5
];

/**
 * Execute Ollama chat request via native HTTP Stream (robust, zero extension dependency) with cURL fallback
 */
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

    // Native stream context (guaranteed built-in PHP support, no DLL needed)
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

    // Fallback to cURL if available
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
    $aiReply = "I am currently reviewing your diabetes wellness query. Please verify local Ollama is active and try again shortly.";
}

outputResponse($aiReply);
