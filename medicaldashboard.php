<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= AUTH ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'department_head') {
    header("Location: login.php");
    exit();
}

/* ================= DB CONNECTION ================= */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ================= PROFILE IMAGE UPLOAD ================= */
if (isset($_POST['upload_image'])) {
    $user_id = $_SESSION['user_id'];
    if (!empty($_FILES['profile_image']['name'])) {
        $targetDir = "uploads/profile/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $fileName      = time() . "_" . basename($_FILES["profile_image"]["name"]);
        $targetFile    = $targetDir . $fileName;
        $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        $allowed       = ['jpg','jpeg','png','gif'];
        if (in_array($imageFileType, $allowed)) {
            if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $targetFile)) {
                $stmt = $conn->prepare("UPDATE users SET profile_image=? WHERE user_id=?");
                $stmt->bind_param("si", $fileName, $user_id);
                $stmt->execute();
                $stmt->close();
                header("Location: medicaldashboard.php");
                exit();
            }
        }
    }
}

/* ================= APPROVE / REJECT ================= */
if (isset($_POST['approve_id'])) {
    $id = (int)$_POST['approve_id'];
    $conn->query("UPDATE users SET status='active' WHERE user_id=$id");
    header("Location: medicaldashboard.php");
    exit();
}
if (isset($_POST['reject_id'])) {
    $id = (int)$_POST['reject_id'];
    $conn->query("UPDATE users SET status='rejected' WHERE user_id=$id");
    header("Location: medicaldashboard.php");
    exit();
}

/* ================= SESSION VARS ================= */
$full_name = $_SESSION['full_name'] ?? 'Department Head';
$user_id   = $_SESSION['user_id']   ?? 0;

/* ================= PROFILE IMAGE ================= */
$imgRow = $conn->query("SELECT profile_image FROM users WHERE user_id=$user_id")->fetch_assoc();
$img    = $imgRow['profile_image'] ?? '';
$imgSrc = $img
    ? 'uploads/profile/' . $img
    : 'https://ui-avatars.com/api/?name=' . urlencode($full_name) . '&background=00C6A7&color=0a0f1e&size=128&bold=true';

/* ================= COUNTS ================= */
$totalDoctors = $conn->query("SELECT COUNT(*) FROM users WHERE role='doctor' AND department_id=1 AND status='active'")->fetch_row()[0];
$totalNurses  = $conn->query("SELECT COUNT(*) FROM users WHERE role='nurse'  AND department_id=1 AND status='active'")->fetch_row()[0];
$pendingCount = $conn->query("SELECT COUNT(*) FROM users WHERE status='inactive' AND department_id=1")->fetch_row()[0];

$pendingLeaves = 0;
$lv_res = $conn->query("
    SELECT COUNT(*) FROM doctor_leaves dl
    JOIN doctors d ON dl.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    WHERE dl.status = 'pending' AND u.department_id = 1
");
if ($lv_res) $pendingLeaves = (int)$lv_res->fetch_row()[0];

$pendingResult = $conn->query("
    SELECT user_id, full_name, role
    FROM users
    WHERE role IN ('doctor','nurse')
    AND status='inactive'
    AND department_id=1
    ORDER BY user_id DESC
");

date_default_timezone_set("Asia/Karachi");
$hour     = date("H");
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
$firstName = htmlspecialchars(explode(' ', $full_name)[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zaman Medical — Command Centre</title>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Neue+Montreal:wght@300;400;500;600&family=Space+Grotesk:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ═══════════════════════════════════════════════
   DESIGN TOKENS — Bioluminescent Deep Space
═══════════════════════════════════════════════ */
:root {
  --void:       #060a14;
  --deep:       #080d1a;
  --abyss:      #0b1220;
  --surface:    #0f1828;
  --lift:       #131f30;
  --raise:      #182638;

  --plasma:     #00C6A7;
  --plasma2:    #00E5C3;
  --nova:       #4D7EFF;
  --nova2:      #7BA3FF;
  --pulse:      #FF6B6B;
  --solar:      #FFB547;
  --violet:     #9B6DFF;

  --txt-0:      #F0F6FF;
  --txt-1:      #8BA3C2;
  --txt-2:      #4A6280;
  --txt-3:      #2A3F58;

  --glow-p:     rgba(0,198,167,.18);
  --glow-n:     rgba(77,126,255,.18);

  --sw: 264px;
  --hdr: 70px;
  --r:  16px;
  --r-sm: 10px;
  --r-lg: 22px;
  --r-xl: 28px;

  --ff-disp: 'Space Grotesk', sans-serif;
  --ff-body: 'Plus Jakarta Sans', sans-serif;

  --ease-expo: cubic-bezier(0.19, 1, 0.22, 1);
  --ease-back: cubic-bezier(0.34, 1.56, 0.64, 1);
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior: smooth; }

body {
  font-family: var(--ff-body);
  background: var(--void);
  color: var(--txt-0);
  overflow-x: hidden;
  min-height: 100vh;
}

/* ── Animated background mesh ───────────────────── */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 1200px 900px at 10% 0%,   rgba(0,198,167,.09) 0%, transparent 55%),
    radial-gradient(ellipse 800px 800px  at 90% 90%,  rgba(77,126,255,.10) 0%, transparent 50%),
    radial-gradient(ellipse 600px 500px  at 50% 40%,  rgba(155,109,255,.05) 0%, transparent 50%);
}

/* Noise grain overlay */
body::after {
  content: '';
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  opacity: .025;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  background-repeat: repeat;
  background-size: 180px;
}

/* ═══════════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════════ */
.sidebar {
  position: fixed; left: 0; top: 0;
  width: var(--sw); height: 100vh;
  background: rgba(8,13,26,.88);
  backdrop-filter: blur(40px) saturate(180%);
  -webkit-backdrop-filter: blur(40px) saturate(180%);
  border-right: 1px solid rgba(255,255,255,.06);
  display: flex; flex-direction: column;
  padding: 20px 14px 20px;
  z-index: 200;
  overflow-y: auto;
}

.sidebar::-webkit-scrollbar { width: 3px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(0,198,167,.2); border-radius: 4px; }

/* Logo */
.logo {
  display: flex; align-items: center; gap: 11px;
  padding: 6px 8px 20px;
  border-bottom: 1px solid rgba(255,255,255,.05);
  margin-bottom: 18px;
}
.logo-mark {
  width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--plasma), var(--nova));
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; color: #fff;
  box-shadow: 0 0 20px rgba(0,198,167,.35), 0 0 40px rgba(0,198,167,.15);
  position: relative;
}
.logo-mark::after {
  content: '';
  position: absolute; inset: -3px;
  border-radius: 14px;
  background: linear-gradient(135deg, var(--plasma), var(--nova));
  z-index: -1; opacity: .2; filter: blur(8px);
}
.logo-txt { line-height: 1.2; }
.logo-txt strong { font-family: var(--ff-disp); font-size: 14px; font-weight: 700; color: var(--txt-0); display: block; }
.logo-txt span { font-size: 10px; color: var(--txt-2); letter-spacing: .4px; }

