<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------------- AUTH ---------------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

/* ---------------- DB ---------------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

$user_id = (int)$_SESSION['user_id'];

/* ---------------- DOCTOR INFO ---------------- */
$stmt = $conn->prepare("
    SELECT d.doctor_id, u.full_name, d.specialty, d.contact,
           d.qualifications, d.experience, d.profile_picture
    FROM doctors d
    JOIN users u ON u.user_id = d.user_id
    WHERE d.user_id = ? LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$details = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$details) {
    $details = ['doctor_id'=>0,'full_name'=>'Doctor','specialty'=>'',
                'contact'=>'','qualifications'=>'','experience'=>'','profile_picture'=>'default.png'];
}
$doctor_id = (int)$details['doctor_id'];

/* ---------------- GREETING ---------------- */
date_default_timezone_set("Asia/Karachi");
$hour = (int)date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");

/* ---------------- STATS ---------------- */
$stats = $conn->query("
    SELECT
        COUNT(*) AS total_appts,
        COUNT(DISTINCT patient_id) AS total_patients,
        SUM(DATE(appointment_date) = CURDATE()) AS today_count,
        SUM(LOWER(status) = 'scheduled') AS pending_count
    FROM appointments
    WHERE doctor_id = $doctor_id
")->fetch_assoc();

$appointments_count = (int)($stats['total_appts'] ?? 0);
$patients_count     = (int)($stats['total_patients'] ?? 0);
$today_count        = (int)($stats['today_count'] ?? 0);
$pending_count      = (int)($stats['pending_count'] ?? 0);

/* ---------------- PENDING LEAVE COUNT ---------------- */
$pending_leaves = 0;
$lv = $conn->query("SELECT COUNT(*) FROM doctor_leaves WHERE doctor_id=$doctor_id AND status='pending'");
if ($lv) $pending_leaves = (int)$lv->fetch_row()[0];

/* ---------------- WEEKLY DATA ---------------- */
$weekly_data   = array_fill(0, 7, 0);
$weekly_labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$res = $conn->query("SELECT DAYOFWEEK(appointment_date) AS d, COUNT(*) AS t FROM appointments WHERE doctor_id=$doctor_id AND YEARWEEK(appointment_date,1)=YEARWEEK(CURDATE(),1) GROUP BY d");
while ($r = $res->fetch_assoc()) {
    $day = (int)$r['d'];
    $idx = ($day === 1) ? 6 : $day - 2;
    if ($idx >= 0 && $idx < 7) $weekly_data[$idx] = (int)$r['t'];
}

/* ---------------- STATUS ---------------- */
$status_labels = ['Scheduled','Completed','Cancelled'];
$status_counts = [0,0,0];
$sr = $conn->query("SELECT LOWER(status) AS s, COUNT(*) AS c FROM appointments WHERE doctor_id=$doctor_id AND LOWER(status) IN ('scheduled','completed','cancelled') GROUP BY LOWER(status)");
while ($r = $sr->fetch_assoc()) {
    $map = ['scheduled'=>0,'completed'=>1,'cancelled'=>2];
    if (isset($map[$r['s']])) $status_counts[$map[$r['s']]] = (int)$r['c'];
}

/* ---------------- PHARMACISTS ---------------- */
$pharmacists = $conn->query("SELECT user_id, full_name FROM users WHERE role='pharmacist'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Dashboard — Zaman Medical</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
:root{
    --b1:#0a0f2a;--b2:#11163d;--b3:#1a237e;--b4:#283593;--b5:#3949ab;
    --b6:#5c6bc0;--b7:#7986cb;--b8:#9fa8da;--b9:#c5cae9;
    --acc:#7e57c2;--acc2:#b39ddb;--acc3:#9575cd;
    --glow:rgba(57,73,171,.6);--glass:rgba(255,255,255,.045);
    --gb:rgba(255,255,255,.09);--gh:rgba(255,255,255,.07);
    --txt:#e8eaf6;--mut:#9fa8da;--sw:268px;--r:20px;
}
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Outfit',sans-serif;background:var(--b1);color:var(--txt);min-height:100vh;overflow-x:hidden;}
body::before{
    content:'';position:fixed;inset:0;
    background:
        radial-gradient(ellipse 90% 70% at 10% 10%,rgba(57,73,171,.28) 0%,transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%,rgba(126,87,194,.22) 0%,transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 45%,rgba(26,35,126,.5) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
body::after{
    content:'';position:fixed;inset:0;
    background-image:linear-gradient(rgba(255,255,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.018) 1px,transparent 1px);
    background-size:52px 52px;pointer-events:none;z-index:0;
}
.sidebar,.main{position:relative;z-index:1;}

/* SIDEBAR */
.sidebar{
    position:fixed;top:0;left:0;width:var(--sw);height:100vh;
    background:rgba(15,21,66,.82);backdrop-filter:blur(32px);-webkit-backdrop-filter:blur(32px);
    border-right:1px solid var(--gb);display:flex;flex-direction:column;
    overflow-y:auto;z-index:1000;scrollbar-width:none;
}
.sidebar::-webkit-scrollbar{display:none;}
.sb-logo{padding:26px 22px 20px;border-bottom:1px solid var(--gb);}
.sb-brand{font-family:'Instrument Serif',serif;font-size:18px;color:#fff;line-height:1.3;}
.sb-sub{font-size:9.5px;color:var(--acc);font-weight:700;text-transform:uppercase;letter-spacing:.13em;margin-top:5px;}
.sb-sec{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--mut);padding:16px 22px 6px;}
.sb-nav a{
    display:flex;align-items:center;gap:11px;
    padding:11px 14px 11px 22px;color:var(--mut);text-decoration:none;
    font-size:13.5px;font-weight:500;border-left:2px solid transparent;
    margin-right:10px;border-radius:0 10px 10px 0;transition:all .2s;
}
.sb-nav a i{width:17px;text-align:center;font-size:14px;}
.sb-nav a:hover{color:var(--b9);background:rgba(57,73,171,.2);border-left-color:var(--b6);}
.sb-nav a.act{color:#fff;background:linear-gradient(90deg,rgba(57,73,171,.45),rgba(126,87,194,.15));border-left-color:var(--acc);font-weight:600;}
.sb-nav a.act i{color:var(--acc);}
.sb-nav a .nb{margin-left:auto;background:var(--b5);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;box-shadow:0 0 14px var(--glow);}
.sb-nav a .nb.red{background:#ef4444;}
.sb-foot{padding:15px 22px;border-top:1px solid var(--gb);font-size:11px;color:var(--mut);display:flex;align-items:center;gap:7px;}
.ldot{width:7px;height:7px;background:var(--acc);border-radius:50%;box-shadow:0 0 10px var(--acc);animation:lp 1.8s infinite;}
@keyframes lp{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.7);opacity:.45;}}

/* MAIN */
.main{margin-left:var(--sw);padding:26px 28px 44px;min-height:100vh;}

/* TOPBAR */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;gap:14px;}
.tl{display:flex;align-items:center;gap:15px;}
.doc-av{width:54px;height:54px;border-radius:14px;object-fit:cover;border:2px solid var(--b5);box-shadow:0 0 0 4px rgba(57,73,171,.3),0 0 28px rgba(57,73,171,.4);}
.g-text{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.025em;line-height:1.2;}
.sp-pill{display:inline-flex;align-items:center;gap:5px;background:rgba(57,73,171,.25);border:1px solid rgba(57,73,171,.45);color:var(--b8);font-size:11px;font-weight:600;padding:3px 12px;border-radius:99px;margin-top:5px;}
.tr{display:flex;align-items:center;gap:10px;}
.btnt{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:12px;font-size:13px;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'Outfit',sans-serif;}
.btnt.lg{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.3);}
.btnt.lg:hover{background:rgba(239,68,68,.32);color:#fff;}
.btnt.pr{background:rgba(57,73,171,.3);color:var(--b8);border:1px solid rgba(57,73,171,.45);}
.btnt.pr:hover{background:rgba(57,73,171,.5);color:#fff;}

/* HERO */
.hero{background:linear-gradient(120deg,rgba(26,35,126,.88) 0%,rgba(57,73,171,.7) 100%);border:1px solid var(--gb);backdrop-filter:blur(22px);border-radius:var(--r);padding:26px 30px;display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:0 10px 50px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.06);}
.hero::before{content:'';position:absolute;top:-80px;right:-80px;width:320px;height:320px;background:radial-gradient(circle,rgba(57,73,171,.35) 0%,transparent 65%);border-radius:50%;pointer-events:none;}
.hero-ttl{font-family:'Instrument Serif',serif;font-size:23px;color:#fff;margin-bottom:6px;letter-spacing:-.02em;}
.hero-s{font-size:13px;color:var(--b9);}
.hero-time{margin-top:14px;display:flex;align-items:center;gap:14px;font-size:12px;color:var(--b8);font-weight:500;}
.ck{background:rgba(57,73,171,.3);border:1px solid rgba(57,73,171,.45);padding:5px 15px;border-radius:99px;font-weight:800;font-size:13px;color:#fff;letter-spacing:.04em;box-shadow:0 0 16px rgba(57,73,171,.4);}
.hico{display:flex;gap:12px;align-items:center;}
.hic{width:52px;height:52px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;font-size:19px;color:rgba(255,255,255,.28);}
.hic.lg{width:70px;height:70px;font-size:28px;border-color:rgba(126,87,194,.5);color:rgba(126,87,194,.55);box-shadow:0 0 32px rgba(126,87,194,.3);}

/* STAT CARDS */
.sc{background:rgba(255,255,255,.04);border:1px solid var(--gb);backdrop-filter:blur(18px);border-radius:var(--r);padding:20px;display:flex;align-items:center;gap:15px;transition:all .25s;position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;border-radius:var(--r) var(--r) 0 0;}
.sc.c1::before{background:linear-gradient(90deg,var(--b5),var(--b6));}
.sc.c2::before{background:linear-gradient(90deg,var(--acc),var(--b7));}
.sc.c3::before{background:linear-gradient(90deg,var(--b4),var(--b5));}
.sc.c4::before{background:linear-gradient(90deg,var(--acc2),var(--acc));}
.sc:hover{transform:translateY(-4px);background:var(--gh);box-shadow:0 22px 55px rgba(0,0,0,.35);}
.si{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;}
.si.b1{background:rgba(57,73,171,.3);color:var(--b7);}
.si.b2{background:rgba(126,87,194,.22);color:var(--acc2);}
.si.b3{background:rgba(92,107,192,.25);color:var(--b8);}
.si.b4{background:rgba(149,117,205,.2);color:var(--acc3);}
.sl{font-size:10.5px;color:var(--mut);font-weight:700;text-transform:uppercase;letter-spacing:.07em;}
.sv{font-size:30px;font-weight:800;color:#fff;line-height:1;margin-top:3px;letter-spacing:-.04em;}
.sd{font-size:11px;margin-top:4px;font-weight:600;color:#4ade80;}
.sd.n{color:var(--mut);}

/* GLASS CARD */
.gc{background:rgba(255,255,255,.035);border:1px solid var(--gb);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px);border-radius:var(--r);padding:22px;height:100%;position:relative;overflow:hidden;transition:box-shadow .25s;}
.gc::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.13),transparent);}
.gc:hover{box-shadow:0 26px 65px rgba(0,0,0,.4);}
.chr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;}
.ct{font-size:14.5px;font-weight:700;color:#fff;letter-spacing:-.01em;}
.cs{font-size:11px;color:var(--mut);margin-top:3px;}
.cbg{font-size:10.5px;font-weight:700;padding:3px 11px;border-radius:99px;background:rgba(57,73,171,.25);color:var(--b7);border:1px solid rgba(57,73,171,.4);white-space:nowrap;}

/* ACTION TILES */
.at{background:rgba(255,255,255,.04);border:1px solid var(--gb);backdrop-filter:blur(16px);border-radius:18px;padding:22px 14px;text-align:center;text-decoration:none;color:var(--txt);display:block;transition:all .25s;cursor:pointer;position:relative;overflow:hidden;}
.at::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;opacity:0;transition:opacity .25s;}
.at.t1::after{background:linear-gradient(90deg,var(--b5),var(--b6));}
.at.t2::after{background:linear-gradient(90deg,var(--acc),var(--acc2));}
.at.t3::after{background:linear-gradient(90deg,var(--b4),var(--b5));}
.at.t4::after{background:linear-gradient(90deg,var(--acc2),var(--acc3));}
.at:hover{transform:translateY(-5px);background:var(--gh);color:#fff;box-shadow:0 22px 55px rgba(0,0,0,.4);}
.at:hover::after{opacity:1;}
.ati{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px;margin:0 auto 11px;}
.ati.a1{background:rgba(57,73,171,.3);color:var(--b6);}
.ati.a2{background:rgba(126,87,194,.22);color:var(--acc2);}
.ati.a3{background:rgba(92,107,192,.25);color:var(--b7);}
.ati.a4{background:rgba(149,117,205,.25);color:var(--acc3);}
.atl{font-size:13px;font-weight:700;color:#fff;margin-bottom:3px;}
.ats{font-size:11px;color:var(--mut);}

/* MODAL */
.modal-content{background:rgba(10,15,42,.94);backdrop-filter:blur(32px);border:1px solid var(--gb);border-radius:22px;color:var(--txt);}
.modal-header{border-bottom:1px solid var(--gb);padding:20px 24px;}
.modal-body{padding:22px 24px;}
.modal-title{color:#fff;font-weight:700;}
.btn-close{filter:invert(1) brightness(.65);}
.form-control,.form-select{background:rgba(255,255,255,.06);border:1px solid var(--gb);color:#fff;border-radius:11px;font-family:'Outfit',sans-serif;}
.form-control:focus{background:rgba(255,255,255,.09);border-color:var(--b5);color:#fff;box-shadow:0 0 0 3px rgba(57,73,171,.35);}
.form-control::placeholder{color:var(--mut);}
textarea.form-control{resize:none;}
.form-label{font-size:11.5px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;}
.pav{width:96px;height:96px;border-radius:18px;object-fit:cover;border:2px solid var(--b5);box-shadow:0 0 0 4px rgba(57,73,171,.3),0 0 40px rgba(57,73,171,.4);}
.bs{background:linear-gradient(135deg,var(--b5),var(--acc));color:#fff;border:none;border-radius:11px;padding:12px;font-size:14px;font-weight:700;font-family:'Outfit',sans-serif;width:100%;cursor:pointer;box-shadow:0 4px 22px rgba(57,73,171,.5);transition:all .2s;}
.bs:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(57,73,171,.7);}

@keyframes fu{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.a{animation:fu .55s ease both;}
.d1{animation-delay:.05s;}.d2{animation-delay:.12s;}.d3{animation-delay:.20s;}
.d4{animation-delay:.28s;}.d5{animation-delay:.36s;}

@media(max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .main{margin-left:0;padding:14px;}
    .topbar{flex-direction:column;align-items:flex-start;}
    .hero{flex-direction:column;gap:14px;}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sb-logo">
        <div class="sb-brand">Zaman Medical<br>Hospital</div>
        <div class="sb-sub">Doctor Portal</div>
    </div>

    <nav class="sb-nav">
        <div class="sb-sec">Navigation</div>
        <a href="#" class="act"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <a href="docadddiagnosis.php"><i class="fa-solid fa-file-medical"></i> Diagnosis</a>
        <a href="docappointment.php">
            <i class="fa-solid fa-calendar-check"></i> Appointments
            <?php if($pending_count > 0): ?><span class="nb"><?= $pending_count ?></span><?php endif; ?>
        </a>
        <a href="docpatientrecord.php"><i class="fa-solid fa-users"></i> Patients</a>
        <a href="docprecriptions.php"><i class="fa-solid fa-pills"></i> Prescriptions</a>
        <a href="doctorocrreport.php"><i class="fa-solid fa-chart-line"></i> OCRReports</a>
        <a href="docreports.php"><i class="fa-solid fa-file-invoice-dollar"></i>Reports</a>
        <a href="docbilling.php"><i class="fa-solid fa-file-invoice-dollar"></i> Billing</a>

        <div class="sb-sec">My Schedule</div>
        <a href="doctor_schedule.php"><i class="fa-solid fa-calendar-week"></i> My Shifts</a>
        <a href="request_leave.php">
            <i class="fa-solid fa-calendar-minus"></i> Request Leave
            <?php if($pending_leaves > 0): ?><span class="nb red"><?= $pending_leaves ?></span><?php endif; ?>
        </a>

        <?php if($pharmacists && $pharmacists->num_rows > 0): ?>
            <div class="sb-sec">Pharmacists</div>
            <?php while ($p = $pharmacists->fetch_assoc()): ?>
                <a href="chat.php?user=<?= (int)$p['user_id'] ?>">
                    <i class="fa-solid fa-comments"></i>
                    <?= htmlspecialchars($p['full_name'] ?? 'Unknown') ?>
                </a>
            <?php endwhile; ?>
        <?php endif; ?>
    </nav>

    <?php
        $nurses_list = $conn->query("SELECT u.user_id, u.full_name FROM users u WHERE u.role = 'nurse' ORDER BY u.full_name");
        if ($nurses_list && $nurses_list->num_rows > 0):
        ?>
            <div class="sb-sec">Nurses</div>
            <?php while ($n = $nurses_list->fetch_assoc()): ?>
                <a href="doctornursechat.php?nurse=<?= (int)$n['user_id'] ?>">
                    <i class="fa-solid fa-comment-medical"></i>
                    <?= htmlspecialchars($n['full_name']) ?>
                </a>
            <?php endwhile; ?>
        <?php endif; ?>

    <div class="sb-foot"><span class="ldot"></span>System Online &nbsp;·&nbsp; v3.0</div>
</div>
<!-- MAIN -->
<div class="main">

    <!-- TOPBAR -->
    <div class="topbar a">
        <div class="tl">
            <img src="uploads/doctors/<?= htmlspecialchars($details['profile_picture'] ?? 'default.png') ?>"
                 class="doc-av"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($details['full_name']) ?>&background=283593&color=fff&size=80&bold=true'">
            <div>
                <div class="g-text"><?= $greeting ?>, Dr. <?= htmlspecialchars($details['full_name']) ?> 👋</div>
                <span class="sp-pill">
                    <i class="fa-solid fa-stethoscope" style="font-size:9px;"></i>
                    <?= !empty($details['specialty']) ? htmlspecialchars($details['specialty']) : 'Add specialty in profile' ?>
                </span>
            </div>
        </div>
        <div class="tr">
            <button class="btnt pr" onclick="showProfile()"><i class="fa-solid fa-user-pen"></i> Edit Profile</button>
            <a href="logout.php" class="btnt lg"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <!-- HERO -->
    <div class="hero a d1">
        <div>
            <div class="hero-ttl">Welcome to Your Command Center</div>
            <div class="hero-s">Real-time clinical analytics &amp; patient management</div>
            <div class="hero-time">
                <span><i class="fa-regular fa-calendar-days me-1"></i><?= date('l, d F Y') ?></span>
                <span class="ck" id="liveClock">--:--:--</span>
            </div>
        </div>
        <div class="hico">
            <div class="hic"><i class="fa-solid fa-capsules"></i></div>
            <div class="hic lg"><i class="fa-solid fa-heart-pulse"></i></div>
            <div class="hic"><i class="fa-solid fa-dna"></i></div>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="row g-3 mb-4 a d2">
        <div class="col-6 col-md-3">
            <div class="sc c1">
                <div class="si b1"><i class="fa-solid fa-calendar-check"></i></div>
                <div>
                    <div class="sl">Total Appts</div>
                    <div class="sv"><?= $appointments_count ?></div>
                    <div class="sd"><i class="fa-solid fa-arrow-trend-up me-1"></i>All time</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sc c2">
                <div class="si b2"><i class="fa-solid fa-users"></i></div>
                <div>
                    <div class="sl">Patients</div>
                    <div class="sv"><?= $patients_count ?></div>
                    <div class="sd"><i class="fa-solid fa-user-check me-1"></i>Unique</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sc c3">
                <div class="si b3"><i class="fa-solid fa-calendar-day"></i></div>
                <div>
                    <div class="sl">Today</div>
                    <div class="sv"><?= $today_count ?></div>
                    <div class="sd"><i class="fa-solid fa-clock me-1"></i>Scheduled today</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sc c4">
                <div class="si b4"><i class="fa-solid fa-hourglass-half"></i></div>
                <div>
                    <div class="sl">Pending</div>
                    <div class="sv"><?= $pending_count ?></div>
                    <div class="sd n"><i class="fa-solid fa-bell me-1"></i>Awaiting</div>
                </div>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="row g-3 mb-4 a d3">
        <div class="col-6 col-md-3">
            <a href="docadddiagnosis.php" class="at t1">
                <div class="ati a1"><i class="fa-solid fa-file-medical"></i></div>
                <div class="atl">Diagnosis</div>
                <div class="ats">Create patient diagnosis</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="docappointment.php" class="at t2">
                <div class="ati a2"><i class="fa-solid fa-calendar-check"></i></div>
                <div class="atl">Appointments</div>
                <div class="ats">Manage bookings</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="doctor_schedule.php" class="at t3">
                <div class="ati a3"><i class="fa-solid fa-calendar-week"></i></div>
                <div class="atl">My Shifts</div>
                <div class="ats">View assigned schedule</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="request_leave.php" class="at t4">
                <div class="ati a4"><i class="fa-solid fa-calendar-minus"></i></div>
                <div class="atl">Request Leave</div>
                <div class="ats">Submit leave application</div>
            </a>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="row g-4 mb-4 a d4">
        <div class="col-lg-8">
            <div class="gc">
                <div class="chr">
                    <div>
                        <div class="ct">Weekly Appointments</div>
                        <div class="cs">Current week overview</div>
                    </div>
                    <span class="cbg">7 Days</span>
                </div>
                <canvas id="weeklyChart" height="110"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="gc">
                <div class="chr">
                    <div>
                        <div class="ct">Appointment Status</div>
                        <div class="cs">Current distribution</div>
                    </div>
                    <span class="cbg">Live</span>
                </div>
                <canvas id="statusChart" height="220"></canvas>
            </div>
        </div>
    </div>

</div><!-- /main -->

<!-- ========= PROFILE MODAL ========= -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-user-pen me-2"></i>Edit Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="/hospital/update_doctor_profile.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <img src="uploads/doctors/<?= htmlspecialchars($details['profile_picture'] ?: 'default.png') ?>"
                             class="pav mb-3"
                             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($details['full_name']) ?>&background=283593&color=fff&size=120&bold=true'">
                        <input type="file" name="profile_picture" class="form-control mt-2">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($details['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Specialty</label>
                            <input type="text" name="specialty" class="form-control" value="<?= htmlspecialchars($details['specialty']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact</label>
                            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($details['contact']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Experience</label>
                            <input type="text" name="experience" class="form-control" value="<?= htmlspecialchars($details['experience']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Qualifications</label>
                            <textarea name="qualifications" rows="4" class="form-control"><?= htmlspecialchars($details['qualifications']) ?></textarea>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="bs"><i class="fa-solid fa-floppy-disk me-2"></i>Save Changes</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ---- CLOCK ---- */
function updateClock(){
    const now = new Date();
    document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-PK',{hour12:true});
}
setInterval(updateClock,1000); updateClock();

/* ---- CHARTS ---- */
new Chart(document.getElementById('weeklyChart'),{
    type:'line',
    data:{
        labels:<?= json_encode($weekly_labels) ?>,
        datasets:[{label:'Appointments',data:<?= json_encode($weekly_data) ?>,borderColor:'#7e57c2',backgroundColor:'rgba(126,87,194,.15)',fill:true,tension:.4}]
    },
    options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#c5cae9'}},y:{ticks:{color:'#c5cae9'},beginAtZero:true}}}
});

new Chart(document.getElementById('statusChart'),{
    type:'doughnut',
    data:{
        labels:<?= json_encode($status_labels) ?>,
        datasets:[{data:<?= json_encode($status_counts) ?>,backgroundColor:['#5c6bc0','#7e57c2','#ef4444'],borderWidth:0}]
    },
    options:{plugins:{legend:{labels:{color:'#e8eaf6'}}}}
});

/* ---- PROFILE MODAL ---- */
function showProfile(){
    new bootstrap.Modal(document.getElementById('profileModal')).show();
}
</script>
</body>
</html>