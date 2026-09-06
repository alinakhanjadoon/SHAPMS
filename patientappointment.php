<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Connection Failed: " . $conn->connect_error);

/* ---------- FETCH PATIENT ID ---------- */
$res = $conn->prepare("SELECT patient_id FROM patients WHERE user_id=?");
$res->bind_param("i", $_SESSION['user_id']);
$res->execute();
$res->bind_result($patient_id);
$res->fetch();
$res->close();

/* ---------- CANCEL APPOINTMENT ---------- */
if (isset($_GET['cancel'])) {
    $appointment_id = (int) $_GET['cancel'];

    $stmt = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE appointment_id=? AND patient_id=? AND status='approved'");
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $stmt->close();

    header("Location: patientappointment.php");
    exit();
}

/* ---------- FETCH APPOINTMENTS ---------- */
$stmt = $conn->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.status,
        u.full_name AS doctor_name,
        d.specialty
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

/* ---------- FETCH STATS FOR GRAPHS ---------- */
$statuses = ['approved','completed','cancelled'];
$stats = [];
foreach($statuses as $status){
    $stmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id=? AND status=?");
    $stmt->bind_param("is", $patient_id, $status);
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
<title>My Appointments | Patient Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --bg: #f3f0fb;
    --bg2: #ece8f8;
    --purple-deep: #7c3aed;
    --purple-mid: #a78bfa;
    --purple-light: #ddd6fe;
    --purple-soft: #ede9fe;
    --lilac: #c4b5fd;
    --accent-pink: #f472b6;
    --text-dark: #1e1b3a;
    --text-mid: #5b5278;
    --text-light: #9c8fc0;
    --border: #e8e2f8;
    --shadow: rgba(124,58,237,0.10);
    --scheduled: #f59e0b;
    --completed: #10b981;
    --cancelled: #ef4444;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text-dark);
    min-height: 100vh;
    position: relative;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: 
        radial-gradient(circle at 20% 30%, rgba(167,139,250,0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(244,114,182,0.12) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(124,58,237,0.08) 0%, transparent 60%);
    z-index: 0;
    pointer-events: none;
}

/* Header */
header {
    position: relative;
    z-index: 2;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    padding: 18px 28px;
    border-bottom: 1px solid var(--border);
    box-shadow: 0 4px 20px var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

header .logo-area {
    display: flex;
    align-items: center;
    gap: 10px;
}

header .logo-area i {
    font-size: 28px;
    color: var(--purple-deep);
}

header .logo-area span {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--text-dark);
}

.header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

header a.back-btn {
    background: linear-gradient(135deg, var(--purple-deep), var(--accent-pink));
    color: white;
    padding: 8px 20px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.25s;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 10px rgba(124,58,237,0.3);
}

header a.back-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(124,58,237,0.4);
}

/* Main Container */
.container {
    position: relative;
    z-index: 2;
    max-width: 1300px;
    margin: 30px auto;
    padding: 0 24px;
}

/* Stats Cards Row */
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    border: 1px solid var(--border);
    box-shadow: 0 4px 20px var(--shadow);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.25s;
}

.stat-card:hover { transform: translateY(-3px); }
.stat-card.scheduled { border-left: 4px solid var(--scheduled); }
.stat-card.completed { border-left: 4px solid var(--completed); }
.stat-card.cancelled { border-left: 4px solid var(--cancelled); }

.stat-info h4 {
    font-size: 12px;
    color: var(--text-mid);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.stat-number {
    font-size: 32px;
    font-weight: 700;
    color: var(--text-dark);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.stat-card.scheduled .stat-icon { background: rgba(245,158,11,0.15); color: var(--scheduled); }
.stat-card.completed .stat-icon { background: rgba(16,185,129,0.15); color: var(--completed); }
.stat-card.cancelled .stat-icon { background: rgba(239,68,68,0.15); color: var(--cancelled); }

/* Flex Layout */
.flex-layout {
    display: flex;
    flex-wrap: wrap;
    gap: 30px;
}

.table-container { flex: 2; min-width: 300px; }
.chart-container { flex: 1; min-width: 300px; }

/* Glass Card */
.glass-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px var(--shadow);
    overflow: hidden;
    transition: transform 0.25s;
}

.glass-card:hover { transform: translateY(-2px); }

.card-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header i { font-size: 24px; color: var(--purple-deep); }

.card-header h2 {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}

/* Table Styles */
.table-wrapper { overflow-x: auto; }

.appointments-table { width: 100%; border-collapse: collapse; }

.appointments-table th,
.appointments-table td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.appointments-table th {
    background: var(--purple-soft);
    color: var(--text-dark);
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.appointments-table td { color: var(--text-mid); font-size: 14px; }
.appointments-table tr:hover td { background: var(--purple-soft); }

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 40px;
    font-size: 12px;
    font-weight: 600;
}

.status-scheduled { background: rgba(245,158,11,0.15); color: var(--scheduled); }
.status-completed  { background: rgba(16,185,129,0.15); color: var(--completed); }
.status-cancelled  { background: rgba(239,68,68,0.15);  color: var(--cancelled); }

/* Cancel Button */
.cancel-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    padding: 6px 14px;
    border-radius: 40px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.cancel-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239,68,68,0.4);
}

