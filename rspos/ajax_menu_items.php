<?php
/**
 * ajax_menu_items.php
 * Returns ALL current menu items + categories from DB.
 * Called every 30s by customer_order.php for live updates.
 */
error_reporting(0);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
require_once __DIR__ . '/db_config.php';

// Auto-fix items with empty/null status (caused by bind_param type mismatch)
@$conn->query("UPDATE menu_items SET status='available' WHERE status='' OR status IS NULL");

// Check which extra columns exist
$existingCols = [];
$colRes = $conn->query("SHOW COLUMNS FROM menu_items");
while($col = $colRes->fetch_assoc()) $existingCols[] = $col['Field'];

$hasTimes  = in_array('available_times', $existingCols);
$hasRange  = in_array('time_start', $existingCols);
$hasSort   = in_array('sort_order', $existingCols);
$hasStock  = in_array('stock', $existingCols);

// Build SELECT
$sel = "m.id,m.name,m.description,m.price,m.category_id,m.image,m.status,c.name AS cat_name";
if($hasTimes) $sel .= ",COALESCE(m.available_times,'all') as available_times";
if($hasRange) $sel .= ",m.time_start,m.time_end";
if($hasStock) $sel .= ",COALESCE(m.stock,-1) as stock";
$order = $hasSort ? "COALESCE(m.sort_order,0) ASC, m.name" : "m.name";

$q = $conn->query("
    SELECT $sel
    FROM menu_items m
    LEFT JOIN categories c ON m.category_id=c.id
    WHERE m.status IN ('available','unavailable')
    ORDER BY $order
");

$items = [];
$uploadDir = __DIR__ . '/assets/uploads/';
$uploadUrl = '/RestaurantPOS/assets/uploads/';

while($m = $q->fetch_assoc()){
    $img = '';
    if(!empty($m['image']) && file_exists($uploadDir . $m['image'])){
        $img = $uploadUrl . $m['image'];
    }
    $items[] = [
        'id'        => (int)$m['id'],
        'name'      => $m['name'],
        'desc'      => $m['description'] ?? '',
        'price'     => (float)$m['price'],
        'catId'     => (int)$m['category_id'],
        'catName'   => $m['cat_name'] ?? '',
        'img'       => $img,
        'status'    => $m['status'] ?? 'available',
        'times'     => $hasTimes ? ($m['available_times'] ?? 'all') : 'all',
        'timeStart' => $hasRange ? ($m['time_start'] ?? '') : '',
        'timeEnd'   => $hasRange ? ($m['time_end'] ?? '') : '',
        'stock'     => $hasStock ? (int)($m['stock'] ?? -1) : -1,
    ];
}

// Categories
$cats = [];
$cq = $conn->query("SELECT id,name FROM categories WHERE status='active' ORDER BY name");
while($c = $cq->fetch_assoc()){
    $cats[] = ['id' => (int)$c['id'], 'name' => $c['name']];
}

echo json_encode(['items' => $items, 'cats' => $cats, 'ts' => time()]);
?>
