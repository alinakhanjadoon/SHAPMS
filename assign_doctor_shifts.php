<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'department_head'])) {
    header("Location: ../auth/login.php");
    exit();
}
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
$logged_user_id = (int)$_SESSION['user_id'];
$logged_role    = $_SESSION['role'];

$dept_head_dept_id = null;
if ($logged_role === 'department_head') {
    $dh = $conn->prepare("SELECT department_id FROM users WHERE user_id = ? LIMIT 1");
    $dh->bind_param("i", $logged_user_id);
    $dh->execute(); $dh->bind_result($dept_head_dept_id); $dh->fetch(); $dh->close();
    if (!$dept_head_dept_id) die("Department head profile not found!");
}
if (isset($_GET['delete'], $_GET['doctor_id'])) {
    $del_id = (int)$_GET['delete']; $del_doc = (int)$_GET['doctor_id'];
    $st = $conn->prepare("DELETE FROM doctor_schedule WHERE schedule_id=? AND doctor_id=?");
    $st->bind_param("ii", $del_id, $del_doc); $st->execute(); $st->close();
    $conn->query("UPDATE appointment_slots SET status='expired' WHERE doctor_id=$del_doc AND slot_date>=CURDATE() AND is_booked=0");
    header("Location: assign_doctor_shifts.php?doc=$del_doc&msg=deleted"); exit();
}
if (isset($_GET['toggle'], $_GET['doctor_id'])) {
    $tog_id = (int)$_GET['toggle']; $tog_doc = (int)$_GET['doctor_id'];
    $conn->query("UPDATE doctor_schedule SET is_available=NOT is_available WHERE schedule_id=$tog_id AND doctor_id=$tog_doc");
    header("Location: assign_doctor_shifts.php?doc=$tog_doc&msg=toggled"); exit();
}
$error = $success = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $working_day = $_POST['working_day'] ?? '';
    $start_time  = $_POST['start_time']  ?? '';
    $end_time    = $_POST['end_time']    ?? '';
    $max_patients = min(30, max(1, (int)($_POST['max_patients'] ?? 30)));
    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    if (!$doctor_id || !$working_day || !$start_time || !$end_time) $error = "All fields are required.";
    elseif (!in_array($working_day, $valid_days)) $error = "Invalid day selected.";
    elseif ($start_time >= $end_time) $error = "End time must be after start time.";
    else {
        $chk = $conn->prepare("SELECT schedule_id FROM doctor_schedule WHERE doctor_id=? AND working_day=?");
        $chk->bind_param("is", $doctor_id, $working_day); $chk->execute();
        $existing = $chk->get_result()->fetch_assoc(); $chk->close();
        if ($existing) {
            $st = $conn->prepare("UPDATE doctor_schedule SET start_time=?,end_time=?,max_patients=?,assigned_by=?,is_available=1 WHERE schedule_id=? AND doctor_id=?");
            $st->bind_param("ssiiii", $start_time, $end_time, $max_patients, $logged_user_id, $existing['schedule_id'], $doctor_id);
        } else {
            $st = $conn->prepare("INSERT INTO doctor_schedule (doctor_id,working_day,start_time,end_time,max_patients,is_available,assigned_by) VALUES (?,?,?,?,?,1,?)");
            $st->bind_param("isssii", $doctor_id, $working_day, $start_time, $end_time, $max_patients, $logged_user_id);
        }
        if ($st->execute()) {
            $safe_day = $conn->real_escape_string($working_day);
            $conn->query("UPDATE appointment_slots SET status='expired' WHERE doctor_id=$doctor_id AND slot_date>=CURDATE() AND is_booked=0 AND DAYNAME(slot_date)='$safe_day'");

            /* Notify the doctor about shift assignment */
            require_once __DIR__ . '/includes/notifications_functions.php';
            $doc_uid_q = $conn->prepare("SELECT user_id FROM doctors WHERE doctor_id=?");
            $doc_uid_q->bind_param("i", $doctor_id); $doc_uid_q->execute();
            $doc_uid_q->bind_result($doc_user_id); $doc_uid_q->fetch(); $doc_uid_q->close();
            if ($doc_user_id) {
                $shift_time = date("h:i A", strtotime($start_time)) . " – " . date("h:i A", strtotime($end_time));
                createNotification($conn, $doc_user_id, 'doctor', 'shift',
                    'Shift Assigned',
                    "A shift has been assigned for $working_day: $shift_time (max $max_patients patients).",
                    'doctoravailability.php'
                );
            }

            $success = "Shift assigned for $working_day successfully!";
        } else { $error = "DB Error: " . $st->error; }
        $st->close();
    }
}
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') $success = "Schedule deleted.";
    if ($_GET['msg'] === 'toggled') $success = "Availability updated.";
}
if ($logged_role === 'department_head') {
    $dr = $conn->prepare("SELECT d.doctor_id, u.full_name, dp.department_name, u.gender FROM doctors d JOIN users u ON d.user_id=u.user_id LEFT JOIN departments dp ON u.department_id=dp.department_id WHERE u.department_id=? AND u.status='active' ORDER BY u.full_name");
    $dr->bind_param("i", $dept_head_dept_id);
} else {
    $dr = $conn->prepare("SELECT d.doctor_id, u.full_name, dp.department_name, u.gender FROM doctors d JOIN users u ON d.user_id=u.user_id LEFT JOIN departments dp ON u.department_id=dp.department_id WHERE u.status='active' ORDER BY dp.department_name, u.full_name");
}
$dr->execute(); $doctors = $dr->get_result()->fetch_all(MYSQLI_ASSOC); $dr->close();
$selected_doctor = isset($_GET['doc']) ? (int)$_GET['doc'] : (isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0);
$schedules = []; $sel_doc_info = null;
if ($selected_doctor) {
    $sq = $conn->prepare("SELECT ds.schedule_id, ds.working_day, ds.start_time, ds.end_time, ds.max_patients, ds.is_available, u.full_name AS assigned_by_name FROM doctor_schedule ds LEFT JOIN users u ON ds.assigned_by=u.user_id WHERE ds.doctor_id=? ORDER BY FIELD(ds.working_day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
    $sq->bind_param("i", $selected_doctor); $sq->execute();
    $schedules = $sq->get_result()->fetch_all(MYSQLI_ASSOC); $sq->close();
    foreach ($doctors as $d) { if ((int)$d['doctor_id'] === $selected_doctor) { $sel_doc_info = $d; break; } }
}
$days_order = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$schedule_map = [];
foreach ($schedules as $s) { $schedule_map[$s['working_day']] = $s; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assign Doctor Shifts — Zaman Medical Command Centre</title>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Neue+Montreal:wght@300;400;500;600&family=Space+Grotesk:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════
   DESIGN TOKENS — Bioluminescent Deep Space
   (matched to Zaman Medical Command Centre)
═══════════════════════════════════════════════ */
:root{
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

  --navy:var(--txt-0);--navy2:var(--plasma);--blue:var(--plasma);--blue2:var(--nova);
  --blue-lt:var(--nova2);--pal:rgba(0,198,167,.1);--pal2:rgba(0,198,167,.06);--sky:var(--surface);
  --white:var(--lift);--bdr:rgba(255,255,255,.08);--bdr2:rgba(255,255,255,.14);
  --t1:var(--txt-0);--t2:var(--txt-1);--t3:var(--txt-2);
  --ok:var(--plasma);--err:var(--pulse);
  --sans:'Plus Jakarta Sans',sans-serif;
  --ff-disp:'Space Grotesk',sans-serif;
  --r:16px;--rs:10px;
  --sh:0 10px 30px rgba(0,0,0,.35);
  --shm:0 16px 40px rgba(0,198,167,.12);

  --ease-expo: cubic-bezier(0.19, 1, 0.22, 1);
  --ease-back: cubic-bezier(0.34, 1.56, 0.64, 1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--void);color:var(--txt-0);min-height:100vh;padding-bottom:60px;position:relative;overflow-x:hidden}
body::before{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:
    radial-gradient(ellipse 1200px 900px at 10% 0%,   rgba(0,198,167,.09) 0%, transparent 55%),
    radial-gradient(ellipse 800px 800px  at 90% 90%,  rgba(77,126,255,.10) 0%, transparent 50%),
    radial-gradient(ellipse 600px 500px  at 50% 40%,  rgba(155,109,255,.05) 0%, transparent 50%);
}
body::after{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;opacity:.025;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  background-repeat:repeat;background-size:180px;
}

/* NAV */
.topbar{background:rgba(8,13,26,.88);backdrop-filter:blur(40px) saturate(180%);-webkit-backdrop-filter:blur(40px) saturate(180%);display:flex;align-items:stretch;min-height:66px;padding:0 26px;border-bottom:1px solid rgba(255,255,255,.06);position:relative;z-index:10}
.topbar::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--plasma) 0%,var(--nova) 50%,var(--plasma) 100%);opacity:.6}
.tb-brand{display:flex;align-items:center;gap:12px;padding:12px 0;margin-right:28px}
.tb-logo{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--plasma),var(--nova));display:grid;place-items:center;box-shadow:0 0 20px rgba(0,198,167,.35),0 0 40px rgba(0,198,167,.15)}
.tb-logo svg{width:20px;height:20px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.tb-t1{font-family:var(--ff-disp);font-size:1rem;font-weight:700;color:#fff;line-height:1.1}
.tb-t2{font-size:.68rem;color:var(--txt-2);font-weight:400;margin-top:2px;letter-spacing:.3px}
.tb-nav{display:flex;align-items:center;gap:1px;flex:1}
.nv{padding:0 15px;height:100%;display:flex;align-items:center;font-size:.78rem;font-weight:700;color:var(--txt-2);text-decoration:none;border-bottom:2px solid transparent;transition:all .18s;cursor:pointer}
.nv:hover{color:var(--txt-0);background:rgba(255,255,255,.04)}
.nv.on{color:#fff;border-bottom-color:var(--plasma);text-shadow:0 0 12px rgba(0,198,167,.5)}
.tb-r{display:flex;align-items:center;gap:9px;margin-left:auto;padding-bottom:2px}
.flt-lbl{font-size:.67rem;color:var(--txt-2);margin-right:3px}
.flt-btn{padding:6px 13px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:100px;color:var(--txt-1);font-size:.73rem;font-weight:700;font-family:var(--sans);cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .18s}
.flt-btn svg{width:9px;height:9px;stroke:var(--txt-2);fill:none;stroke-width:2.5}
.flt-btn:hover{background:rgba(0,198,167,.1);border-color:rgba(0,198,167,.25);color:var(--plasma)}
.av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--plasma),var(--nova));display:grid;place-items:center;font-size:.8rem;font-weight:800;color:#fff;box-shadow:0 0 16px rgba(0,198,167,.3)}
.role-chip{font-size:.62rem;font-weight:800;padding:4px 11px;border-radius:100px;background:rgba(0,198,167,.1);color:var(--plasma);border:1px solid rgba(0,198,167,.22);text-transform:uppercase;letter-spacing:.06em}

/* WRAP */
.wrap{max-width:1040px;margin:0 auto;padding:24px 18px;position:relative;z-index:1}

/* SEC HEAD */
.sh{display:flex;align-items:center;gap:9px;margin-bottom:16px}
.sh h2{font-family:var(--ff-disp);font-size:1rem;font-weight:700;color:var(--txt-0);letter-spacing:-.01em}
.sh-line{flex:1;height:1px;background:rgba(255,255,255,.07)}
.sh-step{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--plasma),var(--nova));color:#fff;font-size:.68rem;font-weight:800;display:grid;place-items:center;flex-shrink:0;box-shadow:0 0 14px rgba(0,198,167,.4)}
.sh-ct{font-size:.7rem;font-weight:700;color:var(--txt-2)}

