<?php
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error");

$doctor_user_id = (int)$_SESSION['user_id'];

$patientQuery = "
SELECT DISTINCT
    p.patient_id,
    u.full_name,
    p.age,
    p.gender,
    p.contact,
    p.address
FROM appointments a
JOIN doctors d ON a.doctor_id = d.doctor_id
JOIN patients p ON a.patient_id = p.patient_id
JOIN users u ON p.user_id = u.user_id
WHERE d.user_id = ?
ORDER BY u.full_name
";

$stmt = $conn->prepare($patientQuery);
$stmt->bind_param("i", $doctor_user_id);
$stmt->execute();
$result = $stmt->get_result();

/* BUILD PATIENT LIST */
$patients = [];
while ($row = $result->fetch_assoc()) {
    $patients[$row['patient_id']] = [
        'info' => $row,
        'records' => []
    ];
}
$stmt->close();

/* FETCH MEDICAL RECORDS SEPARATELY (avoids join multiplication) */
if (!empty($patients)) {
    $patientIds = array_keys($patients);
    $placeholders = implode(',', array_fill(0, count($patientIds), '?'));
    $types = str_repeat('i', count($patientIds));

    $recordQuery = "
        SELECT patient_id, diagnosis, lab_reports, notes, created_at
        FROM medical_records
        WHERE patient_id IN ($placeholders)
        AND (
            (diagnosis IS NOT NULL AND diagnosis != '')
            OR (lab_reports IS NOT NULL AND lab_reports != '')
            OR (notes IS NOT NULL AND notes != '')
        )
        ORDER BY created_at DESC
    ";
    $recStmt = $conn->prepare($recordQuery);
    $recStmt->bind_param($types, ...$patientIds);
    $recStmt->execute();
    $recResult = $recStmt->get_result();

    while ($rec = $recResult->fetch_assoc()) {
        $patients[$rec['patient_id']]['records'][] = $rec;
    }
    $recStmt->close();
}

// Get doctor name
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
<title>Patient Records | Doctor Dashboard</title>

<!-- Google Fonts + Font Awesome -->
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
:root{
    /* Two shades of blue + purplish blue theme */
    --b1: #0a0f2a;
    --b2: #11163d;
    --b3: #1a237e;
    --b4: #283593;
    --b5: #3949ab;
    --b6: #5c6bc0;
    --b7: #7986cb;
    --b8: #9fa8da;
    --b9: #c5cae9;
    --acc: #7e57c2;
    --acc2: #b39ddb;
    --acc3: #9575cd;
    --glow: rgba(57,73,171,.6);
    --glass: rgba(255,255,255,.045);
    --gb: rgba(255,255,255,.09);
    --gh: rgba(255,255,255,.07);
    --txt: #e8eaf6;
    --mut: #9fa8da;
    --r: 20px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Outfit',sans-serif;
    background:var(--b1);
    color:var(--txt);
    min-height:100vh;
    position:relative;
}

/* DEEP BACKGROUND */
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

/* HEADER */
header {
    position:relative;
    z-index:2;
    background:linear-gradient(120deg, rgba(26,35,126,.92) 0%, rgba(57,73,171,.85) 100%);
    backdrop-filter:blur(12px);
    color:#fff;
    padding:18px 28px;
    border-bottom:1px solid var(--gb);
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:15px;
}

header .logo-area {
    display:flex;
    align-items:center;
    gap:12px;
}

header .logo-area i {
    font-size:28px;
    color:var(--acc2);
}

header .logo-area h1 {
    font-size:20px;
    font-weight:700;
    margin:0;
}

header a {
    background:rgba(239,68,68,.2);
    color:#fca5a5;
    padding:8px 20px;
    border-radius:40px;
    text-decoration:none;
    font-weight:600;
    font-size:14px;
    transition:all .2s;
    border:1px solid rgba(239,68,68,.3);
}

header a:hover {
    background:rgba(239,68,68,.35);
    color:#fff;
    transform:translateY(-1px);
}

/* MAIN CONTAINER */
.container {
    position:relative;
    z-index:2;
    max-width:1100px;
    margin:40px auto;
    padding:0 20px;
}

/* DOCTOR BADGE */
.doc-badge {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:25px;
    padding:20px 25px;
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
}

.doc-info {
    display:flex;
    align-items:center;
    gap:12px;
}

.doc-avatar {
    width:50px;
    height:50px;
    border-radius:14px;
    background:linear-gradient(135deg, var(--b5), var(--acc));
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    color:#fff;
}

.doc-details h4 {
    font-size:18px;
    font-weight:700;
    color:#fff;
}

.doc-details span {
    font-size:12px;
    color:var(--mut);
}

/* SEARCH BAR */
.search-wrapper {
    margin-bottom:25px;
}

