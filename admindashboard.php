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
if ($conn->connect_error) die("Database Connection Failed: " . $conn->connect_error);

$user_id = (int)$_SESSION['user_id'];

/* ---------------- ADMIN INFO ---------------- */
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$admin_name = $admin['full_name'] ?? 'Administrator';
$profile    = $admin['profile_image'] ?? 'default-avatar.png';

/* ---------------- GREETING ---------------- */
date_default_timezone_set("Asia/Karachi");
$hour = (int)date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");

/* ---------------- COUNTS ---------------- */
$patients_count     = (int)$conn->query("SELECT COUNT(*) FROM patients")->fetch_row()[0];
$appointments_count = (int)$conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0];
$beds_count         = (int)$conn->query("SELECT COUNT(*) FROM beds")->fetch_row()[0];
$sql = "
SELECT
    SUM(role='doctor' AND status='active') AS doctors,
    SUM(role='nurse' AND status='active') AS nurses,
    SUM(role='pharmacist' AND status='active') AS pharmacists,
    SUM(role='receptionist' AND status='active') AS receptionists,
    SUM(status='inactive' AND role!='patient') AS pending
FROM users";

$result = $conn->query($sql);
$row = $result->fetch_assoc();

$doctor_count       = (int)$row['doctors'];
$nurse_count        = (int)$row['nurses'];
$pharmacist_count   = (int)$row['pharmacists'];
$receptionist_count = (int)$row['receptionists'];
$pending_staff      = (int)$row['pending'];



