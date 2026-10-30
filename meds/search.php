<?php
require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$rawQuery = trim($_GET["query"] ?? $_GET["q"] ?? "");
$dbPath = __DIR__ . '/../data/meds.db';

// Fallback curated list in case SQLite database file is missing
$fallbackList = [
    [
        "name" => "Glycomet 500 Tablet",
        "dose" => "500mg • Tablet",
        "primary_ingredient" => "Metformin",
        "primary_strength" => "500mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "USV Ltd",
        "packaging" => "strip of 10 tablets",
        "price_inr" => "₹18.70",
        "timing" => "With Meal",
        "info" => "First-line oral antidiabetic; lowers hepatic glucose production and improves insulin sensitivity.",
        "icon" => "💊"
    ],
    [
        "name" => "Amaryl 2mg Tablet",
        "dose" => "2mg • Tablet",
        "primary_ingredient" => "Glimepiride",
        "primary_strength" => "2mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Sanofi India Ltd",
        "packaging" => "strip of 15 tablets",
        "price_inr" => "₹182.50",
        "timing" => "Before Breakfast",
        "info" => "Second-generation sulfonylurea; stimulates pancreatic beta-cells to secrete insulin.",
        "icon" => "💊"
    ],
    [
        "name" => "Januvia 100mg Tablet",
        "dose" => "100mg • Tablet",
        "primary_ingredient" => "Sitagliptin",
        "primary_strength" => "100mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "MSD Pharmaceuticals Pvt Ltd",
        "packaging" => "strip of 14 tablets",
        "price_inr" => "₹385.00",
        "timing" => "Once Daily",
        "info" => "DPP-4 inhibitor; enhances natural incretin hormone levels to regulate postprandial glucose.",
        "icon" => "💊"
    ],
    [
        "name" => "Jardiance 10mg Tablet",
        "dose" => "10mg • Tablet",
        "primary_ingredient" => "Empagliflozin",
        "primary_strength" => "10mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Boehringer Ingelheim",
        "packaging" => "strip of 10 tablets",
        "price_inr" => "₹540.00",
        "timing" => "In the Morning",
        "info" => "SGLT2 inhibitor; promotes urinary excretion of excess glucose and offers cardiorenal protection.",
        "icon" => "💊"
    ],
    [
        "name" => "Lantus 100IU/ml Cartridge",
        "dose" => "100IU/ml • Injection",
        "primary_ingredient" => "Insulin Glargine",
        "primary_strength" => "100IU/ml",
        "dosage_form" => "injection",
        "category" => "Antidiabetic",
        "manufacturer" => "Sanofi India Ltd",
        "packaging" => "packet of 5 cartridges",
        "price_inr" => "₹1,450.00",
        "timing" => "At Bedtime",
        "info" => "Long-acting recombinant human basal insulin delivering smooth 24-hour glycemic control.",
        "icon" => "💉"
    ],
    [
        "name" => "Galvus Met 50mg/500mg Tablet",
        "dose" => "50mg/500mg • Tablet",
        "primary_ingredient" => "Vildagliptin + Metformin",
        "primary_strength" => "50mg/500mg",
        "dosage_form" => "tablet",
        "category" => "Antidiabetic",
        "manufacturer" => "Novartis India Ltd",
        "packaging" => "strip of 10 tablets",
        "price_inr" => "₹260.00",
        "timing" => "With Meal",
        "info" => "Dual-action combination targeting insulin secretion and insulin resistance simultaneously.",
        "icon" => "💊"
    ],
    [
        "name" => "Atorva 10 Tablet",
        "dose" => "10mg • Tablet",
        "primary_ingredient" => "Atorvastatin",
        "primary_strength" => "10mg",
        "dosage_form" => "tablet",
        "category" => "Cardiovascular",
        "manufacturer" => "Zydus Cadila",
        "packaging" => "strip of 15 tablets",
        "price_inr" => "₹95.00",
        "timing" => "At Bedtime",
        "info" => "Lipid-lowering statin providing essential cardiovascular protection in diabetes.",
        "icon" => "💊"
    ],
    [
        "name" => "Becosules Capsule",
        "dose" => "1 Capsule",
        "primary_ingredient" => "B-Complex + Vitamin C",
        "primary_strength" => "Standard",
        "dosage_form" => "capsule",
        "category" => "Vitamin Supplement",
        "manufacturer" => "Pfizer Ltd",
        "packaging" => "strip of 20 capsules",
        "price_inr" => "₹48.00",
        "timing" => "After Lunch",
        "info" => "Essential B-vitamin complex supporting nerve health, cellular energy, and vitality.",
        "icon" => "💊"
    ]
];

