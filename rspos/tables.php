<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'db_config.php';

$msg = $err = '';

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_table') {
        $id       = intval($_POST['table_id'] ?? 0);
        $number   = trim($_POST['table_number']);
        $capacity = intval($_POST['capacity']);
        $location = trim($_POST['location']);
        $status   = $_POST['status'] ?? 'available';

        if (empty($number)) { $err = 'Table number is required.'; }
        elseif ($id > 0) {
            $stmt = $conn->prepare("UPDATE restaurant_tables SET table_number=?,capacity=?,location=?,status=? WHERE id=?");
            $stmt->bind_param('sissi', $number,$capacity,$location,$status,$id);
            $stmt->execute(); $stmt->close();
            $msg = 'Table updated.';
        } else {
            $stmt = $conn->prepare("INSERT INTO restaurant_tables (table_number,capacity,location,status) VALUES(?,?,?,?)");
            $stmt->bind_param('siss', $number,$capacity,$location,$status);
            $stmt->execute(); $stmt->close();
            $msg = 'Table added.';
        }
    }

    if ($action === 'delete_table') {
        $id = intval($_POST['table_id']);
        $conn->query("DELETE FROM restaurant_tables WHERE id=$id");
        $msg = 'Table deleted.';
    }

    if ($action === 'change_status') {
        $id     = intval($_POST['table_id']);
        $status = $_POST['new_status'];
        $allowed = ['available','occupied','reserved'];
        if (in_array($status, $allowed)) {
            $conn->query("UPDATE restaurant_tables SET status='$status' WHERE id=$id");
            $msg = 'Status updated.';
        }
    }
}

// ── Fetch tables ─────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$where = $filterStatus ? "WHERE status='".  $conn->real_escape_string($filterStatus)."'" : '';
$tables = $conn->query("SELECT * FROM restaurant_tables $where ORDER BY table_number");

$stats = $conn->query("SELECT status, COUNT(*) AS cnt FROM restaurant_tables GROUP BY status");
$tStats = ['available'=>0,'occupied'=>0,'reserved'=>0];
while ($r = $stats->fetch_assoc()) $tStats[$r['status']] = $r['cnt'];
$total = array_sum($tStats);
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Table Management — ServePoint</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    .view-toggle { display:flex; gap:6px; }
    .view-btn { padding:8px 14px; border-radius:var(--radius-sm); border:1.5px solid var(--border); background:var(--bg-dark); color:var(--text-muted); cursor:pointer; transition:var(--transition); font-size:0.85rem; }
    .view-btn.active { border-color:var(--primary); color:var(--primary); background:rgba(232,168,56,0.08); }
  </style>
</head>


