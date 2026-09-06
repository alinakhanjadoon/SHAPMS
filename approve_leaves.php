<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','department_head'])) {
    header("Location: ../auth/login.php"); exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$logged_user_id = (int)$_SESSION['user_id'];
$logged_role    = $_SESSION['role'];

/* ===== GET DEPT HEAD DEPARTMENT ===== */
/* ===== GET DEPT HEAD DEPARTMENT ===== */
$dept_head_dept_id = null;
if ($logged_role === 'department_head') {
    $dh = $conn->prepare("SELECT department_id FROM users WHERE user_id=? AND role='department_head' LIMIT 1");
    $dh->bind_param("i", $logged_user_id); $dh->execute();
    $dh->bind_result($dept_head_dept_id); $dh->fetch(); $dh->close();
    if (!$dept_head_dept_id) die("Department head profile not found!");
}

/* ===== HANDLE APPROVE / REJECT ===== */
$success = $error = "";
if (isset($_GET['action'], $_GET['leave_id'])) {
    $action   = $_GET['action'];
    $leave_id = (int)$_GET['leave_id'];

    if (in_array($action, ['approved','rejected'])) {
        /* Verify this leave belongs to a doctor in dept head's department */
        if ($logged_role === 'department_head') {
            $verify = $conn->prepare("
                SELECT dl.leave_id FROM doctor_leaves dl
                JOIN doctors d ON dl.doctor_id = d.doctor_id
                JOIN users u ON d.user_id = u.user_id
                WHERE dl.leave_id=? AND u.department_id=?
            ");
            $verify->bind_param("ii", $leave_id, $dept_head_dept_id);
            $verify->execute();
            $ok = $verify->get_result()->fetch_assoc(); $verify->close();
            if (!$ok) { $error = "Unauthorized action."; goto render; }
        }

        $st = $conn->prepare("UPDATE doctor_leaves SET status=?, approved_by=?, updated_at=NOW() WHERE leave_id=? AND status='pending'");
        $st->bind_param("sii", $action, $logged_user_id, $leave_id);
        if ($st->execute() && $st->affected_rows > 0) {
            require_once __DIR__ . '/includes/notifications_functions.php';

            /* Get doctor_id, leave_date, doctor user_id */
            $info = $conn->prepare("
                SELECT dl.doctor_id, dl.leave_date, d.user_id AS doc_user_id, u.full_name AS doc_name
                FROM doctor_leaves dl
                JOIN doctors d ON dl.doctor_id = d.doctor_id
                JOIN users u ON d.user_id = u.user_id
                WHERE dl.leave_id=?
            ");
            $info->bind_param("i", $leave_id); $info->execute();
            $info_row = $info->get_result()->fetch_assoc(); $info->close();
            $lv_doc  = $info_row['doctor_id'];
            $lv_date = $info_row['leave_date'];
            $formatted_date = date("d M Y", strtotime($lv_date));

            /* If approved → expire slots for that date so no new bookings happen */
            if ($action === 'approved') {
                $conn->query("UPDATE appointment_slots SET status='expired' WHERE doctor_id=$lv_doc AND slot_date='$lv_date' AND is_booked=0");

                /* Notify patients with booked appointments on that date */
                $pat_res = $conn->prepare("
                    SELECT a.appointment_id, p.user_id AS patient_user_id
                    FROM appointments a
                    JOIN patients p ON a.patient_id = p.patient_id
                    WHERE a.doctor_id = ? AND DATE(a.appointment_date) = ? AND a.status IN ('scheduled','approved')
                ");
                $pat_res->bind_param("is", $lv_doc, $lv_date);
                $pat_res->execute();
                $pat_rows = $pat_res->get_result()->fetch_all(MYSQLI_ASSOC);
                $pat_res->close();

                foreach ($pat_rows as $pat) {
                    createNotification($conn, $pat['patient_user_id'], 'patient', 'appointment',
                        'Appointment Cancelled — Doctor on Leave',
                        'Dr. ' . $info_row['doc_name'] . ' is on approved leave on ' . $formatted_date . '. Your appointment has been cancelled. Please rebook.',
                        'bookappointment.php'
                    );
                    /* Also cancel those appointments */
                    $upd = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE appointment_id=?");
                    $upd->bind_param("i", $pat['appointment_id']);
                    $upd->execute(); $upd->close();
                }
            }

            /* Notify the doctor about leave decision */
            $decision_msg = $action === 'approved'
                ? "Your leave request for $formatted_date has been approved."
                : "Your leave request for $formatted_date has been rejected.";
            createNotification($conn, $info_row['doc_user_id'], 'doctor', 'leave',
                'Leave Request ' . ucfirst($action),
                $decision_msg,
                'request_leave.php'
            );

            $success = "Leave request " . ucfirst($action) . " successfully.";
        } else {
            $error = "Could not update — request may already be reviewed.";
        }
        $st->close();
    }
}
render:

/* ===== FETCH LEAVE REQUESTS ===== */
$filter = $_GET['filter'] ?? 'pending';
$valid_filters = ['all','pending','approved','rejected'];
if (!in_array($filter, $valid_filters)) $filter = 'pending';

if ($logged_role === 'department_head') {
    $where_dept = "AND u.department_id = $dept_head_dept_id";
} else {
    $where_dept = "";
}

$where_status = ($filter === 'all') ? "" : "AND dl.status = '$filter'";

$leaves_res = $conn->query("
    SELECT dl.leave_id, dl.leave_date, dl.reason, dl.status, dl.created_at,
           u.full_name AS doctor_name, u.gender,
           dp.department_name,
           rev.full_name AS reviewed_by
    FROM doctor_leaves dl
    JOIN doctors d ON dl.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    LEFT JOIN departments dp ON u.department_id = dp.department_id
    LEFT JOIN users rev ON dl.approved_by = rev.user_id
    WHERE 1=1 $where_dept $where_status
    ORDER BY dl.leave_date ASC, dl.created_at DESC
");
$leaves = $leaves_res ? $leaves_res->fetch_all(MYSQLI_ASSOC) : [];

/* ===== COUNTS ===== */
$counts_res = $conn->query("
    SELECT dl.status, COUNT(*) as cnt
    FROM doctor_leaves dl
    JOIN doctors d ON dl.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    WHERE 1=1 $where_dept
    GROUP BY dl.status
");
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0];
while ($row = $counts_res->fetch_assoc()) $counts[$row['status']] = (int)$row['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approve Leaves — SHAPMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
  padding: 32px 16px 48px;
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

.wrap { position: relative; z-index: 1; max-width: 1040px; margin: 0 auto; }

/* ── Page header ─────────────────────────────── */
.page-header { display:flex; align-items:center; gap:16px; margin-bottom:26px; }
.ph-icon {
  width:52px; height:52px; border-radius:16px; flex-shrink:0;
  background: linear-gradient(135deg, var(--plasma), var(--nova));
  display:flex; align-items:center; justify-content:center;
  box-shadow: 0 0 24px rgba(0,198,167,.35), 0 0 46px rgba(0,198,167,.14);
}
.ph-icon svg { width:26px; height:26px; fill:none; stroke:#fff; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
h1 { font-family: var(--ff-disp); font-size:1.6rem; font-weight:700; letter-spacing:-.02em; color:var(--txt-0); }
.ph-sub { font-size:.84rem; color:var(--txt-1); margin-top:3px; }
.role-pill {
  margin-left:auto; font-family: var(--ff-disp); font-size:.68rem; font-weight:700;
  padding:5px 14px; border-radius:100px;
  background: rgba(155,109,255,.1); color: var(--violet);
  border:1px solid rgba(155,109,255,.25);
  text-transform:uppercase; letter-spacing:.05em;
}

/* Alerts */
.alert { padding:13px 18px; border-radius:var(--r-sm); font-size:.88rem; margin-bottom:20px; border:1px solid; display:flex; align-items:center; gap:10px; backdrop-filter: blur(10px); }
.alert.success { background: rgba(0,198,167,.1); border-color: rgba(0,198,167,.28); color: var(--plasma2); }
.alert.error   { background: rgba(255,107,107,.1); border-color: rgba(255,107,107,.28); color: var(--pulse); }
.alert svg { width:18px; height:18px; flex-shrink:0; }

/* ── Stat cards ─────────────────────────────── */
.stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:24px; }
.stat-card {
  background: rgba(15,24,40,.7);
  backdrop-filter: blur(20px);
  border:1px solid rgba(255,255,255,.07);
  border-radius: var(--r-lg);
  padding: 20px 22px;
  display:flex; align-items:center; gap:14px;
  position:relative; overflow:hidden;
  transition: transform .25s var(--ease-expo), border-color .25s;
}
.stat-card::after {
  content:''; position:absolute; top:-30px; right:-30px;
  width:120px; height:120px; border-radius:50%;
  filter: blur(46px); opacity:0; transition: opacity .3s; pointer-events:none;
}
.stat-card:hover { transform: translateY(-3px); }
.stat-card:hover::after { opacity: .5; }
.stat-card.pending-c::after { background: var(--solar); }
.stat-card.approved-c::after { background: var(--plasma); }
.stat-card.rejected-c::after { background: var(--pulse); }
.stat-card.pending-c:hover  { border-color: rgba(255,181,71,.25); }
.stat-card.approved-c:hover { border-color: rgba(0,198,167,.25); }
.stat-card.rejected-c:hover { border-color: rgba(255,107,107,.25); }

.stat-icon { width:44px; height:44px; border-radius:13px; display:grid; place-items:center; flex-shrink:0; }
.stat-icon svg { width:20px; height:20px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.stat-icon.pending-ic  { background: rgba(255,181,71,.1); box-shadow:0 0 18px rgba(255,181,71,.15); }
.stat-icon.pending-ic svg  { stroke: var(--solar); }
.stat-icon.approved-ic { background: rgba(0,198,167,.1); box-shadow:0 0 18px rgba(0,198,167,.15); }
.stat-icon.approved-ic svg { stroke: var(--plasma); }
.stat-icon.rejected-ic { background: rgba(255,107,107,.1); box-shadow:0 0 18px rgba(255,107,107,.15); }
.stat-icon.rejected-ic svg { stroke: var(--pulse); }

.stat-num { font-family: var(--ff-disp); font-size:1.9rem; font-weight:700; line-height:1; color: var(--txt-0); }
.stat-lbl { font-size:.76rem; color:var(--txt-1); margin-top:4px; }

/* ── Panel / card wrap ───────────────────────── */
.card {
  background: rgba(15,24,40,.7);
  backdrop-filter: blur(20px);
  border:1px solid rgba(255,255,255,.07);
  border-radius: var(--r-lg);
  padding:24px; margin-bottom:24px;
  position:relative; overflow:hidden;
}
.card-title {
  font-family: var(--ff-disp);
  font-size:1rem; font-weight:700; color:var(--txt-0);
  padding-bottom:16px; margin-bottom:20px;
  border-bottom:1px solid rgba(255,255,255,.07);
  display:flex; align-items:center; gap:9px; flex-wrap:wrap;
}
.card-title svg { color: var(--plasma); }

/* Filter tabs */
.filter-tabs { display:flex; gap:6px; margin-left:auto; }
.ftab {
  padding:6px 15px; border-radius:100px; font-size:.75rem; font-weight:600;
  text-decoration:none; border:1px solid rgba(255,255,255,.09);
  color:var(--txt-1); background: rgba(255,255,255,.03);
  transition: all .18s;
}
.ftab:hover { border-color: rgba(0,198,167,.3); color: var(--plasma); }
.ftab.active { background: linear-gradient(100deg, var(--plasma), var(--nova)); color:#04140f; border-color: transparent; font-weight:700; }
.ftab .fc { font-family: var(--ff-disp); margin-left:5px; font-size:.7rem; opacity:.85; }

/* ── Table ───────────────────────────────────── */
.tbl { width:100%; border-collapse:collapse; }
.tbl thead th {
  padding:12px 16px; text-align:left;
  font-size:9.5px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase;
  color: var(--txt-3);
  background: rgba(0,0,0,.12);
  border-bottom:1px solid rgba(255,255,255,.06);
}
.tbl td { padding:14px 16px; border-bottom:1px solid rgba(255,255,255,.04); font-size:.86rem; color: var(--txt-1); vertical-align:middle; }
.tbl tr:last-child td { border-bottom:none; }
.tbl tr { transition: background .15s; }
.tbl tr:hover td { background: rgba(255,255,255,.025); }

.date-val { font-family: var(--ff-disp); font-size:.82rem; color: var(--txt-0); }
.doc-info .doc-name { font-weight:700; font-size:.88rem; color: var(--txt-0); }
.doc-info .doc-dept { font-size:.72rem; font-family: var(--ff-disp); color: var(--plasma); margin-top:3px; }
.reason-txt { max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--txt-2); font-size:.78rem; }

.status-badge { display:inline-flex; align-items:center; gap:5px; font-size:.7rem; font-weight:700; padding:4px 11px; border-radius:100px; }
.status-badge.pending  { background: rgba(255,181,71,.1); color: var(--solar); border:1px solid rgba(255,181,71,.25); }
.status-badge.approved { background: rgba(0,198,167,.1); color: var(--plasma); border:1px solid rgba(0,198,167,.25); }
.status-badge.rejected { background: rgba(255,107,107,.1); color: var(--pulse); border:1px solid rgba(255,107,107,.25); }
.s-dot { width:6px; height:6px; border-radius:50%; }
.pending .s-dot  { background: var(--solar); box-shadow:0 0 6px var(--solar); }
.approved .s-dot { background: var(--plasma); box-shadow:0 0 6px var(--plasma); }
.rejected .s-dot { background: var(--pulse); box-shadow:0 0 6px var(--pulse); }

.actions { display:flex; gap:8px; flex-wrap:wrap; }
.btn-approve, .btn-reject {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 14px; border-radius: var(--r-sm);
  font-size:.75rem; font-weight:700; text-decoration:none;
  transition: all .2s var(--ease-back);
}
.btn-approve { background: rgba(0,198,167,.1); color: var(--plasma); border:1px solid rgba(0,198,167,.25); }
.btn-approve:hover { background: rgba(0,198,167,.22); box-shadow:0 0 18px rgba(0,198,167,.25); transform: translateY(-2px) scale(1.02); }
.btn-reject { background: rgba(255,107,107,.1); color: var(--pulse); border:1px solid rgba(255,107,107,.25); }
.btn-reject:hover { background: rgba(255,107,107,.22); box-shadow:0 0 18px rgba(255,107,107,.25); transform: translateY(-2px) scale(1.02); }

.reviewed-by { font-size:.78rem; color:var(--txt-2); }

.empty { text-align:center; padding:52px 20px; color:var(--txt-2); }
.empty svg { width:46px; height:46px; stroke: var(--txt-3); margin:0 auto 14px; display:block; }

@media(max-width:680px){ .stats-row{grid-template-columns:1fr} .filter-tabs{margin-left:0;margin-top:8px} }
</style>
</head>
<body>
<div class="wrap">

<div class="page-header">
  <div class="ph-icon"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
  <div><h1>Approve Leave Requests</h1><p class="ph-sub">Review and manage doctor leave applications</p></div>
  <span class="role-pill"><?= htmlspecialchars($logged_role) ?></span>
</div>

<?php if ($success): ?>
<div class="alert success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-row">
  <div class="stat-card pending-c">
    <div class="stat-icon pending-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
    <div><div class="stat-num"><?= $counts['pending'] ?></div><div class="stat-lbl">Pending</div></div>
  </div>
  <div class="stat-card approved-c">
    <div class="stat-icon approved-ic"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    <div><div class="stat-num"><?= $counts['approved'] ?></div><div class="stat-lbl">Approved</div></div>
  </div>
  <div class="stat-card rejected-c">
    <div class="stat-icon rejected-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
    <div><div class="stat-num"><?= $counts['rejected'] ?></div><div class="stat-lbl">Rejected</div></div>
  </div>
</div>

<!-- Leave Table -->
<div class="card">
  <div class="card-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Leave Requests
    <div class="filter-tabs">
      <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $f=>$label): ?>
        <a href="?filter=<?= $f ?>" class="ftab <?= ($filter===$f)?'active':'' ?>">
          <?= $label ?><span class="fc"><?= $f==='all'?array_sum($counts):($counts[$f]??0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (empty($leaves)): ?>
    <div class="empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      No <?= $filter === 'all' ? '' : $filter ?> leave requests found.
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th>Doctor</th>
          <th>Leave Date</th>
          <th>Reason</th>
          <th>Status</th>
          <th>Reviewed By</th>
          <th>Submitted</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($leaves as $lv): ?>
        <tr>
          <td>
            <div class="doc-info">
              <div class="doc-name"><?= htmlspecialchars($lv['doctor_name']) ?></div>
              <div class="doc-dept"><?= htmlspecialchars($lv['department_name'] ?? '—') ?></div>
            </div>
          </td>
          <td><span class="date-val"><?= date("d M Y", strtotime($lv['leave_date'])) ?></span><br><span style="font-size:.7rem;color:var(--txt-2)"><?= date("l", strtotime($lv['leave_date'])) ?></span></td>
          <td><span class="reason-txt" title="<?= htmlspecialchars($lv['reason']) ?>"><?= htmlspecialchars($lv['reason'] ?: '—') ?></span></td>
          <td><span class="status-badge <?= $lv['status'] ?>"><span class="s-dot"></span><?= ucfirst($lv['status']) ?></span></td>
          <td><span class="reviewed-by"><?= htmlspecialchars($lv['reviewed_by'] ?? '—') ?></span></td>
          <td><span class="date-val" style="font-size:.76rem"><?= date("d M", strtotime($lv['created_at'])) ?></span></td>
          <td>
            <?php if ($lv['status'] === 'pending'): ?>
              <div class="actions">
                <a href="?action=approved&leave_id=<?= $lv['leave_id'] ?>&filter=<?= $filter ?>"
                   class="btn-approve"
                   onclick="return confirm('Approve leave for <?= htmlspecialchars($lv['doctor_name']) ?> on <?= date("d M Y", strtotime($lv['leave_date'])) ?>?')">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Approve
                </a>
                <a href="?action=rejected&leave_id=<?= $lv['leave_id'] ?>&filter=<?= $filter ?>"
                   class="btn-reject"
                   onclick="return confirm('Reject this leave request?')">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  Reject
                </a>
              </div>
            <?php else: ?>
              <span style="font-size:.78rem;color:var(--txt-2)">Reviewed</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</div>
</body>
</html>