/* Profile card */
.sp-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r-lg);
  padding: 18px 14px 14px;
  margin-bottom: 18px;
  display: flex; flex-direction: column; align-items: center; gap: 9px;
  position: relative; overflow: hidden;
}
.sp-card::before {
  content: '';
  position: absolute; top: -60px; right: -60px;
  width: 180px; height: 180px; border-radius: 50%;
  background: radial-gradient(var(--plasma), transparent 70%);
  opacity: .07; pointer-events: none;
}

.av-ring {
  width: 72px; height: 72px; border-radius: 50%;
  padding: 2px;
  background: linear-gradient(135deg, var(--plasma), var(--nova));
  position: relative; cursor: pointer; flex-shrink: 0;
}
.av-ring::before {
  content: '';
  position: absolute; inset: -4px; border-radius: 50%;
  background: linear-gradient(135deg, var(--plasma), var(--nova));
  z-index: -1; opacity: .3; filter: blur(10px);
  animation: ringPulse 3s ease-in-out infinite;
}
@keyframes ringPulse {
  0%, 100% { opacity: .3; transform: scale(1); }
  50%       { opacity: .6; transform: scale(1.08); }
}
.av-ring img {
  width: 100%; height: 100%; border-radius: 50%;
  object-fit: cover; display: block;
  background: var(--surface);
  transition: filter .2s;
}
.av-ring:hover img { filter: brightness(.5); }
.av-overlay {
  position: absolute; inset: 2px; border-radius: 50%;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 2px; opacity: 0; transition: opacity .2s;
  color: #fff; font-size: 10px; font-weight: 700; letter-spacing: .3px;
}
.av-overlay i { font-size: 16px; margin-bottom: 2px; }
.av-ring:hover .av-overlay { opacity: 1; }

.sp-name {
  font-family: var(--ff-disp); font-size: 13.5px; font-weight: 700;
  color: var(--txt-0); text-align: center;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;
}
.sp-role {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(0,198,167,.1);
  border: 1px solid rgba(0,198,167,.22);
  color: var(--plasma); font-size: 10px; font-weight: 700;
  padding: 3px 10px; border-radius: 100px; letter-spacing: .3px;
}
.sp-status { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--txt-1); }
.pulse-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--plasma);
  box-shadow: 0 0 8px var(--plasma);
  animation: pDot 2s ease-in-out infinite;
}
@keyframes pDot { 0%,100%{opacity:1;} 50%{opacity:.3;} }

.btn-upload {
  width: 100%; padding: 8px 12px; border: none; cursor: pointer;
  border-radius: var(--r-sm);
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.08);
  color: var(--txt-1); font-size: 11px; font-weight: 600; font-family: var(--ff-body);
  display: flex; align-items: center; justify-content: center; gap: 7px;
  transition: all .2s; margin-top: 2px;
}
.btn-upload:hover {
  background: rgba(0,198,167,.1);
  border-color: rgba(0,198,167,.25);
  color: var(--plasma);
}
#profileFileInput { display: none; }

/* Nav */
.nav { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.nav-label {
  font-size: 9px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;
  color: var(--txt-3); margin: 14px 0 5px 10px;
}
.nav a {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; color: var(--txt-1);
  padding: 9px 11px; border-radius: var(--r-sm);
  font-size: 13px; font-weight: 500;
  transition: all .18s; position: relative; overflow: hidden;
}
.ni {
  width: 30px; height: 30px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0;
  background: rgba(255,255,255,.04);
  transition: all .18s;
}
.nav a:hover { color: var(--txt-0); background: rgba(255,255,255,.05); }
.nav a:hover .ni { background: rgba(0,198,167,.14); color: var(--plasma); }
.nav a.active {
  color: var(--txt-0);
  background: linear-gradient(100deg, rgba(0,198,167,.13), rgba(77,126,255,.09));
  border: 1px solid rgba(0,198,167,.2);
}
.nav a.active .ni {
  background: linear-gradient(135deg, var(--plasma), var(--nova));
  color: #fff;
  box-shadow: 0 0 14px rgba(0,198,167,.4);
}
.nav a.active::before {
  content: ''; position: absolute; left: 0; top: 20%; height: 60%; width: 2.5px;
  border-radius: 0 3px 3px 0;
  background: linear-gradient(180deg, var(--plasma), var(--nova));
}
.nav a.nav-danger { color: rgba(255,107,107,.6); }
.nav a.nav-danger:hover { color: var(--pulse); background: rgba(255,107,107,.06); }
.nav a.nav-danger:hover .ni { background: rgba(255,107,107,.14); color: var(--pulse); }

.nav-badge {
  margin-left: auto; flex-shrink: 0;
  background: var(--solar); color: var(--void);
  font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 100px;
}
.nav-badge.red { background: var(--pulse); color: #fff; }
.nav-divider { height: 1px; background: rgba(255,255,255,.05); margin: 8px 0; }

/* ═══════════════════════════════════════════════
   MAIN LAYOUT
═══════════════════════════════════════════════ */
.main {
  margin-left: var(--sw);
  padding: 24px 28px 40px;
  position: relative; z-index: 1;
  min-height: 100vh;
}

/* ── Header Bar ─────────────────────────────── */
.topbar {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 28px;
  padding: 0 4px;
}
.greeting-line {
  font-family: var(--ff-disp);
  font-size: 28px; font-weight: 700;
  color: var(--txt-0);
  letter-spacing: -.3px;
  display: flex; align-items: center; gap: 10px;
}
.greeting-sub {
  font-size: 12.5px; color: var(--txt-1); margin-top: 4px;
  display: flex; align-items: center; gap: 8px;
}
.breadcrumb-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--txt-3); }

