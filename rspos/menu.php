<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'db_config.php';
$user = currentUser();

// ── DB Migration: add available_times if missing ──────────
$cols = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'available_times'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE menu_items ADD COLUMN available_times VARCHAR(255) DEFAULT 'all' AFTER image");
    $conn->query("ALTER TABLE menu_items ADD COLUMN time_start TIME DEFAULT NULL AFTER available_times");
    $conn->query("ALTER TABLE menu_items ADD COLUMN time_end   TIME DEFAULT NULL AFTER time_start");
    $conn->query("ALTER TABLE menu_items ADD COLUMN sort_order INT DEFAULT 0 AFTER time_end");
}
// Add stock column if missing
$stockCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock'");
if ($stockCol->num_rows === 0) {
    $conn->query("ALTER TABLE menu_items ADD COLUMN stock INT DEFAULT -1 AFTER sort_order");
    // -1 = unlimited stock
}
// Add image column if missing
$imgCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'image'");
if ($imgCol->num_rows === 0) {
    $conn->query("ALTER TABLE menu_items ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER price");
}
// Ensure uploads dir exists
$uploadDir = __DIR__ . '/assets/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$msg = $err = '';

// ══════════════════════════════════════════════════════════
//  HANDLE POST
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save item (add/edit) ──────────────────────────────
    if ($action === 'save_item') {
        $id     = intval($_POST['item_id']     ?? 0);
        $cat    = intval($_POST['category_id'] ?? 0);
        $name   = trim($_POST['name']          ?? '');
        $desc   = trim($_POST['description']   ?? '');
        $price  = floatval($_POST['price']     ?? 0);
        $status = $_POST['status'] ?? 'available';
        $sort   = intval($_POST['sort_order']  ?? 0);
        $stock  = isset($_POST['stock']) && $_POST['stock'] !== '' ? intval($_POST['stock']) : -1;

        // Available times
        $timesArr = $_POST['available_times'] ?? ['all'];
        if (!is_array($timesArr)) $timesArr = ['all'];
        if (in_array('all', $timesArr)) $timesArr = ['all'];
        $availTimes = implode(',', array_map('trim', $timesArr));

        // Custom time range
        $timeStart = trim($_POST['time_start'] ?? '') ?: null;
        $timeEnd   = trim($_POST['time_end']   ?? '') ?: null;

        // Image upload
        $image = trim($_POST['existing_image'] ?? '');
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === 0) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $newName = 'item_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $newName)) {
                    // Delete old image
                    if ($image && file_exists($uploadDir . $image)) {
                        @unlink($uploadDir . $image);
                    }
                    $image = $newName;
                }
            } else {
                $err = 'Only JPG, PNG, WEBP, GIF images allowed.';
            }
        }

        if (empty($name) || $price <= 0) {
            $err = 'Item name and valid price are required.';
        } elseif (!$err) {
            if ($id > 0) {
                $stmt = $conn->prepare(
                    "UPDATE menu_items SET category_id=?,name=?,description=?,price=?,image=?,
                     available_times=?,time_start=?,time_end=?,sort_order=?,stock=?,status=? WHERE id=?"
                );
                $stmt->bind_param('issdssssiisi', $cat,$name,$desc,$price,$image,
                                  $availTimes,$timeStart,$timeEnd,$sort,$stock,$status,$id);
                $stmt->execute(); $stmt->close();
                $msg = "\"$name\" updated successfully.";
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO menu_items (category_id,name,description,price,image,
                     available_times,time_start,time_end,sort_order,stock,status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param('issdssssiis', $cat,$name,$desc,$price,$image,
                                  $availTimes,$timeStart,$timeEnd,$sort,$stock,$status);
                $stmt->execute(); $stmt->close();
                $msg = "\"$name\" added to menu.";
            }
        }
    }

    // ── Delete item ───────────────────────────────────────
    if ($action === 'delete_item') {
        $id = intval($_POST['item_id']);
        $imgRow = $conn->query("SELECT image FROM menu_items WHERE id=$id")->fetch_assoc();
        if ($imgRow && $imgRow['image'] && file_exists($uploadDir . $imgRow['image'])) {
            @unlink($uploadDir . $imgRow['image']);
        }
        $conn->query("DELETE FROM menu_items WHERE id=$id");
        $msg = 'Item deleted.';
    }

    // ── Toggle status ─────────────────────────────────────
    if ($action === 'toggle_status') {
        $id = intval($_POST['item_id']);
        $conn->query("UPDATE menu_items SET status=IF(status='available','unavailable','available') WHERE id=$id");
        $msg = 'Status updated.';
    }

    // ── Save category ─────────────────────────────────────
    if ($action === 'save_category') {
        $cid   = intval($_POST['cat_id']  ?? 0);
        $cname = trim($_POST['cat_name']  ?? '');
        $cdesc = trim($_POST['cat_desc']  ?? '');
        if (empty($cname)) { $err = 'Category name required.'; }
        elseif ($cid > 0) {
            $s = $conn->prepare("UPDATE categories SET name=?,description=? WHERE id=?");
            $s->bind_param('ssi',$cname,$cdesc,$cid); $s->execute(); $s->close();
            $msg = 'Category updated.';
        } else {
            $s = $conn->prepare("INSERT INTO categories (name,description,status) VALUES(?,?,'active')");
            $s->bind_param('ss',$cname,$cdesc); $s->execute(); $s->close();
            $msg = 'Category created.';
        }
    }

    // ── Delete category ───────────────────────────────────
    if ($action === 'delete_category') {
        $cid = intval($_POST['cat_id']);
        $conn->query("DELETE FROM categories WHERE id=$cid");
        $msg = 'Category deleted.';
    }
}

