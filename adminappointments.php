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

/* ---------------- DELETE ---------------- */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM patients WHERE user_id=$id");
    $conn->query("DELETE FROM users WHERE user_id=$id");
    header("Location: adminpatients.php");
    exit();
}

/* ---------------- ADD / UPDATE ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $gender = $_POST['gender'];
    $age = $_POST['age'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];

    if ($user_id == 0) {
        $conn->query("INSERT INTO users (full_name,email,role,status) VALUES ('$full_name','$email','patient','active')");
        $new_id = $conn->insert_id;
        $conn->query("INSERT INTO patients (user_id,gender,age,contact,address) VALUES ($new_id,'$gender','$age','$contact','$address')");
    } else {
        $conn->query("UPDATE users SET full_name='$full_name', email='$email' WHERE user_id=$user_id");
        $conn->query("UPDATE patients SET gender='$gender', age='$age', contact='$contact', address='$address' WHERE user_id=$user_id");
    }
    header("Location: adminpatients.php");
    exit();
}

/* ---------------- EDIT FETCH ---------------- */
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $res = $conn->query("SELECT u.user_id,u.full_name,u.email, p.gender,p.age,p.contact,p.address FROM users u JOIN patients p ON p.user_id=u.user_id WHERE u.user_id=$id LIMIT 1");
    $edit = $res->fetch_assoc();
}

/* ---------------- ALL PATIENTS ---------------- */
$patients = $conn->query("SELECT u.user_id,u.full_name,u.email, p.gender,p.age,p.contact,p.address FROM users u JOIN patients p ON p.user_id=u.user_id WHERE u.role='patient'");

/* ---------------- ADMIN INFO ---------------- */
$user_id = (int)$_SESSION['user_id'];
$admin = $conn->query("SELECT full_name, profile_image FROM users WHERE user_id=$user_id")->fetch_assoc();
$admin_name = $admin['full_name'] ?? 'Admin';
$profile = $admin['profile_image'] ?? 'default-avatar.png';
date_default_timezone_set("Asia/Karachi");
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");

// Statistics (keep logic unchanged)
$patient_count = $patients->num_rows;
$male_count = $female_count = $other_count = 0;
$patients->data_seek(0);
while($p = $patients->fetch_assoc()) {
    $gender = strtolower($p['gender'] ?? '');
    if($gender == 'male') $male_count++;
    elseif($gender == 'female') $female_count++;
    else $other_count++;
}
$patients->data_seek(0);