/* ALERTS */
.alert{padding:13px 16px;border-radius:var(--rs);font-size:.83rem;font-weight:600;margin-bottom:18px;border:1px solid;display:flex;align-items:center;gap:9px;animation:ai .25s ease;backdrop-filter:blur(10px)}
@keyframes ai{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.alert svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
.alert.ok{background:rgba(0,198,167,.08);border-color:rgba(0,198,167,.25);color:var(--plasma2)}
.alert.er{background:rgba(255,107,107,.08);border-color:rgba(255,107,107,.25);color:var(--pulse)}

/* KPI */
.kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
.kc{background:rgba(15,24,40,.7);backdrop-filter:blur(20px);border-radius:var(--r);border:1px solid rgba(255,255,255,.07);box-shadow:var(--sh);padding:18px 20px;display:flex;align-items:center;gap:14px;position:relative;overflow:hidden;transition:transform .25s var(--ease-expo),box-shadow .25s var(--ease-expo),border-color .25s}
.kc:hover{transform:translateY(-3px);border-color:rgba(0,198,167,.25);box-shadow:0 16px 40px rgba(0,198,167,.1)}
.kc::before{content:'';position:absolute;top:-40px;right:-40px;width:120px;height:120px;border-radius:50%;background:var(--plasma);filter:blur(50px);opacity:.08;pointer-events:none}
.kc-ico{width:48px;height:48px;border-radius:14px;background:rgba(0,198,167,.1);display:grid;place-items:center;flex-shrink:0;box-shadow:0 0 20px rgba(0,198,167,.15)}
.kc-ico svg{width:22px;height:22px;stroke:var(--plasma);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.kc-val{font-family:var(--ff-disp);font-size:1.9rem;font-weight:800;color:var(--txt-0);line-height:1;text-shadow:0 0 24px rgba(0,198,167,.3)}
.kc-val span{font-size:.95rem;font-weight:700;color:var(--plasma)}
.kc-lbl{font-size:.66rem;font-weight:700;color:var(--txt-2);text-transform:uppercase;letter-spacing:.07em;margin-top:4px}
.kc-ring{position:relative;width:48px;height:48px;flex-shrink:0;margin-left:auto}
.kc-ring svg{width:48px;height:48px;transform:rotate(-90deg)}
.kc-ring-pct{position:absolute;inset:0;display:grid;place-items:center;font-size:.7rem;font-weight:800;color:var(--plasma)}

/* CARD */
.card{background:rgba(15,24,40,.7);backdrop-filter:blur(20px);border-radius:var(--r);border:1px solid rgba(255,255,255,.07);box-shadow:var(--sh);padding:22px 24px;margin-bottom:18px;position:relative;overflow:hidden}
.ch{display:flex;align-items:center;gap:9px;padding-bottom:15px;margin-bottom:17px;border-bottom:1px solid rgba(255,255,255,.06)}
.ch-ic{width:28px;height:28px;border-radius:8px;background:rgba(0,198,167,.1);display:grid;place-items:center;flex-shrink:0}
.ch-ic svg{width:14px;height:14px;stroke:var(--plasma);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ch h3{font-family:var(--ff-disp);font-size:.9rem;font-weight:700;color:var(--txt-0)}
.cbadge{margin-left:auto;font-size:.62rem;font-weight:800;padding:4px 11px;border-radius:100px;background:rgba(0,198,167,.08);color:var(--plasma);border:1px solid rgba(0,198,167,.2);text-transform:uppercase;letter-spacing:.05em}

/* DOCTOR GRID */
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:10px}
.dc{padding:13px 14px;border:1px solid rgba(255,255,255,.08);border-radius:var(--rs);cursor:pointer;text-decoration:none;display:block;background:rgba(255,255,255,.02);transition:all .22s var(--ease-expo)}
.dc:hover{border-color:rgba(0,198,167,.3);background:rgba(0,198,167,.05);transform:translateY(-3px);box-shadow:var(--shm)}
.dc.on{border-color:rgba(0,198,167,.4);background:rgba(0,198,167,.09);box-shadow:0 0 0 1px rgba(0,198,167,.3),0 0 20px rgba(0,198,167,.12)}
.dav{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--plasma),var(--nova));display:grid;place-items:center;font-size:.8rem;font-weight:800;color:#fff;margin-bottom:9px;box-shadow:0 0 14px rgba(0,198,167,.3)}
.dc.on .dav{background:linear-gradient(135deg,var(--nova),var(--violet))}
.dn{font-weight:700;font-size:.8rem;color:var(--txt-0);line-height:1.3}
.dd{font-size:.65rem;font-weight:700;color:var(--nova2);margin-top:3px}

/* WEEK CHART */
.wc-wrap{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.06);border-radius:var(--rs);padding:16px;margin-bottom:18px}
.wc-ttl{font-size:.7rem;font-weight:800;color:var(--txt-1);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px}
.wc{display:flex;align-items:flex-end;gap:7px;height:88px}
.wcc{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%;justify-content:flex-end}
.wcbw{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center}
.wcb{width:100%;border-radius:4px 4px 0 0;transition:height .55s cubic-bezier(.4,0,.2,1);min-height:4px}
.wcb.a{background:linear-gradient(180deg,var(--plasma2) 0%,var(--plasma) 100%);box-shadow:0 0 10px rgba(0,198,167,.35)}
.wcb.i{background:rgba(255,255,255,.1)}
.wcb.w{background:linear-gradient(180deg,var(--nova2) 0%,var(--nova) 100%);opacity:.8;box-shadow:0 0 10px rgba(77,126,255,.3)}
.wcb.e{background:rgba(255,255,255,.05);height:5px!important}
.wcpct{font-size:.59rem;font-weight:800;color:var(--plasma);line-height:1}
.wclbl{font-size:.6rem;font-weight:800;color:var(--txt-2);text-transform:uppercase}
.wclbl.hs{color:var(--plasma)}
.wclbl.we{color:var(--nova2)}

