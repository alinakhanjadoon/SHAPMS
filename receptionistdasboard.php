<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

/* ---------- SLOT GENERATOR ----------
   Same helper the patient booking page uses (doctor_schedule -> appointment_slots).
   Adjust this path if generate_slots.php lives in a different folder relative
   to this file. */
require_once 'generate_slots.php';

// ── Current receptionist ──
$user = ['full_name' => 'Receptionist', 'email' => '', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, email, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn, $em, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Receptionist', 'email' => $em ?: '', 'profile_image' => $pi ?: ''];
$stmt->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));

// ── Flash ──
$flash = null;
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// ── AJAX: quick-add a new walk-in patient (no page reload, keeps slot selection) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_patient') {
    header('Content-Type: application/json');

    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $cnic      = trim($_POST['cnic'] ?? '');

    if (!$full_name || !$email || !$cnic) {
        echo json_encode(['success' => false, 'error' => 'Full name, email and CNIC are required.']);
        exit();
    }

    $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $chk->bind_param("s", $email);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'error' => 'A patient with this email already exists.']);
        exit();
    }
    $chk->close();

    // Auto-generate a unique username from the email's local part
    $base_username = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0]));
    if ($base_username === '') $base_username = 'patient';
    $username = $base_username;
    $suffix = 0;
    while (true) {
        $uchk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $uchk->bind_param("s", $username);
        $uchk->execute();
        $uchk->store_result();
        $exists = $uchk->num_rows > 0;
        $uchk->close();
        if (!$exists) break;
        $suffix++;
        $username = $base_username . $suffix;
    }

    // Auto-generate a temporary password (shown once to the receptionist)
    $temp_password = substr(bin2hex(random_bytes(4)), 0, 8);
    $hashed = password_hash($temp_password, PASSWORD_DEFAULT);

    $ins = $conn->prepare("
        INSERT INTO users (full_name, username, email, cnic, password_hash, role, department_id, status)
        VALUES (?, ?, ?, ?, ?, 'patient', NULL, 'active')
    ");
    $ins->bind_param("sssss", $full_name, $username, $email, $cnic, $hashed);

    if (!$ins->execute()) {
        echo json_encode(['success' => false, 'error' => 'Could not create patient: ' . $ins->error]);
        exit();
    }
    $new_user_id = $conn->insert_id;
    $ins->close();

    $pins = $conn->prepare("INSERT INTO patients (user_id) VALUES (?)");
    $pins->bind_param("i", $new_user_id);
    $pins->execute();
    $pins->close();

    echo json_encode([
        'success'       => true,
        'user_id'       => $new_user_id,
        'full_name'     => $full_name,
        'email'         => $email,
        'username'      => $username,
        'temp_password' => $temp_password
    ]);
    exit();
}

// ── AJAX: doctor schedule (identical logic to the patient booking page) ──
if (isset($_GET['fetch_schedule']) && isset($_GET['doctor_id'])) {
    $did = (int)$_GET['doctor_id'];
    $sq = $conn->prepare("
        SELECT working_day, start_time, end_time, max_patients
        FROM doctor_schedule
        WHERE doctor_id = ? AND is_available = 1
        ORDER BY FIELD(working_day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
    ");
    $sq->bind_param("i", $did);
    $sq->execute();
    $sched_rows = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
    $sq->close();
    header('Content-Type: application/json');
    echo json_encode($sched_rows);
    exit();
}

// ── Selected doctor + date for the walk-in slot picker ──
$selected_doctor = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$selected_date   = $_GET['date'] ?? date('Y-m-d');
if ($selected_date < date('Y-m-d')) $selected_date = date('Y-m-d');

if ($selected_doctor && $selected_date) {
    generateSlots($conn, $selected_doctor, $selected_date);
}

// ── Book Walk-in Appointment (POST) — same slot logic as patient booking ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_walkin') {
    $patient_user_id = intval($_POST['patient_id'] ?? 0);   // users.user_id of selected patient
    $doc_id           = intval($_POST['doctor_id']  ?? 0);  // doctors.doctor_id
    $slot_id          = intval($_POST['slot_id']    ?? 0);
    $date             = trim($_POST['date'] ?? '');
    $notes            = trim($_POST['notes'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$patient_user_id || !$doc_id || !$slot_id) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Please select a patient, doctor, date and time slot.'];
        header("Location: receptionistdashboard.php");
        exit();
    }

    // Map the patient's users.user_id -> patients.patient_id (create the row if missing,
    // same as the patient self-booking page does on first login).
    $pstmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id=?");
    $pstmt->bind_param("i", $patient_user_id);
    $pstmt->execute();
    $pstmt->bind_result($patient_id);
    $pstmt->fetch();
    $pstmt->close();
    if (!$patient_id) {
        $pins = $conn->prepare("INSERT INTO patients (user_id) VALUES (?)");
        $pins->bind_param("i", $patient_user_id);
        $pins->execute();
        $patient_id = $pins->insert_id;
        $pins->close();
    }

    // Re-verify the slot is still free right before booking (avoid double-booking
    // if two receptionists / a patient grabbed it in the meantime).
    $check = $conn->prepare("
        SELECT slot_id, start_time
        FROM appointment_slots
        WHERE slot_id = ? AND doctor_id = ? AND slot_date = ?
          AND is_booked = 0 AND status = 'available'
        LIMIT 1
    ");
    $check->bind_param("iis", $slot_id, $doc_id, $date);
    $check->execute();
    $slot = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$slot) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'That slot was just taken. Please pick another.'];
    } else {
        $appt_datetime = $date . ' ' . date('H:i:s', strtotime($slot['start_time']));

        // Build the insert dynamically so it still works whether or not your
        // appointments table has the optional appointment_type / booked_by columns.
        $has_type_col      = $conn->query("SHOW COLUMNS FROM appointments LIKE 'appointment_type'")->num_rows > 0;
        $has_booked_by_col = $conn->query("SHOW COLUMNS FROM appointments LIKE 'booked_by'")->num_rows > 0;

        $cols  = ['patient_id', 'doctor_id', 'slot_id', 'appointment_date', 'status', 'reason'];
        $vals  = [$patient_id,  $doc_id,     $slot_id,  $appt_datetime,     'approved', $notes];
        $types = 'iiisss';

        if ($has_type_col)      { $cols[] = 'appointment_type'; $vals[] = 'walk-in';           $types .= 's'; }
        if ($has_booked_by_col) { $cols[] = 'booked_by';        $vals[] = $_SESSION['user_id']; $types .= 'i'; }

        $sql = "INSERT INTO appointments (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
        $ins = $conn->prepare($sql);
        $ins->bind_param($types, ...$vals);

        if ($ins->execute()) {
            $upd = $conn->prepare("UPDATE appointment_slots SET is_booked=1, status='booked' WHERE slot_id=?");
            $upd->bind_param("i", $slot_id);
            $upd->execute();
            $upd->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Walk-in appointment booked successfully!'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Failed to book appointment. Please try again.'];
        }
        $ins->close();
    }

    header("Location: receptionistdashboard.php?doctor_id=$doc_id&date=$date");
    exit();
}