.search-box {
    width:100%;
    padding:14px 20px;
    border-radius:50px;
    border:1.5px solid var(--gb);
    background:rgba(255,255,255,.07);
    font-family:'Outfit',sans-serif;
    font-size:14px;
    font-weight:500;
    color:#fff;
    transition:all .2s;
    outline:none;
}

.search-box:focus {
    border-color:var(--b6);
    background:rgba(255,255,255,.12);
    box-shadow:0 0 0 4px rgba(92,107,192,.2);
}

.search-box::placeholder {
    color:var(--mut);
}

/* CARD */
.card {
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
    margin-bottom:20px;
    overflow:hidden;
    transition:transform .25s, box-shadow .25s;
}

.card:hover {
    transform:translateY(-2px);
    box-shadow:0 20px 40px -12px rgba(0,0,0,.4);
}

.card-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:18px 24px;
    cursor:pointer;
    background:rgba(255,255,255,.03);
    border-bottom:1px solid var(--gb);
    transition:background .2s;
}

.card-header:hover {
    background:rgba(255,255,255,.06);
}

.card-header h3 {
    margin:0;
    font-size:18px;
    font-weight:700;
    color:#fff;
    display:flex;
    align-items:center;
    gap:10px;
}

.card-header h3 i {
    color:var(--acc);
    font-size:18px;
}

.card-header span {
    font-size:12px;
    color:var(--mut);
}

.details {
    display:none;
    padding:20px 24px;
    border-top:1px solid var(--gb);
}

/* PATIENT INFO GRID */
.info-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:15px;
    margin-bottom:20px;
    padding-bottom:15px;
    border-bottom:1px solid var(--gb);
}

.info-item {
    display:flex;
    align-items:center;
    gap:10px;
}

.info-item i {
    width:30px;
    color:var(--acc);
    font-size:14px;
}

.info-item strong {
    font-size:12px;
    color:var(--mut);
    font-weight:500;
}

.info-item span {
    font-size:14px;
    font-weight:500;
    color:#fff;
}

/* TIMELINE */
.timeline {
    border-left:3px solid var(--acc);
    margin-top:15px;
    padding-left:20px;
}

.record {
    margin-bottom:20px;
    padding:18px;
    background:rgba(255,255,255,.05);
    border-radius:16px;
    position:relative;
    transition:background .2s;
}

.record:hover {
    background:rgba(255,255,255,.08);
}

.record-date {
    font-size:11px;
    color:var(--acc2);
    margin-bottom:12px;
    display:flex;
    align-items:center;
    gap:6px;
}

.record-section {
    margin-bottom:12px;
}

.record-section strong {
    font-size:12px;
    color:var(--b8);
    text-transform:uppercase;
    letter-spacing:.05em;
    display:block;
    margin-bottom:5px;
}

.record-section p {
    font-size:14px;
    color:var(--txt);
    line-height:1.5;
}

.print-btn {
    background:linear-gradient(105deg, var(--b5), var(--acc));
    color:#fff;
    padding:8px 18px;
    border:none;
    border-radius:40px;
    cursor:pointer;
    font-size:12px;
    font-weight:600;
    margin-top:12px;
    transition:all .2s;
    display:inline-flex;
    align-items:center;
    gap:6px;
}

.print-btn:hover {
    transform:translateY(-1px);
    box-shadow:0 4px 12px rgba(57,73,171,.4);
}

.empty-records {
    text-align:center;
    padding:30px;
    color:var(--mut);
}

.empty-records i {
    font-size:40px;
    margin-bottom:10px;
    opacity:0.5;
}

/* responsive */
@media (max-width: 768px) {
    header {
        flex-direction:column;
        text-align:center;
    }
    .card-header {
        flex-direction:column;
        gap:10px;
        text-align:center;
    }
    .info-grid {
        grid-template-columns:1fr;
    }
    .timeline {
        padding-left:12px;
    }
}
</style>

<script>
function toggleDetails(id) {
    let el = document.getElementById(id);
    el.style.display = el.style.display === "block" ? "none" : "block";
}

function searchPatients() {
    let input = document.getElementById("search").value.toLowerCase();
    let cards = document.getElementsByClassName("card");

    for (let i = 0; i < cards.length; i++) {
        let name = cards[i].getAttribute("data-name");
        cards[i].style.display = name.includes(input) ? "block" : "none";
    }
}

