<?php
/**
 * restore_all.php — Full DB restore after reset
 * Run: http://localhost/RestaurantPOS/restore_all.php
 * DELETE after running!
 */
error_reporting(E_ALL);
ini_set('display_errors','1');

$host = 'localhost';
$user = 'root';
$pass = '';

// Connect without DB first
$conn = new mysqli($host, $user, $pass);
if($conn->connect_error) die("MySQL connect failed: " . $conn->connect_error);

$log = [];
$err = [];

function run($conn, $sql, $desc=''){
    global $log, $err;
    if($conn->query($sql)){
        if($desc) $log[] = "✅ $desc";
    } else {
        $err[] = "❌ $desc: " . $conn->error . " | SQL: " . substr($sql,0,80);
    }
}

// ── 1. Create DB ──────────────────────────────────────────
run($conn, "CREATE DATABASE IF NOT EXISTS `restaurant_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "Database created");
run($conn, "USE `restaurant_pos`", "Using restaurant_pos");

// ── 2. Create all tables ──────────────────────────────────
$tables = [
"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','cashier','kitchen') NOT NULL DEFAULT 'cashier',
    email VARCHAR(100),
    phone VARCHAR(20),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    image VARCHAR(255),
    available_times VARCHAR(255) DEFAULT 'all',
    time_start TIME DEFAULT NULL,
    time_end TIME DEFAULT NULL,
    sort_order INT DEFAULT 0,
    stock INT DEFAULT -1,
    status ENUM('available','unavailable') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS restaurant_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(20) NOT NULL UNIQUE,
    capacity INT NOT NULL DEFAULT 4,
    status ENUM('available','occupied','reserved') DEFAULT 'available',
    location VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    total_orders INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    table_id INT,
    customer_id INT,
    cashier_id INT,
    status ENUM('pending','preparing','ready','completed','cancelled') DEFAULT 'pending',
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    discount DECIMAL(10,2) DEFAULT 0.00,
    tax DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT,
    item_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    notes TEXT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method ENUM('cash','card','online') DEFAULT 'cash',
    amount_paid DECIMAL(10,2) NOT NULL,
    change_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('paid','pending','refunded') DEFAULT 'paid',
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(150) NOT NULL,
    unit VARCHAR(50),
    quantity DECIMAL(10,2) DEFAULT 0,
    min_quantity DECIMAL(10,2) DEFAULT 5,
    cost_per_unit DECIMAL(10,2) DEFAULT 0.00,
    supplier VARCHAR(150),
    status ENUM('in_stock','low_stock','out_of_stock') DEFAULT 'in_stock',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    type ENUM('in','out') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    category ENUM('ingredients','staff','utilities','rent','maintenance','marketing','other') DEFAULT 'other',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB",
];

foreach($tables as $sql) run($conn, $sql, "Table created");

// ── 3. Seed Users ─────────────────────────────────────────
$hash = password_hash('admin123', PASSWORD_BCRYPT);
$h2   = password_hash('password', PASSWORD_BCRYPT);
$seeds = [
    "INSERT IGNORE INTO users (full_name,username,password,role,status) VALUES ('Administrator','admin','$hash','admin','active')",
    "INSERT IGNORE INTO users (full_name,username,password,role,status) VALUES ('John Cashier','cashier1','$h2','cashier','active')",
    "INSERT IGNORE INTO users (full_name,username,password,role,status) VALUES ('Mike Kitchen','kitchen1','$h2','kitchen','active')",
];
foreach($seeds as $s) run($conn, $s, "User seeded");

// ── 4. Seed Categories ────────────────────────────────────
$cats = [
    ['Starters','Appetizers and starters'],
    ['Main Course','Main dishes and entrees'],
    ['Beverages','Hot and cold drinks'],
    ['Desserts','Sweet treats and desserts'],
    ['Fast Food','Quick service items'],
    ['Morning Breakfast','Breakfast items for the morning'],
    ['Lunch','Lunch meals and rice dishes'],
    ['Dinner','Dinner meals and evening dishes'],
    ['Snacks','Light snacks and finger food'],
    ['Juicy Drinks','Fresh juices and cold drinks'],
];
foreach($cats as [$n,$d]){
    $n=$conn->real_escape_string($n); $d=$conn->real_escape_string($d);
    run($conn, "INSERT IGNORE INTO categories (name,description,status) VALUES ('$n','$d','active')", "Category: $n");
}

// ── 5. Seed Menu Items ────────────────────────────────────
$items = [
    // [cat_name, item_name, desc, price]
    ['Starters','Spring Rolls','Crispy vegetable spring rolls',5.50],
    ['Starters','Soup of the Day','Fresh daily soup',4.00],
    ['Starters','Garlic Bread','Toasted bread with garlic butter',3.50],
    ['Main Course','Grilled Chicken','Tender grilled chicken with herbs',12.00],
    ['Main Course','Beef Steak','Prime cut beef steak',18.00],
    ['Main Course','Pasta Carbonara','Creamy pasta with bacon',10.50],
    ['Main Course','Fish & Chips','Crispy battered fish with fries',11.00],
    ['Beverages','Coca Cola','Chilled soft drink',2.00],
    ['Beverages','Fresh Orange Juice','Freshly squeezed orange juice',3.50],
    ['Beverages','Coffee','Hot brewed coffee',2.50],
    ['Beverages','Mineral Water','Chilled mineral water',1.50],
    ['Desserts','Chocolate Cake','Rich chocolate layer cake',5.00],
    ['Desserts','Ice Cream','Vanilla or chocolate scoop',3.00],
    ['Fast Food','Burger & Fries','Beef burger with fries',9.00],
    ['Fast Food','Pizza Margherita','Classic tomato and cheese pizza',11.50],
    // Morning
    ['Morning Breakfast','Puttu','Steamed rice flour with coconut',4.50],
    ['Morning Breakfast','Idly','Soft steamed rice cakes (2 pcs)',3.50],
    ['Morning Breakfast','Idiyappam','String hoppers with coconut milk',4.00],
    ['Morning Breakfast','Roti Canai','Crispy flatbread with curry dip',2.50],
    ['Morning Breakfast','Nasi Lemak','Coconut rice with sambal & egg',6.50],
    ['Morning Breakfast','Tosai','Crispy rice crepe with chutney',3.50],
    ['Morning Breakfast','Mee Goreng','Fried noodles with egg & vegetables',6.00],
    ['Morning Breakfast','Poori','Fried puffed bread with curry (2 pcs)',5.00],
    // Lunch
    ['Lunch','Rice + Lauk','White rice with choice of side dishes',7.00],
    ['Lunch','Chicken Briyani','Fragrant basmati rice with spiced chicken',10.00],
    ['Lunch','Mutton Briyani','Basmati rice with slow-cooked mutton',12.00],
    ['Lunch','Nasi Kukus','Steamed rice with crispy chicken',8.50],
    ['Lunch','Mee Rebus','Yellow noodles in thick gravy',7.00],
    ['Lunch','Nasi Goreng Kampung','Village-style fried rice',7.50],
    ['Lunch','Fried Chicken Rice','Crispy fried chicken with rice',8.00],
    // Dinner
    ['Dinner','Dinner Rice Set','Rice with 2 sides + soup',8.00],
    ['Dinner','Chicken Briyani','Evening fragrant briyani with raita',11.00],
    ['Dinner','Kottu Roti','Shredded roti stir-fried with egg & veg',8.50],
    ['Dinner','Nasi Goreng Special','Special fried rice with seafood',9.00],
    ['Dinner','Grilled Fish Rice','Rice with grilled spiced fish',10.00],
    ['Dinner','Lamb Chop Set','Grilled lamb chop with mash & salad',18.00],
    // Snacks
    ['Snacks','Samosa (2 pcs)','Crispy pastry filled with spiced potato',3.00],
    ['Snacks','Spring Rolls (3 pcs)','Crispy fried vegetable rolls',4.00],
    ['Snacks','French Fries','Crispy golden fries with ketchup',4.50],
    ['Snacks','Nuggets (6 pcs)','Golden chicken nuggets with sauce',5.00],
    ['Snacks','Onion Rings','Battered fried onion rings',4.00],
    ['Snacks','Garlic Bread','Toasted bread with garlic butter',3.50],
    // Juicy
    ['Juicy Drinks','Fresh Orange Juice','Freshly squeezed orange',4.50],
    ['Juicy Drinks','Watermelon Juice','Cold blended watermelon',4.00],
    ['Juicy Drinks','Mango Juice','Sweet fresh mango blend',4.50],
    ['Juicy Drinks','Lemon Juice','Fresh lemon with sugar & water',3.50],
    ['Juicy Drinks','Coconut Water','Natural fresh coconut water',4.00],
    ['Juicy Drinks','Avocado Shake','Creamy blended avocado with milk',6.00],
];

foreach($items as [$cat,$name,$desc,$price]){
    $ec=$conn->real_escape_string($cat); $en=$conn->real_escape_string($name);
    $ed=$conn->real_escape_string($desc);
    $catRow = $conn->query("SELECT id FROM categories WHERE name='$ec' LIMIT 1")->fetch_assoc();
    if(!$catRow){ $err[]="❌ Cat not found: $cat"; continue; }
    $cid = $catRow['id'];
    $ex  = $conn->query("SELECT id FROM menu_items WHERE name='$en' AND category_id=$cid LIMIT 1")->fetch_assoc();
    if(!$ex) run($conn,"INSERT INTO menu_items (category_id,name,description,price,available_times,status) VALUES ($cid,'$en','$ed',$price,'all','available')","Item: $name");
}

// ── 6. Seed Tables ────────────────────────────────────────
$tbls = [['T01',2,'Window'],['T02',4,'Main Hall'],['T03',4,'Main Hall'],
         ['T04',6,'Main Hall'],['T05',2,'Outdoor'],['T06',4,'Outdoor'],
         ['T07',8,'Private Room'],['T08',4,'Bar Area']];
foreach($tbls as [$tn,$cap,$loc]){
    run($conn,"INSERT IGNORE INTO restaurant_tables (table_number,capacity,location,status) VALUES ('$tn',$cap,'$loc','available')","Table: $tn");
}

// ── 7. Seed Customers ─────────────────────────────────────
run($conn,"INSERT IGNORE INTO customers (name,phone) VALUES ('Walk-in Customer','0000000000')",'Customer: Walk-in');
run($conn,"INSERT IGNORE INTO customers (name,phone,email) VALUES ('Sarah Johnson','0123456789','sarah@email.com')",'Customer: Sarah');

// ── 8. Seed Inventory ─────────────────────────────────────
$inv = [['Chicken Breast','kg',15.0,5.0,8.00,'Fresh Farms'],['Beef','kg',8.0,3.0,15.00,'Butcher Co'],
        ['Rice','kg',30.0,10.0,1.00,'Grain Store'],['Cooking Oil','litre',10.0,3.0,2.50,'Oil Depot'],
        ['Coca Cola','can',48,20,0.60,'Beverage Co']];
foreach($inv as [$n,$u,$q,$min,$c,$s]){
    $en=$conn->real_escape_string($n); $eu=$conn->real_escape_string($u); $es=$conn->real_escape_string($s);
    run($conn,"INSERT IGNORE INTO inventory (item_name,unit,quantity,min_quantity,cost_per_unit,supplier) VALUES ('$en','$eu',$q,$min,$c,'$es')","Inventory: $n");
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Restore Complete</title>
<style>
body{font-family:monospace;background:#0f0f1a;color:#f0f0f0;padding:30px;}
h2{color:#e8a838;margin-bottom:20px;}
.ok{color:#6fcf97;line-height:2;}
.er{color:#ff8091;line-height:2;}
.box{background:rgba(40,167,69,.1);border-left:4px solid #28a745;padding:16px 20px;border-radius:8px;margin-top:20px;}
a{color:#e8a838;font-weight:700;}
</style>
</head>
<body>
<h2>🔧 RestaurantPOS — Full Restore</h2>
<div class="ok">
<?php foreach($log as $l) echo "<div>$l</div>"; ?>
</div>
<?php if($err): ?>
<div class="er" style="margin-top:16px;">
<?php foreach($err as $e) echo "<div>$e</div>"; ?>
</div>
<?php endif; ?>
<div class="box">
  <p>✅ <strong>Restore complete!</strong> Total: <?= count($log) ?> items seeded.</p>
  <br>
  <p>🔐 <a href="/RestaurantPOS/admin_login.php">Admin Login</a> — Username: <strong>admin</strong> | Password: <strong>admin123</strong></p>
  <p>👤 <a href="/RestaurantPOS/login.php">Staff Login</a> — Username: <strong>cashier1</strong> | Password: <strong>password</strong></p>
  <p>🍽️ <a href="/RestaurantPOS/customer_order.php">Customer Order Page</a></p>
  <br>
  <p style="color:#ffc107;">⚠️ Delete restore_all.php after use!</p>
</div>
</body>
</html>
