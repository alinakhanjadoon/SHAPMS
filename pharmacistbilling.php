<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacist') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Connection failed: " . $conn->connect_error);

$user_id = (int)$_SESSION['user_id'];

/* ---------- FETCH PHARMACIST ID ---------- */
$ph_stmt = $conn->prepare("SELECT pharmacist_id FROM pharmacists WHERE user_id = ?");
$ph_stmt->bind_param("i", $user_id);
$ph_stmt->execute();
$ph_stmt->bind_result($pharmacist_id);
$ph_stmt->fetch();
$ph_stmt->close();

if (!$pharmacist_id) die("Pharmacist profile not found!");

/* ================= AJAX: MARK BILL AS PAID ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'mark_paid') {
    header('Content-Type: application/json');

    $bill_id = (int)($_POST['bill_id'] ?? 0);
    $method  = $_POST['payment_method'] ?? '';

    if ($bill_id <= 0 || !in_array($method, ['Cash', 'Card'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit();
    }

    $upd = $conn->prepare("
        UPDATE pharmacy_bills
        SET payment_status = 'paid', payment_method = ?, paid_at = NOW()
        WHERE bill_id = ? AND payment_status = 'unpaid'
    ");
    $upd->bind_param("si", $method, $bill_id);
    $upd->execute();
    $ok = $upd->affected_rows > 0;
    $upd->close();

    echo json_encode([
        'success' => $ok,
        'error'   => $ok ? null : 'Bill already paid or not found.',
        'method'  => $method
    ]);
    exit();
}

/* ================= PAGE DATA ================= */
$filter = $_GET['filter'] ?? 'unpaid'; // unpaid | paid | all
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
$types = '';

if ($filter === 'unpaid') $where[] = "b.payment_status = 'unpaid'";
elseif ($filter === 'paid') $where[] = "b.payment_status = 'paid'";
// 'all' -> no filter

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR b.bill_id = ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = is_numeric($search) ? intval($search) : 0;
    $types .= 'si';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        b.bill_id, b.prescription_id, b.patient_id, b.subtotal, b.discount_percent,
        b.discount_amount, b.total_amount, b.payment_status, b.payment_method,
        b.created_at, b.paid_at,
        u.full_name AS patient_name
    FROM pharmacy_bills b
    JOIN patients p ON b.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    $whereSql
    ORDER BY b.created_at DESC
