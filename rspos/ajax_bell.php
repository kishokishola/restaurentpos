<?php
/**
 * ajax_bell.php
 * Returns kitchen-only new-order notifications.
 * Called every 15s by navbar bell poller.
 */
error_reporting(0);
ini_set('display_errors','0');
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['pending' => 0]);
    exit;
}

require_once __DIR__ . '/db_config.php';

if (!in_array($_SESSION['user_role'] ?? '', ['kitchen', 'admin'], true)) {
    ob_end_clean();
    echo json_encode(['pending' => 0]);
    exit;
}

$res = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status = 'pending'");
$cnt = 0;
if ($res) $cnt = (int)$res->fetch_assoc()['cnt'];

ob_end_clean();
echo json_encode(['pending' => $cnt]);
?>
