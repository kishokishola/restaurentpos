<?php
/**
 * ajax_customer_order.php
 * Public AJAX endpoint for customer self-ordering (no login required).
 */
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

session_start();

function sendJSON(array $data): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Invalid request.']);
}

require_once __DIR__ . '/db_config.php';

$action = trim($_POST['action'] ?? '');

// ════════════════════════════════════════════════════════
//  PLACE CUSTOMER ORDER
// ════════════════════════════════════════════════════════
if ($action === 'place_order') {

    $tableId    = intval($_POST['table_id']    ?? 0);  $tableId    = $tableId    > 0 ? $tableId    : null;
    $customerName = trim($_POST['customer_name'] ?? 'Walk-in');
    $mealType   = trim($_POST['meal_type']  ?? '');
    $notes      = trim($_POST['notes']      ?? '');
    $items      = json_decode($_POST['items'] ?? '[]', true);

    if (empty($items) || !is_array($items)) {
        sendJSON(['success' => false, 'message' => 'No items selected.']);
    }

    // Keep this endpoint safe even when it is called before customer_order.php
    // has had a chance to add the stock column.
    $stockCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock'");
    if ($stockCol && $stockCol->num_rows === 0) {
        $conn->query("ALTER TABLE menu_items ADD COLUMN stock INT DEFAULT -1");
    }

    // Reserve stock before creating the order. Row locks keep this atomic, so
    // simultaneous customer orders can never oversell an item.
    $conn->begin_transaction();
    $lookupStmt = $conn->prepare(
        "SELECT name, status, COALESCE(stock,-1) AS stock FROM menu_items WHERE id = ? FOR UPDATE"
    );
    $deductStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ?");
    if (!$lookupStmt || !$deductStmt) {
        if ($lookupStmt) $lookupStmt->close();
        if ($deductStmt) $deductStmt->close();
        $conn->rollback();
        sendJSON(['success' => false, 'message' => 'Could not check item stock.']);
    }

    foreach ($items as $it) {
        $menuId = (int)($it['id'] ?? 0);
        $qty    = max(1, (int)($it['qty'] ?? 1));
        if ($menuId <= 0) {
            $lookupStmt->close(); $deductStmt->close();
            $conn->rollback();
            sendJSON(['success' => false, 'message' => 'Invalid menu item.']);
        }

        $lookupStmt->bind_param('i', $menuId);
        $lookupStmt->execute();
        $stock = $lookupStmt->get_result()->fetch_assoc();
        $available = $stock ? (int)$stock['stock'] : 0;
        if (!$stock || $stock['status'] !== 'available' || ($available >= 0 && $available < $qty)) {
            $lookupStmt->close(); $deductStmt->close();
            $conn->rollback();

            $available = max(0, $available);
            $itemName  = $stock['name'] ?? 'This item';
            sendJSON([
                'success' => false,
                'stock_error' => true,
                'item_id' => $menuId,
                'available_stock' => $available,
                'message' => "$itemName: only $available item(s) are available."
            ]);
        }

        // -1 means unlimited, so only managed stock is decremented.
        if ($available >= 0) {
            $deductStmt->bind_param('ii', $qty, $menuId);
            $deductStmt->execute();
        }
    }
    $lookupStmt->close();
    $deductStmt->close();

    // Totals
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += round((float)($it['price'] ?? 0), 2) * max(1, (int)($it['qty'] ?? 1));
    }
    $subtotal = round($subtotal, 2);
    $tax      = round($subtotal * 0.06, 2);
    $total    = round($subtotal + $tax, 2);
    // Customer orders go to the kitchen immediately.
    $status   = 'pending';

    // Unique order number
    $orderNum = '';
    for ($i = 0; $i < 10; $i++) {
        $rand      = strtoupper(bin2hex(random_bytes(4)));
        $candidate = 'CUST-' . date('Ymd-His') . '-' . $rand;
        $esc       = $conn->real_escape_string($candidate);
        $chk       = $conn->query("SELECT id FROM orders WHERE order_number='$esc' LIMIT 1");
        if ($chk && $chk->num_rows === 0) { $orderNum = $candidate; break; }
    }
    if ($orderNum === '') {
        $conn->rollback();
        sendJSON(['success' => false, 'message' => 'Could not generate order number. Try again.']);
    }

    // Add meal type to notes
    $fullNotes = $mealType ? "[{$mealType}]" . ($notes ? " {$notes}" : '') : $notes;

    // Find or use walk-in customer (id=1 or first customer)
    $custId = null;
    $escapedName = $conn->real_escape_string($customerName);
    if ($customerName && $customerName !== 'Walk-in') {
        // Try to match existing or insert new
        $chkCust = $conn->query("SELECT id FROM customers WHERE name='$escapedName' LIMIT 1");
        if ($chkCust && $chkCust->num_rows > 0) {
            $custId = (int)$chkCust->fetch_assoc()['id'];
        } else {
            $conn->query("INSERT INTO customers (name) VALUES ('$escapedName')");
            $custId = (int)$conn->insert_id;
        }
    } else {
        $wk = $conn->query("SELECT id FROM customers WHERE name='Walk-in Customer' LIMIT 1");
        if ($wk && $wk->num_rows > 0) $custId = (int)$wk->fetch_assoc()['id'];
    }

    // Insert order (cashier_id = NULL for customer self-orders)
    $stmt = $conn->prepare(
        "INSERT INTO orders (order_number, table_id, customer_id, cashier_id, status, subtotal, tax, total, notes)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        $conn->rollback();
        sendJSON(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
    }
    $stmt->bind_param('siisddds', $orderNum, $tableId, $custId, $status, $subtotal, $tax, $total, $fullNotes);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        $conn->rollback();
        sendJSON(['success' => false, 'message' => 'Order save failed: ' . $e]);
    }
    $orderId = (int)$conn->insert_id;
    $stmt->close();

    // Insert order items
    $istmt = $conn->prepare(
        "INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, total_price)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($istmt) {
        foreach ($items as $it) {
            $menuId = (int)($it['id'] ?? 0); $menuId = $menuId > 0 ? $menuId : null;
            $iname  = substr(trim((string)($it['name'] ?? 'Item')), 0, 150);
            $qty    = max(1, (int)($it['qty'] ?? 1));
            $uprice = round((float)($it['price'] ?? 0), 2);
            $tprice = round($qty * $uprice, 2);
            $istmt->bind_param('iisidi', $orderId, $menuId, $iname, $qty, $uprice, $tprice);
            $istmt->execute();
        }
        $istmt->close();
    }

    // Mark table occupied
    if ($tableId !== null) {
        $conn->query("UPDATE restaurant_tables SET status='occupied' WHERE id=" . (int)$tableId);
    }
    if ($custId !== null) {
        $conn->query("UPDATE customers SET total_orders = total_orders + 1 WHERE id=" . (int)$custId);
    }

    $conn->commit();

    sendJSON([
        'success'      => true,
        'order_id'     => $orderId,
        'order_number' => $orderNum,
        'subtotal'     => $subtotal,
        'tax'          => $tax,
        'total'        => $total
    ]);
}

sendJSON(['success' => false, 'message' => 'Unknown action.']);
?>
