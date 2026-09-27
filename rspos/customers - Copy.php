<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'db_config.php';

$msg = $err = '';

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_customer') {
        $id      = intval($_POST['cust_id'] ?? 0);
        $name    = trim($_POST['name']);
        $phone   = trim($_POST['phone']);
        $email   = trim($_POST['email']);
        $address = trim($_POST['address']);

        if (empty($name)) { $err = 'Customer name is required.'; }
        elseif ($id > 0) {
            $stmt = $conn->prepare("UPDATE customers SET name=?,phone=?,email=?,address=? WHERE id=?");
            $stmt->bind_param('ssssi', $name,$phone,$email,$address,$id);
            $stmt->execute(); $stmt->close();
            $msg = 'Customer updated.';
        } else {
            $stmt = $conn->prepare("INSERT INTO customers (name,phone,email,address) VALUES(?,?,?,?)");
            $stmt->bind_param('ssss', $name,$phone,$email,$address);
            $stmt->execute(); $stmt->close();
            $msg = 'Customer added.';
        }
    }

    if ($action === 'delete_customer') {
        $id = intval($_POST['cust_id']);
        $conn->query("DELETE FROM customers WHERE id=$id");
        $msg = 'Customer deleted.';
    }
}

// ── Fetch customers ───────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = $search ? "WHERE name LIKE '%" . $conn->real_escape_string($search) . "%' OR phone LIKE '%" . $conn->real_escape_string($search) . "%'" : '';
$customers = $conn->query("SELECT * FROM customers $where ORDER BY name");

$totalCustomers  = $conn->query("SELECT COUNT(*) AS cnt FROM customers")->fetch_assoc()['cnt'];
$totalCustOrders = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE customer_id IS NOT NULL AND customer_id > 0")->fetch_assoc()['cnt'];

