<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

/* ---------- DB ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
$conn->set_charset("utf8mb4");

/* ---------- SAFE FUNCTION ---------- */
function safe($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---------- PATIENT ID ---------- */
$patient_id = 0;

$stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($patient_id);
$stmt->fetch();
$stmt->close();

if (!$patient_id) {
    die("Patient not found.");
}

/* =====================================================
   DOCTORS (FOR AVAILABILITY TAB)
===================================================== */
$doctors = [];

$stmt = $conn->prepare("
    SELECT DISTINCT d.doctor_id, u.full_name
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    WHERE a.patient_id = ?
    ORDER BY u.full_name
");

$stmt->bind_param("i", $patient_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $doctors[] = $row;
}
$stmt->close();

/* =====================================================
   ALL DOCTORS (DIRECTORY) - FIXED SAFE VERSION
===================================================== */
$all_doctors = [];

$stmt = $conn->prepare("
    SELECT 
        d.doctor_id,
        u.full_name,
        u.email,
        u.contact,
        u.profile_image,
        d.specialty,
        d.qualifications,
        d.experience
    FROM doctors d
    JOIN users u ON d.user_id = u.user_id
    WHERE u.role = 'doctor' AND u.status = 'active'
    ORDER BY u.full_name
");

$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $all_doctors[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Directory | Patient Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --bg: #f3f0fb;
    --purple-deep: #7c3aed;
    --purple-mid: #a78bfa;
    --purple-light: #ddd6fe;
    --purple-soft: #ede9fe;
    --accent-pink: #f472b6;
    --text-dark: #1e1b3a;
    --text-mid: #5b5278;
    --text-light: #9c8fc0;
    --border: #e8e2f8;
    --shadow: rgba(124,58,237,0.10);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text-dark);
    min-height: 100vh;
    position: relative;
}

/* Animated Gradient Background */
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
    padding: 16px 28px;
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
    font-size: 26px;
    color: var(--purple-deep);
}

header .logo-area span {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--text-dark);
}

header a {
    background: linear-gradient(135deg, var(--purple-deep), var(--accent-pink));
    color: white;
    padding: 6px 18px;
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

header a:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(124,58,237,0.4);
}

/* Main Container */
.container {
    position: relative;
    z-index: 2;
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 24px;
}

/* Stats Header */
.stats-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.stats-header h2 {
    font-family: 'Playfair Display', serif;
    font-size: 26px;
    font-weight: 600;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 10px;
}

.stats-header h2 i {
    color: var(--purple-deep);
}

.doctor-count {
    background: var(--purple-soft);
    padding: 6px 16px;
    border-radius: 40px;
    font-weight: 600;
    font-size: 13px;
    color: var(--purple-deep);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Section Tabs */
.section-tabs {
    display: flex;
    gap: 16px;
    margin-bottom: 30px;
    border-bottom: 2px solid var(--border);
    padding-bottom: 12px;
}

.tab-btn {
    background: none;
    border: none;
    padding: 10px 24px;
    font-size: 15px;
    font-weight: 600;
    color: var(--text-mid);
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 40px;
    font-family: 'DM Sans', sans-serif;
}

.tab-btn:hover {
    color: var(--purple-deep);
    background: var(--purple-soft);
}

.tab-btn.active {
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    color: white;
    box-shadow: 0 2px 10px rgba(124,58,237,0.3);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Doctors Grid */
.doctors-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
    margin-top: 20px;
}

.doctor-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px var(--shadow);
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
}

.doctor-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(124,58,237,0.15);
}

.doctor-card-header {
    background: linear-gradient(135deg, var(--purple-soft), rgba(167,139,250,0.1));
    padding: 20px;
    text-align: center;
    border-bottom: 1px solid var(--border);
}

.doctor-avatar-lg {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--purple-deep);
    margin-bottom: 12px;
    background: var(--purple-soft);
}

.doctor-card-header h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 6px;
}

.specialty-badge {
    display: inline-block;
    background: var(--purple-light);
    color: var(--purple-deep);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.doctor-card-body {
    padding: 20px;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 13px;
    color: var(--text-mid);
}

.info-row i {
    width: 20px;
    color: var(--purple-deep);
}

.book-link {
    display: inline-block;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    color: white;
    padding: 10px 20px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    margin-top: 15px;
    transition: all 0.2s;
    text-align: center;
    width: 100%;
}

.book-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(124,58,237,0.4);
}

/* Doctor Availability Card */
.doctor-availability-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px var(--shadow);
    margin-bottom: 28px;
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
}

.doctor-availability-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 40px rgba(124,58,237,0.15);
}

.doctor-header {
    padding: 18px 24px;
    background: linear-gradient(135deg, var(--purple-soft), rgba(167,139,250,0.1));
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 12px;
}

.doctor-avatar {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.doctor-info h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}

.doctor-info p {
    font-size: 12px;
    color: var(--text-mid);
    margin: 4px 0 0;
}

.chart-box {
    padding: 24px;
}

.no-data {
    text-align: center;
    padding: 40px;
    color: var(--text-light);
    font-size: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
}