<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  <div class="page-header">
   
    <div class="d-flex gap-2">
      <div class="view-toggle">
        <button class="view-btn active" id="gridViewBtn" onclick="setView('grid')">⊞ Grid</button>
        <button class="view-btn" id="listViewBtn" onclick="setView('list')">☰ List</button>
      </div>
      <?php if (isAdmin()): ?>
        <button class="btn btn-primary" onclick="openTableModal()">＋ Add Table</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:20px;">
    <div class="stat-card">
      <div class="stat-icon blue">🪑</div>
      <div class="stat-info"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Tables</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green">🟢</div>
      <div class="stat-info"><div class="stat-value"><?= $tStats['available'] ?></div><div class="stat-label">Available</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon red">🔴</div>
      <div class="stat-info"><div class="stat-value"><?= $tStats['occupied'] ?></div><div class="stat-label">Occupied</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange">🟡</div>
      <div class="stat-info"><div class="stat-value"><?= $tStats['reserved'] ?></div><div class="stat-label">Reserved</div></div>
    </div>
  </div>

  <!-- Filter Tabs -->
  <div class="report-tabs" style="max-width:400px; margin-bottom:20px;">
    <a href="?status=" class="report-tab <?= $filterStatus===''?'active':'' ?>">All (<?= $total ?>)</a>
    <a href="?status=available" class="report-tab <?= $filterStatus==='available'?'active':'' ?>">Available</a>
    <a href="?status=occupied"  class="report-tab <?= $filterStatus==='occupied'?'active':'' ?>">Occupied</a>
    <a href="?status=reserved"  class="report-tab <?= $filterStatus==='reserved'?'active':'' ?>">Reserved</a>
  </div>

  <!-- Grid View -->
  <div id="gridView">
    <div class="table-grid">
      <?php
      $tables->data_seek(0);
      while ($t = $tables->fetch_assoc()):
        $statusIcon = match($t['status']) { 'available'=>'🟢','occupied'=>'🔴','reserved'=>'🟡', default=>'⚪' };
      ?>
        <div class="table-card <?= $t['status'] ?>">
          <div class="table-number"><?= htmlspecialchars($t['table_number']) ?></div>
          <div class="table-capacity">👥 <?= $t['capacity'] ?> seats</div>
          <?php if ($t['location']): ?>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:3px;">📍 <?= htmlspecialchars($t['location']) ?></div>
          <?php endif; ?>
          <div class="table-status <?= $t['status'] ?>">
            <span class="status-dot"></span>
            <?= $statusIcon ?> <?= ucfirst($t['status']) ?>
          </div>
          <div style="margin-top:12px; display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
            <?php if ($t['status'] !== 'available'): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="table_id" value="<?= $t['id'] ?>">
                <input type="hidden" name="new_status" value="available">
                <button class="btn btn-success btn-sm">Free</button>
              </form>
            <?php endif; ?>
            <?php if ($t['status'] !== 'occupied'): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="table_id" value="<?= $t['id'] ?>">
                <input type="hidden" name="new_status" value="occupied">
                <button class="btn btn-danger btn-sm">Occupy</button>
              </form>
            <?php endif; ?>
            <?php if ($t['status'] !== 'reserved'): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="table_id" value="<?= $t['id'] ?>">
                <input type="hidden" name="new_status" value="reserved">
                <button class="btn btn-warning btn-sm">Reserve</button>
              </form>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
              <button class="btn btn-outline btn-sm" onclick="editTable(<?= htmlspecialchars(json_encode($t)) ?>)">✏️</button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this table?')">
                <input type="hidden" name="action" value="delete_table">
                <input type="hidden" name="table_id" value="<?= $t['id'] ?>">
                <button class="btn btn-ghost btn-sm">🗑️</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  </div>

  <!-- List View -->
  <div id="listView" style="display:none;">
    <div class="card">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>Table</th><th>Capacity</th><th>Location</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php
            $tables->data_seek(0);
            while ($t = $tables->fetch_assoc()):
              $badge = match($t['status']){ 'available'=>'badge-success','occupied'=>'badge-danger','reserved'=>'badge-warning',default=>'badge-muted'};
            ?>
            <tr>
              <td data-label="Table"><strong><?= htmlspecialchars($t['table_number']) ?></strong></td>
              <td data-label="Capacity">👥 <?= $t['capacity'] ?></td>
              <td data-label="Location"><?= htmlspecialchars($t['location'] ?: '—') ?></td>
              <td data-label="Status"><span class="badge <?= $badge ?>"><?= ucfirst($t['status']) ?></span></td>
              <td data-label="Actions">
                <div class="d-flex gap-1">
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="table_id" value="<?= $t['id'] ?>">
                    <select name="new_status" class="form-control" style="padding:4px 8px; font-size:0.8rem; width:auto;">
                      <option value="available" <?= $t['status']==='available'?'selected':'' ?>>Available</option>
                      <option value="occupied"  <?= $t['status']==='occupied'?'selected':'' ?>>Occupied</option>
                      <option value="reserved"  <?= $t['status']==='reserved'?'selected':'' ?>>Reserved</option>
                    </select>
                    <button class="btn btn-primary btn-sm">Update</button>
                  </form>
                  <?php if (isAdmin()): ?>
                    <button class="btn btn-outline btn-sm" onclick="editTable(<?= htmlspecialchars(json_encode($t)) ?>)">✏️</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.main-content -->

<!-- Add/Edit Table Modal -->
<div class="modal-overlay" id="tableModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="tableModalTitle">➕ Add Table</h3>
      <button class="modal-close" onclick="closeModal('tableModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_table">
      <input type="hidden" name="table_id" id="tableId" value="0">
      <div class="modal-body">
        <div class="grid grid-2" style="gap:14px;">
          <div class="form-group">
            <label class="form-label">Table Number *</label>
            <input type="text" name="table_number" id="tableNumber" class="form-control" placeholder="e.g. T09" required>
          </div>
          <div class="form-group">
            <label class="form-label">Capacity</label>
            <input type="number" name="capacity" id="tableCapacity" class="form-control" value="4" min="1" max="50">
          </div>
          <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" name="location" id="tableLocation" class="form-control" placeholder="e.g. Main Hall">
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" id="tableStatus" class="form-control">
              <option value="available">🟢 Available</option>
              <option value="occupied">🔴 Occupied</option>
              <option value="reserved">🟡 Reserved</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('tableModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save Table</button>
      </div>
    </form>
  </div>
</div>

<script>
function openTableModal() {
  document.getElementById('tableModalTitle').textContent='➕ Add Table';
  document.getElementById('tableId').value=0;
  document.getElementById('tableNumber').value='';
  document.getElementById('tableCapacity').value='4';
  document.getElementById('tableLocation').value='';
  document.getElementById('tableStatus').value='available';
  document.getElementById('tableModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function editTable(t) {
  document.getElementById('tableModalTitle').textContent = '✏️ Edit Table';
  document.getElementById('tableId').value = t.id;
  document.getElementById('tableNumber').value = t.table_number;
  document.getElementById('tableCapacity').value = t.capacity;
  document.getElementById('tableLocation').value = t.location || '';
  document.getElementById('tableStatus').value = t.status;
  document.getElementById('tableModal').classList.add('open');
}
function setView(v) {
  document.getElementById('gridView').style.display = v==='grid'?'block':'none';
  document.getElementById('listView').style.display = v==='list'?'block':'none';
  document.getElementById('gridViewBtn').classList.toggle('active', v==='grid');
  document.getElementById('listViewBtn').classList.toggle('active', v==='list');
}
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) o.classList.remove('open'); }));
</script>
</body>
</html>
