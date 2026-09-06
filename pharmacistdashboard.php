<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'pharmacist') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli('localhost', 'root', '', 'SHAPMS');
if ($conn->connect_error) die("DB Error");

/* ---------- USER ---------- */
$pharmacist_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT u.full_name, u.profile_image
    FROM users u
    WHERE u.user_id=? AND u.role='pharmacist'
");
$stmt->bind_param("i", $pharmacist_id);
$stmt->execute();
$stmt->bind_result($full_name, $profile_image);
$stmt->fetch();
$stmt->close();

$profilePic = $profile_image ? $profile_image : "uploads/default.png";

/* ---------- STATS ---------- */
$total_medicines = $conn->query("SELECT COUNT(*) FROM medicines")->fetch_row()[0];
$low_stock       = $conn->query("SELECT COUNT(*) FROM medicines WHERE quantity <= 10")->fetch_row()[0];
$expired         = $conn->query("SELECT COUNT(*) FROM medicines WHERE expiry_date < CURDATE()")->fetch_row()[0];
$pending         = $conn->query("SELECT COUNT(*) FROM prescriptions WHERE status='pending'")->fetch_row()[0];
$completed       = $conn->query("SELECT COUNT(*) FROM prescriptions WHERE status='completed'")->fetch_row()[0];

