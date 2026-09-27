<?php
/**
 * debug_menu.php — Check menu_items DB data
 * Visit: http://localhost/RestaurantPOS/debug_menu.php
 * DELETE after use.
 */
require_once __DIR__ . '/db_config.php';

// Check columns
$cols = $conn->query("SHOW COLUMNS FROM menu_items");
$colNames = [];
while ($c = $cols->fetch_assoc()) $colNames[] = $c['Field'];

// Check items
$q = $conn->query("SELECT id, name, category_id, available_times, time_start, time_end, status FROM menu_items ORDER BY id DESC LIMIT 20");
?>







<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Menu Debug</title>
  <style>
    body{font-family:monospace;background:#0f0f1a;color:#f0f0f0;padding:24px;}
    h2{color:#e8a838;} h3{color:#9090b0;margin:18px 0 8px;}
    table{border-collapse:collapse;width:100%;margin-bottom:20px;}
    th,td{border:1px solid #2e2e4e;padding:8px 12px;text-align:left;font-size:.83rem;}
    th{background:#252540;color:#e8a838;}
    .ok{color:#28a745;} .warn{color:#ffc107;} .err{color:#dc3545;}
    .box{background:rgba(232,168,56,.07);border-left:4px solid #e8a838;padding:14px 18px;border-radius:8px;margin-bottom:16px;}
  </style>
</head>
<body>
  <h2>🔧 Menu Debug</h2>

  <div class="box">
    <strong>Columns in menu_items:</strong><br>
    <?= implode(', ', array_map(fn($c) => in_array($c, ['available_times','time_start','time_end','sort_order'])
        ? "<span class='ok'>$c ✅</span>" : $c, $colNames)) ?>
  </div>

  <?php if (!in_array('available_times', $colNames)): ?>
    <div class="box" style="border-color:#dc3545;">
      ❌ <strong>available_times column MISSING!</strong><br>
      Visit <a href="/RestaurantPOS/menu.php" style="color:#e8a838;">menu.php</a> first to auto-create it.
    </div>
  <?php endif; ?>

  <h3>Recent menu_items (latest 20):</h3>
  <table>
    <thead>
      <tr><th>ID</th><th>Name</th><th>Cat ID</th><th>available_times</th><th>time_start</th><th>time_end</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php while ($r = $q->fetch_assoc()): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= $r['category_id'] ?></td>
        <td>
          <?php $at = $r['available_times'] ?? null; ?>
          <?php if (!$at || $at === 'all'): ?>
            <span class="warn"><?= $at ?? 'NULL' ?></span>
          <?php else: ?>
            <span class="ok"><?= htmlspecialchars($at) ?></span>
          <?php endif; ?>
        </td>
        <td><?= $r['time_start'] ?? '<span class="warn">NULL</span>' ?></td>
        <td><?= $r['time_end']   ?? '<span class="warn">NULL</span>' ?></td>
        <td class="<?= $r['status']==='available'?'ok':'err' ?>"><?= $r['status'] ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

  <div class="box">
    <strong>Categories:</strong><br>
    <?php $cats = $conn->query("SELECT id, name FROM categories WHERE status='active' ORDER BY name");
    while ($c = $cats->fetch_assoc()): ?>
      [<?= $c['id'] ?>] <?= htmlspecialchars($c['name']) ?><br>
    <?php endwhile; ?>
  </div>

  <div class="box" style="border-color:#dc3545;color:#ffc107;">
    ⚠️ Delete this file after debugging!<br>
    <a href="/RestaurantPOS/menu.php" style="color:#e8a838;">→ Go to Menu Management</a>
  </div>
</body>
</html>