function printRecord(content) {
    let w = window.open();
    w.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Prescription Print</title>
            <style>
                body { font-family: 'Outfit', sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
                h2 { color: #1a237e; }
                hr { margin: 20px 0; }
                .header { text-align: center; margin-bottom: 30px; }
                .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Zaman Medical Hospital</h2>
                <p>Prescription Document</p>
            </div>
            ${content}
            <div class="footer">
                <p>This is a computer-generated document. No signature required.</p>
            </div>
        </body>
        </html>
    `);
    w.print();
    w.close();
}
</script>

</head>

<body>

<header>
    <div class="logo-area">
        <i class="fa-solid fa-notes-medical"></i>
        <h1>Patient Medical Records</h1>
    </div>
    <a href="doctordashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
</header>

<div class="container">

    <div class="doc-badge">
        <div class="doc-info">
            <div class="doc-avatar"><i class="fa-solid fa-user-md"></i></div>
            <div class="doc-details">
                <h4>Dr. <?= htmlspecialchars($doc_name) ?></h4>
                <span><i class="fa-regular fa-calendar"></i> Patient Records Overview</span>
            </div>
        </div>
        <i class="fa-solid fa-stethoscope" style="color:var(--acc2); font-size:28px;"></i>
    </div>

    <div class="search-wrapper">
        <input type="text" id="search" class="search-box" onkeyup="searchPatients()" placeholder="🔍 Search patient by name...">
    </div>

    <?php foreach ($patients as $pid => $data): 
        $info = $data['info'];
        $records = $data['records'];
    ?>

    <div class="card" data-name="<?= strtolower($info['full_name']) ?>">
        
        <div class="card-header" onclick="toggleDetails('details<?= $pid ?>')">
            <h3>
                <i class="fa-regular fa-user-circle"></i>
                <?= htmlspecialchars($info['full_name']) ?>
            </h3>
            <span><i class="fa-regular fa-eye"></i> Click to view details ▼</span>
        </div>

        <div id="details<?= $pid ?>" class="details">

            <div class="info-grid">
                <div class="info-item">
                    <i class="fa-regular fa-calendar"></i>
                    <strong>Age:</strong>
                    <span><?= $info['age'] ?> years</span>
                </div>
                <div class="info-item">
                    <i class="fa-regular fa-venus-mars"></i>
                    <strong>Gender:</strong>
                    <span><?= ucfirst($info['gender']) ?></span>
                </div>
                <div class="info-item">
                    <i class="fa-solid fa-phone"></i>
                    <strong>Contact:</strong>
                    <span><?= htmlspecialchars($info['contact']) ?></span>
                </div>
                <div class="info-item">
                    <i class="fa-solid fa-location-dot"></i>
                    <strong>Address:</strong>
                    <span><?= htmlspecialchars($info['address']) ?></span>
                </div>
            </div>

            <div class="timeline">
                <?php if (count($records) > 0): ?>
                    <?php foreach ($records as $rec): ?>
                        <div class="record">
                            <div class="record-date">
                                <i class="fa-regular fa-clock"></i>
                                <?= date("d M Y, h:i A", strtotime($rec['created_at'])) ?>
                            </div>
                            
                            <div class="record-section">
                                <strong><i class="fa-solid fa-stethoscope"></i> Diagnosis</strong>
                                <p><?= nl2br(htmlspecialchars($rec['diagnosis'])) ?></p>
                            </div>

                            <div class="record-section">
                                <strong><i class="fa-solid fa-pills"></i> Prescription / Lab Reports</strong>
                                <p><?= nl2br(htmlspecialchars($rec['lab_reports'])) ?></p>
                            </div>

                            <div class="record-section">
                                <strong><i class="fa-regular fa-note-sticky"></i> Clinical Notes</strong>
                                <p><?= nl2br(htmlspecialchars($rec['notes'])) ?></p>
                            </div>

                            <button class="print-btn"
                            onclick='printRecord(`
                                <p><strong>Patient Name:</strong> <?= htmlspecialchars($info['full_name']) ?></p>
                                <p><strong>Age:</strong> <?= $info['age'] ?> | <strong>Gender:</strong> <?= ucfirst($info['gender']) ?></p>
                                <p><strong>Date:</strong> <?= date("d M Y, h:i A", strtotime($rec['created_at'])) ?></p>
                                <hr>
                                <h3>Diagnosis</h3>
                                <p><?= nl2br(htmlspecialchars($rec['diagnosis'])) ?></p>
                                <h3>Prescription</h3>
                                <p><?= nl2br(htmlspecialchars($rec['lab_reports'])) ?></p>
                                <h3>Notes</h3>
                                <p><?= nl2br(htmlspecialchars($rec['notes'])) ?></p>
                                <hr>
                                <p><em>Dr. <?= htmlspecialchars($doc_name) ?></em></p>
                            `)'>
                            <i class="fa-solid fa-print"></i> Print Prescription
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-records">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>No medical records found for this patient.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php endforeach; ?>

    <?php if(empty($patients)): ?>
    <div style="text-align:center; padding:60px; background:rgba(255,255,255,.04); border-radius:var(--r); border:1px solid var(--gb);">
        <i class="fa-regular fa-folder-open" style="font-size:48px; color:var(--mut); margin-bottom:15px; display:block;"></i>
        <p style="color:var(--mut);">No patients found. Appointments will appear here once you have patients.</p>
    </div>
    <?php endif; ?>

</div>

</body>
</html>