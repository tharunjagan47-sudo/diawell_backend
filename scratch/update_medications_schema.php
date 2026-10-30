<?php
require_once __DIR__ . '/../config/db.php';

$fields = [
    "therapeutic_class" => "VARCHAR(100) NULL",
    "manufacturer" => "VARCHAR(150) NULL",
    "primary_ingredient" => "VARCHAR(150) NULL",
    "packaging" => "VARCHAR(100) NULL",
    "dosage_form" => "VARCHAR(50) NULL"
];

$existing = [];
$stmt = $pdo->query("DESCRIBE medications");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existing[] = strtolower($row['Field']);
}

foreach ($fields as $field => $def) {
    if (!in_array(strtolower($field), $existing)) {
        $pdo->exec("ALTER TABLE medications ADD COLUMN {$field} {$def}");
        echo "Added column $field\n";
    } else {
        echo "Column $field already exists\n";
    }
}
echo "Schema update completed.\n";

