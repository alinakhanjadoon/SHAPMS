<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

/* ---------- FETCH BED STATUS ---------- */
$p = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
$p->bind_param("i", $_SESSION['user_id']);
$p->execute();
$p->bind_result($patient_id);
$p->fetch();
$p->close();

$stmt = $conn->prepare("
    SELECT bed_id, room_number, bed_number, status
    FROM beds
    WHERE patient_id = ?
");

if (!$stmt) {
    die("Query prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$beds = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch nursing notes for this patient
$notes = [];
$nstmt = $conn->prepare("
    SELECT nn.note_type, nn.note_text, nn.created_at, u.full_name AS nurse_name
    FROM nursing_notes nn
    JOIN users u ON nn.created_by = u.user_id
    WHERE nn.patient_id = ?
    ORDER BY nn.created_at DESC
");
$nstmt->bind_param("i", $patient_id);
$nstmt->execute();
$notes = $nstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$nstmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bed Status | Patient Dashboard</title>
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
    --available: #10b981;
    --occupied: #ef4444;
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
    max-width: 800px;
    margin: 50px auto;
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

.bed-count {
    background: var(--purple-soft);
    padding: 8px 18px;
    border-radius: 40px;
    font-weight: 600;
    font-size: 14px;
    color: var(--purple-deep);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Glass Card */
.bed-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px var(--shadow);
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
}

.bed-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 40px rgba(124,58,237,0.15);
}

/* Table Styles */
.table-wrapper {
    overflow-x: auto;
}

.bed-table {
    width: 100%;
    border-collapse: collapse;
}

.bed-table th,
.bed-table td {
    padding: 16px 20px;
    text-align: center;
    border-bottom: 1px solid var(--border);
}

.bed-table th {
    background: linear-gradient(135deg, var(--purple-soft), rgba(167,139,250,0.1));
    color: var(--text-dark);
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.bed-table td {
    color: var(--text-mid);
    font-size: 14px;
    font-weight: 500;
}

.bed-table tr:hover td {
    background: var(--purple-soft);
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: 40px;
    font-size: 12px;
    font-weight: 600;
}

.status-available {
    background: rgba(16,185,129,0.15);
    color: var(--available);
    border: 1px solid rgba(16,185,129,0.3);
}

.status-occupied {
    background: rgba(239,68,68,0.15);
    color: var(--occupied);
    border: 1px solid rgba(239,68,68,0.3);
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
    .bed-table th,
    .bed-table td {
        padding: 12px 14px;
        font-size: 12px;
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
            <i class="fas fa-bed"></i>
            My Bed Status
        </h2>
        <div class="bed-count">
            <i class="fas fa-procedures"></i> Total Beds: <?= count($beds) ?>
        </div>
    </div>

    <?php if (empty($beds)) : ?>
        <div class="empty-state">
            <i class="fas fa-bed"></i>
            <p>No bed assigned yet</p>
            <small>Bed assignments will appear here when you are admitted to the hospital</small>
        </div>
    <?php else: ?>
        <div class="bed-card">
            <div class="table-wrapper">
                <table class="bed-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-barcode"></i> Bed ID</th>
                            <th><i class="fas fa-door-open"></i> Room Number</th>
                            <th><i class="fas fa-bed"></i> Bed Number</th>
                            <th><i class="fas fa-chart-line"></i> Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($beds as $bed): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-hashtag" style="color: var(--purple-mid);"></i>
                                    <?= htmlspecialchars($bed['bed_id']) ?>
                                </td>
                                <td>
                                    <i class="fas fa-building" style="color: var(--purple-mid);"></i>
                                    <?= htmlspecialchars($bed['room_number']) ?>
                                </td>
                                <td>
                                    <i class="fas fa-bed" style="color: var(--purple-mid);"></i>
                                    <?= htmlspecialchars($bed['bed_number']) ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $bed['status'] ?>">
                                        <i class="fas <?= $bed['status'] == 'available' ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                        <?= ucfirst($bed['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<!-- NURSING NOTES SECTION -->
<div class="stats-header" style="margin-top: 40px;">
    <h2>
        <i class="fas fa-notes-medical"></i>
        Nursing Notes
    </h2>
    <div class="bed-count">
        <i class="fas fa-file-medical"></i> Total: <?= count($notes) ?>
    </div>
</div>

<?php if (empty($notes)): ?>
    <div class="empty-state">
        <i class="fas fa-notes-medical"></i>
        <p>No nursing notes yet</p>
        <small>Notes written by your nurse will appear here</small>
    </div>
<?php else: ?>
    <div class="bed-card" style="margin-bottom: 40px;">
        <div class="table-wrapper">
            <table class="bed-table">
                <thead>
                    <tr>
                        <th><i class="fas fa-user-nurse"></i> Nurse</th>
                        <th><i class="fas fa-tag"></i> Type</th>
                        <th><i class="fas fa-file-alt"></i> Note</th>
                        <th><i class="fas fa-clock"></i> Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notes as $note): ?>
                    <tr>
                        <td>
                            <i class="fas fa-user-nurse" style="color: var(--purple-mid);"></i>
                            <?= htmlspecialchars($note['nurse_name']) ?>
                        </td>
                        <td>
                            <span class="status-badge" style="background: var(--purple-soft); color: var(--purple-deep); border: 1px solid var(--purple-light);">
                                <?= htmlspecialchars($note['note_type']) ?>
                            </span>
                        </td>
                        <td style="text-align: left;">
                            <?= htmlspecialchars($note['note_text']) ?>
                        </td>
                        <td style="color: var(--text-light); font-size: 12px;">
                            <i class="fas fa-calendar-alt" style="color: var(--purple-mid);"></i>
                            <?= date('M d, Y h:i A', strtotime($note['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

</div>

</body>
</html>