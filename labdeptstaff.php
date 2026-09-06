<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'department_head') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// ── HANDLE ACTIONS (approve / reject / deactivate) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['staff_id'])) {
    $staff_id = intval($_POST['staff_id']);
    $action   = $_POST['action'];

    $new_status = match($action) {
        'approve'    => 'active',
        'reject'     => 'rejected',
        'deactivate' => 'inactive',
        default      => null
    };

    if ($new_status) {
        $stmt = $conn->prepare("UPDATE users SET status=? WHERE user_id=? AND role='lab'");
        $stmt->bind_param("si", $new_status, $staff_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: labdeptstaff.php?msg=" . urlencode(ucfirst($action) . "d successfully!"));
    exit();
}

// ── FETCH LAB HEAD INFO ──
$user = ['full_name' => 'Lab Head', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Lab Head', 'profile_image' => $pi ?: ''];
$stmt->close();

// ── FILTER ──
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = "WHERE role='lab'";
if ($filter === 'pending')  $where .= " AND status='pending'";
if ($filter === 'active')   $where .= " AND status='active'";
if ($filter === 'rejected') $where .= " AND status='rejected'";
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where .= " AND (full_name LIKE '%$safe%' OR email LIKE '%$safe%')";
}

$staff_list = [];
$res = $conn->query("SELECT user_id, full_name, email, contact, status, created_at FROM users $where ORDER BY created_at DESC");
if ($res) while ($row = $res->fetch_assoc()) $staff_list[] = $row;

// ── COUNTS ──
$total    = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab'")->fetch_row()[0] ?? 0;
$pending  = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab' AND status='pending'")->fetch_row()[0] ?? 0;
$active   = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab' AND status='active'")->fetch_row()[0] ?? 0;
$rejected = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab' AND status='rejected'")->fetch_row()[0] ?? 0;
$conn->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lab Staff — SHAPMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:        #0d1117;
  --bg2:       #161b27;
  --bg3:       #1c2333;
  --border:    rgba(255,255,255,0.07);
  --border2:   rgba(255,255,255,0.12);
  --blue:      #3b82f6;
  --blue-b:    #60a5fa;
  --cyan:      #06b6d4;
  --green:     #10b981;
  --yellow:    #f59e0b;
  --red:       #ef4444;
  --white:     #ffffff;
  --w90:       rgba(255,255,255,0.90);
  --w70:       rgba(255,255,255,0.70);
  --w40:       rgba(255,255,255,0.40);
  --w08:       rgba(255,255,255,0.08);
  --w04:       rgba(255,255,255,0.04);
  --sw:        220px;
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

/* TOPBAR */
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-breadcrumb{font-size:11px;color:var(--w40);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.topbar-title{font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:700;color:var(--white);}
.topbar-right{display:flex;align-items:center;gap:12px;}
.live-badge{display:flex;align-items:center;gap:6px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.25);color:#34d399;padding:5px 12px;border-radius:99px;font-size:11.5px;font-weight:700;}
.live-dot{width:6px;height:6px;background:#34d399;border-radius:50%;animation:lp 1.5s infinite;}
@keyframes lp{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.4;transform:scale(0.7);}}
.avatar-pill{display:flex;align-items:center;gap:8px;background:var(--w08);border:1px solid var(--border2);border-radius:99px;padding:5px 12px 5px 5px;cursor:pointer;}
.avatar-circle{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:white;}
.avatar-name{font-size:12px;font-weight:600;color:var(--w90);line-height:1.2;}
.avatar-role{font-size:9.5px;color:var(--w40);}

/* TICKER */
.ticker-bar{background:var(--bg3);border-bottom:1px solid var(--border);padding:0 28px;height:36px;display:flex;align-items:center;overflow:hidden;}
.ticker-track{display:flex;animation:tick 30s linear infinite;white-space:nowrap;}
.ticker-item{display:flex;align-items:center;gap:6px;padding:0 28px;font-size:11.5px;font-weight:500;color:var(--w70);}
.ticker-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;}
@keyframes tick{0%{transform:translateX(0);}100%{transform:translateX(-50%);}}

/* PAGE */
.page-body{padding:22px 28px 48px;display:flex;flex-direction:column;gap:20px;}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-top:2px solid var(--accent,var(--blue));border-radius:12px;padding:18px 16px 14px;display:flex;flex-direction:column;gap:8px;transition:transform 0.2s;}
.stat-card:hover{transform:translateY(-3px);}
.stat-icon-row{display:flex;align-items:center;justify-content:space-between;}
.stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.stat-val{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:700;color:var(--white);line-height:1;}
.stat-label{font-size:10px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:0.8px;}
.stat-sub{font-size:10.5px;font-weight:600;}

