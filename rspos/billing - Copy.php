<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin','cashier']);
require_once 'db_config.php';

$user  = currentUser();
$error = '';

// Allowed payment methods (Foreigners & Locals)
$allowedMethods = ['cash','card','paypal','google_pay','apple_pay','online'];

// ── Handle Payment Confirmation ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    $id      = (int)($_POST['order_id'] ?? 0);
    $method  = $_POST['payment_method'] ?? 'cash';
    $txnRef  = trim($_POST['transaction_ref'] ?? '');
    
    $o = $conn->query("SELECT * FROM orders WHERE id=$id")->fetch_assoc();
    $p = $conn->query("SELECT id FROM payments WHERE order_id=$id AND status='paid' LIMIT 1");

    if (!$o || $o['status'] !== 'completed' || ($p && $p->num_rows)) {
        $error = 'This order is not available for payment.';
    } elseif (!in_array($method, $allowedMethods, true)) {
        $error = 'Please select a valid payment method.';
    } else {
        $discount = max(0, min((float)($_POST['discount'] ?? 0), (float)$o['subtotal']));
        $taxRate  = max(0, (float)($_POST['tax_rate'] ?? 6));
        $tax      = round(($o['subtotal'] - $discount) * $taxRate / 100, 2);
        $total    = round($o['subtotal'] - $discount + $tax, 2);
        $received = $method === 'cash' ? (float)($_POST['amount_received'] ?? 0) : $total;

        if ($method === 'cash' && $received < $total) {
            $error = 'Cash received must cover the total.';
        }
        if ($method === 'card') {
            $n = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
            $e = trim($_POST['card_expiry'] ?? '');
            $c = preg_replace('/\D/', '', $_POST['card_cvv'] ?? '');
            if (strlen($n) < 13 || strlen($n) > 19 || !preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $e) || strlen($c) < 3 || strlen($c) > 4) {
                $error = 'Enter valid international card details (13-19 digits, MM/YY, CVV).';
            }
        }

        if (!$error) {
            $change = round(max(0, $received - $total), 2);
            $conn->begin_transaction();
            try {
                $uid = (int)$user['id'];
                $q = $conn->prepare('UPDATE orders SET cashier_id=?,discount=?,tax=?,total=? WHERE id=?');
                $q->bind_param('idddi', $uid, $discount, $tax, $total, $id);
                $q->execute();
                $q->close();

                $q = $conn->prepare("INSERT INTO payments(order_id,payment_method,amount_paid,change_amount,transaction_ref,status) VALUES(?,?,?,?,?, 'paid')");
                $q->bind_param('isdds', $id, $method, $received, $change, $txnRef);
                $q->execute();
                $q->close();

                $conn->commit();
                header("Location: /RestaurantPOS/billing.php?paid_order=$id");
                exit;
            } catch (Throwable $x) {
                $conn->rollback();
                $error = 'Payment could not be saved: ' . $x->getMessage();
            }
        }
    }
}

// ── Receipt Data ────────────────────────────────────────────
$paidId         = (int)($_GET['paid_order'] ?? 0);
$receipt        = null;
$receiptItems   = [];
$receiptPayment = null;