// Fetch doctors for chat
$doctors = $conn->query("SELECT user_id, full_name FROM users WHERE role='doctor'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pharmacist Dashboard | Earthen Luxe Pharmacy</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ────────── PALETTE: Deep Purple + Rose ────────── */
:root {
  --primary:        #3B82F6;
  --primary-light:  #60A5FA;
  --primary-soft:   #16213E;
  --rose:           #38BDF8;
  --rose-light:     #7DD3FC;
  --rose-soft:      #16213E;
  --mint:           #34D399;
  --mint-deep:      #10B981;
  --mint-dark:      #34D399;
  --amber:          #FBBF24;
  --amber-light:    #FCD34D;
  --amber-soft:     #1E2440;
  --danger:         #F87171;
  --danger-light:   #FCA5A5;
  --danger-soft:    #1E2440;
  --white:          #FFFFFF;
  --off-white:      #131A30;
  --text-dark:      #F1F5F9;
  --text-mid:       #94A3C2;
  --text-light:     #5C6A91;
  --sidebar-bg:     #0E1530;
  --sidebar-border: #1F2A4A;
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

body {
  font-family: 'Nunito', sans-serif;
  background: radial-gradient(circle at 20% 0%, #142049 0%, #0A0F22 45%, #070A16 100%);
  color: var(--text-dark);
  min-height: 100vh;
}

/* ═══ SIDEBAR ═══ */
.sidebar {
  width: 270px;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  background: var(--sidebar-bg);
  border-right: 2px solid var(--sidebar-border);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  z-index: 1000;
  box-shadow: 6px 0 30px rgba(0,0,0,0.45);
}
.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-thumb { background: var(--primary-soft); border-radius: 10px; }

/* Brand */
.sidebar-brand {
  padding: 28px 22px 22px;
  background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
  position: relative;
  overflow: hidden;
}
.sidebar-brand::after {
  content: '💊';
  position: absolute;
  right: -8px; top: -8px;
  font-size: 80px;
  opacity: 0.13;
  transform: rotate(15deg);
}
.brand-bubble {
  width: 52px; height: 52px;
  background: #0E1530;
  border-radius: 18px;
  display: flex; align-items: center; justify-content: center;
  font-size: 26px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.35);
  margin-bottom: 12px;
}
.brand-title {
  font-family: 'Quicksand', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: white;
  line-height: 1.2;
  text-shadow: 0 2px 8px rgba(0,0,0,0.25);
}
.brand-sub {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.70);
  margin-top: 4px;
}

/* Nav */
.nav-section-label {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--text-light);
  padding: 18px 20px 6px;
}
.sidebar a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 16px;
  margin: 2px 10px;
  color: var(--text-mid);
  text-decoration: none;
  font-size: 13.5px;
  font-weight: 600;
  border-radius: 14px;
  transition: all 0.22s;
}
.sidebar a .nav-icon {
  width: 36px; height: 36px;
  border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  background: var(--primary-soft);
  color: var(--primary-light);
  flex-shrink: 0;
  transition: all 0.22s;
}
.sidebar a:hover {
  background: var(--primary-soft);
  color: var(--text-dark);
  transform: translateX(4px);
}
.sidebar a:hover .nav-icon {
  background: #233056;
  transform: scale(1.1);
}
.sidebar a.active {
  background: linear-gradient(135deg, #2563EB, #3B82F6);
  color: white;
  box-shadow: 0 6px 20px rgba(59,130,246,0.45);
}
.sidebar a.active .nav-icon {
  background: rgba(255,255,255,0.22);
  color: white;
}

/* Doctor chat links */
.doctor-chat-link .nav-icon {
  background: var(--rose-soft);
  color: var(--rose);
}
.sidebar a.doctor-chat-link:hover .nav-icon {
  background: #1B2A4A;
}

.sidebar-footer {
  padding: 16px 20px;
  border-top: 1.5px dashed var(--sidebar-border);
  margin-top: auto;
  font-size: 11px;
  color: var(--text-light);
  text-align: center;
  font-weight: 600;
}

/* ═══ MAIN ═══ */
.main {
  margin-left: 270px;
  padding: 28px 32px 48px;
  min-height: 100vh;
}

/* TOPBAR */
.topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 28px;
  flex-wrap: wrap;
  gap: 16px;
}
.greeting-title {
  font-family: 'Quicksand', sans-serif;
  font-size: 28px;
  font-weight: 700;
  color: var(--text-dark);
}
.greeting-title span { color: var(--primary-light); }
.greeting-sub {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  margin-top: 3px;
  letter-spacing: 0.04em;
}
.topbar-right {
  display: flex;
  align-items: center;
  gap: 12px;
}
.profile-pill {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--off-white);
  padding: 6px 16px 6px 6px;
  border-radius: 50px;
  box-shadow: 0 4px 18px rgba(0,0,0,0.35);
  border: 2px solid var(--sidebar-border);
  cursor: pointer;
  transition: all 0.2s;
}
.profile-pill:hover { box-shadow: 0 8px 28px rgba(59,130,246,0.25); transform: translateY(-2px); }
.profile-pill img {
  width: 42px; height: 42px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--primary);
}
.profile-pill-text { font-size: 12.5px; font-weight: 700; color: var(--text-dark); }
.profile-pill-text small { display: block; font-size: 10px; color: var(--text-light); font-weight: 600; }
.btn-logout {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  background: var(--off-white);
  border: 2px solid var(--sidebar-border);
  color: var(--text-mid);
  padding: 10px 20px;
  border-radius: 50px;
  font-size: 12.5px;
  font-weight: 700;
  text-decoration: none;
  transition: all 0.22s;
  box-shadow: 0 4px 14px rgba(0,0,0,0.3);
}
.btn-logout:hover {
  background: var(--rose-soft);
  border-color: var(--rose);
  color: var(--text-dark);
  transform: translateY(-2px);
}