.topbar-right { display: flex; align-items: center; gap: 16px; }

.clock-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r);
  padding: 10px 18px;
  display: flex; flex-direction: column; align-items: flex-end;
  gap: 2px;
}
.clock-time {
  font-family: var(--ff-disp);
  font-size: 22px; font-weight: 700;
  color: var(--plasma);
  letter-spacing: .5px;
  text-shadow: 0 0 20px rgba(0,198,167,.5);
}
.clock-label { font-size: 9px; color: var(--txt-2); letter-spacing: 1.5px; text-transform: uppercase; }

.topbar-av {
  width: 44px; height: 44px; border-radius: 50%; object-fit: cover;
  border: 2px solid rgba(0,198,167,.35);
  box-shadow: 0 0 16px rgba(0,198,167,.3);
}

/* Notification bell */
.notif-btn {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--txt-1); font-size: 14px;
  position: relative; transition: all .18s;
}
.notif-btn:hover { background: rgba(0,198,167,.1); border-color: rgba(0,198,167,.2); color: var(--plasma); }
.notif-pip {
  position: absolute; top: 8px; right: 8px;
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--pulse);
  border: 1.5px solid var(--void);
  box-shadow: 0 0 8px var(--pulse);
}

/* ═══════════════════════════════════════════════
   STAT CARDS
═══════════════════════════════════════════════ */
.cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 22px;
}

.card {
  background: rgba(15,24,40,.7);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r-lg);
  padding: 22px;
  position: relative; overflow: hidden;
  cursor: default;
  transition: transform .25s var(--ease-expo), box-shadow .25s var(--ease-expo), border-color .25s;
  animation: cardIn .5s var(--ease-expo) both;
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
.card:nth-child(1) { animation-delay: .05s; }
.card:nth-child(2) { animation-delay: .1s; }
.card:nth-child(3) { animation-delay: .15s; }
.card:nth-child(4) { animation-delay: .2s; }

.card:hover { transform: translateY(-4px); }

/* Glimmer streak on hover */
.card::after {
  content: '';
  position: absolute; top: 0; left: -100%;
  width: 60%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.04), transparent);
  transition: left .5s;
  pointer-events: none;
}
.card:hover::after { left: 140%; }

/* Glow orb top-right */
.card-orb {
  position: absolute; top: -30px; right: -30px;
  width: 130px; height: 130px; border-radius: 50%;
  filter: blur(50px); pointer-events: none; opacity: 0;
  transition: opacity .3s;
}
.card:hover .card-orb { opacity: 1; }

.card-doc:hover { border-color: rgba(0,198,167,.25); box-shadow: 0 20px 60px rgba(0,198,167,.1); }
.card-doc .card-orb { background: var(--plasma); }
.card-nur:hover { border-color: rgba(77,126,255,.25); box-shadow: 0 20px 60px rgba(77,126,255,.1); }
.card-nur .card-orb { background: var(--nova); }
.card-pen:hover { border-color: rgba(255,181,71,.25); box-shadow: 0 20px 60px rgba(255,181,71,.1); }
.card-pen .card-orb { background: var(--solar); }
.card-lv:hover  { border-color: rgba(255,107,107,.25); box-shadow: 0 20px 60px rgba(255,107,107,.1); }
.card-lv .card-orb  { background: var(--pulse); }

.card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; }
.card-ico {
  width: 46px; height: 46px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}
.card-doc .card-ico { background: rgba(0,198,167,.1); color: var(--plasma); box-shadow: 0 0 20px rgba(0,198,167,.15); }
.card-nur .card-ico { background: rgba(77,126,255,.1); color: var(--nova2); box-shadow: 0 0 20px rgba(77,126,255,.15); }
.card-pen .card-ico { background: rgba(255,181,71,.1); color: var(--solar); box-shadow: 0 0 20px rgba(255,181,71,.15); }
.card-lv  .card-ico { background: rgba(255,107,107,.1); color: var(--pulse); box-shadow: 0 0 20px rgba(255,107,107,.15); }

.card-trend {
  font-size: 10px; font-weight: 700; padding: 4px 9px; border-radius: 100px;
  display: flex; align-items: center; gap: 4px;
}
.trend-up   { background: rgba(0,198,167,.12); color: var(--plasma); }
.trend-warn { background: rgba(255,181,71,.12); color: var(--solar); }
.trend-hot  { background: rgba(255,107,107,.12); color: var(--pulse); }

.card-value {
  font-family: var(--ff-disp); font-size: 48px; font-weight: 800;
  line-height: 1; margin-bottom: 6px; letter-spacing: -2px;
}
.card-doc .card-value { color: var(--plasma); text-shadow: 0 0 30px rgba(0,198,167,.4); }
.card-nur .card-value { color: var(--nova2); text-shadow: 0 0 30px rgba(77,126,255,.4); }
.card-pen .card-value { color: var(--solar); text-shadow: 0 0 30px rgba(255,181,71,.4); }
.card-lv  .card-value { color: var(--pulse); text-shadow: 0 0 30px rgba(255,107,107,.4); }

.card-label { font-size: 12px; font-weight: 600; color: var(--txt-0); margin-bottom: 3px; }
.card-sub   { font-size: 11px; color: var(--txt-2); }

/* Mini sparkline bar */
.card-spark { display: flex; align-items: flex-end; gap: 2px; height: 24px; margin-top: 14px; }
.spark-bar {
  flex: 1; border-radius: 3px 3px 0 0;
  transition: height .4s var(--ease-expo), opacity .3s;
  opacity: .35;
}
.card:hover .spark-bar { opacity: .65; }
.card-doc .spark-bar { background: var(--plasma); }
.card-nur .spark-bar { background: var(--nova); }
.card-pen .spark-bar { background: var(--solar); }
.card-lv  .spark-bar { background: var(--pulse); }