/* ---------------- MONTHLY APPOINTMENTS ---------------- */
$monthly_labels = [];
$monthly_counts = [];
$res = $conn->query("
    SELECT DATE_FORMAT(appointment_date,'%b') AS month_name,
           YEAR(appointment_date) AS y, MONTH(appointment_date) AS m, COUNT(*) AS total
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY y,m ORDER BY y,m
");
while ($row = $res->fetch_assoc()) {
    $monthly_labels[] = $row['month_name'];
    $monthly_counts[] = (int)$row['total'];
}

if (empty($monthly_labels)) { $monthly_labels = ['No Data']; $monthly_counts = [0]; }

/* ---------------- STAFF CHART ---------------- */
$staff_labels = ['Doctors','Nurses','Pharmacists','Receptionists'];
$staff_counts = [$doctor_count, $nurse_count, $pharmacist_count, $receptionist_count];
$monthlyLabelsJSON = json_encode($monthly_labels);
$monthlyCountsJSON = json_encode($monthly_counts);

$staffLabelsJSON = json_encode($staff_labels);
$staffCountsJSON = json_encode($staff_counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin Dashboard — Zaman Medical</title>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ══════════════════════════════════════════
   ZAMAN MEDICAL — SOFT LUXURY LIGHT THEME
   Inspired by: Taxr UI — pastel gradients,
   floating cards, pill nav, airy whites
══════════════════════════════════════════ */
:root {
  /* Background system */
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

  /* Crimson palette kept */
  --red:        #C62A2A;
  --red-l:      #E03E3E;
  --red-xl:     #F06060;
  --red-2xl:    #FFB3B3;

  /* Card gradients */
  --grad-1: linear-gradient(145deg, #FF6B6B 0%, #C62A2A 100%);
  --grad-2: linear-gradient(145deg, #FF8FA3 0%, #D63864 100%);
  --grad-3: linear-gradient(145deg, #FFB347 0%, #E07020 100%);
  --grad-4: linear-gradient(145deg, #7EC8E3 0%, #2B6CB0 100%);

  /* Text */
  --text-1:  #1A0A14;
  --text-2:  #5A3550;
  --text-3:  #9B7B90;
  --text-w:  rgba(255,255,255,0.95);
  --text-wm: rgba(255,255,255,0.72);

  /* Sidebar */
  --sw: 240px;
  --radius: 22px;
  --radius-s: 14px;
  --radius-pill: 100px;

  /* Shadows */
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

/* ── SCROLLBAR ── */
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

/* Logo */
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

/* Profile mini */
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

/* Search */
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

/* Nav */
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

/* Quick menu grid */
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
  transition: color 0.2s;
}
.sb-quick-btn span {
  font-size: 9px;
  font-weight: 600;
  color: var(--text-3);
  text-align: center;
  line-height: 1.2;
}
.sb-quick-btn:hover {
  background: #fff;
  box-shadow: var(--shadow-soft);
  transform: translateY(-2px);
}
.sb-quick-btn:hover i { color: var(--red); }

/* Footer */
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

/* Pill nav */
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

/* Icon button */
.icon-btn {
  width: 38px; height: 38px;
  border-radius: var(--radius-s);
  background: rgba(255,255,255,0.8);
  border: 1px solid var(--border-s);
  display: flex; align-items: center; justify-content: center;
  color: var(--text-2);
  font-size: 14px;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  box-shadow: var(--shadow-soft);
}
.icon-btn:hover { background: #fff; color: var(--red); box-shadow: var(--shadow-card); }

.notif-dot {
  position: absolute;
  top: 7px; right: 7px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--red);
  border: 1.5px solid #F5F0F8;
  box-shadow: 0 0 6px rgba(198,42,42,0.5);
}

/* Add invoice style button */
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
  font-family: 'Plus Jakarta Sans', sans-serif;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: var(--shadow-soft);
}
.btn-action i { color: var(--red-xl); }
.btn-action:hover {
  box-shadow: var(--shadow-card);
  transform: translateY(-1px);
  color: var(--text-1);
}

/* ══════════ CONTENT ══════════ */
.content {
  flex: 1;
  padding: 28px 32px 48px;
  display: flex;
  flex-direction: column;
  gap: 28px;
}

/* Page title */
.page-title {
  font-family: 'Fraunces', serif;
  font-size: 32px;
  font-weight: 700;
  color: var(--text-1);
  letter-spacing: -0.01em;
}

/* ══════════ FLOATING STAT CARDS (Taxr style) ══════════ */
.float-cards-row {
  display: flex;
  gap: 20px;
  align-items: flex-end;
}

.float-card {
  border-radius: 26px;
  padding: 28px 24px;
  color: var(--text-w);
  position: relative;
  overflow: hidden;
  flex: 1;
  min-height: 180px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.3s;
  cursor: default;
}

.float-card:nth-child(1) {
  background: var(--grad-1);
  box-shadow: 0 16px 48px rgba(198,42,42,0.35);
  transform: translateY(0px) rotate(-1.5deg);
  z-index: 3;
}
.float-card:nth-child(2) {
  background: var(--grad-2);
  box-shadow: 0 16px 48px rgba(214,56,100,0.35);
  transform: translateY(-12px) rotate(0.5deg);
  z-index: 2;
}
.float-card:nth-child(3) {
  background: var(--grad-3);
  box-shadow: 0 16px 48px rgba(224,112,32,0.30);
  transform: translateY(0px) rotate(1.5deg);
  z-index: 1;
}
.float-card:nth-child(4) {
  background: var(--grad-4);
  box-shadow: 0 16px 48px rgba(43,108,176,0.30);
  transform: translateY(-8px) rotate(-0.8deg);
  z-index: 0;
}

.float-card:hover {
  transform: translateY(-16px) rotate(0deg) !important;
  box-shadow: 0 28px 64px rgba(0,0,0,0.20) !important;
  z-index: 10 !important;
}

/* Card shine overlay */
.float-card::before {
  content: '';
  position: absolute;
  top: -50%; left: -50%;
  width: 200%; height: 200%;
  background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.25) 0%, transparent 60%);
  pointer-events: none;
}

.float-card::after {
  content: '';
  position: absolute;
  bottom: -30px; right: -30px;
  width: 120px; height: 120px;
  border-radius: 50%;
  background: rgba(255,255,255,0.10);
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
  position: relative; z-index: 1;
  margin-bottom: auto;
}

.fc-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--text-wm);
  position: relative; z-index: 1;
  margin-top: 16px;
}

.fc-value {
  font-family: 'Fraunces', serif;
  font-size: 42px;
  font-weight: 700;
  color: #fff;
  line-height: 1;
  position: relative; z-index: 1;
  letter-spacing: -0.02em;
}
.fc-value sup {
  font-size: 18px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-weight: 600;
  vertical-align: super;
}

.fc-sub {
  font-size: 11px;
  color: var(--text-wm);
  position: relative; z-index: 1;
  margin-top: 4px;
}

/* ══════════ LOWER GRID ══════════ */
.lower-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

/* Panel card */
.panel {
  background: var(--surface);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  border: 1px solid var(--border-s);
  border-radius: var(--radius);
  padding: 26px;
  box-shadow: var(--shadow-card);
  position: relative;
  overflow: hidden;
}

.panel::before {
  content: '';
  position: absolute;
  top: 0; left: 20%; right: 20%;
  height: 1.5px;
  background: linear-gradient(90deg, transparent, rgba(198,42,42,0.35), transparent);
  border-radius: 100px;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 22px;
}
.panel-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--text-1);
  display: flex;
  align-items: center;
  gap: 8px;
}
.panel-title i { color: var(--red-xl); font-size: 14px; }

