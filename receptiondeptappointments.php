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

// ── ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['appointment_id'])) {
    $appt_id = intval($_POST['appointment_id']);
    $action  = $_POST['action'];

    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE appointments SET status='approved' WHERE appointment_id=?");
        $stmt->bind_param("i", $appt_id); $stmt->execute(); $stmt->close();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Appointment approved successfully.'];
    } elseif ($action === 'complete') {
        $stmt = $conn->prepare("UPDATE appointments SET status='completed' WHERE appointment_id=?");
        $stmt->bind_param("i", $appt_id); $stmt->execute(); $stmt->close();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Appointment marked as completed.'];
    } elseif ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE appointment_id=?");
        $stmt->bind_param("i", $appt_id); $stmt->execute(); $stmt->close();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Appointment cancelled.'];
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM appointments WHERE appointment_id=?");
        $stmt->bind_param("i", $appt_id); $stmt->execute(); $stmt->close();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Appointment deleted.'];
    }

    header("Location: receptiondeptappointments.php");
    exit();
}

// ── Flash ──
$flash = null;
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// ── Current user ──
$user = ['full_name' => 'Reception Head'];
$stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn);
if ($stmt->fetch()) $user['full_name'] = $fn ?: 'Reception Head';
$stmt->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));

// ── Check appointments table exists ──
$has_table = $conn->query("SHOW TABLES LIKE 'appointments'")->num_rows > 0;

// ── Stats ──
$total = $pending = $approved = $completed = $cancelled = $today = 0;
$monthly_data = $status_by_month = $daily_week = $hourly_data = $top_doctors = [];