/* ═══════════════════════════════════════════════
   QUICK ACTIONS
═══════════════════════════════════════════════ */
.quick-actions {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 14px; margin-bottom: 22px;
}
.qa-btn {
  display: flex; align-items: center; gap: 14px;
  padding: 18px 20px; border-radius: var(--r-lg);
  border: 1px solid rgba(255,255,255,.07);
  background: rgba(15,24,40,.7);
  text-decoration: none; color: var(--txt-0);
  position: relative; overflow: hidden;
  transition: all .25s var(--ease-expo);
  animation: cardIn .5s var(--ease-expo) both;
}
.qa-btn:nth-child(1) { animation-delay: .25s; }
.qa-btn:nth-child(2) { animation-delay: .3s; }
.qa-btn:nth-child(3) { animation-delay: .35s; }

.qa-btn::before {
  content: '';
  position: absolute; inset: 0;
  opacity: 0; transition: opacity .25s;
  border-radius: var(--r-lg);
}
.qa-shift::before  { background: linear-gradient(135deg, rgba(0,198,167,.08), transparent); }
.qa-leave::before  { background: linear-gradient(135deg, rgba(255,107,107,.08), transparent); }
.qa-appt::before   { background: linear-gradient(135deg, rgba(77,126,255,.08), transparent); }
.qa-btn:hover::before { opacity: 1; }
.qa-btn:hover { transform: translateY(-3px); }
.qa-shift:hover  { border-color: rgba(0,198,167,.25);  box-shadow: 0 16px 40px rgba(0,198,167,.08); }
.qa-leave:hover  { border-color: rgba(255,107,107,.25); box-shadow: 0 16px 40px rgba(255,107,107,.08); }
.qa-appt:hover   { border-color: rgba(77,126,255,.25);  box-shadow: 0 16px 40px rgba(77,126,255,.08); }

.qa-ico {
  width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: 18px;
  position: relative; z-index: 1;
}
.qa-shift .qa-ico  { background: rgba(0,198,167,.1); color: var(--plasma); }
.qa-leave .qa-ico  { background: rgba(255,107,107,.1); color: var(--pulse); }
.qa-appt  .qa-ico  { background: rgba(77,126,255,.1); color: var(--nova2); }

.qa-body { flex: 1; position: relative; z-index: 1; }
.qa-title { font-family: var(--ff-disp); font-size: 13px; font-weight: 700; color: var(--txt-0); margin-bottom: 3px; }
.qa-desc  { font-size: 11px; color: var(--txt-1); }
.qa-badge {
  margin-left: auto; flex-shrink: 0;
  background: var(--pulse); color: #fff;
  font-size: 9px; font-weight: 800; padding: 3px 9px; border-radius: 100px;
  position: relative; z-index: 1;
  animation: pDot 1.4s ease-in-out infinite;
}
.qa-arrow {
  width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,.05); color: var(--txt-2); font-size: 11px;
  position: relative; z-index: 1;
  transition: all .2s;
}
.qa-btn:hover .qa-arrow { color: var(--txt-0); background: rgba(255,255,255,.09); transform: translateX(3px); }

/* ═══════════════════════════════════════════════
   CHARTS ROW
═══════════════════════════════════════════════ */
.charts-row {
  display: grid;
  grid-template-columns: 1fr 1.6fr 1fr;
  gap: 14px; margin-bottom: 22px;
}
.panel {
  background: rgba(15,24,40,.7);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r-lg);
  padding: 20px;
  position: relative; overflow: hidden;
  animation: cardIn .5s var(--ease-expo) .4s both;
}
.panel::before {
  content: '';
  position: absolute; top: -80px; right: -80px;
  width: 200px; height: 200px; border-radius: 50%;
  opacity: .04; pointer-events: none;
}
.panel:nth-child(1)::before { background: var(--plasma); }
.panel:nth-child(2)::before { background: var(--nova); }
.panel:nth-child(3)::before { background: var(--violet); }

.panel-hd {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 18px;
}
.panel-hd-left { display: flex; align-items: center; gap: 9px; }
.panel-title { font-family: var(--ff-disp); font-size: 13px; font-weight: 700; color: var(--txt-0); }
.panel-sub   { font-size: 11px; color: var(--txt-2); margin-top: 1px; }

.pdot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.pdot-g { background: var(--plasma); box-shadow: 0 0 10px rgba(0,198,167,.7); }
.pdot-b { background: var(--nova);   box-shadow: 0 0 10px rgba(77,126,255,.7); }
.pdot-v { background: var(--violet); box-shadow: 0 0 10px rgba(155,109,255,.7); }

.panel-tag {
  font-size: 9px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
  padding: 3px 9px; border-radius: 100px;
  background: rgba(255,255,255,.06); color: var(--txt-2);
}

/* Donut legend */
.donut-legend {
  display: flex; flex-direction: column; gap: 10px; margin-top: 18px;
}
.dl-item {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 12px;
}
.dl-label { display: flex; align-items: center; gap: 8px; color: var(--txt-1); }
.dl-dot { width: 8px; height: 8px; border-radius: 50%; }
.dl-val { font-family: var(--ff-disp); font-weight: 700; color: var(--txt-0); }

