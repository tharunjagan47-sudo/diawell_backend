<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$userId = getUserId() ?: 1;

$rawDate = trim($_GET["date"] ?? date("Y-m-d"));
$parsed = strtotime($rawDate);
$selectedDate = ($parsed !== false) ? date("Y-m-d", $parsed) : date("Y-m-d");

/**
 * Auto-creates and repairs medications table schema dynamically
 */
function ensureMedicationsSchema(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS medications (
                id INT(11) NOT NULL AUTO_INCREMENT,
                user_id INT(11) NOT NULL,
                name VARCHAR(150) NOT NULL,
                dose VARCHAR(100) NOT NULL,
                reminder_time TIME NOT NULL,
                reminder_date DATE NOT NULL,
                timing VARCHAR(50) DEFAULT 'After Meal',
                frequency VARCHAR(50) DEFAULT 'Daily',
                status VARCHAR(20) DEFAULT 'Upcoming',
                notes TEXT DEFAULT NULL,
                therapeutic_class VARCHAR(150) DEFAULT '',
                manufacturer VARCHAR(150) DEFAULT '',
                primary_ingredient VARCHAR(150) DEFAULT '',
                packaging VARCHAR(100) DEFAULT '',
                dosage_form VARCHAR(50) DEFAULT 'Tablet',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_meds_user_date (user_id, reminder_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $cols = [];
        $desc = $pdo->query("DESCRIBE medications")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($desc as $c) {
            $cols[strtolower($c['Field'])] = true;
        }

        $neededColumns = [
            'user_id' => 'INT(11) NOT NULL',
            'name' => 'VARCHAR(150) NOT NULL',
            'dose' => 'VARCHAR(100) NOT NULL',
            'reminder_time' => 'TIME NOT NULL',
            'reminder_date' => 'DATE NOT NULL',
            'timing' => "VARCHAR(50) DEFAULT 'After Meal'",
            'frequency' => "VARCHAR(50) DEFAULT 'Daily'",
            'status' => "VARCHAR(20) DEFAULT 'Upcoming'",
            'notes' => 'TEXT DEFAULT NULL',
            'therapeutic_class' => "VARCHAR(150) DEFAULT ''",
            'manufacturer' => "VARCHAR(150) DEFAULT ''",
            'primary_ingredient' => "VARCHAR(150) DEFAULT ''",
            'packaging' => "VARCHAR(100) DEFAULT ''",
            'dosage_form' => "VARCHAR(50) DEFAULT 'Tablet'",
            'is_active' => 'TINYINT(1) DEFAULT 1'
        ];

        foreach ($neededColumns as $col => $typeDef) {
            if (!isset($cols[$col])) {
                $pdo->exec("ALTER TABLE medications ADD COLUMN {$col} {$typeDef}");
            }
        }
    } catch (Exception $e) {
        error_log("ensureMedicationsSchema error: " . $e->getMessage());
    }
}

// 1. Ensure schema is fully intact before querying
ensureMedicationsSchema($pdo);

$formattedMeds = [];
$takenCount = 0;

try {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, name, dose, reminder_time, reminder_date, timing, frequency, status, notes,
                therapeutic_class, manufacturer, primary_ingredient, packaging, dosage_form, is_active, created_at
         FROM medications
         WHERE user_id = :user_id AND (reminder_date = :date OR frequency = 'Daily')
         ORDER BY reminder_time ASC"
    );
    $stmt->execute([":user_id" => $userId, ":date" => $selectedDate]);
    $meds = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($meds as $row) {
        $status = ucfirst(strtolower($row["status"] ?? "Upcoming"));
        if ($status === "Taken") {
            $takenCount++;
            $statusColor = "#2E7D32";
            $statusBg = "#E8F5E9";
        } elseif ($status === "Missed") {
            $statusColor = "#C62828";
            $statusBg = "#FFEBEE";
        } else {
            $status = "Upcoming";
            $statusColor = "#B81D85";
            $statusBg = "#FDEEF7";
        }

        $rawTime = $row["reminder_time"] ?? "08:00:00";
        $formattedTime = date("g:i A", strtotime($rawTime));

        $formattedMeds[] = [
            "id" => (int) $row["id"],
            "name" => $row["name"] ?? "",
            "dose" => $row["dose"] ?? "",
            "reminder_time" => $formattedTime,
            "raw_time" => $rawTime,
            "reminder_date" => $row["reminder_date"] ?? $selectedDate,
            "timing" => $row["timing"] ?? "After Meal",
            "frequency" => $row["frequency"] ?? "Daily",
            "status" => $status,
            "status_color" => $statusColor,
            "status_bg" => $statusBg,
            "notes" => $row["notes"] ?? "",
            "therapeutic_class" => $row["therapeutic_class"] ?? "",
            "category" => $row["therapeutic_class"] ?? "",
            "manufacturer" => $row["manufacturer"] ?? "",
            "primary_ingredient" => $row["primary_ingredient"] ?? "",
            "packaging" => $row["packaging"] ?? "",
            "dosage_form" => $row["dosage_form"] ?? "Tablet"
        ];
    }
} catch (Exception $e) {
    error_log("Medications query fallback: " . $e->getMessage());
    $formattedMeds = [];
}

$totalCount = count($formattedMeds);
$adherencePercent = $totalCount > 0 ? round(($takenCount / $totalCount) * 100) : 0;

$dataPayload = [
    "user_id" => $userId,
    "date" => $selectedDate,
    "date_label" => date("D, j M Y", strtotime($selectedDate)),
    "stats" => [
        "total" => $totalCount,
        "taken" => $takenCount,
        "upcoming" => $totalCount - $takenCount,
        "adherence_percent" => $adherencePercent
    ],
    "medications" => $formattedMeds
];

http_response_code(200);
echo json_encode([
    "success" => true,
    "status" => "success",
    "message" => "Medications retrieved successfully.",
    "medications" => $formattedMeds,
    "data" => $dataPayload
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
