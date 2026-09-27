<?php
/**
 * setup_meal_items.php
 * Run once: http://localhost/RestaurantPOS/setup_meal_items.php
 * Adds meal-time categories + food items into the DB.
 */
require_once __DIR__ . '/db_config.php';

$log = [];

// ── 1. Insert meal-time categories ─────────────────────────
$mealCategories = [
    ['Morning Breakfast', 'Breakfast items for the morning'],
    ['Lunch',             'Lunch meals and rice dishes'],
    ['Dinner',            'Dinner meals and evening dishes'],
    ['Snacks',            'Light snacks and finger food'],
    ['Juicy Drinks',      'Fresh juices and cold drinks'],
];

$catIds = [];
foreach ($mealCategories as [$name, $desc]) {
    $eName = $conn->real_escape_string($name);
    $eDesc = $conn->real_escape_string($desc);
    // Check if exists
    $row = $conn->query("SELECT id FROM categories WHERE name='$eName' LIMIT 1")->fetch_assoc();
    if ($row) {
        $catIds[$name] = (int)$row['id'];
        $log[] = "⚠️  Category already exists: $name (id={$row['id']})";
    } else {
        $conn->query("INSERT INTO categories (name, description, status) VALUES ('$eName','$eDesc','active')");
        $catIds[$name] = (int)$conn->insert_id;
        $log[] = "✅ Created category: $name (id={$catIds[$name]})";
    }
}

// ── 2. Food items per category ──────────────────────────────
$foodItems = [
    'Morning Breakfast' => [
        ['Puttu',           'Steamed rice flour with coconut',        4.50],
        ['Idly',            'Soft steamed rice cakes (2 pcs)',         3.50],
        ['Idiyappam',       'String hoppers with coconut milk',        4.00],
        ['Roti Canai',      'Crispy flatbread with curry dip',         2.50],
        ['Mee Goreng',      'Fried noodles with egg & vegetables',     6.00],
        ['Poori',           'Fried puffed bread with curry (2 pcs)',   5.00],
        ['Nasi Lemak',      'Coconut rice with sambal & egg',          6.50],
        ['Tosai',           'Crispy rice crepe with chutney',          3.50],
        ['Chapati',         'Whole wheat flatbread with dhal',         3.00],
        ['Upma',            'Semolina porridge with vegetables',       4.00],
    ],
    'Lunch' => [
        ['Rice + Lauk',         'White rice with choice of side dishes',  7.00],
        ['Chicken Briyani',     'Fragrant basmati rice with spiced chicken', 10.00],
        ['Mutton Briyani',      'Basmati rice with slow-cooked mutton',  12.00],
        ['Nasi Kukus',          'Steamed rice with crispy chicken',       8.50],
        ['Mee Rebus',           'Yellow noodles in thick gravy',          7.00],
        ['Nasi Goreng Kampung', 'Village-style fried rice',               7.50],
        ['Fried Chicken Rice',  'Crispy fried chicken with rice',         8.00],
        ['Mixed Meat Rice',     'Rice with grilled beef & chicken',       9.50],
        ['Vegetable Curry Rice','Rice with mixed vegetable curry',        6.50],
        ['Mee Hoon Sup',        'Rice vermicelli in clear soup',          6.00],
    ],
    'Dinner' => [
        ['Dinner Rice Set',     'Rice with 2 sides + soup',               8.00],
        ['Chicken Briyani',     'Evening fragrant briyani with raita',    11.00],
        ['Mutton Briyani',      'Tender mutton briyani (dinner portion)', 13.00],
        ['Kottu Roti',          'Shredded roti stir-fried with egg & veg',8.50],
        ['Nasi Goreng Special', 'Special fried rice with seafood',         9.00],
        ['Nasi Kukus Ayam',     'Steamed coconut rice with fried chicken', 9.00],
        ['Grilled Fish Rice',   'Rice with grilled spiced fish',          10.00],
        ['Lamb Chop Set',       'Grilled lamb chop with mash & salad',   18.00],
        ['Butter Chicken Set',  'Creamy butter chicken with naan',        12.00],
        ['Seafood Fried Rice',  'Wok-fried rice with prawns & squid',     11.00],
    ],
    'Snacks' => [
        ['Samosa (2 pcs)',      'Crispy pastry filled with spiced potato', 3.00],
        ['Spring Rolls (3 pcs)','Crispy fried vegetable rolls',            4.00],
        ['Murukku',             'Crunchy rice flour snack',                2.50],
        ['Vadai (2 pcs)',       'Crispy lentil donuts with chutney',       3.50],
        ['Banana Fritters',     'Sweet fried banana (3 pcs)',              3.00],
        ['Nuggets (6 pcs)',     'Golden chicken nuggets with sauce',       5.00],
        ['French Fries',        'Crispy golden fries with ketchup',        4.50],
        ['Onion Rings',         'Battered fried onion rings',              4.00],
        ['Garlic Bread',        'Toasted bread with garlic butter',        3.50],
        ['Cheese Toast',        'Toasted bread with melted cheese',        4.00],
    ],
    'Juicy Drinks' => [
        ['Fresh Orange Juice',  'Freshly squeezed orange',                 4.50],
        ['Watermelon Juice',    'Cold blended watermelon',                 4.00],
        ['Mango Juice',         'Sweet fresh mango blend',                 4.50],
        ['Lemon Juice',         'Fresh lemon with sugar & water',          3.50],
        ['Sugarcane Juice',     'Fresh pressed sugarcane',                 3.00],
        ['Coconut Water',       'Natural fresh coconut water',             4.00],
        ['Apple Juice',         'Cold pressed apple juice',                4.50],
        ['Pineapple Juice',     'Sweet tangy pineapple blend',             4.00],
        ['Mixed Fruit Juice',   'Seasonal fruit blend',                    5.00],
        ['Avocado Shake',       'Creamy blended avocado with milk',        6.00],
    ],
];

