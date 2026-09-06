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
if ($conn->connect_error) die("Database connection failed");

/* ---------- GET DOCTOR ID ---------- */
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($doctor_id);
$stmt->fetch();
$stmt->close();

if (!$doctor_id) die("Doctor not found");

/* ---------- INIT ---------- */
$success = "";
$error = "";

/* ---------- HANDLE ADD AVAILABILITY ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $date  = $_POST['date'] ?? '';
    $start = $_POST['start_time'] ?? '';
    $end   = $_POST['end_time'] ?? '';

    $today = date('Y-m-d');

    /* ---------- VALIDATION ---------- */
    if (!$date || !$start || !$end) {
        $error = "❌ All fields are required!";
    }
    elseif ($date < $today) {
        $error = "❌ You cannot set availability for past dates!";
    }
    elseif ($start >= $end) {
        $error = "❌ End time must be greater than start time!";
    }
    else {

        /* ---------- OVERLAP CHECK (FIXED LOGIC) ---------- */
        $check = $conn->prepare("
            SELECT id FROM doctor_availability
            WHERE doctor_id=?
            AND date=?
            AND (
                (start_time < ? AND end_time > ?) OR
                (start_time < ? AND end_time > ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ");

        $check->bind_param(
            "isssssss",
            $doctor_id,
            $date,
            $end,
            $start,
            $start,
            $end,
            $start,
            $end
        );

        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            $error = "❌ This time overlaps with existing availability!";
        } else {

            /* ---------- INSERT ---------- */
            $stmt = $conn->prepare("
                INSERT INTO doctor_availability (doctor_id, date, start_time, end_time)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->bind_param("isss", $doctor_id, $date, $start, $end);

            if ($stmt->execute()) {
                $success = "✅ Availability added successfully!";
            } else {
                $error = "❌ Failed to add availability!";
            }

            $stmt->close();
        }

        $check->close();
    }
}

/* ---------- FETCH AVAILABILITY ---------- */
$stmt = $conn->prepare("
    SELECT * FROM doctor_availability
    WHERE doctor_id=?
    ORDER BY date, start_time
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Get doctor name
$doc_name = "Doctor";
$name_stmt = $conn->prepare("SELECT u.full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
$name_stmt->bind_param("i", $user_id);
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
<title>Doctor Availability | Zaman Medical</title>

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

header .logo-area h2 {
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
    max-width:1000px;
    margin:40px auto;
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
    padding:35px 40px;
    box-shadow:0 25px 50px -12px rgba(0,0,0,.4);
    transition:transform .25s, box-shadow .25s;
}

.container:hover {
    transform:translateY(-3px);
    box-shadow:0 30px 60px -15px rgba(0,0,0,.5);
}

/* FORM TITLE */
.form-title {
    text-align:center;
    margin-bottom:28px;
}

.form-title h3 {
    font-size:26px;
    font-weight:700;
    background:linear-gradient(135deg, #fff, var(--b8));
    background-clip:text;
    -webkit-background-clip:text;
    color:transparent;
    display:inline-flex;
    align-items:center;
    gap:10px;
}

.form-title p {
    font-size:13px;
    color:var(--mut);
    margin-top:6px;
}

/* DOCTOR BADGE */
.doc-badge {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:25px;
    padding-bottom:15px;
    border-bottom:1px solid var(--gb);
}

.doc-info {
    display:flex;
    align-items:center;
    gap:12px;
}

.doc-avatar {
    width:44px;
    height:44px;
    border-radius:14px;
    background:linear-gradient(135deg, var(--b5), var(--acc));
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    color:#fff;
}

.doc-details h4 {
    font-size:16px;
    font-weight:700;
    color:#fff;
}

.doc-details span {
    font-size:11px;
    color:var(--mut);
}

/* FORM STYLES */
.form-row {
    display:flex;
    gap:20px;
    flex-wrap:wrap;
    margin-bottom:10px;
}

.form-group {
    flex:1;
    min-width:180px;
}

.form-group label {
    display:block;
    margin-bottom:6px;
    font-weight:600;
    font-size:12px;
    color:var(--b8);
    letter-spacing:.03em;
    text-transform:uppercase;
}

.form-group label i {
    margin-right:6px;
    color:var(--acc);
    font-size:11px;
}

.form-group input {
    width:100%;
    padding:12px 16px;
    border-radius:16px;
    border:1.5px solid var(--gb);
    background:rgba(255,255,255,.07);
    font-family:'Outfit',monospace;
    font-size:14px;
    font-weight:500;
    color:#fff;
    transition:all .2s;
    outline:none;
}

.form-group input:focus {
    border-color:var(--b6);
    background:rgba(255,255,255,.12);
    box-shadow:0 0 0 4px rgba(92,107,192,.2);
}

.form-group input[type="date"]::-webkit-calendar-picker-indicator,
.form-group input[type="time"]::-webkit-calendar-picker-indicator {
    filter:invert(0.7);
    cursor:pointer;
}

.btn-submit {
    width:100%;
    padding:14px;
    margin-top:20px;
    border:none;
    border-radius:40px;
    background:linear-gradient(105deg, var(--b5), var(--acc));
    color:#fff;
    font-size:15px;
    font-weight:700;
    cursor:pointer;
    transition:all .25s;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    font-family:'Outfit',sans-serif;
    box-shadow:0 6px 18px rgba(57,73,171,.35);
}

.btn-submit:hover {
    transform:translateY(-2px);
    box-shadow:0 12px 28px rgba(57,73,171,.5);
}

.btn-submit:active {
    transform:translateY(1px);
}

/* ALERT MESSAGES */
.success, .error {
    padding:12px 18px;
    border-radius:40px;
    margin-bottom:20px;
    text-align:center;
    font-weight:600;
    font-size:13px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
}

.success {
    background:rgba(74,222,128,.18);
    color:#4ade80;
    border:1px solid rgba(74,222,128,.3);
}

.error {
    background:rgba(248,113,113,.18);
    color:#f87171;
    border:1px solid rgba(248,113,113,.3);
}

/* TABLE STYLES */
.section-title {
    margin-top:35px;
    margin-bottom:20px;
    display:flex;
    align-items:center;
    gap:10px;
    font-size:18px;
    font-weight:700;
    color:#fff;
}

.section-title i {
    color:var(--acc);
    font-size:20px;
}

.table-wrapper {
    overflow-x:auto;
    border-radius:16px;
    border:1px solid var(--gb);
}

table {
    width:100%;
    border-collapse:collapse;
}

th {
    background:rgba(57,73,171,.3);
    color:var(--b9);
    font-weight:600;
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.05em;
    padding:14px 12px;
    text-align:center;
    border-bottom:1px solid var(--gb);
}

td {
    padding:12px 10px;
    text-align:center;
    border-bottom:1px solid rgba(255,255,255,.05);
    color:var(--txt);
    font-size:14px;
}

tr:hover {
    background:rgba(255,255,255,.03);
}

.empty-row td {
    text-align:center;
    padding:40px;
    color:var(--mut);
}

/* responsive */
@media (max-width: 768px) {
    .container {
        margin:20px;
        padding:25px 20px;
    }
    .form-row {
        flex-direction:column;
        gap:10px;
    }
    header {
        flex-direction:column;
        text-align:center;
    }
    .form-title h3 {
        font-size:22px;
    }
}
</style>
</head>

<body>

<header>
    <div class="logo-area">
        <i class="fa-solid fa-calendar-clock"></i>
        <h2>Zaman Medical Hospital</h2>
    </div>
    <a href="doctordashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
</header>

<div class="container">
    
    <div class="form-title">
        <h3><i class="fa-solid fa-clock"></i> Set Your Availability</h3>
        <p><i class="fa-regular fa-calendar"></i> Manage your weekly working schedule</p>
    </div>

    <div class="doc-badge">
        <div class="doc-info">
            <div class="doc-avatar"><i class="fa-solid fa-user-md"></i></div>
            <div class="doc-details">
                <h4>Dr. <?= htmlspecialchars($doc_name) ?></h4>
                <span><i class="fa-regular fa-clock"></i> Today, <?= date('l, d F Y') ?></span>
            </div>
        </div>
        <i class="fa-solid fa-stethoscope" style="color:var(--acc2); font-size:24px;"></i>
    </div>

    <?php if($success): ?>
        <div class="success">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="error">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label><i class="fa-regular fa-calendar"></i> Date</label>
                <input type="date" name="date" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label><i class="fa-regular fa-clock"></i> Start Time</label>
                <input type="time" name="start_time" required>
            </div>
            <div class="form-group">
                <label><i class="fa-regular fa-clock"></i> End Time</label>
                <input type="time" name="end_time" required>
            </div>
        </div>
        <button type="submit" class="btn-submit">
            <i class="fa-solid fa-plus-circle"></i> Add Availability
        </button>
    </form>

    <div class="section-title">
        <i class="fa-solid fa-list-check"></i>
        <span>Your Scheduled Availability</span>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th><i class="fa-regular fa-calendar"></i> Date</th>
                    <th><i class="fa-regular fa-clock"></i> Start Time</th>
                    <th><i class="fa-regular fa-clock"></i> End Time</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $result->data_seek(0);
                $has_rows = false;
                while($row = $result->fetch_assoc()): 
                    $has_rows = true;
                ?>
                <tr>
                    <td><?= date('d M Y', strtotime($row['date'])) ?></td>
                    <td><span class="time-badge"><?= date('h:i A', strtotime($row['start_time'])) ?></span></td>
                    <td><span class="time-badge"><?= date('h:i A', strtotime($row['end_time'])) ?></span></td>
                </tr>
                <?php endwhile; ?>
                <?php if(!$has_rows): ?>
                <tr class="empty-row">
                    <td colspan="3">
                        <i class="fa-regular fa-folder-open"></i> No availability records found<br>
                        <small>Add your availability using the form above</small>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.time-badge {
    background:rgba(92,107,192,.2);
    padding:4px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:500;
}
</style>

</body>
</html>