if ($has_table) {
    $total     = $conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0] ?? 0;
    $pending   = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetch_row()[0] ?? 0;
    $approved  = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='approved'")->fetch_row()[0] ?? 0;
    $completed = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='completed'")->fetch_row()[0] ?? 0;
    $cancelled = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='cancelled'")->fetch_row()[0] ?? 0;
    $today     = $conn->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=CURDATE() OR DATE(created_at)=CURDATE()")->fetch_row()[0] ?? 0;

    // Monthly totals — last 6 months
    $r = $conn->query("SELECT DATE_FORMAT(created_at,'%b') as m, COUNT(*) as total
                       FROM appointments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                       GROUP BY MONTH(created_at), DATE_FORMAT(created_at,'%b')
                       ORDER BY MONTH(created_at)");
    if ($r) while ($row = $r->fetch_assoc()) $monthly_data[] = $row;

    // Monthly by status — last 6 months
    $r2 = $conn->query("SELECT DATE_FORMAT(created_at,'%b') as m, status, COUNT(*) as total
                        FROM appointments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        GROUP BY MONTH(created_at), DATE_FORMAT(created_at,'%b'), status
                        ORDER BY MONTH(created_at)");
    if ($r2) while ($row = $r2->fetch_assoc()) $status_by_month[] = $row;

    // Daily last 7 days
    $r3 = $conn->query("SELECT DATE_FORMAT(created_at,'%a') as day, COUNT(*) as total
                        FROM appointments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY DATE(created_at), DATE_FORMAT(created_at,'%a')
                        ORDER BY DATE(created_at)");
    if ($r3) while ($row = $r3->fetch_assoc()) $daily_week[] = $row;

    // Top doctors/departments (if column exists)
    $cols = $conn->query("SHOW COLUMNS FROM appointments LIKE 'doctor_name'");
    if ($cols && $cols->num_rows > 0) {
        $r4 = $conn->query("SELECT doctor_name, COUNT(*) as total FROM appointments
                            WHERE doctor_name IS NOT NULL AND doctor_name != ''
                            GROUP BY doctor_name ORDER BY total DESC LIMIT 5");
        if ($r4) while ($row = $r4->fetch_assoc()) $top_doctors[] = $row;
    }
}

// ── Filter ──
$filter = isset($_GET['filter']) && in_array($_GET['filter'], ['all','pending','approved','completed','cancelled']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_f = isset($_GET['date']) ? trim($_GET['date']) : '';

// ── Appointment list ──
$appt_list = [];
if ($has_table) {
    $where  = "WHERE 1=1";
    $params = [];
    $types  = '';

    if ($filter !== 'all') { $where .= " AND a.status=?"; $params[] = $filter; $types .= 's'; }
    if ($search !== '')    { $where .= " AND (u.full_name LIKE ? OR u.email LIKE ?)"; $like = '%'.$search.'%'; $params[] = $like; $params[] = $like; $types .= 'ss'; }
    if ($date_f !== '')    { $where .= " AND DATE(a.created_at)=?"; $params[] = $date_f; $types .= 's'; }

    // Try appointment_date column first, fallback gracefully
    $has_appt_date = $conn->query("SHOW COLUMNS FROM appointments LIKE 'appointment_date'")->num_rows > 0;
    $has_doctor    = $conn->query("SHOW COLUMNS FROM appointments LIKE 'doctor_name'")->num_rows > 0;
    $has_notes     = $conn->query("SHOW COLUMNS FROM appointments LIKE 'notes'")->num_rows > 0;

    $sel_date   = $has_appt_date ? "a.appointment_date" : "a.created_at";
    $sel_doctor = $has_doctor    ? "a.doctor_name"      : "NULL as doctor_name";
    $sel_notes  = $has_notes     ? "a.notes"            : "NULL as notes";

    $stmt3 = $conn->prepare("SELECT a.appointment_id, u.full_name as patient_name, u.email as patient_email,
                              a.status, a.created_at, $sel_date as appt_date, $sel_doctor, $sel_notes
                              FROM appointments a
                              JOIN users u ON a.patient_id = u.user_id
                              $where
                              ORDER BY CASE a.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END,
                                       a.created_at DESC
                              LIMIT 100");
    if (!empty($params)) $stmt3->bind_param($types, ...$params);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($row = $res3->fetch_assoc()) $appt_list[] = $row;
    $stmt3->close();
}

$conn->close();

// ── Chart data prep ──
$m_labels  = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data, 'm');
$m_totals  = empty($monthly_data) ? [0,0,0,0,0,0] : array_map('intval', array_column($monthly_data, 'total'));

// Status by month arrays
$m_pending = $m_approved = $m_completed = $m_cancelled = array_fill(0, count($m_labels), 0);
foreach ($status_by_month as $sb) {
    $idx = array_search($sb['m'], $m_labels);
    if ($idx !== false) {
        if ($sb['status'] === 'pending')   $m_pending[$idx]   = intval($sb['total']);
        if ($sb['status'] === 'approved')  $m_approved[$idx]  = intval($sb['total']);
        if ($sb['status'] === 'completed') $m_completed[$idx] = intval($sb['total']);
        if ($sb['status'] === 'cancelled') $m_cancelled[$idx] = intval($sb['total']);
    }
}

$d_labels = empty($daily_week) ? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] : array_column($daily_week, 'day');
$d_totals = empty($daily_week) ? [0,0,0,0,0,0,0] : array_map('intval', array_column($daily_week, 'total'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments — SHAPMS</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --sky:       #0ea5e9;
  --sky-light: #38bdf8;
  --sky-dark:  #0284c7;
  --sky-pale:  #e0f2fe;
  --sky-pale2: #bae6fd;
  --bg:        #f0f7ff;
  --white:     #ffffff;
  --ink:       #0c2d3f;
  --ink70:     rgba(12,45,63,0.70);
  --ink40:     rgba(12,45,63,0.45);
  --border:    rgba(14,165,233,0.14);
  --border2:   rgba(14,165,233,0.22);
  --green:     #10b981;
  --yellow:    #f59e0b;
  --red:       #ef4444;
  --purple:    #8b5cf6;
  --sw:        200px;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; font-size: 13px; overflow-x: hidden; width: 100%; }
.layout { display: flex; min-height: 100vh; width: 100%; max-width: 100vw; overflow: hidden; }

/* SIDEBAR */
.sidebar { width: var(--sw); background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; }
.sidebar-logo { display: flex; align-items: center; gap: 10px; padding: 20px 16px 16px; border-bottom: 1px solid var(--border); }
.logo-box { width: 34px; height: 34px; background: var(--sky); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; flex-shrink: 0; }
.logo-name { font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.1; }
.logo-sub  { font-size: 9px; color: var(--sky-dark); letter-spacing: 1.2px; text-transform: uppercase; font-weight: 600; }
.nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink40); padding: 16px 16px 6px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 0; }
.sidebar-nav::-webkit-scrollbar { width: 0; }
.sidebar-nav a { display: flex; align-items: center; gap: 9px; padding: 9px 16px; color: var(--ink70); text-decoration: none; font-size: 12.5px; font-weight: 500; border-left: 2px solid transparent; transition: all 0.15s; }
.sidebar-nav a i { width: 15px; text-align: center; font-size: 13px; }
.sidebar-nav a:hover  { background: var(--sky-pale); color: var(--sky-dark); }
.sidebar-nav a.active { background: var(--sky-pale); color: var(--sky-dark); border-left-color: var(--sky); font-weight: 600; }
.sidebar-bottom { padding: 14px 16px 18px; border-top: 1px solid var(--border); }
.sidebar-bottom a { display: flex; align-items: center; gap: 9px; color: var(--red); font-size: 12.5px; font-weight: 600; text-decoration: none; }

/* MAIN */
.main { margin-left: var(--sw); flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; width: calc(100% - var(--sw)); }

/* TOPBAR */
.topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 24px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-breadcrumb { font-size: 9.5px; color: var(--ink40); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; font-weight: 600; }
.topbar-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.live-badge { display: flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid rgba(16,185,129,0.25); color: #059669; padding: 5px 11px; border-radius: 99px; font-size: 10.5px; font-weight: 700; }
.live-dot { width: 5px; height: 5px; background: var(--green); border-radius: 50%; animation: lp 1.5s infinite; }
@keyframes lp { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
.avatar-pill { display: flex; align-items: center; gap: 8px; background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 5px 12px 5px 5px; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; background: var(--sky); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: white; }
.avatar-name { font-size: 11.5px; font-weight: 700; color: var(--ink); line-height: 1.2; }
.avatar-role { font-size: 9.5px; color: var(--sky-dark); font-weight: 600; }

/* TICKER */
.ticker-bar { background: var(--sky-pale); border-bottom: 1px solid var(--border); height: 34px; display: flex; align-items: center; overflow: hidden; }
.ticker-track { display: flex; animation: tick 30s linear infinite; white-space: nowrap; }
.ticker-item { display: flex; align-items: center; gap: 6px; padding: 0 24px; font-size: 10.5px; font-weight: 600; color: var(--sky-dark); }
.ticker-dot { width: 4px; height: 4px; border-radius: 50%; background: var(--sky); }
@keyframes tick { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }

/* PAGE BODY */
.page-body { padding: 22px 24px 50px; display: flex; flex-direction: column; gap: 20px; }

/* FLASH */
.flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; }
.flash-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.flash-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.flash-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* STAT CARDS */
.stats-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; }
.stat-card { border-radius: 14px; padding: 14px 16px 12px; position: relative; overflow: hidden; color: white; min-height: 105px; display: flex; flex-direction: column; justify-content: space-between; }
.stat-card::after { content: ''; position: absolute; right: -18px; bottom: -18px; width: 70px; height: 70px; border-radius: 50%; background: rgba(255,255,255,0.12); }
.sc1 { background: #0ea5e9; } .sc2 { background: #38bdf8; } .sc3 { background: #7dd3fc; }
.sc4 { background: #0284c7; } .sc5 { background: #075985; } .sc6 { background: #0369a1; }
.stat-icon  { width: 28px; height: 28px; border-radius: 7px; background: rgba(255,255,255,0.22); display: flex; align-items: center; justify-content: center; font-size: 12px; position: relative; z-index: 2; }
.stat-label { font-size: 8.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; margin-top: 10px; position: relative; z-index: 2; }
.stat-val   { font-size: 26px; font-weight: 700; line-height: 1; margin-top: 2px; position: relative; z-index: 2; }
.stat-sub   { font-size: 9px; opacity: 0.82; margin-top: 2px; position: relative; z-index: 2; }

/* SECTION LABEL */
.section-label { display: flex; align-items: center; gap: 8px; font-size: 9.5px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 1.2px; }
.section-label::before { content: ''; width: 12px; height: 2px; background: var(--sky); border-radius: 2px; }
.section-label::after  { content: ''; flex: 1; height: 1px; background: var(--border); }

/* CARDS */
.card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; }
.card-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; margin-bottom: 2px; }
.card-title i { color: var(--sky); font-size: 13px; }
.card-sub   { font-size: 10.5px; color: var(--ink40); margin-bottom: 12px; }
.card-chip  { background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 3px 10px; font-size: 9.5px; font-weight: 700; color: var(--sky-dark); }
.card-hdr   { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 4px; }

/* LEGEND */
.legend-row  { display: flex; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--ink70); }
.legend-dot  { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

/* CHART GRIDS */
.charts-top    { display: grid; grid-template-columns: 1.5fr 1fr; gap: 14px; }
.charts-mid    { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.charts-bottom { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

/* DONUT LAYOUT */
.donut-wrap  { display: flex; align-items: center; gap: 16px; padding-top: 4px; }
.donut-stats { flex: 1; display: flex; flex-direction: column; gap: 7px; }
.ds-row      { display: flex; align-items: center; justify-content: space-between; }
.ds-label    { display: flex; align-items: center; gap: 6px; font-size: 10.5px; color: var(--ink70); }
.ds-dot      { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }
.ds-val      { font-size: 12px; font-weight: 700; color: var(--ink); }
.ds-total    { margin-top: 5px; padding-top: 7px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.ds-total-label { font-size: 10px; color: var(--ink40); }
.ds-total-val   { font-size: 13px; font-weight: 700; color: var(--ink); }

/* MINI STAT inside card */
.mini-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.mini-stat  { background: var(--bg); border-radius: 8px; padding: 10px 12px; }
.mini-stat-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ink40); font-weight: 700; }
.mini-stat-val   { font-size: 20px; font-weight: 700; color: var(--ink); line-height: 1.1; margin-top: 2px; }

/* TOOLBAR */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filter-tabs { display: flex; gap: 4px; background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 4px; flex-wrap: wrap; }
.filter-tab  { padding: 5px 13px; border-radius: 7px; font-size: 11.5px; font-weight: 600; color: var(--ink70); cursor: pointer; text-decoration: none; transition: all 0.15s; white-space: nowrap; display: flex; align-items: center; gap: 5px; }
.filter-tab:hover  { background: var(--sky-pale); color: var(--sky-dark); }
.filter-tab.active { background: var(--sky); color: white; }
.tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; background: rgba(255,255,255,0.25); font-size: 9px; }
.filter-tab:not(.active) .tab-count { background: var(--sky-pale); color: var(--sky-dark); }
.search-wrap { flex: 1; min-width: 180px; position: relative; }
.search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--ink40); font-size: 12px; }
.search-wrap input { width: 100%; padding: 8px 12px 8px 30px; border: 1px solid var(--border); border-radius: 9px; font-size: 12.5px; color: var(--ink); background: var(--white); outline: none; font-family: inherit; }
.search-wrap input:focus { border-color: var(--sky); box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
.search-wrap input::placeholder { color: var(--ink40); }
.date-input { padding: 8px 12px; border: 1px solid var(--border); border-radius: 9px; font-size: 12px; color: var(--ink); background: var(--white); outline: none; font-family: inherit; }
.date-input:focus { border-color: var(--sky); }

/* TABLE */
.table-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.table-hdr  { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px 13px; border-bottom: 1px solid var(--border); }
.table-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; }
.table-title i { color: var(--sky); }
.table-count { font-size: 11px; color: var(--ink40); font-weight: 600; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 9px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.6px; padding: 9px 16px; border-bottom: 1px solid var(--border); background: #fafcff; }
td { padding: 11px 16px; font-size: 12.5px; color: var(--ink); border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8fcff; }

/* BADGES */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
.b-pending   { background: #fef3c7; color: #b45309;  border: 1px solid #fde68a; }
.b-approved  { background: var(--sky-pale); color: var(--sky-dark); border: 1px solid var(--sky-pale2); }
.b-completed { background: #d1fae5; color: #047857;  border: 1px solid #a7f3d0; }
.b-cancelled { background: #fee2e2; color: #b91c1c;  border: 1px solid #fecaca; }

/* PATIENT CELL */
.pat-cell  { display: flex; align-items: center; gap: 9px; }
.pat-av    { width: 30px; height: 30px; border-radius: 50%; background: var(--sky-pale); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: var(--sky-dark); flex-shrink: 0; }
.pat-name  { font-size: 12.5px; font-weight: 600; color: var(--ink); }
.pat-email { font-size: 10.5px; color: var(--ink40); margin-top: 1px; }

/* ACTION BUTTONS */
.action-group { display: flex; gap: 4px; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 7px; font-size: 10.5px; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; }
.btn-approve  { background: var(--sky-pale); color: var(--sky-dark); border: 1px solid var(--sky-pale2); }
.btn-approve:hover  { background: var(--sky-pale2); }
.btn-complete { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.btn-complete:hover { background: #a7f3d0; }
.btn-cancel   { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.btn-cancel:hover   { background: #fde68a; }
.btn-delete   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.btn-delete:hover   { background: #fecaca; }

/* EMPTY */
.empty-state { text-align: center; padding: 44px 20px; color: var(--ink40); }
.empty-state i { font-size: 28px; margin-bottom: 10px; display: block; color: var(--sky-pale2); }
.empty-state p { font-size: 13px; }

/* NO TABLE */
.no-table { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 20px 22px; color: #92400e; font-size: 13px; display: flex; align-items: center; gap: 12px; }

@media(max-width:1200px) {
  .stats-row    { grid-template-columns: repeat(3,1fr); }
  .charts-top   { grid-template-columns: 1fr; }
  .charts-mid   { grid-template-columns: 1fr; }
  .charts-bottom{ grid-template-columns: 1fr 1fr; }
}
@media(max-width:900px) {
  .charts-bottom{ grid-template-columns: 1fr; }
}
@media(max-width:768px) {
  :root { --sw: 56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display: none; }
  .sidebar-logo { padding: 16px 8px; justify-content: center; }
  .sidebar-nav a { padding: 11px; justify-content: center; }
  .page-body { padding: 14px; }
  .stats-row { grid-template-columns: repeat(2,1fr); }
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-headset"></i></div>
    <div><div class="logo-name">Zaman Medical Center</div><div class="logo-sub">Reception</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="receptionistDHdashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="receptiondeptstaff.php"><i class="fas fa-users"></i><span>Reception Staff</span></a>
    <a href="receptiondeptappointments.php" class="active"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
    <div class="nav-section">Account</div>
    <a href="receptiondeptprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
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
      <div class="topbar-breadcrumb">Appointments / Management</div>
      <div class="topbar-title">All Appointments</div>
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

  <div class="ticker-bar">
    <div class="ticker-track">
      <?php $tickers = ['Manage All Patient Appointments','Approve Pending Requests','Track Completion Rates','Monitor Daily Bookings','Cancel or Reschedule Anytime'];
      foreach (array_merge($tickers,$tickers) as $t): ?>
        <div class="ticker-item"><div class="ticker-dot"></div><?= $t ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="page-body">

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <i class="fas <?= $flash['type']==='success'?'fa-circle-check':($flash['type']==='warning'?'fa-triangle-exclamation':'fa-circle-xmark') ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <?php if (!$has_table): ?>
    <div class="no-table"><i class="fas fa-triangle-exclamation"></i> The <strong>appointments</strong> table does not exist yet. Create it in your database to start tracking appointments.</div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card sc1"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="stat-label">Total</div><div class="stat-val"><?= $total ?></div><div class="stat-sub">All-time</div></div></div>
      <div class="stat-card sc2"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-label">Pending</div><div class="stat-val"><?= $pending ?></div><div class="stat-sub">Need action</div></div></div>
      <div class="stat-card sc3"><div class="stat-icon"><i class="fas fa-circle-check"></i></div><div><div class="stat-label">Approved</div><div class="stat-val"><?= $approved ?></div><div class="stat-sub">Confirmed</div></div></div>
      <div class="stat-card sc4"><div class="stat-icon"><i class="fas fa-star"></i></div><div><div class="stat-label">Completed</div><div class="stat-val"><?= $completed ?></div><div class="stat-sub">Done</div></div></div>
      <div class="stat-card sc5"><div class="stat-icon"><i class="fas fa-ban"></i></div><div><div class="stat-label">Cancelled</div><div class="stat-val"><?= $cancelled ?></div><div class="stat-sub">Not attended</div></div></div>
      <div class="stat-card sc6"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div><div><div class="stat-label">Today</div><div class="stat-val"><?= $today ?></div><div class="stat-sub">Scheduled</div></div></div>
    </div>

    <div class="section-label"><i class="fas fa-chart-line"></i> Analytics & Charts</div>

    <!-- ROW 1: Bar stacked + Donut -->
    <div class="charts-top">
      <div class="card">
        <div class="card-hdr">
          <div>
            <div class="card-title"><i class="fas fa-chart-bar"></i> Monthly Appointments by Status</div>
            <div class="card-sub">Breakdown of all statuses over the last 6 months</div>
          </div>
          <div class="card-chip">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#f59e0b"></div>Pending</div>
          <div class="legend-item"><div class="legend-dot" style="background:#0ea5e9"></div>Approved</div>
          <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div>Completed</div>
          <div class="legend-item"><div class="legend-dot" style="background:#ef4444"></div>Cancelled</div>
        </div>
        <div style="position:relative;width:100%;height:150px;">
          <canvas id="stackedChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-hdr">
          <div>
            <div class="card-title"><i class="fas fa-chart-pie"></i> Status Distribution</div>
            <div class="card-sub">Overall appointment breakdown</div>
          </div>
          <div class="card-chip">All-time</div>
        </div>
        <div class="donut-wrap">
          <div style="position:relative;width:100px;height:100px;flex-shrink:0;">
            <canvas id="donutChart"></canvas>
          </div>
          <div class="donut-stats">
            <div class="ds-row"><div class="ds-label"><div class="ds-dot" style="background:#f59e0b"></div>Pending</div><div class="ds-val"><?= $pending ?></div></div>
            <div class="ds-row"><div class="ds-label"><div class="ds-dot" style="background:#0ea5e9"></div>Approved</div><div class="ds-val"><?= $approved ?></div></div>
            <div class="ds-row"><div class="ds-label"><div class="ds-dot" style="background:#10b981"></div>Completed</div><div class="ds-val"><?= $completed ?></div></div>
            <div class="ds-row"><div class="ds-label"><div class="ds-dot" style="background:#ef4444"></div>Cancelled</div><div class="ds-val"><?= $cancelled ?></div></div>
            <div class="ds-total"><div class="ds-total-label">Total</div><div class="ds-total-val"><?= $total ?></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ROW 2: Line chart + Daily bar -->
    <div class="charts-mid">
      <div class="card">
        <div class="card-hdr">
          <div>
            <div class="card-title"><i class="fas fa-chart-line"></i> Monthly Trend</div>
            <div class="card-sub">Total appointments per month</div>
          </div>
          <div class="card-chip">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#0ea5e9"></div>Appointments</div>
        </div>
        <div style="position:relative;width:100%;height:130px;">
          <canvas id="lineChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-hdr">
          <div>
            <div class="card-title"><i class="fas fa-calendar-week"></i> Last 7 Days</div>
            <div class="card-sub">Daily appointment volume this week</div>
          </div>
          <div class="card-chip">7 Days</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#8b5cf6"></div>Daily bookings</div>
        </div>
        <div style="position:relative;width:100%;height:130px;">
          <canvas id="dailyChart"></canvas>
        </div>
      </div>
    </div>

    <!-- ROW 3: Completion rate + Approval rate + Summary mini -->
    <div class="charts-bottom">
      <div class="card">
        <div class="card-title"><i class="fas fa-trophy"></i> Completion Rate</div>
        <div class="card-sub">Completed vs total appointments</div>
        <div class="mini-stats">
          <div class="mini-stat">
            <div class="mini-stat-label">Completed</div>
            <div class="mini-stat-val" style="color:#10b981"><?= $completed ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">Rate</div>
            <div class="mini-stat-val" style="color:#10b981"><?= $total > 0 ? round(($completed/$total)*100) : 0 ?>%</div>
          </div>
        </div>
        <div style="position:relative;width:100%;height:100px;">
          <canvas id="completionChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-title"><i class="fas fa-thumbs-up"></i> Approval Rate</div>
        <div class="card-sub">Approved vs pending requests</div>
        <div class="mini-stats">
          <div class="mini-stat">
            <div class="mini-stat-label">Approved</div>
            <div class="mini-stat-val" style="color:#0ea5e9"><?= $approved ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">Rate</div>
            <div class="mini-stat-val" style="color:#0ea5e9"><?= ($approved+$pending) > 0 ? round(($approved/($approved+$pending))*100) : 0 ?>%</div>
          </div>
        </div>
        <div style="position:relative;width:100%;height:100px;">
          <canvas id="approvalChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-title"><i class="fas fa-ban"></i> Cancellation Rate</div>
        <div class="card-sub">Cancelled vs total appointments</div>
        <div class="mini-stats">
          <div class="mini-stat">
            <div class="mini-stat-label">Cancelled</div>
            <div class="mini-stat-val" style="color:#ef4444"><?= $cancelled ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">Rate</div>
            <div class="mini-stat-val" style="color:#ef4444"><?= $total > 0 ? round(($cancelled/$total)*100) : 0 ?>%</div>
          </div>
        </div>
        <div style="position:relative;width:100%;height:100px;">
          <canvas id="cancellationChart"></canvas>
        </div>
      </div>
    </div>

    <div class="section-label"><i class="fas fa-list"></i> Appointment Records</div>

    <!-- TOOLBAR -->
    <form method="GET" style="display:contents;">
      <div class="toolbar">
        <div class="filter-tabs">
          <?php
          $tabs = ['all'=>'All','pending'=>'Pending','approved'=>'Approved','completed'=>'Completed','cancelled'=>'Cancelled'];
          $counts = ['all'=>$total,'pending'=>$pending,'approved'=>$approved,'completed'=>$completed,'cancelled'=>$cancelled];
          foreach ($tabs as $k => $label): ?>
            <a href="?filter=<?= $k ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $date_f ? '&date='.urlencode($date_f) : '' ?>"
               class="filter-tab <?= $filter===$k ? 'active' : '' ?>">
              <?= $label ?> <span class="tab-count"><?= $counts[$k] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="search-wrap">
          <i class="fas fa-magnifying-glass"></i>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search patient name or email…">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        </div>
        <input type="date" name="date" class="date-input" value="<?= htmlspecialchars($date_f) ?>" onchange="this.form.submit()" title="Filter by date">
      </div>
    </form>

    <!-- TABLE -->
    <div class="table-card">
      <div class="table-hdr">
        <div class="table-title"><i class="fas fa-calendar-check"></i> Appointments</div>
        <div class="table-count"><?= count($appt_list) ?> record<?= count($appt_list) !== 1 ? 's' : '' ?></div>
      </div>

      <?php if (empty($appt_list)): ?>
      <div class="empty-state">
        <i class="fas fa-calendar-xmark"></i>
        <p>No appointments found</p>
      </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Patient</th>
            <th>Appt. Date</th>
            <th>Booked On</th>
            <?php if (!empty($appt_list[0]['doctor_name'])): ?><th>Doctor</th><?php endif; ?>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appt_list as $i => $a):
            $ini = strtoupper(substr($a['patient_name'] ?? 'PA', 0, 2));
            $show_doctor = !empty($a['doctor_name']);
          ?>
          <tr>
            <td style="color:var(--ink40);font-size:11px"><?= $i + 1 ?></td>
            <td>
              <div class="pat-cell">
                <div class="pat-av"><?= htmlspecialchars($ini) ?></div>
                <div>
                  <div class="pat-name"><?= htmlspecialchars($a['patient_name']) ?></div>
                  <div class="pat-email"><?= htmlspecialchars($a['patient_email'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td style="font-size:11.5px">
              <?= $a['appt_date'] ? date('M d, Y', strtotime($a['appt_date'])) : '—' ?>
            </td>
            <td style="color:var(--ink40);font-size:11px">
              <?= date('M d, Y', strtotime($a['created_at'])) ?>
            </td>
            <?php if ($show_doctor): ?>
            <td style="font-size:11.5px"><?= htmlspecialchars($a['doctor_name']) ?></td>
            <?php endif; ?>
            <td>
              <?php
              $sc = ['pending'=>'b-pending','approved'=>'b-approved','completed'=>'b-completed','cancelled'=>'b-cancelled'];
              $ic = ['pending'=>'fa-hourglass-half','approved'=>'fa-circle-check','completed'=>'fa-star','cancelled'=>'fa-ban'];
              $st = $a['status'];
              ?>
              <span class="badge <?= $sc[$st] ?? 'b-pending' ?>">
                <i class="fas <?= $ic[$st] ?? 'fa-circle' ?>" style="font-size:8px"></i>
                <?= ucfirst($st) ?>
              </span>
            </td>
            <td>
              <div class="action-group">
                <?php if ($st === 'pending'): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this appointment?');">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                    <button type="submit" class="btn btn-approve"><i class="fas fa-check"></i> Approve</button>
                  </form>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this appointment?');">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                    <button type="submit" class="btn btn-cancel"><i class="fas fa-xmark"></i> Cancel</button>
                  </form>
                <?php elseif ($st === 'approved'): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as completed?');">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                    <button type="submit" class="btn btn-complete"><i class="fas fa-star"></i> Complete</button>
                  </form>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this appointment?');">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                    <button type="submit" class="btn btn-cancel"><i class="fas fa-xmark"></i> Cancel</button>
                  </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this appointment?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                  <button type="submit" class="btn btn-delete"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
Chart.defaults.color = 'rgba(12,45,63,0.45)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

const mLabels    = <?= json_encode($m_labels) ?>;
const mTotals    = <?= json_encode($m_totals) ?>;
const mPending   = <?= json_encode($m_pending) ?>;
const mApproved  = <?= json_encode($m_approved) ?>;
const mCompleted = <?= json_encode($m_completed) ?>;
const mCancelled = <?= json_encode($m_cancelled) ?>;
const dLabels    = <?= json_encode($d_labels) ?>;
const dTotals    = <?= json_encode($d_totals) ?>;
const statPending   = <?= intval($pending) ?>;
const statApproved  = <?= intval($approved) ?>;
const statCompleted = <?= intval($completed) ?>;
const statCancelled = <?= intval($cancelled) ?>;
const statTotal     = <?= intval($total) ?>;

const TT = { backgroundColor:'#0c2d3f', padding:9, cornerRadius:7 };

// 1. Stacked bar — monthly by status
new Chart(document.getElementById('stackedChart'), {
  type: 'bar',
  data: {
    labels: mLabels,
    datasets: [
      { label:'Pending',   data: mPending,   backgroundColor:'rgba(245,158,11,0.75)',  borderRadius:3 },
      { label:'Approved',  data: mApproved,  backgroundColor:'rgba(14,165,233,0.75)',  borderRadius:3 },
      { label:'Completed', data: mCompleted, backgroundColor:'rgba(16,185,129,0.75)',  borderRadius:3 },
      { label:'Cancelled', data: mCancelled, backgroundColor:'rgba(239,68,68,0.75)',   borderRadius:3 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend:{ display:false }, tooltip: TT },
    scales: {
      x: { stacked:true, grid:{ display:false } },
      y: { stacked:true, grid:{ color:'rgba(14,165,233,0.07)' }, beginAtZero:true, ticks:{ precision:0 } }
    }
  }
});

// 2. Donut — status distribution
new Chart(document.getElementById('donutChart'), {
  type: 'doughnut',
  data: {
    labels: ['Pending','Approved','Completed','Cancelled'],
    datasets: [{ data:[statPending||0.1, statApproved, statCompleted, statCancelled],
      backgroundColor:['#f59e0b','#0ea5e9','#10b981','#ef4444'], borderWidth:0, hoverOffset:5 }]
  },
  options: { responsive:true, maintainAspectRatio:false, cutout:'72%',
    plugins:{ legend:{ display:false }, tooltip: TT } }
});

// 3. Line — monthly trend
new Chart(document.getElementById('lineChart'), {
  type: 'line',
  data: {
    labels: mLabels,
    datasets: [{ label:'Appointments', data: mTotals,
      borderColor:'#0ea5e9', backgroundColor:'rgba(14,165,233,0.1)',
      borderWidth:2, fill:true, tension:0.4, pointRadius:4, pointBackgroundColor:'#0ea5e9' }]
  },
  options: { responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false }, tooltip: TT },
    scales: { x:{ grid:{ display:false } }, y:{ grid:{ color:'rgba(14,165,233,0.07)' }, beginAtZero:true, ticks:{ precision:0 } } }
  }
});

// 4. Daily bar — last 7 days
new Chart(document.getElementById('dailyChart'), {
  type: 'bar',
  data: {
    labels: dLabels,
    datasets: [{ label:'Appointments', data: dTotals,
      backgroundColor:'rgba(139,92,246,0.55)', borderColor:'#8b5cf6',
      borderWidth:1.5, borderRadius:5, maxBarThickness:30 }]
  },
  options: { responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false }, tooltip: TT },
    scales: { x:{ grid:{ display:false } }, y:{ grid:{ color:'rgba(139,92,246,0.07)' }, beginAtZero:true, ticks:{ precision:0 } } }
  }
});

// 5. Completion rate — horizontal bar
new Chart(document.getElementById('completionChart'), {
  type: 'bar',
  data: {
    labels: ['Completed','Remaining'],
    datasets: [{ data:[statCompleted, Math.max(0, statTotal-statCompleted)],
      backgroundColor:['#10b981','rgba(16,185,129,0.15)'],
      borderRadius:6, maxBarThickness:28 }]
  },
  options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false }, tooltip: TT },
    scales: { x:{ grid:{ display:false }, beginAtZero:true, ticks:{ precision:0 } }, y:{ grid:{ display:false } } }
  }
});

// 6. Approval rate — horizontal bar
new Chart(document.getElementById('approvalChart'), {
  type: 'bar',
  data: {
    labels: ['Approved','Pending'],
    datasets: [{ data:[statApproved, statPending],
      backgroundColor:['#0ea5e9','rgba(14,165,233,0.15)'],
      borderRadius:6, maxBarThickness:28 }]
  },
  options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false }, tooltip: TT },
    scales: { x:{ grid:{ display:false }, beginAtZero:true, ticks:{ precision:0 } }, y:{ grid:{ display:false } } }
  }
});

// 7. Cancellation rate — horizontal bar
new Chart(document.getElementById('cancellationChart'), {
  type: 'bar',
  data: {
    labels: ['Cancelled','Others'],
    datasets: [{ data:[statCancelled, Math.max(0, statTotal-statCancelled)],
      backgroundColor:['#ef4444','rgba(239,68,68,0.12)'],
      borderRadius:6, maxBarThickness:28 }]
  },
  options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false }, tooltip: TT },
    scales: { x:{ grid:{ display:false }, beginAtZero:true, ticks:{ precision:0 } }, y:{ grid:{ display:false } } }
  }
});

// Auto-dismiss flash
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.display='none', 4000);
</script>
</body>
</html>