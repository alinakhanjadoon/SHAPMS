<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lab') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

$uid = $_SESSION['user_id'];
$errors = [];
$success = '';

// ── FETCH STAFF INFO ──
$user = ['full_name' => 'Lab Technician', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->bind_result($fn, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Lab Technician', 'profile_image' => $pi ?: ''];
$stmt->close();

// ── FETCH PATIENTS (role = 'patient') ──
$patients = [];
$pres = $conn->query("SELECT user_id, full_name FROM users WHERE role='patient' ORDER BY full_name ASC");
if ($pres) while ($row = $pres->fetch_assoc()) $patients[] = $row;

// ── COMMON TEST TYPES ──
$test_types = [
    'Complete Blood Count (CBC)',
    'Blood Sugar (Fasting)',
    'Blood Sugar (Random)',
    'HbA1c',
    'Lipid Profile',
    'Liver Function Test (LFT)',
    'Kidney Function Test (KFT)',
    'Thyroid Function Test (TFT)',
    'Urine Analysis',
    'Urine Culture',
    'Hepatitis B (HBsAg)',
    'Hepatitis C (Anti-HCV)',
    'Dengue NS1 Antigen',
    'Malaria Parasite',
    'Chest X-Ray',
    'ECG',
    'Echocardiography',
    'Ultrasound Abdomen',
    'MRI Brain',
    'CT Scan',
    'Stool Analysis',
    'Sputum Culture',
    'Blood Culture',
    'COVID-19 PCR',
    'Pregnancy Test (Beta-HCG)',
    'Other'
];

// ── HANDLE UPLOAD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $test_name  = trim($_POST['test_name'] ?? '');
    $custom_test= trim($_POST['custom_test'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if ($test_name === 'Other' && $custom_test !== '') $test_name = $custom_test;

    // Validate
    if ($patient_id <= 0)   $errors[] = "Please select a patient.";
    if ($test_name === '' || $test_name === 'Other') $errors[] = "Please select or enter a test name.";
    if (empty($_FILES['report_file']['name'])) $errors[] = "Please upload the report file.";

    // File validation
    $allowed_types = ['application/pdf','image/jpeg','image/png','image/jpg'];
    $max_size      = 5 * 1024 * 1024; // 5MB

    if (!empty($_FILES['report_file']['name'])) {
        $file_type = $_FILES['report_file']['type'];
        $file_size = $_FILES['report_file']['size'];
        $file_ext  = strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($file_type, $allowed_types) && !in_array($file_ext, ['pdf','jpg','jpeg','png'])) {
            $errors[] = "Only PDF, JPG, and PNG files are allowed.";
        }
        if ($file_size > $max_size) {
            $errors[] = "File size must not exceed 5MB.";
        }
    }

    if (empty($errors)) {
        // Create uploads folder if not exists
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $ext       = strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION));
        $filename  = 'report_' . $uid . '_' . time() . '_' . rand(100,999) . '.' . $ext;
        $dest      = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['report_file']['tmp_name'], $dest)) {
            $stmt = $conn->prepare("INSERT INTO lab_reports (patient_id, uploaded_by, test_name, report_file, status, notes, uploaded_at)
                                    VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
            $stmt->bind_param("iisss", $patient_id, $uid, $test_name, $filename, $notes);
            if ($stmt->execute()) {
                $success = "Report uploaded successfully! It is now pending review by the Lab Head.";
            } else {
                $errors[] = "Database error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors[] = "Failed to save the file. Check folder permissions.";
        }
    }
}

// ── STATS (for sidebar mini summary) ──
$my_total     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid")->fetch_row()[0] ?? 0;
$my_pending   = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='pending'")->fetch_row()[0] ?? 0;

$conn->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Report — SHAPMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:    #0d1117; --bg2: #161b27; --bg3: #1c2333;
  --border: rgba(255,255,255,0.07); --border2: rgba(255,255,255,0.12);
  --blue: #3b82f6; --blue-b: #60a5fa; --cyan: #06b6d4;
  --green: #10b981; --yellow: #f59e0b; --red: #ef4444; --purple: #8b5cf6;
  --white: #ffffff; --w90: rgba(255,255,255,0.90); --w70: rgba(255,255,255,0.70);
  --w40: rgba(255,255,255,0.40); --w08: rgba(255,255,255,0.08); --w04: rgba(255,255,255,0.04);
  --sw: 220px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--white);min-height:100vh;}