/* Status bars */
.status-bars { display: flex; flex-direction: column; gap: 14px; margin-top: 4px; }
.sb-row { }
.sb-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.sb-name { font-size: 12px; color: var(--txt-1); }
.sb-val  { font-family: var(--ff-disp); font-size: 13px; font-weight: 700; color: var(--txt-0); }
.sb-track {
  height: 5px; background: rgba(255,255,255,.06);
  border-radius: 100px; overflow: hidden;
}
.sb-fill {
  height: 100%; border-radius: 100px;
  animation: barFill .8s var(--ease-expo) .5s both;
}
@keyframes barFill { from { width: 0 !important; } }
.sb-active  .sb-fill { background: linear-gradient(90deg, var(--plasma), var(--plasma2)); }
.sb-pending .sb-fill { background: linear-gradient(90deg, var(--solar), #ffcc80); }
.sb-reject  .sb-fill { background: linear-gradient(90deg, var(--pulse), #ff9a9a); }

/* ═══════════════════════════════════════════════
   PENDING TABLE
═══════════════════════════════════════════════ */
.table-wrap {
  background: rgba(15,24,40,.7);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r-lg);
  overflow: hidden;
  animation: cardIn .5s var(--ease-expo) .5s both;
}
.table-head {
  padding: 18px 24px;
  border-bottom: 1px solid rgba(255,255,255,.06);
  display: flex; justify-content: space-between; align-items: center;
  background: rgba(0,0,0,.15);
}
.table-head h3 { font-family: var(--ff-disp); font-size: 15px; font-weight: 700; }
.tbl-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,181,71,.1); border: 1px solid rgba(255,181,71,.2);
  color: var(--solar); font-size: 11px; font-weight: 700;
  padding: 5px 12px; border-radius: 100px;
}
.anim-pulse {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--solar);
  animation: pDot 1.2s ease-in-out infinite;
  display: inline-block;
}

table { width: 100%; border-collapse: collapse; }
th {
  padding: 12px 24px; text-align: left;
  font-size: 9.5px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
  color: var(--txt-3);
  background: rgba(0,0,0,.1);
  border-bottom: 1px solid rgba(255,255,255,.05);
}
td {
  padding: 14px 24px;
  border-bottom: 1px solid rgba(255,255,255,.04);
  font-size: 13px; color: var(--txt-1); vertical-align: middle;
}
tr:last-child td { border-bottom: none; }
tr { transition: background .15s; }
tr:hover td { background: rgba(255,255,255,.02); }
td strong { color: var(--txt-0); font-weight: 600; }

.row-av-w  { display: flex; align-items: center; gap: 12px; }
.row-av {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 800; font-family: var(--ff-disp); flex-shrink: 0;
}
.rav-doc { background: rgba(0,198,167,.12); color: var(--plasma); border: 1px solid rgba(0,198,167,.22); }
.rav-nur { background: rgba(77,126,255,.12); color: var(--nova2); border: 1px solid rgba(77,126,255,.22); }

.badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 11px; border-radius: 100px;
  font-size: 11px; font-weight: 700; letter-spacing: .3px;
}
.badge-doc { background: rgba(0,198,167,.1); color: var(--plasma); border: 1px solid rgba(0,198,167,.2); }
.badge-nur { background: rgba(77,126,255,.1); color: var(--nova2); border: 1px solid rgba(77,126,255,.2); }

.actions { display: flex; gap: 8px; }
.actions form { margin: 0; }

.btn-approve, .btn-reject {
  display: inline-flex; align-items: center; gap: 6px;
  border: none; cursor: pointer; border-radius: var(--r-sm);
  padding: 8px 16px; font-size: 12px; font-weight: 700;
  font-family: var(--ff-body); letter-spacing: .2px;
  transition: all .2s var(--ease-back);
}
.btn-approve {
  background: rgba(0,198,167,.1); color: var(--plasma);
  border: 1px solid rgba(0,198,167,.22);
}
.btn-approve:hover {
  background: rgba(0,198,167,.22);
  box-shadow: 0 0 20px rgba(0,198,167,.25);
  transform: translateY(-2px) scale(1.02);
}
.btn-reject {
  background: rgba(255,107,107,.1); color: var(--pulse);
  border: 1px solid rgba(255,107,107,.22);
}
.btn-reject:hover {
  background: rgba(255,107,107,.22);
  box-shadow: 0 0 20px rgba(255,107,107,.25);
  transform: translateY(-2px) scale(1.02);
}

.empty {
  padding: 60px 40px; text-align: center; color: var(--txt-2);
}
.empty-ico {
  width: 64px; height: 64px; border-radius: 50%;
  background: rgba(0,198,167,.07); border: 1px solid rgba(0,198,167,.18);
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; color: var(--plasma); margin: 0 auto 16px;
  box-shadow: 0 0 30px rgba(0,198,167,.1);
}
.empty h3 { font-family: var(--ff-disp); font-size: 16px; font-weight: 700; color: var(--txt-1); margin-bottom: 6px; }
.empty p  { font-size: 12px; }

/* ── Scrollbar ───────────────────────── */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,198,167,.2); border-radius: 99px; }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════
     SIDEBAR
