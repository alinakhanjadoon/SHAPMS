<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= AUTH ================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

/* ================= DB ================= */
$conn = new mysqli("localhost", "root", "", "SHAPMS");

if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

/* ================= SLOT GENERATOR ================= */
require_once 'generate_slots.php';

/* ================= GET PATIENT ID ================= */
$stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($patient_id);
$stmt->fetch();
$stmt->close();

if (!$patient_id) {
    $stmt = $conn->prepare("INSERT INTO patients (user_id) VALUES (?)");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $patient_id = $stmt->insert_id;
    $stmt->close();
}

/* ================= GET DOCTORS ================= */
$doctors_res = $conn->query("
    SELECT d.doctor_id, u.full_name, d.specialty
    FROM doctors d
    JOIN users u ON d.user_id = u.user_id
    ORDER BY u.full_name
");

$doctors = [];
while ($d = $doctors_res->fetch_assoc()) {
    $doctors[] = $d;
}

/* ================= AJAX: DOCTOR SCHEDULE ================= */
if (isset($_GET['fetch_schedule']) && isset($_GET['doctor_id'])) {

    $did = (int)$_GET['doctor_id'];

    $sq = $conn->prepare("
        SELECT working_day, start_time, end_time, max_patients
        FROM doctor_schedule
        WHERE doctor_id = ?
        AND is_available = 1
        ORDER BY FIELD(
            working_day,
            'Monday','Tuesday','Wednesday',
            'Thursday','Friday','Saturday','Sunday'
        )
    ");

    $sq->bind_param("i", $did);
    $sq->execute();

    $sched_rows = $sq->get_result()->fetch_all(MYSQLI_ASSOC);

    $sq->close();

    header('Content-Type: application/json');
    echo json_encode($sched_rows);
    exit();
}

/* ================= SELECTED DOCTOR + DATE ================= */
$selected_doctor = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$selected_date   = $_GET['date'] ?? date('Y-m-d');

if ($selected_date < date('Y-m-d')) {
    $selected_date = date('Y-m-d');
}

/* ================= GENERATE SLOTS ================= */
if ($selected_doctor && $selected_date) {
    generateSlots($conn, $selected_doctor, $selected_date);
}

/* ================= LOAD SLOTS ================= */
$slots = [];

if ($selected_doctor && $selected_date) {

    $s = $conn->prepare("
        SELECT slot_id, start_time, end_time, is_booked, status
        FROM appointment_slots
        WHERE doctor_id = ?
        AND slot_date = ?
        AND status = 'available'
        ORDER BY start_time
    ");

    $s->bind_param("is", $selected_doctor, $selected_date);
    $s->execute();

    $slots = $s->get_result()->fetch_all(MYSQLI_ASSOC);

    $s->close();
}

/* ================= BOOKING ================= */
$success = "";
$error   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $doc_id  = (int)($_POST['doctor_id'] ?? 0);
    $slot_id = (int)($_POST['slot_id'] ?? 0);

    $date = trim($_POST['date'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = "Invalid date submitted.";
    }

    $reason = trim($_POST['reason'] ?? '');

    if (!$error && (!$doc_id || !$slot_id)) {
        $error = "Please select a valid slot.";
    }

    if (!$error) {

        $check = $conn->prepare("
            SELECT slot_id, start_time
            FROM appointment_slots
            WHERE slot_id = ?
            AND doctor_id = ?
            AND slot_date = ?
            AND is_booked = 0
            AND status = 'available'
            LIMIT 1
        ");

        $check->bind_param("iis", $slot_id, $doc_id, $date);
        $check->execute();

        $slot = $check->get_result()->fetch_assoc();

        $check->close();

        if (!$slot) {

            $error = "This slot is no longer available.";

        } else {

            $appt_datetime = $date . ' ' . date('H:i:s', strtotime($slot['start_time']));

            $ins = $conn->prepare("
                INSERT INTO appointments
                (
                    patient_id,
                    doctor_id,
                    slot_id,
                    appointment_date,
                    status,
                    reason
                )
                VALUES (?, ?, ?, ?, 'scheduled', ?)
            ");

            $ins->bind_param(
                "iiiss",
                $patient_id,
                $doc_id,
                $slot_id,
                $appt_datetime,
                $reason
            );

            $ins->execute();

            if ($ins->affected_rows > 0) {

                $upd = $conn->prepare("
                    UPDATE appointment_slots
                    SET is_booked = 1,
                        status = 'booked'
                    WHERE slot_id = ?
                ");

                $upd->bind_param("i", $slot_id);
                $upd->execute();
                $upd->close();

                $success = "Appointment booked successfully!";
            } else {
                $error = "Failed to book appointment.";
            }

            $ins->close();
        }
    }
}

/* ================= BOOKED SLOTS COUNT ================= */
$booked_count = 0;
$total_count  = 0;

if ($selected_doctor && $selected_date) {

    $cq = $conn->prepare("
        SELECT
            SUM(CASE WHEN is_booked = 1 THEN 1 ELSE 0 END) AS booked,
            COUNT(*) AS total
        FROM appointment_slots
        WHERE doctor_id = ?
        AND slot_date = ?
        AND status IN ('available','booked')
    ");

    $cq->bind_param("is", $selected_doctor, $selected_date);
    $cq->execute();

    $counts = $cq->get_result()->fetch_assoc();

    $cq->close();

    $booked_count = (int)($counts['booked'] ?? 0);
    $total_count  = (int)($counts['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Appointment — SHAPMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --brand:       #6d4fc2;
  --brand-2:     #9d6fe8;
  --brand-light: #ede9fa;
  --brand-dark:  #4a34a0;
  --pink:        #ec6fb0;
  --green:       #16a34a;
  --green-light: #dcfce7;
  --red:         #dc2626;
  --red-light:   #fee2e2;
  --text-1: #111827;
  --text-2: #374151;
  --text-3: #6b7280;
  --border: #e5e7eb;
  --surface: #f9fafb;
  --white: #ffffff;
  --radius: 10px;
  --radius-lg: 14px;
}

html { scroll-behavior: smooth; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
}

body {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 14px;
  line-height: 1.6;
  color: var(--text-2);
  background: var(--surface);
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
}

/* ── AMBIENT BACKGROUND ── */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  background:
    radial-gradient(circle at 10% 8%, rgba(109,79,194,0.08) 0%, transparent 45%),
    radial-gradient(circle at 90% 15%, rgba(236,111,176,0.08) 0%, transparent 45%),
    radial-gradient(circle at 50% 100%, rgba(157,111,232,0.06) 0%, transparent 50%);
}
.bg-blob { position: fixed; border-radius: 50%; filter: blur(90px); z-index: 0; pointer-events: none; opacity: 0.55; }
.bg-blob.b1 { width: 320px; height: 320px; top: -120px; right: -100px; background: #d9cffb; animation: driftA 22s ease-in-out infinite; }
.bg-blob.b2 { width: 260px; height: 260px; bottom: -100px; left: -90px; background: #fbe0ee; animation: driftB 26s ease-in-out infinite; }
@keyframes driftA { 0%,100% { transform: translate(0,0); } 50% { transform: translate(-25px, 30px); } }
@keyframes driftB { 0%,100% { transform: translate(0,0); } 50% { transform: translate(20px, -25px); } }

/* Entrance choreography */
@keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
.reveal { opacity: 0; animation: fadeUp 0.55s cubic-bezier(.22,1,.36,1) forwards; }
.r1 { animation-delay: .04s; }
.r2 { animation-delay: .12s; }
.r3 { animation-delay: .20s; }
.r4 { animation-delay: .28s; }

/* ── PAGE HEADER ── */
.page-header {
  position: relative;
  z-index: 2;
  background: rgba(255,255,255,0.88);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
  position: sticky;
  top: 0;
  box-shadow: 0 4px 24px rgba(109,79,194,0.06);
}
.page-header-inner {
  max-width: 900px;
  margin: 0 auto;
  padding: 18px 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--text-3);
}
.breadcrumb a {
  color: var(--text-3);
  text-decoration: none;
  transition: color .15s;
}
.breadcrumb a:hover { color: var(--brand); }
.breadcrumb .sep { font-size: 10px; color: var(--border); }

.page-title-block { display: flex; align-items: center; gap: 12px; }
.page-icon {
  width: 40px; height: 40px;
  background: linear-gradient(135deg, var(--brand), var(--pink));
  border-radius: 12px;
  display: grid; place-items: center;
  color: #fff; font-size: 16px;
  flex-shrink: 0;
  box-shadow: 0 6px 16px rgba(109,79,194,0.35);
  animation: iconPulse 3s ease-in-out infinite;
}
@keyframes iconPulse {
  0%,100% { transform: scale(1) rotate(0deg); }
  50% { transform: scale(1.06) rotate(-3deg); }
}
.page-title {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 19px; font-weight: 700; color: var(--text-1); line-height: 1.2;
}
.page-sub   { font-size: 12px; color: var(--text-3); }

.header-meta {
  display: flex; align-items: center; gap: 20px;
}
.meta-item {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--text-3);
  transition: color .15s;
}
.meta-item:hover { color: var(--brand); }
.meta-item i { color: var(--brand); font-size: 12px; }

.btn-back {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 16px;
  border-radius: var(--radius);
  font-family: inherit; font-size: 13px; font-weight: 500;
  color: var(--brand);
  background: var(--brand-light);
  border: 1px solid #ddd6fe;
  text-decoration: none;
  white-space: nowrap;
  transition: all .18s;
}
.btn-back:hover {
  background: var(--brand);
  color: #fff;
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(109,79,194,.25);
}
.btn-back i { font-size: 12px; }

/* ── MAIN WRAP ── */
.wrap {
  position: relative;
  z-index: 1;
  max-width: 900px;
  margin: 0 auto;
  padding: 28px 24px 60px;
}

/* ── ALERTS ── */
.alert {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 16px;
  border-radius: var(--radius);
  margin-bottom: 20px;
  border: 1px solid;
  font-size: 13px;
  line-height: 1.5;
  animation: alertPop 0.45s cubic-bezier(.22,1,.36,1);
  position: relative;
  overflow: hidden;
}
@keyframes alertPop { from { opacity: 0; transform: scale(0.96) translateY(-6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.alert-icon {
  width: 30px; height: 30px;
  border-radius: 8px;
  display: grid; place-items: center;
  flex-shrink: 0; font-size: 13px;
}
.alert.success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
.alert.success .alert-icon { background: #dcfce7; color: #16a34a; animation: checkBounce 0.6s ease; }
@keyframes checkBounce { 0% { transform: scale(0.4); } 60% { transform: scale(1.15); } 100% { transform: scale(1); } }
.alert.error   { background: #fff1f2; border-color: #fecdd3; color: #be123c; animation: alertPop 0.45s cubic-bezier(.22,1,.36,1), shakeX 0.5s ease .1s; }
.alert.error   .alert-icon { background: #ffe4e6; color: #dc2626; }
@keyframes shakeX { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
.alert strong  { display: block; font-weight: 600; margin-bottom: 1px; }

/* ── STEPS ── */
.steps {
  display: flex; align-items: center; gap: 0;
  margin-bottom: 28px;
  background: rgba(255,255,255,0.9);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 16px 20px;
  box-shadow: 0 6px 20px rgba(109,79,194,0.06);
}
.step {
  display: flex; align-items: center; gap: 10px;
  flex: 1;
}
.step-num {
  width: 28px; height: 28px;
  border-radius: 50%;
  display: grid; place-items: center;
  font-size: 12px; font-weight: 600;
  flex-shrink: 0;
  border: 1.5px solid var(--border);
  color: var(--text-3);
  background: var(--white);
  transition: all .25s;
}
.step.active .step-num {
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  border-color: var(--brand);
  color: #fff;
  box-shadow: 0 0 0 4px rgba(109,79,194,0.15);
  animation: stepGlow 2s ease-in-out infinite;
}
@keyframes stepGlow {
  0%,100% { box-shadow: 0 0 0 4px rgba(109,79,194,0.15); }
  50% { box-shadow: 0 0 0 7px rgba(109,79,194,0.08); }
}
.step.done .step-num {
  background: var(--green-light);
  border-color: #86efac;
  color: var(--green);
}
.step-text { font-size: 13px; }
.step-label { font-weight: 500; color: var(--text-2); transition: color .2s; }
.step.active .step-label { color: var(--brand); }
.step.done   .step-label { color: var(--green); }
.step-desc { font-size: 11px; color: var(--text-3); }
.step-divider {
  width: 32px; height: 1px;
  background: linear-gradient(90deg, var(--border), var(--brand-light));
  flex-shrink: 0;
}

/* ── CARD ── */
.card {
  background: rgba(255,255,255,0.95);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  margin-bottom: 16px;
  transition: box-shadow .25s, transform .25s;
  box-shadow: 0 4px 16px rgba(17,24,39,0.03);
}
.card:hover {
  box-shadow: 0 12px 30px rgba(109,79,194,0.09);
}
.card-header {
  display: flex; align-items: center; gap: 10px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}
.card-header-icon {
  width: 32px; height: 32px;
  background: var(--brand-light);
  border-radius: 8px;
  display: grid; place-items: center;
  color: var(--brand); font-size: 14px;
  flex-shrink: 0;
  transition: transform .3s;
}
.card:hover .card-header-icon { transform: rotate(-8deg) scale(1.08); }
.card-header-title { font-size: 14px; font-weight: 600; color: var(--text-1); }
.card-header-sub   { font-size: 12px; color: var(--text-3); margin-top: 1px; }
.card-body { padding: 20px; }

/* ── FORM FIELDS ── */
.field-row {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: 14px;
  align-items: end;
}
.field { display: flex; flex-direction: column; gap: 6px; }
.field label {
  font-size: 12px; font-weight: 500;
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: .04em;
}
.field select,
.field input[type="date"] {
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-family: inherit;
  font-size: 14px;
  color: var(--text-1);
  background: var(--white);
  outline: none;
  width: 100%;
  transition: border-color .15s, box-shadow .15s, transform .15s;
  appearance: none; -webkit-appearance: none;
}
.select-wrap { position: relative; }
.select-wrap::after {
  content: '\f107';
  font-family: 'Font Awesome 6 Free'; font-weight: 900;
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  color: var(--text-3); pointer-events: none; font-size: 12px;
  transition: transform .2s;
}
.field select:focus,
.field input:focus {
  border-color: var(--brand);
  box-shadow: 0 0 0 3px rgba(109,79,194,.1);
}

/* ── BUTTON ── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px;
  border-radius: var(--radius);
  font-family: inherit; font-size: 14px; font-weight: 500;
  border: none; cursor: pointer;
  transition: all .18s;
  white-space: nowrap;
  text-decoration: none;
  position: relative;
  overflow: hidden;
}
.btn:active { transform: scale(.97); }
.btn-primary {
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  background-size: 160% 160%;
  color: #fff;
  box-shadow: 0 4px 14px rgba(109,79,194,.32);
}
.btn-primary:hover {
  box-shadow: 0 8px 22px rgba(109,79,194,.4);
  transform: translateY(-2px);
  background-position: 100% 0;
}

.btn-confirm {
  background: linear-gradient(135deg, var(--brand), var(--pink));
  background-size: 200% 200%;
  animation: btnDrift 5s ease infinite;
  color: #fff;
  padding: 13px 24px; font-size: 14px; font-weight: 700;
  width: 100%; justify-content: center;
  border-radius: var(--radius);
  box-shadow: 0 6px 18px rgba(109,79,194,.32);
  letter-spacing: 0.2px;
}
@keyframes btnDrift { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
.btn-confirm:hover { box-shadow: 0 10px 26px rgba(109,79,194,.42); transform: translateY(-2px); }

/* ── DOCTOR SCHEDULE PILLS ── */
.schedule-wrap { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); animation: fadeUp 0.4s ease; }
.schedule-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .05em; color: var(--text-3); margin-bottom: 10px;
}
.schedule-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.day-pill {
  display: flex; flex-direction: column; gap: 2px;
  padding: 10px 14px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  min-width: 110px;
  transition: border-color .18s, transform .18s, box-shadow .18s;
  animation: fadeUp 0.35s ease backwards;
}
.day-pill:hover { border-color: var(--brand); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(109,79,194,.14); }
.day-pill .dp-day { font-size: 13px; font-weight: 600; color: var(--text-1); }
.day-pill .dp-time { font-size: 11px; color: var(--text-3); }
.day-pill .dp-max  { font-size: 11px; color: var(--text-3); }

.no-schedule-note {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 14px;
  background: #fefce8; border: 1px solid #fde047;
  border-radius: var(--radius);
  font-size: 13px; color: #854d0e;
  margin-top: 14px;
  animation: shakeX 0.5s ease;
}
.no-schedule-note i { animation: iconPulse 1.6s ease-in-out infinite; }

/* ── SLOT STATS ── */
.slot-stats {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
  margin-bottom: 16px;
}
.stat-pill {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  transition: transform .2s, box-shadow .2s;
}
.stat-pill:hover { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(17,24,39,0.08); }
.stat-pill.s-free   { border-color: #bbf7d0; background: #f0fdf4; }
.stat-pill.s-booked { border-color: #fecdd3; background: #fff1f2; }
.stat-pill.s-total  { border-color: #ddd6fe; background: #f5f3ff; }
.stat-icon {
  width: 34px; height: 34px;
  border-radius: 8px;
  display: grid; place-items: center;
  font-size: 14px; flex-shrink: 0;
}
.stat-pill.s-free   .stat-icon { background: #dcfce7; color: #16a34a; }
.stat-pill.s-booked .stat-icon { background: #ffe4e6; color: #dc2626; }
.stat-pill.s-total  .stat-icon { background: #ede9fa; color: var(--brand); }
.stat-val   { font-size: 22px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.stat-pill.s-free   .stat-val { color: #16a34a; }
.stat-pill.s-booked .stat-val { color: #dc2626; }
.stat-pill.s-total  .stat-val { color: var(--brand); }
.stat-lbl { font-size: 11px; color: var(--text-3); margin-top: 2px; text-transform: uppercase; letter-spacing: .03em; }

/* ── DONUT + LEGEND ── */
.avail-bar {
  display: flex; align-items: center; gap: 16px;
  padding: 14px 16px;
  background: linear-gradient(120deg, var(--surface), #f5f3ff);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  margin-bottom: 20px;
}
.avail-chart { width: 72px; height: 72px; flex-shrink: 0; }
.avail-legend-title { font-size: 13px; font-weight: 600; color: var(--text-1); margin-bottom: 8px; }
.avail-legend { display: flex; flex-direction: column; gap: 5px; }
.legend-row  { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text-2); }
.legend-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.avail-pct   { margin-top: 8px; font-size: 12px; font-weight: 700; color: var(--brand); }

/* ── SLOT GRID ── */
.slots-label {
  font-size: 12px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .05em; color: var(--text-3);
  margin-bottom: 12px;
  display: flex; align-items: center; gap: 8px;
}
.slots-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

.slot-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(108px, 1fr));
  gap: 8px;
  margin-bottom: 20px;
}
.slot-btn {
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  padding: 12px 8px;
  border-radius: var(--radius);
  border: 1px solid;
  cursor: pointer;
  transition: all .18s cubic-bezier(.22,1,.36,1);
  position: relative;
  animation: fadeUp 0.4s ease backwards;
}
.slot-btn input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }

.slot-btn.free {
  background: var(--white); border-color: var(--border);
  color: var(--text-2);
}
.slot-btn.free:hover {
  border-color: var(--brand); background: var(--brand-light);
  color: var(--brand); transform: translateY(-3px) scale(1.03);
  box-shadow: 0 8px 18px rgba(109,79,194,.18);
}
.slot-btn.booked {
  background: #fff5f5; border-color: #fecdd3;
  color: #fca5a5; cursor: not-allowed;
  text-decoration: line-through; opacity: .75;
}
.slot-btn.selected {
  background: linear-gradient(135deg, var(--brand), var(--brand-2)) !important;
  border-color: var(--brand) !important;
  color: #fff !important;
  transform: translateY(-3px) scale(1.05) !important;
  box-shadow: 0 10px 24px rgba(109,79,194,.4) !important;
  animation: selectPop .35s cubic-bezier(.22,1.5,.36,1) !important;
}
@keyframes selectPop { 0% { transform: scale(0.9); } 55% { transform: scale(1.08); } 100% { transform: scale(1.05); } }
.slot-btn.selected .s-label { color: rgba(255,255,255,.75) !important; }

.s-time  { font-size: 13px; font-weight: 600; }
.s-label { font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }

/* ── EMPTY STATES ── */
.empty-state {
  text-align: center; padding: 44px 20px; color: var(--text-3);
  animation: fadeUp 0.4s ease;
}
.empty-state-icon {
  width: 56px; height: 56px;
  background: var(--brand-light); border-radius: 50%;
  display: grid; place-items: center;
  font-size: 22px; color: var(--brand);
  margin: 0 auto 14px;
  animation: floatIcon 3s ease-in-out infinite;
}
@keyframes floatIcon { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
.empty-state strong { display: block; font-size: 15px; font-weight: 600; color: var(--text-1); margin-bottom: 6px; }
.empty-state p { font-size: 13px; max-width: 260px; margin: 0 auto; line-height: 1.6; }

/* ── BOOKING CONFIRM ── */
#booking-confirm { display: none; }
#booking-confirm.show { display: block; animation: fadeUp 0.4s cubic-bezier(.22,1,.36,1); }

.divider { height: 1px; background: linear-gradient(90deg, transparent, var(--border), transparent); margin: 18px 0; }

.selected-slot-row {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px;
  background: linear-gradient(120deg, var(--brand-light), #fbe0ee);
  border: 1px solid #c4b5fd;
  border-radius: var(--radius);
  margin-bottom: 16px;
}
.ssl-icon {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  border-radius: 8px;
  display: grid; place-items: center;
  color: #fff; font-size: 14px; flex-shrink: 0;
  animation: iconPulse 2.2s ease-in-out infinite;
}
.ssl-label { font-size: 11px; color: var(--brand); text-transform: uppercase; letter-spacing: .04em; font-weight: 500; }
.ssl-val   { font-size: 15px; font-weight: 700; color: var(--brand-dark); }

.form-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-field label { font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: .04em; color: var(--text-3); }
.form-field textarea {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-family: inherit; font-size: 14px;
  background: var(--white); color: var(--text-1);
  resize: vertical; outline: none;
  transition: border-color .15s, box-shadow .15s;
  line-height: 1.55;
}
.form-field textarea::placeholder { color: var(--text-3); }
.form-field textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(109,79,194,.1); }

/* ── RESPONSIVE ── */
@media (max-width: 680px) {
  .page-header-inner { flex-direction: column; align-items: flex-start; gap: 10px; }
  .header-meta { display: none; }
  .btn-back-mobile { display: inline-flex !important; }
  .field-row  { grid-template-columns: 1fr; }
  .slot-stats { grid-template-columns: repeat(3, 1fr); }
  .steps      { flex-wrap: wrap; gap: 8px; }
  .step-divider { display: none; }
}
.btn-back-mobile { display: none; }
@media (max-width: 460px) {
  .slot-stats { grid-template-columns: 1fr 1fr; }
  .slot-grid  { grid-template-columns: repeat(3, 1fr); }
  .wrap { padding: 16px 14px 40px; }
}
</style>
</head>
<body>

<div class="bg-blob b1"></div>
<div class="bg-blob b2"></div>

<!-- PAGE HEADER -->
<header class="page-header">
  <div class="page-header-inner">
    <div>
      <nav class="breadcrumb">
        <a href="patientdashboard.php">Dashboard</a>
        <span class="sep"><i class="fas fa-chevron-right"></i></span>
        <span>Book Appointment</span>
      </nav>
      <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
        <div class="page-icon"><i class="fas fa-calendar-plus"></i></div>
        <div>
          <div class="page-title">Book an Appointment</div>
          <div class="page-sub">Find a doctor and reserve your slot</div>
        </div>
        <a href="patientdashboard.php" class="btn-back btn-back-mobile" style="margin-left:auto;">
          <i class="fas fa-arrow-left"></i> Dashboard
        </a>
      </div>
    </div>
    <div class="header-meta">
      <div class="meta-item"><i class="fas fa-clock"></i> Open 8:00 AM – 10:00 PM</div>
      <div class="meta-item"><i class="fas fa-phone"></i> +92 300 123 4567</div>
      <div class="meta-item"><i class="fas fa-envelope"></i> shapms@hospital.com</div>
      <a href="patientdashboard.php" class="btn-back">
        <i class="fas fa-arrow-left"></i> Dashboard
      </a>
    </div>
  </div>
</header>

<!-- MAIN CONTENT -->
<div class="wrap">

  <!-- Alerts -->
  <?php if ($success): ?>
  <div class="alert success reveal">
    <div class="alert-icon"><i class="fas fa-check"></i></div>
    <div><strong>Appointment confirmed</strong><?= htmlspecialchars($success) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert error reveal">
    <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div><strong>Booking failed</strong><?= htmlspecialchars($error) ?></div>
  </div>
  <?php endif; ?>

  <!-- Step indicator -->
  <div class="steps reveal r1">
    <div class="step <?= $selected_doctor ? 'done' : 'active' ?>">
      <div class="step-num"><?= $selected_doctor ? '<i class="fas fa-check" style="font-size:10px"></i>' : '1' ?></div>
      <div class="step-text">
        <div class="step-label">Doctor &amp; Date</div>
        <div class="step-desc">Choose who and when</div>
      </div>
    </div>
    <div class="step-divider"></div>
    <div class="step <?= ($selected_doctor && !empty($slots)) ? 'active' : '' ?>">
      <div class="step-num">2</div>
      <div class="step-text">
        <div class="step-label">Pick a slot</div>
        <div class="step-desc">Select available time</div>
      </div>
    </div>
    <div class="step-divider"></div>
    <div class="step <?= $success ? 'done' : '' ?>">
      <div class="step-num"><?= $success ? '<i class="fas fa-check" style="font-size:10px"></i>' : '3' ?></div>
      <div class="step-text">
        <div class="step-label">Confirm</div>
        <div class="step-desc">Add notes and book</div>
      </div>
    </div>
  </div>

  <!-- STEP 1: Doctor & Date -->
  <div class="card reveal r2">
    <div class="card-header">
      <div class="card-header-icon"><i class="fas fa-user-md"></i></div>
      <div>
        <div class="card-header-title">Select doctor &amp; date</div>
        <div class="card-header-sub">Pick your preferred specialist and appointment date</div>
      </div>
    </div>
    <div class="card-body">
      <form method="GET" action="">
        <div class="field-row">
          <div class="field">
            <label>Doctor</label>
            <div class="select-wrap">
              <select name="doctor_id" required>
                <option value="">— Choose a doctor —</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['doctor_id'] ?>"
                    <?= ($selected_doctor === $d['doctor_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['full_name']) ?> — <?= htmlspecialchars($d['specialty'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field">
            <label>Appointment date</label>
            <input type="date" name="date"
                   value="<?= htmlspecialchars($selected_date) ?>"
                   min="<?= date('Y-m-d') ?>" required>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> View slots
          </button>
        </div>
      </form>

      <!-- Doctor schedule -->
      <div id="doc-availability" style="display:none;" class="schedule-wrap">
        <div class="schedule-label">Working schedule</div>
        <div class="schedule-pills" id="doc-avail-inner"></div>
      </div>
      <div id="doc-no-schedule" style="display:none;">
        <div class="no-schedule-note">
          <i class="fas fa-triangle-exclamation"></i>
          This doctor hasn't set a working schedule yet. Please select a different doctor.
        </div>
      </div>
    </div>
  </div>

  <!-- STEP 2: Slots -->
  <?php if ($selected_doctor && $selected_date): ?>
  <div class="card reveal r3">
    <div class="card-header">
      <div class="card-header-icon"><i class="fas fa-clock"></i></div>
      <div>
        <div class="card-header-title">Available slots</div>
        <div class="card-header-sub"><?= date("l, d F Y", strtotime($selected_date)) ?></div>
      </div>
    </div>
    <div class="card-body">

      <?php if ($total_count > 0): ?>
      <!-- Stats row -->
      <div class="slot-stats">
        <div class="stat-pill s-free">
          <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
          <div>
            <div class="stat-val"><?= $total_count - $booked_count ?></div>
            <div class="stat-lbl">Available</div>
          </div>
        </div>
        <div class="stat-pill s-booked">
          <div class="stat-icon"><i class="fas fa-circle-xmark"></i></div>
          <div>
            <div class="stat-val"><?= $booked_count ?></div>
            <div class="stat-lbl">Booked</div>
          </div>
        </div>
        <div class="stat-pill s-total">
          <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
          <div>
            <div class="stat-val"><?= $total_count ?></div>
            <div class="stat-lbl">Total</div>
          </div>
        </div>
      </div>

      <!-- Availability bar -->
      <div class="avail-bar">
        <div class="avail-chart"><canvas id="slotDonut"></canvas></div>
        <div>
          <div class="avail-legend-title">Availability overview</div>
          <div class="avail-legend">
            <div class="legend-row"><span class="legend-dot" style="background:#4ade80"></span> <?= $total_count - $booked_count ?> slots open</div>
            <div class="legend-row"><span class="legend-dot" style="background:#f87171"></span> <?= $booked_count ?> slots taken</div>
          </div>
          <?php $pct = $total_count > 0 ? round(($total_count - $booked_count) / $total_count * 100) : 0; ?>
          <div class="avail-pct"><?= $pct ?>% available</div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (empty($slots) && $total_count === 0): ?>
        <div class="empty-state">
          <div class="empty-state-icon"><i class="fas fa-calendar-xmark"></i></div>
          <strong>No schedule for this day</strong>
          <p>Dr. schedule does not include <?= date("l", strtotime($selected_date)) ?>. Try a different date.</p>
        </div>

   <?php elseif (empty($slots) && $booked_count > 0): ?>
        <div class="empty-state">
          <div class="empty-state-icon"><i class="fas fa-ban"></i></div>
          <strong>Fully booked</strong>
          <p>All <?= $total_count ?> slots are taken for this date. Please try another day.</p>
        </div>

      <?php else: ?>
        <form method="POST" id="slotForm">
          <input type="hidden" name="doctor_id" value="<?= $selected_doctor ?>">
          <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
          <input type="hidden" name="slot_id" id="hiddenSlotId" value="">

          <div class="slots-label">Choose your time</div>
          <div class="slot-grid">
            <?php
            $all_slots_q = $conn->prepare("
                SELECT slot_id, start_time, end_time, is_booked
                FROM appointment_slots
                WHERE doctor_id = ? AND slot_date = ? AND status IN ('available','booked')
                ORDER BY start_time
            ");
            $all_slots_q->bind_param("is", $selected_doctor, $selected_date);
            $all_slots_q->execute();
            $all_slots = $all_slots_q->get_result()->fetch_all(MYSQLI_ASSOC);
            $all_slots_q->close();
            $slot_i = 0;
            ?>

            <?php foreach ($all_slots as $slot): ?>
              <?php $slot_i++; $slot_delay = min($slot_i * 0.02, 0.5); ?>
              <?php if ($slot['is_booked']): ?>
                <div class="slot-btn booked" style="animation-delay: <?= $slot_delay ?>s">
                  <span class="s-time"><?= date("h:i A", strtotime($slot['start_time'])) ?></span>
                  <span class="s-label">Booked</span>
                </div>
              <?php else: ?>
                <div class="slot-btn free"
                     id="slot-<?= $slot['slot_id'] ?>"
                     style="animation-delay: <?= $slot_delay ?>s"
                     onclick="selectSlot(<?= $slot['slot_id'] ?>, '<?= date('h:i A', strtotime($slot['start_time'])) ?>')">
                  <input type="radio" name="slot_id" value="<?= $slot['slot_id'] ?>">
                  <span class="s-time"><?= date("h:i A", strtotime($slot['start_time'])) ?></span>
                  <span class="s-label">Open</span>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <!-- Confirm panel -->
          <div id="booking-confirm">
            <div class="divider"></div>

            <div class="selected-slot-row">
              <div class="ssl-icon"><i class="fas fa-clock"></i></div>
              <div>
                <div class="ssl-label">Selected time slot</div>
                <div class="ssl-val" id="selectedTimeDisplay">—</div>
              </div>
            </div>

            <div class="form-field">
              <label>Reason for visit <span style="font-weight:400;text-transform:none;font-size:11px;color:var(--text-3)">(optional)</span></label>
              <textarea name="reason" rows="3" placeholder="Describe your symptoms or purpose of visit…"></textarea>
            </div>

            <button type="submit" class="btn btn-confirm">
              <i class="fas fa-calendar-check"></i> Confirm appointment
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /wrap -->

<script>
let currentSelected = null;

function selectSlot(slotId, timeStr) {
  if (currentSelected !== null) {
    const prev = document.getElementById('slot-' + currentSelected);
    if (prev) { prev.classList.remove('selected'); prev.classList.add('free'); }
  }
  const el = document.getElementById('slot-' + slotId);
  if (el) {
    el.classList.remove('free');
    el.classList.add('selected');
    el.querySelector('input[type="radio"]').checked = true;
  }
  currentSelected = slotId;
  document.getElementById('hiddenSlotId').value = slotId;
  document.getElementById('selectedTimeDisplay').textContent = timeStr;
  const panel = document.getElementById('booking-confirm');
  panel.classList.add('show');
  panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

document.getElementById('slotForm')?.addEventListener('submit', function(e) {
  if (!document.getElementById('hiddenSlotId').value) {
    e.preventDefault();
    alert('Please select a time slot first.');
  }
});

/* Doctor availability */
const doctorSelect = document.querySelector('select[name="doctor_id"]');
const availBox     = document.getElementById('doc-availability');
const availInner   = document.getElementById('doc-avail-inner');
const noSchedBox   = document.getElementById('doc-no-schedule');
const dateInput    = document.querySelector('input[type="date"]');

const dayMap = { 'Sunday':0,'Monday':1,'Tuesday':2,'Wednesday':3,'Thursday':4,'Friday':5,'Saturday':6 };
let allowedDays = [];

function fmt12(t) {
  const [h, m] = t.split(':');
  const hour = parseInt(h);
  return `${hour % 12 || 12}:${m} ${hour >= 12 ? 'PM' : 'AM'}`;
}

function loadDoctorAvailability(doctorId) {
  if (!doctorId) { availBox.style.display = 'none'; noSchedBox.style.display = 'none'; allowedDays = []; return; }
  fetch(`?fetch_schedule=1&doctor_id=${doctorId}`)
    .then(r => r.json())
    .then(data => {
      availBox.style.display = 'none'; noSchedBox.style.display = 'none';
      availInner.innerHTML = ''; allowedDays = [];
      if (!data.length) { noSchedBox.style.display = 'block'; return; }
      data.forEach((row, idx) => {
        allowedDays.push(dayMap[row.working_day]);
        const pill = document.createElement('div');
        pill.className = 'day-pill';
        pill.style.animationDelay = (idx * 0.05) + 's';
        pill.innerHTML = `<span class="dp-day">${row.working_day}</span>
          <span class="dp-time">${fmt12(row.start_time)} – ${fmt12(row.end_time)}</span>
          <span class="dp-max">${row.max_patients} patients/day</span>`;
        availInner.appendChild(pill);
      });
      availBox.style.display = 'block';
      if (dateInput) advanceDateToValidDay();
    })
    .catch(() => { noSchedBox.style.display = 'block'; });
}

function advanceDateToValidDay() {
  if (!allowedDays.length) return;
  const today = new Date(); today.setHours(0,0,0,0);
  let check = new Date(today);
  for (let i = 0; i < 14; i++) {
    if (allowedDays.includes(check.getDay())) {
      const yyyy = check.getFullYear();
      const mm   = String(check.getMonth()+1).padStart(2,'0');
      const dd   = String(check.getDate()).padStart(2,'0');
      dateInput.value = `${yyyy}-${mm}-${dd}`; break;
    }
    check.setDate(check.getDate() + 1);
  }
}

if (dateInput) {
  dateInput.addEventListener('change', function() {
    if (!allowedDays.length) return;
    const picked  = new Date(this.value + 'T00:00:00');
    const dayNum  = picked.getDay();
    if (!allowedDays.includes(dayNum)) {
      const names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
      alert(`The doctor doesn't work on ${names[dayNum]}. Please choose a working day.`);
    }
  });
}

if (doctorSelect) {
  doctorSelect.addEventListener('change', function() { loadDoctorAvailability(this.value); });
  if (doctorSelect.value) loadDoctorAvailability(doctorSelect.value);
}

/* Donut chart */
const donutCanvas = document.getElementById('slotDonut');
if (donutCanvas) {
  const free   = <?= $total_count - $booked_count ?>;
  const booked = <?= $booked_count ?>;
  new Chart(donutCanvas, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [free, booked],
        backgroundColor: ['#4ade80','#f87171'],
        borderColor: ['#fff','#fff'],
        borderWidth: 2,
        hoverBorderWidth: 3,
      }]
    },
    options: {
      cutout: '70%',
      plugins: { legend: { display: false }, tooltip: { enabled: true } },
      animation: { animateRotate: true, duration: 800, easing: 'easeOutQuart' }
    }
  });
}
</script>
</body>
</html>