.empty-state i {
    font-size: 64px;
    color: var(--text-light);
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state p {
    font-size: 18px;
    color: var(--text-mid);
    margin-bottom: 8px;
}

/* Responsive */
@media (max-width: 768px) {
    header {
        flex-direction: column;
        text-align: center;
    }
    .container {
        padding: 0 16px;
    }
    .stats-header {
        flex-direction: column;
        text-align: center;
    }
    .section-tabs {
        justify-content: center;
    }
    .doctors-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<header>
    <div class="logo-area">
        <i class="fas fa-heartbeat"></i>
        <span>SHAPMS</span>
    </div>
    <a href="patientdashboard.php">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</header>

<div class="container">
    
    <div class="stats-header">
        <h2>
            <i class="fas fa-user-md"></i>
            Doctor Directory
        </h2>
        <div class="doctor-count">
            <i class="fas fa-stethoscope"></i> Total Doctors: <?= count($all_doctors) ?>
        </div>
    </div>

    <!-- Section Tabs -->
    <div class="section-tabs">
        <button class="tab-btn active" onclick="showTab('doctors')">
            <i class="fas fa-list"></i> All Doctors
        </button>
        <button class="tab-btn" onclick="showTab('availability')">
            <i class="fas fa-calendar-alt"></i> Availability
        </button>
    </div>

    <!-- Tab 1: All Doctors List -->
    <div id="doctors" class="tab-content active">
        <div class="doctors-grid">
            <?php foreach ($all_doctors as $doc): ?>
                <div class="doctor-card">
                    <div class="doctor-card-header">
                        <?php
                        $img = $doc['profile_image'];
                        $imgPath = (!empty($img) && file_exists($_SERVER['DOCUMENT_ROOT'].'/hospital/'.$img))
                            ? $img
                            : "https://ui-avatars.com/api/?name=" . urlencode($doc['full_name']) . "&background=7c3aed&color=fff&size=100&bold=true";
                        ?>
                        <img src="<?= $imgPath ?>" alt="Doctor" class="doctor-avatar-lg" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($doc['full_name']) ?>&background=7c3aed&color=fff&size=100&bold=true'">
                        <h3>Dr. <?= safe($doc['full_name']) ?></h3>
                        <span class="specialty-badge"><?= safe($doc['specialty'] ?? 'General Physician') ?></span>
                    </div>
                    <div class="doctor-card-body">
                        <div class="info-row">
                            <i class="fas fa-envelope"></i>
                            <span><?= safe($doc['email']) ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-phone"></i>
                            <span><?= safe($doc['contact'] ?? 'Not available') ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-graduation-cap"></i>
                            <span><strong>Qualifications:</strong> <?= safe($doc['qualifications'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-briefcase"></i>
                            <span><strong>Experience:</strong> <?= safe($doc['experience'] ?? 'Not specified') ?></span>
                        </div>
                        <a class="book-link" href="bookappointment.php?doctor_id=<?= $doc['doctor_id'] ?>">
                            <i class="fas fa-calendar-plus"></i> Book Appointment
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tab 2: Availability -->
    <div id="availability" class="tab-content">
        <?php if (count($doctors) == 0): ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <p>No appointment history found.</p>
                <small>Book appointments to see doctor availability charts</small>
            </div>
        <?php else: ?>
            <?php foreach ($doctors as $doc): ?>
                <?php
                $availability = [];
                $stmt = $conn->prepare("
                    SELECT date 
                    FROM doctor_availability 
                    WHERE doctor_id = ?
                    ORDER BY date
                ");
                $stmt->bind_param("i", $doc['doctor_id']);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $availability[] = date('d M', strtotime($row['date']));
                }
                $stmt->close();
                ?>
                <div class="doctor-availability-card">
                    <div class="doctor-header">
                        <div class="doctor-avatar">
                            <i class="fas fa-user-md"></i>
                        </div>
                        <div class="doctor-info">
                            <h3>Dr. <?= safe($doc['full_name']) ?></h3>
                            <p><i class="fas fa-calendar-alt"></i> Weekly Schedule Overview</p>
                        </div>
                    </div>
                    <?php if (!empty($availability)): ?>
                        <div class="chart-box">
                            <canvas id="chart<?= $doc['doctor_id'] ?>"></canvas>
                        </div>
                        <script>
                        new Chart(document.getElementById("chart<?= $doc['doctor_id'] ?>"), {
                            type: 'line',
                            data: {
                                labels: <?= json_encode($availability) ?>,
                                datasets: [{
                                    label: 'Availability',
                                    data: <?= json_encode(array_fill(0, count($availability), 1)) ?>,
                                    borderColor: '#7c3aed',
                                    backgroundColor: 'rgba(124,58,237,0.1)',
                                    tension: 0.3,
                                    fill: true,
                                    pointRadius: 6,
                                    pointBackgroundColor: '#a78bfa',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointHoverRadius: 8,
                                    borderWidth: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                plugins: {
                                    legend: { 
                                        labels: { 
                                            color: '#5b5278',
                                            font: { family: 'DM Sans', size: 11, weight: '500' },
                                            boxWidth: 10
                                        } 
                                    },
                                    tooltip: {
                                        backgroundColor: 'white',
                                        titleColor: '#1e1b3a',
                                        bodyColor: '#5b5278',
                                        borderColor: '#e8e2f8',
                                        borderWidth: 1,
                                        padding: 10,
                                        cornerRadius: 8
                                    }
                                },
                                scales: {
                                    x: { 
                                        ticks: { color: '#5b5278', font: { size: 11 } },
                                        grid: { color: 'rgba(167,139,250,0.1)' }
                                    },
                                    y: { display: false }
                                }
                            }
                        });
                        </script>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-calendar-times"></i>
                            <p>No schedule available</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function showTab(tab){
    // Update tab buttons
    const btns = document.querySelectorAll('.tab-btn');
    btns.forEach(btn => btn.classList.remove('active'));
    
    // Update tab content visibility
    document.getElementById('doctors').classList.remove('active');
    document.getElementById('availability').classList.remove('active');
    
    if (tab === 'doctors') {
        document.getElementById('doctors').classList.add('active');
        btns[0].classList.add('active');
    } else {
        document.getElementById('availability').classList.add('active');
        btns[1].classList.add('active');
    }
}
</script>

</body>
</html>