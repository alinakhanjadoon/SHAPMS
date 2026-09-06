<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

$doctor_user_id = (int)$_SESSION['user_id'];
$success = $error = "";

/* ---------- FETCH DOCTOR ID ---------- */
$doctor_stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
$doctor_stmt->bind_param("i", $doctor_user_id);
$doctor_stmt->execute();
$doctor_result = $doctor_stmt->get_result();
$doctor_row = $doctor_result->fetch_assoc();
$doctor_stmt->close();

$doctor_id = $doctor_row['doctor_id'] ?? 0;

if ($doctor_id <= 0) {
    die("Doctor not found in database!");
}

/* ---------- FETCH APPOINTMENTS + PATIENTS (for this doctor) ---------- */
$appt_stmt = $conn->prepare("
    SELECT a.appointment_id, a.patient_id, u.full_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    INNER JOIN users u ON p.user_id = u.user_id
    WHERE a.doctor_id = ?
    ORDER BY a.appointment_id DESC
");
$appt_stmt->bind_param("i", $doctor_id);
$appt_stmt->execute();
$appt_result = $appt_stmt->get_result();
$appointments = $appt_result->fetch_all(MYSQLI_ASSOC);
$appt_stmt->close();

/* ---------- FETCH MEDICINES (for the line-item picker) ---------- */
$med_result = $conn->query("SELECT medicine_id, name, quantity, price, controlled_drug FROM medicines ORDER BY name ASC");
$medicines = $med_result ? $med_result->fetch_all(MYSQLI_ASSOC) : [];

/* ---------- ADD PRESCRIPTION (multi-medicine) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $med_ids   = $_POST['medicine_id']  ?? [];
    $dosages   = $_POST['dosage']       ?? [];
    $freqs     = $_POST['frequency']    ?? [];
    $durations = $_POST['duration']     ?? [];
    $qtys      = $_POST['quantity']     ?? [];

    // Build a clean list of valid rows (medicine chosen + quantity > 0)
    $items = [];
    for ($i = 0; $i < count($med_ids); $i++) {
        $mid = (int)($med_ids[$i] ?? 0);
        $qty = (int)($qtys[$i] ?? 0);
        if ($mid > 0 && $qty > 0) {
            $items[] = [
                'medicine_id' => $mid,
                'dosage'      => trim($dosages[$i] ?? ''),
                'frequency'   => trim($freqs[$i] ?? ''),
                'duration'    => trim($durations[$i] ?? ''),
                'quantity'    => $qty,
            ];
        }
    }

    if ($appointment_id <= 0) {
        $error = "Please select an appointment!";
    } elseif (empty($items)) {
        $error = "Please add at least one medicine with a quantity!";
    } else {

        /* Confirm the appointment belongs to this doctor, get patient_id */
        $stmt = $conn->prepare("SELECT patient_id FROM appointments WHERE appointment_id = ? AND doctor_id = ?");
        $stmt->bind_param("ii", $appointment_id, $doctor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        $patient_id = $row['patient_id'] ?? 0;

        if ($patient_id <= 0) {
            $error = "Invalid appointment selected!";
        } else {

            // Build a human-readable summary for the legacy prescription_details column
            $medNameLookup = [];
            foreach ($medicines as $m) $medNameLookup[$m['medicine_id']] = $m['name'];

            $summaryLines = [];
            foreach ($items as $it) {
                $mname = $medNameLookup[$it['medicine_id']] ?? 'Medicine';
                $parts = [$mname];
                if ($it['dosage'])   $parts[] = $it['dosage'];
                if ($it['frequency']) $parts[] = $it['frequency'];
                if ($it['duration'])  $parts[] = $it['duration'];
                $parts[] = "Qty: " . $it['quantity'];
                $summaryLines[] = "- " . implode(' | ', $parts);
            }
            $summary = implode("\n", $summaryLines);

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO prescriptions (appointment_id, patient_id, prescription_details, status)
                    VALUES (?, ?, ?, 'pending')
                ");
                $stmt->bind_param("iis", $appointment_id, $patient_id, $summary);
                $stmt->execute();
                $prescription_id = $conn->insert_id;
                $stmt->close();

                $itemStmt = $conn->prepare("
                    INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ");
                foreach ($items as $it) {
                    $itemStmt->bind_param(
                        "iisssi",
                        $prescription_id,
                        $it['medicine_id'],
                        $it['dosage'],
                        $it['frequency'],
                        $it['duration'],
                        $it['quantity']
                    );
                    $itemStmt->execute();
                }
                $itemStmt->close();

                $conn->commit();
                $success = "Prescription added successfully with " . count($items) . " medicine(s)! Sent to pharmacy.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to add prescription. Please try again.";
            }
        }
    }
}