// Selected customer history
$selectedId = intval($_GET['id'] ?? 0);
$selectedCust = null;
$custOrders   = null;
if ($selectedId > 0) {
    $selectedCust = $conn->query("SELECT * FROM customers WHERE id=$selectedId")->fetch_assoc();
    if ($selectedCust) {
        $custOrders = $conn->query("
            SELECT o.*, COALESCE(t.table_number,'Takeaway') AS table_number
            FROM orders o
            LEFT JOIN restaurant_tables t ON o.table_id=t.id
            WHERE o.customer_id=$selectedId
            ORDER BY o.created_at DESC");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Customer Management — RestoPOS</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    .cust-layout { display:grid; grid-template-columns:1fr 360px; gap:20px; align-items:start; }
    .cust-row { cursor:pointer; }
    .cust-row.active { background:rgba(232,168,56,0.06) !important; }
    .cust-avatar { width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.9rem; color:#000; flex-shrink:0; }
    @media(max-width:900px){ .cust-layout{ grid-template-columns:1fr; } }
  </style>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  <div class="page-header">
   
    <button class="btn btn-primary" onclick="openCustModal()">＋ Add Customer</button>
  </div>

  <?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:20px;">
    <div class="stat-card">
      <div class="stat-icon blue">👥</div>
      <div class="stat-info"><div class="stat-value"><?= $totalCustomers ?></div><div class="stat-label">Total Customers</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange">🛒</div>
      <div class="stat-info"><div class="stat-value"><?= $totalCustOrders ?></div><div class="stat-label">Customer Orders</div></div>
    </div>
  </div>

  <!-- Search -->
  <div class="filter-bar">
    <form method="GET" style="display:contents;">
      <div class="search-box" style="flex:1; max-width:400px;">
        <span class="search-icon">🔍</span>
        <input type="text" name="q" class="form-control" placeholder="Search by name or phone..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <button class="btn btn-primary" type="submit">Search</button>
      <a href="/RestaurantPOS/customers.php" class="btn btn-ghost">Clear</a>
    </form>
  </div>

  <div class="cust-layout">

    <!-- Left: Customer List -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon">👥</span> Customers</div>
        <span class="text-muted fs-sm"><?= $customers->num_rows ?> found</span>
      </div>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>Customer</th><th>Phone</th><th>Email</th><th>Orders</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php if ($customers->num_rows === 0): ?>
              <tr><td colspan="5" class="text-center text-muted" style="padding:40px;">
                <?= $search ? 'No customers found for "'.$search.'"' : 'No customers yet.' ?>
              </td></tr>
            <?php else: ?>
              <?php while ($c = $customers->fetch_assoc()):
                $initial = strtoupper(substr($c['name'],0,1));
              ?>
                <tr class="cust-row <?= $selectedId==$c['id']?'active':'' ?>" onclick="window.location.href='?id=<?= $c['id'] ?><?= $search?'&q='.urlencode($search):'' ?>'">
                  <td data-label="Customer">
                    <div class="d-flex align-center gap-2">
                      <div class="cust-avatar"><?= htmlspecialchars($initial) ?></div>
                      <div>
                        <div class="fw-bold"><?= htmlspecialchars($c['name']) ?></div>
                        <?php if ($c['address']): ?><div class="fs-sm text-muted">📍 <?= htmlspecialchars(substr($c['address'],0,30)) ?></div><?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td data-label="Phone"><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                  <td data-label="Email" class="text-muted"><?= htmlspecialchars($c['email'] ?: '—') ?></td>
                  <td data-label="Orders"><span class="badge badge-primary"><?= $c['total_orders'] ?></span></td>
                  <td data-label="Actions">
                    <div class="d-flex gap-1" onclick="event.stopPropagation()">
                      <button class="btn btn-outline btn-sm" onclick="editCust(<?= htmlspecialchars(json_encode($c)) ?>)">✏️</button>
                      <form method="POST" onsubmit="return confirm('Delete this customer?')">
                        <input type="hidden" name="action" value="delete_customer">
                        <input type="hidden" name="cust_id" value="<?= $c['id'] ?>">
                        <button class="btn btn-danger btn-sm">🗑️</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Right: Customer Detail / Order History -->
    <div>
      <?php if ($selectedCust): ?>
        <div class="card" style="margin-bottom:16px;">
          <div class="card-header">
            <div class="card-title"><span class="icon">👤</span> Customer Profile</div>
            <button class="btn btn-outline btn-sm" onclick="editCust(<?= htmlspecialchars(json_encode($selectedCust)) ?>)">✏️ Edit</button>
          </div>
          <div class="card-body">
            <div style="text-align:center; margin-bottom:20px;">
              <div class="cust-avatar" style="width:64px; height:64px; font-size:1.5rem; margin:0 auto 12px;">
                <?= strtoupper(substr($selectedCust['name'],0,1)) ?>
              </div>
              <h3><?= htmlspecialchars($selectedCust['name']) ?></h3>
              <?php if ($selectedCust['phone']): ?><p class="text-muted">📞 <?= htmlspecialchars($selectedCust['phone']) ?></p><?php endif; ?>
              <?php if ($selectedCust['email']): ?><p class="text-muted">✉️ <?= htmlspecialchars($selectedCust['email']) ?></p><?php endif; ?>
              <?php if ($selectedCust['address']): ?><p class="text-muted fs-sm">📍 <?= htmlspecialchars($selectedCust['address']) ?></p><?php endif; ?>
            </div>
            <?php
            $custStats = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS rev FROM orders WHERE customer_id={$selectedCust['id']} AND status='completed'")->fetch_assoc();
            ?>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
              <div style="background:var(--bg-dark); border-radius:var(--radius-sm); padding:14px; text-align:center;">
                <div class="stat-value" style="font-size:1.3rem;"><?= $custStats['cnt'] ?></div>
                <div class="stat-label">Orders</div>
              </div>
              <div style="background:var(--bg-dark); border-radius:var(--radius-sm); padding:14px; text-align:center;">
                <div class="stat-value text-primary" style="font-size:1.1rem;">Rs <?= number_format($custStats['rev'],0) ?></div>
                <div class="stat-label">Total Spent</div>
              </div>
            </div>
            <p class="text-muted fs-sm text-center" style="margin-top:10px;">
              Member since <?= date('d M Y', strtotime($selectedCust['created_at'])) ?>
            </p>
          </div>
        </div>

        <!-- Order History -->
        <div class="card">
          <div class="card-header"><div class="card-title"><span class="icon">🛒</span> Order History</div></div>
          <div style="max-height:400px; overflow-y:auto;">
            <?php if ($custOrders->num_rows === 0): ?>
              <p class="text-muted text-center" style="padding:30px;">No orders yet.</p>
            <?php else: ?>
              <?php while ($o = $custOrders->fetch_assoc()):
                $badge = match($o['status']){'completed'=>'badge-success','cancelled'=>'badge-danger','preparing'=>'badge-warning','ready'=>'badge-info',default=>'badge-muted'};
              ?>
                <div style="padding:12px 18px; border-bottom:1px solid var(--border);">
                  <div class="d-flex justify-between align-center">
                    <div>
                      <div class="fw-bold fs-sm"><?= htmlspecialchars($o['order_number']) ?></div>
                      <div class="text-muted" style="font-size:0.75rem;">🪑 <?= htmlspecialchars($o['table_number']) ?> · <?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></div>
                    </div>
                    <div class="text-right">
                      <div class="text-primary fw-bold">Rs <?= number_format($o['total'],2) ?></div>
                      <span class="badge <?= $badge ?>"><?= ucfirst($o['status']) ?></span>
                    </div>
                  </div>
                </div>
              <?php endwhile; ?>
            <?php endif; ?>
          </div>
        </div>

      <?php else: ?>
        <div class="card">
          <div class="card-body text-center" style="padding:50px;">
            <div style="font-size:3rem; margin-bottom:14px;">👆</div>
            <h3 class="text-muted">Select a customer</h3>
            <p class="text-muted fs-sm" style="margin-top:6px;">Click on any customer to view their profile and order history</p>
          </div>
        </div>
      <?php endif; ?>
    </div>

  </div>

</div><!-- /.main-content -->

<!-- Add/Edit Customer Modal -->
<div class="modal-overlay" id="custModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="custModalTitle">➕ Add Customer</h3>
      <button class="modal-close" onclick="closeModal('custModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_customer">
      <input type="hidden" name="cust_id" id="custId" value="0">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="name" id="custName" class="form-control" placeholder="Customer name" required>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <div class="input-group">
            <span class="input-prefix">📞</span>
            <input type="text" name="phone" id="custPhone" class="form-control" placeholder="e.g. 0123456789">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <div class="input-group">
            <span class="input-prefix">✉️</span>
            <input type="email" name="email" id="custEmail" class="form-control" placeholder="email@example.com">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea name="address" id="custAddress" class="form-control" rows="2" placeholder="Optional address..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('custModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save Customer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCustModal() {
  document.getElementById('custModalTitle').textContent = '➕ Add Customer';
  document.getElementById('custId').value = '0';
  ['custName','custPhone','custEmail','custAddress'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('custModal').classList.add('open');
}
function editCust(c) {
  document.getElementById('custModalTitle').textContent = '✏️ Edit Customer';
  document.getElementById('custId').value      = c.id;
  document.getElementById('custName').value    = c.name;
  document.getElementById('custPhone').value   = c.phone || '';
  document.getElementById('custEmail').value   = c.email || '';
  document.getElementById('custAddress').value = c.address || '';
  document.getElementById('custModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) o.classList.remove('open'); }));
</script>
</body>
</html>
