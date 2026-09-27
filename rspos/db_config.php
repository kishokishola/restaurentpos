<?php
// Suppress warnings from appearing in JSON responses
error_reporting(E_ERROR);
@ini_set('display_errors', '0');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'restaurant_pos');

// Connect without selecting a database first
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die(json_encode(['error' => 'MySQL connection failed: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');

// Create database if it doesn't exist
$conn->query("CREATE DATABASE IF NOT EXISTS `restaurant_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db('restaurant_pos');

// Run schema setup only once (check if tables exist)
$tablesExist = $conn->query("SHOW TABLES LIKE 'users'")->num_rows > 0;

if (!$tablesExist) {
    $sql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin','cashier','kitchen') NOT NULL DEFAULT 'cashier',
        email VARCHAR(100),
        phone VARCHAR(20),
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS menu_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT,
        name VARCHAR(150) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image VARCHAR(255),
        status ENUM('available','unavailable') DEFAULT 'available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS restaurant_tables (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number VARCHAR(20) NOT NULL UNIQUE,
        capacity INT NOT NULL DEFAULT 4,
        status ENUM('available','occupied','reserved') DEFAULT 'available',
        location VARCHAR(100),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        email VARCHAR(100),
        address TEXT,
        total_orders INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS orders (
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
    );

    CREATE TABLE IF NOT EXISTS order_items (
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
    );

    CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        payment_method ENUM('cash','card','online') DEFAULT 'cash',
        amount_paid DECIMAL(10,2) NOT NULL,
        change_amount DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('paid','pending','refunded') DEFAULT 'paid',
        paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(150) NOT NULL,
        unit VARCHAR(50),
        quantity DECIMAL(10,3) DEFAULT 0,
        min_quantity DECIMAL(10,3) DEFAULT 5,
        cost_per_unit DECIMAL(10,2) DEFAULT 0.00,
        supplier VARCHAR(150),
        status ENUM('in_stock','low_stock','out_of_stock') DEFAULT 'in_stock',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_id INT NOT NULL,
        type ENUM('in','out') NOT NULL,
        quantity DECIMAL(10,3) NOT NULL,
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    );

    ";

    // Execute each statement one by one
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') $conn->query($stmt);
    }

    // Seed sample data
    $seedSql = [
        // Users — password is 'password'
        "INSERT IGNORE INTO users (full_name,username,password,role) VALUES
            ('Admin User','admin','\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin'),
            ('John Cashier','cashier1','\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','cashier'),
            ('Mike Kitchen','kitchen1','\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','kitchen')",

        "INSERT IGNORE INTO categories (name,description) VALUES
            ('Starters','Appetizers and starters'),
            ('Main Course','Main dishes and entrees'),
            ('Beverages','Hot and cold drinks'),
            ('Desserts','Sweet treats and desserts'),
            ('Fast Food','Quick service items')",

        "INSERT IGNORE INTO menu_items (category_id,name,description,price) VALUES
            (1,'Spring Rolls','Crispy vegetable spring rolls',5.50),
            (1,'Soup of the Day','Fresh daily soup',4.00),
            (1,'Garlic Bread','Toasted bread with garlic butter',3.50),
            (2,'Grilled Chicken','Tender grilled chicken with herbs',12.00),
            (2,'Beef Steak','Prime cut beef steak',18.00),
            (2,'Pasta Carbonara','Creamy pasta with bacon',10.50),
            (2,'Fish & Chips','Crispy battered fish with fries',11.00),
            (3,'Coca Cola','Chilled soft drink',2.00),
            (3,'Fresh Orange Juice','Freshly squeezed orange juice',3.50),
            (3,'Coffee','Hot brewed coffee',2.50),
            (3,'Mineral Water','Chilled mineral water',1.50),
            (4,'Chocolate Cake','Rich chocolate layer cake',5.00),
            (4,'Ice Cream','Vanilla or chocolate scoop',3.00),
            (5,'Burger & Fries','Beef burger with fries',9.00),
            (5,'Pizza Margherita','Classic tomato and cheese pizza',11.50)",

        "INSERT IGNORE INTO restaurant_tables (table_number,capacity,location) VALUES
            ('T01',2,'Window'),('T02',4,'Main Hall'),('T03',4,'Main Hall'),
            ('T04',6,'Main Hall'),('T05',2,'Outdoor'),('T06',4,'Outdoor'),
            ('T07',8,'Private Room'),('T08',4,'Bar Area')",

        "INSERT IGNORE INTO inventory (item_name,unit,quantity,min_quantity,cost_per_unit,supplier) VALUES
            ('Chicken Breast','kg',15.0,5.0,8.00,'Fresh Farms'),
            ('Beef','kg',8.0,3.0,15.00,'Butcher Co'),
            ('All-purpose Flour','kg',25.0,10.0,1.50,'Grain Store'),
            ('Cooking Oil','litre',10.0,3.0,2.50,'Oil Depot'),
            ('Tomatoes','kg',4.0,5.0,1.20,'Fresh Farms'),
            ('Onions','kg',7.0,4.0,0.80,'Fresh Farms'),
            ('Rice','kg',30.0,10.0,1.00,'Grain Store'),
            ('Pasta','kg',12.0,5.0,1.80,'Grain Store'),
            ('Cheese','kg',2.0,3.0,12.00,'Dairy Direct'),
            ('Coca Cola','can',48,20,0.60,'Beverage Co')",

        "INSERT IGNORE INTO customers (name,phone,email) VALUES
            ('Walk-in Customer','0000000000',''),
            ('Sarah Johnson','0123456789','sarah@email.com'),
            ('Ahmad Rahman','0187654321','ahmad@email.com')",
    ];

    foreach ($seedSql as $s) {
        $conn->query($s);
    }
}

$menuColumns = [];
$menuColumnResult = $conn->query("SHOW COLUMNS FROM menu_items");
if ($menuColumnResult) {
    while ($menuColumn = $menuColumnResult->fetch_assoc()) $menuColumns[] = $menuColumn['Field'];
}
if (!in_array('stock', $menuColumns, true)) {
    $conn->query("ALTER TABLE menu_items ADD COLUMN stock INT NOT NULL DEFAULT -1");
}

// Keep existing databases on the standard kitchen order workflow.
$orderStatusColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'");
if ($orderStatusColumn && ($orderStatus = $orderStatusColumn->fetch_assoc())) {
    if (stripos($orderStatus['Type'], "'awaiting_payment'") === false) {
        // Already uses the standard order statuses.
    } else {
        $conn->query("UPDATE orders SET status='pending' WHERE status='awaiting_payment'");
        $conn->query("ALTER TABLE orders MODIFY status ENUM('pending','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending'");
    }
}

// Existing installations may have the users table without the seeded admin.
$adminPassword = password_hash('password', PASSWORD_BCRYPT);
$adminStmt = $conn->prepare("INSERT IGNORE INTO users (full_name, username, password, role, status) VALUES ('Admin User', 'admin', ?, 'admin', 'active')");
if ($adminStmt) {
    $adminStmt->bind_param('s', $adminPassword);
    $adminStmt->execute();
    $adminStmt->close();
}
?>