/* LEGEND */
.legend{display:flex;gap:14px;margin-top:12px;flex-wrap:wrap}
.leg-i{display:flex;align-items:center;gap:5px;font-size:.67rem;font-weight:700;color:var(--txt-2)}
.leg-dot{width:8px;height:8px;border-radius:2px}

/* TWO COL */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}

/* FORM */
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.fg{display:flex;flex-direction:column;gap:6px}
.fg.full{grid-column:1/-1}
.fg label{font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-1)}
.fg select,.fg input[type=time],.fg input[type=number]{padding:10px 13px;border:1px solid rgba(255,255,255,.09);border-radius:var(--rs);font-family:var(--sans);font-size:.84rem;color:var(--txt-0);background:rgba(255,255,255,.03);outline:none;transition:border .2s,box-shadow .2s,background .2s;width:100%;appearance:none;color-scheme:dark}
.fg select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238BA3C2' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:30px}
.fg select:focus,.fg input:focus{border-color:var(--plasma);box-shadow:0 0 0 3px rgba(0,198,167,.15);background:rgba(0,198,167,.04)}
.fg select option{background:var(--surface);color:var(--txt-0)}
.hint{font-size:.69rem;color:var(--txt-2)}

/* DOC HDR */
.dh{display:flex;align-items:center;gap:12px;padding:13px 15px;background:rgba(0,198,167,.05);border-radius:var(--rs);margin-bottom:14px;border:1px solid rgba(0,198,167,.15)}
.dhav{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--plasma),var(--nova));display:grid;place-items:center;font-size:1rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 0 18px rgba(0,198,167,.35)}
.dhn{font-family:var(--ff-disp);font-weight:700;font-size:.9rem;color:var(--txt-0)}
.dhd{font-size:.68rem;font-weight:700;color:var(--nova2);margin-top:3px}
.dhm{display:flex;gap:5px;margin-top:6px}
.mc{font-size:.6rem;font-weight:700;padding:3px 9px;border-radius:100px;background:rgba(255,255,255,.05);color:var(--txt-1);border:1px solid rgba(255,255,255,.08)}