.panel-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  background: rgba(198,42,42,0.08);
  border: 1px solid rgba(198,42,42,0.15);
  font-size: 10px;
  font-weight: 700;
  color: var(--red);
  letter-spacing: 0.05em;
}

/* Staff distribution */
.staff-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.staff-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 14px;
  border-radius: var(--radius-s);
  background: rgba(255,255,255,0.6);
  border: 1px solid var(--border);
  transition: all 0.22s;
}
.staff-row:hover {
  background: #fff;
  box-shadow: var(--shadow-soft);
  transform: translateX(4px);
}

.staff-avatar {
  width: 36px; height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: #fff;
  flex-shrink: 0;
}

.staff-info { flex: 1; }
.staff-role-name {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-1);
}
.staff-role-sub {
  font-size: 10.5px;
  color: var(--text-3);
  margin-top: 1px;
}

.staff-count-badge {
  font-family: 'Fraunces', serif;
  font-size: 22px;
  font-weight: 700;
  color: var(--text-1);
}

.staff-bar-col { width: 80px; }
.staff-bar-track {
  height: 5px;
  border-radius: 100px;
  background: rgba(180,100,120,0.12);
  overflow: hidden;
}
.staff-bar-fill {
  height: 100%;
  border-radius: 100px;
  transition: width 1s cubic-bezier(0.34,1.56,0.64,1);
}

/* Chart panel */
.chart-wrap canvas { width: 100% !important; }

/* Doughnut panel */
.doughnut-center-wrap {
  display: flex;
  align-items: center;
  gap: 28px;
}
.doughnut-relative {
  position: relative;
  flex-shrink: 0;
}
.doughnut-label {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.doughnut-label-num {
  font-family: 'Fraunces', serif;
  font-size: 30px;
  font-weight: 700;
  color: var(--text-1);
}
.doughnut-label-sub {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--text-3);
}

.donut-legend {
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
}
.donut-leg-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.donut-leg-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.donut-leg-name {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-2);
  flex: 1;
}
.donut-leg-val {
  font-family: 'Fraunces', serif;
  font-size: 16px;
  font-weight: 700;
  color: var(--text-1);
}

/* Quick actions panel */
.qa-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.qa-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px 8px;
  border-radius: var(--radius-s);
  background: rgba(255,255,255,0.6);
  border: 1px solid var(--border);
  text-decoration: none;
  transition: all 0.22s;
}
.qa-icon {
  width: 40px; height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: #fff;
}
.qa-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-2);
  text-align: center;
  line-height: 1.3;
}
.qa-btn:hover {
  background: #fff;
  box-shadow: var(--shadow-card);
  transform: translateY(-4px);
}