/* FILTER BAR */
.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;}
.ftab{padding:6px 14px;border-radius:99px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;border:1px solid var(--border2);color:var(--w70);}
.ftab:hover{background:var(--w08);color:var(--white);}
.ftab.active{background:var(--blue);border-color:var(--blue);color:white;}
.ftab-pending.active{background:var(--yellow);border-color:var(--yellow);color:#0d1117;}
.ftab-active.active{background:var(--green);border-color:var(--green);color:white;}
.ftab-rejected.active{background:var(--red);border-color:var(--red);color:white;}
.search-wrap{margin-left:auto;position:relative;}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--w40);font-size:12px;}
.search-wrap input{background:var(--bg3);border:1px solid var(--border2);border-radius:99px;padding:7px 14px 7px 32px;font-size:12.5px;color:var(--white);outline:none;font-family:'Inter',sans-serif;width:220px;transition:border-color 0.2s;}
.search-wrap input:focus{border-color:var(--blue);}
.search-wrap input::placeholder{color:var(--w40);}

/* TABLE CARD */
.table-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
.table-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;}
.table-title{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:8px;}
.table-title i{color:var(--cyan);}
.table-count{font-size:11px;color:var(--w40);}
table{width:100%;border-collapse:collapse;}
th{text-align:left;font-size:9.5px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:0.8px;padding:0 16px 10px;border-bottom:1px solid var(--border);}
td{padding:12px 16px;font-size:13px;color:var(--w90);border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--w04);}

