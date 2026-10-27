<?php
require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed. Use GET.", 405);
}

$query = strtolower(trim($_GET["query"] ?? $_GET["q"] ?? ""));

// Curated Diabetic & Common Medications Catalog
$catalog = [
    [
        "name" => "Metformin",
        "dose" => "500 mg • 1 Tablet",
        "category" => "Biguanide",
        "timing" => "With Meal",
        "info" => "Lowers hepatic glucose production and improves insulin sensitivity."
    ],
    [
        "name" => "Metformin XR",
        "dose" => "1000 mg • Extended Release",
        "category" => "Biguanide",
        "timing" => "With Dinner",
        "info" => "Sustained-release formulation to minimize gastrointestinal discomfort."
    ],
    [
        "name" => "Glimepiride",
        "dose" => "2 mg • 1 Tablet",
        "category" => "Sulfonylurea",
        "timing" => "Before Breakfast",
        "info" => "Stimulates the beta cells of the pancreas to produce more insulin."
    ],
    [
        "name" => "Januvia (Sitagliptin)",
        "dose" => "100 mg • 1 Tablet",
        "category" => "DPP-4 Inhibitor",
        "timing" => "Once Daily",
        "info" => "Increases incretin hormones to regulate insulin secretion."
    ],
    [
        "name" => "Jardiance (Empagliflozin)",
        "dose" => "10 mg • 1 Tablet",
        "category" => "SGLT2 Inhibitor",
        "timing" => "In the Morning",
        "info" => "Helps the kidneys remove excess glucose through urine."
    ],
    [
        "name" => "Insulin Glargine (Lantus)",
        "dose" => "10–20 Units • Subcutaneous",
        "category" => "Long-acting Insulin",
        "timing" => "At Bedtime",
        "info" => "Provides 24-hour background basal insulin coverage."
    ],
    [
        "name" => "Multivitamin & Minerals",
        "dose" => "1 Capsule",
        "category" => "Dietary Supplement",
        "timing" => "After Lunch",
        "info" => "Antioxidant and micronutrient support for overall vitality."
    ],
    [
        "name" => "Atorvastatin",
        "dose" => "20 mg • 1 Tablet",
        "category" => "Statin",
        "timing" => "At Bedtime",
        "info" => "Cardiovascular protective agent optimizing lipid profiles in diabetics."
    ]
];

if ($query === "") {
    $filtered = $catalog;
} else {
    $filtered = array_values(array_filter($catalog, function($item) use ($query) {
        return strpos(strtolower($item["name"]), $query) !== false ||
               strpos(strtolower($item["category"]), $query) !== false;
    }));
}

respond(true, "Medication suggestions retrieved successfully.", 200, [
    "search_query" => $query,
    "count" => count($filtered),
    "medications" => $filtered
]);