.layout{display:flex;min-height:100vh;}

/* SIDEBAR */
.sidebar{width:var(--sw);background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:22px 18px 20px;border-bottom:1px solid var(--border);}
.logo-box{width:34px;height:34px;background:linear-gradient(135deg,var(--blue),var(--cyan));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;color:white;flex-shrink:0;}
.logo-name{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:700;color:var(--white);line-height:1.1;}
.logo-sub{font-size:9px;color:var(--w40);letter-spacing:1.5px;text-transform:uppercase;}
.nav-section{font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--w40);padding:18px 18px 6px;}
.sidebar-nav{flex:1;overflow-y:auto;padding:6px 0;}
.sidebar-nav::-webkit-scrollbar{width:0;}
.sidebar-nav a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:var(--w70);text-decoration:none;font-size:13px;font-weight:500;transition:all 0.15s;border-left:2px solid transparent;}
.sidebar-nav a i{width:16px;text-align:center;font-size:13px;}
.sidebar-nav a:hover{background:var(--w04);color:var(--white);}
.sidebar-nav a.active{background:rgba(59,130,246,0.12);color:var(--blue-b);border-left-color:var(--blue);font-weight:600;}
.sidebar-bottom{padding:14px 18px 20px;border-top:1px solid var(--border);}
.sidebar-bottom a{display:flex;align-items:center;gap:9px;color:#f87171;font-size:13px;font-weight:500;text-decoration:none;padding:8px 0;}

/* MAIN */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-breadcrumb{font-size:11px;color:var(--w40);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.topbar-title{font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:700;color:var(--white);}
.topbar-right{display:flex;align-items:center;gap:12px;}
.live-badge{display:flex;align-items:center;gap:6px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.25);color:#34d399;padding:5px 12px;border-radius:99px;font-size:11.5px;font-weight:700;}
.live-dot{width:6px;height:6px;background:#34d399;border-radius:50%;animation:lp 1.5s infinite;}
@keyframes lp{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.4;transform:scale(0.7);}}
.avatar-pill{display:flex;align-items:center;gap:8px;background:var(--w08);border:1px solid var(--border2);border-radius:99px;padding:5px 12px 5px 5px;}
.avatar-circle{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:white;}
.avatar-name{font-size:12px;font-weight:600;color:var(--w90);line-height:1.2;}
.avatar-role{font-size:9.5px;color:var(--w40);}

/* TICKER */
.ticker-bar{background:var(--bg3);border-bottom:1px solid var(--border);padding:0 28px;height:36px;display:flex;align-items:center;overflow:hidden;}
.ticker-track{display:flex;animation:tick 28s linear infinite;white-space:nowrap;}
.ticker-item{display:flex;align-items:center;gap:6px;padding:0 28px;font-size:11.5px;font-weight:500;color:var(--w70);}
.ticker-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;}
@keyframes tick{0%{transform:translateX(0);}100%{transform:translateX(-50%);}}

/* PAGE */
.page-body{padding:26px 28px 56px;display:flex;flex-direction:column;gap:22px;}

/* SECTION LABEL */
.section-label{display:flex;align-items:center;gap:8px;font-size:10px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:1.5px;}
.section-label::before{content:'';width:12px;height:1px;background:var(--w40);}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* LAYOUT GRID */
.upload-grid{display:grid;grid-template-columns:1fr 320px;gap:22px;align-items:start;}

/* FORM CARD */
.form-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.form-card-header{padding:22px 26px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;}
.form-card-icon{width:40px;height:40px;background:linear-gradient(135deg,rgba(59,130,246,0.2),rgba(6,182,212,0.1));border:1px solid rgba(59,130,246,0.25);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--blue-b);}
.form-card-title{font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:700;color:var(--white);}
.form-card-sub{font-size:12px;color:var(--w40);margin-top:2px;}
.form-body{padding:26px;}