$age_0_18 = $age_19_35 = $age_36_50 = $age_51_plus = 0;
$patients->data_seek(0);
while($p = $patients->fetch_assoc()) {
    $age = (int)($p['age'] ?? 0);
    if($age <= 18) $age_0_18++;
    elseif($age <= 35) $age_19_35++;
    elseif($age <= 50) $age_36_50++;
    elseif($age > 50) $age_51_plus++;
}
$patients->data_seek(0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Patients Management — Zaman Medical</title>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

/* Panel grid */
.lower-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
.panel {
  background: var(--surface);
  backdrop-filter: blur(20px) saturate(160%);
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
}

/* Form Card (glassmorphism) */
.form-card {
  background: var(--surface);
  backdrop-filter: blur(20px) saturate(160%);
  border: 1px solid var(--border-s);
  border-radius: var(--radius);
  overflow: hidden;
  box-shadow: var(--shadow-card);
}
.form-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--border);
  background: rgba(198,42,42,0.06);
  display: flex;
  align-items: center;
  gap: 10px;
}
.form-header h3 {
  font-size: 16px;
  font-weight: 700;
  color: var(--text-1);
}
.form-body { padding: 24px; }
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 18px;
}
.form-group label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-3);
  display: block;
  margin-bottom: 6px;
}
.form-control {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: 14px;
  font-size: 13px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  background: rgba(255,255,255,0.7);
  transition: all 0.2s;
}
.form-control:focus {
  outline: none;
  border-color: var(--red-xl);
  box-shadow: 0 0 0 3px rgba(198,42,42,0.1);
}
.btn-submit {
  background: var(--grad-1);
  color: #fff;
  border: none;
  padding: 12px 28px;
  border-radius: 100px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 20px;
  font-family: 'Plus Jakarta Sans', sans-serif;
}
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(198,42,42,0.3); }
.btn-cancel {
  background: rgba(108,117,125,0.15);
  color: var(--text-2);
  border: 1px solid var(--border);
  padding: 12px 28px;
  border-radius: 100px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-left: 12px;
  transition: all 0.2s;
}
.btn-cancel:hover { background: rgba(108,117,125,0.25); transform: translateY(-2px); }

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
.patients-table {
  width: 100%;
  border-collapse: collapse;
}
.patients-table thead th {
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
.patients-table tbody td {
  padding: 14px 20px;
  font-size: 13px;
  color: var(--text-2);
  border-bottom: 1px solid var(--border);
}
.patients-table tbody tr:hover {
  background: rgba(198,42,42,0.04);
}
.gender-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 100px;
  font-size: 10px;
  font-weight: 700;
}
.gender-male { background: rgba(59,130,246,0.12); color: #3b82f6; }
.gender-female { background: rgba(236,72,153,0.12); color: #ec489a; }
.gender-other { background: rgba(139,92,246,0.12); color: #8b5cf6; }
.action-buttons {
  display: flex;
  gap: 8px;
}
.btn-edit, .btn-delete {
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 11px;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.btn-edit { background: rgba(198,42,42,0.1); color: var(--red); }
.btn-edit:hover { background: var(--red); color: #fff; }
.btn-delete { background: rgba(108,117,125,0.12); color: #6c757d; }
.btn-delete:hover { background: #dc3545; color: #fff; }
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
.lower-grid { animation: riseUp 0.5s 0.15s ease both; }
.float-card:nth-child(1) { animation: floatIn 0.5s 0.05s ease both; }
.float-card:nth-child(2) { animation: floatIn 0.5s 0.12s ease both; }
.float-card:nth-child(3) { animation: floatIn 0.5s 0.19s ease both; }
.float-card:nth-child(4) { animation: floatIn 0.5s 0.26s ease both; }
.form-card, .table-card { animation: riseUp 0.4s 0.1s ease both; }

@media (max-width: 1200px) {
  .lower-grid { grid-template-columns: 1fr; }
  .float-cards-row { flex-wrap: wrap; }
}
@media (max-width: 900px) {
  :root { --sw: 200px; }
  .content { padding: 20px; }
  .topbar-nav { display: none; }
  .form-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR (exact same as dashboard) ══════════════ -->
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
    <a href="adminpatients.php" class="sb-link active"><span class="sb-icon"><i class="fa-solid fa-users"></i></span> Patients</a>
      <a href="adminappointments.php" class="sb-link">
    <span class="sb-icon"><i class="fa-solid fa-calendar-check"></i></span>
    Appointments
  </a>

  <a href="adminbilling.php" class="sb-link">
    <span class="sb-icon"><i class="fa-solid fa-file-invoice-dollar"></i></span>
    Billing
  </a>

  <div class="sb-section-label">System</div>

  <a href="adminsettings.php" class="sb-link">
    <span class="sb-icon"><i class="fa-solid fa-gear"></i></span>
    Settings
  </a>
</nav>

<div class="sb-quick">
  <div class="sb-quick-title">Quick Actions</div>
  <div class="sb-quick-grid">
    <a href="adminpatients.php" class="sb-quick-btn">
      <i class="fa-solid fa-user-plus"></i>
      <span>Add Patient</span>
    </a>
    <a href="admindoctors.php" class="sb-quick-btn">
      <i class="fa-solid fa-user-doctor"></i>
      <span>Doctors</span>
    </a>
    <a href="adminreports.php" class="sb-quick-btn">
      <i class="fa-solid fa-chart-simple"></i>
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
<!-- ═════════════ MAIN CONTENT ═════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-nav">
      <a href="admindashboard.php">Dashboard</a>
      <a href="adminpatients.php" class="active">Patients</a>
    </div>

    <div class="topbar-right">
      <div class="tb-greeting">
        <?= $greeting ?>, <strong><?= htmlspecialchars($admin_name) ?></strong>
      </div>

      <a href="#" class="icon-btn"><i class="fa-solid fa-bell"></i></a>
      <a href="adminpatients.php" class="btn-action">
        <i class="fa-solid fa-user-plus"></i> Add Patient
      </a>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="content">

    <h1 class="page-title">Patients Management</h1>

    <!-- FLOAT CARDS -->
    <div class="float-cards-row">
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-users"></i></div>
        <div>
          <div class="fc-label">Total Patients</div>
          <div class="fc-value"><?= $patient_count ?></div>
          <div class="fc-sub">Registered patients</div>
        </div>
      </div>

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-mars"></i></div>
        <div>
          <div class="fc-label">Male</div>
          <div class="fc-value"><?= $male_count ?></div>
        </div>
      </div>

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-venus"></i></div>
        <div>
          <div class="fc-label">Female</div>
          <div class="fc-value"><?= $female_count ?></div>
        </div>
      </div>

      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-user-group"></i></div>
        <div>
          <div class="fc-label">Others</div>
          <div class="fc-value"><?= $other_count ?></div>
        </div>
      </div>
    </div>

    <!-- FORM + TABLE -->
    <div class="lower-grid">

      <!-- FORM -->
      <div class="form-card">
        <div class="form-header">
          <i class="fa-solid fa-user-plus"></i>
          <h3><?= $edit ? "Update Patient" : "Add New Patient" ?></h3>
        </div>

        <form method="POST" class="form-body">
          <input type="hidden" name="user_id" value="<?= $edit['user_id'] ?? 0 ?>">

          <div class="form-grid">
            <div class="form-group">
              <label>Full Name</label>
              <input class="form-control" name="full_name" value="<?= $edit['full_name'] ?? '' ?>" required>
            </div>

            <div class="form-group">
              <label>Email</label>
              <input class="form-control" name="email" value="<?= $edit['email'] ?? '' ?>" required>
            </div>

            <div class="form-group">
              <label>Gender</label>
              <select class="form-control" name="gender">
                <option>Male</option>
                <option>Female</option>
                <option>Other</option>
              </select>
            </div>

            <div class="form-group">
              <label>Age</label>
              <input class="form-control" name="age" value="<?= $edit['age'] ?? '' ?>">
            </div>

            <div class="form-group">
              <label>Contact</label>
              <input class="form-control" name="contact" value="<?= $edit['contact'] ?? '' ?>">
            </div>

            <div class="form-group">
              <label>Address</label>
              <input class="form-control" name="address" value="<?= $edit['address'] ?? '' ?>">
            </div>
          </div>

          <button class="btn-submit">
            <i class="fa-solid fa-save"></i>
            Save Patient
          </button>

          <?php if ($edit): ?>
            <a href="adminpatients.php" class="btn-cancel">Cancel</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- TABLE -->
      <div class="table-card">
        <div class="table-header">
          <h3><i class="fa-solid fa-users"></i> Patient Records</h3>
          <span class="record-count"><?= $patient_count ?> Records</span>
        </div>

        <table class="patients-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Gender</th>
              <th>Age</th>
              <th>Contact</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php if ($patients->num_rows > 0): ?>
              <?php while ($p = $patients->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($p['full_name']) ?></td>
                  <td><?= htmlspecialchars($p['email']) ?></td>
                  <td>
                    <span class="gender-badge gender-<?= strtolower($p['gender']) ?>">
                      <?= $p['gender'] ?>
                    </span>
                  </td>
                  <td><?= $p['age'] ?></td>
                  <td><?= $p['contact'] ?></td>
                  <td>
                    <div class="action-buttons">
                      <a class="btn-edit" href="?edit=<?= $p['user_id'] ?>">
                        <i class="fa-solid fa-pen"></i> Edit
                      </a>
                      <a class="btn-delete" href="?delete=<?= $p['user_id'] ?>" onclick="return confirm('Delete this patient?')">
                        <i class="fa-solid fa-trash"></i> Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="empty-state">
                  <i class="fa-solid fa-user-xmark"></i>
                  <div>No patients found</div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>

      </div>
    </div>
  </div>
</div>

</body>
</html>