/* ══════════ ANIMATIONS ══════════ */
@keyframes riseUp {
  from { opacity: 0; transform: translateY(30px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes floatIn {
  from { opacity: 0; transform: translateY(20px) scale(0.96); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.page-title   { animation: riseUp 0.4s ease both; }
.float-cards-row { animation: floatIn 0.5s 0.05s ease both; }
.lower-grid   { animation: riseUp 0.5s 0.15s ease both; }

/* Float card staggered animations */
.float-card:nth-child(1) { animation: floatIn 0.5s 0.05s ease both; }
.float-card:nth-child(2) { animation: floatIn 0.5s 0.12s ease both; }
.float-card:nth-child(3) { animation: floatIn 0.5s 0.19s ease both; }
.float-card:nth-child(4) { animation: floatIn 0.5s 0.26s ease both; }

/* ══════════ RESPONSIVE ══════════ */
@media (max-width: 1200px) {
  .lower-grid { grid-template-columns: 1fr; }
  .float-cards-row { flex-wrap: wrap; }
  .float-card { min-width: calc(50% - 10px); flex: none; }
}
@media (max-width: 900px) {
  :root { --sw: 200px; }
  .content { padding: 20px; }
  .topbar-nav { display: none; }
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
    <a href="admindashboard.php" class="sb-link active">
      <span class="sb-icon"><i class="fa-solid fa-table-columns"></i></span>
      Dashboard
    </a>
    <a href="adminreports.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-chart-line"></i></span>
      Reports
    </a>
   

    <div class="sb-section-label">Staff</div>
    <a href="adminstaffapproval.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-user-check"></i></span>
      Approval
      <?php if ($pending_staff > 0): ?>
        <span style="margin-left:auto;background:var(--red);color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;line-height:1.5;"><?= $pending_staff ?></span>
      <?php endif; ?>
    </a>
    <a href="admindoctors.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-user-doctor"></i></span>
      Doctors
    </a>
    <a href="adminnurses.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-user-nurse"></i></span>
      Nurses
    </a>
    <a href="adminpharmacists.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-pills"></i></span>
      Pharmacists
    </a>
    <a href="adminreceptionists.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-headset"></i></span>
      Receptionists
    </a>

    <div class="sb-section-label">Hospital</div>
    <a href="adminpatients.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-users"></i></span>
      Patients
    </a>
    <a href="adminappointments.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-calendar-check"></i></span>
      Appointments
    </a>
    <a href="adminprescriptions.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-file-medical"></i></span>
      Prescriptions
    </a>
    <a href="adminbeds.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-bed"></i></span>
      Beds
    </a>

    <div class="sb-section-label">Account</div>
    <a href="adminprofile.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-user-gear"></i></span>
      Profile
    </a>
    <a href="adminsettings.php" class="sb-link">
      <span class="sb-icon"><i class="fa-solid fa-gears"></i></span>
      Settings
    </a>
  </nav>

  <!-- Quick Menu Grid (Taxr-style) -->
  <div class="sb-quick">
    <div class="sb-quick-title">Quick Menu</div>
    <div class="sb-quick-grid">
      <a href="adminpatients.php" class="sb-quick-btn">
        <i class="fa-solid fa-users" style="color:#C62A2A;"></i>
        <span>Patients</span>
      </a>
      <a href="adminappointments.php" class="sb-quick-btn">
        <i class="fa-solid fa-calendar-check" style="color:#D63864;"></i>
        <span>Appts</span>
      </a>
      <a href="adminbeds.php" class="sb-quick-btn">
        <i class="fa-solid fa-bed" style="color:#E07020;"></i>
        <span>Beds</span>
      </a>
      <a href="admindoctors.php" class="sb-quick-btn">
        <i class="fa-solid fa-user-doctor" style="color:#2B6CB0;"></i>
        <span>Doctors</span>
      </a>
      <a href="adminprescriptions.php" class="sb-quick-btn">
        <i class="fa-solid fa-file-medical" style="color:#7C3AED;"></i>
        <span>Rx</span>
      </a>
      <a href="adminreports.php" class="sb-quick-btn">
        <i class="fa-solid fa-chart-line" style="color:#059669;"></i>
        <span>Reports</span>
      </a>
    </div>
  </div>

  <div class="sb-footer">
    <a href="logout.php" class="sb-logout">
      <i class="fa-solid fa-right-from-bracket"></i>
      Logout
    </a>
  </div>
</aside>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <!-- Pill Navigation -->
    <nav class="topbar-nav">
      <a href="admindashboard.php" class="active"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
      <a href="adminappointments.php"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
      <a href="adminpatients.php"><i class="fa-solid fa-users"></i> Patients</a>
      <a href="adminreports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
    </nav>

    <div class="topbar-right">
      <div class="tb-greeting">
        <?= $greeting ?>, <strong><?= htmlspecialchars(explode(' ', $admin_name)[0]) ?></strong> 👋
      </div>
      <a href="adminactivity.php" class="icon-btn">
        <i class="fa-solid fa-bell"></i>
        <?php if ($pending_staff > 0): ?><span class="notif-dot"></span><?php endif; ?>
      </a>
      <a href="adminprofile.php" class="icon-btn">
        <i class="fa-solid fa-user-gear"></i>
      </a>
      <a href="adminstaffapproval.php" class="btn-action">
        <i class="fa-solid fa-user-check"></i>
        Approve Staff
        <?php if ($pending_staff > 0): ?>
          <span style="background:var(--red);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:100px;"><?= $pending_staff ?></span>
        <?php endif; ?>
      </a>
    </div>
  </header>

  <!-- CONTENT -->
  <main class="content">

    <!-- Page header -->
    <div style="display:flex;align-items:baseline;justify-content:space-between;">
      <div>
        <div class="page-title">Dashboard</div>
        <div style="font-size:13px;color:var(--text-3);margin-top:4px;"><?= date('l, d F Y') ?> &nbsp;·&nbsp; Hospital Command Center</div>
      </div>
      <div style="font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--text-3);letter-spacing:-0.02em;" id="live-clock"><?= date('h:i A') ?></div>
    </div>

    <!-- FLOATING STAT CARDS -->
    <div class="float-cards-row">

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-users"></i></div>
        <div>
          <div class="fc-label">Total Patients</div>
          <div class="fc-value"><?= number_format($patients_count) ?></div>
          <div class="fc-sub">Registered in system</div>
        </div>
      </div>

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-calendar-check"></i></div>
        <div>
          <div class="fc-label">Appointments</div>
          <div class="fc-value"><?= number_format($appointments_count) ?></div>
          <div class="fc-sub">Total scheduled</div>
        </div>
      </div>

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-bed"></i></div>
        <div>
          <div class="fc-label">Hospital Beds</div>
          <div class="fc-value"><?= number_format($beds_count) ?></div>
          <div class="fc-sub">Total capacity</div>
        </div>
      </div>

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-user-clock"></i></div>
        <div>
          <div class="fc-label">Pending Approval</div>
          <div class="fc-value"><?= number_format($pending_staff) ?></div>
          <div class="fc-sub">Awaiting review</div>
        </div>
      </div>

    </div>

    <!-- LOWER GRID -->
    <div class="lower-grid">

      <!-- Monthly Appointments Chart -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-calendar-days"></i> Monthly Appointments</div>
          <span class="panel-badge"><i class="fa-solid fa-circle" style="font-size:6px;color:var(--red);"></i> Last 6 months</span>
        </div>
        <div class="chart-wrap">
          <canvas id="appointmentsChart" height="120"></canvas>
        </div>
      </div>

      <!-- Staff Distribution -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-users-between-lines"></i> Staff Distribution</div>
          <span class="panel-badge">Active</span>
        </div>

        <div class="doughnut-center-wrap">
          <div class="doughnut-relative">
            <canvas id="staffChart" width="160" height="160"></canvas>
            <div class="doughnut-label">
              <div class="doughnut-label-num"><?= $doctor_count + $nurse_count + $pharmacist_count + $receptionist_count ?></div>
              <div class="doughnut-label-sub">Staff</div>
            </div>
          </div>
          <div class="donut-legend">
            <?php
              $donut_data = [
                ['Doctors',       $doctor_count,       '#C62A2A'],
                ['Nurses',        $nurse_count,        '#D63864'],
                ['Pharmacists',   $pharmacist_count,   '#E07020'],
                ['Receptionists', $receptionist_count, '#2B6CB0'],
              ];
              foreach ($donut_data as [$r, $c, $col]):
            ?>
            <div class="donut-leg-row">
              <span class="donut-leg-dot" style="background:<?= $col ?>;box-shadow:0 0 6px <?= $col ?>55;"></span>
              <span class="donut-leg-name"><?= $r ?></span>
              <span class="donut-leg-val"><?= $c ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Staff Breakdown -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-id-card"></i> Staff Breakdown</div>
        </div>
        <div class="staff-list">
          <?php
            $sb_data = [
              ['Doctors',       $doctor_count,       '#C62A2A', 'fa-user-doctor',   'var(--grad-1)'],
              ['Nurses',        $nurse_count,        '#D63864', 'fa-user-nurse',     'var(--grad-2)'],
              ['Pharmacists',   $pharmacist_count,   '#E07020', 'fa-pills',          'var(--grad-3)'],
              ['Receptionists', $receptionist_count, '#2B6CB0', 'fa-headset',        'var(--grad-4)'],
            ];
            $ts = max(1, $doctor_count + $nurse_count + $pharmacist_count + $receptionist_count);
            foreach ($sb_data as [$r, $c, $col, $ic, $grad]):
              $pct = round(($c / $ts) * 100);
          ?>
          <div class="staff-row">
            <div class="staff-avatar" style="background:<?= $grad ?>;">
              <i class="fa-solid <?= $ic ?>"></i>
            </div>
            <div class="staff-info">
              <div class="staff-role-name"><?= $r ?></div>
              <div class="staff-role-sub"><?= $pct ?>% of total staff</div>
            </div>
            <div class="staff-bar-col">
              <div class="staff-bar-track">
                <div class="staff-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>;"></div>
              </div>
            </div>
            <div class="staff-count-badge"><?= $c ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-bolt"></i> Quick Actions</div>
        </div>
        <div class="qa-grid">
          <a href="adminappointments.php" class="qa-btn">
            <div class="qa-icon" style="background:var(--grad-1);">
              <i class="fa-solid fa-calendar-plus"></i>
            </div>
            <span class="qa-label">New Appointment</span>
          </a>
          <a href="adminstaffapproval.php" class="qa-btn">
            <div class="qa-icon" style="background:var(--grad-2);">
              <i class="fa-solid fa-user-check"></i>
            </div>
            <span class="qa-label">Approve Staff</span>
          </a>
          <a href="adminreports.php" class="qa-btn">
            <div class="qa-icon" style="background:var(--grad-3);">
              <i class="fa-solid fa-chart-line"></i>
            </div>
            <span class="qa-label">View Reports</span>
          </a>
          <a href="adminpatients.php" class="qa-btn">
            <div class="qa-icon" style="background:var(--grad-4);">
              <i class="fa-solid fa-users"></i>
            </div>
            <span class="qa-label">Patients</span>
          </a>
          <a href="adminprescriptions.php" class="qa-btn">
            <div class="qa-icon" style="background:linear-gradient(145deg,#A78BFA,#7C3AED);">
              <i class="fa-solid fa-file-medical"></i>
            </div>
            <span class="qa-label">Prescriptions</span>
          </a>
          <a href="adminbeds.php" class="qa-btn">
            <div class="qa-icon" style="background:linear-gradient(145deg,#6EE7B7,#059669);">
              <i class="fa-solid fa-bed"></i>
            </div>
            <span class="qa-label">Beds</span>
          </a>
        </div>
      </div>

    </div>

  </main>
</div>

<!-- SCRIPTS -->
<script>
/* Live Clock */
function updateClock() {
  const now = new Date();
  let h = now.getHours(), m = now.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  const el = document.getElementById('live-clock');
  if (el) el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ' ' + ampm;
}
updateClock();
setInterval(updateClock, 30000);

/* Charts */
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";

/* Line Chart */
new Chart(document.getElementById('appointmentsChart'), {
  type: 'bar',
  data: {
    labels: <?= $monthlyLabelsJSON ?>,
    datasets: [{
      data: <?= $monthlyCountsJSON ?>,
      backgroundColor: (ctx) => {
        const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
        g.addColorStop(0, 'rgba(198,42,42,0.9)');
        g.addColorStop(1, 'rgba(214,56,100,0.2)');
        return g;
      },
      borderRadius: 10,
      borderSkipped: false,
      borderColor: 'transparent',
      hoverBackgroundColor: (ctx) => {
        const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
        g.addColorStop(0, '#C62A2A');
        g.addColorStop(1, 'rgba(214,56,100,0.5)');
        return g;
      },
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(255,255,255,0.95)',
        borderColor: 'rgba(198,42,42,0.20)',
        borderWidth: 1,
        titleColor: '#1A0A14',
        bodyColor: '#5A3550',
        padding: 12,
        cornerRadius: 12,
        boxShadow: '0 8px 24px rgba(0,0,0,0.1)',
      }
    },
    scales: {
      x: {
        ticks: { color: '#9B7B90', font: { size: 12, weight: '600' } },
        grid: { display: false },
        border: { display: false }
      },
      y: {
        ticks: { color: '#9B7B90', font: { size: 11 } },
        grid: { color: 'rgba(180,100,120,0.07)', drawBorder: false },
        border: { display: false },
        beginAtZero: true
      }
    }
  }
});

/* Doughnut Chart */
new Chart(document.getElementById('staffChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($staff_labels) ?>,
    datasets: [{
      data: <?= json_encode($staff_counts) ?>,
      backgroundColor: ['#C62A2A','#D63864','#E07020','#2B6CB0'],
      borderColor: 'rgba(255,255,255,0.9)',
      borderWidth: 4,
      hoverOffset: 10,
    }]
  },
  options: {
    cutout: '65%',
    responsive: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(255,255,255,0.95)',
        borderColor: 'rgba(198,42,42,0.20)',
        borderWidth: 1,
        titleColor: '#1A0A14',
        bodyColor: '#5A3550',
        padding: 10,
        cornerRadius: 10,
      }
    }
  }
});
</script>
<?php
$conn->close();
?>
</body>
</html>