if ($paidId) {
    $receipt = $conn->query("SELECT o.*, COALESCE(t.table_number,'Takeaway') table_number, COALESCE(c.name,'Walk-in') customer_name FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=$paidId")->fetch_assoc();
    if ($receipt) {
        $q = $conn->query("SELECT item_name,quantity,total_price FROM order_items WHERE order_id=$paidId");
        while ($r = $q->fetch_assoc()) $receiptItems[] = $r;
        $receiptPayment = $conn->query("SELECT * FROM payments WHERE order_id=$paidId AND status='paid' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    }
}

// ── Pending Orders ──────────────────────────────────────────
$q = $conn->query("SELECT o.id,o.order_number,o.table_id,o.subtotal,o.tax,o.total,o.notes,COALESCE(t.table_number,'Takeaway') table_number,COALESCE(c.name,'Walk-in') customer_name FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN payments p ON p.order_id=o.id AND p.status='paid' WHERE o.status='completed' AND p.id IS NULL ORDER BY o.updated_at DESC");
$orders = [];
while ($o = $q->fetch_assoc()) {
    $id = (int)$o['id'];
    $o['id'] = $id;
    $o['items'] = [];
    $iq = $conn->query("SELECT item_name,quantity,total_price FROM order_items WHERE order_id=$id");
    while ($i = $iq->fetch_assoc()) $o['items'][] = $i;
    $orders[] = $o;
}

$pmTitles = [
    'cash'       => '💵 Cash',
    'card'       => '💳 International Card',
    'paypal'     => '🅿️ PayPal',
    'google_pay' => '🌐 Google Pay',
    'apple_pay'  => '🍏 Apple Pay',
    'online'     => '📱 Online Wallet'
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/RestaurantPOS/manifest.json">
  <title>Billing & International Payments — ServePoint</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    .wizard{width:100%;max-width:1160px;margin:0 auto}
    .steps{display:flex;gap:8px;overflow-x:auto;margin-bottom:22px;padding-bottom:4px}
    .step{white-space:nowrap;padding:9px 16px;border-radius:20px;background:var(--bg-card2);color:var(--text-muted);font-size:.8rem;font-weight:700;transition:.2s}
    .step.active{background:var(--primary);color:#000;box-shadow:0 3px 12px rgba(232,168,56,.35)}
    .panel{display:none}
    .panel.active{display:block;animation:pFade .22s ease forwards}
    @keyframes pFade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    .gridx{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
    .choice{border:1.5px solid var(--border);border-radius:14px;padding:18px 12px;background:var(--bg-card);cursor:pointer;text-align:center;color:var(--text-main);transition:all .2s;position:relative;overflow:hidden}
    .choice:hover,.choice.selected{border-color:var(--primary);box-shadow:0 0 0 2px rgba(232,168,56,.25);transform:translateY(-2px)}
    .choice.selected{background:rgba(232,168,56,.12);border-color:var(--primary);color:var(--primary)}
    .choice-badge{position:absolute;top:6px;right:6px;font-size:.62rem;font-weight:800;background:var(--primary);color:#000;padding:2px 6px;border-radius:10px;text-transform:uppercase}
    .ico{font-size:2.2rem;display:block;margin-bottom:8px}
    .choice-title{font-weight:700;font-size:.9rem}
    .choice-sub{font-size:.72rem;color:var(--text-muted);margin-top:4px}
    .summary{max-width:680px;margin:auto;background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:20px 24px}
    .line{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px dashed var(--border)}
    .line.total{font-size:1.3rem;font-weight:900;color:var(--primary);border-bottom:none;padding-top:14px}
    .fx-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;background:rgba(232,168,56,.1);border:1px solid rgba(232,168,56,.3);border-radius:12px;padding:10px 16px;margin:16px 0;font-size:.84rem}
    .fx-badges{display:flex;gap:6px;flex-wrap:wrap}
    .fx-tag{background:rgba(0,0,0,.35);padding:3px 9px;border-radius:12px;font-size:.75rem;font-weight:700;color:#fff}
    .actions{display:flex;justify-content:space-between;gap:12px;margin-top:24px}
    .field{display:none;margin-top:18px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:16px;padding:20px}
    .field.show{display:block;animation:pFade .2s ease forwards}
    .note{min-height:22px;font-size:.84rem;margin-top:10px;font-weight:600}
    .invalid{color:var(--danger)}
    .valid{color:var(--success)}

    /* Cash quick buttons */
    .quick-tenders{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .qt-btn{background:var(--bg-card2);border:1px solid var(--border);color:var(--text-main);padding:6px 12px;border-radius:14px;font-size:.78rem;font-weight:700;cursor:pointer;transition:.18s}
    .qt-btn:hover{background:var(--primary);color:#000;border-color:var(--primary)}

    /* Card visual simulator */
    .card-graphic{max-width:380px;height:180px;margin:0 auto 18px;background:linear-gradient(135deg,#1f1c3a 0%,#0d0c1c 100%);border:1.5px solid rgba(232,168,56,.4);border-radius:16px;padding:18px 22px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 8px 24px rgba(0,0,0,.5);position:relative}
    .card-top{display:flex;justify-content:space-between;align-items:center}
    .card-chip{width:36px;height:26px;background:linear-gradient(135deg,#d4af37,#aa820a);border-radius:5px}
    .card-brand{font-weight:900;font-size:1.15rem;letter-spacing:1px;color:var(--primary)}
    .card-num-disp{font-family:'Courier New',monospace;font-size:1.15rem;letter-spacing:2px;color:#fff}
    .card-bot{display:flex;justify-content:space-between;align-items:flex-end;font-size:.75rem;color:var(--text-muted)}
    .card-bot b{color:#fff;font-size:.85rem}

    /* PayPal / GPay / Apple Pay Buttons & Status */
    .pp-box, .gp-box, .ap-box{text-align:center;padding:15px}
    .btn-paypal{background:#ffc439;color:#003087;border:none;border-radius:26px;padding:13px 30px;font-size:1.05rem;font-weight:800;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(255,196,57,.4)}
    .btn-paypal:hover{background:#f4bb34;transform:translateY(-2px);box-shadow:0 6px 20px rgba(255,196,57,.6)}
    .btn-gpay{background:#000;color:#fff;border:1.5px solid #555;border-radius:26px;padding:12px 30px;font-size:1.05rem;font-weight:800;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:8px}
    .btn-gpay:hover{border-color:var(--primary);box-shadow:0 0 16px rgba(232,168,56,.3);transform:translateY(-2px)}
    .btn-applepay{background:#000;color:#fff;border:1.5px solid #fff;border-radius:26px;padding:12px 30px;font-size:1.05rem;font-weight:800;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:8px}
    .btn-applepay:hover{background:#111;box-shadow:0 0 16px rgba(255,255,255,.3);transform:translateY(-2px)}
    .live-qr{width:160px;height:160px;margin:16px auto;background:#fff;border-radius:12px;padding:10px;display:flex;align-items:center;justify-content:center}
    .live-qr svg{width:100%;height:100%}
    .pay-status-pill{display:inline-flex;align-items:center;gap:7px;padding:6px 16px;border-radius:20px;font-size:.82rem;font-weight:700;margin-top:12px}
    .pay-status-pill.ready{background:rgba(23,162,184,.15);border:1px solid #17a2b8;color:#17a2b8}
    .pay-status-pill.success{background:rgba(40,167,69,.15);border:1px solid #28a745;color:#28a745}

    /* Modal dialog for real-time payment processing */
    .rt-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;align-items:center;justify-content:center}
    .rt-modal-overlay.open{display:flex}
    .rt-modal-box{background:#14142a;border:1.5px solid var(--primary);border-radius:20px;max-width:440px;width:90%;padding:28px 24px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.9);animation:rtPop .25s ease}
    @keyframes rtPop{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
    .spinner{width:48px;height:48px;border:4px solid rgba(232,168,56,.2);border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite;margin:16px auto}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* Receipt */
    .receipt{width:260px;margin:auto;background:#fff;color:#000;padding:16px 12px;font:11px 'Courier New',monospace;line-height:1.45;border-radius:4px;box-shadow:0 8px 30px rgba(0,0,0,.5)}
    .center{text-align:center}
    .receipt hr{border:0;border-top:1px dashed #000;margin:6px 0}
    .success-mark{text-align:center;font-size:3.5rem;margin-bottom:8px}
    @media print{
      body>*{display:none!important}
      #printReceipt{display:block!important;position:absolute;left:0;top:0;width:100%}
      .receipt{width:58mm;padding:2mm;font-size:9pt;box-shadow:none}
    }
    #printReceipt{display:none}
  </style>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">💳 <span>Billing & Checkout</span></h1>
      <p class="page-subtitle">Accept local cash, international cards, PayPal, Google Pay & Apple Pay.</p>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:18px;">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($receipt && $receiptPayment):
      $methodName = $pmTitles[$receiptPayment['payment_method']] ?? ucfirst(str_replace('_',' ',$receiptPayment['payment_method']));
      $approxUsd = round($receiptPayment['amount_paid'] / 305, 2);
  ?>
    <!-- ── Payment Success & Receipt ── -->
    <section class="card" style="max-width:580px;margin:0 auto;">
      <div class="card-body" style="padding:28px 24px;">
        <div class="success-mark">✅</div>
        <h2 style="text-align:center;color:var(--success);margin-bottom:4px;">Payment Successful!</h2>
        <p class="text-muted" style="text-align:center;margin-bottom:20px;">
          Table <?= htmlspecialchars($receipt['table_number']) ?> · Order <?= htmlspecialchars($receipt['order_number']) ?>
        </p>
        
        <div class="receipt" id="receipt">
          <div class="center">
            <b style="font-size:14px">★ SERVEPOINT ★</b><br>
            HOTEL & RESTAURANT POS<br>
            <small><?= date('d/m/Y h:i A') ?></small>
          </div>
          <hr>
          Order #: <?= htmlspecialchars($receipt['order_number']) ?><br>
          Table: <?= htmlspecialchars($receipt['table_number']) ?><br>
          Customer: <?= htmlspecialchars($receipt['customer_name']) ?><br>
          Cashier: <?= htmlspecialchars($user['name']) ?>
          <hr>
          <?php foreach ($receiptItems as $i): ?>
            <?= (int)$i['quantity'] ?> × <?= htmlspecialchars($i['item_name']) ?>
            <span style="float:right">Rs <?= number_format($i['total_price'], 2) ?></span><br>
          <?php endforeach; ?>
          <hr>
          Subtotal: <span style="float:right">Rs <?= number_format($receipt['subtotal'], 2) ?></span><br>
          Tax (6%): <span style="float:right">Rs <?= number_format($receipt['tax'], 2) ?></span><br>
          <b>TOTAL: <span style="float:right">Rs <?= number_format($receipt['total'], 2) ?></span></b><br>
          <small style="color:#444;">(≈ USD $<?= number_format($approxUsd, 2) ?>)</small>
          <hr>
          Method: <b><?= htmlspecialchars($methodName) ?></b><br>
          Paid: Rs <?= number_format($receiptPayment['amount_paid'], 2) ?><br>
          Change: Rs <?= number_format($receiptPayment['change_amount'], 2) ?><br>
          <?php if (!empty($receiptPayment['transaction_ref'])): ?>
            Auth/Txn: <span style="font-size:9.5px"><?= htmlspecialchars($receiptPayment['transaction_ref']) ?></span><br>
          <?php endif; ?>
          <hr>
          <div class="center" style="font-weight:bold;margin-top:6px;">
            Thank you for dining with us!<br>
            Please visit again 🍽️
          </div>
        </div>

        <div class="actions" style="justify-content:center;margin-top:24px;">
          <button class="btn btn-primary" onclick="window.print()">🖨 Print Bill Receipt</button>
          <a class="btn btn-outline" href="/RestaurantPOS/billing.php">Process Another Bill</a>
        </div>
      </div>
    </section>
    <div id="printReceipt"></div>
    <script>
      document.getElementById('printReceipt').innerHTML = document.getElementById('receipt').outerHTML;
    </script>
  <?php else: ?>
    <!-- ── Billing Flow Wizard ── -->
    <div class="wizard">
      <div class="steps">
        <span class="step active" data-s="1">1. Select Table</span>
        <span class="step" data-s="2">2. Select Order</span>
        <span class="step" data-s="3">3. Order Summary</span>
        <span class="step" data-s="4">4. Payment (Foreign & Local)</span>
        <span class="step" data-s="5">5. Review & Confirm</span>
      </div>

      <!-- Step 1: Select Table -->
      <section class="panel active card" data-p="1">
        <div class="card-header"><div class="card-title">🪑 Select Table with Completed Order</div></div>
        <div class="card-body"><div class="gridx" id="tables"></div></div>
      </section>

      <!-- Step 2: Select Order -->
      <section class="panel card" data-p="2">
        <div class="card-header"><div class="card-title">🍽️ Select Customer Order</div></div>
        <div class="card-body">
          <div class="gridx" id="orderList"></div>
          <div class="actions"><button class="btn btn-ghost" onclick="go(1)">← Back to Tables</button></div>
        </div>
      </section>

      <!-- Step 3: Order Summary -->
      <section class="panel card" data-p="3">
        <div class="card-header"><div class="card-title">🧾 Order Summary & Currency Breakdown</div></div>
        <div class="card-body">
          <div class="summary" id="orderSummary"></div>
          <div class="actions">
            <button class="btn btn-ghost" onclick="go(2)">← Back</button>
            <button class="btn btn-primary" onclick="go(4)">Proceed to Payment Method →</button>
          </div>
        </div>
      </section>

      <!-- Step 4: Payment Method (Dynamic Foreigners & Local) -->
      <section class="panel card" data-p="4">
        <div class="card-header">
          <div class="card-title">💰 Payment Methods (Local & International)</div>
        </div>
        <div class="card-body">
          
          <!-- Dynamic Currency Converter Bar -->
          <div class="fx-bar" id="fxBar">
            <div>
              <strong>🌐 Foreign Currency Converter:</strong>
              <span id="fxPayableText" style="margin-left:6px;font-weight:700;"></span>
            </div>
            <div class="fx-badges">
              <span class="fx-tag" id="tagUsd">USD $0.00</span>
              <span class="fx-tag" id="tagEur">EUR €0.00</span>
              <span class="fx-tag" id="tagGbp">GBP £0.00</span>
            </div>
          </div>

          <!-- Payment Options Grid -->
          <div class="gridx">
            <button type="button" class="choice selected" id="optCash" onclick="methodSelect('cash')">
              <span class="ico">💵</span>
              <div class="choice-title">Cash</div>
              <div class="choice-sub">Local Currency (Rs)</div>
            </button>
            
            <button type="button" class="choice" id="optCard" onclick="methodSelect('card')">
              <span class="choice-badge">Global</span>
              <span class="ico">💳</span>
              <div class="choice-title">International Card</div>
              <div class="choice-sub">Visa / Master / Amex</div>
            </button>

            <button type="button" class="choice" id="optPaypal" onclick="methodSelect('paypal')">
              <span class="choice-badge" style="background:#003087;color:#ffc439;">PayPal</span>
              <span class="ico">🅿️</span>
              <div class="choice-title">PayPal</div>
              <div class="choice-sub">Foreign Tourists & QR</div>
            </button>

            <button type="button" class="choice" id="optGpay" onclick="methodSelect('google_pay')">
              <span class="choice-badge" style="background:#fff;color:#000;">GPay</span>
              <span class="ico">🌐</span>
              <div class="choice-title">Google Pay</div>
              <div class="choice-sub">Tap to Pay / Mobile</div>
            </button>

            <button type="button" class="choice" id="optApplepay" onclick="methodSelect('apple_pay')">
              <span class="choice-badge" style="background:#000;color:#fff;"> Pay</span>
              <span class="ico">🍏</span>
              <div class="choice-title">Apple Pay</div>
              <div class="choice-sub">iPhone / Touch & FaceID</div>
            </button>

            <button type="button" class="choice" id="optOnline" onclick="methodSelect('online')">
              <span class="ico">📱</span>
              <div class="choice-title">Online / QR</div>
              <div class="choice-sub">Instant QR Wallet</div>
            </button>
          </div>

          <!-- CASH FIELDS -->
          <div class="field show" id="cashFields">
            <label class="form-label" style="font-weight:700;">Amount Received (Rs)</label>
            <input id="cashReceived" class="form-control" type="number" min="0" step="0.01" placeholder="Enter cash given by customer" oninput="live()">
            <div class="quick-tenders">
              <span style="font-size:.78rem;color:var(--text-muted);display:flex;align-items:center;">Quick Tender:</span>
              <button type="button" class="qt-btn" onclick="tenderExact()">Exact</button>
              <button type="button" class="qt-btn" onclick="tenderAdd(500)">+500</button>
              <button type="button" class="qt-btn" onclick="tenderAdd(1000)">+1000</button>
              <button type="button" class="qt-btn" onclick="tenderAdd(2000)">+2000</button>
              <button type="button" class="qt-btn" onclick="tenderAdd(5000)">+5000</button>
            </div>
            <div id="cashNote" class="note"></div>
          </div>

          <!-- CARD FIELDS -->
          <div class="field" id="cardFields">
            <div class="card-graphic">
              <div class="card-top">
                <div class="card-chip"></div>
                <div class="card-brand" id="prevBrand">VISA / MC</div>
              </div>
              <div class="card-num-disp" id="prevNum">•••• •••• •••• ••••</div>
              <div class="card-bot">
                <div>
                  CARD HOLDER<br>
                  <b id="prevName">VALUED GUEST</b>
                </div>
                <div>
                  EXPIRES<br>
                  <b id="prevExp">MM/YY</b>
                </div>
              </div>
            </div>

            <div class="grid grid-2" style="gap:14px">
              <div>
                <label class="form-label">Card Holder Name</label>
                <input id="cardName" class="form-control" placeholder="Name on card" oninput="cardLiveUpdate();live()">
              </div>
              <div>
                <label class="form-label">Card Number (All International Cards)</label>
                <input id="cardNumber" class="form-control" inputmode="numeric" maxlength="23" placeholder="4xxx xxxx xxxx xxxx" oninput="cardFormat(this);cardLiveUpdate();live()">
              </div>
              <div>
                <label class="form-label">Expiry (MM/YY)</label>
                <input id="cardExpiry" class="form-control" inputmode="numeric" maxlength="5" placeholder="MM/YY" oninput="expiryFormat(this);cardLiveUpdate();live()">
              </div>
              <div>
                <label class="form-label">CVV / CVC</label>
                <input id="cardCvv" class="form-control" type="password" inputmode="numeric" maxlength="4" placeholder="123" oninput="live()">
              </div>
            </div>
            <div style="margin-top:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
              <button type="button" class="btn btn-outline" style="font-size:.8rem;" onclick="simulateCardSwipe()">📲 Simulate Contactless Card Tap</button>
              <div id="cardNote" class="note invalid"></div>
            </div>
          </div>

          <!-- PAYPAL FIELDS -->
          <div class="field" id="paypalFields">
            <div class="pp-box">
              <div style="margin-bottom:12px;">
                <h3 style="color:#0079c1;font-weight:800;margin-bottom:4px;">PayPal International Checkout</h3>
                <p class="text-muted" style="font-size:.85rem;">Instant real-time payment for foreign guests using PayPal balance, international credit cards, or PayPal QR scan.</p>
              </div>
              
              <div class="fx-bar" style="max-width:440px;margin:12px auto;">
                <span>Total Due: <strong id="ppAmountLkr">Rs 0.00</strong></span>
                <span>≈ <strong style="color:var(--primary);" id="ppAmountUsd">$0.00 USD</strong></span>
              </div>

              <div class="live-qr">
                <!-- Live SVG QR Code pattern -->
                <svg viewBox="0 0 100 100">
                  <path d="M0,0 h30 v30 h-30 z M5,5 v20 h20 v-20 z M10,10 h10 v10 h-10 z" fill="#003087"/>
                  <path d="M70,0 h30 v30 h-30 z M75,5 v20 h20 v-20 z M80,10 h10 v10 h-10 z" fill="#003087"/>
                  <path d="M0,70 h30 v30 h-30 z M5,75 v20 h20 v-20 z M10,80 h10 v10 h-10 z" fill="#003087"/>
                  <rect x="40" y="10" width="8" height="8" fill="#0079c1"/>
                  <rect x="52" y="20" width="8" height="8" fill="#0079c1"/>
                  <rect x="35" y="40" width="30" height="20" rx="3" fill="#ffc439"/>
                  <text x="42" y="54" font-size="10" font-weight="900" fill="#003087">P</text>
                  <text x="50" y="54" font-size="10" font-weight="900" fill="#0079c1">P</text>
                  <rect x="75" y="45" width="10" height="10" fill="#003087"/>
                  <rect x="45" y="75" width="12" height="12" fill="#003087"/>
                  <rect x="65" y="80" width="15" height="10" fill="#0079c1"/>
                </svg>
              </div>

              <button type="button" class="btn-paypal" onclick="triggerRealtimeGateway('paypal')">
                <span>🅿️</span> Pay with PayPal
              </button>
              <div>
                <span class="pay-status-pill ready" id="ppStatus">Ready for PayPal Authorization</span>
              </div>
            </div>
          </div>

          <!-- GOOGLE PAY FIELDS -->
          <div class="field" id="gpayFields">
            <div class="gp-box">
              <h3 style="color:#fff;font-weight:800;margin-bottom:6px;">Google Pay (GPay)</h3>
              <p class="text-muted" style="font-size:.85rem;">Fast, simple and secure checkout with Google Pay NFC tap or mobile screen confirmation.</p>

              <div class="fx-bar" style="max-width:440px;margin:14px auto;">
                <span>Total Payable: <strong id="gpAmountLkr">Rs 0.00</strong></span>
                <span>≈ <strong style="color:var(--primary);" id="gpAmountUsd">$0.00 USD</strong></span>
              </div>

              <button type="button" class="btn-gpay" onclick="triggerRealtimeGateway('google_pay')">
                <span style="font-size:1.3rem;">🌐</span> Pay with Google Pay
              </button>
              <div>
                <span class="pay-status-pill ready" id="gpStatus">Tap card / phone to GPay reader</span>
              </div>
            </div>
          </div>

          <!-- APPLE PAY FIELDS -->
          <div class="field" id="applepayFields">
            <div class="ap-box">
              <h3 style="color:#fff;font-weight:800;margin-bottom:6px;"> Apple Pay</h3>
              <p class="text-muted" style="font-size:.85rem;">Pay seamlessly with iPhone, Apple Watch or iPad using Face ID / Touch ID.</p>

              <div class="fx-bar" style="max-width:440px;margin:14px auto;">
                <span>Total Payable: <strong id="apAmountLkr">Rs 0.00</strong></span>
                <span>≈ <strong style="color:var(--primary);" id="apAmountUsd">$0.00 USD</strong></span>
              </div>

              <button type="button" class="btn-applepay" onclick="triggerRealtimeGateway('apple_pay')">
                <span></span> Pay with Apple Pay
              </button>
              <div>
                <span class="pay-status-pill ready" id="apStatus">Hold iPhone near reader to authenticate</span>
              </div>
            </div>
          </div>

          <!-- ONLINE / QR FIELDS -->
          <div class="field" id="onlineFields">
            <div style="text-align:center;padding:15px;">
              <h3 style="font-weight:800;margin-bottom:6px;">📱 Instant QR Code & Online Wallet</h3>
              <p class="text-muted" style="font-size:.85rem;">Customer scans this dynamic QR code with any banking app or digital wallet.</p>
              <div class="live-qr">
                <svg viewBox="0 0 100 100">
                  <path d="M0,0 h30 v30 h-30 z M5,5 v20 h20 v-20 z M10,10 h10 v10 h-10 z" fill="#28a745"/>
                  <path d="M70,0 h30 v30 h-30 z M75,5 v20 h20 v-20 z M80,10 h10 v10 h-10 z" fill="#28a745"/>
                  <path d="M0,70 h30 v30 h-30 z M5,75 v20 h20 v-20 z M10,80 h10 v10 h-10 z" fill="#28a745"/>
                  <rect x="42" y="15" width="10" height="10" fill="#28a745"/>
                  <rect x="58" y="30" width="8" height="8" fill="#17a2b8"/>
                  <rect x="42" y="42" width="16" height="16" rx="2" fill="#e8a838"/>
                  <rect x="75" y="45" width="12" height="12" fill="#28a745"/>
                  <rect x="45" y="75" width="10" height="10" fill="#17a2b8"/>
                  <rect x="68" y="78" width="14" height="12" fill="#28a745"/>
                </svg>
              </div>
              <button type="button" class="btn btn-success" onclick="triggerRealtimeGateway('online')">
                ⚡ Simulate Instant QR Scan & Payment
              </button>
              <div>
                <span class="pay-status-pill ready" id="onStatus">Awaiting customer QR scan...</span>
              </div>
            </div>
          </div>

          <div class="actions">
            <button type="button" class="btn btn-ghost" onclick="go(3)">← Back</button>
            <button type="button" class="btn btn-primary" onclick="review()">Review Payment →</button>
          </div>
        </div>
      </section>

      <!-- Step 5: Review & Confirm -->
      <section class="panel card" data-p="5">
        <div class="card-header"><div class="card-title">✅ Payment Review & Confirmation</div></div>
        <div class="card-body">
          <div class="summary" id="paymentSummary"></div>
          <form method="POST">
            <input type="hidden" name="action" value="confirm_payment">
            <input type="hidden" name="order_id" id="fId">
            <input type="hidden" name="payment_method" id="fMethod">
            <input type="hidden" name="discount" value="0">
            <input type="hidden" name="tax_rate" value="6">
            <input type="hidden" name="amount_received" id="fReceived">
            <input type="hidden" name="card_number" id="fNum">
            <input type="hidden" name="card_expiry" id="fExp">
            <input type="hidden" name="card_cvv" id="fCvv">
            <input type="hidden" name="transaction_ref" id="fTxn">

            <div class="actions">
              <button type="button" class="btn btn-ghost" onclick="go(4)">← Back</button>
              <button type="submit" class="btn btn-success" id="btnFinalConfirm" style="font-size:1.05rem;padding:12px 30px;">
                ✅ Confirm & Print Receipt
              </button>
            </div>
          </form>
        </div>
      </section>
    </div>

    <!-- Real-time Gateway Simulator Modal -->
    <div class="rt-modal-overlay" id="rtModal">
      <div class="rt-modal-box">
        <div id="rtIcon" style="font-size:3rem;margin-bottom:8px;">🅿️</div>
        <h3 id="rtTitle" style="color:var(--primary);margin-bottom:6px;">Connecting to Gateway...</h3>
        <p id="rtDesc" class="text-muted" style="font-size:.88rem;margin-bottom:14px;">Contacting payment provider in real-time...</p>
        <div class="spinner" id="rtSpinner"></div>
        <div id="rtSuccess" style="display:none;font-size:3rem;margin-bottom:10px;">✅</div>
        <div id="rtRef" style="font-family:'Courier New',monospace;font-size:.9rem;color:var(--primary);font-weight:700;display:none;margin-bottom:14px;"></div>
        <button type="button" class="btn btn-primary" id="rtDoneBtn" style="display:none;width:100%;" onclick="closeRtModal()">Continue to Review →</button>
      </div>
    </div>

    <script>
    const orders = <?= json_encode($orders, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
    let picked = null, method = 'cash', currentTxn = '';
    
    // FX Rates
    const FX_USD = 305.00; // 1 USD = 305 LKR
    const FX_EUR = 330.00; // 1 EUR = 330 LKR
    const FX_GBP = 388.00; // 1 GBP = 388 LKR

    const rs = n => 'Rs ' + Number(n).toFixed(2);
    const usd = n => '$' + (Number(n) / FX_USD).toFixed(2);
    const eur = n => '€' + (Number(n) / FX_EUR).toFixed(2);
    const gbp = n => '£' + (Number(n) / FX_GBP).toFixed(2);
    const esc = s => String(s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function go(n) {
      document.querySelectorAll('.panel').forEach(x => x.classList.toggle('active', +x.dataset.p === n));
      document.querySelectorAll('.step').forEach(x => x.classList.toggle('active', +x.dataset.s === n));
      scrollTo({ top: 0, behavior: 'smooth' });
    }

    function key(o) { return o.table_id || 'walkin'; }

    function tables() {
      let g = {};
      orders.forEach(o => {
        let k = key(o);
        g[k] ??= { name: o.table_number, n: 0, key: k };
        g[k].n++;
      });
      let a = Object.values(g);
      document.getElementById('tables').innerHTML = a.length ? a.map(x => `
        <button class="choice" onclick="pickTable('${x.key}')">
          <span class="ico">🪑</span>
          <div class="choice-title">${esc(x.name)}</div>
          <small class="text-muted">${x.n} completed order${x.n > 1 ? 's' : ''}</small>
        </button>
      `).join('') : '<p class="text-muted" style="padding:20px;">No completed orders are currently waiting for payment.</p>';
    }

    function pickTable(k) {
      let a = orders.filter(o => key(o) === k);
      orderList.innerHTML = a.map(o => `
        <button class="choice" onclick="pickOrder(${o.id})">
          <div class="choice-title" style="color:var(--primary);">${esc(o.order_number)}</div>
          <small class="text-muted">${esc(o.customer_name)}</small><br>
          <small>${o.items.map(i => i.quantity + '× ' + esc(i.item_name)).join(', ')}</small><br>
          <b style="font-size:1.05rem;display:inline-block;margin-top:6px;">${rs(o.total)}</b>
        </button>
      `).join('');
      go(2);
    }

    function lines(o) {
      return o.items.map(i => `
        <div class="line">
          <span>${esc(i.item_name)} ×${i.quantity}</span>
          <span>${rs(i.total_price)}</span>
        </div>
      `).join('');
    }

    function pickOrder(id) {
      picked = orders.find(o => o.id === id);
      const totalNum = +picked.total;
      
      orderSummary.innerHTML = `
        <div class="line"><span>Table</span><b>${esc(picked.table_number)}</b></div>
        <div class="line"><span>Customer</span><b>${esc(picked.customer_name)}</b></div>
        ${lines(picked)}
        <div class="line"><span>Subtotal</span><span>${rs(picked.subtotal)}</span></div>
        <div class="line"><span>Tax (6%)</span><span>${rs(picked.tax)}</span></div>
        <div class="line total"><span>Total Payable</span><span>${rs(picked.total)}</span></div>
        <div class="fx-bar" style="margin-top:12px;">
          <span>🌐 Foreign Exchange Equivalents:</span>
          <div class="fx-badges">
            <span class="fx-tag">${usd(totalNum)} USD</span>
            <span class="fx-tag">${eur(totalNum)} EUR</span>
            <span class="fx-tag">${gbp(totalNum)} GBP</span>
          </div>
        </div>
      `;

      // Update foreign exchange text in payment panel
      document.getElementById('fxPayableText').textContent = rs(totalNum);
      document.getElementById('tagUsd').textContent = usd(totalNum) + ' USD';
      document.getElementById('tagEur').textContent = eur(totalNum) + ' EUR';
      document.getElementById('tagGbp').textContent = gbp(totalNum) + ' GBP';

      document.getElementById('ppAmountLkr').textContent = rs(totalNum);
      document.getElementById('ppAmountUsd').textContent = usd(totalNum) + ' USD';

      document.getElementById('gpAmountLkr').textContent = rs(totalNum);
      document.getElementById('gpAmountUsd').textContent = usd(totalNum) + ' USD';

      document.getElementById('apAmountLkr').textContent = rs(totalNum);
      document.getElementById('apAmountUsd').textContent = usd(totalNum) + ' USD';

      currentTxn = '';
      methodSelect('cash');
      go(3);
    }

    function methodSelect(m) {
      method = m;
      const opts = {
        cash: 'optCash', card: 'optCard', paypal: 'optPaypal',
        google_pay: 'optGpay', apple_pay: 'optApplepay', online: 'optOnline'
      };
      const fields = {
        cash: 'cashFields', card: 'cardFields', paypal: 'paypalFields',
        google_pay: 'gpayFields', apple_pay: 'applepayFields', online: 'onlineFields'
      };

      Object.keys(opts).forEach(k => {
        const btn = document.getElementById(opts[k]);
        if (btn) btn.classList.toggle('selected', k === m);
        const fld = document.getElementById(fields[k]);
        if (fld) fld.classList.toggle('show', k === m);
      });

      live();
    }

    // Cash tenders
    function tenderExact() {
      if (!picked) return;
      cashReceived.value = (+picked.total).toFixed(2);
      live();
    }
    function tenderAdd(amount) {
      if (!picked) return;
      let cur = +cashReceived.value || 0;
      cashReceived.value = (cur + amount).toFixed(2);
      live();
    }

    // Card formatters
    function cardFormat(x) {
      let v = x.value.replace(/\D/g, '').slice(0, 19);
      x.value = v.replace(/(.{4})/g, '$1 ').trim();
    }
    function expiryFormat(x) {
      let v = x.value.replace(/\D/g, '').slice(0, 4);
      x.value = v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v;
    }
    function detectCardBrand(num) {
      num = num.replace(/\s+/g, '');
      if (/^4/.test(num)) return 'VISA';
      if (/^5[1-5]/.test(num)) return 'MASTERCARD';
      if (/^3[47]/.test(num)) return 'AMEX';
      if (/^(6011|65|64[4-9])/.test(num)) return 'DISCOVER';
      if (/^(?:2131|1800|35)/.test(num)) return 'JCB';
      return 'INTERNATIONAL CARD';
    }
    function cardLiveUpdate() {
      const num = cardNumber.value.trim();
      const name = cardName.value.trim();
      const exp = cardExpiry.value.trim();
      
      document.getElementById('prevNum').textContent = num || '•••• •••• •••• ••••';
      document.getElementById('prevName').textContent = (name || 'VALUED GUEST').toUpperCase();
      document.getElementById('prevExp').textContent = exp || 'MM/YY';
      document.getElementById('prevBrand').textContent = detectCardBrand(num);
    }

    function validCard() {
      return cardName.value.trim() &&
             cardNumber.value.replace(/\D/g, '').length >= 13 &&
             /^(0[1-9]|1[0-2])\/\d{2}$/.test(cardExpiry.value) &&
             /^\d{3,4}$/.test(cardCvv.value);
    }

    function simulateCardSwipe() {
      cardName.value = 'Johnathan Smith';
      cardNumber.value = '4532 8923 7102 9841';
      cardExpiry.value = '11/28';
      cardCvv.value = '824';
      cardLiveUpdate();
      currentTxn = 'CARD-AUTH-' + Math.floor(100000 + Math.random() * 900000);
      live();
      alert('💳 Contactless Chip / NFC Card read successfully!\nVerified via International Visa Gateway.');
    }

    // Real-time Gateway Simulator (PayPal, GPay, Apple Pay, Online)
    function triggerRealtimeGateway(gateway) {
      if (!picked) return;
      const modal = document.getElementById('rtModal');
      const icon = document.getElementById('rtIcon');
      const title = document.getElementById('rtTitle');
      const desc = document.getElementById('rtDesc');
      const spin = document.getElementById('rtSpinner');
      const succ = document.getElementById('rtSuccess');
      const ref = document.getElementById('rtRef');
      const btn = document.getElementById('rtDoneBtn');

      modal.classList.add('open');
      spin.style.display = 'block';
      succ.style.display = 'none';
      ref.style.display = 'none';
      btn.style.display = 'none';

      let prefix = 'TXN';
      if (gateway === 'paypal') {
        icon.textContent = '🅿️';
        title.textContent = 'Connecting to PayPal...';
        desc.textContent = 'Authorizing payment of ' + usd(picked.total) + ' USD via PayPal...';
        prefix = 'PAYID';
      } else if (gateway === 'google_pay') {
        icon.textContent = '🌐';
        title.textContent = 'Google Pay Authorization...';
        desc.textContent = 'Securing contactless token from Google Wallet...';
        prefix = 'GPAY';
      } else if (gateway === 'apple_pay') {
        icon.textContent = '🍏';
        title.textContent = 'Apple Pay Face ID / Touch ID...';
        desc.textContent = 'Authorizing with Apple Pay Secure Enclave...';
        prefix = 'APAY';
      } else {
        icon.textContent = '📱';
        title.textContent = 'Scanning Digital Wallet...';
        desc.textContent = 'Receiving real-time payment notification...';
        prefix = 'QR';
      }

      setTimeout(() => {
        title.textContent = 'Authenticating Guest Credentials...';
      }, 1000);

      setTimeout(() => {
        spin.style.display = 'none';
        succ.style.display = 'block';
        title.style.color = '#28a745';
        title.textContent = 'Payment Authorized & Received!';
        
        currentTxn = prefix + '-' + Math.floor(10000000 + Math.random() * 90000000);
        ref.textContent = 'Authorization Code: ' + currentTxn;
        ref.style.display = 'block';
        desc.textContent = rs(picked.total) + ' (' + usd(picked.total) + ' USD) confirmed in real-time.';
        btn.style.display = 'inline-block';

        // Update pills on main form
        if (gateway === 'paypal') {
          const p = document.getElementById('ppStatus');
          p.className = 'pay-status-pill success';
          p.textContent = '✅ Authorized via PayPal: ' + currentTxn;
        } else if (gateway === 'google_pay') {
          const p = document.getElementById('gpStatus');
          p.className = 'pay-status-pill success';
          p.textContent = '✅ Authorized via Google Pay: ' + currentTxn;
        } else if (gateway === 'apple_pay') {
          const p = document.getElementById('apStatus');
          p.className = 'pay-status-pill success';
          p.textContent = '✅ Authorized via Apple Pay: ' + currentTxn;
        } else {
          const p = document.getElementById('onStatus');
          p.className = 'pay-status-pill success';
          p.textContent = '✅ QR Payment Confirmed: ' + currentTxn;
        }
      }, 2200);
    }

    function closeRtModal() {
      document.getElementById('rtModal').classList.remove('open');
      review();
    }

    function live() {
      if (!picked) return;
      if (method === 'cash') {
        let v = +cashReceived.value || 0, d = +picked.total - v;
        cashNote.className = 'note ' + (v && d > 0 ? 'invalid' : (v >= +picked.total ? 'valid' : ''));
        cashNote.textContent = v >= +picked.total
          ? '✅ Change to return: ' + rs(v - +picked.total)
          : (v ? '⚠️ Need ' + rs(d) + ' more to complete payment' : 'Total payable: ' + rs(picked.total));
      } else if (method === 'card') {
        const ok = validCard();
        cardNote.className = 'note ' + (ok ? 'valid' : 'invalid');
        cardNote.textContent = ok
          ? '✅ Card details verified · Ready to charge ' + rs(picked.total)
          : 'Enter valid card holder name, 13-19 digit card number, MM/YY expiry and CVV.';
      }
    }

    function review() {
      if (!picked) return;
      if (method === 'cash') {
        let v = +cashReceived.value || 0;
        if (v < +picked.total) {
          return alert('Please enter cash received equal to or more than the bill total (' + rs(picked.total) + ').');
        }
      } else if (method === 'card') {
        if (!validCard()) {
          return alert('Please enter valid international card details before proceeding.');
        }
        if (!currentTxn) currentTxn = 'CARD-' + Math.floor(100000 + Math.random() * 900000);
      } else {
        if (!currentTxn) {
          triggerRealtimeGateway(method);
          return;
        }
      }

      let r = method === 'cash' ? +cashReceived.value : +picked.total;
      const titles = {
        cash: '💵 Cash (Local Currency)',
        card: '💳 International Card (' + detectCardBrand(cardNumber.value) + ')',
        paypal: '🅿️ PayPal (International)',
        google_pay: '🌐 Google Pay',
        apple_pay: '🍏 Apple Pay',
        online: '📱 Online / QR Wallet'
      };

      paymentSummary.innerHTML = `
        <div class="line"><span>Table</span><b>${esc(picked.table_number)}</b></div>
        <div class="line"><span>Order #</span><b>${esc(picked.order_number)}</b></div>
        <div class="line"><span>Customer</span><b>${esc(picked.customer_name)}</b></div>
        <div class="line"><span>Payment Method</span><b style="color:var(--primary);">${titles[method] || method}</b></div>
        <div class="line"><span>Bill Total</span><b>${rs(picked.total)}</b></div>
        <div class="line"><span>Foreign Equivalent</span><span><b>${usd(picked.total)} USD</b> &nbsp;·&nbsp; ${eur(picked.total)} EUR</span></div>
        <div class="line"><span>Amount Received</span><b>${rs(r)}</b></div>
        ${method === 'cash' ? `<div class="line total"><span>Change</span><span>${rs(Math.max(0, r - picked.total))}</span></div>` : ''}
        ${currentTxn ? `<div class="line"><span>Transaction / Auth Ref</span><code style="color:var(--primary);">${esc(currentTxn)}</code></div>` : ''}
      `;

      fId.value = picked.id;
      fMethod.value = method;
      fReceived.value = r;
      fNum.value = cardNumber.value;
      fExp.value = cardExpiry.value;
      fCvv.value = cardCvv.value;
      fTxn.value = currentTxn;

      go(5);
    }

    tables();
    setInterval(() => {
      if (document.querySelector('[data-p="1"]').classList.contains('active')) {
        location.reload();
      }
    }, 10000);
    </script>
  <?php endif; ?>
</main>
</body>
</html>