if (!file_exists($dbPath)) {
    // If SQLite file not yet generated, return filtered fallback list
    if ($rawQuery === "") {
        $filtered = $fallbackList;
    } else {
        $filtered = array_values(array_filter($fallbackList, function($item) use ($rawQuery) {
            return stripos($item["name"], $rawQuery) !== false ||
                   stripos($item["primary_ingredient"], $rawQuery) !== false ||
                   stripos($item["category"], $rawQuery) !== false;
        }));
    }

    respond(true, "Medication suggestions retrieved successfully.", 200, [
        "search_query" => $rawQuery,
        "count" => count($filtered),
        "source" => "catalog",
        "medications" => $filtered
    ]);
}

try {
    $sqlite = new PDO("sqlite:" . $dbPath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $results = [];

    if ($rawQuery === "") {
        // Return top curated antidiabetic & wellness medicines from dataset
        $curatedIds = [94187, 75, 121, 27, 448, 785, 1170, 4, 30, 24]; // Known diverse popular products
        $idList = implode(',', $curatedIds);
        $stmt = $sqlite->query("
            SELECT product_id, brand_name, primary_ingredient, primary_strength,
                   dosage_form, therapeutic_class, manufacturer, packaging_raw, price_inr
            FROM medicines
            WHERE product_id IN ($idList) OR LOWER(therapeutic_class) = 'antidiabetic'
            LIMIT 20
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Clean query for FTS5 (keep alphanumeric and spaces)
        $cleanTerm = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $rawQuery));

        if (strlen($cleanTerm) >= 2) {
            $ftsTerm = $cleanTerm . '*';
            $stmt = $sqlite->prepare("
                SELECT m.product_id, m.brand_name, m.primary_ingredient, m.primary_strength, 
                       m.dosage_form, m.therapeutic_class, m.manufacturer, m.packaging_raw, m.price_inr
                FROM medicines_fts f
                JOIN medicines m ON m.product_id = f.rowid
                WHERE medicines_fts MATCH :term
                ORDER BY rank
                LIMIT 30
            ");
            $stmt->execute([":term" => $ftsTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fallback to LIKE if FTS returned empty
        if (empty($results)) {
            $likeTerm = '%' . $rawQuery . '%';
            $stmt = $sqlite->prepare("
                SELECT product_id, brand_name, primary_ingredient, primary_strength, 
                       dosage_form, therapeutic_class, manufacturer, packaging_raw, price_inr
                FROM medicines
                WHERE brand_name LIKE :like1 OR primary_ingredient LIKE :like2
                ORDER BY 
                    CASE WHEN LOWER(therapeutic_class) = 'antidiabetic' THEN 0 ELSE 1 END,
                    LENGTH(brand_name) ASC
                LIMIT 30
            ");
            $stmt->execute([":like1" => $likeTerm, ":like2" => $likeTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $formatted = [];
    foreach ($results as $row) {
        $dosageForm = strtolower($row["dosage_form"] ?? "tablet");
        $primaryStrength = trim($row["primary_strength"] ?? "");
        $primaryIngredient = trim($row["primary_ingredient"] ?? "");
        $therapeuticClass = trim($row["therapeutic_class"] ?? "other");
        $packaging = trim($row["packaging_raw"] ?? "");
        $price = (float) ($row["price_inr"] ?? 0.0);

        // Determine icon based on dosage form
        if (strpos($dosageForm, "inject") !== false || strpos($dosageForm, "penfill") !== false || strpos($dosageForm, "cartridge") !== false) {
            $icon = "💉";
        } elseif (strpos($dosageForm, "inhaler") !== false || strpos($dosageForm, "respule") !== false) {
            $icon = "💨";
        } elseif (strpos($dosageForm, "syrup") !== false || strpos($dosageForm, "suspension") !== false || strpos($dosageForm, "solution") !== false || strpos($dosageForm, "drop") !== false) {
            $icon = "💧";
        } elseif (strpos($dosageForm, "cream") !== false || strpos($dosageForm, "gel") !== false || strpos($dosageForm, "ointment") !== false) {
            $icon = "🧴";
        } else {
            $icon = "💊";
        }

        // Formatted dose label
        $doseLabel = $primaryStrength !== "" ? ($primaryStrength . " • " . ucfirst($dosageForm)) : ucfirst($dosageForm);

        // Determine recommended schedule timing based on category & ingredient
        $lowerClass = strtolower($therapeuticClass);
        $lowerIng = strtolower($primaryIngredient);

        if (strpos($lowerIng, "metformin") !== false) {
            $timing = "With Meal";
        } elseif (strpos($lowerIng, "glimepiride") !== false || strpos($lowerIng, "glipizide") !== false || strpos($lowerIng, "gliclazide") !== false) {
            $timing = "Before Breakfast";
        } elseif (strpos($lowerIng, "insulin") !== false) {
            $timing = "Before Meal";
        } elseif (strpos($lowerClass, "antidiabetic") !== false) {
            $timing = "With Food";
        } elseif (strpos($lowerClass, "statin") !== false || strpos($lowerIng, "atorvastatin") !== false || strpos($lowerIng, "rosuvastatin") !== false) {
            $timing = "At Bedtime";
        } elseif (strpos($lowerClass, "antacid") !== false) {
            $timing = "Before Meal";
        } elseif (strpos($lowerClass, "vitamin") !== false || strpos($lowerClass, "supplement") !== false) {
            $timing = "After Lunch";
        } else {
            $timing = "After Meal";
        }

        // Format therapeutic class nicely
        $categoryDisplay = ($lowerClass === "other" || $lowerClass === "") 
            ? "General Medicine" 
            : ucwords(str_replace(['_', '-'], ' ', $therapeuticClass));

        // Format info description
        $infoParts = [];
        if ($primaryIngredient !== "") {
            $infoParts[] = "Active: {$primaryIngredient}" . ($primaryStrength !== "" ? " ({$primaryStrength})" : "");
        }
        if ($categoryDisplay !== "") {
            $infoParts[] = "Class: {$categoryDisplay}";
        }
        if (!empty($row["manufacturer"])) {
            $infoParts[] = "Mfd: {$row["manufacturer"]}";
        }
        $infoText = implode(" • ", $infoParts);

        $formatted[] = [
            "product_id" => (int) $row["product_id"],
            "name" => $row["brand_name"],
            "dose" => $doseLabel,
            "primary_ingredient" => $primaryIngredient,
            "primary_strength" => $primaryStrength,
            "dosage_form" => ucfirst($dosageForm),
            "category" => $categoryDisplay,
            "manufacturer" => $row["manufacturer"] ?? "",
            "packaging" => $packaging,
            "price_inr" => $price > 0 ? ("₹" . number_format($price, 2)) : "",
            "timing" => $timing,
            "info" => $infoText,
            "icon" => $icon
        ];
    }

    respond(true, "Medication suggestions retrieved successfully.", 200, [
        "search_query" => $rawQuery,
        "count" => count($formatted),
        "source" => "dataset",
        "medications" => $formatted
    ]);

} catch (Exception $e) {
    error_log("Search medications error: " . $e->getMessage());
    respond(false, "Error searching medications database.", 500);
}