/* HERO */
.hero-banner {
  background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 55%, #38BDF8 100%);
  border-radius: 28px;
  padding: 34px 38px;
  margin-bottom: 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: relative;
  overflow: hidden;
  box-shadow: 0 12px 40px rgba(37,99,235,0.35);
}
.hero-banner::before {
  content: '';
  position: absolute;
  top: -60px; right: 220px;
  width: 200px; height: 200px;
  background: rgba(255,255,255,0.10);
  border-radius: 50%;
}
.hero-banner::after {
  content: '';
  position: absolute;
  bottom: -40px; right: 120px;
  width: 140px; height: 140px;
  background: rgba(255,255,255,0.08);
  border-radius: 50%;
}
.hero-text .hero-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255,255,255,0.20);
  backdrop-filter: blur(10px);
  border-radius: 50px;
  padding: 4px 14px;
  font-size: 11px;
  font-weight: 800;
  color: white;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  margin-bottom: 12px;
}
.hero-title {
  font-family: 'Quicksand', sans-serif;
  font-size: 28px;
  font-weight: 700;
  color: white;
  line-height: 1.25;
  text-shadow: 0 2px 12px rgba(0,0,0,0.25);
}
.hero-title span { font-size: 26px; }
.hero-sub {
  font-size: 13px;
  color: rgba(255,255,255,0.85);
  margin-top: 8px;
  font-weight: 600;
}
.hero-badges {
  display: flex;
  gap: 8px;
  margin-top: 16px;
  flex-wrap: wrap;
}
.hero-badge {
  background: rgba(255,255,255,0.18);
  backdrop-filter: blur(8px);
  border-radius: 50px;
  padding: 5px 14px;
  font-size: 11.5px;
  font-weight: 700;
  color: white;
  display: flex;
  align-items: center;
  gap: 5px;
  border: 1px solid rgba(255,255,255,0.25);
}
.hero-icons {
  display: flex;
  gap: 14px;
  align-items: center;
  position: relative;
  z-index: 1;
}
.hero-bubble {
  background: rgba(255,255,255,0.14);
  backdrop-filter: blur(12px);
  border-radius: 22px;
  padding: 18px 20px;
  text-align: center;
  border: 2px solid rgba(255,255,255,0.25);
  box-shadow: 0 8px 24px rgba(0,0,0,0.20);
}
.hero-bubble .h-num {
  font-family: 'Quicksand', sans-serif;
  font-size: 28px;
  font-weight: 700;
  color: white;
  line-height: 1;
}
.hero-bubble .h-lbl {
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: rgba(255,255,255,0.75);
  margin-top: 4px;
}

/* STAT CARDS */
.stat-card {
  background: var(--off-white);
  border-radius: 24px;
  padding: 22px 18px;
  text-align: center;
  transition: all 0.3s;
  border: 2px solid var(--sidebar-border);
  box-shadow: 0 6px 24px rgba(0,0,0,0.30);
  position: relative;
  overflow: hidden;
  cursor: default;
}
.stat-card:hover {
  transform: translateY(-8px) scale(1.02);
  box-shadow: 0 18px 40px rgba(59,130,246,0.20);
  border-color: var(--primary);
}
.stat-card.c-primary { border-color: var(--sidebar-border); }
.stat-card.c-rose    { border-color: var(--sidebar-border); }
.stat-card.c-danger  { border-color: var(--sidebar-border); }
.stat-card.c-amber   { border-color: var(--sidebar-border); }
.stat-card.c-mint    { border-color: var(--sidebar-border); }

.stat-card .blob {
  position: absolute;
  top: -20px; right: -20px;
  width: 90px; height: 90px;
  border-radius: 50%;
  opacity: 0.18;
}
.c-primary .blob { background: var(--primary); }
.c-rose    .blob { background: var(--rose); }
.c-danger  .blob { background: var(--danger); }
.c-amber   .blob { background: var(--amber); }
.c-mint    .blob { background: var(--mint-deep); }

