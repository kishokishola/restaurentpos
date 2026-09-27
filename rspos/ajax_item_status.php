<?php
/**
 * ajax_item_status.php — Returns current status + stock of all menu items.
 */
error_reporting(0); ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_config.php';

$hasStock = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock'")->num_rows > 0;
$sql = $hasStock
    ? "SELECT id, status, stock FROM menu_items"
    : "SELECT id, status FROM menu_items";
$rows = $conn->query($sql);
$out  = [];
while ($r = $rows->fetch_assoc()) {
    $out[(int)$r['id']] = [
        'status' => $r['status'],
        'stock'  => $hasStock ? (int)$r['stock'] : -1,
    ];
}
echo json_encode($out);
?>