/* Chart Card */
.chart-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px var(--shadow);
    padding: 24px;
}

.chart-card h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-card h3 i { color: var(--purple-deep); }

canvas { max-height: 280px; }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}

/* Notification widget overrides to match purple theme */
.notif-bell {
    border-color: var(--border) !important;
    background: white !important;
}
.notif-bell:hover { background: var(--purple-soft) !important; }
.notif-badge { background: var(--purple-deep) !important; }
.notif-item.unread { background: var(--purple-soft) !important; }
.notif-dd-head button { color: var(--purple-deep) !important; }

/* Responsive */
@media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr; gap: 12px; }
    .flex-layout { flex-direction: column; }
    header { flex-direction: column; text-align: center; }
    .header-right { justify-content: center; }
    .appointments-table th,
    .appointments-table td { padding: 10px 12px; font-size: 12px; }
}
</style>
</head>
<body>

<header>
    <div class="logo-area">
        <i class="fas fa-heartbeat"></i>
        <span>SHAPMS</span>
    </div>
    <div class="header-right">
        <!-- Notification API path (same folder as this file) -->
        <script>window.NOTIF_API_PATH = 'notifications_api.php';</script>
        <?php include __DIR__ . '/includes/notification_widget.php'; ?>

        <a href="patientdashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</header>

<div class="container">
    <!-- Stats Cards -->
    <div class="stats-row">
        <div class="stat-card scheduled">
            <div class="stat-info">
                <h4><i class="fas fa-clock"></i> Upcoming</h4>
                <div class="stat-number"><?= $stats['approved'] ?></div>
            </div>
            <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
        </div>
        <div class="stat-card completed">
            <div class="stat-info">
                <h4><i class="fas fa-check-circle"></i> Completed</h4>
                <div class="stat-number"><?= $stats['completed'] ?></div>
            </div>
            <div class="stat-icon"><i class="fas fa-check-double"></i></div>
        </div>
        <div class="stat-card cancelled">
            <div class="stat-info">
                <h4><i class="fas fa-times-circle"></i> Cancelled</h4>
                <div class="stat-number"><?= $stats['cancelled'] ?></div>
            </div>
            <div class="stat-icon"><i class="fas fa-ban"></i></div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-layout">
        <!-- Appointments Table -->
        <div class="table-container">
            <div class="glass-card">
                <div class="card-header">
                    <i class="fas fa-notes-medical"></i>
                    <h2>Your Appointments</h2>
                </div>
                <div class="table-wrapper">
                    <table class="appointments-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Doctor</th>
                                <th>Specialty</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            $found = false;
                            while($row = $result->fetch_assoc()):
                                $found = true;
                            ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                                <td><?= htmlspecialchars($row['specialty'] ?? 'General') ?></td>
                                <td><?= date('M d, Y', strtotime($row['appointment_date'])) ?></td>
                                <td><?= date('h:i A', strtotime($row['appointment_date'])) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $row['status'] ?>">
                                        <i class="fas <?= $row['status'] == 'approved' ? 'fa-clock' : ($row['status'] == 'completed' ? 'fa-check-circle' : 'fa-times-circle') ?>"></i>
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($row['status'] === 'approved'): ?>
                                    <a href="?cancel=<?= $row['appointment_id'] ?>" class="cancel-btn"
                                       onclick="return confirm('Are you sure you want to cancel this appointment?')">
                                        <i class="fas fa-ban"></i> Cancel
                                    </a>
                                    <?php else: ?>
                                    <span style="color: var(--text-light); font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if(!$found): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-calendar-times"></i>
                                        <p>No appointments found</p>
                                        <small>Book an appointment from the dashboard</small>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="chart-container">
            <div class="chart-card">
                <h3>
                    <i class="fas fa-chart-pie"></i>
                    Appointment Status Overview
                </h3>
                <canvas id="appointmentChart"></canvas>
                <div style="margin-top: 20px; text-align: center;">
                    <div style="display: inline-flex; gap: 16px; flex-wrap: wrap; justify-content: center;">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <div style="width: 10px; height: 10px; border-radius: 50%; background: #f59e0b;"></div>
                            <span style="font-size: 12px; color: var(--text-mid);">Upcoming: <?= $stats['approved'] ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <div style="width: 10px; height: 10px; border-radius: 50%; background: #10b981;"></div>
                            <span style="font-size: 12px; color: var(--text-mid);">Completed: <?= $stats['completed'] ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <div style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444;"></div>
                            <span style="font-size: 12px; color: var(--text-mid);">Cancelled: <?= $stats['cancelled'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('appointmentChart').getContext('2d');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Upcoming', 'Completed', 'Cancelled'],
        datasets: [{
            data: [<?= $stats['approved'] ?>, <?= $stats['completed'] ?>, <?= $stats['cancelled'] ?>],
            backgroundColor: ['#f59e0b', '#10b981', '#ef4444'],
            borderColor: 'white',
            borderWidth: 2,
            hoverOffset: 10,
            cutout: '60%'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'white',
                titleColor: '#1e2a4a',
                bodyColor: '#5b5278',
                borderColor: '#e8e2f8',
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