.stat-icon-wrap {
  width: 62px; height: 62px;
  border-radius: 20px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 14px;
  font-size: 28px;
  position: relative;
  z-index: 1;
}
.c-primary .stat-icon-wrap { background: var(--primary-soft); }
.c-rose    .stat-icon-wrap { background: var(--rose-soft); }
.c-danger  .stat-icon-wrap { background: var(--danger-soft); }
.c-amber   .stat-icon-wrap { background: var(--amber-soft); }
.c-mint    .stat-icon-wrap { background: #122A24; }

.stat-number {
  font-family: 'Quicksand', sans-serif;
  font-size: 38px;
  font-weight: 700;
  line-height: 1;
  margin-bottom: 6px;
}
.c-primary .stat-number { color: var(--primary-light); }
.c-rose    .stat-number { color: var(--rose); }
.c-danger  .stat-number { color: var(--danger); }
.c-amber   .stat-number { color: var(--amber); }
.c-mint    .stat-number { color: var(--mint); }

.stat-label {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-light);
}
.stat-trend {
  margin-top: 10px;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 50px;
}
.c-primary .stat-trend { background: var(--primary-soft); color: var(--primary-light); }
.c-rose    .stat-trend { background: var(--rose-soft);    color: var(--rose); }
.c-danger  .stat-trend { background: var(--danger-soft);  color: var(--danger); }
.c-amber   .stat-trend { background: var(--amber-soft);   color: var(--amber); }
.c-mint    .stat-trend { background: #122A24;             color: var(--mint); }

/* CHART CARDS */
.chart-card {
  background: var(--off-white);
  border-radius: 24px;
  padding: 24px;
  border: 2px solid var(--sidebar-border);
  box-shadow: 0 6px 24px rgba(0,0,0,0.30);
  transition: all 0.25s;
  height: 100%;
}
.chart-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 16px 40px rgba(59,130,246,0.18);
  border-color: var(--primary);
}
.chart-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 8px;
}
.chart-title {
  font-family: 'Quicksand', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: var(--text-dark);
  display: flex;
  align-items: center;
  gap: 8px;
}
.chart-icon {
  width: 34px; height: 34px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.chart-badge {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 4px 12px;
  border-radius: 50px;
}

/* INFO CARDS */
.info-card {
  background: var(--off-white);
  border-radius: 20px;
  padding: 20px;
  border: 2px solid var(--sidebar-border);
  box-shadow: 0 4px 16px rgba(0,0,0,0.30);
  height: 100%;
  transition: all 0.25s;
}
.info-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(59,130,246,0.18); border-color: var(--primary); }
.info-card-title {
  font-family: 'Quicksand', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: var(--text-dark);
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Progress bars */
.prog-row { margin-bottom: 14px; }
.prog-label {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  font-weight: 700;
  color: var(--text-mid);
  margin-bottom: 5px;
}
.prog-bar-bg {
  height: 10px;
  border-radius: 50px;
  background: var(--primary-soft);
  overflow: hidden;
}
.prog-bar-fill {
  height: 100%;
  border-radius: 50px;
  transition: width 1s ease;
}

/* Quick actions */
.quick-action {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: #0E1530;
  border-radius: 14px;
  text-decoration: none;
  color: var(--text-dark);
  font-size: 13px;
  font-weight: 700;
  transition: all 0.22s;
  border: 1.5px solid transparent;
  margin-bottom: 8px;
}
.quick-action:last-child { margin-bottom: 0; }
.quick-action:hover {
  background: var(--primary-soft);
  border-color: var(--primary);
  color: var(--text-dark);
  transform: translateX(5px);
}
.qa-icon {
  width: 38px; height: 38px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
  flex-shrink: 0;
}

/* Activity feed */
.activity-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1.5px dashed var(--sidebar-border);
}
.activity-item:last-child { border-bottom: none; }
.act-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
  margin-top: 5px;
}
.act-text { font-size: 12.5px; font-weight: 600; color: var(--text-mid); line-height: 1.5; }
.act-time { font-size: 10.5px; color: var(--text-light); margin-top: 2px; font-weight: 600; }