═══════════════════════════════════════════════ -->
<div class="sidebar">

  <div class="logo">
    <div class="logo-mark"><i class="fa-solid fa-hospital-user"></i></div>
    <div class="logo-txt">
      <strong>Zaman Medical</strong>
      <span>Command Centre</span>
    </div>
  </div>

  <!-- Profile Card -->
  <div class="sp-card">
    <div class="av-ring" onclick="document.getElementById('profileFileInput').click();" title="Change photo">
      <img src="<?= $imgSrc ?>" alt="Avatar" id="avatarPreview">
      <div class="av-overlay"><i class="fa-solid fa-camera"></i>Change</div>
    </div>
    <div class="sp-name"><?= htmlspecialchars($full_name) ?></div>
    <div class="sp-role"><i class="fa-solid fa-user-shield" style="font-size:9px"></i> Dept Head</div>
    <div class="sp-status"><div class="pulse-dot"></div> Online &amp; Active</div>
    <form method="POST" enctype="multipart/form-data" id="uploadForm" style="width:100%">
      <input type="file" name="profile_image" id="profileFileInput" accept="image/*" onchange="previewAndSubmit(this)">
      <button type="submit" name="upload_image" class="btn-upload">
        <i class="fa-solid fa-arrow-up-from-bracket"></i> Upload Photo
      </button>
    </form>
  </div>

  <div class="nav">
    <span class="nav-label">Overview</span>
    <a class="active" href="medicaldashboard.php">
      <div class="ni"><i class="fa-solid fa-table-columns"></i></div>
      Dashboard
    </a>

    <span class="nav-label">Staff</span>
    <a href="medicaldepartment_staff_management.php">
      <div class="ni"><i class="fa-solid fa-users"></i></div>
      Staff Management
    </a>
    <a href="medicaldepartment_pending_approvals.php">
      <div class="ni"><i class="fa-solid fa-hourglass-half"></i></div>
      Pending Approvals
      <?php if($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
    </a>

    <span class="nav-label">Scheduling</span>
    <a href="assign_doctor_shifts.php">
      <div class="ni"><i class="fa-solid fa-calendar-plus"></i></div>
      Assign Shifts
    </a>
    <a href="approve_leaves.php">
      <div class="ni"><i class="fa-solid fa-calendar-xmark"></i></div>
      Leave Requests
      <?php if($pendingLeaves > 0): ?><span class="nav-badge red"><?= $pendingLeaves ?></span><?php endif; ?>
    </a>

    <span class="nav-label">Operations</span>
    <a href="medicaldepartment_appointments.php">
      <div class="ni"><i class="fa-solid fa-calendar-check"></i></div>
      Appointments
    </a>
    <a href="medicaldepartment_reports.php">
      <div class="ni"><i class="fa-solid fa-chart-line"></i></div>
      Reports &amp; Analytics
    </a>
    <a href="medicaldepartment_departments.php">
      <div class="ni"><i class="fa-solid fa-building-columns"></i></div>
      Departments
    </a>

    <div class="nav-divider"></div>
    <span class="nav-label">Account</span>
    <a href="medicaldepartment_profile.php">
      <div class="ni"><i class="fa-solid fa-circle-user"></i></div>
      My Profile
    </a>
    <a href="http://localhost/hospital/logout.php" class="nav-danger">
      <div class="ni"><i class="fa-solid fa-right-from-bracket"></i></div>
      Sign Out
    </a>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════ -->
<div class="main">

  <!-- ── Top Bar ───────────────────────────────── -->
  <div class="topbar">
    <div>
      <div class="greeting-line">
        <?= $greeting ?>, <?= $firstName ?> &nbsp;<span style="font-size:26px">👋</span>
      </div>
      <div class="greeting-sub">
        <i class="fa-regular fa-calendar" style="color:var(--txt-2);font-size:11px"></i>
        <?= date("l, d F Y") ?>
        <div class="breadcrumb-dot"></div>
        Medical Department
        <div class="breadcrumb-dot"></div>
        <span style="color:var(--plasma)">Live Overview</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="notif-btn">
        <i class="fa-regular fa-bell"></i>
        <?php if($pendingLeaves + $pendingCount > 0): ?><div class="notif-pip"></div><?php endif; ?>
      </div>
      <div class="clock-card">
        <div class="clock-time" id="liveClock"></div>
        <div class="clock-label">Live Time · PKT</div>
      </div>
      <img src="<?= $imgSrc ?>" class="topbar-av" id="topbarAv" alt="Profile">
    </div>
  </div>

  <!-- ── Stat Cards ────────────────────────────── -->
  <div class="cards">

    <!-- Doctors -->
    <div class="card card-doc">
      <div class="card-orb"></div>
      <div class="card-top">
        <div class="card-ico"><i class="fa-solid fa-user-doctor"></i></div>
        <div class="card-trend trend-up"><i class="fa-solid fa-arrow-trend-up"></i> Active</div>
      </div>
      <div class="card-label">Total Doctors</div>
      <div class="card-value" id="doctorCount"><?= $totalDoctors ?></div>
      <div class="card-sub">Active physicians on staff</div>
      <div class="card-spark" id="sparkDoc">
        <?php for($i=0;$i<7;$i++): $h=rand(30,100); ?><div class="spark-bar" style="height:<?=$h?>%"></div><?php endfor; ?>
      </div>
    </div>

    <!-- Nurses -->
    <div class="card card-nur">
      <div class="card-orb"></div>
      <div class="card-top">
        <div class="card-ico"><i class="fa-solid fa-user-nurse"></i></div>
        <div class="card-trend trend-up"><i class="fa-solid fa-arrow-trend-up"></i> Active</div>
      </div>
      <div class="card-label">Total Nurses</div>
      <div class="card-value" id="nurseCount"><?= $totalNurses ?></div>
      <div class="card-sub">Registered nursing staff</div>
      <div class="card-spark">
        <?php for($i=0;$i<7;$i++): $h=rand(25,95); ?><div class="spark-bar" style="height:<?=$h?>%"></div><?php endfor; ?>
      </div>
    </div>

    <!-- Pending -->
    <div class="card card-pen">
      <div class="card-orb"></div>
      <div class="card-top">
        <div class="card-ico"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="card-trend trend-warn"><i class="fa-solid fa-clock"></i> Review</div>
      </div>
      <div class="card-label">Pending Approvals</div>
      <div class="card-value" id="pendingCount"><?= $pendingCount ?></div>
      <div class="card-sub">Awaiting your action</div>
      <div class="card-spark">
        <?php for($i=0;$i<7;$i++): $h=rand(20,80); ?><div class="spark-bar" style="height:<?=$h?>%"></div><?php endfor; ?>
      </div>
    </div>

    <!-- Leave Requests -->
    <div class="card card-lv" style="cursor:pointer" onclick="location.href='approve_leaves.php'">
      <div class="card-orb"></div>
      <div class="card-top">
        <div class="card-ico"><i class="fa-solid fa-calendar-xmark"></i></div>
        <div class="card-trend trend-hot"><i class="fa-solid fa-triangle-exclamation"></i> Urgent</div>
      </div>
      <div class="card-label">Leave Requests</div>
      <div class="card-value"><?= $pendingLeaves ?></div>
      <div class="card-sub">Pending doctor leaves</div>
      <div class="card-spark">
        <?php for($i=0;$i<7;$i++): $h=rand(15,70); ?><div class="spark-bar" style="height:<?=$h?>%"></div><?php endfor; ?>
      </div>
    </div>

  </div>

  <!-- ── Quick Actions ─────────────────────────── -->
  <div class="quick-actions">
    <a href="assign_doctor_shifts.php" class="qa-btn qa-shift">
      <div class="qa-ico"><i class="fa-solid fa-calendar-plus"></i></div>
      <div class="qa-body">
        <div class="qa-title">Assign Doctor Shifts</div>
        <div class="qa-desc">Set weekly schedule for doctors</div>
      </div>
      <div class="qa-arrow"><i class="fa-solid fa-arrow-right"></i></div>
    </a>
    <a href="approve_leaves.php" class="qa-btn qa-leave">
      <div class="qa-ico"><i class="fa-solid fa-calendar-xmark"></i></div>
      <div class="qa-body">
        <div class="qa-title">Approve Leave Requests</div>
        <div class="qa-desc">Review pending doctor leaves</div>
      </div>
      <?php if($pendingLeaves > 0): ?><span class="qa-badge"><?= $pendingLeaves ?> pending</span><?php endif; ?>
      <div class="qa-arrow"><i class="fa-solid fa-arrow-right"></i></div>
    </a>
    <a href="medicaldepartment_appointments.php" class="qa-btn qa-appt">
      <div class="qa-ico"><i class="fa-solid fa-calendar-check"></i></div>
      <div class="qa-body">
        <div class="qa-title">View Appointments</div>
        <div class="qa-desc">Today's doctor appointments</div>
      </div>
      <div class="qa-arrow"><i class="fa-solid fa-arrow-right"></i></div>
    </a>
  </div>

  <!-- ── Charts Row ────────────────────────────── -->
  <div class="charts-row">

    <!-- Donut — Staff distribution -->
    <div class="panel">
      <div class="panel-hd">
        <div class="panel-hd-left">
          <div class="pdot pdot-g"></div>
          <div>
            <div class="panel-title">Staff Mix</div>
            <div class="panel-sub">Doctors vs Nurses</div>
          </div>
        </div>
        <span class="panel-tag">Live</span>
      </div>
      <canvas id="pieChart" height="160"></canvas>
      <div class="donut-legend">
        <div class="dl-item">
          <div class="dl-label"><div class="dl-dot" style="background:var(--plasma)"></div> Doctors</div>
          <div class="dl-val" id="leg-doc"><?= $totalDoctors ?></div>
        </div>
        <div class="dl-item">
          <div class="dl-label"><div class="dl-dot" style="background:var(--nova)"></div> Nurses</div>
          <div class="dl-val" id="leg-nur"><?= $totalNurses ?></div>
        </div>
      </div>
    </div>

    <!-- Bar — Monthly registrations -->
    <div class="panel">
      <div class="panel-hd">
        <div class="panel-hd-left">
          <div class="pdot pdot-b"></div>
          <div>
            <div class="panel-title">Monthly Registrations</div>
            <div class="panel-sub">Staff growth over time</div>
          </div>
        </div>
        <span class="panel-tag">This Year</span>
      </div>
      <canvas id="barChart" height="160"></canvas>
    </div>

    <!-- Status bars -->
    <div class="panel">
      <div class="panel-hd">
        <div class="panel-hd-left">
          <div class="pdot pdot-v"></div>
          <div>
            <div class="panel-title">Staff Status</div>
            <div class="panel-sub">Breakdown by state</div>
          </div>
        </div>
        <span class="panel-tag">Live</span>
      </div>
      <div class="status-bars" id="statusBars">
        <!-- Filled by JS -->
        <div class="sb-row sb-active">
          <div class="sb-head">
            <span class="sb-name">Active</span>
            <span class="sb-val" id="sb-active">—</span>
          </div>
          <div class="sb-track"><div class="sb-fill" id="fill-active" style="width:0%"></div></div>
        </div>
        <div class="sb-row sb-pending">
          <div class="sb-head">
            <span class="sb-name">Pending</span>
            <span class="sb-val" id="sb-pending">—</span>
          </div>
          <div class="sb-track"><div class="sb-fill" id="fill-pending" style="width:0%"></div></div>
        </div>
        <div class="sb-row sb-reject">
          <div class="sb-head">
            <span class="sb-name">Rejected</span>
            <span class="sb-val" id="sb-reject">—</span>
          </div>
          <div class="sb-track"><div class="sb-fill" id="fill-reject" style="width:0%"></div></div>
        </div>
      </div>

      <!-- Mini line chart for status trends -->
      <canvas id="lineChart" height="100" style="margin-top:20px"></canvas>
    </div>

  </div>

  <!-- ── Pending Approvals Table ───────────────── -->
  <div class="table-wrap">
    <div class="table-head">
      <h3>Pending Staff Approvals</h3>
      <span class="tbl-badge" id="pendingBadge">
        <span class="anim-pulse"></span>
        <?= $pendingCount ?> Awaiting Review
      </span>
    </div>

    <?php if ($pendingResult && $pendingResult->num_rows > 0): ?>
      <table>
        <thead>
          <tr>
            <th>Staff Member</th>
            <th>Role</th>
            <th>ID</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $pendingResult->fetch_assoc()):
            $init  = strtoupper(substr($row['full_name'],0,1));
            $isDoc = $row['role'] === 'doctor';
          ?>
          <tr>
            <td>
              <div class="row-av-w">
                <div class="row-av <?= $isDoc?'rav-doc':'rav-nur' ?>"><?= $init ?></div>
                <strong><?= htmlspecialchars($row['full_name']) ?></strong>
              </div>
            </td>
            <td>
              <span class="badge <?= $isDoc?'badge-doc':'badge-nur' ?>">
                <i class="fa-solid <?= $isDoc?'fa-user-doctor':'fa-user-nurse' ?>" style="font-size:9px"></i>
                <?= ucfirst($row['role']) ?>
              </span>
            </td>
            <td><span style="font-family:var(--ff-disp);color:var(--txt-2);font-size:12px">#<?= str_pad($row['user_id'],4,'0',STR_PAD_LEFT) ?></span></td>
            <td>
              <div class="actions">
                <form method="POST">
                  <input type="hidden" name="approve_id" value="<?= $row['user_id'] ?>">
                  <button class="btn-approve" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="reject_id" value="<?= $row['user_id'] ?>">
                  <button class="btn-reject" type="submit"><i class="fa-solid fa-xmark"></i> Reject</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty">
        <div class="empty-ico"><i class="fa-solid fa-circle-check"></i></div>
        <h3>All Caught Up</h3>
        <p>No pending requests — every approval has been processed.</p>
      </div>
    <?php endif; ?>
  </div>