foreach ($foodItems as $catName => $items) {
    $catId = $catIds[$catName] ?? null;
    if (!$catId) { $log[] = "❌ Category not found: $catName"; continue; }

    foreach ($items as [$iname, $idesc, $iprice]) {
        $eName  = $conn->real_escape_string($iname);
        $eDesc  = $conn->real_escape_string($idesc);
        $ePrice = (float)$iprice;
        // Check exists in this category
        $ex = $conn->query("SELECT id FROM menu_items WHERE name='$eName' AND category_id=$catId LIMIT 1")->fetch_assoc();
        if ($ex) {
            $log[] = "⚠️  Item already exists: $iname under $catName";
        } else {
            $conn->query("INSERT INTO menu_items (category_id, name, description, price, status)
                          VALUES ($catId, '$eName', '$eDesc', $ePrice, 'available')");
            $log[] = "✅ Added item: $iname → $catName (RM $ePrice)";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Meal Items Setup</title>
  <style>
    body { font-family: monospace; background: #0f0f1a; color: #f0f0f0; padding: 30px; }
    h2   { color: #e8a838; margin-bottom: 20px; }
    .log { line-height: 2; font-size: 0.9rem; }
    .done { margin-top: 30px; padding: 18px 24px; background: rgba(40,167,69,0.15);
            border-left: 4px solid #28a745; border-radius: 8px; font-size: 1rem; }
    a { color: #e8a838; }
  </style>
</head>
<body>
  <h2>🍽️ Meal Items Setup — RestoPOS</h2>
  <div class="log">
    <?php foreach ($log as $line): ?>
      <div><?= htmlspecialchars($line) ?></div>
    <?php endforeach; ?>
  </div>
  <div class="done">
    ✅ Setup complete! Total items processed: <?= count($log) ?><br><br>
    👉 <a href="/RestaurantPOS/customer_order.php">Go to Customer Order Page</a> &nbsp;|&nbsp;
    <a href="/RestaurantPOS/orders.php">Go to Admin Orders</a>
  </div>
</body>
</html>