// Get doctor name for header
$doc_name = "Doctor";
$name_stmt = $conn->prepare("SELECT u.full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
$name_stmt->bind_param("i", $doctor_user_id);
$name_stmt->execute();
$name_result = $name_stmt->get_result();
if ($name_result && $row = $name_result->fetch_assoc()) {
    $doc_name = $row['full_name'];
}
$name_stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Add Prescription | Doctor Dashboard</title>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
:root{
    --b1: #0a0f2a; --b2: #11163d; --b3: #1a237e; --b4: #283593;
    --b5: #3949ab; --b6: #5c6bc0; --b7: #7986cb; --b8: #9fa8da; --b9: #c5cae9;
    --acc: #7e57c2; --acc2: #b39ddb; --acc3: #9575cd;
    --glow: rgba(57,73,171,.6);
    --gb: rgba(255,255,255,.09);
    --txt: #e8eaf6; --mut: #9fa8da; --r: 20px;
    --stock-ok: #4ade80; --stock-low: #fbbf24; --stock-out: #f87171;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Outfit',sans-serif;
    background:var(--b1);
    color:var(--txt);
    min-height:100vh;
    position:relative;
}
body::before{
    content:'';
    position:fixed;inset:0;
    background:
        radial-gradient(ellipse 90% 70% at 10% 10%, rgba(57,73,171,.28) 0%,transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%, rgba(126,87,194,.22) 0%,transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 45%, rgba(26,35,126,.5) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
body::after{
    content:'';
    position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.018) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.018) 1px,transparent 1px);
    background-size:52px 52px;
    pointer-events:none;z-index:0;
}