/* INFO */
.ib{background:rgba(77,126,255,.06);border:1px solid rgba(77,126,255,.2);border-left:3px solid var(--nova);border-radius:0 var(--rs) var(--rs) 0;padding:10px 13px;font-size:.77rem;font-weight:600;color:var(--nova2);margin-bottom:14px;display:flex;gap:7px;align-items:flex-start}
.ib svg{width:13px;height:13px;flex-shrink:0;margin-top:2px;stroke:var(--nova2);fill:none;stroke-width:2}

/* BTNS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 20px;border-radius:var(--rs);font-family:var(--sans);font-size:.83rem;font-weight:800;border:none;cursor:pointer;transition:all .2s var(--ease-back);text-decoration:none}
.btn:active{transform:scale(.97)}
.btn-p{background:linear-gradient(135deg,var(--plasma) 0%,var(--nova) 100%);color:#fff;width:100%;justify-content:center;box-shadow:0 4px 20px rgba(0,198,167,.3);margin-top:4px}
.btn-p:hover{box-shadow:0 8px 28px rgba(0,198,167,.45);transform:translateY(-2px)}
.btn-p svg{width:13px;height:13px;stroke:#fff;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.btn-sm{padding:6px 12px;font-size:.7rem;border-radius:8px}
.btn-del{background:rgba(255,107,107,.1);color:var(--pulse);border:1px solid rgba(255,107,107,.22)}
.btn-del:hover{background:rgba(255,107,107,.22);box-shadow:0 0 16px rgba(255,107,107,.2);transform:translateY(-2px) scale(1.02)}
.btn-del svg{width:11px;height:11px;stroke:var(--pulse);fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}

/* TABLE */
.tbl{width:100%;border-collapse:collapse}
.tbl thead tr{background:rgba(0,0,0,.15)}
.tbl th{padding:10px 12px;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-2);text-align:left;border-bottom:1px solid rgba(255,255,255,.07)}
.tbl td{padding:12px 12px;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;color:var(--txt-1)}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.dp{display:inline-block;font-size:.65rem;font-weight:800;padding:4px 11px;border-radius:100px;background:rgba(0,198,167,.08);color:var(--plasma);border:1px solid rgba(0,198,167,.2)}
.dp.we{background:rgba(155,109,255,.08);color:var(--violet);border-color:rgba(155,109,255,.22)}
.tv{font-weight:700;font-size:.79rem;color:var(--txt-0)}
.td2{font-size:.68rem;color:var(--txt-2)}
.cpw{display:flex;align-items:center;gap:7px}
.cpb{flex:1;height:5px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;max-width:46px}
.cpf{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--plasma),var(--nova))}
.cpn{font-size:.75rem;font-weight:800;color:var(--txt-0);min-width:16px}
.av-b{display:inline-flex;align-items:center;gap:5px;font-size:.64rem;font-weight:800;padding:4px 10px;border-radius:100px;cursor:pointer;text-decoration:none;transition:all .2s var(--ease-back);text-transform:uppercase;letter-spacing:.04em}
.av-b:hover{transform:scale(1.05)}
.av-dot{width:6px;height:6px;border-radius:50%}
.av-b.on{background:rgba(0,198,167,.1);color:var(--plasma2);border:1px solid rgba(0,198,167,.25)}
.av-b.off{background:rgba(255,107,107,.1);color:var(--pulse);border:1px solid rgba(255,107,107,.25)}
.av-b.on .av-dot{background:var(--plasma);box-shadow:0 0 6px var(--plasma)}
.av-b.off .av-dot{background:var(--pulse);box-shadow:0 0 6px var(--pulse)}

