<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed. Use POST.", 405);
}

$data = getRequestData();
$userId = getUserId($data) ?: 1;

$name = trim($data["name"] ?? $data["medication_name"] ?? $data["medicationName"] ?? "");
$dose = trim($data["dose"] ?? $data["medication_dose"] ?? $data["medicationDose"] ?? "500 mg • 1 Tablet");
$rawTime = trim($data["reminder_time"] ?? $data["selectedTime"] ?? "08:00:00");
$rawDate = trim($data["reminder_date"] ?? $data["reminderDateText"] ?? date("Y-m-d"));
$timing = trim($data["timing"] ?? $data["selectedTiming"] ?? "After Meal");
$frequency = trim($data["frequency"] ?? "Daily");
$notes = trim($data["notes"] ?? "");
$therapeuticClass = trim($data["therapeutic_class"] ?? $data["category"] ?? "");
$manufacturer = trim($data["manufacturer"] ?? "");
$primaryIngredient = trim($data["primary_ingredient"] ?? "");
$packaging = trim($data["packaging"] ?? "");
$dosageForm = trim($data["dosage_form"] ?? "Tablet");

if ($name === "") {
    respond(false, "Medication name is required.", 400);
}

// Normalize date
$parsedDate = strtotime($rawDate);
$reminderDate = ($parsedDate !== false) ? date("Y-m-d", $parsedDate) : date("Y-m-d");

// Normalize time
$parsedTime = strtotime($rawTime);
$reminderTime = ($parsedTime !== false) ? date("H:i:s", $parsedTime) : "08:00:00";

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

ensureMedicationsSchema($pdo);

try {
    // 1. Verify user exists or insert placeholder
    $check = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $check->execute([":id" => $userId]);
    if (!$check->fetch()) {
        $ins = $pdo->prepare("INSERT IGNORE INTO users (id, full_name, email, password_hash) VALUES (:id, 'User', CONCAT('user', :id, '@diawell.local'), 'hash123')");
        $ins->execute([":id" => $userId]);
    }

    // 2. Insert medication reminder
    $stmt = $pdo->prepare(
        "INSERT INTO medications (user_id, name, dose, reminder_time, reminder_date, timing, frequency, status, notes, therapeutic_class, manufacturer, primary_ingredient, packaging, dosage_form, is_active)
         VALUES (:user_id, :name, :dose, :reminder_time, :reminder_date, :timing, :frequency, 'Upcoming', :notes, :therapeutic_class, :manufacturer, :primary_ingredient, :packaging, :dosage_form, 1)"
    );

    $stmt->execute([
        ":user_id" => $userId,
        ":name" => $name,
        ":dose" => $dose,
        ":reminder_time" => $reminderTime,
        ":reminder_date" => $reminderDate,
        ":timing" => $timing,
        ":frequency" => $frequency,
        ":notes" => $notes,
        ":therapeutic_class" => $therapeuticClass,
        ":manufacturer" => $manufacturer,
        ":primary_ingredient" => $primaryIngredient,
        ":packaging" => $packaging,
        ":dosage_form" => $dosageForm
    ]);

    $medId = (int) $pdo->lastInsertId();

    // 3. Create reminder notification if notifications table exists
    try {
        $timeLabel = date("g:i A", strtotime($reminderTime));
        $notif = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, body, notification_type) 
             VALUES (:user_id, :title, :body, 'medication')"
        );
        $notif->execute([
            ":user_id" => $userId,
            ":title" => "Medication Reminder Set",
            ":body" => "Reminder active for {$name} ({$dose}) scheduled at {$timeLabel} ({$timing})."
        ]);
    } catch (Exception $ne) {
        // Non-blocking notification insert failure
    }

    $timeLabel = date("g:i A", strtotime($reminderTime));

    $medicationItem = [
        "id" => $medId,
        "name" => $name,
        "dose" => $dose,
        "reminder_time" => $timeLabel,
        "raw_time" => $reminderTime,
        "reminder_date" => $reminderDate,
        "timing" => $timing,
        "frequency" => $frequency,
        "status" => "Upcoming",
        "status_color" => "#B81D85",
        "status_bg" => "#FDEEF7",
        "notes" => $notes,
        "therapeutic_class" => $therapeuticClass,
        "category" => $therapeuticClass,
        "manufacturer" => $manufacturer,
        "primary_ingredient" => $primaryIngredient,
        "packaging" => $packaging,
        "dosage_form" => $dosageForm
    ];

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "status" => "success",
        "message" => "Medication reminder set successfully.",
        "data" => $medicationItem
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (PDOException $e) {
    error_log("Medication add error: " . $e->getMessage());
    respond(false, "Database error setting medication reminder: " . $e->getMessage(), 500);
}
