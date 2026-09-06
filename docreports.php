<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

/* ---------- GET REAL DOCTOR ID ---------- */
$stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($doctor_id);
$stmt->fetch();
$stmt->close();

if (!$doctor_id) {
    die("Doctor profile not found!");
}

/* ---------- MONTHLY APPOINTMENTS (REAL DATA) ---------- */
$months = [];
$appointments_per_month = [];

$stmt = $conn->prepare("
    SELECT MONTH(appointment_date) AS month, COUNT(*) AS total
    FROM appointments
    WHERE doctor_id = ?
    AND YEAR(appointment_date) = YEAR(CURDATE())
    GROUP BY MONTH(appointment_date)
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();

$data = array_fill(1, 12, 0);

while ($row = $result->fetch_assoc()) {
    $data[(int)$row['month']] = (int)$row['total'];
}
$stmt->close();

$monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

foreach ($monthNames as $i => $name) {
    $months[] = $name;
    $appointments_per_month[] = $data[$i + 1];
}

/* ---------- STATUS DISTRIBUTION (REAL-TIME FIX) ---------- */
$stmt = $conn->prepare("
    SELECT status, COUNT(*) as total
    FROM appointments
    WHERE doctor_id = ?
    GROUP BY status
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();

$stats = [
    'scheduled' => 0,
    'approved' => 0,
    'completed' => 0,
    'cancelled' => 0
];

while ($row = $result->fetch_assoc()) {
    $stats[$row['status']] = (int)$row['total'];
}
$stmt->close();

/* ---------- TODAY / WEEK / MONTH ---------- */

/* Today */
$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM appointments
    WHERE doctor_id = ?
    AND DATE(appointment_date) = CURDATE()
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$today_count = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

/* Week */
$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM appointments
    WHERE doctor_id = ?
    AND YEARWEEK(appointment_date, 1) = YEARWEEK(CURDATE(), 1)
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$week_count = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

/* Month */
$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM appointments
    WHERE doctor_id = ?
    AND YEAR(appointment_date) = YEAR(CURDATE())
    AND MONTH(appointment_date) = MONTH(CURDATE())
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$month_count = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// Get doctor name
$doc_name = "Doctor";
$name_stmt = $conn->prepare("SELECT u.full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
$name_stmt->bind_param("i", $_SESSION['user_id']);
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
<title>Doctor Reports | Zaman Medical</title>

<!-- Google Fonts + Font Awesome -->
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

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
    max-width:1300px;
    margin:30px auto;
    padding:0 24px;
}

/* DOCTOR BADGE */
.doc-badge {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:30px;
    padding:20px 28px;
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
}

.doc-info {
    display:flex;
    align-items:center;
    gap:15px;
}

.doc-avatar {
    width:55px;
    height:55px;
    border-radius:16px;
    background:linear-gradient(135deg, var(--b5), var(--acc));
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:24px;
    color:#fff;
}

.doc-details h3 {
    font-size:20px;
    font-weight:700;
    color:#fff;
    margin-bottom:4px;
}

.doc-details span {
    font-size:12px;
    color:var(--mut);
}

/* STAT CARDS */
.stats-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
    gap:24px;
    margin-bottom:40px;
}

.stat-card {
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
    padding:24px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    transition:transform .25s, box-shadow .25s;
}

.stat-card:hover {
    transform:translateY(-3px);
    box-shadow:0 20px 40px -12px rgba(0,0,0,.4);
}

.stat-info h4 {
    font-size:12px;
    color:var(--mut);
    text-transform:uppercase;
    letter-spacing:.08em;
    margin-bottom:8px;
}

.stat-info .stat-number {
    font-size:42px;
    font-weight:800;
    color:#fff;
    line-height:1;
}

.stat-info p {
    font-size:12px;
    color:var(--b8);
    margin-top:8px;
}

.stat-icon {
    width:60px;
    height:60px;
    border-radius:18px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:28px;
}

.stat-card.today .stat-icon { background:rgba(57,73,171,.3); color:var(--b7); }
.stat-card.week .stat-icon { background:rgba(126,87,194,.25); color:var(--acc2); }
.stat-card.month .stat-icon { background:rgba(92,107,192,.25); color:var(--b8); }

/* CHARTS GRID */
.charts-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(450px,1fr));
    gap:28px;
}

.chart-card {
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
    padding:24px;
    transition:box-shadow .25s;
}

.chart-card:hover {
    box-shadow:0 20px 40px -12px rgba(0,0,0,.4);
}

.chart-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
    padding-bottom:12px;
    border-bottom:1px solid var(--gb);
}

.chart-header h5 {
    font-size:18px;
    font-weight:700;
    color:#fff;
    display:flex;
    align-items:center;
    gap:10px;
}

.chart-header h5 i {
    color:var(--acc);
    font-size:18px;
}

.chart-header span {
    font-size:11px;
    color:var(--mut);
    background:rgba(57,73,171,.2);
    padding:4px 12px;
    border-radius:40px;
}

canvas {
    max-height:350px;
    width:100%;
}

/* responsive */
@media (max-width: 768px) {
    header {
        flex-direction:column;
        text-align:center;
    }
    .container {
        padding:0 16px;
    }
    .stats-grid {
        grid-template-columns:1fr;
    }
    .charts-grid {
        grid-template-columns:1fr;
    }
    .doc-badge {
        flex-direction:column;
        text-align:center;
        gap:15px;
    }
}
</style>
</head>

<body>

