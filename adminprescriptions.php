<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------------- AUTH ---------------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

/* ---------------- DB ---------------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

/* ---------------- DELETE PRESCRIPTION ---------------- */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM prescriptions WHERE prescription_id=$id");

    header("Location: adminprescriptions.php");
    exit();
}

/* ---------------- UPDATE STATUS ---------------- */
if (isset($_GET['status'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['status'];

    $stmt = $conn->prepare("UPDATE prescriptions SET status=? WHERE prescription_id=?");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: adminprescriptions.php");
    exit();
}

/* ---------------- PRESCRIPTIONS LIST ---------------- */
$prescriptions = $conn->query("
    SELECT 
        pr.prescription_id,
        pr.appointment_id,
        pr.patient_id,
        pr.prescription_details,
        pr.status,
        pr.created_at,
        u.full_name AS patient_name
    FROM prescriptions pr
    LEFT JOIN users u ON u.user_id = pr.patient_id
    ORDER BY pr.created_at DESC
");

/* ---------------- STATS ---------------- */
$total = $conn->query("SELECT COUNT(*) FROM prescriptions")->fetch_row()[0];
$pending = $conn->query("SELECT COUNT(*) FROM prescriptions WHERE status='pending'")->fetch_row()[0];
$verified = $conn->query("SELECT COUNT(*) FROM prescriptions WHERE status='verified'")->fetch_row()[0];
$dispensed = $conn->query("SELECT COUNT(*) FROM prescriptions WHERE status='dispensed'")->fetch_row()[0];

/* ---------------- ADMIN INFO ---------------- */
$user_id = (int)$_SESSION['user_id'];
$admin = $conn->query("SELECT full_name, profile_image FROM users WHERE user_id=$user_id")->fetch_assoc();
$admin_name = $admin['full_name'] ?? 'Admin';
$profile = $admin['profile_image'] ?? 'default-avatar.png';

date_default_timezone_set("Asia/Karachi");
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Prescriptions Management — Zaman Medical</title>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
/* ══════════════════════════════════════════
   ZAMAN MEDICAL — SOFT LUXURY LIGHT THEME (exact same as dashboard)
══════════════════════════════════════════ */
:root {
  --bg:         #F5F0F8;
  --bg-mesh:    radial-gradient(ellipse at 20% 10%, #FFE4E4 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, #FFD6E0 0%, transparent 45%),
                radial-gradient(ellipse at 60% 20%, #FFECD2 0%, transparent 40%),
                #F5F0F8;
  --surface:    rgba(255,255,255,0.75);
  --surface-s:  rgba(255,255,255,0.55);
  --surface-b:  rgba(255,255,255,0.92);
  --border:     rgba(180,100,120,0.10);
  --border-s:   rgba(180,100,120,0.18);

  --red:        #C62A2A;
  --red-l:      #E03E3E;
  --red-xl:     #F06060;
  --red-2xl:    #FFB3B3;

  --grad-1: linear-gradient(145deg, #FF6B6B 0%, #C62A2A 100%);
  --grad-2: linear-gradient(145deg, #FF8FA3 0%, #D63864 100%);
  --grad-3: linear-gradient(145deg, #FFB347 0%, #E07020 100%);
  --grad-4: linear-gradient(145deg, #7EC8E3 0%, #2B6CB0 100%);

  --text-1:  #1A0A14;
  --text-2:  #5A3550;
  --text-3:  #9B7B90;
  --text-w:  rgba(255,255,255,0.95);
  --text-wm: rgba(255,255,255,0.72);

  --sw: 240px;
  --radius: 22px;
  --radius-s: 14px;
  --radius-pill: 100px;

  --shadow-card:  0 8px 32px rgba(180,60,80,0.12), 0 2px 8px rgba(180,60,80,0.06);
  --shadow-float: 0 20px 60px rgba(180,60,80,0.18), 0 4px 16px rgba(180,60,80,0.10);
  --shadow-soft:  0 4px 20px rgba(0,0,0,0.06);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
  font-family: 'Plus Jakarta Sans', sans-serif;
  background: var(--bg-mesh);
  background-attachment: fixed;
  color: var(--text-1);
  min-height: 100vh;
  overflow-x: hidden;
}

::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--red-2xl); border-radius: 10px; }

/* ══════════ SIDEBAR ══════════ */
.sidebar {
  position: fixed;
  left: 0; top: 0;
  width: var(--sw);
  height: 100vh;
  background: var(--surface-b);
  backdrop-filter: blur(24px) saturate(180%);
  -webkit-backdrop-filter: blur(24px) saturate(180%);
  border-right: 1px solid var(--border-s);
  display: flex;
  flex-direction: column;
  z-index: 200;
  box-shadow: 4px 0 32px rgba(180,60,80,0.08);
}

.sb-logo {
  padding: 24px 20px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 12px;
}
.sb-logo-mark {
  width: 40px; height: 40px;
  border-radius: 12px;
  background: var(--grad-1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: #fff;
  box-shadow: 0 6px 20px rgba(198,42,42,0.35);
  flex-shrink: 0;
}
.sb-logo-name {
  font-family: 'Fraunces', serif;
  font-size: 17px;
  font-weight: 700;
  color: var(--text-1);
  line-height: 1.1;
}
.sb-logo-tag {
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--red-xl);
  display: block;
  margin-top: 2px;
}

.sb-profile {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.sb-profile img {
  width: 38px; height: 38px;
  border-radius: 12px;
  object-fit: cover;
  border: 2px solid var(--red-2xl);
  box-shadow: 0 4px 12px rgba(198,42,42,0.2);
}
.sb-profile-name {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-1);
  line-height: 1.2;
}
.sb-profile-role {
  font-size: 10px;
  color: var(--red-xl);
  font-weight: 600;
  letter-spacing: 0.08em;
}

.sb-search {
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
}
.sb-search-inner {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--surface-s);
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  padding: 8px 14px;
}
.sb-search-inner i { color: var(--text-3); font-size: 12px; }
.sb-search-inner input {
  border: none;
  background: none;
  outline: none;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 12.5px;
  color: var(--text-1);
  width: 100%;
}
.sb-search-inner input::placeholder { color: var(--text-3); }

.sb-nav { flex: 1; padding: 10px 12px; overflow-y: auto; }
.sb-section-label {
  padding: 12px 10px 5px;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.20em;
  text-transform: uppercase;
  color: var(--text-3);
}
.sb-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  color: var(--text-2);
  text-decoration: none;
  font-size: 13px;
  font-weight: 500;
  border-radius: var(--radius-s);
  transition: all 0.2s;
  margin-bottom: 2px;
}
.sb-icon {
  width: 32px; height: 32px;
  border-radius: 10px;
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: var(--text-3);
  flex-shrink: 0;
  transition: all 0.2s;
}
.sb-link:hover {
  background: rgba(198,42,42,0.07);
  color: var(--red);
}
.sb-link:hover .sb-icon {
  background: rgba(198,42,42,0.12);
  color: var(--red);
}
.sb-link.active {
  background: linear-gradient(135deg, rgba(198,42,42,0.12), rgba(224,62,62,0.06));
  color: var(--red);
  font-weight: 700;
  box-shadow: inset 0 0 0 1px rgba(198,42,42,0.15);
}
.sb-link.active .sb-icon {
  background: var(--grad-1);
  color: #fff;
  box-shadow: 0 4px 12px rgba(198,42,42,0.30);
}

.sb-quick {
  padding: 14px 20px;
  border-top: 1px solid var(--border);
}
.sb-quick-title {
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--text-3);
  margin-bottom: 10px;
}
.sb-quick-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.sb-quick-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 10px 4px;
  border-radius: var(--radius-s);
  background: var(--surface-s);
  border: 1px solid var(--border);
  text-decoration: none;
  transition: all 0.2s;
}
.sb-quick-btn i {
  font-size: 16px;
  color: var(--text-2);
}
.sb-quick-btn span {
  font-size: 9px;
  font-weight: 600;
  color: var(--text-3);
  text-align: center;
}
.sb-quick-btn:hover {
  background: #fff;
  box-shadow: var(--shadow-soft);
  transform: translateY(-2px);
}
.sb-quick-btn:hover i { color: var(--red); }

.sb-footer {
  padding: 14px 20px;
  border-top: 1px solid var(--border);
}
.sb-logout {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: var(--radius-s);
  background: rgba(198,42,42,0.07);
  border: 1px solid rgba(198,42,42,0.15);
  color: var(--red);
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s;
}
.sb-logout:hover {
  background: rgba(198,42,42,0.14);
  transform: translateX(4px);
}

/* ══════════ MAIN ══════════ */
.main {
  margin-left: var(--sw);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ══════════ TOPBAR ══════════ */
.topbar {
  position: sticky;
  top: 0;
  z-index: 100;
  background: rgba(245,240,248,0.80);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  border-bottom: 1px solid var(--border-s);
  padding: 0 32px;
  height: 68px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}
.topbar-nav {
  display: flex;
  align-items: center;
  gap: 4px;
  background: rgba(255,255,255,0.7);
  border: 1px solid var(--border-s);
  border-radius: var(--radius-pill);
  padding: 4px;
}
.topbar-nav a {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 16px;
  border-radius: var(--radius-pill);
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-2);
  text-decoration: none;
  transition: all 0.2s;
  white-space: nowrap;
}
.topbar-nav a:hover { color: var(--red); background: rgba(198,42,42,0.06); }
.topbar-nav a.active {
  background: var(--grad-1);
  color: #fff;
  box-shadow: 0 4px 14px rgba(198,42,42,0.30);
}
.topbar-right { display: flex; align-items: center; gap: 10px; }
.tb-greeting {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-2);
}
.tb-greeting strong { color: var(--text-1); font-weight: 700; }
.icon-btn {
  width: 38px; height: 38px;
  border-radius: var(--radius-s);
  background: rgba(255,255,255,0.8);
  border: 1px solid var(--border-s);
  display: flex; align-items: center; justify-content: center;
  color: var(--text-2);
  font-size: 14px;
  text-decoration: none;
  transition: all 0.2s;
  box-shadow: var(--shadow-soft);
}
.icon-btn:hover { background: #fff; color: var(--red); box-shadow: var(--shadow-card); }
.btn-action {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 18px;
  background: #fff;
  border: 1px solid var(--border-s);
  color: var(--text-1);
  border-radius: var(--radius-pill);
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s;
  box-shadow: var(--shadow-soft);
}
.btn-action i { color: var(--red-xl); }
.btn-action:hover { box-shadow: var(--shadow-card); transform: translateY(-1px); }

/* ══════════ CONTENT ══════════ */
.content {
  flex: 1;
  padding: 28px 32px 48px;
  display: flex;
  flex-direction: column;
  gap: 28px;
}
.page-title {
  font-family: 'Fraunces', serif;
  font-size: 32px;
  font-weight: 700;
  color: var(--text-1);
  letter-spacing: -0.01em;
}

/* ⟡ FLOATING CARDS (exact same as dashboard) */
.float-cards-row {
  display: flex;
  gap: 20px;
  align-items: flex-end;
  flex-wrap: wrap;
}
.float-card {
  border-radius: 26px;
  padding: 28px 24px;
  color: var(--text-w);
  position: relative;
  overflow: hidden;
  flex: 1;
  min-width: 180px;
  min-height: 180px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.3s;
  cursor: default;
}
.float-card:nth-child(1) { background: var(--grad-1); transform: translateY(0px) rotate(-1.5deg); z-index: 3; }
.float-card:nth-child(2) { background: var(--grad-2); transform: translateY(-12px) rotate(0.5deg); z-index: 2; }
.float-card:nth-child(3) { background: var(--grad-3); transform: translateY(0px) rotate(1.5deg); z-index: 1; }
.float-card:nth-child(4) { background: var(--grad-4); transform: translateY(-8px) rotate(-0.8deg); z-index: 0; }
.float-card:hover {
  transform: translateY(-16px) rotate(0deg) !important;
  box-shadow: 0 28px 64px rgba(0,0,0,0.20) !important;
  z-index: 10 !important;
}
.float-card::before {
  content: '';
  position: absolute;
  top: -50%; left: -50%;
  width: 200%; height: 200%;
  background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.25) 0%, transparent 60%);
  pointer-events: none;
}
.fc-icon-wrap {
  width: 46px; height: 46px;
  border-radius: 14px;
  background: rgba(255,255,255,0.25);
  backdrop-filter: blur(8px);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  color: #fff;
  margin-bottom: auto;
}
.fc-label { font-size: 10px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; color: var(--text-wm); margin-top: 16px; }
.fc-value { font-family: 'Fraunces', serif; font-size: 42px; font-weight: 700; color: #fff; line-height: 1; letter-spacing: -0.02em; }
.fc-sub { font-size: 11px; color: var(--text-wm); margin-top: 4px; }

/* Table Card */
.table-card {
  background: var(--surface);
  backdrop-filter: blur(20px) saturate(160%);
  border: 1px solid var(--border-s);
  border-radius: var(--radius);
  overflow: hidden;
  box-shadow: var(--shadow-card);
}
.table-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--border);
  background: rgba(198,42,42,0.04);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.table-header h3 {
  font-size: 15px;
  font-weight: 700;
  color: var(--text-1);
  display: flex;
  align-items: center;
  gap: 8px;
}
.record-count {
  font-size: 11px;
  font-weight: 600;
  background: rgba(255,255,255,0.7);
  padding: 4px 12px;
  border-radius: 100px;
  color: var(--text-2);
}
.prescriptions-table {
  width: 100%;
  border-collapse: collapse;
}
.prescriptions-table thead th {
  text-align: left;
  padding: 14px 20px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-3);
  background: rgba(245,240,248,0.5);
  border-bottom: 1px solid var(--border);
}
.prescriptions-table tbody td {
  padding: 14px 20px;
  font-size: 13px;
  color: var(--text-2);
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}
.prescriptions-table tbody tr:hover {
  background: rgba(198,42,42,0.04);
}
.prescription-details {
  max-width: 300px;
  white-space: normal;
  word-break: break-word;
  line-height: 1.4;
}
.status-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 100px;
  font-size: 10px;
  font-weight: 700;
}
.status-pending { background: rgba(245,158,11,0.12); color: #d97706; }
.status-verified { background: rgba(34,197,94,0.12); color: #16a34a; }
.status-dispensed { background: rgba(59,130,246,0.12); color: #3b82f6; }
.action-buttons {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.action-btn {
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.action-verify { background: rgba(34,197,94,0.12); color: #16a34a; }
.action-verify:hover { background: #16a34a; color: #fff; }
.action-dispense { background: rgba(59,130,246,0.12); color: #3b82f6; }
.action-dispense:hover { background: #3b82f6; color: #fff; }
.action-delete { background: rgba(220,53,69,0.12); color: #dc3545; }
.action-delete:hover { background: #dc3545; color: #fff; }
.empty-state {
  text-align: center;
  padding: 50px;
  color: var(--text-3);
}
.empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

@keyframes riseUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes floatIn {
  from { opacity: 0; transform: translateY(20px) scale(0.96); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.page-title { animation: riseUp 0.4s ease both; }
.float-cards-row { animation: floatIn 0.5s 0.05s ease both; }
.float-card:nth-child(1) { animation: floatIn 0.5s 0.05s ease both; }
.float-card:nth-child(2) { animation: floatIn 0.5s 0.12s ease both; }
.float-card:nth-child(3) { animation: floatIn 0.5s 0.19s ease both; }
.float-card:nth-child(4) { animation: floatIn 0.5s 0.26s ease both; }
.table-card { animation: riseUp 0.4s 0.1s ease both; }

@media (max-width: 1200px) {
  .float-cards-row { flex-wrap: wrap; }
}
@media (max-width: 900px) {
  :root { --sw: 200px; }
  .content { padding: 20px; }
  .topbar-nav { display: none; }
  .prescriptions-table thead th, .prescriptions-table tbody td { padding: 10px 12px; }
  .prescription-details { max-width: 200px; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
  <div class="sb-logo">
    <div class="sb-logo-mark"><i class="fa-solid fa-hospital"></i></div>
    <div>
      <div class="sb-logo-name">Zaman Medical</div>
      <span class="sb-logo-tag">Admin Portal</span>
    </div>
  </div>
  <div class="sb-profile">
    <img src="<?= htmlspecialchars($profile) ?>" onerror="this.src='default-avatar.png'" alt="Admin">
    <div>
      <div class="sb-profile-name"><?= htmlspecialchars($admin_name) ?></div>
      <div class="sb-profile-role">Super Admin</div>
    </div>
  </div>
  <div class="sb-search">
    <div class="sb-search-inner">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" placeholder="Search...">
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section-label">Navigation</div>
    <a href="admindashboard.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-table-columns"></i></span> Dashboard</a>
    <a href="adminreports.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-chart-line"></i></span> Reports</a>
   
    <div class="sb-section-label">Staff</div>
    <a href="adminstaffapproval.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-check"></i></span> Approval</a>
    <a href="admindoctors.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-doctor"></i></span> Doctors</a>
    <a href="adminnurses.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-nurse"></i></span> Nurses</a>
    <a href="adminpharmacists.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-pills"></i></span> Pharmacists</a>
    <a href="adminreceptionists.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-headset"></i></span> Receptionists</a>
    <div class="sb-section-label">Hospital</div>
    <a href="adminpatients.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-users"></i></span> Patients</a>
    <a href="adminappointments.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-calendar-check"></i></span> Appointments</a>
    <a href="adminprescriptions.php" class="sb-link active"><span class="sb-icon"><i class="fa-solid fa-file-medical"></i></span> Prescriptions</a>
    <a href="adminbeds.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-bed"></i></span> Beds</a>
    <div class="sb-section-label">Account</div>
    <a href="adminprofile.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-gear"></i></span> Profile</a>
    <a href="adminsettings.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-gears"></i></span> Settings</a>
  </nav>
  <div class="sb-quick">
    <div class="sb-quick-title">Quick Menu</div>
    <div class="sb-quick-grid">
      <a href="adminpatients.php" class="sb-quick-btn"><i class="fa-solid fa-users" style="color:#C62A2A;"></i><span>Patients</span></a>
      <a href="adminappointments.php" class="sb-quick-btn"><i class="fa-solid fa-calendar-check" style="color:#D63864;"></i><span>Appts</span></a>
      <a href="adminbeds.php" class="sb-quick-btn"><i class="fa-solid fa-bed" style="color:#E07020;"></i><span>Beds</span></a>
      <a href="admindoctors.php" class="sb-quick-btn"><i class="fa-solid fa-user-doctor" style="color:#2B6CB0;"></i><span>Doctors</span></a>
      <a href="adminprescriptions.php" class="sb-quick-btn"><i class="fa-solid fa-file-medical" style="color:#7C3AED;"></i><span>Rx</span></a>
      <a href="adminreports.php" class="sb-quick-btn"><i class="fa-solid fa-chart-line" style="color:#059669;"></i><span>Reports</span></a>
    </div>
  </div>
  <div class="sb-footer">
    <a href="logout.php" class="sb-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</aside>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <nav class="topbar-nav">
      <a href="admindashboard.php"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
      <a href="adminappointments.php"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
      <a href="adminpatients.php"><i class="fa-solid fa-users"></i> Patients</a>
      <a href="adminreports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
    </nav>
    <div class="topbar-right">
      <div class="tb-greeting"><?= $greeting ?>, <strong><?= htmlspecialchars(explode(' ', $admin_name)[0]) ?></strong> 👋</div>
      <a href="adminactivity.php" class="icon-btn"><i class="fa-solid fa-bell"></i></a>
      <a href="adminprofile.php" class="icon-btn"><i class="fa-solid fa-user-gear"></i></a>
    </div>
  </header>

  <!-- CONTENT -->
  <main class="content">

    <div style="display:flex;align-items:baseline;justify-content:space-between;">
      <div>
        <div class="page-title">Prescriptions</div>
        <div style="font-size:13px;color:var(--text-3);margin-top:4px;"><?= date('l, d F Y') ?> &nbsp;·&nbsp; Pharmacy management</div>
      </div>
      <div style="font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--text-3);" id="live-clock"><?= date('h:i A') ?></div>
    </div>

    <!-- FLOATING STAT CARDS -->
    <div class="float-cards-row">
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-file-prescription"></i></div>
        <div>
          <div class="fc-label">Total Prescriptions</div>
          <div class="fc-value"><?= number_format($total) ?></div>
          <div class="fc-sub">All time</div>
        </div>
      </div>
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-hourglass-half"></i></div>
        <div>
          <div class="fc-label">Pending</div>
          <div class="fc-value"><?= number_format($pending) ?></div>
          <div class="fc-sub">Awaiting verification</div>
        </div>
      </div>
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-check-circle"></i></div>
        <div>
          <div class="fc-label">Verified</div>
          <div class="fc-value"><?= number_format($verified) ?></div>
          <div class="fc-sub">Ready for dispensing</div>
        </div>
      </div>
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-truck-fast"></i></div>
        <div>
          <div class="fc-label">Dispensed</div>
          <div class="fc-value"><?= number_format($dispensed) ?></div>
          <div class="fc-sub">Completed</div>
        </div>
      </div>
    </div>

        <!-- PRESCRIPTIONS TABLE -->
    <div class="table-card">
      <div class="table-header">
        <h3><i class="fa-solid fa-list"></i> All Prescriptions</h3>
        <div class="record-count"><?= $total ?> records</div>
      </div>

      <?php if ($prescriptions && $prescriptions->num_rows > 0): ?>

        <table class="prescriptions-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Patient</th>
              <th>Appointment</th>
              <th>Details</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php while ($row = $prescriptions->fetch_assoc()): ?>
              <?php
                $id = (int)$row['prescription_id'];
                $status = $row['status'] ?? 'pending';
              ?>

              <tr>
                <td>#<?= $id ?></td>

                <td>
                  <?= htmlspecialchars($row['patient_name'] ?? 'Unknown') ?>
                </td>

                <td>
                  <?= htmlspecialchars($row['appointment_id'] ?? 'N/A') ?>
                </td>

                <td class="prescription-details">
                  <?= htmlspecialchars($row['prescription_details'] ?? 'No details') ?>
                </td>

                <td>
                  <span class="status-badge status-<?= htmlspecialchars($status) ?>">
                    <?= ucfirst(htmlspecialchars($status)) ?>
                  </span>
                </td>

                <td>
                  <?= htmlspecialchars($row['created_at'] ?? '') ?>
                </td>

                <td>
                  <div class="action-buttons">

                    <?php if ($status === 'pending'): ?>
                      <a class="action-btn action-verify"
                         href="?status=verified&id=<?= $id ?>">
                        <i class="fa-solid fa-check"></i> Verify
                      </a>
                    <?php endif; ?>

                    <?php if ($status === 'verified'): ?>
                      <a class="action-btn action-dispense"
                         href="?status=dispensed&id=<?= $id ?>">
                        <i class="fa-solid fa-truck-fast"></i> Dispense
                      </a>
                    <?php endif; ?>

                    <a class="action-btn action-delete"
                       href="?delete=<?= $id ?>"
                       onclick="return confirm('Are you sure you want to delete this prescription?');">
                      <i class="fa-solid fa-trash"></i> Delete
                    </a>

                  </div>
                </td>
              </tr>

            <?php endwhile; ?>
          </tbody>

        </table>

      <?php else: ?>

        <div class="empty-state">
          <i class="fa-solid fa-file-medical"></i>
          <p>No prescriptions found</p>
        </div>

      <?php endif; ?>

    </div>

  </main>
</div>

<!-- LIVE CLOCK SCRIPT -->
<script>
setInterval(() => {
  const now = new Date();
  let hours = now.getHours();
  let minutes = now.getMinutes().toString().padStart(2, '0');
  let ampm = hours >= 12 ? 'PM' : 'AM';

  hours = hours % 12;
  hours = hours ? hours : 12;

  document.getElementById('live-clock').innerText =
    `${hours}:${minutes} ${ampm}`;
}, 1000);
</script>

</body>
</html> 