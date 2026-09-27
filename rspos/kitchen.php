<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin','kitchen']);
require_once 'db_config.php';

$msg = '';

// ── Handle status updates ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $orderId = intval($_POST['order_id'] ?? 0);

    if ($action === 'update_status' && $orderId > 0) {
        $newStatus = $_POST['new_status'];
        $allowed   = ['pending','preparing','ready','completed'];
        if (in_array($newStatus, $allowed)) {
            $conn->query("UPDATE orders SET status='$newStatus' WHERE id=$orderId");
            if ($newStatus === 'completed') {
                $ord = $conn->query("SELECT table_id FROM orders WHERE id=$orderId")->fetch_assoc();
                if ($ord['table_id']) $conn->query("UPDATE restaurant_tables SET status='available' WHERE id={$ord['table_id']}");
            }
            $msg = 'Order status updated.';
        }
    }
}

// ── Fetch orders by status ────────────────────────────────
function fetchOrders($conn, $status) {
    return $conn->query("
        SELECT o.*, COALESCE(t.table_number,'Takeaway') AS table_number,
               TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_minutes
        FROM orders o
        LEFT JOIN restaurant_tables t ON o.table_id=t.id
        WHERE o.status='$status'
        ORDER BY o.created_at ASC");
}

function getOrderItems($conn, $orderId) {
    return $conn->query("SELECT * FROM order_items WHERE order_id=$orderId");
}

$pending   = fetchOrders($conn, 'pending');
$preparing = fetchOrders($conn, 'preparing');
$ready     = fetchOrders($conn, 'ready');
$completed = $conn->query("SELECT o.*, COALESCE(t.table_number,'Takeaway') AS table_number FROM orders o LEFT JOIN restaurant_tables t ON o.table_id=t.id WHERE o.status='completed' AND DATE(o.updated_at)=CURDATE() ORDER BY o.updated_at DESC LIMIT 10");

$counts = [
    'pending'   => $pending->num_rows,
    'preparing' => $preparing->num_rows,
    'ready'     => $ready->num_rows,
    'completed' => $completed->num_rows,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Kitchen Orders — RestoPOS</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    .kitchen-board {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      align-items: start;
    }
    .kitchen-col { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
    .kitchen-col-header {
      padding:14px 18px;
      font-weight:700; font-size:0.9rem;
      border-bottom:1px solid var(--border);
      display:flex; align-items:center; justify-content:space-between;
    }
    .kitchen-card {
      margin:10px;
      background:var(--bg-card2);
      border:1px solid var(--border);
      border-radius:var(--radius-sm);
      padding:14px;
      border-left:4px solid var(--primary);
      transition:var(--transition);
    }
    .kitchen-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-sm); }
    .kitchen-card.urgent { border-left-color:var(--danger); }
    .kitchen-card.ready  { border-left-color:var(--success); }
    .kitchen-card.done   { border-left-color:var(--text-muted); opacity:0.7; }
    .timer { font-size:0.78rem; font-weight:700; padding:3px 8px; border-radius:10px; }
    .timer.ok     { background:rgba(40,167,69,0.15); color:var(--success); }
    .timer.warn   { background:rgba(255,193,7,0.15); color:var(--warning); }
    .timer.urgent { background:rgba(220,53,69,0.15); color:var(--danger); }
    .item-list { margin:8px 0; padding-left:14px; }
    .item-list li { font-size:0.83rem; color:var(--text-muted); padding:2px 0; }
    .col-pending   .kitchen-col-header { border-top:3px solid var(--warning); }
    .col-preparing .kitchen-col-header { border-top:3px solid var(--info); }
    .col-ready     .kitchen-col-header { border-top:3px solid var(--success); }
    .col-completed .kitchen-col-header { border-top:3px solid var(--text-muted); }
    @media(max-width:900px) { .kitchen-board { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:560px) { .kitchen-board { grid-template-columns:1fr; } }
  </style>
</head>



<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  <div class="page-header">
   
    <div class="d-flex gap-2 align-center">
      <span class="badge badge-warning">⏳ <?= $counts['pending'] ?> Pending</span>
      <span class="badge badge-info">🔥 <?= $counts['preparing'] ?> Preparing</span>
      
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <!-- Kitchen Board -->
  <div class="kitchen-board">

    <!-- PENDING -->
    <div class="kitchen-col col-pending">
      <div class="kitchen-col-header">
        <span>🟡 New Orders</span>
        <span class="badge badge-warning"><?= $counts['pending'] ?></span>
      </div>
      <?php if ($pending->num_rows === 0): ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted); font-size:0.85rem;">No pending orders</div>
      <?php else: ?>
        <?php while ($o = $pending->fetch_assoc()):
          $age  = intval($o['age_minutes']);
          $urgCls = $age >= 15 ? 'urgent' : '';
          $timerCls = $age >= 15 ? 'urgent' : ($age >= 8 ? 'warn' : 'ok');
        ?>
          <div class="kitchen-card <?= $urgCls ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="font-size:0.9rem;"><?= htmlspecialchars($o['order_number']) ?></strong>
              <span class="timer <?= $timerCls ?>">⏱ <?= $age ?>m</span>
            </div>
            <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:8px;">
              🪑 <?= htmlspecialchars($o['table_number']) ?>
            </div>
            <ul class="item-list">
              <?php $items = getOrderItems($conn, $o['id']); while ($it = $items->fetch_assoc()): ?>
                <li><?= $it['quantity'] ?>× <?= htmlspecialchars($it['item_name']) ?></li>
              <?php endwhile; ?>
            </ul>
            <?php if ($o['notes']): ?>
              <div style="font-size:0.75rem; color:var(--warning); margin-top:6px;">📝 <?= htmlspecialchars($o['notes']) ?></div>
            <?php endif; ?>
            <form method="POST" style="margin-top:10px;">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <input type="hidden" name="new_status" value="preparing">
              <button class="btn btn-info btn-sm btn-block">🔥 Start Preparing</button>
            </form>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <!-- PREPARING -->
    <div class="kitchen-col col-preparing">
      <div class="kitchen-col-header">
        <span>🔥 Preparing</span>
        <span class="badge badge-info"><?= $counts['preparing'] ?></span>
      </div>
      <?php if ($preparing->num_rows === 0): ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted); font-size:0.85rem;">No orders being prepared</div>
      <?php else: ?>
        <?php while ($o = $preparing->fetch_assoc()):
          $age = intval($o['age_minutes']);
          $timerCls = $age >= 20 ? 'urgent' : ($age >= 12 ? 'warn' : 'ok');
        ?>
          <div class="kitchen-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="font-size:0.9rem;"><?= htmlspecialchars($o['order_number']) ?></strong>
              <span class="timer <?= $timerCls ?>">⏱ <?= $age ?>m</span>
            </div>
            <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:8px;">
              🪑 <?= htmlspecialchars($o['table_number']) ?>
            </div>
            <ul class="item-list">
              <?php $items = getOrderItems($conn, $o['id']); while ($it = $items->fetch_assoc()): ?>
                <li><?= $it['quantity'] ?>× <?= htmlspecialchars($it['item_name']) ?></li>
              <?php endwhile; ?>
            </ul>
            <?php if ($o['notes']): ?>
              <div style="font-size:0.75rem; color:var(--warning); margin-top:6px;">📝 <?= htmlspecialchars($o['notes']) ?></div>
            <?php endif; ?>
            <div class="d-flex gap-1" style="margin-top:10px;">
              <form method="POST" style="flex:1;">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="hidden" name="new_status" value="ready">
                <button class="btn btn-success btn-sm btn-block">✅ Mark Ready</button>
              </form>
              <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="hidden" name="new_status" value="pending">
                <button class="btn btn-ghost btn-sm btn-icon-only" title="Back to pending">↩</button>
              </form>
            </div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <!-- READY -->
    <div class="kitchen-col col-ready">
      <div class="kitchen-col-header">
        <span>✅ Ready to Serve</span>
        <span class="badge badge-success"><?= $counts['ready'] ?></span>
      </div>
      <?php if ($ready->num_rows === 0): ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted); font-size:0.85rem;">No orders ready</div>
      <?php else: ?>
        <?php while ($o = $ready->fetch_assoc()): ?>
          <div class="kitchen-card ready">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="font-size:0.9rem;"><?= htmlspecialchars($o['order_number']) ?></strong>
              <span class="badge badge-success">Ready!</span>
            </div>
            <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:8px;">
              🪑 <?= htmlspecialchars($o['table_number']) ?>
            </div>
            <ul class="item-list">
              <?php $items = getOrderItems($conn, $o['id']); while ($it = $items->fetch_assoc()): ?>
                <li><?= $it['quantity'] ?>× <?= htmlspecialchars($it['item_name']) ?></li>
              <?php endwhile; ?>
            </ul>
            <div class="d-flex gap-1" style="margin-top:10px;">
              <form method="POST" style="flex:1;">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="hidden" name="new_status" value="completed">
                <button class="btn btn-primary btn-sm btn-block">🏁 Complete</button>
              </form>
              <a href="/RestaurantPOS/billing.php?order_id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">💳</a>
            </div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <!-- COMPLETED TODAY -->
    <div class="kitchen-col col-completed">
      <div class="kitchen-col-header">
        <span>🏁 Done Today</span>
        <span class="badge badge-muted"><?= $counts['completed'] ?></span>
      </div>
      <?php if ($completed->num_rows === 0): ?>
        <div style="padding:30px; text-align:center; color:var(--text-muted); font-size:0.85rem;">No completed orders today</div>
      <?php else: ?>
        <?php while ($o = $completed->fetch_assoc()): ?>
          <div class="kitchen-card done">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
              <strong style="font-size:0.85rem;"><?= htmlspecialchars($o['order_number']) ?></strong>
              <span class="badge badge-success fs-sm">Done</span>
            </div>
            <div style="font-size:0.77rem; color:var(--text-muted);">
              🪑 <?= htmlspecialchars($o['table_number']) ?> · Rs <?= number_format($o['total'],2) ?>
            </div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

  </div><!-- /.kitchen-board -->

</div><!-- /.main-content -->

<script>
let autoRefreshInterval = null;
function toggleAutoRefresh(cb) {
  if (cb.checked) {
    autoRefreshInterval = setInterval(() => location.reload(), 30000);
  } else {
    clearInterval(autoRefreshInterval);
  }
}
</script>
</body>
</html>
