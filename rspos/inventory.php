<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'db_config.php';

$msg = $err = '';

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_item') {
        $id       = intval($_POST['item_id'] ?? 0);
        $name     = trim($_POST['item_name']);
        $unit     = trim($_POST['unit']);
        $qty      = floatval($_POST['quantity']);
        $minQty   = floatval($_POST['min_quantity']);
        $cost     = floatval($_POST['cost_per_unit']);
        $supplier = trim($_POST['supplier']);
        $status   = $qty <= 0 ? 'out_of_stock' : ($qty <= $minQty ? 'low_stock' : 'in_stock');

        if (empty($name)) { $err = 'Item name is required.'; }
        elseif ($id > 0) {
            $stmt = $conn->prepare("UPDATE inventory SET item_name=?,unit=?,quantity=?,min_quantity=?,cost_per_unit=?,supplier=?,status=? WHERE id=?");
            $stmt->bind_param('ssdddssi', $name,$unit,$qty,$minQty,$cost,$supplier,$status,$id);
            $stmt->execute(); $stmt->close();
            $msg = 'Item updated.';
        } else {
            $stmt = $conn->prepare("INSERT INTO inventory (item_name,unit,quantity,min_quantity,cost_per_unit,supplier,status) VALUES(?,?,?,?,?,?,?)");
            $stmt->bind_param('ssdddss', $name,$unit,$qty,$minQty,$cost,$supplier,$status);
            $stmt->execute(); $stmt->close();
            $msg = 'Item added to inventory.';
        }
    }

    if ($action === 'stock_transaction') {
        $invId   = intval($_POST['inv_id']);
        $type    = $_POST['type']; // 'in' or 'out'
        $qty     = floatval($_POST['qty']);
        $notes   = trim($_POST['notes']);
        $userId  = currentUser()['id'];

        if ($qty <= 0) { $err = 'Quantity must be positive.'; }
        else {
            $delta = $type === 'in' ? $qty : -$qty;
            $conn->query("UPDATE inventory SET quantity=GREATEST(0, quantity+$delta) WHERE id=$invId");
            $conn->query("UPDATE inventory SET status=CASE WHEN quantity=0 THEN 'out_of_stock' WHEN quantity<=min_quantity THEN 'low_stock' ELSE 'in_stock' END WHERE id=$invId");
            $stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id,type,quantity,notes,created_by) VALUES(?,?,?,?,?)");
            $stmt->bind_param('isdsi', $invId,$type,$qty,$notes,$userId);
            $stmt->execute(); $stmt->close();
            $msg = 'Stock ' . ($type === 'in' ? 'added' : 'deducted') . ' successfully.';
        }
    }

    if ($action === 'delete_item') {
        $id = intval($_POST['item_id']);
        $conn->query("DELETE FROM inventory WHERE id=$id");
        $msg = 'Item deleted.';
    }

}

// ── Fetch ─────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$where = '1=1';
if ($filterStatus) $where .= " AND status='" . $conn->real_escape_string($filterStatus) . "'";
if ($search)       $where .= " AND item_name LIKE '%" . $conn->real_escape_string($search) . "%'";

$items = $conn->query("SELECT * FROM inventory WHERE $where ORDER BY status, item_name");

$statCounts = ['in_stock'=>0,'low_stock'=>0,'out_of_stock'=>0];
$statQ = $conn->query("SELECT status, COUNT(*) AS cnt FROM inventory GROUP BY status");
while ($r = $statQ->fetch_assoc()) $statCounts[$r['status']] = $r['cnt'];
$totalItems = array_sum($statCounts);