<header>
    <div class="logo-area">
        <i class="fa-solid fa-chart-line"></i>
        <h2>Clinical Analytics & Reports</h2>
    </div>
    <a href="doctordashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
</header>

<div class="container">

    <div class="doc-badge">
        <div class="doc-info">
            <div class="doc-avatar"><i class="fa-solid fa-user-md"></i></div>
            <div class="doc-details">
                <h3>Dr. <?= htmlspecialchars($doc_name) ?></h3>
                <span><i class="fa-regular fa-calendar"></i> Report Generated: <?= date('l, d F Y') ?></span>
            </div>
        </div>
        <i class="fa-solid fa-chart-simple" style="color:var(--acc2); font-size:32px;"></i>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-grid">
        <div class="stat-card today">
            <div class="stat-info">
                <h4><i class="fa-regular fa-calendar-day"></i> Today's Appointments</h4>
                <div class="stat-number"><?= $today_count ?></div>
                <p><?= date('d M Y') ?></p>
            </div>
            <div class="stat-icon">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
        </div>

        <div class="stat-card week">
            <div class="stat-info">
                <h4><i class="fa-regular fa-calendar-week"></i> This Week</h4>
                <div class="stat-number"><?= $week_count ?></div>
                <p>Weekly total appointments</p>
            </div>
            <div class="stat-icon">
                <i class="fa-solid fa-chart-simple"></i>
            </div>
        </div>

        <div class="stat-card month">
            <div class="stat-info">
                <h4><i class="fa-regular fa-calendar"></i> This Month</h4>
                <div class="stat-number"><?= $month_count ?></div>
                <p><?= date('F Y') ?> overview</p>
            </div>
            <div class="stat-icon">
                <i class="fa-solid fa-chart-column"></i>
            </div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="charts-grid">
        
        <!-- Line Chart - Monthly Appointments -->
        <div class="chart-card">
            <div class="chart-header">
                <h5><i class="fa-solid fa-chart-line"></i> Monthly Appointments Trend</h5>
                <span><i class="fa-regular fa-calendar"></i> <?= date('Y') ?></span>
            </div>
            <canvas id="lineChart"></canvas>
        </div>

        <!-- Doughnut Chart - Status Distribution -->
        <div class="chart-card">
            <div class="chart-header">
                <h5><i class="fa-solid fa-chart-pie"></i> Appointment Status Distribution</h5>
                <span>Real-time data</span>
            </div>
            <canvas id="doughnutChart"></canvas>
            
            <!-- Status Legend -->
            <div style="display:flex; justify-content:center; gap:20px; margin-top:20px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#ff9800;"></div>
                    <span style="font-size:12px; color:var(--mut);">Scheduled: <?= $stats['scheduled'] ?></span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#2196f3;"></div>
                    <span style="font-size:12px; color:var(--mut);">Approved: <?= $stats['approved'] ?></span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#4caf50;"></div>
                    <span style="font-size:12px; color:var(--mut);">Completed: <?= $stats['completed'] ?></span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#f44336;"></div>
                    <span style="font-size:12px; color:var(--mut);">Cancelled: <?= $stats['cancelled'] ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Line Chart - Monthly Appointments
const ctx1 = document.getElementById('lineChart').getContext('2d');
const gradient1 = ctx1.createLinearGradient(0, 0, 0, 350);
gradient1.addColorStop(0, 'rgba(57,73,171,0.5)');
gradient1.addColorStop(1, 'rgba(126,87,194,0.05)');

new Chart(ctx1, {
    type: 'line',
    data: {
        labels: <?= json_encode($months) ?>,
        datasets: [{
            label: 'Appointments',
            data: <?= json_encode($appointments_per_month) ?>,
            borderColor: '#7e57c2',
            backgroundColor: gradient1,
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#5c6bc0',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 8,
            pointHoverBackgroundColor: '#b39ddb'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                labels: { color: '#9fa8da', font: { family: 'Outfit', size: 12, weight: '600' } }
            },
            tooltip: {
                backgroundColor: 'rgba(10,15,42,0.95)',
                titleColor: '#fff',
                bodyColor: '#9fa8da',
                borderColor: '#7e57c2',
                borderWidth: 1,
                padding: 10,
                cornerRadius: 8
            }
        },
        scales: {
            x: {
                grid: { color: 'rgba(255,255,255,0.05)' },
                ticks: { color: '#9fa8da', font: { family: 'Outfit', size: 11 } }
            },
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(255,255,255,0.05)' },
                ticks: { color: '#9fa8da', font: { family: 'Outfit', size: 11 }, precision: 0 }
            }
        }
    }
});

// Doughnut Chart - Status Distribution
new Chart(document.getElementById('doughnutChart'), {
    type: 'doughnut',
    data: {
        labels: ['Scheduled', 'Approved', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?= $stats['scheduled'] ?>,
                <?= $stats['approved'] ?>,
                <?= $stats['completed'] ?>,
                <?= $stats['cancelled'] ?>
            ],
            backgroundColor: ['#ff9800', '#2196f3', '#4caf50', '#f44336'],
            borderColor: 'rgba(10,15,42,0.8)',
            borderWidth: 3,
            hoverOffset: 10,
            cutout: '65%'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(10,15,42,0.95)',
                titleColor: '#fff',
                bodyColor: '#9fa8da',
                borderColor: '#7e57c2',
                borderWidth: 1,
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: function(context) {
                        return `${context.label}: ${context.raw} appointments`;
                    }
                }
            }
        }
    }
});
</script>

</body>
</html>