/* FORM ELEMENTS */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.form-group{display:flex;flex-direction:column;gap:7px;margin-bottom:20px;}
.form-group:last-child{margin-bottom:0;}
.form-label{font-size:12px;font-weight:600;color:var(--w70);display:flex;align-items:center;gap:6px;}
.form-label i{color:var(--blue-b);font-size:11px;}
.required{color:#f87171;margin-left:2px;}
.form-input, .form-select, .form-textarea{
  background:var(--bg3);border:1px solid var(--border2);border-radius:9px;
  padding:10px 14px;font-size:13px;color:var(--white);
  outline:none;font-family:'Inter',sans-serif;width:100%;
  transition:border-color 0.2s, box-shadow 0.2s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus{
  border-color:var(--blue);
  box-shadow:0 0 0 3px rgba(59,130,246,0.12);
}
.form-input::placeholder, .form-textarea::placeholder{color:var(--w40);}
.form-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.4)' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;cursor:pointer;}
.form-select option{background:#1c2333;color:white;}
.form-textarea{resize:vertical;min-height:90px;line-height:1.6;}

/* Custom test input */
.custom-test-wrap{display:none;margin-top:10px;}
.custom-test-wrap.show{display:block;}

/* FILE DROP ZONE */
.drop-zone{border:2px dashed var(--border2);border-radius:12px;padding:36px 20px;text-align:center;cursor:pointer;transition:all 0.2s;position:relative;background:var(--bg3);}
.drop-zone:hover,.drop-zone.drag-over{border-color:var(--blue);background:rgba(59,130,246,0.05);}
.drop-zone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.drop-icon{width:48px;height:48px;background:rgba(59,130,246,0.10);border:1px solid rgba(59,130,246,0.2);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:20px;color:var(--blue-b);}
.drop-title{font-size:14px;font-weight:600;color:var(--w90);margin-bottom:4px;}
.drop-sub{font-size:12px;color:var(--w40);line-height:1.5;}
.drop-types{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px;flex-wrap:wrap;}
.drop-type-badge{padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;background:var(--w08);color:var(--w70);border:1px solid var(--border2);}

/* File preview */
.file-preview{display:none;margin-top:14px;background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:12px 14px;align-items:center;gap:10px;}
.file-preview.show{display:flex;}
.file-preview-icon{width:32px;height:32px;background:rgba(16,185,129,0.12);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#34d399;flex-shrink:0;}
.file-preview-name{font-size:12.5px;font-weight:600;color:var(--w90);word-break:break-all;}
.file-preview-size{font-size:11px;color:var(--w40);margin-top:2px;}
.file-remove{margin-left:auto;background:none;border:none;color:var(--w40);cursor:pointer;font-size:13px;padding:4px;transition:color 0.15s;flex-shrink:0;}
.file-remove:hover{color:#f87171;}

/* SUBMIT BTN */
.btn-submit{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),#2563eb);color:white;border:none;border-radius:99px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Space Grotesk',sans-serif;letter-spacing:0.3px;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.2s;margin-top:6px;}
.btn-submit:hover{background:linear-gradient(135deg,#2563eb,#1d4ed8);transform:translateY(-1px);box-shadow:0 6px 20px rgba(59,130,246,0.3);}
.btn-submit:active{transform:translateY(0);}

/* ALERTS */
.alert{border-radius:10px;padding:14px 16px;font-size:13px;font-weight:500;display:flex;align-items:flex-start;gap:10px;line-height:1.5;}
.alert i{margin-top:1px;flex-shrink:0;}
.alert-error{background:rgba(239,68,68,0.10);border:1px solid rgba(239,68,68,0.25);color:#fca5a5;}
.alert-success{background:rgba(16,185,129,0.10);border:1px solid rgba(16,185,129,0.25);color:#6ee7b7;}
.alert ul{margin-top:6px;padding-left:16px;}
.alert ul li{margin-bottom:3px;}

/* SIDEBAR RIGHT PANEL */
.side-stack{display:flex;flex-direction:column;gap:16px;}

/* INFO CARD */
.info-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px;}
.info-card-title{font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:700;color:var(--white);display:flex;align-items:center;gap:7px;margin-bottom:14px;}
.info-card-title i{font-size:12px;}

.step-list{display:flex;flex-direction:column;gap:12px;}
.step-item{display:flex;align-items:flex-start;gap:10px;}
.step-num{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;margin-top:1px;}
.step-text{font-size:12px;color:var(--w70);line-height:1.5;}
.step-text strong{color:var(--w90);}

.rule-list{display:flex;flex-direction:column;gap:9px;}
.rule-item{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--w70);line-height:1.4;}
.rule-item i{margin-top:2px;flex-shrink:0;font-size:11px;}

/* MINI STATS */
.mini-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.mini-stat{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 14px;text-align:center;}
.mini-stat-val{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;color:var(--white);line-height:1;}
.mini-stat-label{font-size:10px;font-weight:600;color:var(--w40);text-transform:uppercase;letter-spacing:0.6px;margin-top:4px;}

/* TOAST */
.toast{position:fixed;top:20px;right:24px;background:#10b981;color:white;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:999;display:flex;align-items:center;gap:8px;box-shadow:0 8px 24px rgba(0,0,0,0.3);animation:ti 0.3s ease;}
@keyframes ti{from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:1050px){.upload-grid{grid-template-columns:1fr;}.side-stack{display:grid;grid-template-columns:1fr 1fr;}}
@media(max-width:768px){
  .sidebar{width:56px;}
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span{display:none;}
  .sidebar-logo{padding:16px 10px;justify-content:center;}
  .sidebar-nav a{padding:11px;justify-content:center;}
  .main{margin-left:56px;}
  .page-body{padding:14px;}
  .form-row{grid-template-columns:1fr;}
  .side-stack{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-flask"></i></div>
    <div><div class="logo-name">SHAPMS</div><div class="logo-sub">Lab Staff</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="labstaffdashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="labstaffuploadreport.php" class="active"><i class="fas fa-upload"></i><span>Upload Report</span></a>
    <a href="labstaffreports.php"><i class="fas fa-file-medical-alt"></i><span>My Reports</span></a>
    <div class="nav-section">Account</div>
    <a href="labstaffprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="patientchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">

  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Lab Staff / Upload Report</div>
      <div class="topbar-title">Upload Lab Report</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Lab Technician</div>
        </div>
      </div>
    </div>
  </header>

  <!-- Ticker -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php $tickers=[['#60a5fa','Upload PDF or Image Reports'],['#34d399','Max File Size 5MB'],['#fbbf24','Reports Go Pending After Upload'],['#a78bfa','Lab Head Reviews & Approves'],['#06b6d4','All Uploads Are Secure'],['#60a5fa','Upload PDF or Image Reports'],['#34d399','Max File Size 5MB'],['#fbbf24','Reports Go Pending After Upload'],['#a78bfa','Lab Head Reviews & Approves'],['#06b6d4','All Uploads Are Secure']];
      foreach($tickers as $t): ?>
        <div class="ticker-item"><div class="ticker-dot" style="background:<?= $t[0] ?>"></div><?= $t[1] ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="page-body">

    <div class="section-label"><i class="fas fa-upload"></i> New Report Submission</div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <i class="fas fa-circle-exclamation"></i>
      <div>
        <strong>Please fix the following:</strong>
        <ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="fas fa-circle-check"></i>
      <div><?= htmlspecialchars($success) ?></div>
    </div>
    <?php endif; ?>

    <div class="upload-grid">

      <!-- FORM -->
      <div class="form-card">
        <div class="form-card-header">
          <div class="form-card-icon"><i class="fas fa-file-medical-alt"></i></div>
          <div>
            <div class="form-card-title">Report Details</div>
            <div class="form-card-sub">Fill in all fields and attach the report file</div>
          </div>
        </div>
        <div class="form-body">
          <form method="POST" enctype="multipart/form-data" id="uploadForm">

            <div class="form-row">
              <!-- Patient -->
              <div class="form-group">
                <label class="form-label"><i class="fas fa-user"></i> Patient <span class="required">*</span></label>
                <select name="patient_id" class="form-select" required>
                  <option value="">— Select Patient —</option>
                  <?php foreach($patients as $p): ?>
                  <option value="<?= $p['user_id'] ?>" <?= (isset($_POST['patient_id']) && $_POST['patient_id'] == $p['user_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['full_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Test Type -->
              <div class="form-group">
                <label class="form-label"><i class="fas fa-vial"></i> Test Name <span class="required">*</span></label>
                <select name="test_name" class="form-select" id="testSelect" required>
                  <option value="">— Select Test —</option>
                  <?php foreach($test_types as $t): ?>
                  <option value="<?= htmlspecialchars($t) ?>" <?= (isset($_POST['test_name']) && $_POST['test_name'] === $t) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <!-- Custom test name -->
                <div class="custom-test-wrap" id="customWrap">
                  <input type="text" name="custom_test" class="form-input" placeholder="Type test name here…"
                         value="<?= htmlspecialchars($_POST['custom_test'] ?? '') ?>">
                </div>
              </div>
            </div>

            <!-- Notes -->
            <div class="form-group">
              <label class="form-label"><i class="fas fa-note-sticky"></i> Notes / Remarks <span style="color:var(--w40);font-size:11px;font-weight:400">(optional)</span></label>
              <textarea name="notes" class="form-textarea" placeholder="Add any relevant observations, sample condition, technician remarks…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <!-- File Upload -->
            <div class="form-group">
              <label class="form-label"><i class="fas fa-paperclip"></i> Report File <span class="required">*</span></label>
              <div class="drop-zone" id="dropZone">
                <input type="file" name="report_file" id="fileInput" accept=".pdf,.jpg,.jpeg,.png">
                <div class="drop-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                <div class="drop-title">Drop file here or click to browse</div>
                <div class="drop-sub">Attach the scanned or digital lab report</div>
                <div class="drop-types">
                  <span class="drop-type-badge"><i class="fas fa-file-pdf" style="color:#ef4444"></i> PDF</span>
                  <span class="drop-type-badge"><i class="fas fa-image" style="color:#3b82f6"></i> JPG</span>
                  <span class="drop-type-badge"><i class="fas fa-image" style="color:#10b981"></i> PNG</span>
                  <span class="drop-type-badge"><i class="fas fa-weight-hanging" style="color:#f59e0b"></i> Max 5MB</span>
                </div>
              </div>
              <!-- File Preview -->
              <div class="file-preview" id="filePreview">
                <div class="file-preview-icon"><i class="fas fa-file" id="previewIcon"></i></div>
                <div>
                  <div class="file-preview-name" id="previewName"></div>
                  <div class="file-preview-size" id="previewSize"></div>
                </div>
                <button type="button" class="file-remove" id="removeFile" title="Remove file"><i class="fas fa-xmark"></i></button>
              </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
              <i class="fas fa-upload"></i> Upload Report
            </button>

          </form>
        </div>
      </div>

      <!-- RIGHT PANEL -->
      <div class="side-stack">

        <!-- My Stats -->
        <div class="info-card">
          <div class="info-card-title" style="color:var(--blue-b)"><i class="fas fa-chart-simple"></i> My Upload Summary</div>
          <div class="mini-stats">
            <div class="mini-stat">
              <div class="mini-stat-val"><?= $my_total ?></div>
              <div class="mini-stat-label">Total Uploads</div>
            </div>
            <div class="mini-stat">
              <div class="mini-stat-val" style="color:#fbbf24"><?= $my_pending ?></div>
              <div class="mini-stat-label">Pending Review</div>
            </div>
          </div>
        </div>

        <!-- How it works -->
        <div class="info-card">
          <div class="info-card-title" style="color:var(--cyan)"><i class="fas fa-circle-info"></i> How It Works</div>
          <div class="step-list">
            <div class="step-item">
              <div class="step-num" style="background:rgba(59,130,246,0.15);color:#60a5fa">1</div>
              <div class="step-text"><strong>Select the patient</strong> who the test was performed for.</div>
            </div>
            <div class="step-item">
              <div class="step-num" style="background:rgba(6,182,212,0.15);color:#22d3ee">2</div>
              <div class="step-text"><strong>Choose the test type</strong> from the list or enter a custom name.</div>
            </div>
            <div class="step-item">
              <div class="step-num" style="background:rgba(139,92,246,0.15);color:#a78bfa">3</div>
              <div class="step-text"><strong>Attach the report</strong> as a PDF or image file.</div>
            </div>
            <div class="step-item">
              <div class="step-num" style="background:rgba(16,185,129,0.15);color:#34d399">4</div>
              <div class="step-text"><strong>Submit.</strong> The Lab Head will review and mark it completed.</div>
            </div>
          </div>
        </div>

        <!-- File Rules -->
        <div class="info-card">
          <div class="info-card-title" style="color:var(--yellow)"><i class="fas fa-triangle-exclamation"></i> Upload Rules</div>
          <div class="rule-list">
            <div class="rule-item"><i class="fas fa-check" style="color:#34d399"></i> Accepted formats: PDF, JPG, PNG</div>
            <div class="rule-item"><i class="fas fa-check" style="color:#34d399"></i> Maximum file size: 5MB per report</div>
            <div class="rule-item"><i class="fas fa-check" style="color:#34d399"></i> Ensure the file is legible and complete</div>
            <div class="rule-item"><i class="fas fa-xmark" style="color:#f87171"></i> Do not upload duplicate reports</div>
            <div class="rule-item"><i class="fas fa-xmark" style="color:#f87171"></i> Do not upload reports for wrong patients</div>
            <div class="rule-item"><i class="fas fa-clock" style="color:#fbbf24"></i> All uploads default to "Pending" status</div>
          </div>
        </div>

      </div><!-- /side-stack -->
    </div><!-- /upload-grid -->

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<?php if ($success): ?>
<div class="toast" id="toast"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
<script>setTimeout(()=>{ const t=document.getElementById('toast'); if(t) t.remove(); },4000);</script>
<?php endif; ?>

<script>
// Show/hide custom test input
const testSelect = document.getElementById('testSelect');
const customWrap = document.getElementById('customWrap');

function toggleCustom() {
  customWrap.classList.toggle('show', testSelect.value === 'Other');
  if (testSelect.value === 'Other') {
    customWrap.querySelector('input').focus();
  }
}
testSelect.addEventListener('change', toggleCustom);
<?php if (isset($_POST['test_name']) && $_POST['test_name'] === 'Other'): ?>
customWrap.classList.add('show');
<?php endif; ?>

// File drop zone
const dropZone   = document.getElementById('dropZone');
const fileInput  = document.getElementById('fileInput');
const filePreview= document.getElementById('filePreview');
const previewName= document.getElementById('previewName');
const previewSize= document.getElementById('previewSize');
const previewIcon= document.getElementById('previewIcon');
const removeBtn  = document.getElementById('removeFile');

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
  return (bytes/(1024*1024)).toFixed(2) + ' MB';
}

function showPreview(file) {
  previewName.textContent = file.name;
  previewSize.textContent = formatSize(file.size);
  const ext = file.name.split('.').pop().toLowerCase();
  previewIcon.className = ext === 'pdf' ? 'fas fa-file-pdf' : 'fas fa-file-image';
  previewIcon.style.color = ext === 'pdf' ? '#f87171' : '#60a5fa';
  filePreview.classList.add('show');
  dropZone.style.borderColor = 'var(--green)';
}

function clearFile() {
  fileInput.value = '';
  filePreview.classList.remove('show');
  dropZone.style.borderColor = '';
}

fileInput.addEventListener('change', () => {
  if (fileInput.files[0]) showPreview(fileInput.files[0]);
  else clearFile();
});

removeBtn.addEventListener('click', clearFile);

// Drag & drop
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('drag-over');
  const dt = e.dataTransfer;
  if (dt.files && dt.files[0]) {
    fileInput.files = dt.files;
    showPreview(dt.files[0]);
  }
});

// Submit loading state
document.getElementById('uploadForm').addEventListener('submit', function() {
  const btn = document.getElementById('submitBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
  btn.disabled = true;
});
</script>
</body>
</html>