header {
    position:relative; z-index:2;
    background:linear-gradient(120deg, rgba(26,35,126,.92) 0%, rgba(57,73,171,.85) 100%);
    backdrop-filter:blur(12px);
    color:#fff; padding:18px 28px; text-align:center; font-size:1.5rem; font-weight:700;
    border-bottom:1px solid var(--gb);
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;
}
header .logo-area { display:flex; align-items:center; gap:12px; }
header .logo-area i { font-size:28px; color:var(--acc2); }
header a {
    background:rgba(239,68,68,.2); color:#fca5a5; padding:8px 20px; border-radius:40px;
    text-decoration:none; font-weight:600; font-size:14px; transition:all .2s; border:1px solid rgba(239,68,68,.3);
}
header a:hover { background:rgba(239,68,68,.35); color:#fff; transform:translateY(-1px); }

.container {
    position:relative; z-index:2; max-width:720px; margin:50px auto;
    background:rgba(255,255,255,.04); backdrop-filter:blur(22px);
    border:1px solid var(--gb); border-radius:var(--r); padding:35px 40px;
    box-shadow:0 25px 50px -12px rgba(0,0,0,.4);
}

.form-title { text-align:center; margin-bottom:28px; }
.form-title h2 {
    font-size:28px; font-weight:700;
    background:linear-gradient(135deg, #fff, var(--b8));
    background-clip:text; -webkit-background-clip:text; color:transparent;
    display:inline-flex; align-items:center; gap:10px;
}
.form-title p { font-size:13px; color:var(--mut); margin-top:6px; }

form label {
    display:block; margin-top:18px; font-weight:600; font-size:13px; color:var(--b8);
    letter-spacing:.03em; text-transform:uppercase;
}
form label i { margin-right:8px; color:var(--acc); font-size:12px; }

form select, form textarea, form input[type="text"], form input[type="number"] {
    width:100%; padding:12px 16px; margin-top:6px; border-radius:16px;
    border:1.5px solid var(--gb); background:rgba(255,255,255,.07);
    font-family:'Outfit',sans-serif; font-size:14px; font-weight:500; color:#fff;
    transition:all .2s; outline:none;
}
form select { cursor:pointer; appearance:none;
    background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%239fa8da" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    background-repeat:no-repeat; background-position:right 16px center;
}
form select option { background:var(--b2); color:#fff; }
form select:focus, form textarea:focus, form input:focus {
    border-color:var(--b6); background:rgba(255,255,255,.12); box-shadow:0 0 0 4px rgba(92,107,192,.2);
}

form input[type="submit"] {
    width:100%; padding:14px; margin-top:28px; border:none; border-radius:40px;
    background:linear-gradient(105deg, var(--b5), var(--acc)); color:#fff;
    font-size:15px; font-weight:700; cursor:pointer; transition:all .25s;
    display:flex; align-items:center; justify-content:center; gap:8px;
    font-family:'Outfit',sans-serif; box-shadow:0 6px 18px rgba(57,73,171,.35);
}
form input[type="submit"]:hover { transform:translateY(-2px); box-shadow:0 12px 28px rgba(57,73,171,.5); }
form input[type="submit"]:active { transform:translateY(1px); }

.success, .error {
    padding:12px 18px; border-radius:40px; margin-bottom:20px; text-align:center;
    font-weight:600; font-size:13px; display:flex; align-items:center; justify-content:center; gap:8px;
    white-space:pre-line;
}
.success { background:rgba(74,222,128,.18); color:#4ade80; border:1px solid rgba(74,222,128,.3); }
.error { background:rgba(248,113,113,.18); color:#f87171; border:1px solid rgba(248,113,113,.3); }

.doc-badge {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid var(--gb);
}
.doc-info { display:flex; align-items:center; gap:12px; }
.doc-avatar {
    width:44px; height:44px; border-radius:14px;
    background:linear-gradient(135deg, var(--b5), var(--acc));
    display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff;
}
.doc-details h4 { font-size:16px; font-weight:700; color:#fff; }
.doc-details span { font-size:11px; color:var(--mut); }

/* ── MEDICINE ITEM ROWS ── */
.items-wrap { margin-top:18px; display:flex; flex-direction:column; gap:14px; }
.item-row {
    position:relative; padding:16px 18px 14px; border:1.5px solid var(--gb);
    border-radius:16px; background:rgba(255,255,255,.03);
}
.item-row-top { display:grid; grid-template-columns: 2fr 1fr; gap:10px; }
.item-row-bottom { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-top:10px; }
.item-row label { margin-top:0; font-size:10.5px; }
.item-row select, .item-row input { margin-top:4px; padding:9px 12px; border-radius:12px; font-size:13px; }
.stock-hint { font-size:10.5px; margin-top:5px; font-weight:600; }
.stock-hint.ok  { color: var(--stock-ok); }
.stock-hint.low { color: var(--stock-low); }
.stock-hint.out { color: var(--stock-out); }
.remove-item-btn {
    position:absolute; top:10px; right:10px; width:26px; height:26px; border-radius:50%;
    background:rgba(248,113,113,.15); border:1px solid rgba(248,113,113,.3); color:#f87171;
    cursor:pointer; font-size:12px; display:flex; align-items:center; justify-content:center;
}
.remove-item-btn:hover { background:rgba(248,113,113,.3); }

.add-item-btn {
    margin-top:14px; width:100%; padding:11px; border-radius:14px; cursor:pointer;
    background:rgba(126,87,194,.15); border:1.5px dashed var(--acc2); color:var(--acc2);
    font-family:'Outfit',sans-serif; font-weight:700; font-size:13px;
    display:flex; align-items:center; justify-content:center; gap:8px; transition:all .2s;
}
.add-item-btn:hover { background:rgba(126,87,194,.28); }

.controlled-tag {
    display:inline-flex; align-items:center; gap:4px; font-size:9.5px; font-weight:700;
    color:#fbbf24; background:rgba(251,191,36,.15); border:1px solid rgba(251,191,36,.3);
    padding:2px 8px; border-radius:99px; margin-left:6px; vertical-align:middle;
}

@media (max-width: 640px) {
    .container { margin:30px 20px; padding:25px 20px; }
    header { flex-direction:column; text-align:center; }
    .form-title h2 { font-size:24px; }
    .item-row-top, .item-row-bottom { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<header>
    <div class="logo-area">
        <i class="fa-solid fa-prescription-bottle-medical"></i>
        <span>Zaman Medical Hospital</span>
    </div>
    <a href="doctordashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
</header>

<div class="container">

    <div class="form-title">
        <h2><i class="fa-solid fa-file-prescription"></i> Add Prescription</h2>
        <p><i class="fa-regular fa-note-sticky"></i> Add one or more medicines — sent directly to pharmacy</p>
    </div>

    <div class="doc-badge">
        <div class="doc-info">
            <div class="doc-avatar"><i class="fa-solid fa-user-md"></i></div>
            <div class="doc-details">
                <h4>Dr. <?= htmlspecialchars($doc_name) ?></h4>
                <span><i class="fa-regular fa-clock"></i> Today, <?= date('d M Y') ?></span>
            </div>
        </div>
        <i class="fa-solid fa-stethoscope" style="color:var(--acc2); font-size:24px;"></i>
    </div>

    <?php if($success): ?>
        <div class="success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="prescriptionForm">

        <label><i class="fa-solid fa-user"></i> Select Patient (Appointment)</label>
        <select name="appointment_id" required>
            <option value="">-- Select Appointment --</option>
            <?php if (!empty($appointments)): ?>
                <?php foreach ($appointments as $row): ?>
                    <option value="<?= $row['appointment_id'] ?>">
                        📋 <?= htmlspecialchars($row['full_name']) ?> (Appt #<?= $row['appointment_id'] ?>)
                    </option>
                <?php endforeach; ?>
            <?php else: ?>
                <option value="">No appointments found</option>
            <?php endif; ?>
        </select>

        <label style="margin-top:24px;"><i class="fa-solid fa-pills"></i> Medicines</label>
        <div class="items-wrap" id="itemsWrap"></div>

        <button type="button" class="add-item-btn" id="addItemBtn">
            <i class="fa-solid fa-plus"></i> Add Medicine
        </button>

        <input type="submit" value="Add Prescription">

        <div style="text-align:center; margin-top:16px; font-size:11px; color:var(--mut);">
            <i class="fa-solid fa-lock"></i> Prescription will be sent to pharmacy for dispensing
        </div>
    </form>
</div>

<script>
const medicines = <?= json_encode($medicines) ?>;
let rowCount = 0;

function stockClass(qty, min) {
    if (qty <= 0) return 'out';
    if (qty <= min) return 'low';
    return 'ok';
}
function stockText(qty, min) {
    if (qty <= 0) return 'Out of stock';
    if (qty <= min) return `Low stock: ${qty} left`;
    return `In stock: ${qty} available`;
}

function buildMedicineOptions(selectedId) {
    let opts = '<option value="">-- Select Medicine --</option>';
    medicines.forEach(m => {
        const sel = (selectedId == m.medicine_id) ? 'selected' : '';
        const tag = m.controlled_drug == 1 ? ' [Controlled]' : '';
        opts += `<option value="${m.medicine_id}" data-qty="${m.quantity}" data-min="10" ${sel}>${m.name}${tag} — Rs.${m.price}</option>`;
    });
    return opts;
}

function addItemRow() {
    rowCount++;
    const id = rowCount;
    const wrap = document.getElementById('itemsWrap');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.id = 'item-' + id;
    row.innerHTML = `
        <button type="button" class="remove-item-btn" onclick="removeItemRow(${id})"><i class="fa-solid fa-xmark"></i></button>
        <div class="item-row-top">
            <div>
                <label><i class="fa-solid fa-capsules"></i> Medicine</label>
                <select name="medicine_id[]" class="med-select" onchange="updateStockHint(${id})" required>
                    ${buildMedicineOptions(null)}
                </select>
                <div class="stock-hint" id="stockHint-${id}"></div>
            </div>
            <div>
                <label><i class="fa-solid fa-cubes"></i> Quantity</label>
                <input type="number" name="quantity[]" min="1" value="1" required>
            </div>
        </div>
        <div class="item-row-bottom">
            <div>
                <label><i class="fa-solid fa-syringe"></i> Dosage</label>
                <input type="text" name="dosage[]" placeholder="e.g. 500mg">
            </div>
            <div>
                <label><i class="fa-solid fa-repeat"></i> Frequency</label>
                <input type="text" name="frequency[]" placeholder="e.g. Twice daily">
            </div>
            <div>
                <label><i class="fa-solid fa-calendar-days"></i> Duration</label>
                <input type="text" name="duration[]" placeholder="e.g. 5 days">
            </div>
        </div>
    `;
    wrap.appendChild(row);
}

function removeItemRow(id) {
    const row = document.getElementById('item-' + id);
    if (row) row.remove();
    if (document.getElementById('itemsWrap').children.length === 0) addItemRow();
}

function updateStockHint(id) {
    const row = document.getElementById('item-' + id);
    const select = row.querySelector('.med-select');
    const opt = select.options[select.selectedIndex];
    const hint = document.getElementById('stockHint-' + id);
    if (!opt || !opt.value) { hint.textContent = ''; return; }
    const qty = parseInt(opt.dataset.qty);
    const min = parseInt(opt.dataset.min);
    hint.className = 'stock-hint ' + stockClass(qty, min);
    hint.textContent = stockText(qty, min);
}

document.getElementById('addItemBtn').addEventListener('click', addItemRow);
addItemRow(); // start with one row
</script>

</body>
</html>