// ── Stats ──
$has_appts = $conn->query("SHOW TABLES LIKE 'appointments'")->num_rows > 0;

$total_appts = $today_appts = $pending_appts = $completed_appts = $walkin_appts = 0;
if ($has_appts) {
    $total_appts     = $conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0] ?? 0;
    $today_appts     = $conn->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=CURDATE() OR DATE(created_at)=CURDATE()")->fetch_row()[0] ?? 0;
    $pending_appts   = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetch_row()[0] ?? 0;
    $completed_appts = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='completed'")->fetch_row()[0] ?? 0;
    $has_type_col    = $conn->query("SHOW COLUMNS FROM appointments LIKE 'appointment_type'")->num_rows > 0;
    if ($has_type_col) {
        $walkin_appts = $conn->query("SELECT COUNT(*) FROM appointments WHERE appointment_type='walk-in'")->fetch_row()[0] ?? 0;
    }
}

$total_patients = $conn->query("SELECT COUNT(*) FROM users WHERE role='patient'")->fetch_row()[0] ?? 0;

// ── Doctors list (for walk-in booking + availability widget) ──
// Pulled from the doctors table (not just users) so doctor_id lines up with
// doctors.doctor_id, exactly like the patient booking page.
$doctors = [];
$dr = $conn->query("
    SELECT d.doctor_id, u.full_name, u.email, d.specialty
    FROM doctors d
    JOIN users u ON d.user_id = u.user_id
    WHERE u.status = 'active'
    ORDER BY u.full_name
");
if ($dr) while ($row = $dr->fetch_assoc()) $doctors[] = $row;

// ── Patients list (for booking form patient picker) ──
$patients = [];
$pr = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='patient' ORDER BY full_name");
if ($pr) while ($row = $pr->fetch_assoc()) $patients[] = $row;

// ── Slot grid for the selected doctor + date (walk-in step 2) ──
$all_slots = [];
$booked_count = $total_count = 0;
if ($selected_doctor && $selected_date) {
    $asq = $conn->prepare("
        SELECT slot_id, start_time, end_time, is_booked
        FROM appointment_slots
        WHERE doctor_id = ? AND slot_date = ? AND status IN ('available','booked')
        ORDER BY start_time
    ");
    $asq->bind_param("is", $selected_doctor, $selected_date);
    $asq->execute();
    $all_slots = $asq->get_result()->fetch_all(MYSQLI_ASSOC);
    $asq->close();

    $total_count  = count($all_slots);
    $booked_count = count(array_filter($all_slots, fn($s) => (int)$s['is_booked'] === 1));
}

// ── Today's appointments ──
// NOTE: joined through patients/doctors tables (patient_id = patients.patient_id,
// doctor_id = doctors.doctor_id) to match the slot-based schema used above and
// by the patient booking page. If you have older rows inserted the previous way
// (patient_id / doctor_id = users.user_id directly), they won't match this join —
// see the message after the file for how to check/fix that.
$today_list = [];
if ($has_appts) {
    $tq = $conn->query("
        SELECT a.appointment_id, u.full_name as patient_name, u.email as patient_email,
               a.status, a.created_at, du.full_name as doctor_name
        FROM appointments a
        JOIN patients p     ON a.patient_id = p.patient_id
        JOIN users u        ON p.user_id = u.user_id
        LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
        LEFT JOIN users du  ON d.user_id = du.user_id
        WHERE DATE(a.appointment_date) = CURDATE()
        ORDER BY a.created_at DESC LIMIT 10
    ");
    if ($tq) while ($row = $tq->fetch_assoc()) $today_list[] = $row;
}

// ── Recent patients ──
$recent_patients = [];
$rp = $conn->query("SELECT user_id, full_name, email, created_at FROM users WHERE role='patient' ORDER BY created_at DESC LIMIT 6");
if ($rp) while ($row = $rp->fetch_assoc()) $recent_patients[] = $row;

// ── Doctor availability (today's booked count per doctor) ──
$doctor_availability = [];
foreach ($doctors as $doc) {
    $appt_count = 0;
    if ($has_appts) {
        $acq = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND DATE(appointment_date)=CURDATE()");
        $acq->bind_param("i", $doc['doctor_id']);
        $acq->execute();
        $acq->bind_result($cnt);
        $acq->fetch();
        $appt_count = $cnt ?? 0;
        $acq->close();
    }
    $doctor_availability[] = ['name' => $doc['full_name'], 'count' => $appt_count, 'id' => $doc['doctor_id']];
}

// ── Monthly chart data ──
$monthly_data = [];
if ($has_appts) {
    $mr = $conn->query("SELECT DATE_FORMAT(created_at,'%b') as m, COUNT(*) as total
                        FROM appointments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        GROUP BY MONTH(created_at), DATE_FORMAT(created_at,'%b')
                        ORDER BY MONTH(created_at)");
    if ($mr) while ($row = $mr->fetch_assoc()) $monthly_data[] = $row;
}
$m_labels = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data, 'm');
$m_totals = empty($monthly_data) ? [0,0,0,0,0,0] : array_map('intval', array_column($monthly_data, 'total'));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receptionist Dashboard — SHAPMS</title>
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
  --sw:        210px;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; font-size: 13px; overflow-x: hidden; width: 100%; }
.layout { display: flex; min-height: 100vh; width: 100%; max-width: 100vw; overflow: hidden; }

/* ── SIDEBAR ── */
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

/* ── MAIN ── */
.main { margin-left: var(--sw); flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; width: calc(100% - var(--sw)); }

/* ── TOPBAR ── */
.topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 24px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-breadcrumb { font-size: 9.5px; color: var(--ink40); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; font-weight: 600; }
.topbar-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.live-badge { display: flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid rgba(16,185,129,0.25); color: #059669; padding: 5px 11px; border-radius: 99px; font-size: 10.5px; font-weight: 700; }
.live-dot { width: 5px; height: 5px; background: var(--green); border-radius: 50%; animation: lp 1.5s infinite; }
@keyframes lp { 0%,100%{opacity:1;}50%{opacity:0.4;} }
.avatar-pill { display: flex; align-items: center; gap: 8px; background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 5px 12px 5px 5px; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; background: var(--sky); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: white; }
.avatar-name { font-size: 11.5px; font-weight: 700; color: var(--ink); line-height: 1.2; }
.avatar-role { font-size: 9.5px; color: var(--sky-dark); font-weight: 600; }

/* ── TICKER ── */
.ticker-bar { background: var(--sky-pale); border-bottom: 1px solid var(--border); height: 34px; display: flex; align-items: center; overflow: hidden; }
.ticker-track { display: flex; animation: tick 30s linear infinite; white-space: nowrap; }
.ticker-item { display: flex; align-items: center; gap: 6px; padding: 0 24px; font-size: 10.5px; font-weight: 600; color: var(--sky-dark); }
.ticker-dot { width: 4px; height: 4px; border-radius: 50%; background: var(--sky); }
@keyframes tick { 0%{transform:translateX(0);}100%{transform:translateX(-50%);} }

/* ── PAGE BODY ── */
.page-body { padding: 22px 24px 50px; display: flex; flex-direction: column; gap: 20px; }

/* ── FLASH ── */
.flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; }
.flash-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.flash-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.flash-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* ── STAT CARDS ── */
.stats-row { display: grid; grid-template-columns: repeat(6,1fr); gap: 12px; }
.stat-card { border-radius: 14px; padding: 14px 16px 12px; position: relative; overflow: hidden; color: white; min-height: 108px; display: flex; flex-direction: column; justify-content: space-between; }
.stat-card::after { content:''; position: absolute; right:-18px; bottom:-18px; width:70px; height:70px; border-radius:50%; background:rgba(255,255,255,0.12); }
.sc1{background:#0ea5e9;} .sc2{background:#38bdf8;} .sc3{background:#7dd3fc;color:#0c4a6e;}
.sc4{background:#0284c7;} .sc5{background:#075985;} .sc6{background:#0369a1;}
.stat-icon { width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,0.22); display:flex; align-items:center; justify-content:center; font-size:12px; position:relative; z-index:2; }
.stat-label { font-size:8.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; opacity:0.9; margin-top:10px; position:relative; z-index:2; }
.stat-val   { font-size:26px; font-weight:700; line-height:1; margin-top:2px; position:relative; z-index:2; }
.stat-sub   { font-size:9px; opacity:0.82; margin-top:2px; position:relative; z-index:2; }

/* ── SECTION LABEL ── */
.section-label { display:flex; align-items:center; gap:8px; font-size:9.5px; font-weight:700; color:var(--ink40); text-transform:uppercase; letter-spacing:1.2px; }
.section-label::before { content:''; width:12px; height:2px; background:var(--sky); border-radius:2px; }
.section-label::after  { content:''; flex:1; height:1px; background:var(--border); }

/* ── CARDS ── */
.card { background:var(--white); border:1px solid var(--border); border-radius:14px; padding:18px 20px; }
.card-title { font-size:13px; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:7px; margin-bottom:2px; }
.card-title i { color:var(--sky); font-size:13px; }
.card-sub { font-size:10.5px; color:var(--ink40); margin-bottom:14px; }
.card-hdr { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:4px; }
.card-chip { background:var(--sky-pale); border:1px solid var(--border2); border-radius:99px; padding:3px 10px; font-size:9.5px; font-weight:700; color:var(--sky-dark); }

/* ── BOOK APPOINTMENT FORM ── */
.book-card { background: var(--white); border: 2px solid var(--sky-pale2); border-radius: 16px; padding: 22px 24px; }
.book-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
.book-grid-wide { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-label { font-size: 10px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.5px; }
.form-control { padding: 9px 12px; border: 1px solid var(--border); border-radius: 9px; font-size: 12.5px; color: var(--ink); background: var(--white); outline: none; font-family: inherit; width: 100%; }
.form-control:focus { border-color: var(--sky); box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
.form-control::placeholder { color: var(--ink40); }
select.form-control { cursor: pointer; }
.btn-book { display: inline-flex; align-items: center; gap: 7px; padding: 10px 22px; background: var(--sky); color: white; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; margin-top: 14px; }
.btn-book:hover { background: var(--sky-dark); }

/* ── WALK-IN SLOT PICKER ── */
.slot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
.slot-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 12px 8px; border-radius: 9px; border: 1.5px solid; cursor: pointer; transition: all 0.15s; }
.slot-btn .slot-time  { font-size: 12.5px; font-weight: 700; }
.slot-btn .slot-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.75; }
.slot-btn.free   { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
.slot-btn.free:hover { border-color: var(--sky); background: var(--sky-pale); color: var(--sky-dark); transform: translateY(-2px); }
.slot-btn.booked { background: #fee2e2; border-color: #fecaca; color: #fca5a5; cursor: not-allowed; opacity: 0.8; position: relative; }
.slot-btn.booked::after { content:''; position:absolute; top:50%; left:6px; right:6px; height:1.5px; background:rgba(252,165,165,0.6); transform:rotate(-6deg); }
.slot-btn.selected { background: var(--sky) !important; border-color: var(--sky) !important; color: #fff !important; transform: translateY(-2px) scale(1.02) !important; }
.avail-pill { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 9px 14px; border-radius: 9px; border: 1.5px solid var(--sky-pale2); background: var(--sky-pale); min-width: 100px; }
.avail-pill .day-name { font-size: 11.5px; font-weight: 700; color: var(--sky-dark); }
.avail-pill .day-time { font-size: 10px; color: var(--ink70); }
.avail-pill .day-max  { font-size: 9px; color: var(--ink40); }

/* ── TWO COL LAYOUT ── */
.two-col { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; }
.three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

/* ── TABLE ── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 9px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.6px; padding: 9px 12px; border-bottom: 1px solid var(--border); background: #fafcff; }
td { padding: 10px 12px; font-size: 12px; color: var(--ink); border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8fcff; }
.view-all { font-size: 11px; color: var(--sky-dark); text-decoration: none; font-weight: 700; display: flex; align-items: center; gap: 4px; }
.view-all:hover { opacity: 0.7; }

/* ── BADGES ── */
.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:99px; font-size:9.5px; font-weight:700; }
.b-pending   { background:#fef3c7; color:#b45309;  border:1px solid #fde68a; }
.b-approved  { background:var(--sky-pale); color:var(--sky-dark); border:1px solid var(--sky-pale2); }
.b-completed { background:#d1fae5; color:#047857;  border:1px solid #a7f3d0; }
.b-cancelled { background:#fee2e2; color:#b91c1c;  border:1px solid #fecaca; }
.b-walkin    { background:#f3e8ff; color:#7c3aed;  border:1px solid #ddd6fe; }

/* ── PATIENT CELL ── */
.pat-cell { display:flex; align-items:center; gap:8px; }
.pat-av   { width:28px; height:28px; border-radius:50%; background:var(--sky-pale); display:flex; align-items:center; justify-content:center; font-size:9.5px; font-weight:700; color:var(--sky-dark); flex-shrink:0; }
.pat-name  { font-size:12px; font-weight:600; color:var(--ink); }
.pat-email { font-size:10px; color:var(--ink40); }

/* ── DOCTOR AVAILABILITY CARD ── */
.doc-list { display: flex; flex-direction: column; gap: 8px; }
.doc-item { display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: var(--bg); border-radius: 9px; border: 1px solid var(--border); }
.doc-info { display: flex; align-items: center; gap: 9px; }
.doc-av   { width: 30px; height: 30px; border-radius: 50%; background: var(--sky-pale2); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: var(--sky-dark); flex-shrink: 0; }
.doc-name { font-size: 12px; font-weight: 600; color: var(--ink); }
.doc-count { font-size: 10px; color: var(--ink40); margin-top: 1px; }
.avail-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.avail-free { background: #10b981; }
.avail-busy { background: #f59e0b; }
.avail-full { background: #ef4444; }

/* ── QUICK ACTIONS ── */
.quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.qa-btn { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; text-decoration: none; color: var(--ink); font-size: 12px; font-weight: 600; transition: all 0.15s; cursor: pointer; }
.qa-btn:hover { background: var(--sky-pale); border-color: var(--sky-pale2); color: var(--sky-dark); }
.qa-icon { width: 32px; height: 32px; border-radius: 8px; background: var(--sky-pale); display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--sky-dark); flex-shrink: 0; }

/* ── EMPTY ── */
.empty-state { text-align:center; padding:28px 16px; color:var(--ink40); }
.empty-state i { font-size:24px; margin-bottom:8px; display:block; color:var(--sky-pale2); }
.empty-state p { font-size:12.5px; }

@media(max-width:1200px) {
  .stats-row { grid-template-columns:repeat(3,1fr); }
  .book-grid { grid-template-columns:1fr 1fr; }
  .two-col { grid-template-columns:1fr; }
  .three-col { grid-template-columns:1fr 1fr; }
}
@media(max-width:900px) {
  .three-col { grid-template-columns:1fr; }
  .book-grid-wide { grid-template-columns:1fr; }
}
@media(max-width:768px) {
  :root { --sw:56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display:none; }
  .sidebar-logo { padding:16px 8px; justify-content:center; }
  .sidebar-nav a { padding:11px; justify-content:center; }
  .page-body { padding:14px; }
  .stats-row { grid-template-columns:repeat(2,1fr); }
  .book-grid { grid-template-columns:1fr; }
  .quick-actions { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-concierge-bell"></i></div>
    <div><div class="logo-name">Zaman Medical Center</div><div class="logo-sub">Reception</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="receptionistdasboard.php" class="active"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="receptionistappointments.php"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
    
   
    <a href="receptionistbilling.php"><i class="fas fa-file-invoice-dollar"></i><span>Billing</span></a>
    <div class="nav-section">Account</div>
    <a href="receptionistprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="receptionistchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Front Desk / Dashboard</div>
      <div class="topbar-title">Receptionist Dashboard</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Receptionist</div>
        </div>
      </div>
    </div>
  </header>

  <!-- TICKER -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php $tickers = ['Front Desk Active','Book Walk-in Appointments','Check Doctor Availability','Manage Patient Records','Today\'s Queue Live','All Systems Operational'];
      foreach (array_merge($tickers,$tickers) as $t): ?>
        <div class="ticker-item"><div class="ticker-dot"></div><?= $t ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PAGE BODY -->
  <div class="page-body">

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <i class="fas <?= $flash['type']==='success'?'fa-circle-check':($flash['type']==='warning'?'fa-triangle-exclamation':'fa-circle-xmark') ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card sc1"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="stat-label">Total Appts</div><div class="stat-val"><?= $total_appts ?></div><div class="stat-sub">All-time</div></div></div>
      <div class="stat-card sc2"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div><div><div class="stat-label">Today</div><div class="stat-val"><?= $today_appts ?></div><div class="stat-sub">Scheduled today</div></div></div>
      <div class="stat-card sc3"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-label">Pending</div><div class="stat-val"><?= $pending_appts ?></div><div class="stat-sub">In queue</div></div></div>
      <div class="stat-card sc4"><div class="stat-icon"><i class="fas fa-star"></i></div><div><div class="stat-label">Completed</div><div class="stat-val"><?= $completed_appts ?></div><div class="stat-sub">Done</div></div></div>
      <div class="stat-card sc5"><div class="stat-icon"><i class="fas fa-person-walking-arrow-right"></i></div><div><div class="stat-label">Walk-ins</div><div class="stat-val"><?= $walkin_appts ?></div><div class="stat-sub">Booked by you</div></div></div>
      <div class="stat-card sc6"><div class="stat-icon"><i class="fas fa-user-injured"></i></div><div><div class="stat-label">Patients</div><div class="stat-val"><?= $total_patients ?></div><div class="stat-sub">Registered</div></div></div>
    </div>

    <!-- BOOK WALK-IN -->
    <div class="section-label"><i class="fas fa-plus-circle"></i> Book Walk-in Appointment</div>

    <div class="book-card">
      <div class="card-hdr">
        <div>
          <div class="card-title"><i class="fas fa-person-walking-arrow-right"></i> New Walk-in Booking</div>
          <div class="card-sub">Choose a doctor and date to see their real schedule and open slots</div>
        </div>
        <div class="card-chip">Walk-in</div>
      </div>

      <!-- STEP 1: choose doctor + date -->
      <form method="GET" class="book-grid" style="align-items:end;">
        <div class="form-group">
          <label class="form-label">Doctor *</label>
          <select name="doctor_id" id="walkinDoctorSelect" class="form-control" required>
            <option value="">— Choose Doctor —</option>
            <?php foreach ($doctors as $d): ?>
              <option value="<?= $d['doctor_id'] ?>" <?= $selected_doctor === (int)$d['doctor_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['full_name']) ?><?= !empty($d['specialty']) ? ' — '.htmlspecialchars($d['specialty']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Date *</label>
          <input type="date" name="date" id="walkinDateInput" class="form-control" value="<?= htmlspecialchars($selected_date) ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">&nbsp;</label>
          <button type="submit" class="btn-book" style="margin-top:0;"><i class="fas fa-magnifying-glass"></i> View Slots</button>
        </div>
      </form>

      <!-- Doctor's working schedule (loaded via AJAX, same as patient page) -->
      <div id="walkinAvail" style="display:none;margin-top:14px;flex-wrap:wrap;gap:8px;">
        <div class="form-label" style="width:100%;margin-bottom:8px;">Doctor's Working Schedule</div>
        <div id="walkinAvailInner" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
      </div>
      <div id="walkinNoSched" style="display:none;margin-top:14px;">
        <div class="flash flash-warning"><i class="fas fa-triangle-exclamation"></i> This doctor has no working schedule set yet. Choose a different doctor.</div>
      </div>

      <!-- STEP 2: pick a slot, then confirm patient -->
      <?php if ($selected_doctor && $selected_date): ?>
        <div style="margin-top:18px;">
          <?php if ($total_count > 0): ?>
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
              <span class="badge b-approved"><?= $total_count - $booked_count ?> Available</span>
              <span class="badge b-pending"><?= $booked_count ?> Booked</span>
              <span style="font-size:11px;color:var(--ink40);"><?= date("l, d F Y", strtotime($selected_date)) ?></span>
            </div>
          <?php endif; ?>

          <?php if (empty($all_slots)): ?>
            <div class="empty-state">
              <i class="fas fa-calendar-xmark"></i>
              <p>No schedule found for this doctor on <?= date("l", strtotime($selected_date)) ?>. Try another date.</p>
            </div>
          <?php else: ?>
            <div class="slot-grid" id="walkinSlotGrid">
              <?php foreach ($all_slots as $slot): ?>
                <?php if ($slot['is_booked']): ?>
                  <div class="slot-btn booked">
                    <span class="slot-time"><?= date("h:i A", strtotime($slot['start_time'])) ?></span>
                    <span class="slot-label">Booked</span>
                  </div>
                <?php else: ?>
                  <div class="slot-btn free" id="wslot-<?= $slot['slot_id'] ?>"
                       onclick="selectWalkinSlot(<?= $slot['slot_id'] ?>, '<?= date('h:i A', strtotime($slot['start_time'])) ?>')">
                    <span class="slot-time"><?= date("h:i A", strtotime($slot['start_time'])) ?></span>
                    <span class="slot-label">Available</span>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <form method="POST" id="walkinBookingForm" style="display:none;margin-top:16px;border-top:1px dashed var(--border2);padding-top:16px;">
              <input type="hidden" name="action" value="book_walkin">
              <input type="hidden" name="doctor_id" value="<?= $selected_doctor ?>">
              <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
              <input type="hidden" name="slot_id" id="walkinHiddenSlotId" value="">

              <div style="display:flex;align-items:center;gap:10px;background:var(--sky-pale);border:1px solid var(--border2);border-radius:9px;padding:10px 14px;margin-bottom:14px;">
                <i class="fas fa-clock" style="color:var(--sky-dark);"></i>
                <span style="font-size:12.5px;font-weight:700;">Selected time: <span id="walkinSelectedTime">—</span></span>
              </div>

              <div class="book-grid-wide">
                <div class="form-group">
                  <div style="display:flex;align-items:center;justify-content:space-between;">
                    <label class="form-label">Select Patient *</label>
                    <span id="newPatientToggle" style="font-size:10.5px;font-weight:700;color:var(--sky-dark);cursor:pointer;">
                      <i class="fas fa-user-plus"></i> New Patient
                    </span>
                  </div>
                  <select name="patient_id" id="walkinPatientSelect" class="form-control" required>
                    <option value="">— Choose Patient —</option>
                    <?php foreach ($patients as $p): ?>
                      <option value="<?= $p['user_id'] ?>"><?= htmlspecialchars($p['full_name']) ?> (<?= htmlspecialchars($p['email']) ?>)</option>
                    <?php endforeach; ?>
                  </select>

                  <!-- Quick-add new patient (AJAX, keeps slot selection intact) -->
                  <div id="newPatientPanel" style="display:none;margin-top:8px;padding:14px;background:var(--bg);border:1px dashed var(--border2);border-radius:9px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                      <input type="text" id="npFullName" class="form-control" placeholder="Full Name *" style="margin:0;">
                      <input type="email" id="npEmail" class="form-control" placeholder="Email *" style="margin:0;">
                      <input type="text" id="npCnic" class="form-control" placeholder="CNIC (e.g. 35201-1234567-1) *" style="margin:0;grid-column:span 2;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:10px;align-items:center;">
                      <button type="button" id="npSubmitBtn" class="btn-book" style="margin-top:0;padding:8px 16px;font-size:11.5px;">
                        <i class="fas fa-check"></i> Create &amp; Select
                      </button>
                      <span id="npError" style="font-size:11px;color:#b91c1c;"></span>
                    </div>
                    <div id="npSuccessNote" style="display:none;margin-top:10px;padding:10px 12px;background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;font-size:11px;color:#065f46;"></div>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Notes / Reason</label>
                  <input type="text" name="notes" class="form-control" placeholder="e.g. Fever, follow-up, general checkup…">
                </div>
              </div>

              <button type="submit" class="btn-book"><i class="fas fa-calendar-plus"></i> Confirm Walk-in Booking</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- TODAY'S QUEUE + DOCTOR AVAILABILITY -->
    <div class="section-label"><i class="fas fa-list"></i> Today's Queue & Doctor Availability</div>

    <div class="two-col">

      <!-- Today's appointments -->
      <div class="card">
        <div class="card-hdr" style="margin-bottom:12px;">
          <div class="card-title"><i class="fas fa-clock"></i> Today's Appointments</div>
          <a href="receptionistappointments.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($today_list)): ?>
        <div class="empty-state"><i class="fas fa-calendar-xmark"></i><p>No appointments scheduled today</p></div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table>
          <thead><tr><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($today_list as $a):
              $ini = strtoupper(substr($a['patient_name'] ?? 'PA', 0, 2));
              $sc = ['pending'=>'b-pending','approved'=>'b-approved','completed'=>'b-completed','cancelled'=>'b-cancelled'];
              $st = $a['status'];
            ?>
            <tr>
              <td>
                <div class="pat-cell">
                  <div class="pat-av"><?= htmlspecialchars($ini) ?></div>
                  <div>
                    <div class="pat-name"><?= htmlspecialchars($a['patient_name']) ?></div>
                    <div class="pat-email"><?= htmlspecialchars($a['patient_email'] ?? '') ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:11.5px;color:var(--ink70)"><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
              <td><span class="badge <?= $sc[$st] ?? 'b-pending' ?>"><?= ucfirst($st) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Doctor Availability -->
      <div class="card">
        <div class="card-hdr" style="margin-bottom:14px;">
          <div class="card-title"><i class="fas fa-user-doctor"></i> Doctor Availability</div>
          <div class="card-chip">Today</div>
        </div>
        <?php if (empty($doctor_availability)): ?>
        <div class="empty-state"><i class="fas fa-user-doctor"></i><p>No active doctors found</p></div>
        <?php else: ?>
        <div class="doc-list">
          <?php foreach ($doctor_availability as $doc):
            $count = $doc['count'];
            $dotClass = $count < 5 ? 'avail-free' : ($count < 10 ? 'avail-busy' : 'avail-full');
            $label    = $count < 5 ? 'Available' : ($count < 10 ? 'Busy' : 'Fully Booked');
            $ini = strtoupper(substr($doc['name'], 0, 2));
          ?>
          <div class="doc-item">
            <div class="doc-info">
              <div class="doc-av"><?= htmlspecialchars($ini) ?></div>
              <div>
                <div class="doc-name"><?= htmlspecialchars($doc['name']) ?></div>
                <div class="doc-count"><?= $count ?> appointment<?= $count !== 1 ? 's' : '' ?> today</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:5px;">
              <div class="avail-dot <?= $dotClass ?>"></div>
              <span style="font-size:10.5px;font-weight:600;color:var(--ink70)"><?= $label ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- CHART + QUICK ACTIONS + RECENT PATIENTS -->
    <div class="section-label"><i class="fas fa-chart-bar"></i> Analytics & Quick Actions</div>

    <div class="three-col">

      <!-- Monthly Chart -->
      <div class="card">
        <div class="card-hdr" style="margin-bottom:4px;">
          <div class="card-title"><i class="fas fa-chart-bar"></i> Monthly Appointments</div>
          <div class="card-chip">6 Months</div>
        </div>
        <div class="card-sub">Booking volume — last 6 months</div>
        <div style="position:relative;width:100%;height:130px;">
          <canvas id="monthlyChart"></canvas>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="card">
        <div class="card-title" style="margin-bottom:4px;"><i class="fas fa-bolt"></i> Quick Actions</div>
        <div class="card-sub">Common front-desk tasks</div>
        <div class="quick-actions">
          <a href="receptionistappointments.php" class="qa-btn">
            <div class="qa-icon"><i class="fas fa-calendar-check"></i></div>
            All Appointments
          </a>
          <a href="receptionistpatients.php" class="qa-btn">
            <div class="qa-icon"><i class="fas fa-user-injured"></i></div>
            Patient List
          </a>
          <a href="receptionistdoctors.php" class="qa-btn">
            <div class="qa-icon"><i class="fas fa-user-doctor"></i></div>
            Doctor List
          </a>
          <a href="receptionistprofile.php" class="qa-btn">
            <div class="qa-icon"><i class="fas fa-user-circle"></i></div>
            My Profile
          </a>
          <button class="qa-btn" onclick="scrollToBooking()">
            <div class="qa-icon"><i class="fas fa-plus"></i></div>
            New Walk-in
          </button>
          <a href="patientchangepassword.php" class="qa-btn">
            <div class="qa-icon"><i class="fas fa-lock"></i></div>
            Change Password
          </a>
        </div>
      </div>

      <!-- Recent Patients -->
      <div class="card">
        <div class="card-hdr" style="margin-bottom:12px;">
          <div class="card-title"><i class="fas fa-user-injured"></i> Recent Patients</div>
          <a href="receptionistpatients.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($recent_patients)): ?>
        <div class="empty-state"><i class="fas fa-user-slash"></i><p>No patients yet</p></div>
        <?php else: ?>
        <div class="doc-list">
          <?php foreach ($recent_patients as $p):
            $ini = strtoupper(substr($p['full_name'], 0, 2));
          ?>
          <div class="doc-item">
            <div class="doc-info">
              <div class="doc-av"><?= htmlspecialchars($ini) ?></div>
              <div>
                <div class="doc-name"><?= htmlspecialchars($p['full_name']) ?></div>
                <div class="doc-count"><?= htmlspecialchars($p['email']) ?></div>
              </div>
            </div>
            <span style="font-size:9.5px;color:var(--ink40)"><?= date('M d', strtotime($p['created_at'])) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
Chart.defaults.color = 'rgba(12,45,63,0.45)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

const mLabels = <?= json_encode($m_labels) ?>;
const mTotals = <?= json_encode($m_totals) ?>;

new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: mLabels,
    datasets: [{
      label: 'Appointments',
      data: mTotals,
      backgroundColor: 'rgba(14,165,233,0.52)',
      borderColor: '#0ea5e9',
      borderWidth: 1.5,
      borderRadius: 6,
      maxBarThickness: 36
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#0c2d3f', padding:9, cornerRadius:7 } },
    scales: {
      x: { grid:{ display:false } },
      y: { grid:{ color:'rgba(14,165,233,0.07)' }, beginAtZero:true, ticks:{ precision:0 } }
    }
  }
});

// ── Walk-in: load doctor's working schedule (same pattern as patient booking page) ──
const wDoctorSelect = document.getElementById('walkinDoctorSelect');
const wAvailBox      = document.getElementById('walkinAvail');
const wAvailInner    = document.getElementById('walkinAvailInner');
const wNoSched        = document.getElementById('walkinNoSched');

function wFmt12(t) {
  const [h, m] = t.split(':');
  const hour = parseInt(h);
  const ampm = hour >= 12 ? 'PM' : 'AM';
  const h12  = hour % 12 || 12;
  return `${h12}:${m} ${ampm}`;
}

function loadWalkinSchedule(doctorId) {
  if (!doctorId) { wAvailBox.style.display = 'none'; wNoSched.style.display = 'none'; return; }
  fetch(`?fetch_schedule=1&doctor_id=${doctorId}`)
    .then(r => r.json())
    .then(data => {
      wAvailBox.style.display = 'none';
      wNoSched.style.display  = 'none';
      wAvailInner.innerHTML   = '';
      if (!data.length) { wNoSched.style.display = 'block'; return; }
      data.forEach(row => {
        const pill = document.createElement('div');
        pill.className = 'avail-pill';
        pill.innerHTML = `<span class="day-name">${row.working_day}</span><span class="day-time">${wFmt12(row.start_time)}–${wFmt12(row.end_time)}</span><span class="day-max">${row.max_patients}/day</span>`;
        wAvailInner.appendChild(pill);
      });
      wAvailBox.style.display = 'flex';
    })
    .catch(() => { wNoSched.style.display = 'block'; });
}
if (wDoctorSelect) {
  wDoctorSelect.addEventListener('change', function () { loadWalkinSchedule(this.value); });
  if (wDoctorSelect.value) loadWalkinSchedule(wDoctorSelect.value);
}

// ── Walk-in: slot selection ──
let wCurrentSelected = null;
function selectWalkinSlot(slotId, timeStr) {
  if (wCurrentSelected !== null) {
    const prev = document.getElementById('wslot-' + wCurrentSelected);
    if (prev) { prev.classList.remove('selected'); prev.classList.add('free'); }
  }
  const el = document.getElementById('wslot-' + slotId);
  if (el) { el.classList.remove('free'); el.classList.add('selected'); }
  wCurrentSelected = slotId;
  document.getElementById('walkinHiddenSlotId').value = slotId;
  document.getElementById('walkinSelectedTime').textContent = timeStr;
  const form = document.getElementById('walkinBookingForm');
  form.style.display = 'block';
  form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── Walk-in: quick-add new patient inline ──
const npToggle      = document.getElementById('newPatientToggle');
const npPanel        = document.getElementById('newPatientPanel');
const npSubmitBtn    = document.getElementById('npSubmitBtn');
const npError         = document.getElementById('npError');
const npSuccessNote  = document.getElementById('npSuccessNote');
const walkinPatientSelect = document.getElementById('walkinPatientSelect');

if (npToggle) {
  npToggle.addEventListener('click', function () {
    const open = npPanel.style.display === 'block';
    npPanel.style.display = open ? 'none' : 'block';
    npToggle.innerHTML = open
      ? '<i class="fas fa-user-plus"></i> New Patient'
      : '<i class="fas fa-xmark"></i> Cancel';
  });
}

if (npSubmitBtn) {
  npSubmitBtn.addEventListener('click', function () {
    const full_name = document.getElementById('npFullName').value.trim();
    const email     = document.getElementById('npEmail').value.trim();
    const cnic      = document.getElementById('npCnic').value.trim();

    npError.textContent = '';
    if (!full_name || !email || !cnic) {
      npError.textContent = 'Please fill in name, email and CNIC.';
      return;
    }

    npSubmitBtn.disabled = true;
    npSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';

    const body = new URLSearchParams({ ajax_action: 'add_patient', full_name, email, cnic });

    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
      .then(r => r.json())
      .then(data => {
        npSubmitBtn.disabled = false;
        npSubmitBtn.innerHTML = '<i class="fas fa-check"></i> Create &amp; Select';

        if (!data.success) {
          npError.textContent = data.error || 'Could not create patient.';
          return;
        }

        // Add + select the new patient in the dropdown
        const opt = document.createElement('option');
        opt.value = data.user_id;
        opt.textContent = `${data.full_name} (${data.email})`;
        opt.selected = true;
        walkinPatientSelect.appendChild(opt);
        walkinPatientSelect.value = data.user_id;

        npSuccessNote.style.display = 'block';
        npSuccessNote.innerHTML = `Patient created and selected. Login: <strong>${data.username}</strong> / Temp password: <strong>${data.temp_password}</strong> — share this with the patient.`;

        document.getElementById('npFullName').value = '';
        document.getElementById('npEmail').value = '';
        document.getElementById('npCnic').value = '';
      })
      .catch(() => {
        npSubmitBtn.disabled = false;
        npSubmitBtn.innerHTML = '<i class="fas fa-check"></i> Create &amp; Select';
        npError.textContent = 'Network error. Please try again.';
      });
  });
}

// Scroll to booking form
function scrollToBooking() {
  document.querySelector('.book-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Auto-dismiss flash
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.display = 'none', 4000);
</script>
</body>
</html>