/* EMPTY */
.empty{text-align:center;padding:38px 20px;color:var(--txt-2);font-size:.84rem;font-weight:600}
.empty svg{width:36px;height:36px;stroke:rgba(255,255,255,.15);margin:0 auto 11px;display:block;fill:none;stroke-width:1.5}
.selprompt{text-align:center;padding:54px 20px;color:var(--txt-1)}
.selprompt svg{width:54px;height:54px;stroke:rgba(255,255,255,.15);opacity:.8;margin:0 auto 14px;display:block;fill:none;stroke-width:1.5}
.selprompt strong{display:block;font-family:var(--ff-disp);font-size:1rem;font-weight:700;color:var(--txt-0);margin-bottom:5px}

/* Scrollbar */
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(0,198,167,.2);border-radius:99px}

@media(max-width:700px){
  .two-col,.fg2{grid-template-columns:1fr}
  .fg.full{grid-column:1}
  .kpi-row{grid-template-columns:1fr}
  .doc-grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
  .topbar{padding:0 14px}
  .tb-nav{display:none}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="tb-brand">
    <div class="tb-logo"><svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
    <div><div class="tb-t1">SHAPMS</div><div class="tb-t2">Hospital Personnel Management</div></div>
  </div>
  <div class="tb-nav">
    <span class="nv">Dashboard</span>
    <span class="nv on">Doctor Shifts</span>
    <span class="nv">Appointments</span>
    <span class="nv">Reports</span>
  </div>
  <div class="tb-r">
    <span class="flt-lbl">● Filtro por Área</span>
    <?php if ($logged_role === 'admin'): ?>
    <button class="flt-btn">Depto. 1 <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
    <button class="flt-btn">Depto. 2 <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
    <?php endif; ?>
    <span class="role-chip"><?= htmlspecialchars($logged_role) ?></span>
    <div class="av">A</div>
  </div>
</div>

<div class="wrap">

<?php if ($success): ?>
<div class="alert ok">
  <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert er">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- STEP 1 -->
<div class="sh">
  <div class="sh-step">1</div>
  <h2>Select a Doctor</h2>
  <div class="sh-line"></div>
  <span class="sh-ct"><?= count($doctors) ?> active staff</span>
</div>

<div class="card">
  <div class="ch">
    <div class="ch-ic"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
    <h3>Medical Staff Directory</h3>
    <span class="cbadge"><?= count($doctors) ?> Doctors</span>
  </div>
  <?php if (empty($doctors)): ?>
    <div class="empty">
      <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      No active doctors found in your department.
    </div>
  <?php else: ?>
    <div class="doc-grid">
      <?php foreach ($doctors as $d): ?>
        <a href="?doc=<?= $d['doctor_id'] ?>" class="dc <?= ($selected_doctor===(int)$d['doctor_id'])?'on':'' ?>">
          <div class="dav"><?= strtoupper(substr($d['full_name'],0,1)) ?></div>
          <div class="dn"><?= htmlspecialchars($d['full_name']) ?></div>
          <div class="dd"><?= htmlspecialchars($d['department_name'] ?? 'No Dept') ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($selected_doctor && $sel_doc_info):
  $active_days = count(array_filter($schedules, fn($s) => $s['is_available']));
  $total_days  = count($schedules);
  $total_cap   = array_sum(array_column($schedules, 'max_patients'));
  $sched_pct   = round(($total_days/7)*100);
  $active_pct  = $total_days > 0 ? round(($active_days/$total_days)*100) : 0;
  $circ = 2 * M_PI * 22; // circumference for r=22
?>

<!-- KPIs -->
<div class="kpi-row">
  <div class="kc">
    <div class="kc-ico"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg></div>
    <div><div class="kc-val"><?= $total_days ?><span>/7</span></div><div class="kc-lbl">Days Scheduled</div></div>
    <div class="kc-ring">
      <svg viewBox="0 0 52 52">
        <circle cx="26" cy="26" r="22" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="5"/>
        <circle cx="26" cy="26" r="22" fill="none" stroke="<?= $sched_pct >= 70 ? '#00C6A7' : '#4D7EFF' ?>" stroke-width="5"
          stroke-dasharray="<?= round(($sched_pct/100)*$circ) ?> <?= round($circ) ?>" stroke-linecap="round"/>
      </svg>
      <div class="kc-ring-pct"><?= $sched_pct ?>%</div>
    </div>
  </div>
  <div class="kc">
    <div class="kc-ico"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
    <div><div class="kc-val"><?= $active_days ?></div><div class="kc-lbl">Active Shifts</div></div>
    <div class="kc-ring">
      <svg viewBox="0 0 52 52">
        <circle cx="26" cy="26" r="22" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="5"/>
        <circle cx="26" cy="26" r="22" fill="none" stroke="#00C6A7" stroke-width="5"
          stroke-dasharray="<?= round(($active_pct/100)*$circ) ?> <?= round($circ) ?>" stroke-linecap="round"/>
      </svg>
      <div class="kc-ring-pct" style="color:#00C6A7"><?= $active_pct ?>%</div>
    </div>
  </div>
  <div class="kc">
    <div class="kc-ico"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    <div><div class="kc-val"><?= $total_cap ?></div><div class="kc-lbl">Weekly Capacity</div></div>
  </div>
</div>

<!-- WEEKLY CHART -->
<div class="wc-wrap">
  <div class="wc-ttl">Weekly Schedule Overview — <?= htmlspecialchars($sel_doc_info['full_name']) ?></div>
  <div class="wc">
    <?php
    $days_full  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $days_short = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $max_cap = max(1, max(array_map(fn($d)=>$schedule_map[$d]['max_patients']??0, $days_full)));
    foreach ($days_full as $i => $day):
      $s = $schedule_map[$day] ?? null;
      $pct = $s ? round(($s['max_patients']/$max_cap)*100) : 0;
      $is_wk = in_array($day,['Saturday','Sunday']);
      $cls = $s ? ($s['is_available'] ? ($is_wk?'w':'a') : 'i') : 'e';
      $lbl_c = $s ? ($is_wk?'we':'hs') : '';
    ?>
    <div class="wcc">
      <?php if ($s && $pct > 0): ?><div class="wcpct"><?= $s['max_patients'] ?></div><?php endif; ?>
      <div class="wcbw"><div class="wcb <?= $cls ?>" style="height:<?= max(5,$pct) ?>%"></div></div>
      <div class="wclbl <?= $lbl_c ?>"><?= $days_short[$i] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="legend">
    <div class="leg-i"><div class="leg-dot" style="background:#00C6A7"></div>Weekday active</div>
    <div class="leg-i"><div class="leg-dot" style="background:#4D7EFF;opacity:.8"></div>Weekend</div>
    <div class="leg-i"><div class="leg-dot" style="background:rgba(255,255,255,.15)"></div>Inactive</div>
    <div class="leg-i"><div class="leg-dot" style="background:rgba(255,255,255,.06)"></div>Not set</div>
  </div>
</div>

<!-- STEP 2 -->
<div class="sh">
  <div class="sh-step">2</div>
  <h2>Assign / Manage Shifts</h2>
  <div class="sh-line"></div>
</div>

<div class="two-col">
  <!-- FORM -->
  <div class="card">
    <div class="ch">
      <div class="ch-ic"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
      <h3>Assign Shift</h3>
      <span class="cbadge">Max 30 pts</span>
    </div>
    <div class="dh">
      <div class="dhav"><?= strtoupper(substr($sel_doc_info['full_name'],0,1)) ?></div>
      <div>
        <div class="dhn"><?= htmlspecialchars($sel_doc_info['full_name']) ?></div>
        <div class="dhd"><?= htmlspecialchars($sel_doc_info['department_name']??'') ?></div>
        <div class="dhm">
          <span class="mc"><?= ucfirst($sel_doc_info['gender']??'—') ?></span>
          <span class="mc">ID #<?= $selected_doctor ?></span>
        </div>
      </div>
    </div>
    <div class="ib">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      Saving an existing day will update it. Future unbooked slots are auto-cleared.
    </div>
    <form method="POST">
      <input type="hidden" name="doctor_id" value="<?= $selected_doctor ?>">
      <div class="fg2">
        <div class="fg">
          <label>Working Day</label>
          <select name="working_day" required>
            <option value="">— Select Day —</option>
            <?php foreach ($days_order as $day): ?>
              <option value="<?= $day ?>" <?= (($_POST['working_day']??'')===$day)?'selected':'' ?>><?= $day ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Max Patients</label>
          <input type="number" name="max_patients" min="1" max="30" value="<?= htmlspecialchars($_POST['max_patients']??'30') ?>" required>
          <span class="hint">1 – 30 per session</span>
        </div>
        <div class="fg">
          <label>Start Time</label>
          <input type="time" name="start_time" value="<?= htmlspecialchars($_POST['start_time']??'09:00') ?>" required>
        </div>
        <div class="fg">
          <label>End Time</label>
          <input type="time" name="end_time" value="<?= htmlspecialchars($_POST['end_time']??'17:00') ?>" required>
        </div>
        <div class="fg full">
          <button type="submit" class="btn btn-p">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Shift
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- TABLE -->
  <div class="card">
    <div class="ch">
      <div class="ch-ic"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
      <h3>Current Schedule</h3>
      <span class="cbadge"><?= count($schedules) ?> Days</span>
    </div>
    <?php if (empty($schedules)): ?>
      <div class="empty">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        No shifts assigned yet.
      </div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Day</th><th>Hours</th><th>Cap.</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($schedules as $s): $wk = in_array($s['working_day'],['Saturday','Sunday']); ?>
          <tr>
            <td><span class="dp <?= $wk?'we':'' ?>"><?= substr($s['working_day'],0,3) ?></span></td>
            <td><div class="tv"><?= date("h:i A",strtotime($s['start_time'])) ?></div><div class="td2"><?= date("h:i A",strtotime($s['end_time'])) ?></div></td>
            <td><div class="cpw"><div class="cpb"><div class="cpf" style="width:<?= round(($s['max_patients']/30)*100) ?>%"></div></div><span class="cpn"><?= $s['max_patients'] ?></span></div></td>
            <td><a href="?toggle=<?= $s['schedule_id'] ?>&doctor_id=<?= $selected_doctor ?>&doc=<?= $selected_doctor ?>" class="av-b <?= $s['is_available']?'on':'off' ?>"><span class="av-dot"></span><?= $s['is_available']?'Active':'Off' ?></a></td>
            <td><a href="?delete=<?= $s['schedule_id'] ?>&doctor_id=<?= $selected_doctor ?>&doc=<?= $selected_doctor ?>" class="btn btn-sm btn-del" onclick="return confirm('Delete <?= $s['working_day'] ?> schedule?')"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="selprompt">
    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    <strong>Select a doctor above</strong>
    Click any doctor card to view and assign their weekly shifts.
  </div>
</div>
<?php endif; ?>

</div>
</body>
</html>