";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch items for each bill
foreach ($bills as &$bill) {
    $iq = $conn->prepare("
        SELECT medicine_name, unit_price, quantity, subtotal
        FROM pharmacy_bill_items
        WHERE bill_id = ?
        ORDER BY bill_item_id ASC
    ");
    $iq->bind_param("i", $bill['bill_id']);
    $iq->execute();
    $bill['items'] = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
    $iq->close();
}
unset($bill);

/* Tab counts */
$counts = ['unpaid' => 0, 'paid' => 0, 'all' => 0];
$cr = $conn->query("SELECT payment_status, COUNT(*) c FROM pharmacy_bills GROUP BY payment_status");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $counts['all'] += $row['c'];
        if ($row['payment_status'] === 'unpaid') $counts['unpaid'] += $row['c'];
        if ($row['payment_status'] === 'paid')   $counts['paid']   += $row['c'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing | Pharmacist Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg: #faf5f8;
    --purple-deep: #6d28d9;
    --purple-mid: #8b5cf6;
    --purple-light: #ddd6fe;
    --purple-soft: #f3eefd;
    --rose: #e11d48;
    --rose-soft: #ffe4e9;
    --rose-mid: #fb7185;
    --text-dark: #241b3a;
    --text-mid: #5b5278;
    --text-light: #9c8fc0;
    --border: #ece4f7;
    --shadow: rgba(109,40,217,0.10);
    --ok: #10b981;
    --warn: #f59e0b;
    --danger: #e11d48;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DM Sans', sans-serif; background:var(--bg); color:var(--text-dark); min-height:100vh; }

header {
    background: rgba(255,255,255,.96); backdrop-filter: blur(12px);
    padding: 18px 28px; border-bottom: 1px solid var(--border); box-shadow: 0 4px 20px var(--shadow);
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;
}
header .logo-area { display:flex; align-items:center; gap:10px; }
header .logo-area i { font-size:26px; color:var(--purple-deep); }
header .logo-area span { font-family:'Playfair Display', serif; font-size:19px; font-weight:700; }
header a {
    background: linear-gradient(135deg, var(--purple-deep), var(--rose));
    color:#fff; padding:8px 20px; border-radius:40px; text-decoration:none; font-weight:600; font-size:13px;
    display:flex; align-items:center; gap:6px; box-shadow:0 2px 10px rgba(109,40,217,.3); transition:all .2s;
}
header a:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(109,40,217,.4); }

.container { max-width: 1100px; margin: 36px auto; padding: 0 24px; }

.tab-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
.tab-btn {
    display:flex; align-items:center; gap:7px; padding:9px 18px; border-radius:99px;
    border:1.5px solid var(--border); background:#fff; color:var(--text-mid); font-size:12.5px; font-weight:700;
    text-decoration:none; transition:all .15s;
}
.tab-btn:hover { border-color: var(--purple-light); color: var(--purple-deep); }
.tab-btn.active { background: linear-gradient(135deg, var(--purple-deep), var(--rose)); border-color:transparent; color:#fff; }
.tab-count { background:rgba(0,0,0,.08); padding:1px 8px; border-radius:99px; font-size:10.5px; }
.tab-btn.active .tab-count { background:rgba(255,255,255,.25); }

.search-row { margin-bottom: 22px; }
.search-input {
    width: 100%; max-width: 360px; padding: 10px 16px 10px 38px; border:1px solid var(--border); border-radius:12px;
    font-size:13px; font-family:inherit; outline:none;
    background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%236d28d9' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0'/%3E%3C/svg%3E") no-repeat 13px center;
}
.search-input:focus { border-color: var(--purple-mid); }

#ajaxFlash .flash { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:12px; font-size:12.5px; font-weight:600; margin-bottom:16px; }
.flash-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
.flash-danger  { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

.bill-card { background:#fff; border-radius:22px; border:1px solid var(--border); box-shadow:0 10px 28px var(--shadow); margin-bottom:22px; overflow:hidden; }
.bill-hdr {
    padding:18px 22px; background: linear-gradient(135deg, var(--purple-soft), rgba(139,92,246,.06));
    border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;
}
.bill-hdr-left { display:flex; align-items:center; gap:12px; }
.pat-av { width:40px; height:40px; border-radius:50%; background:var(--purple-light); display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; color:var(--purple-deep); flex-shrink:0; }
.bill-pat-name { font-size:14.5px; font-weight:700; }
.bill-meta { font-size:11px; color:var(--text-mid); margin-top:2px; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:99px; font-size:10.5px; font-weight:700; }
.b-unpaid { background:#fef3c7; color:#b45309; border:1px solid #fde68a; }
.b-paid   { background:#d1fae5; color:#047857; border:1px solid #a7f3d0; }

.bill-body { padding: 6px 22px 20px; }
.item-row {
    display:grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap:14px; align-items:center;
    padding: 12px 0; border-bottom: 1px dashed var(--border); font-size:12.5px;
}
.item-row.hdr-row { color:var(--text-light); font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border-bottom:1px solid var(--border); }
.item-row:last-child { border-bottom:none; }
.item-name { font-weight:600; color:var(--text-dark); }
.item-right { text-align:right; }

.totals-box { margin-top:14px; padding-top:14px; border-top:1.5px solid var(--border); display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
.totals-row { display:flex; justify-content:space-between; gap:40px; font-size:12.5px; color:var(--text-mid); width:260px; }
.totals-row.grand { font-size:15px; font-weight:700; color:var(--text-dark); padding-top:6px; border-top:1px dashed var(--border); }
.totals-row .disc { color:var(--rose); }

.bill-footer { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:16px; flex-wrap:wrap; }

.btn-mark {
    padding:9px 18px; border-radius:10px; border:none; cursor:pointer; font-family:inherit;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid)); color:#fff; font-size:12px; font-weight:700;
    display:inline-flex; align-items:center; gap:6px; transition:all .15s;
}
.btn-mark:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(109,40,217,.35); }

.method-choice { display:flex; gap:8px; align-items:center; }
.btn-method {
    padding:9px 16px; border-radius:10px; border:1.5px solid var(--border); cursor:pointer; font-family:inherit;
    background:#fff; color:var(--text-mid); font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; transition:all .15s;
}
.btn-method:hover { border-color: var(--purple-mid); color:var(--purple-deep); }
.btn-cancel { background:none; border:none; color:var(--text-light); font-size:11.5px; font-weight:600; cursor:pointer; text-decoration:underline; }

.paid-tag { display:inline-flex; align-items:center; gap:6px; color:var(--ok); font-size:12.5px; font-weight:700; }
.paid-tag .method-pill { background:#d1fae5; color:#047857; border:1px solid #a7f3d0; padding:2px 10px; border-radius:99px; font-size:10.5px; }

.empty-state { text-align:center; padding:60px 20px; background:#fff; border:1px solid var(--border); border-radius:22px; color:var(--text-light); }
.empty-state i { font-size:44px; margin-bottom:14px; display:block; opacity:.5; }
.empty-state p { font-size:15px; color:var(--text-mid); }

@media (max-width:700px) {
    .item-row { grid-template-columns: 1.5fr 1fr 1fr; font-size:11.5px; }
    .item-row span.hide-mobile { display:none; }
    .totals-row { width:100%; }
}
</style>
</head>
<body>

<header>
    <div class="logo-area">
        <i class="fas fa-mortar-pestle"></i>
        <span>SHAPMS Pharmacy</span>
    </div>
    <a href="pharmacistdashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</header>

<div class="container">

    <div class="tab-row">
        <a href="?filter=unpaid" class="tab-btn <?= $filter==='unpaid'?'active':'' ?>"><i class="fas fa-hourglass-half"></i> Unpaid <span class="tab-count"><?= $counts['unpaid'] ?></span></a>
        <a href="?filter=paid" class="tab-btn <?= $filter==='paid'?'active':'' ?>"><i class="fas fa-circle-check"></i> Paid <span class="tab-count"><?= $counts['paid'] ?></span></a>
        <a href="?filter=all" class="tab-btn <?= $filter==='all'?'active':'' ?>"><i class="fas fa-list"></i> All <span class="tab-count"><?= $counts['all'] ?></span></a>
    </div>

    <form method="GET" class="search-row">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="text" name="q" class="search-input" placeholder="Search patient name or bill #..." value="<?= htmlspecialchars($search) ?>">
    </form>

    <div id="ajaxFlash"></div>

    <?php if (empty($bills)): ?>
        <div class="empty-state">
            <i class="fas fa-file-invoice-dollar"></i>
            <p>No bills found for this filter.</p>
        </div>
    <?php else: ?>
        <?php foreach ($bills as $bill):
            $ini = strtoupper(substr($bill['patient_name'] ?? 'PA', 0, 2));
            $isPaid = $bill['payment_status'] === 'paid';
        ?>
        <div class="bill-card" id="bill-<?= $bill['bill_id'] ?>">
            <div class="bill-hdr">
                <div class="bill-hdr-left">
                    <div class="pat-av"><?= htmlspecialchars($ini) ?></div>
                    <div>
                        <div class="bill-pat-name"><?= htmlspecialchars($bill['patient_name']) ?></div>
                        <div class="bill-meta">
                            Bill #<?= $bill['bill_id'] ?> &middot;
                            Rx #<?= $bill['prescription_id'] ?> &middot;
                            <?= date('d M Y, h:i A', strtotime($bill['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <span class="badge <?= $isPaid ? 'b-paid' : 'b-unpaid' ?>" id="bill-badge-<?= $bill['bill_id'] ?>">
                    <?= $isPaid ? 'Paid' : 'Unpaid' ?>
                </span>
            </div>

            <div class="bill-body">
                <div class="item-row hdr-row">
                    <div>Medicine</div>
                    <div class="item-right">Unit Price</div>
                    <div class="item-right">Qty</div>
                    <div class="item-right">Subtotal</div>
                </div>
                <?php foreach ($bill['items'] as $it): ?>
                <div class="item-row">
                    <div class="item-name"><?= htmlspecialchars($it['medicine_name']) ?></div>
                    <div class="item-right">Rs. <?= number_format($it['unit_price'], 2) ?></div>
                    <div class="item-right"><?= $it['quantity'] ?></div>
                    <div class="item-right">Rs. <?= number_format($it['subtotal'], 2) ?></div>
                </div>
                <?php endforeach; ?>

                <div class="totals-box">
                    <div class="totals-row"><span>Subtotal</span><span>Rs. <?= number_format($bill['subtotal'], 2) ?></span></div>
                    <div class="totals-row"><span>Discount (<?= number_format($bill['discount_percent'], 0) ?>%)</span><span class="disc">− Rs. <?= number_format($bill['discount_amount'], 2) ?></span></div>
                    <div class="totals-row grand"><span>Total</span><span>Rs. <?= number_format($bill['total_amount'], 2) ?></span></div>
                </div>

                <div class="bill-footer" id="footer-<?= $bill['bill_id'] ?>">
                    <?php if ($isPaid): ?>
                        <span class="paid-tag">
                            <i class="fas fa-circle-check"></i> Paid via
                            <span class="method-pill"><?= htmlspecialchars($bill['payment_method']) ?></span>
                            on <?= date('d M Y, h:i A', strtotime($bill['paid_at'])) ?>
                        </span>
                    <?php else: ?>
                        <button class="btn-mark" onclick="showMethodChoice(<?= $bill['bill_id'] ?>)">
                            <i class="fas fa-money-check-dollar"></i> Mark as Paid
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
function showFlash(type, msg) {
    const box = document.getElementById('ajaxFlash');
    box.innerHTML = `<div class="flash flash-${type}"><i class="fas ${type==='success'?'fa-circle-check':'fa-circle-xmark'}"></i> ${msg}</div>`;
    setTimeout(() => { box.innerHTML = ''; }, 4500);
}

function showMethodChoice(billId) {
    const footer = document.getElementById('footer-' + billId);
    footer.innerHTML = `
        <div class="method-choice">
            <span style="font-size:12px;color:var(--text-mid);font-weight:600;">Payment method:</span>
            <button class="btn-method" onclick="markPaid(${billId}, 'Cash', this)"><i class="fas fa-money-bill-wave"></i> Cash</button>
            <button class="btn-method" onclick="markPaid(${billId}, 'Card', this)"><i class="fas fa-credit-card"></i> Card</button>
            <button class="btn-cancel" onclick="cancelMethodChoice(${billId})">Cancel</button>
        </div>
    `;
}

function cancelMethodChoice(billId) {
    const footer = document.getElementById('footer-' + billId);
    footer.innerHTML = `
        <button class="btn-mark" onclick="showMethodChoice(${billId})">
            <i class="fas fa-money-check-dollar"></i> Mark as Paid
        </button>
    `;
}

function markPaid(billId, method, btn) {
    const footer = document.getElementById('footer-' + billId);
    footer.innerHTML = '<span style="font-size:12px;color:var(--text-mid);"><i class="fas fa-spinner fa-spin"></i> Processing...</span>';

    const body = new URLSearchParams({ ajax_action: 'mark_paid', bill_id: billId, payment_method: method });

    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showFlash('danger', data.error || 'Could not mark as paid.');
                cancelMethodChoice(billId);
                return;
            }

            const badge = document.getElementById('bill-badge-' + billId);
            badge.textContent = 'Paid';
            badge.className = 'badge b-paid';

            const now = new Date();
            const formatted = now.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) +
                               ', ' + now.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit', hour12:true });

            footer.innerHTML = `
                <span class="paid-tag">
                    <i class="fas fa-circle-check"></i> Paid via
                    <span class="method-pill">${data.method}</span>
                    on ${formatted}
                </span>
            `;

            showFlash('success', 'Bill marked as paid.');
        })
        .catch(() => {
            showFlash('danger', 'Network error. Please try again.');
            cancelMethodChoice(billId);
        });
}
</script>

</body>
</html>