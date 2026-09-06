<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= AUTH ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit();
}

/* ================= DB ================= */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

/* ================= GET DOCTOR ID ================= */
$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT doctor_id, full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result) die("Doctor profile not found!");
$doctor_id   = $result['doctor_id'];
$doctor_name = $result['full_name'];

/* ================= FETCH SCHEDULE (READ ONLY) ================= */
$res = $conn->prepare("
    SELECT ds.schedule_id, ds.working_day, ds.start_time, ds.end_time,
           ds.max_patients, ds.is_available,
           u.full_name AS assigned_by_name
    FROM doctor_schedule ds
    LEFT JOIN users u ON ds.assigned_by = u.user_id
    WHERE ds.doctor_id = ?
    ORDER BY FIELD(ds.working_day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
");
$res->bind_param("i", $doctor_id);
$res->execute();
$schedules = $res->get_result()->fetch_all(MYSQLI_ASSOC);
$res->close();

/* ================= FETCH PENDING LEAVES ================= */
$lv = $conn->prepare("
    SELECT leave_id, leave_date, reason, status
    FROM doctor_leaves
    WHERE doctor_id = ? AND leave_date >= CURDATE()
    ORDER BY leave_date ASC
    LIMIT 5
");
$lv->bind_param("i", $doctor_id);
$lv->execute();
$upcoming_leaves = $lv->get_result()->fetch_all(MYSQLI_ASSOC);
$lv->close();

/* ================= TODAY'S SLOT COUNT ================= */
$today = date('Y-m-d');
$tc = $conn->prepare("
    SELECT
        SUM(CASE WHEN is_booked=1 THEN 1 ELSE 0 END) AS booked,
        COUNT(*) AS total
    FROM appointment_slots
    WHERE doctor_id=? AND slot_date=? AND status IN ('available','booked')
");
$tc->bind_param("is", $doctor_id, $today);
$tc->execute();
$today_counts = $tc->get_result()->fetch_assoc();
$tc->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Schedule — SHAPMS</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root{
    /* Two shades of blue + purplish blue theme from report file */
    --b1: #0a0f2a;
    --b2: #11163d;
    --b3: #1a237e;
    --b4: #283593;
    --b5: #3949ab;
    --b6: #5c6bc0;
    --b7: #7986cb;
    --b8: #9fa8da;
    --b9: #c5cae9;
    --acc: #7e57c2;
    --acc2: #b39ddb;
    --acc3: #9575cd;
    --glow: rgba(57,73,171,.6);
    --glass: rgba(255,255,255,.045);
    --gb: rgba(255,255,255,.09);
    --gh: rgba(255,255,255,.07);
    --txt: #e8eaf6;
    --mut: #9fa8da;
    --r: 20px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:'Outfit',sans-serif;
    background:var(--b1);
    color:var(--txt);
    min-height:100vh;
    position:relative;
    padding:32px 16px;
}

/* DEEP BACKGROUND */
body::before{
    content:'';
    position:fixed;inset:0;
    background:
        radial-gradient(ellipse 90% 70% at 10% 10%, rgba(57,73,171,.28) 0%,transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%, rgba(126,87,194,.22) 0%,transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 45%, rgba(26,35,126,.5) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
body::after{
    content:'';
    position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.018) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.018) 1px,transparent 1px);
    background-size:52px 52px;
    pointer-events:none;z-index:0;
}

.wrap{max-width:820px;margin:0 auto;position:relative;z-index:2}

/* Header */
.page-header{display:flex;align-items:center;gap:14px;margin-bottom:28px}
.ph-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--b5),var(--acc));border-radius:12px;display:grid;place-items:center}
.ph-icon svg{width:26px;height:26px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:1.5rem;font-weight:700;letter-spacing:-.02em;color:#fff}
.ph-sub{font-size:.84rem;color:var(--mut);margin-top:2px}

/* Info banner — READ ONLY notice */
.readonly-banner{
  display:flex;align-items:flex-start;gap:12px;
  background:var(--glass);backdrop-filter:blur(22px);border:1px solid var(--gb);
  border-radius:var(--r);padding:14px 18px;
  margin-bottom:24px;
}
.readonly-banner svg{width:20px;height:20px;stroke:var(--acc2);flex-shrink:0;margin-top:1px}
.readonly-banner strong{display:block;font-size:.9rem;color:#fff;margin-bottom:2px}
.readonly-banner p{font-size:.82rem;color:var(--mut);line-height:1.5}
.readonly-banner a{color:var(--acc2);font-weight:600;text-decoration:none}
.readonly-banner a:hover{text-decoration:underline}

/* Today Stats */
.today-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
.stat-card{
    background:var(--glass);backdrop-filter:blur(22px);
    border-radius:var(--r);border:1px solid var(--gb);
    padding:18px 20px;text-align:center;transition:transform .25s
}
.stat-card:hover{transform:translateY(-3px)}
.stat-num{font-size:2rem;font-weight:800;font-family:var(--mono);line-height:1;color:#fff}
.stat-lbl{font-size:.76rem;color:var(--mut);margin-top:4px}
.stat-day{font-size:.7rem;font-family:var(--mono);color:var(--b8);margin-top:2px}

/* Card */
.card{
    background:var(--glass);backdrop-filter:blur(22px);
    border-radius:var(--r);border:1px solid var(--gb);
    padding:24px;margin-bottom:24px
}
.card-title{
    font-size:.95rem;font-weight:700;padding-bottom:14px;margin-bottom:20px;
    border-bottom:1px solid var(--gb);display:flex;align-items:center;gap:8px;color:#fff
}
.badge{
    font-family:var(--mono);font-size:.68rem;background:rgba(57,73,171,.3);
    color:var(--b7);padding:2px 8px;border-radius:20px;border:1px solid var(--b6)
}
.badge-green{
    background:rgba(126,87,194,.25);color:var(--acc2);border-color:var(--acc)
}

/* Schedule Table */
.tbl{width:100%;border-collapse:collapse}
.tbl thead tr{background:rgba(255,255,255,.03)}
.tbl th{
    padding:10px 14px;font-size:.7rem;font-weight:600;text-transform:uppercase;
    letter-spacing:.06em;color:var(--mut);text-align:left;border-bottom:2px solid var(--gb)
}
.tbl td{
    padding:13px 14px;font-size:.86rem;border-bottom:1px solid var(--gb);
    vertical-align:middle;color:var(--txt)
}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.03)}

.day-pill{
    display:inline-block;font-family:var(--mono);font-size:.72rem;font-weight:600;
    padding:3px 10px;border-radius:20px;background:rgba(57,73,171,.3);
    color:var(--b7);border:1px solid var(--b6)
}
.day-pill.wk{background:rgba(126,87,194,.25);color:var(--acc2);border-color:var(--acc)}
.time-val{font-family:var(--mono);font-size:.83rem;color:var(--b9)}
.avail-badge{
    display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:600;
    padding:3px 9px;border-radius:20px
}
.avail-badge.on{background:rgba(126,87,194,.25);color:var(--acc2);border:1px solid var(--acc)}
.avail-badge.off{background:rgba(239,68,68,.2);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
.av-dot{width:6px;height:6px;border-radius:50%}
.avail-badge.on .av-dot{background:var(--acc)}
.avail-badge.off .av-dot{background:#f44336}
.assigned-by{font-size:.72rem;color:var(--mut)}

/* Leave status */
.leave-badge{
    display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;
    padding:3px 9px;border-radius:20px
}
.leave-badge.pending{background:rgba(255,193,7,.2);color:#ffd54f;border:1px solid rgba(255,193,7,.3)}
.leave-badge.approved{background:rgba(126,87,194,.25);color:var(--acc2);border:1px solid var(--acc)}
.leave-badge.rejected{background:rgba(239,68,68,.2);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}

/* Empty */
.empty{text-align:center;padding:40px 20px;color:var(--mut);font-size:.88rem}
.empty svg{width:44px;height:44px;stroke:var(--b8);margin:0 auto 12px;display:block}
.empty strong{display:block;font-size:.95rem;color:#fff;margin-bottom:6px}

/* Request leave button */
.btn{
    display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:40px;
    font-family:'Outfit',sans-serif;font-size:.86rem;font-weight:600;border:none;
    cursor:pointer;transition:all .2s;text-decoration:none
}
.btn-teal{
    background:linear-gradient(135deg,var(--b5),var(--acc));color:#fff;
    border:1px solid var(--b6)
}
.btn-teal:hover{transform:translateY(-1px);box-shadow:0 8px 20px var(--glow)}

/* Weekly visual bar */
.week-bar{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:24px}
.week-day{
    padding:10px 6px;border-radius:8px;text-align:center;border:1px solid var(--gb);
    background:rgba(255,255,255,.03)
}
.week-day.working{background:rgba(57,73,171,.3);border-color:var(--b6)}
.week-day.off{background:rgba(255,255,255,.03);opacity:.5}
.wd-name{
    font-family:var(--mono);font-size:.68rem;font-weight:600;text-transform:uppercase;color:var(--mut)
}
.week-day.working .wd-name{color:var(--b7)}
.wd-status{font-size:.65rem;margin-top:4px;font-weight:600;color:var(--mut)}
.week-day.working .wd-status{color:var(--acc2)}

@media(max-width:600px){.today-stats{grid-template-columns:1fr 1fr}.week-bar{grid-template-columns:repeat(4,1fr)}}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="page-header">
  <div class="ph-icon">
    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
  </div>
  <div>
    <h1>My Weekly Schedule</h1>
    <p class="ph-sub">Dr. <?= htmlspecialchars($doctor_name) ?> — View your assigned shifts</p>
  </div>
</div>

<!-- READ ONLY NOTICE -->
<div class="readonly-banner">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
  </svg>
  <div>
    <strong>Your schedule is managed by the Department Head</strong>
    <p>You cannot change your own availability. Your weekly shifts are assigned by the Medical Department Head.
    If you need a day off, please <a href="request_leave.php">submit a leave request</a>.</p>
  </div>
</div>

<!-- Today's Stats -->
<div class="today-stats">
  <div class="stat-card">
    <div class="stat-num"><?= max(0, (int)($today_counts['total']??0) - (int)($today_counts['booked']??0)) ?></div>
    <div class="stat-lbl">Free Slots</div>
    <div class="stat-day">Today</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?= (int)($today_counts['booked']??0) ?></div>
    <div class="stat-lbl">Booked</div>
    <div class="stat-day">Today</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?= (int)($today_counts['total']??0) ?></div>
    <div class="stat-lbl">Total Slots</div>
    <div class="stat-day">Today</div>
  </div>
</div>

<!-- Weekly Visual Bar -->
<?php
$working_days_set = array_column($schedules, 'working_day');
$all_days = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday','Sun'=>'Sunday'];
?>
<div class="week-bar">
  <?php foreach ($all_days as $short => $full): $is_working = in_array($full, $working_days_set); ?>
    <div class="week-day <?= $is_working ? 'working' : 'off' ?>">
      <div class="wd-name"><?= $short ?></div>
      <div class="wd-status"><?= $is_working ? 'Working' : 'Off' ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Schedule Table -->
<div class="card">
  <div class="card-title">
    <i class="fa-regular fa-calendar"></i>
    Assigned Weekly Shifts
    <span class="badge"><?= count($schedules) ?> working day(s)</span>
    <a href="request_leave.php" class="btn btn-teal" style="margin-left:auto;font-size:.78rem;padding:6px 14px">
      <i class="fa-solid fa-plus"></i> Request Leave
    </a>
  </div>

  <?php if (empty($schedules)): ?>
    <div class="empty">
      <i class="fa-regular fa-calendar-xmark"></i>
      <strong>No shifts assigned yet</strong>
      Your Department Head has not assigned your working schedule yet. Please contact them.
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th>Day</th>
          <th>Start Time</th>
          <th>End Time</th>
          <th>Max Patients</th>
          <th>Status</th>
          <th>Assigned By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($schedules as $row):
          $is_wk = in_array($row['working_day'], ['Saturday','Sunday']);
        ?>
        <tr>
          <td><span class="day-pill <?= $is_wk?'wk':'' ?>"><?= $row['working_day'] ?></span></td>
          <td><span class="time-val"><?= date("h:i A", strtotime($row['start_time'])) ?></span></td>
          <td><span class="time-val"><?= date("h:i A", strtotime($row['end_time'])) ?></span></td>
          <td style="font-family:var(--mono);font-weight:700"><?= $row['max_patients'] ?></td>
          <td>
            <span class="avail-badge <?= $row['is_available']?'on':'off' ?>">
              <span class="av-dot"></span>
              <?= $row['is_available'] ? 'Active' : 'Suspended' ?>
            </span>
          </td>
          <td><span class="assigned-by"><?= htmlspecialchars($row['assigned_by_name'] ?? 'Department Head') ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Upcoming Leaves -->
<div class="card">
  <div class="card-title">
    <i class="fa-regular fa-clock"></i>
    Upcoming Leave Requests
    <span class="badge badge-green"><?= count($upcoming_leaves) ?> upcoming</span>
  </div>

  <?php if (empty($upcoming_leaves)): ?>
    <div class="empty" style="padding:24px 20px">
      <i class="fa-regular fa-clock"></i>
      No upcoming leave requests.
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Date</th><th>Day</th><th>Reason</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($upcoming_leaves as $lv): ?>
        <tr>
          <td><span class="time-val"><?= date("d M Y", strtotime($lv['leave_date'])) ?></span></td>
          <td style="color:var(--mut);font-size:.82rem"><?= date("l", strtotime($lv['leave_date'])) ?></td>
          <td style="font-size:.82rem;color:var(--mut)"><?= htmlspecialchars($lv['reason'] ?: '—') ?></td>
          <td><span class="leave-badge <?= $lv['status'] ?>"><?= ucfirst($lv['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</div>
</body>
</html>