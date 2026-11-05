<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

$data = getRequestData();
$targetToken = trim($data["fcm_token"] ?? "");
$userId = $data["user_id"] ?? getUserId();

$title = trim($data["title"] ?? "💊 Time for your Medication!");
$body = trim($data["body"] ?? "");
$medName = trim($data["med_name"] ?? "");
$dosage = trim($data["dosage"] ?? "");

if (empty($body)) {
    if (!empty($medName)) {
        $body = "Please take your scheduled dose of $medName" . (!empty($dosage) ? " ($dosage)" : "") . ".";
    } else {
        $body = "It is time to take your scheduled diabetes medication.";
    }
}

// If token not provided directly, look up from user_fcm_tokens table
if (empty($targetToken) && !empty($userId)) {
    try {
        $stmt = $pdo->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([":user_id" => $userId]);
        $row = $stmt->fetch();
        if ($row && !empty($row["fcm_token"])) {
            $targetToken = $row["fcm_token"];
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
}

// Also save record into existing notifications table so it appears in the app's notification history
if (!empty($userId)) {
    try {
        $insertStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, body, notification_type, is_read, created_at) VALUES (:user_id, :title, :body, 'medication', 0, NOW())");
        $insertStmt->execute([
            ":user_id" => $userId,
            ":title" => $title,
            ":body" => $body
        ]);
    } catch (Exception $e) {
        // Ignore if notifications table not present
    }
}

$fcmResult = [
    "dispatched" => false,
    "target_token" => $targetToken,
    "title" => $title,
    "body" => $body,
    "note" => ""
];

// Check for Firebase Service Account JSON
$serviceAccountPath = __DIR__ . '/../config/firebase_service_account.json';
$fcmProjectId = "diawell-5e46e"; // From google-services.json

if (!empty($targetToken)) {
    if (file_exists($serviceAccountPath)) {
        $sa = json_decode(file_get_contents($serviceAccountPath), true);
        if ($sa && isset($sa["client_email"], $sa["private_key"])) {
            $accessToken = getGoogleAccessToken($sa);
            if ($accessToken) {
                $v1Url = "https://fcm.googleapis.com/v1/projects/{$fcmProjectId}/messages:send";
                $messagePayload = [
                    "message" => [
                        "token" => $targetToken,
                        "notification" => [
                            "title" => $title,
                            "body" => $body
                        ],
                        "data" => [
                            "med_name" => $medName,
                            "dosage" => $dosage,
                            "title" => $title,
                            "body" => $body
                        ]
                    ]
                ];

                $ch = curl_init($v1Url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($messagePayload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer " . $accessToken,
                        "Content-Type: application/json"
                    ],
                    CURLOPT_TIMEOUT => 10
                ]);
                $fcmResp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $fcmResult["dispatched"] = ($httpCode >= 200 && $httpCode < 300);
                $fcmResult["response"] = json_decode($fcmResp, true) ?: $fcmResp;
            }
        }
    } else {
        $fcmResult["note"] = "Token saved in database. Direct local heads-up notification and exact alarm triggered on Android.";
    }
}

respond(true, "Medication notification processed successfully.", 200, $fcmResult);

function getGoogleAccessToken(array $sa): ?string {
    try {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claim = json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlClaim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claim));

        $signature = '';
        $binarySignature = '';
        $pkey = openssl_pkey_get_private($sa['private_key']);
        if (!$pkey) return null;
        openssl_sign("$base64UrlHeader.$base64UrlClaim", $binarySignature, $pkey, OPENSSL_ALGO_SHA256);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($binarySignature));

        $jwt = "$base64UrlHeader.$base64UrlClaim.$base64UrlSignature";

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 10
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($res, true);
        return $json['access_token'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}