/* Section title */
.section-title {
  font-family: 'Quicksand', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: var(--text-dark);
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.section-title::after {
  content: '';
  flex: 1;
  height: 2px;
  background: linear-gradient(90deg, var(--sidebar-border), transparent);
  border-radius: 2px;
}

/* Floating decorative bubbles */
.deco-bubble {
  position: fixed;
  border-radius: 50%;
  pointer-events: none;
  z-index: 0;
  opacity: 0.25;
}
.db1 { width:180px;height:180px; background:radial-gradient(circle,#3B82F6,transparent); top:-40px;right:100px; }
.db2 { width:120px;height:120px; background:radial-gradient(circle,#38BDF8,transparent); bottom:80px;right:60px; }
.db3 { width:90px;height:90px;  background:radial-gradient(circle,#34D399,transparent); bottom:200px;left:300px; }

@media(max-width:992px){
  .sidebar{ transform:translateX(-100%); }
  .main{ margin-left:0; padding:16px; }
}
  
  
</style>
</head>
<body>

<!-- Floating decorative blobs -->
<div class="deco-bubble db1"></div>
<div class="deco-bubble db2"></div>
<div class="deco-bubble db3"></div>

<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-bubble">💊</div>
    <div class="brand-title">Pharmacy<br>Panel</div>
    <div class="brand-sub">Management System</div>
  </div>

  <div style="padding:8px 0; flex:1;">
    <div class="nav-section-label">Main Navigation</div>

    <a class="active" href="#">
      <div class="nav-icon"><i class="fa-solid fa-table-cells-large"></i></div> Dashboard
    </a>
    <a href="pharmacistprescription.php">
      <div class="nav-icon"><i class="fa-solid fa-file-prescription"></i></div> Prescriptions
    </a>
    <a href="pharmacistmedicine.php">
      <div class="nav-icon"><i class="fa-solid fa-pills"></i></div> Medicines
    </a>
    <a href="pharmaciststock.php">
      <div class="nav-icon"><i class="fa-solid fa-warehouse"></i></div> Stock Control
    </a>
    <a href="pharmacistbilling.php">
      <div class="nav-icon"><i class="fa-solid fa-receipt"></i></div> Billing
    </a>

    <div class="nav-section-label" style="margin-top:8px;">Physician Chat</div>
    <?php while($doctor = $doctors->fetch_assoc()): ?>
    <a href="chat.php?user=<?= $doctor['user_id'] ?>" class="doctor-chat-link">
      <div class="nav-icon"><i class="fa-solid fa-comments"></i></div>
      <?= htmlspecialchars($doctor['full_name']) ?>
    </a>
    <?php endwhile; ?>
  </div>

  <div class="sidebar-footer">
    🌿 Earthen Luxe Pharmacy &nbsp;·&nbsp; v3.0
  </div>
</div>

<!-- ═══ MAIN ═══ -->
<div class="main" style="position:relative;z-index:1;">

  <!-- TOPBAR -->
  <div class="topbar">
    <div>
      <div class="greeting-title">Hello, <span><?= htmlspecialchars($full_name) ?></span> 👋</div>
      <div class="greeting-sub">📅 <?= date('l, F j, Y') ?> &nbsp;·&nbsp; Clinical Pharmacy Dashboard</div>
    </div>
    <div class="topbar-right">
      <div class="profile-pill" onclick="document.getElementById('profileInput').click()">
        <img id="profilePreview" src="<?= $profilePic ?>" alt="Profile">
        <div class="profile-pill-text">
          <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>
          <small><i class="fa-solid fa-camera" style="color:var(--primary);"></i> Update photo</small>
        </div>
      </div>
      <input type="file" id="profileInput" style="display:none;" accept="image/*">
      <a href="logout.php" class="btn-logout">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Exit
      </a>
    </div>
  </div>

  <!-- HERO -->
  <div class="hero-banner mb-4">
    <div class="hero-text">
      <div class="hero-eyebrow">✦ Smart Pharmacy Management</div>
      <div class="hero-title">
        Precision in every prescription 💊<br>
        <span>Real-time stock oversight</span>
      </div>
      <div class="hero-sub">Manage medicines, verify prescriptions &amp; monitor inventory — all in one place.</div>
      <div class="hero-badges">
        <div class="hero-badge">🔒 100% Secure</div>
        <div class="hero-badge">⚡ Live Data</div>
        <div class="hero-badge">📋 Easy Reports</div>
      </div>
    </div>
    <div class="hero-icons">
      <div class="hero-bubble">
        <div class="h-num"><?= $total_medicines ?></div>
        <div class="h-lbl">Medicines</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div class="hero-bubble">
          <div class="h-num"><?= $pending ?></div>
          <div class="h-lbl">Pending Rx</div>
        </div>
        <div class="hero-bubble">
          <div class="h-num"><?= $completed ?></div>
          <div class="h-lbl">Completed</div>
        </div>
      </div>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="section-title">📊 Overview</div>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg" style="min-width:160px;">
      <div class="stat-card c-primary">
        <div class="blob"></div>
        <div class="stat-icon-wrap">💊</div>
        <div class="stat-number"><?= $total_medicines ?></div>
        <div class="stat-label">Total Medicines</div>
        <div class="stat-trend"><i class="fa-solid fa-layer-group"></i> Formulations</div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg" style="min-width:160px;">
      <div class="stat-card c-amber">
        <div class="blob"></div>
        <div class="stat-icon-wrap">⚠️</div>
        <div class="stat-number"><?= $low_stock ?></div>
        <div class="stat-label">Low Stock</div>
        <div class="stat-trend"><i class="fa-solid fa-arrow-trend-down"></i> Reorder needed</div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg" style="min-width:160px;">
      <div class="stat-card c-danger">
        <div class="blob"></div>
        <div class="stat-icon-wrap">📅</div>
        <div class="stat-number"><?= $expired ?></div>
        <div class="stat-label">Expired Stock</div>
        <div class="stat-trend"><i class="fa-solid fa-clock"></i> Needs removal</div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg" style="min-width:160px;">
      <div class="stat-card c-rose">
        <div class="blob"></div>
        <div class="stat-icon-wrap">📋</div>
        <div class="stat-number"><?= $pending ?></div>
        <div class="stat-label">Pending Rx</div>
        <div class="stat-trend"><i class="fa-solid fa-hourglass-half"></i> Awaiting verify</div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg" style="min-width:160px;">
      <div class="stat-card c-mint">
        <div class="blob"></div>
        <div class="stat-icon-wrap">✅</div>
        <div class="stat-number"><?= $completed ?></div>
        <div class="stat-label">Completed</div>
        <div class="stat-trend"><i class="fa-solid fa-check-circle"></i> Fulfilled</div>
      </div>
    </div>
  </div>

  <!-- CHARTS ROW 1 -->
  <div class="section-title">📈 Analytics</div>
  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">
            <div class="chart-icon" style="background:#EEEDFE;color:#534AB7;">📦</div>
            Inventory Status
          </div>
          <span class="chart-badge" style="background:#EEEDFE;color:#534AB7;">Live</span>
        </div>
        <canvas id="medChart" height="200"></canvas>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">
            <div class="chart-icon" style="background:#E1F5EE;color:#0F6E56;">🥧</div>
            Prescription Split
          </div>
          <span class="chart-badge" style="background:#E1F5EE;color:#0F6E56;">Today</span>
        </div>
        <canvas id="presChart" height="200"></canvas>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">
            <div class="chart-icon" style="background:#FBEAF0;color:#D4537E;">📉</div>
            Stock Health
          </div>
        </div>
        <canvas id="stockGauge" height="200"></canvas>
      </div>
    </div>
  </div>

  <!-- CHARTS ROW 2 + INFO -->
  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">
            <div class="chart-icon" style="background:#FAEEDA;color:#BA7517;">📊</div>
            Medicine vs Low Stock vs Expired
          </div>
        </div>
        <canvas id="radarChart" height="240"></canvas>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">
            <div class="chart-icon" style="background:#FCEBEB;color:#A32D2D;">📋</div>
            Rx Fulfillment Rate
          </div>
        </div>
        <canvas id="hbarChart" height="240"></canvas>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="info-card">
        <div class="info-card-title">
          <span style="font-size:18px;">⚡</span> Quick Actions
        </div>
        <a href="pharmacistprescription.php" class="quick-action">
          <div class="qa-icon" style="background:#FBEAF0;color:#D4537E;">📋</div>
          View Prescriptions
        </a>
        <a href="pharmacistmedicine.php" class="quick-action">
          <div class="qa-icon" style="background:#EEEDFE;color:#534AB7;">💊</div>
          Manage Medicines
        </a>
        <a href="pharmaciststock.php" class="quick-action">
          <div class="qa-icon" style="background:#E1F5EE;color:#0F6E56;">📦</div>
          Stock Control
        </a>
        <a href="pharmacistbilling.php" class="quick-action">
          <div class="qa-icon" style="background:#FAEEDA;color:#BA7517;">🧾</div>
          Billing
        </a>
      </div>
    </div>
  </div>

  <!-- STOCK HEALTH BARS + ACTIVITY -->
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="info-card">
        <div class="info-card-title"><span style="font-size:18px;">🏥</span> Stock Health Overview</div>

        <?php
          $healthy   = max(0, $total_medicines - $low_stock - $expired);
          $total_denom = max(1, $total_medicines);
        ?>

        <div class="prog-row">
          <div class="prog-label">
            <span>✅ Healthy Stock</span>
            <span><?= $healthy ?> / <?= $total_medicines ?></span>
          </div>
          <div class="prog-bar-bg">
            <div class="prog-bar-fill" style="width:<?= round(($healthy/$total_denom)*100) ?>%;background:linear-gradient(90deg,#1D9E75,#9FE1CB);"></div>
          </div>
        </div>

        <div class="prog-row">
          <div class="prog-label">
            <span>⚠️ Low Inventory</span>
            <span><?= $low_stock ?> / <?= $total_medicines ?></span>
          </div>
          <div class="prog-bar-bg">
            <div class="prog-bar-fill" style="width:<?= round(($low_stock/$total_denom)*100) ?>%;background:linear-gradient(90deg,#EF9F27,#FAC775);"></div>
          </div>
        </div>

        <div class="prog-row">
          <div class="prog-label">
            <span>❌ Expired</span>
            <span><?= $expired ?> / <?= $total_medicines ?></span>
          </div>
          <div class="prog-bar-bg">
            <div class="prog-bar-fill" style="width:<?= round(($expired/$total_denom)*100) ?>%;background:linear-gradient(90deg,#E24B4A,#F7C1C1);"></div>
          </div>
        </div>

        <div class="prog-row">
          <div class="prog-label">
            <span>📋 Prescription Completion</span>
            <?php $rx_total = max(1, $pending + $completed); ?>
            <span><?= $completed ?> / <?= $rx_total ?></span>
          </div>
          <div class="prog-bar-bg">
            <div class="prog-bar-fill" style="width:<?= round(($completed/$rx_total)*100) ?>%;background:linear-gradient(90deg,#534AB7,#7F77DD);"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="info-card">
        <div class="info-card-title"><span style="font-size:18px;">🕐</span> Recent Activity</div>
        <div class="activity-item">
          <div class="act-dot" style="background:#1D9E75;"></div>
          <div>
            <div class="act-text">Dashboard loaded successfully — all systems operational</div>
            <div class="act-time">Just now</div>
          </div>
        </div>
        <div class="activity-item">
          <div class="act-dot" style="background:#EF9F27;"></div>
          <div>
            <div class="act-text"><?= $low_stock ?> medicine(s) flagged for low stock — reorder advised</div>
            <div class="act-time">Auto-detected</div>
          </div>
        </div>
        <div class="activity-item">
          <div class="act-dot" style="background:#E24B4A;"></div>
          <div>
            <div class="act-text"><?= $expired ?> expired item(s) detected — removal recommended</div>
            <div class="act-time">Auto-detected</div>
          </div>
        </div>
        <div class="activity-item">
          <div class="act-dot" style="background:#D4537E;"></div>
          <div>
            <div class="act-text"><?= $pending ?> prescription(s) awaiting pharmacist verification</div>
            <div class="act-time">Pending queue</div>
          </div>
        </div>
        <div class="activity-item">
          <div class="act-dot" style="background:#534AB7;"></div>
          <div>
            <div class="act-text"><?= $completed ?> prescription(s) successfully fulfilled today</div>
            <div class="act-time">Completed</div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /main -->

<!-- PROFILE UPLOAD SCRIPT (Logic Unchanged) -->
<script>
document.getElementById('profileInput').addEventListener('change', function() {
  let file = this.files[0];
  if (!file) return;
  let formData = new FormData();
  formData.append("profile_image", file);
  fetch("upload_profile.php", {
    method: "POST",
    body: formData,
    credentials: "same-origin"
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      document.getElementById("profilePreview").src = data.url + "?t=" + new Date().getTime();
      alert("Profile portrait updated successfully!");
    } else {
      alert(data.message || "Upload failed");
    }
  })
  .catch(() => alert("Upload error. Please try again."));
});
</script>

<!-- CHARTS -->
<script>
const palette = {
  primary:  '#3B82F6',
  primaryL: '#60A5FA',
  primaryS: '#16213E',
  rose:     '#38BDF8',
  roseL:    '#7DD3FC',
  mint:     '#34D399',
  mintL:    '#10B981',
  amber:    '#FBBF24',
  amberL:   '#FCD34D',
  danger:   '#F87171',
  dangerL:  '#FCA5A5',
  white:    '#FFFFFF',
  textMid:  '#94A3C2',
};

const chartDefaults = {
  responsive: true,
  maintainAspectRatio: true,
  plugins: {
    tooltip: {
      backgroundColor: '#1E1A3C',
      titleColor: '#FFFFFF',
      bodyColor: '#CECBF6',
      borderColor: '#534AB7',
      borderWidth: 1,
      cornerRadius: 10,
      padding: 10,
    },
    legend: {
      labels: { color: palette.textMid, font: { family: 'Nunito', weight: '700', size: 12 }, usePointStyle: true, pointStyle: 'circle', padding: 16 }
    }
  }
};

/* 1. Bar — Inventory */
new Chart(document.getElementById('medChart'), {
  type: 'bar',
  data: {
    labels: ['Total Stock', 'Low Stock', 'Expired'],
    datasets: [{
      data: [<?= $total_medicines ?>, <?= $low_stock ?>, <?= $expired ?>],
      backgroundColor: [palette.primary, palette.amber, palette.danger],
      borderRadius: 14,
      barPercentage: 0.6,
    }]
  },
  options: {
    ...chartDefaults,
    plugins: { ...chartDefaults.plugins, legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { color: palette.textMid, font: { family: 'Nunito', weight: '700' } } },
      y: { grid: { color: 'rgba(83,74,183,0.10)' }, ticks: { color: palette.textMid, stepSize: 1 }, beginAtZero: true }
    }
  }
});

/* 2. Doughnut — Prescriptions */
new Chart(document.getElementById('presChart'), {
  type: 'doughnut',
  data: {
    labels: ['Pending', 'Completed'],
    datasets: [{
      data: [<?= $pending ?>, <?= $completed ?>],
      backgroundColor: [palette.rose, palette.mint],
      borderColor: [palette.white, palette.white],
      borderWidth: 4,
      hoverOffset: 10,
      cutout: '70%'
    }]
  },
  options: { ...chartDefaults }
});

/* 3. Polar — Stock gauge */
new Chart(document.getElementById('stockGauge'), {
  type: 'polarArea',
  data: {
    labels: ['Healthy', 'Low', 'Expired', 'Pending Rx'],
    datasets: [{
      data: [
        Math.max(0, <?= $total_medicines ?> - <?= $low_stock ?> - <?= $expired ?>),
        <?= $low_stock ?>,
        <?= $expired ?>,
        <?= $pending ?>
      ],
      backgroundColor: [palette.mintL, palette.amberL, palette.dangerL, palette.primaryS],
      borderColor:     [palette.mint, palette.amber, palette.danger, palette.primary],
      borderWidth: 2,
    }]
  },
  options: {
    ...chartDefaults,
    scales: { r: { ticks: { display: false }, grid: { color: 'rgba(83,74,183,0.10)' } } }
  }
});

/* 4. Radar */
new Chart(document.getElementById('radarChart'), {
  type: 'radar',
  data: {
    labels: ['Total Medicines', 'Low Stock', 'Expired', 'Pending Rx', 'Completed Rx'],
    datasets: [{
      label: 'Pharmacy Stats',
      data: [<?= $total_medicines ?>, <?= $low_stock ?>, <?= $expired ?>, <?= $pending ?>, <?= $completed ?>],
      backgroundColor: 'rgba(83,74,183,0.15)',
      borderColor: palette.primary,
      borderWidth: 2.5,
      pointBackgroundColor: palette.rose,
      pointRadius: 5,
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      r: {
        ticks: { display: false, backdropColor: 'transparent' },
        grid: { color: 'rgba(83,74,183,0.15)' },
        pointLabels: { color: palette.textMid, font: { family: 'Nunito', weight: '700', size: 11 } }
      }
    },
    plugins: { ...chartDefaults.plugins, legend: { display: false } }
  }
});

/* 5. Horizontal bar — fulfillment */
new Chart(document.getElementById('hbarChart'), {
  type: 'bar',
  data: {
    labels: ['Completed', 'Pending', 'Low Stock', 'Expired'],
    datasets: [{
      label: 'Count',
      data: [<?= $completed ?>, <?= $pending ?>, <?= $low_stock ?>, <?= $expired ?>],
      backgroundColor: [palette.mint, palette.primary, palette.amber, palette.danger],
      borderRadius: 10,
      barPercentage: 0.65,
    }]
  },
  options: {
    ...chartDefaults,
    indexAxis: 'y',
    plugins: { ...chartDefaults.plugins, legend: { display: false } },
    scales: {
      x: { grid: { color: 'rgba(83,74,183,0.10)' }, ticks: { color: palette.textMid, stepSize: 1 }, beginAtZero: true },
      y: { grid: { display: false }, ticks: { color: palette.textMid, font: { family: 'Nunito', weight: '700' } } }
    }
  }
});
</script>

</body>
</html>
