<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php"); exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$user_id = (int)$_SESSION['user_id'];

/* ===== GET DOCTOR ID ===== */
$st = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id=? LIMIT 1");
$st->bind_param("i", $user_id); $st->execute();
$st->bind_result($doctor_id); $st->fetch(); $st->close();
if (!$doctor_id) die("Doctor profile not found! user_id=" . $user_id);

/* ===== HANDLE CANCEL (doctor cancels own pending request) ===== */
if (isset($_GET['cancel'])) {
    $cancel_id = (int)$_GET['cancel'];
    $st = $conn->prepare("DELETE FROM doctor_leaves WHERE leave_id=? AND doctor_id=? AND status='pending'");
    $st->bind_param("ii", $cancel_id, $doctor_id);
    $st->execute(); $st->close();
    header("Location: request_leave.php?msg=cancelled"); exit();
}

/* ===== HANDLE POST — submit leave request ===== */
$error = $success = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_date = $_POST['leave_date'] ?? '';
    $reason     = trim($_POST['reason'] ?? '');

    if (!$leave_date)
        $error = "Please select a leave date.";
    elseif ($leave_date < date('Y-m-d'))
        $error = "Cannot request leave for a past date.";
    else {
        $chk = $conn->prepare("SELECT leave_id FROM doctor_leaves WHERE doctor_id=? AND leave_date=? AND status IN ('pending','approved')");
        $chk->bind_param("is", $doctor_id, $leave_date);
        $chk->execute();
        $dup = $chk->get_result()->fetch_assoc(); $chk->close();

        if ($dup) {
            $error = "You already have a leave request for this date.";
        } else {
            $ins = $conn->prepare("INSERT INTO doctor_leaves (doctor_id, leave_date, reason, status) VALUES (?,?,?,'pending')");
            $ins->bind_param("iss", $doctor_id, $leave_date, $reason);
            if ($ins->execute()) {
                $success = "Leave request submitted for " . date("d M Y", strtotime($leave_date)) . ". Awaiting approval.";

                /* Notify patients who have appointments on that date */
                require_once __DIR__ . '/includes/notifications_functions.php';
                $formatted_date = date("d M Y", strtotime($leave_date));

                $pat_res = $conn->prepare("
                    SELECT a.appointment_id, p.user_id AS patient_user_id
                    FROM appointments a
                    JOIN patients p ON a.patient_id = p.patient_id
                    WHERE a.doctor_id = ? AND DATE(a.appointment_date) = ?
                    AND a.status IN ('scheduled','approved')
                ");
                $pat_res->bind_param("is", $doctor_id, $leave_date);
                $pat_res->execute();
                $pat_rows = $pat_res->get_result()->fetch_all(MYSQLI_ASSOC);
                $pat_res->close();

                foreach ($pat_rows as $pat) {
                    createNotification($conn, $pat['patient_user_id'], 'patient', 'leave',
                        'Doctor Leave Request — Pending',
                        'Dr. ' . $doctor_name . ' has requested leave on ' . $formatted_date . '. Your appointment may be affected. Please check back for updates.',
                        'patientnotification.php'
                    );
                }
            } else {
                $error = "DB Error: " . $ins->error;
            }
            $ins->close();
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled') $success = "Leave request cancelled.";

/* ===== FETCH ALL LEAVE REQUESTS FOR THIS DOCTOR ===== */
$lv = $conn->prepare("
    SELECT dl.leave_id, dl.leave_date, dl.reason, dl.status, dl.created_at,
           u.full_name AS approved_by_name
    FROM doctor_leaves dl
    LEFT JOIN users u ON dl.approved_by = u.user_id
    WHERE dl.doctor_id = ?
    ORDER BY dl.leave_date DESC
");
$lv->bind_param("i", $doctor_id);
$lv->execute();
$leaves = $lv->get_result()->fetch_all(MYSQLI_ASSOC); $lv->close();

/* ===== FETCH DOCTOR NAME ===== */
$un = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
$un->bind_param("i", $user_id); $un->execute();
$un->bind_result($doctor_name); $un->fetch(); $un->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request Leave — SHAPMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    /* Two shades of blue + purplish blue theme */
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

    --mono:'IBM Plex Mono',monospace;
    --sans:'Outfit',sans-serif;

    --success-bg: rgba(16,185,129,.12);
    --success-bd: rgba(16,185,129,.35);
    --success-tx: #6ee7b7;

    --danger-bg: rgba(239,68,68,.12);
    --danger-bd: rgba(239,68,68,.35);
    --danger-tx: #fca5a5;

    --warn-bg: rgba(245,158,11,.12);
    --warn-bd: rgba(245,158,11,.35);
    --warn-tx: #fcd34d;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:var(--sans);
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

.wrap{position:relative;z-index:2;max-width:820px;margin:0 auto}

/* PAGE HEADER */
.page-header{
    display:flex;align-items:center;gap:16px;margin-bottom:28px;
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
    padding:20px 26px;
}
.ph-icon{
    width:52px;height:52px;border-radius:16px;
    background:linear-gradient(135deg, var(--b5), var(--acc));
    display:grid;place-items:center;flex-shrink:0;
}
.ph-icon svg{width:26px;height:26px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:1.5rem;font-weight:700;letter-spacing:-.02em;color:#fff}
.ph-sub{font-size:.84rem;color:var(--mut);margin-top:3px}

/* ALERTS */
.alert{padding:13px 18px;border-radius:14px;font-size:.88rem;margin-bottom:20px;border:1px solid;display:flex;align-items:center;gap:10px;backdrop-filter:blur(10px)}
.alert.success{background:var(--success-bg);border-color:var(--success-bd);color:var(--success-tx)}
.alert.error{background:var(--danger-bg);border-color:var(--danger-bd);color:var(--danger-tx)}
.alert svg{width:18px;height:18px;flex-shrink:0}

/* CARDS (glass) */
.card{
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(22px);
    border:1px solid var(--gb);
    border-radius:var(--r);
    box-shadow:0 20px 40px -12px rgba(0,0,0,.35);
    padding:26px;margin-bottom:24px;
    transition:box-shadow .25s;
}
.card:hover{box-shadow:0 24px 48px -12px rgba(0,0,0,.45)}
.card-title{
    font-size:1rem;font-weight:700;color:#fff;
    padding-bottom:14px;margin-bottom:22px;
    border-bottom:1px solid var(--gb);
    display:flex;align-items:center;gap:9px;
}
.card-title svg{color:var(--acc2)}
.badge{
    font-family:var(--mono);font-size:.68rem;
    background:rgba(57,73,171,.22);color:var(--b9);
    padding:3px 10px;border-radius:20px;border:1px solid var(--gb);
}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.fg{display:flex;flex-direction:column;gap:7px}
.fg.full{grid-column:1/-1}
.fg label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
.fg input[type=date],.fg textarea{
    padding:11px 15px;border:1px solid var(--gb);border-radius:10px;
    font-family:var(--sans);font-size:.9rem;color:var(--txt);
    background:rgba(255,255,255,.05);outline:none;
    transition:border .2s,box-shadow .2s,background .2s;width:100%;
    color-scheme:dark;
}
.fg input:focus,.fg textarea:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(126,87,194,.2);background:rgba(255,255,255,.08)}
.fg textarea{resize:vertical;min-height:80px;font-family:var(--sans)}
.fg textarea::placeholder{color:rgba(159,168,218,.55)}
.hint{font-size:.72rem;color:var(--mut)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;font-family:var(--sans);font-size:.88rem;font-weight:600;border:none;cursor:pointer;transition:transform .1s,box-shadow .2s,background .2s;text-decoration:none}
.btn:active{transform:scale(.98)}
.btn-teal{
    background:linear-gradient(135deg, var(--b5), var(--acc));
    color:#fff;width:100%;justify-content:center;margin-top:6px;
    box-shadow:0 10px 24px -8px rgba(126,87,194,.55);
}
.btn-teal:hover{box-shadow:0 14px 30px -8px rgba(126,87,194,.7)}
.btn-sm{padding:6px 12px;font-size:.74rem;border-radius:8px}
.btn-ghost{background:rgba(255,255,255,.06);color:var(--txt);border:1px solid var(--gb)}
.btn-ghost:hover{background:rgba(255,255,255,.1)}

/* TABLE */
.tbl{width:100%;border-collapse:collapse}
.tbl thead tr{background:rgba(255,255,255,.03)}
.tbl th{padding:10px 12px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);text-align:left;border-bottom:1px solid var(--gb)}
.tbl td{padding:12px;font-size:.84rem;border-bottom:1px solid var(--gh);vertical-align:middle;color:var(--txt)}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.03)}

.status-badge{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:700;padding:4px 10px;border-radius:20px}
.status-badge.pending{background:var(--warn-bg);color:var(--warn-tx);border:1px solid var(--warn-bd)}
.status-badge.approved{background:var(--success-bg);color:var(--success-tx);border:1px solid var(--success-bd)}
.status-badge.rejected{background:var(--danger-bg);color:var(--danger-tx);border:1px solid var(--danger-bd)}
.s-dot{width:6px;height:6px;border-radius:50%}
.pending .s-dot{background:#f59e0b}
.approved .s-dot{background:#10b981}
.rejected .s-dot{background:#ef4444}

.date-val{font-family:var(--mono);font-size:.82rem;color:var(--b9)}
.reason-txt{max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--mut);font-size:.8rem}
.empty{text-align:center;padding:40px 20px;color:var(--mut);font-size:.88rem}
.empty svg{width:40px;height:40px;stroke:var(--b8);margin:0 auto 12px;display:block;opacity:.6}

.warn-box{
    background:var(--warn-bg);border:1px solid var(--warn-bd);border-radius:12px;
    padding:12px 16px;font-size:.8rem;color:var(--warn-tx);margin-bottom:18px;
    display:flex;gap:9px;align-items:flex-start;
}
.warn-box svg{width:15px;height:15px;flex-shrink:0;margin-top:2px}

@media(max-width:560px){.form-grid{grid-template-columns:1fr}.fg.full{grid-column:1}}
</style>
</head>
<body>
<div class="wrap">

<div class="page-header">
  <div class="ph-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg></div>
  <div><h1>Request Leave</h1><p class="ph-sub">Submit a leave request — Dr. <?= htmlspecialchars($doctor_name) ?></p></div>
</div>

<?php if ($success): ?>
<div class="alert success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Leave Request</div>
  <div class="warn-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    Your leave must be approved by your Department Head. Any booked appointments on that day will need to be rescheduled.
  </div>
  <form method="POST">
    <div class="form-grid">
      <div class="fg">
        <label>Leave Date</label>
        <input type="date" name="leave_date" value="<?= htmlspecialchars($_POST['leave_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>" required>
        <span class="hint">Cannot be today or a past date</span>
      </div>
      <div class="fg">
        <label>Reason <span style="font-weight:400;color:var(--mut)">(optional)</span></label>
        <textarea name="reason" placeholder="Brief reason for leave..."><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
      </div>
      <div class="fg full">
        <button type="submit" class="btn btn-teal">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Submit Leave Request
        </button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    My Leave Requests
    <span class="badge"><?= count($leaves) ?> total</span>
  </div>
  <?php if (empty($leaves)): ?>
    <div class="empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>No leave requests yet.</div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Date</th><th>Reason</th><th>Status</th><th>Reviewed By</th><th>Submitted</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($leaves as $lv): ?>
        <tr>
          <td><span class="date-val"><?= date("d M Y", strtotime($lv['leave_date'])) ?></span></td>
          <td><span class="reason-txt"><?= htmlspecialchars($lv['reason'] ?: '—') ?></span></td>
          <td><span class="status-badge <?= $lv['status'] ?>"><span class="s-dot"></span><?= ucfirst($lv['status']) ?></span></td>
          <td style="font-size:.8rem;color:var(--mut)"><?= htmlspecialchars($lv['approved_by_name'] ?? '—') ?></td>
          <td><span class="date-val" style="font-size:.78rem"><?= date("d M", strtotime($lv['created_at'])) ?></span></td>
          <td>
            <?php if ($lv['status'] === 'pending'): ?>
              <a href="?cancel=<?= $lv['leave_id'] ?>" class="btn btn-sm btn-ghost" onclick="return confirm('Cancel this leave request?')">Cancel</a>
            <?php else: ?>
              <span style="font-size:.78rem;color:var(--mut)">—</span>
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