/* AVATAR in table */
.staff-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white;flex-shrink:0;}
.staff-info{display:flex;align-items:center;gap:10px;}
.staff-name{font-size:13px;font-weight:600;color:var(--w90);}
.staff-email{font-size:11px;color:var(--w40);}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;}
.b-pending {background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);}
.b-active  {background:rgba(16,185,129,0.12);color:#34d399;border:1px solid rgba(16,185,129,0.2);}
.b-rejected{background:rgba(239,68,68,0.12);color:#f87171;border:1px solid rgba(239,68,68,0.2);}
.b-inactive{background:rgba(255,255,255,0.08);color:var(--w70);border:1px solid var(--border2);}

/* ACTION BUTTONS */
.action-btns{display:flex;gap:6px;align-items:center;}
.btn-action{padding:5px 12px;border-radius:99px;font-size:11px;font-weight:600;border:none;cursor:pointer;transition:all 0.15s;font-family:'Inter',sans-serif;display:flex;align-items:center;gap:5px;}
.btn-approve{background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.25);}
.btn-approve:hover{background:#10b981;color:white;}
.btn-reject{background:rgba(239,68,68,0.12);color:#f87171;border:1px solid rgba(239,68,68,0.2);}
.btn-reject:hover{background:#ef4444;color:white;}
.btn-deactivate{background:rgba(255,255,255,0.08);color:var(--w70);border:1px solid var(--border2);}
.btn-deactivate:hover{background:var(--w15);color:white;}

/* EMPTY */
.empty-state{text-align:center;padding:48px 16px;color:var(--w40);}
.empty-state i{font-size:36px;margin-bottom:12px;display:block;color:var(--w40);}
.empty-state h3{font-size:15px;font-weight:600;color:var(--w70);margin-bottom:6px;}
.empty-state p{font-size:12.5px;}

/* TOAST */
.toast{position:fixed;top:20px;right:24px;background:#10b981;color:white;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:999;display:flex;align-items:center;gap:8px;box-shadow:0 8px 24px rgba(0,0,0,0.3);animation:toastin 0.3s ease;}
@keyframes toastin{from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);}}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:28px;width:380px;box-shadow:0 20px 60px rgba(0,0,0,0.5);}
.modal h3{font-family:'Space Grotesk',sans-serif;font-size:17px;font-weight:700;margin-bottom:8px;}
.modal p{font-size:13px;color:var(--w70);margin-bottom:22px;line-height:1.5;}
.modal-btns{display:flex;gap:10px;justify-content:flex-end;}
.modal-cancel{padding:9px 20px;border-radius:99px;background:var(--w08);color:var(--w70);border:1px solid var(--border2);font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}
.modal-confirm{padding:9px 20px;border-radius:99px;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;color:white;}

@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){
  .sidebar{width:56px;}
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span{display:none;}
  .sidebar-logo{padding:16px 10px;justify-content:center;}
  .sidebar-nav a{padding:11px;justify-content:center;}
  .main{margin-left:56px;}
  .page-body{padding:14px;}
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-flask"></i></div>
    <div><div class="logo-name">SHAPMS</div><div class="logo-sub">Laboratory</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="labdeptdashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="labdeptstaff.php" class="active"><i class="fas fa-users"></i><span>Lab Staff</span></a>
    <a href="labdeptreports.php"><i class="fas fa-file-medical-alt"></i><span>Lab Reports</span></a>
    <div class="nav-section">Account</div>
    <a href="labdeptprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="patientchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Dashboard / Lab Staff</div>
      <div class="topbar-title">Lab Staff Management</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Dept. Head</div>
        </div>
      </div>
    </div>
  </header>

  <!-- Ticker -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php $tickers=[['#34d399','Staff Management Active'],['#60a5fa','Approve Pending Technicians'],['#fbbf24','Review Rejections'],['#06b6d4','Monitor Active Staff'],['#34d399','Staff Management Active'],['#60a5fa','Approve Pending Technicians'],['#fbbf24','Review Rejections'],['#06b6d4','Monitor Active Staff']];
      foreach($tickers as $t): ?>
        <div class="ticker-item"><div class="ticker-dot" style="background:<?= $t[0] ?>"></div><?= $t[1] ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="page-body">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card" style="--accent:#3b82f6">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(59,130,246,0.12);color:#60a5fa"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-val"><?= $total ?></div>
        <div class="stat-label">Total Staff</div>
        <div class="stat-sub" style="color:#60a5fa">All lab technicians</div>
      </div>
      <div class="stat-card" style="--accent:#f59e0b">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(245,158,11,0.12);color:#fbbf24"><i class="fas fa-user-clock"></i></div>
        </div>
        <div class="stat-val"><?= $pending ?></div>
        <div class="stat-label">Pending Approvals</div>
        <div class="stat-sub" style="color:#fbbf24">Needs attention</div>
      </div>
      <div class="stat-card" style="--accent:#10b981">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(16,185,129,0.12);color:#34d399"><i class="fas fa-user-check"></i></div>
        </div>
        <div class="stat-val"><?= $active ?></div>
        <div class="stat-label">Active Staff</div>
        <div class="stat-sub" style="color:#34d399">↑ Operational</div>
      </div>
      <div class="stat-card" style="--accent:#ef4444">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(239,68,68,0.12);color:#f87171"><i class="fas fa-user-times"></i></div>
        </div>
        <div class="stat-val"><?= $rejected ?></div>
        <div class="stat-label">Rejected</div>
        <div class="stat-sub" style="color:#f87171">Access denied</div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
      <div class="filter-tabs">
        <a href="?filter=all"      class="ftab <?= $filter==='all'?'active':'' ?>">All (<?= $total ?>)</a>
        <a href="?filter=pending"  class="ftab ftab-pending <?= $filter==='pending'?'active':'' ?>">Pending (<?= $pending ?>)</a>
        <a href="?filter=active"   class="ftab ftab-active  <?= $filter==='active'?'active':'' ?>">Active (<?= $active ?>)</a>
        <a href="?filter=rejected" class="ftab ftab-rejected <?= $filter==='rejected'?'active':'' ?>">Rejected (<?= $rejected ?>)</a>
      </div>
      <form method="GET" class="search-wrap">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <i class="fas fa-search"></i>
        <input type="text" name="search" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>">
      </form>
    </div>

    <!-- Staff Table -->
    <div class="table-card">
      <div class="table-hdr">
        <div class="table-title"><i class="fas fa-users"></i> Lab Technicians</div>
        <div class="table-count"><?= count($staff_list) ?> result<?= count($staff_list)!==1?'s':'' ?></div>
      </div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Staff Member</th>
            <th>Contact</th>
            <th>Status</th>
            <th>Registered</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($staff_list)): ?>
          <tr>
            <td colspan="6">
              <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No staff found</h3>
                <p>No lab technicians match your current filter.</p>
              </div>
            </td>
          </tr>
          <?php else: foreach ($staff_list as $i => $s):
            $initials_s = strtoupper(substr($s['full_name'], 0, 2));
            $badge_class = match($s['status']) {
              'active'   => 'b-active',
              'pending'  => 'b-pending',
              'rejected' => 'b-rejected',
              default    => 'b-inactive'
            };
          ?>
          <tr>
            <td style="color:var(--w40);font-size:12px"><?= $i + 1 ?></td>
            <td>
              <div class="staff-info">
                <div class="staff-avatar"><?= htmlspecialchars($initials_s) ?></div>
                <div>
                  <div class="staff-name"><?= htmlspecialchars($s['full_name']) ?></div>
                  <div class="staff-email"><?= htmlspecialchars($s['email']) ?></div>
                </div>
              </div>
            </td>
            <td style="color:var(--w70);font-size:12px"><?= htmlspecialchars($s['contact'] ?: '—') ?></td>
            <td><span class="badge <?= $badge_class ?>"><?= ucfirst($s['status']) ?></span></td>
            <td style="color:var(--w40);font-size:12px"><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
            <td>
              <div class="action-btns">
                <?php if ($s['status'] === 'pending'): ?>
                  <button class="btn-action btn-approve" onclick="confirmAction(<?= $s['user_id'] ?>,'approve','Approve','Are you sure you want to approve <b><?= htmlspecialchars($s['full_name']) ?></b>? They will get full lab access.')">
                    <i class="fas fa-check"></i> Approve
                  </button>
                  <button class="btn-action btn-reject" onclick="confirmAction(<?= $s['user_id'] ?>,'reject','Reject','Are you sure you want to reject <b><?= htmlspecialchars($s['full_name']) ?></b>?')">
                    <i class="fas fa-times"></i> Reject
                  </button>
                <?php elseif ($s['status'] === 'active'): ?>
                  <button class="btn-action btn-deactivate" onclick="confirmAction(<?= $s['user_id'] ?>,'deactivate','Deactivate','Are you sure you want to deactivate <b><?= htmlspecialchars($s['full_name']) ?></b>?')">
                    <i class="fas fa-ban"></i> Deactivate
                  </button>
                <?php elseif ($s['status'] === 'rejected' || $s['status'] === 'inactive'): ?>
                  <button class="btn-action btn-approve" onclick="confirmAction(<?= $s['user_id'] ?>,'approve','Re-Activate','Are you sure you want to re-activate <b><?= htmlspecialchars($s['full_name']) ?></b>?')">
                    <i class="fas fa-rotate-left"></i> Re-Activate
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<!-- TOAST -->
<?php if ($msg): ?>
<div class="toast" id="toast"><i class="fas fa-check-circle"></i><?= htmlspecialchars($msg) ?></div>
<script>setTimeout(()=>document.getElementById('toast').remove(), 3500);</script>
<?php endif; ?>

<!-- CONFIRM MODAL -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <h3 id="modal-title">Confirm Action</h3>
    <p id="modal-body">Are you sure?</p>
    <div class="modal-btns">
      <button class="modal-cancel" onclick="closeModal()">Cancel</button>
      <form method="POST" id="modal-form" style="display:inline">
        <input type="hidden" name="staff_id" id="modal-staff-id">
        <input type="hidden" name="action"   id="modal-action">
        <button type="submit" class="modal-confirm" id="modal-confirm-btn">Confirm</button>
      </form>
    </div>
  </div>
</div>

<script>
function confirmAction(id, action, label, bodyHtml) {
  document.getElementById('modal-title').textContent = label + ' Staff Member';
  document.getElementById('modal-body').innerHTML = bodyHtml;
  document.getElementById('modal-staff-id').value = id;
  document.getElementById('modal-action').value = action;
  const btn = document.getElementById('modal-confirm-btn');
  btn.textContent = label;
  btn.style.background = action === 'approve' ? '#10b981' : action === 'reject' ? '#ef4444' : '#6b7280';
  document.getElementById('modal').classList.add('open');
}

function closeModal() {
  document.getElementById('modal').classList.remove('open');
}

document.getElementById('modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>
</body>
</html>