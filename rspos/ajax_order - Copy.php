<?php
/**
 * ajax_order.php
 * Pure JSON AJAX endpoint — no HTML, no navbar, no session warnings.
 */
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

session_start();

// ── Send JSON and exit ────────────────────────────────────
function sendJSON(array $data): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ── Auth ──────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    sendJSON(['success' => false, 'message' => 'Session expired. Please login again.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Invalid request.']);
}

require_once __DIR__ . '/db_config.php';

$userId = (int) $_SESSION['user_id'];
$action = trim($_POST['action'] ?? '');

// ════════════════════════════════════════════════════════
//  PLACE ORDER
// ════════════════════════════════════════════════════════
if ($action === 'place_order') {

    // ── Inputs ────────────────────────────────────────────
    $rawTable    = (int) ($_POST['table_id']    ?? 0);
    $rawCustomer = (int) ($_POST['customer_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $items       = json_decode($_POST['items'] ?? '[]', true);

    // 0 → NULL so FK constraints pass
    $tableId    = $rawTable    > 0 ? $rawTable    : null;
    $customerId = $rawCustomer > 0 ? $rawCustomer : null;

    if (empty($items) || !is_array($items)) {
        sendJSON(['success' => false, 'message' => 'No items selected.']);
    }

    // ── Totals ────────────────────────────────────────────
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += round((float)($it['price'] ?? 0), 2) * max(1, (int)($it['qty'] ?? 1));
    }
    $subtotal = round($subtotal, 2);
    $tax      = round($subtotal * 0.06, 2);
    $total    = round($subtotal + $tax, 2);
    $status   = 'pending';

    // ── Unique order number ───────────────────────────────
    $orderNum = '';
    for ($i = 0; $i < 10; $i++) {
        $rand = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
        $candidate = 'ORD-' . date('Ymd-His') . '-' . $rand;
        $esc = $conn->real_escape_string($candidate);
        $chk = $conn->query("SELECT id FROM orders WHERE order_number='$esc' LIMIT 1");
        if ($chk && $chk->num_rows === 0) {
            $orderNum = $candidate;
            break;
        }
    }
    if ($orderNum === '') {
        sendJSON(['success' => false, 'message' => 'Could not generate order number. Try again.']);
    }

    // ── Insert order ──────────────────────────────────────
    // Columns: order_number(s), table_id(i), customer_id(i), cashier_id(i),
    //          status(s), subtotal(d), tax(d), total(d), notes(s)
    // Type string: s i i i s d d d s  = 9 params
    $stmt = $conn->prepare(
        "INSERT INTO orders
            (order_number, table_id, customer_id, cashier_id, status, subtotal, tax, total, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
    }

    // bind_param with nullable ints — PHP mysqli accepts null for 'i'
    $stmt->bind_param(
        'siiisddds',
        $orderNum,   // s
        $tableId,    // i  (null ok)
        $customerId, // i  (null ok)
        $userId,     // i
        $status,     // s
        $subtotal,   // d
        $tax,        // d
        $total,      // d
        $notes       // s
    );

    if (!$stmt->execute()) {
        $e = $stmt->error;
        $stmt->close();
        sendJSON(['success' => false, 'message' => 'Order save failed: ' . $e]);
    }

    $orderId = (int) $conn->insert_id;
    $stmt->close();

    // ── Insert order items ────────────────────────────────
    // Columns: order_id(i), menu_item_id(i), item_name(s),
    //          quantity(i), unit_price(d), total_price(d)
    // Type string: i i s i d d  = 6 params
    $istmt = $conn->prepare(
        "INSERT INTO order_items
            (order_id, menu_item_id, item_name, quantity, unit_price, total_price)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if ($istmt) {
        foreach ($items as $it) {
            $menuId = (int)($it['id'] ?? 0);
            $menuId = $menuId > 0 ? $menuId : null;
            $iname  = substr(trim((string)($it['name'] ?? 'Item')), 0, 150);
            $qty    = max(1, (int)($it['qty'] ?? 1));
            $uprice = round((float)($it['price'] ?? 0), 2);
            $tprice = round($qty * $uprice, 2);

            $istmt->bind_param('iisidi',
                $orderId, $menuId, $iname, $qty, $uprice, $tprice
            );
            $istmt->execute();
        }
        $istmt->close();
    }

    // ── Side effects ──────────────────────────────────────
    if ($tableId !== null) {
        $conn->query("UPDATE restaurant_tables SET status='occupied' WHERE id=" . (int)$tableId);
    }
    if ($customerId !== null) {
        $conn->query("UPDATE customers SET total_orders = total_orders + 1 WHERE id=" . (int)$customerId);
    }

    // ── Return success ────────────────────────────────────
    sendJSON([
        'success'      => true,
        'order_id'     => $orderId,
        'order_number' => $orderNum,
        'subtotal'     => $subtotal,
        'tax'          => $tax,
        'total'        => $total
    ]);
}

// ── Unknown action ────────────────────────────────────────
sendJSON(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
?>
