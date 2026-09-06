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

/* ================= AJAX: DISPENSE A MEDICINE ITEM ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'dispense_item') {
    header('Content-Type: application/json');

    $item_id = (int)($_POST['item_id'] ?? 0);
    if ($item_id <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid item.']); exit(); }

    // Get the item + how much is needed
    $iq = $conn->prepare("SELECT prescription_id, medicine_id, quantity, status FROM prescription_items WHERE item_id = ?");
    $iq->bind_param("i", $item_id);
    $iq->execute();
    $item = $iq->get_result()->fetch_assoc();
    $iq->close();

    if (!$item) { echo json_encode(['success'=>false,'error'=>'Item not found.']); exit(); }
    if ($item['status'] === 'dispensed') { echo json_encode(['success'=>false,'error'=>'Already dispensed.']); exit(); }

    $needed = (int)$item['quantity'];
    $medicine_id = (int)$item['medicine_id'];
    $prescription_id = (int)$item['prescription_id'];

    // Atomic stock deduction: only succeeds if enough stock exists
    $upd = $conn->prepare("UPDATE medicines SET quantity = quantity - ? WHERE medicine_id = ? AND quantity >= ?");
    $upd->bind_param("iii", $needed, $medicine_id, $needed);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        // Not enough stock — fetch current stock to show pharmacist
        $sq = $conn->prepare("SELECT quantity FROM medicines WHERE medicine_id = ?");
        $sq->bind_param("i", $medicine_id);
        $sq->execute();
        $sq->bind_result($currentStock);
        $sq->fetch();
        $sq->close();

        $mstmt = $conn->prepare("UPDATE prescription_items SET status = 'out_of_stock' WHERE item_id = ?");
        $mstmt->bind_param("i", $item_id);
        $mstmt->execute();
        $mstmt->close();

        echo json_encode(['success'=>false, 'error'=>"Insufficient stock. Only $currentStock left, $needed needed.", 'item_status'=>'out_of_stock']);
        exit();
    }

    // Mark item dispensed
    $dstmt = $conn->prepare("UPDATE prescription_items SET status='dispensed', dispensed_by=?, dispensed_at=NOW() WHERE item_id=?");
    $dstmt->bind_param("ii", $pharmacist_id, $item_id);
    $dstmt->execute();
    $dstmt->close();

    // Get remaining stock to show in UI
    $sq = $conn->prepare("SELECT quantity FROM medicines WHERE medicine_id = ?");
    $sq->bind_param("i", $medicine_id);
    $sq->execute();
    $sq->bind_result($remainingStock);
    $sq->fetch();
    $sq->close();

    // If ALL items on this prescription are now dispensed, mark whole prescription dispensed
    $cq = $conn->prepare("SELECT COUNT(*) total, SUM(status='dispensed') done FROM prescription_items WHERE prescription_id = ?");
    $cq->bind_param("i", $prescription_id);
    $cq->execute();
    $cq->bind_result($total, $done);
    $cq->fetch();
    $cq->close();

   $prescriptionFullyDispensed = ($total > 0 && (int)$done === (int)$total);
    $bill_id = null;

    if ($prescriptionFullyDispensed) {
        $pstmt = $conn->prepare("UPDATE prescriptions SET status='dispensed', pharmacist_id=? WHERE prescription_id=?");
        $pstmt->bind_param("ii", $pharmacist_id, $prescription_id);
        $pstmt->execute();
        $pstmt->close();

        /* ---- Auto-generate bill (only if one doesn't already exist for this prescription) ---- */
        $bq = $conn->prepare("SELECT bill_id FROM pharmacy_bills WHERE prescription_id = ?");
        $bq->bind_param("i", $prescription_id);
        $bq->execute();
        $bq->bind_result($existingBillId);
        $bq->fetch();
        $bq->close();

        if ($existingBillId) {
            $bill_id = $existingBillId;
        } else {
            // Patient for this prescription
            $ppq = $conn->prepare("SELECT patient_id FROM prescriptions WHERE prescription_id = ?");
            $ppq->bind_param("i", $prescription_id);
            $ppq->execute();
            $ppq->bind_result($billPatientId);
            $ppq->fetch();
            $ppq->close();

            // All items on this prescription with medicine price
            $itq = $conn->prepare("
                SELECT pi.medicine_id, pi.quantity, m.name, m.price
                FROM prescription_items pi
                JOIN medicines m ON pi.medicine_id = m.medicine_id
                WHERE pi.prescription_id = ?
            ");
            $itq->bind_param("i", $prescription_id);
            $itq->execute();
            $billItems = $itq->get_result()->fetch_all(MYSQLI_ASSOC);
            $itq->close();

            $subtotal = 0;
            foreach ($billItems as $bi) {
                $subtotal += $bi['quantity'] * $bi['price'];
            }
            $discountPercent = 10.00;
            $discountAmount  = round($subtotal * ($discountPercent / 100), 2);
            $totalAmount     = round($subtotal - $discountAmount, 2);

            $insBill = $conn->prepare("
                INSERT INTO pharmacy_bills
                    (prescription_id, patient_id, pharmacist_id, subtotal, discount_percent, discount_amount, total_amount)
                VALUES (?,?,?,?,?,?,?)
            ");
            $insBill->bind_param(
                "iiidddd",
                $prescription_id, $billPatientId, $pharmacist_id,
                $subtotal, $discountPercent, $discountAmount, $totalAmount
            );
            $insBill->execute();
            $bill_id = $conn->insert_id;
            $insBill->close();

            $insItem = $conn->prepare("
                INSERT INTO pharmacy_bill_items
                    (bill_id, medicine_id, medicine_name, unit_price, quantity, subtotal)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($billItems as $bi) {
                $lineSubtotal = $bi['quantity'] * $bi['price'];
                $insItem->bind_param(
                    "iisdid",
                    $bill_id, $bi['medicine_id'], $bi['name'], $bi['price'], $bi['quantity'], $lineSubtotal
                );
                $insItem->execute();
            }
            $insItem->close();
        }
    }

    echo json_encode([
        'success' => true,
        'remaining_stock' => $remainingStock,
        'prescription_fully_dispensed' => $prescriptionFullyDispensed,
        'bill_id' => $bill_id
    ]);
    exit();
}
/* ================= PAGE DATA ================= */
$filter = $_GET['filter'] ?? 'pending'; // pending | dispensed | all
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
$types = '';

if ($filter === 'pending')   $where[] = "pr.status = 'pending'";
elseif ($filter === 'dispensed') $where[] = "pr.status = 'dispensed'";
// 'all' -> no filter

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR pr.prescription_id = ? OR pr.patient_id = ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = is_numeric($search) ? intval($search) : 0; $params[] = is_numeric($search) ? intval($search) : 0;
    $types .= 'sii';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        pr.prescription_id, pr.patient_id, pr.status, pr.created_at,
        u.full_name AS patient_name, u.email AS patient_email,
        du.full_name AS doctor_name,
        a.appointment_date
    FROM prescriptions pr
    JOIN patients p ON pr.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN appointments a ON pr.appointment_id = a.appointment_id
    LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN users du ON d.user_id = du.user_id
    $whereSql
    ORDER BY pr.created_at DESC
";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch items for each prescription
foreach ($prescriptions as &$presc) {
    $iq = $conn->prepare("
        SELECT pi.item_id, pi.medicine_id, pi.dosage, pi.frequency, pi.duration, pi.quantity, pi.status,
               m.name AS medicine_name, m.quantity AS stock_qty, m.controlled_drug
        FROM prescription_items pi
        JOIN medicines m ON pi.medicine_id = m.medicine_id
        WHERE pi.prescription_id = ?
        ORDER BY pi.item_id ASC
    ");
    $iq->bind_param("i", $presc['prescription_id']);
    $iq->execute();
    $presc['items'] = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
    $iq->close();
}
unset($presc);

/* Tab counts */
$counts = ['pending' => 0, 'dispensed' => 0, 'all' => 0];
$cr = $conn->query("SELECT status, COUNT(*) c FROM prescriptions GROUP BY status");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $counts['all'] += $row['c'];
        if ($row['status'] === 'pending')   $counts['pending']   += $row['c'];
        if ($row['status'] === 'dispensed') $counts['dispensed'] += $row['c'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescriptions | Pharmacist Dashboard</title>
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

.presc-card { background:#fff; border-radius:22px; border:1px solid var(--border); box-shadow:0 10px 28px var(--shadow); margin-bottom:22px; overflow:hidden; }
.presc-hdr {
    padding:18px 22px; background: linear-gradient(135deg, var(--purple-soft), rgba(139,92,246,.06));
    border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;
}
.presc-hdr-left { display:flex; align-items:center; gap:12px; }
.pat-av { width:40px; height:40px; border-radius:50%; background:var(--purple-light); display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; color:var(--purple-deep); flex-shrink:0; }
.presc-pat-name { font-size:14.5px; font-weight:700; }
.presc-meta { font-size:11px; color:var(--text-mid); margin-top:2px; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:99px; font-size:10.5px; font-weight:700; }
.b-pending   { background:#fef3c7; color:#b45309; border:1px solid #fde68a; }
.b-dispensed { background:#d1fae5; color:#047857; border:1px solid #a7f3d0; }
.b-out       { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }

.presc-body { padding: 8px 22px 20px; }
.item-row {
    display:grid; grid-template-columns: 2fr 1.4fr 1fr 1fr; gap:14px; align-items:center;
    padding: 14px 0; border-bottom: 1px dashed var(--border);
}
.item-row:last-child { border-bottom:none; }
.item-med { display:flex; flex-direction:column; gap:3px; }
.item-med-name { font-size:13px; font-weight:700; color:var(--text-dark); }
.item-med-name .ctrl-tag { font-size:9px; font-weight:700; color:#b45309; background:#fef3c7; border:1px solid #fde68a; padding:1px 7px; border-radius:99px; margin-left:6px; }
.item-dosage { font-size:11px; color:var(--text-mid); }
.item-qty { font-size:12.5px; font-weight:600; color:var(--text-dark); }
.item-qty small { display:block; font-size:9.5px; color:var(--text-light); font-weight:600; text-transform:uppercase; }
.item-stock { font-size:12px; font-weight:700; }
.stock-ok   { color: var(--ok); }
.stock-low  { color: var(--warn); }
.stock-none { color: var(--danger); }
.item-action { text-align:right; }

.btn-dispense {
    padding:8px 16px; border-radius:10px; border:none; cursor:pointer; font-family:inherit;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid)); color:#fff; font-size:11.5px; font-weight:700;
    display:inline-flex; align-items:center; gap:6px; transition:all .15s;
}
.btn-dispense:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(109,40,217,.35); }
.btn-dispense:disabled { opacity:.6; cursor:not-allowed; transform:none; box-shadow:none; }
.done-tag { display:inline-flex; align-items:center; gap:5px; color:var(--ok); font-size:11.5px; font-weight:700; }

.empty-state { text-align:center; padding:60px 20px; background:#fff; border:1px solid var(--border); border-radius:22px; color:var(--text-light); }
.empty-state i { font-size:44px; margin-bottom:14px; display:block; opacity:.5; }
.empty-state p { font-size:15px; color:var(--text-mid); }

@media (max-width:700px) {
    .item-row { grid-template-columns: 1fr; gap:6px; }
    .item-action { text-align:left; margin-top:6px; }
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
        <a href="?filter=pending" class="tab-btn <?= $filter==='pending'?'active':'' ?>"><i class="fas fa-hourglass-half"></i> Pending <span class="tab-count"><?= $counts['pending'] ?></span></a>
        <a href="?filter=dispensed" class="tab-btn <?= $filter==='dispensed'?'active':'' ?>"><i class="fas fa-circle-check"></i> Dispensed <span class="tab-count"><?= $counts['dispensed'] ?></span></a>
        <a href="?filter=all" class="tab-btn <?= $filter==='all'?'active':'' ?>"><i class="fas fa-list"></i> All <span class="tab-count"><?= $counts['all'] ?></span></a>
    </div>

    <form method="GET" class="search-row">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="text" name="q" class="search-input" placeholder="Search patient name or prescription #..." value="<?= htmlspecialchars($search) ?>">
    </form>

    <div id="ajaxFlash"></div>

    <?php if (empty($prescriptions)): ?>
        <div class="empty-state">
            <i class="fas fa-prescription-bottle-medical"></i>
            <p>No prescriptions found for this filter.</p>
        </div>
    <?php else: ?>
        <?php foreach ($prescriptions as $presc):
            $ini = strtoupper(substr($presc['patient_name'] ?? 'PA', 0, 2));
            $pBadge = $presc['status'] === 'dispensed' ? 'b-dispensed' : 'b-pending';
        ?>
        <div class="presc-card" id="presc-<?= $presc['prescription_id'] ?>">
            <div class="presc-hdr">
                <div class="presc-hdr-left">
                    <div class="pat-av"><?= htmlspecialchars($ini) ?></div>
                    <div>
                        <div class="presc-pat-name"><?= htmlspecialchars($presc['patient_name']) ?> <span style="font-weight:500;color:var(--text-light);font-size:11px;">(Patient ID: <?= $presc['patient_id'] ?>)</span></div>
                        <div class="presc-meta">
                            Rx #<?= $presc['prescription_id'] ?> &middot;
                            Dr. <?= htmlspecialchars($presc['doctor_name'] ?? '—') ?> &middot;
                            <?= date('d M Y', strtotime($presc['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <span class="badge <?= $pBadge ?>" id="presc-badge-<?= $presc['prescription_id'] ?>">
                    <?= $presc['status'] === 'dispensed' ? 'Fully Dispensed' : 'Pending' ?>
                </span>
            </div>

            <div class="presc-body">
                <?php foreach ($presc['items'] as $item):
                    $stock = (int)$item['stock_qty'];
                    $stockClass = $stock <= 0 ? 'stock-none' : ($stock <= 10 ? 'stock-low' : 'stock-ok');
                    $isDispensed = $item['status'] === 'dispensed';
                ?>
                <div class="item-row" id="item-<?= $item['item_id'] ?>">
                    <div class="item-med">
                        <div class="item-med-name">
                            <?= htmlspecialchars($item['medicine_name']) ?>
                            <?php if ($item['controlled_drug']): ?><span class="ctrl-tag">Controlled</span><?php endif; ?>
                        </div>
                        <div class="item-dosage">
                            <?= htmlspecialchars($item['dosage'] ?: '—') ?>
                            <?= $item['frequency'] ? ' · ' . htmlspecialchars($item['frequency']) : '' ?>
                            <?= $item['duration'] ? ' · ' . htmlspecialchars($item['duration']) : '' ?>
                        </div>
                    </div>
                    <div class="item-qty"><small>Requested</small><?= $item['quantity'] ?> unit(s)</div>
                    <div class="item-stock <?= $stockClass ?>" id="stock-<?= $item['item_id'] ?>">
                        <?= $stock ?> in stock
                    </div>
                    <div class="item-action" id="action-<?= $item['item_id'] ?>">
                        <?php if ($isDispensed): ?>
                            <span class="done-tag"><i class="fas fa-check-circle"></i> Dispensed</span>
                        <?php else: ?>
                            <button class="btn-dispense" onclick="dispenseItem(<?= $item['item_id'] ?>, <?= $presc['prescription_id'] ?>, this)">
                                <i class="fas fa-hand-holding-medical"></i> Dispense
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
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

function dispenseItem(itemId, prescriptionId, btn) {
    if (!confirm('Confirm this medicine has been handed to the patient?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Dispensing...';

    const body = new URLSearchParams({ ajax_action: 'dispense_item', item_id: itemId });

    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showFlash('danger', data.error || 'Could not dispense.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-hand-holding-medical"></i> Dispense';
                if (data.item_status === 'out_of_stock') {
                    document.getElementById('action-' + itemId).innerHTML =
                        '<span style="color:var(--danger);font-size:11.5px;font-weight:700;"><i class="fas fa-triangle-exclamation"></i> Out of stock</span>';
                }
                return;
            }

            document.getElementById('action-' + itemId).innerHTML =
                '<span class="done-tag"><i class="fas fa-check-circle"></i> Dispensed</span>';

            const stockEl = document.getElementById('stock-' + itemId);
            stockEl.textContent = data.remaining_stock + ' in stock';
            stockEl.className = 'item-stock ' + (data.remaining_stock <= 0 ? 'stock-none' : (data.remaining_stock <= 10 ? 'stock-low' : 'stock-ok'));

            showFlash('success', 'Medicine dispensed and stock updated.');

            if (data.prescription_fully_dispensed) {
                const badge = document.getElementById('presc-badge-' + prescriptionId);
                badge.textContent = 'Fully Dispensed';
                badge.className = 'badge b-dispensed';
            }
        })
        .catch(() => {
            showFlash('danger', 'Network error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-hand-holding-medical"></i> Dispense';
        });
}
</script>

</body>
</html>