// ══════════════════════════════════════════════════════════
//  FETCH DATA
// ══════════════════════════════════════════════════════════
$filterCat    = intval($_GET['cat']    ?? 0);
$filterSearch = trim($_GET['q']       ?? '');
$filterStatus = $_GET['status']       ?? '';
$filterTime   = $_GET['time']         ?? '';

$where = '1=1';
if ($filterCat > 0)      $where .= " AND m.category_id=$filterCat";
if ($filterSearch !== '') $where .= " AND m.name LIKE '%" . $conn->real_escape_string($filterSearch) . "%'";
if ($filterStatus !== '') $where .= " AND m.status='" . $conn->real_escape_string($filterStatus) . "'";
if ($filterTime !== '')   $where .= " AND (m.available_times='all' OR FIND_IN_SET('" . $conn->real_escape_string($filterTime) . "', m.available_times))";

$items = $conn->query("
    SELECT m.*, c.name AS cat_name
    FROM menu_items m
    LEFT JOIN categories c ON m.category_id=c.id
    WHERE $where
    ORDER BY m.sort_order ASC, c.name, m.name");

$categories = $conn->query("SELECT * FROM categories WHERE status='active' ORDER BY name");
$catList = [];
while ($c = $categories->fetch_assoc()) $catList[] = $c;

$totalItems = (int)$conn->query("SELECT COUNT(*) AS n FROM menu_items")->fetch_assoc()['n'];
$availItems = (int)$conn->query("SELECT COUNT(*) AS n FROM menu_items WHERE status='available'")->fetch_assoc()['n'];

$selectedCatName = 'All Categories';
$selectedCatCnt  = $totalItems;
if ($filterCat > 0) {
    foreach ($catList as $c) {
        if ((int)$c['id'] === $filterCat) {
            $selectedCatName = $c['name'];
            $selectedCatCnt  = $catCounts[(int)$c['id']] ?? 0;
            break;
        }
    }
}

// Time slots config — matches customer_order.php meal tabs
$timeSlots = [
    'all'               => ['label'=>'&#127758; Always',    'color'=>'#28a745'],
    'Morning Breakfast' => ['label'=>'&#127749; Morning',   'color'=>'#f5c96a'],
    'Lunch'             => ['label'=>'&#9728; Lunch',       'color'=>'#e8a838'],
    'Dinner'            => ['label'=>'&#127769; Dinner',    'color'=>'#17a2b8'],
    'Snacks'            => ['label'=>'&#127871; Snacks',    'color'=>'#6f42c1'],
    'Coffee Time'       => ['label'=>'&#9749; Coffee',      'color'=>'#a0522d'],
    'Ice Creams'        => ['label'=>'&#127846; Ice Cream', 'color'=>'#ff69b4'],
    'Cool Drinks'       => ['label'=>'&#129380; Drinks',    'color'=>'#20c997'],
];

// Per-category item counts for dashboard
$catCounts = [];
$ccRes = $conn->query("SELECT category_id, COUNT(*) as cnt FROM menu_items GROUP BY category_id");
while($cc = $ccRes->fetch_assoc()) $catCounts[(int)$cc['category_id']] = (int)$cc['cnt'];

// Per-time-slot counts
$timeCounts = ['all'=>0];
foreach(array_keys($timeSlots) as $ts){ if($ts!=='all') $timeCounts[$ts]=0; }
$tcRes = $conn->query("SELECT available_times FROM menu_items");
while($tc = $tcRes ? $tcRes->fetch_assoc() : null){
    if(!$tc) break;
    $parts = explode(',', $tc['available_times'] ?? 'all');
    foreach($parts as $p){
        $p = trim($p);
        if($p === 'all'){ $timeCounts['all']++; }
        elseif(isset($timeCounts[$p])){ $timeCounts[$p]++; }
    }
}
?>





<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/RestaurantPOS/manifest.json">
  <title>Menu Management — ServePoint</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    /* ── Menu card ── */
    .mc-wrap { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px; }
    .mc {
      background:var(--bg-card); border:1.5px solid var(--border);
      border-radius:var(--radius); overflow:hidden;
      transition:var(--transition); display:flex; flex-direction:column;
    }
    .mc:hover { border-color:var(--primary); box-shadow:var(--shadow); transform:translateY(-2px); }
    .mc-img {
      height:150px; background:var(--bg-card2);
      display:flex; align-items:center; justify-content:center;
      font-size:3.5rem; position:relative; overflow:hidden; flex-shrink:0;
    }
    .mc-img img { width:100%; height:100%; object-fit:cover; display:block; }
    .mc-status-dot {
      position:absolute; top:10px; right:10px;
      width:12px; height:12px; border-radius:50%;
      border:2px solid var(--bg-card);
    }
    .mc-status-dot.avail { background:var(--success); }
    .mc-status-dot.unavail { background:var(--danger); }

    .mc-body { padding:14px; flex:1; display:flex; flex-direction:column; gap:6px; }
    .mc-name { font-size:.95rem; font-weight:700; }
    .mc-cat  { font-size:.75rem; color:var(--text-muted); }
    .mc-desc { font-size:.76rem; color:var(--text-muted); line-height:1.4; flex:1; }
    .mc-price { font-size:1.05rem; font-weight:800; color:var(--primary); }

    .time-chips { display:flex; gap:4px; flex-wrap:wrap; margin-top:4px; }
    .tc {
      font-size:.65rem; font-weight:700; padding:2px 8px;
      border-radius:10px; text-transform:uppercase; letter-spacing:.4px;
    }

    .mc-actions { display:flex; gap:6px; padding:10px 14px;
                  border-top:1px solid var(--border); background:var(--bg-card2); }

    /* ── Image upload preview ── */
    .img-preview-box {
      width:100%; height:160px; border:2px dashed var(--border);
      border-radius:var(--radius-sm); display:flex; align-items:center;
      justify-content:center; overflow:hidden; cursor:pointer;
      transition:var(--transition); background:var(--bg-dark); position:relative;
    }
    .img-preview-box:hover { border-color:var(--primary); }
    .img-preview-box img { width:100%; height:100%; object-fit:cover; }
    .img-placeholder { color:var(--text-muted); text-align:center; font-size:.85rem; }
    .img-placeholder .ip-ico { font-size:2.5rem; margin-bottom:8px; }
    #fileInput { display:none; }

    /* ── Time selector checkboxes ── */
    .time-sel { display:flex; flex-wrap:wrap; gap:8px; }
    .ts-label {
      display:inline-flex; align-items:center; gap:6px;
      padding:7px 14px; border-radius:20px;
      border:1.5px solid var(--border);
      background:var(--bg-dark); color:var(--text-muted);
      font-size:.8rem; font-weight:700; cursor:pointer;
      transition:var(--transition); user-select:none;
    }
    .ts-label input { display:none; }
    .ts-label.checked { border-color:var(--primary); color:var(--primary); background:rgba(232,168,56,.1); }

    /* ── Inline table view ── */
    .view-toggle { display:flex; gap:6px; }
    .vtb { padding:7px 14px; border-radius:var(--radius-sm); border:1.5px solid var(--border);
           background:var(--bg-dark); color:var(--text-muted); cursor:pointer;
           font-size:.82rem; font-weight:700; transition:var(--transition); }
    .vtb.active { border-color:var(--primary); color:var(--primary); background:rgba(232,168,56,.1); }

    #tableView  { display:none; }
    #gridView   { display:grid; }
    body.table-mode #tableView { display:block; }
    body.table-mode #gridView  { display:none; }
  </style>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  <!-- Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🍽️ <span>Menu</span> Management</h1>
      <p class="page-subtitle">Add items, set availability times & upload photos</p>
    </div>
    <div class="d-flex gap-2" style="flex-wrap:wrap;">
      <div class="view-toggle">
        <button class="vtb active" id="btnGrid" onclick="setView('grid')">⊞ Grid</button>
        <button class="vtb" id="btnTable" onclick="setView('table')">☰ Table</button>
      </div>
      <button class="btn btn-ghost" onclick="openCatModal()">📁 Categories</button>
      <button class="btn btn-primary" onclick="openItemModal()">＋ Add Item</button>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:20px;">
    <div class="stat-card"><div class="stat-icon orange">&#127869;</div>
      <div class="stat-info"><div class="stat-value"><?= $totalItems ?></div><div class="stat-label">Total Items</div></div>
    </div>
    <div class="stat-card"><div class="stat-icon green">&#10003;</div>
      <div class="stat-info"><div class="stat-value"><?= $availItems ?></div><div class="stat-label">Available</div></div>
    </div>
    <div class="stat-card"><div class="stat-icon red">&#10007;</div>
      <div class="stat-info"><div class="stat-value"><?= $totalItems - $availItems ?></div><div class="stat-label">Unavailable</div></div>
    </div>
    <div class="stat-card"><div class="stat-icon blue">&#128193;</div>
      <div class="stat-info"><div class="stat-value"><?= count($catList) ?></div><div class="stat-label">Categories</div></div>
    </div>
  </div>

  <!-- ── Smart Filter Dashboard ── -->
  <style>
    .fdb-wrap{
		margin-bottom:22px;
		}
	
    .fdb-section{
		margin-bottom:16px;
		}
		
    .fdb-title{
		font-size:.72rem;
		font-weight:800;
		text-transform:uppercase;
		letter-spacing:1px;
		color:var(--text-muted);
		margin-bottom:8px;
		padding-left:2px;
		}
		
		
    .fdb-row{
		display:flex;
		gap:8px;
		flex-wrap:wrap;
		}
	
    .fdb-chip{
		display:inline-flex;
		align-items:center;
		gap:6px;
		padding:7px 14px;
		border-radius:20px;
		border:1.5px solid var(--border);
		background:var(--bg-card);
	color:var(--text-muted);
	font-size:.78rem;
	font-weight:700;
	cursor:pointer;
	transition:all .18s;
	text-decoration:none;
	white-space:nowrap;
	}
	
	
    .fdb-chip:hover{
		border-color:var(--primary);
		color:var(--primary);
		background:rgba(232,168,56,.14);
		}
		
    .fdb-chip.active{border-color:var(--primary);color:#000;background:var(--primary);}
    .fdb-chip .cnt{display:inline-block;background:rgba(0,0,0,.18);border-radius:10px;padding:1px 7px;font-size:.68rem;font-weight:800;margin-left:2px;}
    .fdb-chip.active .cnt{background:rgba(0,0,0,.2);}
    .fdb-chip.status-avail{border-color:#28a745;}
    .fdb-chip.status-avail:hover,.fdb-chip.status-avail.active{background:#28a745;border-color:#28a745;color:#fff;}
    .fdb-chip.status-unavail{border-color:#dc3545;}
    .fdb-chip.status-unavail:hover,.fdb-chip.status-unavail.active{background:#dc3545;border-color:#dc3545;color:#fff;}

    /* ── Filter by Category Dropdown Button & Items ── */
    .cat-dd-wrapper{
      position:relative;
      display:inline-block;
      min-width:280px;
      max-width:100%;
    }
    .cat-dd-btn{
      display:inline-flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      width:100%;
      min-width:280px;
      padding:10px 18px;
      border-radius:24px;
      border:1.5px solid var(--border);
      background:var(--bg-card);
      color:var(--text-main);
      font-size:.85rem;
      font-weight:700;
      cursor:pointer;
      transition:all .2s ease;
      user-select:none;
      -webkit-tap-highlight-color:rgba(232,168,56,.2);
      outline:none;
    }
    .cat-dd-btn:hover,
    .cat-dd-btn:focus,
    .cat-dd-wrapper.open .cat-dd-btn{
      border-color:var(--primary);
      color:var(--primary);
      background:rgba(232,168,56,.12);
      box-shadow:0 0 16px rgba(232,168,56,.25);
    }
    .cat-dd-btn-text{
      display:inline-flex;
      align-items:center;
      gap:8px;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .cat-dd-btn-text .cat-name-label{
      color:#fff;
      font-weight:700;
    }
    .cat-dd-arrow{
      color:var(--primary);
      font-size:.78rem;
      transition:transform .25s ease;
      flex-shrink:0;
    }
    .cat-dd-wrapper.open .cat-dd-arrow{
      transform:rotate(180deg);
    }
    .cat-dd-menu{
      display:none;
      position:absolute;
      top:calc(100% + 7px);
      left:0;
      width:100%;
      min-width:290px;
      max-height:360px;
      overflow-y:auto;
      background:#16162a;
      border:1.5px solid rgba(232,168,56,.4);
      border-radius:14px;
      box-shadow:0 12px 35px rgba(0,0,0,.75), 0 0 0 1px rgba(255,255,255,.05);
      z-index:1050;
      padding:6px;
      animation:catDdFade .2s ease forwards;
    }
    @keyframes catDdFade{
      from{opacity:0;transform:translateY(-6px)}
      to{opacity:1;transform:translateY(0)}
    }
    .cat-dd-wrapper.open .cat-dd-menu{
      display:block;
    }
    /* Categories items with clear, reactive hover & touch states */
    .cat-dd-item{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:10px 14px;
      margin:3px 0;
      border-radius:9px;
      color:#c8c8e0;
      font-size:.84rem;
      font-weight:600;
      text-decoration:none;
      border:1px solid transparent;
      cursor:pointer;
      transition:all .18s ease-in-out;
      touch-action:manipulation;
      -webkit-tap-highlight-color:rgba(232,168,56,.25);
    }
    /* Vibrant hover and touch state */
    .cat-dd-item:hover,
    .cat-dd-item:focus,
    .cat-dd-item:active,
    .cat-dd-item.touch-active{
      background:rgba(232,168,56,.22) !important;
      border-color:rgba(232,168,56,.55) !important;
      color:var(--primary) !important;
      transform:translateX(5px);
      box-shadow:inset 3px 0 0 var(--primary);
    }
    .cat-dd-item.active{
      background:linear-gradient(135deg,var(--primary),var(--primary-dark)) !important;
      color:#000 !important;
      font-weight:800;
      border-color:var(--primary) !important;
      box-shadow:0 2px 10px rgba(232,168,56,.35);
    }
    .cat-dd-item .cnt,
    .cat-dd-btn-text .cnt{
      display:inline-block;
      background:rgba(0,0,0,.28);
      color:inherit;
      border-radius:10px;
      padding:2px 7px;
      font-size:.7rem;
      font-weight:800;
      flex-shrink:0;
    }
    .cat-dd-item.active .cnt{
      background:rgba(0,0,0,.25);
      color:#000;
    }
  </style>

  <div class="fdb-wrap">
    <!-- Filter by Category Dropdown Button -->
    <div class="fdb-section">
      <div class="fdb-title">&#128193; Filter by Category</div>
      <div class="cat-dd-wrapper" id="catDdWrapper">
        <button type="button" class="cat-dd-btn" id="catDdBtn" onclick="toggleCatDropdown(event)">
          <span class="cat-dd-btn-text">
            <span>&#128193;</span>
            <span class="cat-name-label"><?= htmlspecialchars($selectedCatName) ?></span>
            <span class="cnt"><?= $selectedCatCnt ?></span>
          </span>
          <span class="cat-dd-arrow">&#9662;</span>
        </button>
        <div class="cat-dd-menu" id="catDdMenu">
          <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['cat'=>0,'status'=>'','time'=>''])) ?>"
             class="cat-dd-item <?= $filterCat===0&&$filterStatus===''&&$filterTime===''?'active':'' ?>">
            <span>&#127869; All Categories</span>
            <span class="cnt"><?= $totalItems ?></span>
          </a>
          <?php foreach($catList as $c):
            $cnt = $catCounts[(int)$c['id']] ?? 0;
            $active = $filterCat===$c['id'] ? 'active' : '';
          ?>
          <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['cat'=>$c['id'],'status'=>'','time'=>''])) ?>"
             class="cat-dd-item <?= $active ?>">
            <span>&#128193; <?= htmlspecialchars($c['name']) ?></span>
            <span class="cnt"><?= $cnt ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
	

	
	
	
	
	
	
	
	
	

    <!-- Status row -->
    <div class="fdb-section">
      <div class="fdb-title">&#9679; Filter by Status</div>
      <div class="fdb-row">
        <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['status'=>''])) ?>"
           class="fdb-chip <?= $filterStatus===''?'active':'' ?>">
          All Status <span class="cnt"><?= $totalItems ?></span>
        </a>
        <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['status'=>'available'])) ?>"
           class="fdb-chip status-avail <?= $filterStatus==='available'?'active':'' ?>">
          &#10003; Available <span class="cnt"><?= $availItems ?></span>
        </a>
        <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['status'=>'unavailable'])) ?>"
           class="fdb-chip status-unavail <?= $filterStatus==='unavailable'?'active':'' ?>">
          &#10007; Unavailable <span class="cnt"><?= $totalItems - $availItems ?></span>
        </a>
      </div>
    </div>

















    <!-- Time slots row -->
    <div class="fdb-section">
      <div class="fdb-title">&#9200; Filter by Meal Time</div>
      <div class="fdb-row">
        <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['time'=>''])) ?>"
           class="fdb-chip <?= $filterTime===''?'active':'' ?>">
          All Times <span class="cnt"><?= $totalItems ?></span>
        </a>
        <?php foreach($timeSlots as $tk => $ts):
          if($tk==='all') continue;
          $tCount = $timeCounts[$tk] ?? 0;
          $active = $filterTime===$tk ? 'active' : '';
        ?>
        <a href="/RestaurantPOS/menu.php?<?= http_build_query(array_merge($_GET,['time'=>$tk])) ?>"
           class="fdb-chip <?= $active ?>" style="<?= $active ? "background:{$ts['color']};border-color:{$ts['color']};color:#000;" : "border-color:{$ts['color']}44;color:{$ts['color']};" ?>">
          <?= $ts['label'] ?> <span class="cnt"><?= $tCount ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>















  <!-- Filters — search only (category/status/time via dashboard chips above) -->
  <form method="GET" class="filter-bar" style="margin-bottom:20px;">
    <input type="hidden" name="cat"    value="<?= $filterCat ?>">
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <input type="hidden" name="time"   value="<?= htmlspecialchars($filterTime) ?>">
    <div class="search-box" style="flex:1;">
      <span class="search-icon">&#128269;</span>
      <input type="text" name="q" class="form-control" placeholder="Search items..." value="<?= htmlspecialchars($filterSearch) ?>">
    </div>
    <button class="btn btn-primary" type="submit">Search</button>
    <a href="/RestaurantPOS/menu.php" class="btn btn-ghost">&#8635; Reset All</a>
  </form>






  <!-- ── GRID VIEW ── -->
  <div class="mc-wrap" id="gridView">
    <?php if ($items->num_rows === 0): ?>
      <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-muted);">
        <div style="font-size:3rem;margin-bottom:12px;">&#127869;</div>
        <p>No items found. <button class="btn btn-primary btn-sm" onclick="openItemModal()">Add first item &rarr;</button></p>
      </div>
    <?php else: ?>
      <?php
      $emojiFb = ['🍔','🍕','🍜','🍣','🥗','🍰','☕','🥤','🍖','🥘','🌮','🍱','🍗','🍝','🍛'];
      while ($item = $items->fetch_assoc()):
        $times = explode(',', $item['available_times'] ?? 'all');
        $imgPath = $item['image'] ? '/RestaurantPOS/assets/uploads/' . htmlspecialchars($item['image']) : null;
        $fb = $emojiFb[$item['id'] % count($emojiFb)];
      ?>
      <div class="mc" id="item-<?= $item['id'] ?>">
        <!-- Image -->
        <div class="mc-img">
          <?php if ($imgPath && file_exists(__DIR__.'/assets/uploads/'.$item['image'])): ?>
            <img src="<?= $imgPath ?>" alt="<?= htmlspecialchars($item['name']) ?>">
          <?php else: ?>
            <?= $fb ?>
          <?php endif; ?>
          <span class="mc-status-dot <?= $item['status']==='available'?'avail':'unavail' ?>"></span>
        </div>
        <!-- Body -->
        <div class="mc-body">
          <div class="mc-name"><?= htmlspecialchars($item['name']) ?></div>
          <div class="mc-cat">📁 <?= htmlspecialchars($item['cat_name'] ?? 'Uncategorized') ?></div>
          <?php if ($item['description']): ?>
            <div class="mc-desc"><?= htmlspecialchars(mb_strimwidth($item['description'],0,70,'…')) ?></div>
          <?php endif; ?>
          <div class="mc-price">Rs <?= number_format($item['price'],2) ?></div>

          <!-- Time chips -->
          <div class="time-chips">
            <?php foreach ($times as $t):
              $t = trim($t);
              $ts = $timeSlots[$t] ?? ['label'=>ucfirst($t),'color'=>'#9090b0'];
            ?>
              <span class="tc" style="background:<?= $ts['color'] ?>22;color:<?= $ts['color'] ?>;border:1px solid <?= $ts['color'] ?>44;">
                <?= $ts['label'] ?>
              </span>
            <?php endforeach; ?>
            <?php if ($item['time_start'] && $item['time_end']): ?>
              <span class="tc" style="background:rgba(23,162,184,.15);color:#17a2b8;border:1px solid rgba(23,162,184,.3);">
                🕐 <?= date('h:i A', strtotime($item['time_start'])) ?>–<?= date('h:i A', strtotime($item['time_end'])) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>
        <!-- Actions -->
        <div class="mc-actions">
          <button class="btn btn-outline btn-sm" style="flex:1;"
            onclick='editItem(<?= json_encode($item) ?>)'>✏️ Edit</button>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
            <button class="btn btn-ghost btn-sm" title="Toggle status" type="submit">🔄</button>
          </form>
          <button class="btn btn-danger btn-sm btn-icon-only"
            onclick="confirmDel(<?= $item['id'] ?>,'<?= addslashes($item['name']) ?>')" title="Delete">🗑</button>
        </div>
      </div>
      <?php endwhile; ?>
    <?php endif; ?>
  </div>

  <!-- ── TABLE VIEW ── -->
  <div id="tableView">
    <div class="card">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Image</th><th>Name</th><th>Category</th>
              <th>Price</th><th>Available Times</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Re-run query (items cursor exhausted)
            $items2 = $conn->query("
                SELECT m.*, c.name AS cat_name FROM menu_items m
                LEFT JOIN categories c ON m.category_id=c.id
                WHERE $where ORDER BY m.sort_order ASC, c.name, m.name");
            if ($items2->num_rows === 0): ?>
              <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">No items found.</td></tr>
            <?php else:
              while ($item = $items2->fetch_assoc()):
                $times   = explode(',', $item['available_times'] ?? 'all');
                $imgPath = $item['image'] ? '/RestaurantPOS/assets/uploads/'.htmlspecialchars($item['image']) : null;
                $fb      = $emojiFb[$item['id'] % count($emojiFb)];
            ?>
              <tr>
                <td style="width:60px;">
                  <?php if ($imgPath && file_exists(__DIR__.'/assets/uploads/'.$item['image'])): ?>
                    <img src="<?= $imgPath ?>" style="width:50px;height:50px;border-radius:8px;object-fit:cover;">
                  <?php else: ?>
                    <span style="font-size:1.8rem;"><?= $fb ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?= htmlspecialchars($item['name']) ?></strong>
                  <?php if ($item['description']): ?>
                    <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($item['description'],0,50,'…')) ?></small>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($item['cat_name'] ?? '—') ?></td>
                <td class="text-primary fw-bold">Rs <?= number_format($item['price'],2) ?></td>
                <td>
                  <div class="time-chips">
                    <?php foreach ($times as $t):
                      $t  = trim($t);
                      $ts = $timeSlots[$t] ?? ['label'=>$t,'color'=>'#9090b0'];
                    ?>
                      <span class="tc" style="background:<?= $ts['color'] ?>22;color:<?= $ts['color'] ?>;border:1px solid <?= $ts['color'] ?>44;">
                        <?= $ts['label'] ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td>
                  <span class="badge <?= $item['status']==='available'?'badge-success':'badge-danger' ?>">
                    <?= $item['status']==='available'?'✅ Available':'❌ Unavailable' ?>
                  </span>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <button class="btn btn-outline btn-sm" onclick='editItem(<?= json_encode($item) ?>)'>✏️</button>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit" title="Toggle">🔄</button>
                    </form>
                    <button class="btn btn-danger btn-sm btn-icon-only"
                      onclick="confirmDel(<?= $item['id'] ?>,'<?= addslashes($item['name']) ?>')">🗑</button>
                  </div>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.main-content -->