</div><!-- /main -->

<!-- ═══════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════ -->
<script>
/* Clock */
function tick(){
  document.getElementById('liveClock').textContent =
    new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(tick,1000); tick();

/* Avatar preview */
function previewAndSubmit(input){
  if(!input.files[0]) return;
  const url = URL.createObjectURL(input.files[0]);
  document.getElementById('avatarPreview').src = url;
  document.getElementById('topbarAv').src = url;
  document.getElementById('uploadForm').submit();
}

/* Chart defaults */
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = '#4A6280';

/* ── Donut ─────────────────────────────────── */
const pieCtx = document.getElementById('pieChart');
const pieChart = new Chart(pieCtx, {
  type: 'doughnut',
  data: {
    labels: ['Doctors','Nurses'],
    datasets: [{
      data: [0,0],
      backgroundColor: ['#00C6A7','#4D7EFF'],
      borderWidth: 0,
      hoverOffset: 12,
      hoverBackgroundColor: ['#00E5C3','#7BA3FF'],
    }]
  },
  options: {
    responsive: true, cutout: '74%',
    plugins: { legend: { display: false }, tooltip: {
      backgroundColor: 'rgba(8,13,26,.95)',
      borderColor: 'rgba(255,255,255,.08)', borderWidth: 1,
      titleColor: '#F0F6FF', bodyColor: '#8BA3C2',
      padding: 12, cornerRadius: 10,
    }},
    animation: { animateRotate: true, duration: 1000 }
  }
});

/* ── Bar ───────────────────────────────────── */
const barCtx = document.getElementById('barChart');
const barChart = new Chart(barCtx, {
  type: 'bar',
  data: {
    labels: [],
    datasets: [{
      label: 'Registrations',
      data: [],
      backgroundColor: (ctx) => {
        const g = ctx.chart.ctx.createLinearGradient(0,0,0,200);
        g.addColorStop(0,'rgba(77,126,255,.85)');
        g.addColorStop(1,'rgba(0,198,167,.4)');
        return g;
      },
      borderRadius: 8, borderSkipped: false,
      hoverBackgroundColor: 'rgba(0,198,167,.9)',
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false }, tooltip: {
      backgroundColor: 'rgba(8,13,26,.95)',
      borderColor: 'rgba(255,255,255,.08)', borderWidth: 1,
      titleColor: '#F0F6FF', bodyColor: '#8BA3C2',
      padding: 12, cornerRadius: 10,
    }},
    scales: {
      x: { grid: { display: false }, border: { display: false },
           ticks: { font: { size: 10 } } },
      y: { beginAtZero: true, ticks: { precision:0, font:{ size:10 } },
           grid: { color:'rgba(255,255,255,.04)' }, border:{ display:false } }
    },
    animation: { duration: 900 }
  }
});

/* ── Line ──────────────────────────────────── */
const lineCtx = document.getElementById('lineChart');
const lineChart = new Chart(lineCtx, {
  type: 'line',
  data: {
    labels: ['Active','Pending','Rejected'],
    datasets: [{
      data: [0,0,0],
      borderColor: '#9B6DFF',
      backgroundColor: (ctx) => {
        const g = ctx.chart.ctx.createLinearGradient(0,0,0,120);
        g.addColorStop(0,'rgba(155,109,255,.25)');
        g.addColorStop(1,'rgba(155,109,255,.0)');
        return g;
      },
      fill: true, tension: .45,
      pointRadius: 5, pointBackgroundColor: '#9B6DFF',
      pointBorderColor: 'rgba(8,13,26,1)', pointBorderWidth: 2,
      pointHoverRadius: 7,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false }, tooltip: {
      backgroundColor: 'rgba(8,13,26,.95)',
      borderColor: 'rgba(255,255,255,.08)', borderWidth: 1,
      titleColor: '#F0F6FF', bodyColor: '#8BA3C2',
      padding: 12, cornerRadius: 10,
    }},
    scales: {
      y: { beginAtZero:true, ticks:{ precision:0, font:{size:10} },
           grid:{ color:'rgba(255,255,255,.04)' }, border:{ display:false } },
      x: { grid:{ display:false }, border:{ display:false }, ticks:{ font:{size:10} } }
    },
    animation: { duration: 900 }
  }
});

/* ── Data fetch & update ───────────────────── */
function loadData(){
  fetch('medical_chart_data.php')
    .then(r => r.json())
    .then(d => {
      if(d.error){ console.warn(d.message); return; }

      /* Cards */
      document.getElementById('doctorCount').textContent  = d.doctors;
      document.getElementById('nurseCount').textContent   = d.nurses;
      document.getElementById('pendingCount').textContent = d.pendingCount;
      document.getElementById('pendingBadge').innerHTML   =
        '<span class="anim-pulse"></span> ' + d.pendingCount + ' Awaiting Review';

      /* Legend */
      if(document.getElementById('leg-doc')) document.getElementById('leg-doc').textContent = d.doctors;
      if(document.getElementById('leg-nur')) document.getElementById('leg-nur').textContent = d.nurses;

      /* Donut */
      pieChart.data.datasets[0].data = [d.doctors, d.nurses];
      pieChart.update();

      /* Bar */
      barChart.data.labels = d.months;
      barChart.data.datasets[0].data = d.monthly;
      barChart.update();

      /* Line */
      lineChart.data.datasets[0].data = [d.active, d.pending, d.rejected];
      lineChart.update();

      /* Status bars */
      const total = (d.active||0) + (d.pending||0) + (d.rejected||0) || 1;
      document.getElementById('sb-active').textContent  = d.active;
      document.getElementById('sb-pending').textContent = d.pending;
      document.getElementById('sb-reject').textContent  = d.rejected;
      document.getElementById('fill-active').style.width  = ((d.active/total)*100).toFixed(1)+'%';
      document.getElementById('fill-pending').style.width = ((d.pending/total)*100).toFixed(1)+'%';
      document.getElementById('fill-reject').style.width  = ((d.rejected/total)*100).toFixed(1)+'%';
    })
    .catch(e => console.log(e));
}
loadData();
setInterval(loadData, 3000);
</script>
</body>
</html>