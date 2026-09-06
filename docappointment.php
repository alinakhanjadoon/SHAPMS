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
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);

/* ---------- GET DOCTOR ID ---------- */
$stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($doctor_id);
$stmt->fetch();
$stmt->close();

if (!$doctor_id) die("Doctor record not found!");

/* =========================================================
   HANDLE ACTIONS (COMPLETE / CANCEL only)
========================================================= */
if (isset($_GET['action']) && isset($_GET['id'])) {

    $action = $_GET['action'];
    $appointment_id = intval($_GET['id']);

    $allowed = ['completed', 'cancelled'];

    if (in_array($action, $allowed)) {
        // Get appointment + patient info for notification
        $info = $conn->prepare("
            SELECT a.appointment_date, a.patient_id, p.user_id AS patient_user_id
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            WHERE a.appointment_id = ? AND a.doctor_id = ?
        ");
        $info->bind_param("ii", $appointment_id, $doctor_id);
        $info->execute();
        $info_row = $info->get_result()->fetch_assoc();
        $info->close();

        $stmt = $conn->prepare("
            UPDATE appointments 
            SET status=? 
            WHERE appointment_id=? AND doctor_id=?
        ");
        $stmt->bind_param("sii", $action, $appointment_id, $doctor_id);
        $stmt->execute();
        $stmt->close();

        // Send notification to patient
        if ($info_row) {
            require_once __DIR__ . "/includes/notifications_functions.php";
            $appt_date = date("d M Y", strtotime($info_row["appointment_date"]));
            $appt_time = date("h:i A", strtotime($info_row["appointment_date"]));
            if ($action === "cancelled") {
                createNotification($conn, $info_row["patient_user_id"], "patient", "appointment",
                    "Appointment Cancelled",
                    "Your appointment on $appt_date at $appt_time has been cancelled by the doctor.",
                    "patientappointment.php"
                );
            } elseif ($action === "completed") {
                createNotification($conn, $info_row["patient_user_id"], "patient", "appointment",
                    "Appointment Completed",
                    "Your appointment on $appt_date at $appt_time has been marked as completed.",
                    "patientappointment.php"
                );
            }
        }

        header("Location: docappointment.php");
        exit();
    }
}

/* =========================================================
   FETCH APPOINTMENTS
========================================================= */
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.appointment_date, a.status,
           u.full_name AS patient_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    WHERE a.doctor_id = ?
    ORDER BY a.appointment_date DESC
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$appointments = $stmt->get_result();
$stmt->close();

/* =========================================================
   STATS FOR CHART — scheduled, completed, cancelled
========================================================= */
$statuses = ['scheduled', 'completed', 'cancelled'];
$stats = [];

foreach ($statuses as $status) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM appointments 
        WHERE doctor_id=? AND status=?
    ");
    $stmt->bind_param("is", $doctor_id, $status);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats[$status] = $count;
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zaman Medical | Manage Appointments</title>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<style>
:root {
    --b1: #0a1628; --b2: #0f1c3d; --b3: #1a2a52; --b4: #1e3a8a;
    --b5: #2563eb; --b6: #3b82f6; --b7: #60a5fa; --b8: #93c5fd;
    --acc: #1d4ed8; --acc2: #2563eb; --glow: rgba(37,99,235,.5);
    --glass: rgba(255,255,255,.045); --gb: rgba(255,255,255,.09);
    --txt: #e0e7ff; --mut: #7c8db5; --sw: 268px; --r: 20px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Outfit', sans-serif; background: var(--b1); color: var(--txt); min-height: 100vh; overflow-x: hidden; }

body::before {
    content: ''; position: fixed; inset: 0;
    background: radial-gradient(ellipse 90% 70% at 10% 10%, rgba(37,99,235,.2) 0%, transparent 55%),
                radial-gradient(ellipse 70% 60% at 90% 80%, rgba(29,78,216,.15) 0%, transparent 55%),
                radial-gradient(ellipse 50% 50% at 55% 45%, rgba(26,42,82,.4) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
}
body::after {
    content: ''; position: fixed; inset: 0;
    background-image: linear-gradient(rgba(255,255,255,.015) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,.015) 1px, transparent 1px);
    background-size: 52px 52px; pointer-events: none; z-index: 0;
}

.sidebar {
    position: fixed; top: 0; left: 0; width: var(--sw); height: 100vh;
    background: rgba(11,22,44,.88); backdrop-filter: blur(32px);
    border-right: 1px solid var(--gb); display: flex; flex-direction: column;
    overflow-y: auto; z-index: 1000; scrollbar-width: none;
}
.sidebar::-webkit-scrollbar { display: none; }
.sb-logo { padding: 26px 22px 20px; border-bottom: 1px solid var(--gb); }
.sb-brand { font-family: 'Instrument Serif', serif; font-size: 18px; color: #fff; line-height: 1.3; }
.sb-sub { font-size: 9.5px; color: var(--b6); font-weight: 700; text-transform: uppercase; letter-spacing: .13em; margin-top: 5px; }
.sb-sec { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: var(--mut); padding: 16px 22px 6px; }
.sb-nav a {
    display: flex; align-items: center; gap: 11px;
    padding: 11px 14px 11px 22px; color: var(--mut); text-decoration: none;
    font-size: 13.5px; font-weight: 500; border-left: 2px solid transparent;
    margin-right: 10px; border-radius: 0 10px 10px 0; transition: all .2s;
}
.sb-nav a i { width: 17px; text-align: center; font-size: 14px; }
.sb-nav a:hover { color: var(--b8); background: rgba(37,99,235,.12); border-left-color: var(--b6); }
.sb-nav a.active-link { color: #fff; background: linear-gradient(90deg, rgba(37,99,235,.38), rgba(37,99,235,.06)); border-left-color: var(--acc); font-weight: 600; }
.sb-nav a.active-link i { color: var(--b6); }
.sb-foot { padding: 15px 22px; border-top: 1px solid var(--gb); font-size: 11px; color: var(--mut); display: flex; align-items: center; gap: 7px; margin-top: auto; }
.ldot { width: 7px; height: 7px; background: #3b82f6; border-radius: 50%; box-shadow: 0 0 10px #3b82f6; animation: pulseDot 1.8s infinite; }
@keyframes pulseDot { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.7);opacity:.45} }

.main { margin-left: var(--sw); padding: 26px 28px 44px; min-height: 100vh; position: relative; z-index: 2; }
.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; gap: 14px; flex-wrap: wrap; }
.tl { display: flex; align-items: center; gap: 15px; }
.doc-av { width: 54px; height: 54px; border-radius: 14px; object-fit: cover; border: 2px solid var(--b5); box-shadow: 0 0 0 4px rgba(37,99,235,.22), 0 0 28px rgba(37,99,235,.35); }
.g-text { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -.025em; line-height: 1.2; }
.sp-pill { display: inline-flex; align-items: center; gap: 5px; background: rgba(37,99,235,.2); border: 1px solid rgba(37,99,235,.38); color: var(--b7); font-size: 11px; font-weight: 600; padding: 3px 12px; border-radius: 99px; margin-top: 5px; }
.btnt { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; transition: all .2s; font-family: 'Outfit', sans-serif; }
.btnt.lg { background: rgba(239,68,68,.18); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
.btnt.lg:hover { background: rgba(239,68,68,.32); color: #fff; }

.hero {
    background: linear-gradient(120deg, rgba(22,38,74,.9) 0%, rgba(11,22,44,.88) 100%);
    border: 1px solid var(--gb); backdrop-filter: blur(22px); border-radius: var(--r);
    padding: 26px 30px; display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 28px; position: relative; overflow: hidden;
    box-shadow: 0 10px 50px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.06);
}
.hero::before { content: ''; position: absolute; top: -80px; right: -80px; width: 320px; height: 320px; background: radial-gradient(circle, rgba(37,99,235,.22) 0%, transparent 65%); border-radius: 50%; pointer-events: none; }
.hero::after  { content: ''; position: absolute; bottom: -60px; left: 220px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(29,78,216,.15) 0%, transparent 65%); border-radius: 50%; pointer-events: none; }
.hero-ttl { font-family: 'Instrument Serif', serif; font-size: 23px; color: #fff; margin-bottom: 6px; letter-spacing: -.02em; }
.hero-s { font-size: 13px; color: var(--mut); }
.hero-time { margin-top: 14px; display: flex; align-items: center; gap: 14px; font-size: 12px; color: var(--b7); font-weight: 500; }
.ck { background: rgba(37,99,235,.22); border: 1px solid rgba(37,99,235,.38); padding: 5px 15px; border-radius: 99px; font-weight: 800; font-size: 13px; color: #fff; letter-spacing: .04em; box-shadow: 0 0 16px rgba(37,99,235,.3); }
.hico { display: flex; gap: 12px; align-items: center; }
.hic { width: 52px; height: 52px; border-radius: 50%; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.04); display: flex; align-items: center; justify-content: center; font-size: 19px; color: rgba(255,255,255,.28); }
.hic.lg { width: 70px; height: 70px; font-size: 28px; border-color: rgba(37,99,235,.38); color: rgba(59,130,246,.45); box-shadow: 0 0 32px rgba(37,99,235,.2); }

.glass-card { background: rgba(255,255,255,.04); border: 1px solid var(--gb); backdrop-filter: blur(22px); border-radius: var(--r); padding: 24px; transition: all .25s; }
.glass-card:hover { box-shadow: 0 26px 65px rgba(0,0,0,.4); }
.card-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--gb); }
.card-header-custom h4 { font-size: 18px; font-weight: 700; margin: 0; color: #fff; }

.table-custom { width: 100%; border-collapse: separate; border-spacing: 0; }
.table-custom thead th { background: rgba(37,99,235,.2); color: var(--b8); font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; padding: 14px 12px; border-bottom: 1px solid var(--gb); }
.table-custom tbody td { padding: 14px 12px; border-bottom: 1px solid rgba(255,255,255,.05); color: var(--txt); font-size: 14px; }
.table-custom tbody tr:hover { background: rgba(255,255,255,.03); }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
.status-scheduled { background: rgba(59,130,246,.2);  color: #60a5fa; }
.status-completed  { background: rgba(74,222,128,.2);  color: #4ade80; }
.status-cancelled  { background: rgba(248,113,113,.2); color: #f87171; }

.action-group { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
.action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: all .2s; border: none; cursor: pointer; }
.action-btn i { font-size: 11px; }
.btn-complete { background: rgba(74,222,128,.2);  color: #4ade80; border: 1px solid rgba(74,222,128,.3); }
.btn-complete:hover { background: rgba(74,222,128,.4);  color: #fff; transform: translateY(-1px); }
.btn-cancel   { background: rgba(248,113,113,.2); color: #f87171; border: 1px solid rgba(248,113,113,.3); }
.btn-cancel:hover   { background: rgba(248,113,113,.4); color: #fff; transform: translateY(-1px); }

.chart-wrapper { background: rgba(255,255,255,.04); border: 1px solid var(--gb); backdrop-filter: blur(22px); border-radius: var(--r); padding: 24px; height: 100%; }
.chart-title { font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.chart-title i { color: var(--b6); }

.two-column { display: flex; gap: 28px; flex-wrap: wrap; }
.col-table { flex: 2; min-width: 300px; }
.col-chart  { flex: 1; min-width: 320px; }

@keyframes fadeSlide { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
.animated { animation: fadeSlide .5s ease-out forwards; }

@media (max-width: 992px) { .sidebar{transform:translateX(-100%)} .main{margin-left:0;padding:20px} .two-column{flex-direction:column} }
@media (max-width: 768px) { .hero{flex-direction:column;text-align:center;gap:16px} .hero-time{justify-content:center} .topbar{flex-direction:column;align-items:flex-start} .action-group{flex-direction:column;align-items:center} .table-custom{font-size:12px} .action-btn{padding:4px 10px;font-size:11px} }
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sb-logo">
        <div class="sb-brand">Zaman Medical<br>Hospital</div>
        <div class="sb-sub">Doctor Portal</div>
    </div>
    <nav class="sb-nav">
        <div class="sb-sec">Navigation</div>
        <a href="doctordashboard.php"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <a href="docadddiagnosis.php"><i class="fa-solid fa-file-medical"></i> Diagnosis</a>
        <a href="docappointment.php" class="active-link"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
        <a href="docpatientrecord.php"><i class="fa-solid fa-users"></i> Patients</a>
        <a href="docprescriptions.php"><i class="fa-solid fa-pills"></i> Prescriptions</a>
        <a href="doctoravailability.php"><i class="fa-solid fa-clock"></i> Availability</a>
        <a href="docreports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
        <a href="docbilling.php"><i class="fa-solid fa-file-invoice-dollar"></i> Billing</a>
    </nav>
    <div class="sb-foot">
        <span class="ldot"></span> System Online &nbsp;·&nbsp; v3.0
        <span style="margin-left: auto; font-size: 9px;" id="liveClock"></span>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">

    <!-- TOPBAR -->
    <div class="topbar animated">
        <div class="tl">
            <?php
            $doc_name = "Doctor";
            $name_stmt = $conn->prepare("SELECT u.full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
            $name_stmt->bind_param("i", $_SESSION['user_id']);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            if ($name_result && $row = $name_result->fetch_assoc()) { $doc_name = $row['full_name']; }
            $name_stmt->close();
            $greeting_hour = (int)date('H');
            $greeting = ($greeting_hour < 12) ? "Good Morning" : (($greeting_hour < 17) ? "Good Afternoon" : "Good Evening");
            ?>
            <img src="https://ui-avatars.com/api/?name=<?= urlencode($doc_name) ?>&background=1e3a8a&color=fff&size=80&bold=true"
                 class="doc-av" alt="doctor avatar">
            <div>
                <div class="g-text"><?= $greeting ?>, Dr. <?= htmlspecialchars($doc_name) ?> 👋</div>
                <span class="sp-pill"><i class="fa-solid fa-stethoscope"></i> Appointment Management</span>
            </div>
        </div>
        <div class="tr">
            <a href="logout.php" class="btnt lg"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <!-- HERO -->
    <div class="hero animated" style="animation-delay:0.05s;">
        <div>
            <div class="hero-ttl">📅 Appointment Manager</div>
            <div class="hero-s">View and manage your patient appointments</div>
            <div class="hero-time">
                <span><i class="fa-regular fa-calendar-days me-1"></i><?= date('l, d F Y') ?></span>
                <span class="ck" id="heroClock">--:--:--</span>
            </div>
        </div>
        <div class="hico">
            <div class="hic"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="hic lg"><i class="fa-solid fa-clock"></i></div>
            <div class="hic"><i class="fa-solid fa-list-check"></i></div>
        </div>
    </div>

    <!-- TWO COLUMN -->
    <div class="two-column">

        <!-- TABLE -->
        <div class="col-table animated" style="animation-delay:0.1s;">
            <div class="glass-card">
                <div class="card-header-custom">
                    <h4><i class="fa-solid fa-list-ul me-2"></i>All Appointments</h4>
                    <span class="sp-pill" style="margin-top:0;">Total: <?= $appointments->num_rows ?></span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $appointments->data_seek(0);
                            while($row = $appointments->fetch_assoc()):
                            ?>
                            <tr>
                                <td><i class="fa-regular fa-user me-2"></i><?= htmlspecialchars($row['patient_name']) ?></td>
                                <td><?= date('Y-m-d', strtotime($row['appointment_date'])) ?></td>
                                <td><?= date('h:i A', strtotime($row['appointment_date'])) ?></td>
                                <td><span class="status-badge status-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span></td>
                                <td>
                                    <div class="action-group">
                                        <?php if($row['status'] == 'scheduled'): ?>
                                            <!-- Auto-booked: doctor can Complete or Cancel -->
                                            <a class="action-btn btn-complete"
                                               href="docappointment.php?action=completed&id=<?= $row['appointment_id'] ?>">
                                                <i class="fa-regular fa-circle-check"></i> Complete
                                            </a>
                                            <a class="action-btn btn-cancel"
                                               onclick="return confirm('Cancel this appointment?')"
                                               href="docappointment.php?action=cancelled&id=<?= $row['appointment_id'] ?>">
                                                <i class="fa-regular fa-circle-xmark"></i> Cancel
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--mut);font-size:12px;">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if($appointments->num_rows == 0): ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:40px;color:var(--mut);">
                                    <i class="fa-regular fa-calendar fa-2x mb-2 d-block"></i>
                                    No appointments found
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- CHART -->
        <div class="col-chart animated" style="animation-delay:0.15s;">
            <div class="chart-wrapper">
                <div class="chart-title">
                    <i class="fa-solid fa-chart-pie fa-lg"></i>
                    <span>Appointment Status Distribution</span>
                </div>
                <canvas id="appointmentChart" style="max-height:320px;width:100%"></canvas>

                <div style="margin-top:24px;display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;">
                    <div style="text-align:center;flex:1;">
                        <div style="font-size:11px;color:var(--mut);">Scheduled</div>
                        <div style="font-size:24px;font-weight:800;color:#60a5fa;"><?= $stats['scheduled'] ?></div>
                    </div>
                    <div style="text-align:center;flex:1;">
                        <div style="font-size:11px;color:var(--mut);">Completed</div>
                        <div style="font-size:24px;font-weight:800;color:#4ade80;"><?= $stats['completed'] ?></div>
                    </div>
                    <div style="text-align:center;flex:1;">
                        <div style="font-size:11px;color:var(--mut);">Cancelled</div>
                        <div style="font-size:24px;font-weight:800;color:#f87171;"><?= $stats['cancelled'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateClock() {
    const t = new Date().toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    const h = document.getElementById('heroClock');
    const l = document.getElementById('liveClock');
    if(h) h.textContent = t;
    if(l) l.textContent = t;
}
updateClock();
setInterval(updateClock, 1000);

const ctx = document.getElementById('appointmentChart').getContext('2d');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Scheduled', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?= $stats['scheduled'] ?>,
                <?= $stats['completed'] ?>,
                <?= $stats['cancelled'] ?>
            ],
            backgroundColor: ['#3b82f6', '#4ade80', '#f87171'],
            borderColor: 'rgba(11,22,44,.8)',
            borderWidth: 3,
            hoverOffset: 8,
            cutout: '60%'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: '#7c8db5', font: {family:'Outfit',size:11,weight:'500'}, boxWidth: 10, padding: 12 }
            },
            tooltip: {
                backgroundColor: 'rgba(11,22,44,.95)', titleColor: '#fff',
                bodyColor: '#7c8db5', borderColor: '#2563eb',
                borderWidth: 1, padding: 10, cornerRadius: 8
            }
        }
    }
});
</script>

</body>
</html>