<!-- ══════════════════════════════════════════════════════
     ADD / EDIT ITEM MODAL
══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="itemModal">
  <div class="modal modal-xl">
    <div class="modal-header">
      <h3 class="modal-title" id="iModalTitle">➕ Add Menu Item</h3>
      <button class="modal-close" onclick="closeItemModal()">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="itemForm">
      <input type="hidden" name="action" value="save_item">
      <input type="hidden" name="item_id" id="iId" value="0">
      <input type="hidden" name="existing_image" id="iExistImg" value="">

      <div class="modal-body">
        <div class="grid" style="grid-template-columns:280px 1fr;gap:24px;">

          <!-- LEFT: Image upload -->
          <div>
            <label class="form-label">📷 Item Photo</label>
            <div class="img-preview-box" id="imgBox" onclick="document.getElementById('fileInput').click()">
              <img id="imgPreviewEl" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;">
              <div class="img-placeholder" id="imgPlaceholder">
                <div class="ip-ico">📷</div>
                <div>Click to upload photo</div>
                <div style="font-size:.72rem;margin-top:4px;color:#4a4a6a;">JPG, PNG, WEBP (max 5MB)</div>
              </div>
            </div>
            <input type="file" name="image" id="fileInput" accept="image/*" onchange="previewImg(this)">
            <div id="imgExistPreview" style="margin-top:8px;display:none;">
              <img id="imgExistEl" src="" style="width:100%;border-radius:8px;object-fit:cover;max-height:100px;">
              <button type="button" onclick="clearImg()" class="btn btn-danger btn-sm btn-block" style="margin-top:6px;">🗑 Remove Photo</button>
            </div>

            <!-- Sort order -->
            <div class="form-group" style="margin-top:16px;">
              <label class="form-label">Sort Order</label>
              <input type="number" name="sort_order" id="iSort" class="form-control" min="0" value="0" placeholder="0 = first">
              <small style="color:var(--text-muted);font-size:.72rem;">Lower = shows first in menu</small>
            </div>

            <!-- Stock quantity -->
            <div class="form-group" style="margin-top:12px;">
              <label class="form-label">📦 Stock Qty</label>
              <input type="number" name="stock" id="iStock" class="form-control" min="-1" value="-1" placeholder="-1 = Unlimited">
              <small style="color:var(--text-muted);font-size:.72rem;">-1 = Unlimited &nbsp;|&nbsp; 0+ = Limited stock</small>
            </div>
          </div>











          <!-- RIGHT: Details -->
          <div>
            <div class="grid grid-2" style="gap:14px;">
              <div class="form-group">
                <label class="form-label">Item Name *</label>
                <input type="text" name="name" id="iName" class="form-control" placeholder="e.g. Chicken Briyani" required>
              </div>
              <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category_id" id="iCat" class="form-control">
                  <option value="0">— Select Category —</option>
                  <?php foreach ($catList as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Price (Rs) *</label>
                <div class="input-group">
                  <span class="input-prefix">Rs</span>
                  <input type="number" name="price" id="iPrice" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" id="iStatus" class="form-control">
                  <option value="available">✅ Available</option>
                  <option value="unavailable">❌ Unavailable</option>
                </select>
              </div>
            </div>

            <!-- Description -->
            <div class="form-group">
              <label class="form-label">Description</label>
              <textarea name="description" id="iDesc" class="form-control" rows="3"
                        placeholder="Short description shown to customers..."></textarea>
            </div>

            <!-- Available Times -->
            <div class="form-group">
              <label class="form-label">⏰ Available During</label>
              <small style="color:var(--text-muted);display:block;margin-bottom:8px;font-size:.75rem;">
                Select when this item is available. Customers will only see it during selected times.
              </small>
              <div class="time-sel" id="timeSel">
                <?php foreach ($timeSlots as $k => $ts): ?>
                  <label class="ts-label" id="tsl-<?= str_replace(' ','_',$k) ?>" onclick="toggleTime('<?= $k ?>', this)">
                    <input type="checkbox" name="available_times[]" value="<?= $k ?>" class="time-cb"
                           <?= $k === 'all' ? 'checked' : '' ?>>
                    <?= $ts['label'] ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
			
			
			
			
			
			
			

            <!-- Custom time range -->
            <div class="form-group">
              <label class="form-label">🕐 Custom Time Range (optional)</label>
              <small style="color:var(--text-muted);display:block;margin-bottom:8px;font-size:.75rem;">
                Set specific hours within selected meal slots (e.g. 06:00–10:00 for Morning)
              </small>
              <div class="grid grid-2" style="gap:12px;">
                <div>
                  <label class="form-label" style="font-size:.72rem;">From</label>
                  <input type="time" name="time_start" id="iTimeStart" class="form-control">
                </div>
                <div>
                  <label class="form-label" style="font-size:.72rem;">To</label>
                  <input type="time" name="time_end" id="iTimeEnd" class="form-control">
                </div>
              </div>
            </div>
          </div><!-- /right -->
        </div><!-- /grid -->
      </div><!-- /modal-body -->







      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeItemModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save Item</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Delete Confirm ── -->
<div class="modal-overlay" id="delModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3 class="modal-title">🗑️ Delete Item?</h3>
      <button class="modal-close" onclick="document.getElementById('delModal').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body text-center" style="padding:30px;">
      <div style="font-size:2.8rem;margin-bottom:10px;">⚠️</div>
      <p>Delete <strong id="delName"></strong>?</p>
      <p class="text-muted" style="font-size:.82rem;margin-top:5px;">This cannot be undone. Photo will also be deleted.</p>
    </div>
    <div class="modal-footer">
      <form method="POST">
        <input type="hidden" name="action"  value="delete_item">
        <input type="hidden" name="item_id" id="delId" value="">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('delModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-danger">🗑️ Delete</button>
      </form>
    </div>
  </div>
</div>

<!-- ── Category Modal ── -->
<div class="modal-overlay" id="catModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title">📁 Manage Categories</h3>
      <button class="modal-close" onclick="document.getElementById('catModal').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" style="margin-bottom:22px;">
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="cat_id" id="catId" value="0">
        <div class="grid grid-2" style="gap:12px;margin-bottom:12px;">
          <div class="form-group">
            <label class="form-label">Category Name *</label>
            <input type="text" name="cat_name" id="catName" class="form-control" placeholder="e.g. Main Course" required>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="cat_desc" id="catDesc" class="form-control" placeholder="Optional">
          </div>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">💾 Save</button>
          <button type="button" class="btn btn-ghost" onclick="resetCat()">↺ Reset</button>
        </div>
      </form>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Name</th><th>Description</th><th>Items</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($catList as $c):
              $cnt = (int)$conn->query("SELECT COUNT(*) AS n FROM menu_items WHERE category_id={$c['id']}")->fetch_assoc()['n'];
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
              <td class="text-muted"><?= htmlspecialchars($c['description'] ?? '') ?></td>
              <td><span class="badge badge-primary"><?= $cnt ?> items</span></td>
              <td>
                <button class="btn btn-outline btn-sm" onclick="editCat(<?= $c['id'] ?>,'<?= addslashes($c['name']) ?>','<?= addslashes($c['description'] ?? '') ?>')">✏️</button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category? Items will be uncategorized.')">
                  <input type="hidden" name="action" value="delete_category">
                  <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                  <button class="btn btn-danger btn-sm">🗑️</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>











<script>
// ── View toggle ───────────────────────────────────────────
function setView(v) {
  if (v === 'table') {
    document.body.classList.add('table-mode');
    document.getElementById('btnGrid').classList.remove('active');
    document.getElementById('btnTable').classList.add('active');
  } else {
    document.body.classList.remove('table-mode');
    document.getElementById('btnGrid').classList.add('active');
    document.getElementById('btnTable').classList.remove('active');
  }
  localStorage.setItem('menuView', v);
}
// Restore view preference
(function(){ const v = localStorage.getItem('menuView'); if(v) setView(v); })();

// ── Image preview ─────────────────────────────────────────
function previewImg(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('imgPreviewEl').src = e.target.result;
      document.getElementById('imgPreviewEl').style.display = 'block';
      document.getElementById('imgPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
function clearImg() {
  document.getElementById('iExistImg').value = '';
  document.getElementById('fileInput').value = '';
  document.getElementById('imgPreviewEl').style.display = 'none';
  document.getElementById('imgPlaceholder').style.display = 'flex';
  document.getElementById('imgExistPreview').style.display = 'none';
}









// ── Time slot toggle ──────────────────────────────────────
function toggleTime(key, lbl) {
  const cb = lbl.querySelector('input');
  const allLbl = document.getElementById('tsl-all');
  const allCb  = allLbl ? allLbl.querySelector('input') : null;

  if (key === 'all') {
    // Selecting 'all' unchecks others
    const isNowChecked = !cb.checked;
    cb.checked = isNowChecked;
    lbl.classList.toggle('checked', isNowChecked);
    if (isNowChecked) {
      document.querySelectorAll('.time-cb').forEach(c => {
        if (c !== cb) { c.checked = false; c.parentElement.classList.remove('checked'); }
      });
    }
  } else {
    cb.checked = !cb.checked;
    lbl.classList.toggle('checked', cb.checked);
    // Uncheck 'all' if any specific slot is chosen
    if (cb.checked && allCb) {
      allCb.checked = false;
      allLbl.classList.remove('checked');
    }
    // If nothing selected, re-check 'all'
    const anyChecked = [...document.querySelectorAll('.time-cb')].some(c => c !== allCb && c.checked);
    if (!anyChecked && allCb) {
      allCb.checked = true;
      allLbl.classList.add('checked');
    }
  }
}

function setTimeChecks(timesArr) {
  document.querySelectorAll('.time-cb').forEach(cb => {
    const isChecked = timesArr.includes(cb.value);
    cb.checked = isChecked;
    cb.parentElement.classList.toggle('checked', isChecked);
  });
}














// ── Open / close item modal ───────────────────────────────
function openItemModal() {
  resetItemForm();
  document.getElementById('itemModal').classList.add('open');
}
function closeItemModal() {
  document.getElementById('itemModal').classList.remove('open');
}
function resetItemForm() {
  document.getElementById('iId').value       = '0';
  document.getElementById('iModalTitle').textContent = '➕ Add Menu Item';
  document.getElementById('iName').value     = '';
  document.getElementById('iPrice').value    = '';
  document.getElementById('iDesc').value     = '';
  document.getElementById('iSort').value     = '0';
  const iStock = document.getElementById('iStock'); if(iStock) iStock.value = '-1';
  document.getElementById('iCat').value      = '0';
  document.getElementById('iStatus').value   = 'available';
  document.getElementById('iTimeStart').value= '';
  document.getElementById('iTimeEnd').value  = '';
  document.getElementById('iExistImg').value = '';
  document.getElementById('imgPreviewEl').style.display  = 'none';
  document.getElementById('imgPlaceholder').style.display= 'flex';
  document.getElementById('imgExistPreview').style.display= 'none';
  document.getElementById('fileInput').value = '';
  setTimeChecks(['all']);
}

function editItem(item) {
  document.getElementById('iModalTitle').textContent = '✏️ Edit: ' + item.name;
  document.getElementById('iId').value       = item.id;
  document.getElementById('iName').value     = item.name      || '';
  document.getElementById('iPrice').value    = item.price     || '';
  document.getElementById('iDesc').value     = item.description || '';
  document.getElementById('iSort').value     = item.sort_order || 0;
  document.getElementById('iStock').value    = (item.stock !== undefined && item.stock !== null) ? item.stock : -1;
  document.getElementById('iCat').value      = item.category_id || 0;
  document.getElementById('iStatus').value   = item.status    || 'available';
  document.getElementById('iTimeStart').value= item.time_start || '';
  document.getElementById('iTimeEnd').value  = item.time_end  || '';
  document.getElementById('iExistImg').value = item.image     || '';

  // Show existing image
  if (item.image) {
    const existPreview = document.getElementById('imgExistPreview');
    const existEl = document.getElementById('imgExistEl');
    existEl.src = '/RestaurantPOS/assets/uploads/' + item.image;
    existPreview.style.display = 'block';
    document.getElementById('imgPlaceholder').style.display = 'none';
  }

  // Set time checkboxes
  const times = (item.available_times || 'all').split(',').map(s => s.trim());
  setTimeChecks(times);

  document.getElementById('itemModal').classList.add('open');
}

// ── Delete confirm ────────────────────────────────────────
function confirmDel(id, name) {
  document.getElementById('delId').value = id;
  document.getElementById('delName').textContent = name;
  document.getElementById('delModal').classList.add('open');
}

// ── Category modal ────────────────────────────────────────
function openCatModal() { document.getElementById('catModal').classList.add('open'); }
function editCat(id, name, desc) {
  document.getElementById('catId').value   = id;
  document.getElementById('catName').value = name;
  document.getElementById('catDesc').value = desc;
}
function resetCat() {
  document.getElementById('catId').value   = '0';
  document.getElementById('catName').value = '';
  document.getElementById('catDesc').value = '';
}

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if(e.target === o) o.classList.remove('open'); });
});

// Init time checkbox visuals on page load
document.querySelectorAll('.time-cb').forEach(cb => {
  if(cb.checked) cb.parentElement.classList.add('checked');
});

// Category Dropdown Toggle & Outside Click
function toggleCatDropdown(e) {
  if (e) { e.preventDefault(); e.stopPropagation(); }
  var w = document.getElementById('catDdWrapper');
  if (w) w.classList.toggle('open');
}

document.addEventListener('click', function(e) {
  var w = document.getElementById('catDdWrapper');
  if (w && !w.contains(e.target)) {
    w.classList.remove('open');
  }
});

// Touch feedback: immediately highlights on mobile/tablet finger touch
document.querySelectorAll('.cat-dd-item').forEach(function(item) {
  item.addEventListener('touchstart', function() {
    this.classList.add('touch-active');
  }, {passive: true});
  item.addEventListener('touchend', function() {
    var self = this;
    setTimeout(function(){ self.classList.remove('touch-active'); }, 150);
  }, {passive: true});
});
</script>


</body>
</html>
