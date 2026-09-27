<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin','kitchen']);
require_once 'db_config.php';

// ── Update order status ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oid  = intval($_POST['order_id'] ?? 0);
    $ns   = $_POST['new_status'] ?? '';
    $ok   = ['pending','preparing','ready','completed'];
    if ($oid > 0 && in_array($ns, $ok)) {
        $conn->query("UPDATE orders SET status='$ns' WHERE id=$oid");
        if ($ns === 'completed') {
            $ord = $conn->query("SELECT table_id FROM orders WHERE id=$oid")->fetch_assoc();
            if ($ord && $ord['table_id'])
                $conn->query("UPDATE restaurant_tables SET status='available' WHERE id={$ord['table_id']}");
        }
    }
    header('Location: /RestaurantPOS/kitchen_display.php');
    exit;
}

// ── Fetch active orders ───────────────────────────────────
function kOrders($conn, $status) {
    return $conn->query("
        SELECT o.id, o.order_number, o.notes, o.status,
               COALESCE(t.table_number,'Takeaway') AS tbl,
               TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age
        FROM orders o
        LEFT JOIN restaurant_tables t ON o.table_id=t.id
        WHERE o.status='$status'
        ORDER BY o.created_at ASC");
}
function kItems($conn, $oid) {
    return $conn->query("SELECT item_name,quantity,notes FROM order_items WHERE order_id=$oid");
}

$pending   = kOrders($conn,'pending');
$preparing = kOrders($conn,'preparing');
$ready     = kOrders($conn,'ready');

$pCount  = $pending->num_rows;
$prCount = $preparing->num_rows;
$rCount  = $ready->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Kitchen Display — ServePoint</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    /* ── Kitchen board layout ── */
    .kb-board{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:16px;
      align-items:start;
    }
    @media(max-width:900px){.kb-board{grid-template-columns:repeat(2,1fr);}}
    @media(max-width:560px){.kb-board{grid-template-columns:1fr;}}

    /* Column */
    .kb-col{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
    .kb-col-head{
      padding:14px 18px;font-weight:800;font-size:.92rem;
      border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:space-between;
    }
    .kb-col.pending   .kb-col-head{border-top:3px solid var(--warning);}
    .kb-col.preparing .kb-col-head{border-top:3px solid var(--info);}
    .kb-col.ready     .kb-col-head{border-top:3px solid var(--success);}

    /* Order card */
    .kb-card{
      margin:10px;background:var(--bg-card2);
      border:1px solid var(--border);border-radius:var(--radius-sm);
      padding:14px;border-left:4px solid var(--primary);
      transition:var(--transition);
    }
    .kb-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm);}
    .kb-card.urgent{border-left-color:var(--danger);}
    .kb-card.ready-card{border-left-color:var(--success);}

    .kb-card-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
    .kb-ord-num{font-size:.9rem;font-weight:800;color:var(--primary);}
    .kb-tbl{font-size:.75rem;color:var(--text-muted);margin-bottom:6px;}

    /* Timer */
    @keyframes urgentPulse{0%,100%{background:rgba(220,53,69,.15)}50%{background:rgba(220,53,69,.3)}}
    .kb-timer{
      font-size:.72rem;font-weight:800;padding:3px 9px;border-radius:10px;
    }
    .kb-timer.ok{background:rgba(40,167,69,.15);color:var(--success);}
    .kb-timer.warn{background:rgba(255,193,7,.15);color:var(--warning);}
    .kb-timer.urgent{background:rgba(220,53,69,.15);color:var(--danger);animation:urgentPulse 1.2s ease infinite;}

    /* Item list */
    .kb-items{margin:8px 0;padding-left:0;list-style:none;}
    .kb-item{
      display:flex;align-items:center;gap:8px;
      font-size:.83rem;color:var(--text-muted);
      padding:4px 0;border-bottom:1px solid rgba(255,255,255,.05);
    }
    .kb-item:last-child{border-bottom:none;}
    .kb-item-qty{
      min-width:26px;height:26px;border-radius:50%;
      background:var(--bg-dark);color:var(--primary);
      font-weight:800;font-size:.78rem;
      display:flex;align-items:center;justify-content:center;
      flex-shrink:0;
    }
    .kb-item-name{flex:1;font-weight:600;color:var(--text-main);}

    /* Notes */
    .kb-note{font-size:.75rem;color:var(--warning);margin-top:6px;padding:5px 8px;background:rgba(255,193,7,.08);border-radius:5px;}

    /* Action buttons */
    .kb-btns{display:flex;gap:6px;margin-top:10px;}

    /* Stats bar */
    .kb-stats{
      display:flex;gap:16px;flex-wrap:wrap;
      background:var(--bg-card);border:1px solid var(--border);
      border-radius:var(--radius-sm);padding:14px 20px;
      margin-bottom:20px;
    }
    .kb-stat{display:flex;align-items:center;gap:8px;}
    .kb-stat-val{font-size:1.5rem;font-weight:800;}
    .kb-stat-lbl{font-size:.75rem;color:var(--text-muted);}

    /* Auto-refresh indicator */
    .refresh-bar{
      height:3px;background:var(--border);border-radius:3px;
      overflow:hidden;margin-bottom:16px;
    }
    .refresh-progress{
      height:100%;background:var(--primary);border-radius:3px;
      animation:refill 30s linear infinite;
    }
    @keyframes refill{from{width:100%}to{width:0%}}

    .empty-col{padding:30px;text-align:center;color:var(--text-muted);font-size:.85rem;}
  </style>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">👨‍🍳 <span>Kitchen</span> Display</h1>
      <p class="page-subtitle">Live order board — <?= date('D, d M Y') ?></p>
    </div>
    <div class="d-flex gap-2 align-center">
      <span class="badge badge-warning">⏳ <?= $pCount ?> New</span>
      <span class="badge badge-info">🔥 <?= $prCount ?> Cooking</span>
      <span class="badge badge-success">✅ <?= $rCount ?> Ready</span>
      <button class="btn btn-ghost btn-sm" onclick="location.reload()">🔄 Refresh</button>
    </div>
  </div>

  <!-- Auto-refresh bar (30s) -->
  <div class="refresh-bar"><div class="refresh-progress"></div></div>

  <!-- Stats -->
  <div class="kb-stats">
    <div class="kb-stat">
      <span class="kb-stat-val" style="color:var(--warning);"><?= $pCount ?></span>
      <div><div class="kb-stat-lbl">New Orders</div></div>
    </div>
    <div class="kb-stat">
      <span class="kb-stat-val" style="color:var(--info);"><?= $prCount ?></span>
      <div><div class="kb-stat-lbl">Preparing</div></div>
    </div>
    <div class="kb-stat">
      <span class="kb-stat-val" style="color:var(--success);"><?= $rCount ?></span>
      <div><div class="kb-stat-lbl">Ready to Serve</div></div>
    </div>
    <div class="kb-stat" style="margin-left:auto;">
      <label class="form-check" style="cursor:pointer;">
        <input type="checkbox" id="autoRef" onchange="toggleAuto(this)"> Auto-refresh (30s)
      </label>
    </div>
  </div>

  <!-- Board -->
  <div class="kb-board">

    <!-- NEW ORDERS -->
    <div class="kb-col pending">
      <div class="kb-col-head">
        <span>🟡 New Orders</span>
        <span class="badge badge-warning"><?= $pCount ?></span>
      </div>
      <?php if ($pending->num_rows === 0): ?>
        <div class="empty-col">📭 No new orders</div>
      <?php else: ?>
        <?php while ($o = $pending->fetch_assoc()):
          $age = intval($o['age']);
          $tc  = $age >= 15 ? 'urgent' : ($age >= 8 ? 'warn' : 'ok');
          $cc  = $age >= 15 ? 'urgent' : '';
        ?>
        <div class="kb-card <?= $cc ?>">
          <div class="kb-card-top">
            <span class="kb-ord-num"><?= htmlspecialchars($o['order_number']) ?></span>
            <span class="kb-timer <?= $tc ?>">⏱ <?= $age ?>m</span>
          </div>
          <div class="kb-tbl">🪑 <?= htmlspecialchars($o['tbl']) ?></div>
          <ul class="kb-items">
            <?php $items = kItems($conn,$o['id']); while ($it=$items->fetch_assoc()): ?>
              <li class="kb-item">
                <span class="kb-item-qty"><?= $it['quantity'] ?></span>
                <span class="kb-item-name"><?= htmlspecialchars($it['item_name']) ?></span>
              </li>
            <?php endwhile; ?>
          </ul>
          <?php if ($o['notes']): ?>
            <div class="kb-note">📝 <?= htmlspecialchars($o['notes']) ?></div>
          <?php endif; ?>
          <div class="kb-btns">
            <form method="POST" style="flex:1;">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <input type="hidden" name="new_status" value="preparing">
              <button class="btn btn-info btn-sm btn-block">🔥 Start Cooking</button>
            </form>
          </div>
        </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <!-- PREPARING -->
    <div class="kb-col preparing">
      <div class="kb-col-head">
        <span>🔥 Preparing</span>
        <span class="badge badge-info"><?= $prCount ?></span>
      </div>
      <?php if ($preparing->num_rows === 0): ?>
        <div class="empty-col">🍳 Nothing cooking</div>
      <?php else: ?>
        <?php while ($o = $preparing->fetch_assoc()):
          $age = intval($o['age']);
          $tc  = $age >= 20 ? 'urgent' : ($age >= 12 ? 'warn' : 'ok');
        ?>
        <div class="kb-card">
          <div class="kb-card-top">
            <span class="kb-ord-num"><?= htmlspecialchars($o['order_number']) ?></span>
            <span class="kb-timer <?= $tc ?>">⏱ <?= $age ?>m</span>
          </div>
          <div class="kb-tbl">🪑 <?= htmlspecialchars($o['tbl']) ?></div>
          <ul class="kb-items">
            <?php $items = kItems($conn,$o['id']); while ($it=$items->fetch_assoc()): ?>
              <li class="kb-item">
                <span class="kb-item-qty"><?= $it['quantity'] ?></span>
                <span class="kb-item-name"><?= htmlspecialchars($it['item_name']) ?></span>
              </li>
            <?php endwhile; ?>
          </ul>
          <?php if ($o['notes']): ?>
            <div class="kb-note">📝 <?= htmlspecialchars($o['notes']) ?></div>
          <?php endif; ?>
          <div class="kb-btns">
            <form method="POST" style="flex:1;">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <input type="hidden" name="new_status" value="ready">
              <button class="btn btn-success btn-sm btn-block">✅ Mark Ready</button>
            </form>
            <form method="POST">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <input type="hidden" name="new_status" value="pending">
              <button class="btn btn-ghost btn-sm" title="Back to New">↩</button>
            </form>
          </div>
        </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <!-- READY -->
    <div class="kb-col ready">
      <div class="kb-col-head">
        <span>✅ Ready to Serve</span>
        <span class="badge badge-success"><?= $rCount ?></span>
      </div>
      <?php if ($ready->num_rows === 0): ?>
        <div class="empty-col">🍽️ Nothing ready yet</div>
      <?php else: ?>
        <?php while ($o = $ready->fetch_assoc()): ?>
        <div class="kb-card ready-card">
          <div class="kb-card-top">
            <span class="kb-ord-num"><?= htmlspecialchars($o['order_number']) ?></span>
            <span class="badge badge-success" style="font-size:.68rem;">Ready!</span>
          </div>
          <div class="kb-tbl">🪑 <?= htmlspecialchars($o['tbl']) ?></div>
          <ul class="kb-items">
            <?php $items = kItems($conn,$o['id']); while ($it=$items->fetch_assoc()): ?>
              <li class="kb-item">
                <span class="kb-item-qty"><?= $it['quantity'] ?></span>
                <span class="kb-item-name"><?= htmlspecialchars($it['item_name']) ?></span>
              </li>
            <?php endwhile; ?>
          </ul>
          <div class="kb-btns">
            <form method="POST" style="flex:1;">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <input type="hidden" name="new_status" value="completed">
              <button class="btn btn-primary btn-sm btn-block">🏁 Complete & Serve</button>
            </form>
          </div>
        </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

  </div><!-- /.kb-board -->
</div>

<script>
let autoInterval = null;
function toggleAuto(cb){
  if (cb.checked) { autoInterval = setInterval(()=>location.reload(), 30000); }
  else            { clearInterval(autoInterval); }
}
// Restore checkbox state
window.addEventListener('load',()=>{
  const cb = document.getElementById('autoRef');
  if (localStorage.getItem('kbAutoRefresh')==='1') { cb.checked=true; toggleAuto(cb); }
  cb.addEventListener('change',()=>localStorage.setItem('kbAutoRefresh', cb.checked?'1':'0'));
});
</script>
</body>
</html>