// Recent transactions
$transactions = $conn->query("
    SELECT it.*, i.item_name, i.unit, COALESCE(u.full_name,'—') AS user_name
    FROM inventory_transactions it
    JOIN inventory i ON it.inventory_id=i.id
    LEFT JOIN users u ON it.created_by=u.id
    ORDER BY it.created_at DESC LIMIT 20");

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Inventory — RestoPOS</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  <div class="page-header">
   
    <?php if (isAdmin()): ?>
      <button class="btn btn-primary" onclick="openItemModal()">＋ Add Item</button>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:20px;">
    <div class="stat-card"><div class="stat-icon blue">📦</div><div class="stat-info"><div class="stat-value"><?= $totalItems ?></div><div class="stat-label">Total Items</div></div></div>
    <div class="stat-card"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value"><?= $statCounts['in_stock'] ?></div><div class="stat-label">In Stock</div></div></div>
    <div class="stat-card"><div class="stat-icon orange">⚠️</div><div class="stat-info"><div class="stat-value"><?= $statCounts['low_stock'] ?></div><div class="stat-label">Low Stock</div></div></div>
    <div class="stat-card"><div class="stat-icon red">❌</div><div class="stat-info"><div class="stat-value"><?= $statCounts['out_of_stock'] ?></div><div class="stat-label">Out of Stock</div></div></div>
  </div>

  <!-- Filter -->
  <div class="filter-bar">
    <form method="GET" style="display:contents;">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" name="q" class="form-control" placeholder="Search inventory..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <select name="status" class="form-control" style="max-width:200px;">
        <option value="">All Status</option>
        <option value="in_stock"      <?= $filterStatus==='in_stock'?'selected':'' ?>>✅ In Stock</option>
        <option value="low_stock"     <?= $filterStatus==='low_stock'?'selected':'' ?>>⚠️ Low Stock</option>
        <option value="out_of_stock"  <?= $filterStatus==='out_of_stock'?'selected':'' ?>>❌ Out of Stock</option>
      </select>
      <button class="btn btn-primary" type="submit">Filter</button>
      <a href="/RestaurantPOS/inventory.php" class="btn btn-ghost">Reset</a>
    </form>
  </div>

  <!-- Inventory Table -->
  <div class="card mb-3">
    <div class="card-header">
      <div class="card-title"><span class="icon">📦</span> Stock Items</div>
      <span class="text-muted fs-sm"><?= $items->num_rows ?> items</span>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Item</th><th>Unit</th><th>Qty</th><th>Min Qty</th><th>Stock Level</th><th>Supplier</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if ($items->num_rows === 0): ?>
            <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">No inventory items found.</td></tr>
          <?php else: ?>
            <?php while ($item = $items->fetch_assoc()):
              $pct = $item['min_quantity'] > 0 ? min(100, round(($item['quantity']/$item['min_quantity'])*100)) : 100;
              $pct = max($pct, 0);
              $barCls = $item['status'] === 'in_stock' ? 'high' : ($item['status'] === 'low_stock' ? 'medium' : 'low');
              $badge  = match($item['status']){'in_stock'=>'badge-success','low_stock'=>'badge-warning','out_of_stock'=>'badge-danger',default=>'badge-muted'};
              $statusLabel = match($item['status']){'in_stock'=>'In Stock','low_stock'=>'Low Stock','out_of_stock'=>'Out of Stock',default=>'Unknown'};
            ?>
              <tr>
                <td data-label="Item"><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                <td data-label="Unit"><?= htmlspecialchars($item['unit']) ?></td>
                <td data-label="Qty" class="fw-bold <?= $item['status']==='out_of_stock'?'text-danger':($item['status']==='low_stock'?'text-warning':'text-success') ?>">
                  <?= number_format($item['quantity'],3) ?>
                </td>
                <td data-label="Min Qty" class="text-muted"><?= number_format($item['min_quantity'],3) ?></td>
                <td data-label="Stock Level" style="min-width:120px;">
                  <div class="stock-level">
                    <div class="stock-bar">
                      <div class="stock-fill <?= $barCls ?>" style="width:<?= min(100,$pct) ?>%"></div>
                    </div>
                    <span class="fs-sm text-muted"><?= $pct ?>%</span>
                  </div>
                </td>
                <td data-label="Supplier" class="text-muted"><?= htmlspecialchars($item['supplier'] ?: '—') ?></td>
                <td data-label="Status"><span class="badge <?= $badge ?>"><?= $statusLabel ?></span></td>
                <td data-label="Actions">
                  <div class="d-flex gap-1">
                    <button class="btn btn-success btn-sm" onclick="openStockModal(<?= $item['id'] ?>,'<?= addslashes($item['item_name']) ?>','<?= $item['unit'] ?>','in')">＋ In</button>
                    <button class="btn btn-warning btn-sm" onclick="openStockModal(<?= $item['id'] ?>,'<?= addslashes($item['item_name']) ?>','<?= $item['unit'] ?>','out')">− Out</button>
                    <?php if (isAdmin()): ?>
                      <button class="btn btn-outline btn-sm btn-icon-only" onclick="editItem(<?= htmlspecialchars(json_encode($item)) ?>)" title="Edit">✏️</button>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this item?')">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <button class="btn btn-danger btn-sm btn-icon-only">🗑️</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Transactions -->
  <div class="card" style="margin-top:20px;">
    <div class="card-header"><div class="card-title"><span class="icon">📋</span> Recent Transactions</div></div>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Item</th><th>Type</th><th>Qty</th><th>Notes</th><th>By</th><th>Date</th></tr></thead>
        <tbody>
          <?php if ($transactions->num_rows === 0): ?>
            <tr><td colspan="6" class="text-center text-muted" style="padding:30px;">No transactions yet.</td></tr>
          <?php else: ?>
            <?php while ($tx = $transactions->fetch_assoc()): ?>
              <tr>
                <td data-label="Item"><strong><?= htmlspecialchars($tx['item_name']) ?></strong></td>
                <td data-label="Type">
                  <span class="badge <?= $tx['type']==='in'?'badge-success':'badge-warning' ?>">
                    <?= $tx['type']==='in'?'＋ Stock In':'− Stock Out' ?>
                  </span>
                </td>
                <td data-label="Qty"><?= number_format($tx['quantity'],3) ?> <?= $tx['unit'] ?></td>
                <td data-label="Notes" class="text-muted"><?= htmlspecialchars($tx['notes'] ?: '—') ?></td>
                <td data-label="By" class="text-muted"><?= htmlspecialchars($tx['user_name']) ?></td>
                <td data-label="Date" class="text-muted fs-sm"><?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?></td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /.main-content -->













<!-- Add/Edit Item Modal -->
<div class="modal-overlay" id="itemModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title" id="itemModalTitle">➕ Add Inventory Item</h3>
      <button class="modal-close" onclick="closeModal('itemModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_item">
      <input type="hidden" name="item_id" id="itemId" value="0">
      <div class="modal-body">
        <div class="grid grid-2" style="gap:14px;">
          <div class="form-group">
            <label class="form-label">Item Name *</label>
            <input type="text" name="item_name" id="iName" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Unit</label>
            <input type="text" name="unit" id="iUnit" class="form-control" placeholder="kg, litre, pcs...">
          </div>
          <div class="form-group">
            <label class="form-label">Current Quantity</label>
            <input type="number" name="quantity" id="iQty" class="form-control" step="0.001" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Min. Quantity (Alert)</label>
            <input type="number" name="min_quantity" id="iMinQty" class="form-control" step="0.001" min="0" value="5">
          </div>
          <div class="form-group">
            <label class="form-label">Cost per Unit (Rs)</label>
            <input type="number" name="cost_per_unit" id="iCost" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Supplier</label>
            <input type="text" name="supplier" id="iSupplier" class="form-control" placeholder="Supplier name">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('itemModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Stock In/Out Modal -->
<div class="modal-overlay" id="stockModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="stockModalTitle">📦 Stock Transaction</h3>
      <button class="modal-close" onclick="closeModal('stockModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="stock_transaction">
      <input type="hidden" name="inv_id" id="stockInvId">
      <input type="hidden" name="type" id="stockType">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" id="stockItemLabel">Item</label>
          <p id="stockItemName" class="fw-bold" style="font-size:1rem;"></p>
        </div>
        <div class="form-group">
          <label class="form-label">Quantity *</label>
          <div class="input-group">
            <input type="number" name="qty" class="form-control" step="0.001" min="0.001" placeholder="0" required>
            <span class="input-prefix" id="stockUnit" style="border-left:none; border-radius:0 8px 8px 0;"></span>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional note...">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('stockModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="stockSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
function openItemModal() {
  document.getElementById('itemModalTitle').textContent = '➕ Add Item';
  document.getElementById('itemId').value    = '0';
  document.getElementById('iName').value     = '';
  document.getElementById('iUnit').value     = '';
  document.getElementById('iQty').value      = '0';
  document.getElementById('iMinQty').value   = '5';
  document.getElementById('iCost').value     = '0';
  document.getElementById('iSupplier').value = '';
  document.getElementById('itemModal').classList.add('open');
}
function closeModal(id)  { document.getElementById(id).classList.remove('open'); }
function editItem(item) {
  document.getElementById('itemModalTitle').textContent = '✏️ Edit Item';
  document.getElementById('itemId').value    = item.id;
  document.getElementById('iName').value     = item.item_name;
  document.getElementById('iUnit').value     = item.unit;
  document.getElementById('iQty').value      = item.quantity;
  document.getElementById('iMinQty').value   = item.min_quantity;
  document.getElementById('iCost').value     = item.cost_per_unit;
  document.getElementById('iSupplier').value = item.supplier || '';
  openItemModal();
}

function openStockModal(id, name, unit, type) {
  document.getElementById('stockInvId').value = id;
  document.getElementById('stockType').value  = type;
  document.getElementById('stockItemName').textContent = name;
  document.getElementById('stockUnit').textContent     = unit;
  document.getElementById('stockModalTitle').textContent = type === 'in' ? '＋ Stock In — '+name : '− Stock Out — '+name;
  document.getElementById('stockSubmitBtn').textContent = type === 'in' ? '＋ Add Stock' : '− Deduct Stock';
  document.getElementById('stockSubmitBtn').className  = type === 'in' ? 'btn btn-success' : 'btn btn-warning';
  document.getElementById('stockModal').classList.add('open');
}

document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) o.classList.remove('open'); }));
</script>
</body>
</html>
