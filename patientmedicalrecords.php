<?php
session_start();

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");

if ($conn->connect_error) {
    die("Database connection failed");
}

/* ---------- GET PATIENT ID ---------- */
$stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();

$patient_res = $stmt->get_result();
$patient_row = $patient_res->fetch_assoc();

$patient_id = $patient_row['patient_id'] ?? 0;

$stmt->close();

/* ---------- FETCH MEDICAL RECORDS ---------- */
$stmt = $conn->prepare("
    SELECT 
        mr.medical_record_id,
        mr.diagnosis,
        mr.lab_reports,
        mr.notes,
        mr.created_at,
        u.full_name AS doctor_name
    FROM medical_records mr
    JOIN doctors d ON mr.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    WHERE mr.patient_id = ?
    ORDER BY mr.created_at DESC
");

$stmt->bind_param("i", $patient_id);
$stmt->execute();

$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Medical Records | Patient Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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

header a {
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

header a:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(124,58,237,0.4);
}

/* Main Container */
.container {
    position: relative;
    z-index: 2;
    max-width: 1100px;
    margin: 40px auto;
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
    font-size: 28px;
    font-weight: 600;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 10px;
}

.stats-header h2 i {
    color: var(--purple-deep);
}

.record-count {
    background: var(--purple-soft);
    padding: 8px 18px;
    border-radius: 40px;
    font-weight: 600;
    font-size: 14px;
    color: var(--purple-deep);
}

/* Medical Record Card */
.medical-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px var(--shadow);
    margin-bottom: 24px;
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
}

.medical-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 40px rgba(124,58,237,0.15);
}

/* Card Header */
.card-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--purple-soft), rgba(167,139,250,0.1));
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.card-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.card-header-left i {
    font-size: 28px;
    color: var(--purple-deep);
}

.card-header-left h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}

.record-date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-mid);
}

.record-date i {
    font-size: 12px;
    color: var(--purple-mid);
}

/* Card Body */
.card-body {
    padding: 20px 24px;
}

.doctor-info {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.doctor-avatar {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.doctor-details {
    flex: 1;
}

.doctor-details .label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.doctor-details .name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-dark);
}

/* Info Section */
.info-section {
    margin-bottom: 20px;
}

.info-section:last-child {
    margin-bottom: 0;
}

.section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.section-title i {
    font-size: 16px;
    color: var(--purple-deep);
}

.section-title strong {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-mid);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.section-content {
    background: var(--purple-soft);
    border-radius: 16px;
    padding: 16px;
}

.section-content p {
    font-size: 14px;
    line-height: 1.6;
    color: var(--text-mid);
    white-space: pre-wrap;
}

/* Empty State */
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

.empty-state small {
    color: var(--text-light);
    font-size: 13px;
}

/* Responsive */
@media (max-width: 640px) {
    header {
        flex-direction: column;
        text-align: center;
    }
    .container {
        padding: 0 16px;
        margin: 30px auto;
    }
    .stats-header {
        flex-direction: column;
        text-align: center;
    }
    .card-header {
        flex-direction: column;
        text-align: center;
    }
    .doctor-info {
        flex-direction: column;
        text-align: center;
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
            <i class="fas fa-folder-medical"></i>
            My Medical Records
        </h2>
        <div class="record-count">
            <i class="fas fa-file-alt"></i> Total: <?= $result->num_rows ?> Records
        </div>
    </div>

    <?php if ($result->num_rows === 0): ?>
        <div class="empty-state">
            <i class="fas fa-notes-medical"></i>
            <p>No medical records found</p>
            <small>Medical records will appear here after your consultations</small>
        </div>
    <?php endif; ?>

    <?php while ($row = $result->fetch_assoc()): ?>
        <div class="medical-card">
            <div class="card-header">
                <div class="card-header-left">
                    <i class="fas fa-file-medical-alt"></i>
                    <h3>Medical Record #<?= htmlspecialchars($row['medical_record_id']) ?></h3>
                </div>
                <div class="record-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?= date("d M Y", strtotime($row['created_at'])) ?>
                </div>
            </div>
            <div class="card-body">
                <div class="doctor-info">
                    <div class="doctor-avatar">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <div class="doctor-details">
                        <div class="label">Attending Physician</div>
                        <div class="name">Dr. <?= htmlspecialchars($row['doctor_name']) ?></div>
                    </div>
                </div>

                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-stethoscope"></i>
                        <strong>Diagnosis</strong>
                    </div>
                    <div class="section-content">
                        <p><?= nl2br(htmlspecialchars($row['diagnosis'])) ?></p>
                    </div>
                </div>

                <?php if (!empty($row['lab_reports'])): ?>
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-flask"></i>
                        <strong>Lab Reports</strong>
                    </div>
                    <div class="section-content">
                        <p><?= nl2br(htmlspecialchars($row['lab_reports'])) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($row['notes'])): ?>
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-pen-alt"></i>
                        <strong>Clinical Notes</strong>
                    </div>
                    <div class="section-content">
                        <p><?= nl2br(htmlspecialchars($row['notes'])) ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endwhile; ?>
</div>

</body>
</html>