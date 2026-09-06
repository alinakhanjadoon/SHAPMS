<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

$nurse_user_id = $_SESSION['user_id'];

/* =========================================================
   ONE-TIME SCHEMA EXTENSIONS (safe to leave in — they only
   create tables/columns if they don't already exist).
   Run once, then feel free to delete this block.
========================================================= */
$conn->query("CREATE TABLE IF NOT EXISTS nurse_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    nurse_id INT NOT NULL,
    patient_id INT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS patient_vitals (
    vital_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    heart_rate INT NULL,
    blood_pressure VARCHAR(20) NULL,
    blood_sugar DECIMAL(6,2) NULL,
    temperature DECIMAL(5,2) NULL,
    spo2 INT NULL,
    resp_rate INT NULL,
    recorded_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS medication_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    medicine_name VARCHAR(150) NOT NULL,
    dose VARCHAR(50) NULL,
    route VARCHAR(50) NULL,
    scheduled_time DATETIME NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    administered_by INT NULL,
    administered_at DATETIME NULL
)");
$conn->query("CREATE TABLE IF NOT EXISTS nursing_notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    note_type VARCHAR(50) DEFAULT 'General Observation',
    note_text TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS beds (
    bed_id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(20) NOT NULL,
    bed_number VARCHAR(20) NOT NULL,
    patient_id INT NULL,
    status VARCHAR(20) DEFAULT 'available'
)");

// ── Current nurse ──
$user = ['full_name' => 'Nurse', 'department' => 'General'];
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $nurse_user_id);
$stmt->execute();
$stmt->bind_result($fn, $pi);
if ($stmt->fetch()) { $user['full_name'] = $fn ?: 'Nurse'; $user['profile_image'] = $pi ?: ''; }
$stmt->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));

$nq = $conn->prepare("SELECT nurse_id, department FROM nurses WHERE user_id=?");
$nq->bind_param("i", $nurse_user_id);
$nq->execute();
$nq->bind_result($nurse_id, $department);
$nq->fetch();
$nq->close();
$user['department'] = $department ?: 'General';

// ── Flash ──
$flash = null;
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

/* =========================================================
   AJAX HANDLERS
========================================================= */

// ── Record vitals ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'save_vitals') {
    header('Content-Type: application/json');
    $patient_id = (int)($_POST['patient_id'] ?? 0);

    $puq = $conn->prepare("SELECT user_id FROM patients WHERE patient_id=?");
    $puq->bind_param("i", $patient_id);
    $puq->execute();
    $puq->bind_result($puser_id);
    $puq->fetch();
    $puq->close();

    if (!$puser_id) { echo json_encode(['success'=>false,'error'=>'Patient not found.']); exit(); }

    $hr   = $_POST['heart_rate'] !== '' ? (int)$_POST['heart_rate'] : null;
    $bp   = trim($_POST['blood_pressure'] ?? '');
    $temp = $_POST['temperature'] !== '' ? (float)$_POST['temperature'] : null;
    $spo2 = $_POST['spo2'] !== '' ? (int)$_POST['spo2'] : null;
    $glu  = $_POST['glucose'] !== '' ? (float)$_POST['glucose'] : null;
    $rr   = $_POST['resp_rate'] !== '' ? (int)$_POST['resp_rate'] : null;

    $ins = $conn->prepare("INSERT INTO patient_vitals
        (user_id, date, heart_rate, blood_pressure, blood_sugar, temperature, spo2, resp_rate, recorded_by)
        VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("iisdddii", $puser_id, $hr, $bp, $glu, $temp, $spo2, $rr, $nurse_user_id);
    $ok = $ins->execute();
    echo json_encode(['success'=>$ok, 'error'=>$ok?null:$ins->error]);
    $ins->close();
    exit();
}

// ── Administer medication ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'administer_med') {
    header('Content-Type: application/json');
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);

    $upd = $conn->prepare("UPDATE medication_schedule SET status='done', administered_by=?, administered_at=NOW() WHERE schedule_id=?");
    $upd->bind_param("ii", $nurse_user_id, $schedule_id);
    $ok = $upd->execute();
    echo json_encode(['success'=>$ok, 'error'=>$ok?null:$upd->error]);
    $upd->close();
    exit();
}

// ── Save nursing note (Activity #1) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'save_note') {
    header('Content-Type: application/json');
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $note_type  = trim($_POST['note_type'] ?? 'General Observation');
    $note_text  = trim($_POST['note_text'] ?? '');

    if (!$patient_id || !$note_text) { echo json_encode(['success'=>false,'error'=>'Patient and note text are required.']); exit(); }

    $ins = $conn->prepare("INSERT INTO nursing_notes (patient_id, note_type, note_text, created_by, created_at) VALUES (?,?,?,?,NOW())");
    $ins->bind_param("issi", $patient_id, $note_type, $note_text, $nurse_user_id);
    $ok = $ins->execute();
    $note_id = $conn->insert_id;
    $ins->close();
    echo json_encode([
        'success'   => $ok,
        'error'     => $ok ? null : $conn->error,
        'note_id'   => $note_id,
        'created_at'=> date('M d, h:i A')
    ]);
    exit();
}

// ── Allocate bed to patient (Activity #2) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'allocate_bed') {
    header('Content-Type: application/json');
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $bed_id     = (int)($_POST['bed_id'] ?? 0);

    if (!$patient_id || !$bed_id) { echo json_encode(['success'=>false,'error'=>'Patient and bed are required.']); exit(); }

    // Free up any bed currently held by this patient
    $free = $conn->prepare("UPDATE beds SET patient_id=NULL, status='available' WHERE patient_id=?");
    $free->bind_param("i", $patient_id);
    $free->execute();
    $free->close();

    // Make sure target bed is actually available
    $chk = $conn->prepare("SELECT bed_id FROM beds WHERE bed_id=? AND status='available'");
    $chk->bind_param("i", $bed_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        echo json_encode(['success'=>false,'error'=>'That bed is no longer available.']);
        exit();
    }
    $chk->close();

    $upd = $conn->prepare("UPDATE beds SET patient_id=?, status='occupied' WHERE bed_id=?");
    $upd->bind_param("ii", $patient_id, $bed_id);
    $ok = $upd->execute();
    $upd->close();

    $rq = $conn->prepare("SELECT room_number, bed_number FROM beds WHERE bed_id=?");
    $rq->bind_param("i", $bed_id);
    $rq->execute();
    $rq->bind_result($room_number, $bed_number);
    $rq->fetch();
    $rq->close();

    echo json_encode(['success'=>$ok, 'error'=>$ok?null:$conn->error, 'room_number'=>$room_number, 'bed_number'=>$bed_number]);
    exit();
}

// ── Admit patient (creates user + patient + bed + nurse assignment) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'admit_patient') {
    header('Content-Type: application/json');
    $full_name = trim($_POST['full_name'] ?? '');
    $age       = (int)($_POST['age'] ?? 0);
    $gender    = trim($_POST['gender'] ?? 'other');
    $bed_id    = (int)($_POST['bed_id'] ?? 0);
    $condition = trim($_POST['condition'] ?? '');

    if (!$full_name || !$bed_id) {
        echo json_encode(['success'=>false,'error'=>'Name and bed are required.']);
        exit();
    }

    $username = strtolower(preg_replace('/[^a-z0-9]/i','', $full_name)) . rand(100,999);
    $temp_password = substr(bin2hex(random_bytes(4)), 0, 8);
    $hashed = password_hash($temp_password, PASSWORD_DEFAULT);
    $email = $username . '@placeholder.local';

    $ins = $conn->prepare("INSERT INTO users (full_name, username, email, password_hash, role, status) VALUES (?,?,?,?, 'patient', 'active')");
    $ins->bind_param("ssss", $full_name, $username, $email, $hashed);
    $ins->execute();
    $new_user_id = $conn->insert_id;
    $ins->close();

    $pins = $conn->prepare("INSERT INTO patients (user_id, age, gender, `condition`, clinical_status) VALUES (?,?,?,?, 'stable')");
    $pins->bind_param("iiss", $new_user_id, $age, $gender, $condition);
    $pins->execute();
    $new_patient_id = $conn->insert_id;
    $pins->close();

    $bupd = $conn->prepare("UPDATE beds SET patient_id=?, status='occupied' WHERE bed_id=?");
    $bupd->bind_param("ii", $new_patient_id, $bed_id);
    $bupd->execute();
    $bupd->close();

    $nains = $conn->prepare("INSERT INTO nurse_assignments (nurse_id, patient_id) VALUES (?,?)");
    $nains->bind_param("ii", $nurse_id, $new_patient_id);
    $nains->execute();
    $nains->close();

    echo json_encode(['success'=>true, 'username'=>$username, 'temp_password'=>$temp_password]);
    exit();
}

/* =========================================================
   PAGE DATA (real queries — no hardcoded JS objects)
========================================================= */

// ── Assigned patients ──
$patients = [];
$pq = $conn->prepare("
    SELECT p.patient_id, p.user_id, u.full_name, p.`condition`, p.clinical_status,
           b.room_number, b.bed_number,
           (SELECT MAX(pv.date) FROM patient_vitals pv WHERE pv.user_id = p.user_id) AS last_vital_date
    FROM nurse_assignments na
    JOIN patients p ON na.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN beds b ON b.patient_id = p.patient_id
    WHERE na.nurse_id = ?
    GROUP BY p.patient_id
    ORDER BY b.room_number
");
$pq->bind_param("i", $nurse_id);
$pq->execute();
$res = $pq->get_result();
while ($row = $res->fetch_assoc()) $patients[] = $row;
$pq->close();

// ── Stats ──
$total_patients = count($patients);
$critical_count = count(array_filter($patients, fn($p) => $p['clinical_status'] === 'critical'));

// ── Today's medication schedule ──
$medications = [];
$mq = $conn->prepare("
    SELECT ms.schedule_id, ms.medicine_name, ms.dose, ms.scheduled_time, ms.status,
           p.patient_id, u.full_name AS patient_name, b.room_number
    FROM medication_schedule ms
    JOIN nurse_assignments na ON na.patient_id = ms.patient_id
    JOIN patients p ON ms.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN beds b ON b.patient_id = p.patient_id
    WHERE na.nurse_id = ? AND DATE(ms.scheduled_time) = CURDATE()
    ORDER BY ms.scheduled_time
");
$mq->bind_param("i", $nurse_id);
$mq->execute();
$medications = $mq->get_result()->fetch_all(MYSQLI_ASSOC);
$mq->close();

$meds_today   = count($medications);
$meds_done    = count(array_filter($medications, fn($m) => $m['status'] === 'done'));
$meds_pending = $meds_today - $meds_done;

// ── Today's appointments ──
$appointments = [];
$aq = $conn->prepare("
    SELECT a.appointment_date, u.full_name AS patient_name, b.room_number, du.full_name AS doctor_name
    FROM appointments a
    JOIN nurse_assignments na ON na.patient_id = a.patient_id
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN beds b ON b.patient_id = p.patient_id
    LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN users du ON d.user_id = du.user_id
    WHERE na.nurse_id = ? AND DATE(a.appointment_date) = CURDATE()
    ORDER BY a.appointment_date
");
$aq->bind_param("i", $nurse_id);
$aq->execute();
$appointments = $aq->get_result()->fetch_all(MYSQLI_ASSOC);
$aq->close();

// ── Available beds (for admit + allocate modals) ──
$available_beds = [];
$bq = $conn->query("SELECT bed_id, room_number, bed_number FROM beds WHERE status='available' ORDER BY room_number, bed_number");
if ($bq) while ($row = $bq->fetch_assoc()) $available_beds[] = $row;

// ── Latest vitals snapshot per assigned patient (most recent reading) ──
$latest_vitals = [];
foreach ($patients as $p) {
    $vq = $conn->prepare("SELECT heart_rate, blood_pressure, temperature, spo2, blood_sugar, resp_rate, date
                           FROM patient_vitals WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $vq->bind_param("i", $p['user_id']);
    $vq->execute();
    $vr = $vq->get_result()->fetch_assoc();
    $vq->close();
    if ($vr) $latest_vitals[$p['patient_id']] = $vr;
}

// ── Recent nursing notes ──
$recent_notes = [];
$nrq = $conn->prepare("
    SELECT nn.note_id, nn.note_type, nn.note_text, nn.created_at, u.full_name AS patient_name
    FROM nursing_notes nn
    JOIN patients p ON nn.patient_id = p.patient_id
    JOIN nurse_assignments na ON na.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    WHERE na.nurse_id = ?
    ORDER BY nn.created_at DESC LIMIT 6
");
$nrq->bind_param("i", $nurse_id);
$nrq->execute();
$recent_notes = $nrq->get_result()->fetch_all(MYSQLI_ASSOC);
$nrq->close();

// ── Alerts: overdue meds + critical patients ──
$alerts = [];
foreach ($medications as $m) {
    if ($m['status'] !== 'done' && strtotime($m['scheduled_time']) < time()) {
        $alerts[] = [
            'title' => 'Medication Overdue',
            'desc'  => htmlspecialchars($m['medicine_name']) . ' for ' . htmlspecialchars($m['patient_name']) . ' was due at ' . date('h:i A', strtotime($m['scheduled_time'])),
            'severity' => 'urgent', 'icon' => '💉'
        ];
    }
}
foreach ($patients as $p) {
    if ($p['clinical_status'] === 'critical') {
        $alerts[] = [
            'title' => 'Critical Patient',
            'desc'  => htmlspecialchars($p['full_name']) . ' — Room ' . htmlspecialchars($p['room_number'] ?? 'Unassigned'),
            'severity' => 'urgent', 'icon' => '🚨'
        ];
    }
}

// ── 7-day patient load chart (assignments made per day, last 7 days) ──
$chart_labels = [];
$chart_values = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('D', strtotime($d));
    $cq = $conn->prepare("SELECT COUNT(*) FROM nurse_assignments WHERE nurse_id=? AND DATE(assigned_at)<=?");
    $cq->bind_param("is", $nurse_id, $d);
    $cq->execute();
    $cq->bind_result($cnt);
    $cq->fetch();
    $chart_values[] = (int)($cnt ?? 0);
    $cq->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zaman Medical Center — Nurse Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --mint:       #5BBFB5;
    --mint-light: #A8DDD8;
    --mint-pale:  #E8F7F6;
    --mint-dark:  #3A9E94;
    --teal-deep:  #2C7873;
    --white:      #FFFFFF;
    --off-white:  #F4FAFA;
    --gray-50:    #F9FAFB;
    --gray-100:   #F3F4F6;
    --gray-200:   #E5E7EB;
    --gray-400:   #9CA3AF;
    --gray-600:   #6B7280;
    --gray-800:   #1F2937;
    --red:        #EF4444;
    --red-light:  #FEE2E2;
    --amber:      #F59E0B;
    --amber-light:#FEF3C7;
    --green:      #10B981;
    --green-light:#D1FAE5;
    --blue:       #3B82F6;
    --blue-light: #DBEAFE;
    --purple:     #8B5CF6;
    --purple-light:#EDE9FE;
    --shadow-sm:  0 1px 3px rgba(91,191,181,0.12);
    --shadow-md:  0 4px 16px rgba(91,191,181,0.18);
    --shadow-lg:  0 8px 32px rgba(91,191,181,0.22);
    --radius:     14px;
    --radius-sm:  8px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', sans-serif;
    background: var(--off-white);
    color: var(--gray-800);
    min-height: 100vh;
    display: flex;
    overflow-x: hidden;
  }

  /* ─── SIDEBAR ─── */
  .sidebar {
    width: 260px;
    min-height: 100vh;
    background: linear-gradient(160deg, var(--teal-deep) 0%, var(--mint-dark) 60%, var(--mint) 100%);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
    box-shadow: 4px 0 24px rgba(44,120,115,0.18);
  }
  .sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.15); }
  .sidebar-logo .brand { font-family: 'Poppins', sans-serif; font-size: 22px; font-weight: 700; color: var(--white); letter-spacing: -0.5px; }
  .sidebar-logo .brand span { color: var(--mint-light); }
  .sidebar-logo .tagline { font-size: 11px; color: rgba(255,255,255,0.6); margin-top: 3px; letter-spacing: 0.5px; text-transform: uppercase; }

  .nurse-profile { padding: 20px 24px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.12); }
  .nurse-avatar { width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.25); display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight:700; border: 2px solid rgba(255,255,255,0.4); flex-shrink: 0; color:#fff; }
  .nurse-name { font-size: 14px; font-weight: 600; color: #fff; }
  .nurse-role { font-size: 11px; color: rgba(255,255,255,0.65); margin-top: 1px; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ADE80; margin-left: auto; flex-shrink: 0; box-shadow: 0 0 0 2px rgba(74,222,128,0.3); }

  .nav-section { padding: 16px 0; flex: 1; overflow-y: auto; }
  .nav-label { font-size: 10px; font-weight: 600; color: rgba(255,255,255,0.45); letter-spacing: 1.2px; text-transform: uppercase; padding: 8px 24px 4px; }
  .nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 24px; cursor: pointer; transition: all 0.2s; color: rgba(255,255,255,0.75); font-size: 14px; font-weight: 500; border-left: 3px solid transparent; position: relative; text-decoration:none; }
  .nav-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
  .nav-item.active { background: rgba(255,255,255,0.18); color: #fff; border-left-color: #fff; }
  .nav-icon { font-size: 17px; width: 20px; text-align: center; }
  .nav-badge { margin-left: auto; background: var(--red); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
  .nav-badge.amber { background: var(--amber); color: #fff; }

  .sidebar-footer { padding: 16px 24px; border-top: 1px solid rgba(255,255,255,0.12); }
  .logout-btn { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500; cursor: pointer; transition: color 0.2s; padding: 8px 0; text-decoration:none; }
  .logout-btn:hover { color: #fff; }

  /* ─── MAIN ─── */
  .main { margin-left: 260px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; min-width:0; }

  .topbar { background: var(--white); height: 68px; display: flex; align-items: center; padding: 0 32px; gap: 20px; border-bottom: 1px solid var(--gray-200); position: sticky; top: 0; z-index: 50; box-shadow: var(--shadow-sm); }
  .topbar-title { font-family: 'Poppins', sans-serif; font-size: 20px; font-weight: 700; color: var(--gray-800); flex: 1; }
  .topbar-title span { color: var(--mint-dark); }
  .search-bar { display: flex; align-items: center; gap: 8px; background: var(--gray-100); border-radius: 10px; padding: 8px 14px; width: 240px; border: 1.5px solid transparent; transition: border-color 0.2s; }
  .search-bar:focus-within { border-color: var(--mint); background: var(--white); }
  .search-bar input { border: none; background: none; outline: none; font-size: 13px; color: var(--gray-800); width: 100%; }
  .search-bar input::placeholder { color: var(--gray-400); }
  .topbar-actions { display: flex; align-items: center; gap: 12px; }
  .icon-btn { width: 38px; height: 38px; border-radius: 10px; background: var(--gray-100); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; position: relative; transition: background 0.2s; }
  .icon-btn:hover { background: var(--mint-pale); }
  .icon-btn .badge { position: absolute; top: 5px; right: 5px; width: 8px; height: 8px; border-radius: 50%; background: var(--red); border: 1.5px solid var(--white); }
  .date-chip { background: var(--mint-pale); color: var(--mint-dark); font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 8px; }

  .page { padding: 28px 32px; }

  /* ── FLASH ── */
  .flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; margin-bottom:18px; }
  .flash-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .flash-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

  .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px; }
  .stat-card { background: var(--white); border-radius: var(--radius); padding: 22px 20px; display: flex; align-items: flex-start; gap: 14px; box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); transition: box-shadow 0.2s, transform 0.2s; cursor: default; }
  .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
  .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
  .stat-icon.mint   { background: var(--mint-pale); }
  .stat-icon.red    { background: var(--red-light); }
  .stat-icon.amber  { background: var(--amber-light); }
  .stat-icon.green  { background: var(--green-light); }
  .stat-info { flex: 1; }
  .stat-label { font-size: 12px; font-weight: 500; color: var(--gray-600); margin-bottom: 4px; }
  .stat-value { font-size: 28px; font-weight: 700; color: var(--gray-800); line-height: 1; }
  .stat-sub { font-size: 11px; color: var(--gray-400); margin-top: 5px; }
  .stat-sub .up   { color: var(--green); font-weight: 600; }
  .stat-sub .down { color: var(--red);   font-weight: 600; }

  .main-grid { display: grid; grid-template-columns: 1fr 340px; gap: 20px; margin-bottom: 24px; }

  .card { background: var(--white); border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); }
  .card-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px 0; }
  .card-title { font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 700; color: var(--gray-800); }
  .card-action { font-size: 12px; font-weight: 600; color: var(--mint-dark); cursor: pointer; padding: 5px 12px; border-radius: 7px; transition: background 0.2s; background:none; border:none; }
  .card-action:hover { background: var(--mint-pale); }
  .card-body { padding: 16px 22px 20px; }

  .patient-table { width: 100%; border-collapse: collapse; }
  .patient-table th { font-size: 11px; font-weight: 600; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.8px; padding: 8px 12px; text-align: left; border-bottom: 1.5px solid var(--gray-100); }
  .patient-table td { padding: 12px 12px; font-size: 13px; border-bottom: 1px solid var(--gray-50); vertical-align: middle; }
  .patient-table tr:last-child td { border-bottom: none; }
  .patient-table tr:hover td { background: var(--mint-pale); }

  .pt-avatar { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: var(--white); flex-shrink: 0; background: var(--mint-dark); }
  .pt-info { display: flex; align-items: center; gap: 10px; }
  .pt-name { font-weight: 600; font-size: 13px; color: var(--gray-800); }
  .pt-id   { font-size: 11px; color: var(--gray-400); }

  .badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
  .badge.critical { background: var(--red-light);   color: var(--red); }
  .badge.stable   { background: var(--green-light);  color: var(--green); }
  .badge.moderate { background: var(--amber-light);  color: var(--amber); }
  .badge.recovering, .badge.obs { background: var(--blue-light); color: var(--blue); }

  .action-dots { cursor: pointer; font-size: 16px; color: var(--gray-400); padding: 4px 8px; border-radius: 6px; transition: background 0.2s; background:none; border:none; }
  .action-dots:hover { background: var(--gray-100); }

  .vitals-list { display: flex; flex-direction: column; gap: 12px; }
  .vital-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: var(--radius-sm); background: var(--gray-50); border: 1px solid var(--gray-100); transition: all 0.2s; }
  .vital-row:hover { background: var(--mint-pale); border-color: var(--mint-light); }
  .vital-icon { font-size: 20px; }
  .vital-info { flex: 1; }
  .vital-label { font-size: 11px; color: var(--gray-400); font-weight: 500; }
  .vital-val { font-size: 16px; font-weight: 700; color: var(--gray-800); margin-top: 1px; }
  .vital-unit { font-size: 10px; color: var(--gray-400); font-weight: 400; }
  .vital-trend { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
  .vital-trend.ok   { background: var(--green-light); color: var(--green); }
  .vital-trend.warn { background: var(--amber-light); color: var(--amber); }
  .vital-trend.bad  { background: var(--red-light);   color: var(--red); }
  .vital-trend.na   { background: var(--gray-100);    color: var(--gray-400); }
  .patient-select-mini { width:100%; margin-bottom:14px; }

  .bottom-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }

  .med-item { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--gray-50); }
  .med-item:last-child { border-bottom: none; }
  .med-time { font-size: 11px; font-weight: 700; color: var(--mint-dark); width: 50px; flex-shrink: 0; }
  .med-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .med-info { flex: 1; }
  .med-name { font-size: 13px; font-weight: 600; color: var(--gray-800); }
  .med-patient { font-size: 11px; color: var(--gray-400); margin-top: 1px; }
  .med-status { font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 20px; flex-shrink: 0; cursor:pointer; border:none; }
  .med-status.done    { background: var(--green-light); color: var(--green); cursor:default; }
  .med-status.pending { background: var(--amber-light); color: var(--amber); }
  .med-status.due     { background: var(--red-light);   color: var(--red); }

  .appt-item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-bottom: 1px solid var(--gray-50); }
  .appt-item:last-child { border-bottom: none; }
  .appt-time-block { background: var(--mint-pale); border-radius: var(--radius-sm); padding: 8px 10px; text-align: center; flex-shrink: 0; min-width: 54px; }
  .appt-hour { font-size: 14px; font-weight: 700; color: var(--mint-dark); line-height: 1; }
  .appt-ampm { font-size: 10px; color: var(--mint); font-weight: 500; }
  .appt-info { flex: 1; }
  .appt-name { font-size: 13px; font-weight: 600; color: var(--gray-800); }
  .appt-type { font-size: 11px; color: var(--gray-400); margin-top: 2px; }
  .appt-room { font-size: 11px; color: var(--mint-dark); font-weight: 600; background: var(--mint-pale); padding: 2px 8px; border-radius: 6px; }

  .alert-item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 10px; border-left: 3px solid; }
  .alert-item:last-child { margin-bottom: 0; }
  .alert-item.urgent { background: var(--red-light);   border-color: var(--red); }
  .alert-item.warn   { background: var(--amber-light); border-color: var(--amber); }
  .alert-item.info   { background: var(--blue-light);  border-color: var(--blue); }
  .alert-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
  .alert-text { flex: 1; }
  .alert-title { font-size: 12px; font-weight: 700; color: var(--gray-800); }
  .alert-desc  { font-size: 11px; color: var(--gray-600); margin-top: 2px; }

  /* ── NOTES LIST ── */
  .note-item { padding: 11px 0; border-bottom: 1px solid var(--gray-50); }
  .note-item:last-child { border-bottom: none; }
  .note-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
  .note-patient { font-size:12.5px; font-weight:700; color:var(--gray-800); }
  .note-type-chip { font-size:9.5px; font-weight:700; color:var(--purple); background:var(--purple-light); padding:2px 8px; border-radius:20px; }
  .note-text { font-size:11.5px; color:var(--gray-600); line-height:1.4; }
  .note-time { font-size:9.5px; color:var(--gray-400); margin-top:3px; }

  .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
  .quick-btn { display: flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration:none; }
  .quick-btn.primary { background: linear-gradient(135deg, var(--mint-dark), var(--mint)); color: #fff; box-shadow: 0 2px 8px rgba(91,191,181,0.35); }
  .quick-btn.primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(91,191,181,0.45); }
  .quick-btn.outline { background: var(--white); color: var(--mint-dark); border: 1.5px solid var(--mint-light); }
  .quick-btn.outline:hover { background: var(--mint-pale); }

  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; }
  .modal-overlay.open { display: flex; }
  .modal { background: var(--white); border-radius: var(--radius); padding: 28px; width: 480px; max-width: 95vw; box-shadow: var(--shadow-lg); animation: popIn 0.2s ease; max-height:90vh; overflow-y:auto; }
  @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
  .modal-title { font-family: 'Poppins', sans-serif; font-size: 17px; font-weight: 700; color: var(--gray-800); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
  .modal-title span { color: var(--mint-dark); }
  .form-group { margin-bottom: 16px; }
  .form-label { font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 6px; display: block; }
  .form-control { width: 100%; padding: 10px 14px; border-radius: var(--radius-sm); border: 1.5px solid var(--gray-200); font-size: 13px; font-family: 'Inter', sans-serif; outline: none; transition: border-color 0.2s; background: var(--white); }
  .form-control:focus { border-color: var(--mint); }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
  .btn-cancel { padding: 9px 20px; border-radius: 8px; border: 1.5px solid var(--gray-200); background: var(--white); font-size: 13px; font-weight: 600; color: var(--gray-600); cursor: pointer; transition: background 0.2s; }
  .btn-cancel:hover { background: var(--gray-100); }
  .btn-save { padding: 9px 20px; border-radius: 8px; border: none; background: linear-gradient(135deg, var(--mint-dark), var(--mint)); color: var(--white); font-size: 13px; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
  .btn-save:hover { opacity: 0.9; }
  .btn-save:disabled { opacity:0.6; cursor:not-allowed; }
  .modal-error { font-size:11.5px; color:#b91c1c; margin-top:-8px; margin-bottom:12px; min-height:14px; }
  .modal-success-note { margin-top:14px; padding:10px 12px; background:#d1fae5; border:1px solid #a7f3d0; border-radius:8px; font-size:11.5px; color:#065f46; }

  .toast { position: fixed; bottom: 28px; right: 28px; background: var(--gray-800); color: #fff; padding: 12px 20px; border-radius: 10px; font-size: 13px; font-weight: 500; box-shadow: var(--shadow-lg); z-index: 300; display: flex; align-items: center; gap: 10px; transform: translateY(80px); opacity: 0; transition: all 0.35s ease; }
  .toast.show { transform: translateY(0); opacity: 1; }
  .toast.success { background: var(--mint-dark); }
  .toast.error   { background: var(--red); }

  .empty-state { text-align:center; padding:28px 16px; color:var(--gray-400); }
  .empty-state .e-icon { font-size:24px; margin-bottom:8px; display:block; }
  .empty-state p { font-size:12.5px; }

  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: var(--mint-light); border-radius: 3px; }

  @media(max-width:1200px){ .stats-grid{grid-template-columns:repeat(2,1fr);} .main-grid{grid-template-columns:1fr;} .bottom-grid{grid-template-columns:1fr;} }
  @media(max-width:768px){ .sidebar{width:64px;} .sidebar .brand,.tagline,.nurse-name,.nurse-role,.nav-label,.nav-item span:not(.nav-icon),.logout-btn span:last-child{display:none;} .main{margin-left:64px;} .page{padding:16px;} }
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="brand">Zaman Medical<span>Center</span></div>
    <div class="tagline">Hospital Management System</div>
  </div>

  <div class="nurse-profile">
    <div class="nurse-avatar"><?= htmlspecialchars($initials) ?></div>
    <div>
      <div class="nurse-name"><?= htmlspecialchars($user['full_name']) ?></div>
      <div class="nurse-role">Nurse · <?= htmlspecialchars($user['department']) ?></div>
    </div>
    <div class="status-dot"></div>
  </div>

  <nav class="nav-section">
    <div class="nav-label">Main</div>
    <div class="nav-item active"><span class="nav-icon">🏥</span><span>Dashboard</span></div>
    <div class="nav-item" onclick="document.getElementById('patients-card').scrollIntoView({behavior:'smooth'})">
      <span class="nav-icon">🛏️</span><span>My Patients</span>
      <span class="nav-badge"><?= $total_patients ?></span>
    </div>
    <div class="nav-item" onclick="openVitalsModal()"><span class="nav-icon">💓</span><span>Record Vitals</span></div>
    <div class="nav-item" onclick="document.getElementById('meds-card').scrollIntoView({behavior:'smooth'})">
      <span class="nav-icon">💊</span><span>Medications</span>
      <?php if ($meds_pending > 0): ?><span class="nav-badge amber"><?= $meds_pending ?></span><?php endif; ?>
    </div>

    <div class="nav-label">Activities</div>
    <div class="nav-item" onclick="openBedModal()"><span class="nav-icon">🛏️</span><span>Allocate Bed</span></div>
    <div class="nav-item" onclick="openNoteModal()"><span class="nav-icon">📝</span><span>Write Note</span></div>
    <a href="nursechatdoctor.php" class="nav-item"><span class="nav-icon">💬</span><span>Chat with Doctor</span></a>

    <div class="nav-label">Reports</div>
    <div class="nav-item" onclick="document.getElementById('appts-card').scrollIntoView({behavior:'smooth'})"><span class="nav-icon">📅</span><span>Appointments</span></div>
    <div class="nav-item" onclick="document.getElementById('alerts-card').scrollIntoView({behavior:'smooth'})">
      <span class="nav-icon">🔔</span><span>Alerts</span>
      <?php if (count($alerts) > 0): ?><span class="nav-badge"><?= count($alerts) ?></span><?php endif; ?>
    </div>
  </nav>

  <div class="sidebar-footer">
    <a href="receptionistprofile.php" class="nav-item" style="border-radius:8px; padding:10px 12px;"><span class="nav-icon">⚙️</span><span>Settings</span></a>
    <a href="logout.php" class="logout-btn"><span>🚪</span><span>Sign Out</span></a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">

  <header class="topbar">
    <div class="topbar-title">Good day, <span><?= htmlspecialchars($user['full_name']) ?></span> 👋</div>
    <div class="search-bar">
      <span>🔍</span>
      <input type="text" id="patient-search" placeholder="Search patients, meds, records…">
    </div>
    <div class="topbar-actions">
      <div class="date-chip" id="date-chip"></div>
      <button class="icon-btn" title="Notifications" onclick="showToast('<?= count($alerts) ?> active alerts', 'success')">
        🔔 <?php if (count($alerts)>0): ?><span class="badge"></span><?php endif; ?>
      </button>
      <a href="nursechatdoctor.php" class="icon-btn" title="Chat with Doctor" style="text-decoration:none;display:flex;">💬</a>
    </div>
  </header>

  <div class="page">

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- QUICK ACTIONS -->
    <div class="quick-actions">
      <button class="quick-btn primary" onclick="openVitalsModal()">➕ Record Vitals</button>
      <button class="quick-btn outline" onclick="openMedModal()">💊 Administer Med</button>
      <button class="quick-btn outline" onclick="openNoteModal()">📝 Write Nursing Note</button>
      <button class="quick-btn outline" onclick="openBedModal()">🛏️ Allocate Bed</button>
      <button class="quick-btn outline" onclick="openAdmitModal()">➕ Admit Patient</button>
      <a href="nursechatdoctor.php" class="quick-btn outline">💬 Chat with Doctor</a>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon mint">🛏️</div>
        <div class="stat-info">
          <div class="stat-label">Assigned Patients</div>
          <div class="stat-value"><?= $total_patients ?></div>
          <div class="stat-sub">Across your ward</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red">🚨</div>
        <div class="stat-info">
          <div class="stat-label">Critical Patients</div>
          <div class="stat-value"><?= $critical_count ?></div>
          <div class="stat-sub"><?= $critical_count > 0 ? '<span class="down">⚠ Requires attention</span>' : 'All stable' ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon amber">💊</div>
        <div class="stat-info">
          <div class="stat-label">Meds Due Today</div>
          <div class="stat-value"><?= $meds_today ?></div>
          <div class="stat-sub"><span class="up"><?= $meds_done ?> given</span> · <?= $meds_pending ?> pending</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green">🛏️</div>
        <div class="stat-info">
          <div class="stat-label">Available Beds</div>
          <div class="stat-value"><?= count($available_beds) ?></div>
          <div class="stat-sub">Ready for allocation</div>
        </div>
      </div>
    </div>

    <!-- MAIN GRID -->
    <div class="main-grid">

      <!-- PATIENT TABLE -->
      <div class="card" id="patients-card">
        <div class="card-header">
          <div class="card-title">My Patients</div>
          <button class="card-action" onclick="openAdmitModal()">+ Admit Patient</button>
        </div>
        <div class="card-body" style="padding-top:12px;">
          <?php if (empty($patients)): ?>
            <div class="empty-state"><span class="e-icon">🛏️</span><p>No patients currently assigned to you</p></div>
          <?php else: ?>
          <table class="patient-table" id="patient-table">
            <thead>
              <tr><th>Patient</th><th>Room</th><th>Condition</th><th>Status</th><th>Last Vital</th><th></th></tr>
            </thead>
            <tbody id="patient-tbody">
              <?php foreach ($patients as $p):
                $ini = strtoupper(substr($p['full_name'], 0, 2));
                $statusClass = in_array($p['clinical_status'], ['critical','stable','moderate']) ? $p['clinical_status'] : 'obs';
              ?>
              <tr data-search="<?= htmlspecialchars(strtolower($p['full_name'].' '.$p['condition'])) ?>">
                <td>
                  <div class="pt-info">
                    <div class="pt-avatar"><?= htmlspecialchars($ini) ?></div>
                    <div>
                      <div class="pt-name"><?= htmlspecialchars($p['full_name']) ?></div>
                      <div class="pt-id">PT-<?= str_pad($p['patient_id'],4,'0',STR_PAD_LEFT) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-weight:600;color:var(--mint-dark)"><?= $p['room_number'] ? 'Room '.htmlspecialchars($p['room_number']) : '—' ?></td>
                <td style="font-size:12px;color:var(--gray-600)"><?= htmlspecialchars($p['condition'] ?: '—') ?></td>
                <td><span class="badge <?= $statusClass ?>"><?= ucfirst($p['clinical_status'] ?: 'stable') ?></span></td>
                <td style="font-size:12px;color:var(--gray-400)"><?= $p['last_vital_date'] ? date('M d', strtotime($p['last_vital_date'])) : 'No data' ?></td>
                <td><button class="action-dots" onclick="openVitalsModal(<?= $p['patient_id'] ?>)">⋯</button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- VITALS SNAPSHOT -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Patient Vitals</div>
          <button class="card-action" onclick="openVitalsModal()">Record</button>
        </div>
        <div class="card-body">
          <select class="form-control patient-select-mini" id="vitals-snapshot-select" onchange="renderVitalsSnapshot(this.value)">
            <option value="">— Select a patient —</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['patient_id'] ?>"><?= htmlspecialchars($p['full_name']) ?><?= $p['room_number'] ? ' · Room '.htmlspecialchars($p['room_number']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="vitals-list" id="vitals-snapshot">
            <div class="empty-state"><span class="e-icon">💓</span><p>Select a patient to view their latest vitals</p></div>
          </div>
        </div>
      </div>
    </div>

    <!-- BOTTOM ROW -->
    <div class="bottom-grid">

      <!-- MEDICATIONS -->
      <div class="card" id="meds-card">
        <div class="card-header">
          <div class="card-title">💊 Medication Schedule</div>
          <div class="card-action"><?= $meds_today ?> today</div>
        </div>
        <div class="card-body">
          <?php if (empty($medications)): ?>
            <div class="empty-state"><span class="e-icon">💊</span><p>No medications scheduled today</p></div>
          <?php else: foreach ($medications as $m):
            $isDone = $m['status'] === 'done';
            $isDue  = !$isDone && strtotime($m['scheduled_time']) < time();
            $dot = $isDone ? 'var(--green)' : ($isDue ? 'var(--red)' : 'var(--amber)');
            $statusClass = $isDone ? 'done' : ($isDue ? 'due' : 'pending');
            $statusLabel = $isDone ? '✓ Done' : ($isDue ? 'Due Now' : 'Pending');
          ?>
          <div class="med-item">
            <div class="med-time"><?= date('h:i A', strtotime($m['scheduled_time'])) ?></div>
            <div class="med-dot" style="background:<?= $dot ?>"></div>
            <div class="med-info">
              <div class="med-name"><?= htmlspecialchars($m['medicine_name']) ?> <?= $m['dose'] ? '· '.htmlspecialchars($m['dose']) : '' ?></div>
              <div class="med-patient"><?= htmlspecialchars($m['patient_name']) ?><?= $m['room_number'] ? ' · Room '.htmlspecialchars($m['room_number']) : '' ?></div>
            </div>
            <button class="med-status <?= $statusClass ?>" <?= $isDone ? 'disabled' : 'onclick="administerMed('.$m['schedule_id'].', this)"' ?>><?= $statusLabel ?></button>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- APPOINTMENTS -->
      <div class="card" id="appts-card">
        <div class="card-header">
          <div class="card-title">📅 Today's Appointments</div>
          <div class="card-action"><?= count($appointments) ?> today</div>
        </div>
        <div class="card-body">
          <?php if (empty($appointments)): ?>
            <div class="empty-state"><span class="e-icon">📅</span><p>No appointments for your patients today</p></div>
          <?php else: foreach ($appointments as $a): ?>
          <div class="appt-item">
            <div class="appt-time-block">
              <div class="appt-hour"><?= date('h:i', strtotime($a['appointment_date'])) ?></div>
              <div class="appt-ampm"><?= date('A', strtotime($a['appointment_date'])) ?></div>
            </div>
            <div class="appt-info">
              <div class="appt-name"><?= htmlspecialchars($a['patient_name']) ?></div>
              <div class="appt-type"><?= $a['doctor_name'] ? 'with Dr. '.htmlspecialchars($a['doctor_name']) : 'Appointment' ?></div>
            </div>
            <div class="appt-room"><?= $a['room_number'] ? 'R-'.htmlspecialchars($a['room_number']) : '—' ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- ALERTS -->
      <div class="card" id="alerts-card">
        <div class="card-header">
          <div class="card-title">🔔 Active Alerts</div>
          <div class="card-action"><?= count($alerts) ?></div>
        </div>
        <div class="card-body">
          <?php if (empty($alerts)): ?>
            <div class="empty-state"><span class="e-icon">✅</span><p>No active alerts. All clear!</p></div>
          <?php else: foreach ($alerts as $al): ?>
          <div class="alert-item <?= $al['severity'] === 'urgent' ? 'urgent' : 'info' ?>">
            <div class="alert-icon"><?= $al['icon'] ?></div>
            <div class="alert-text">
              <div class="alert-title"><?= htmlspecialchars($al['title']) ?></div>
              <div class="alert-desc"><?= $al['desc'] ?></div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- SECOND BOTTOM ROW: NOTES + LOAD CHART -->
    <div class="main-grid" style="margin-top:4px;">

      <!-- RECENT NOTES -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📝 Recent Nursing Notes</div>
          <button class="card-action" onclick="openNoteModal()">+ Add Note</button>
        </div>
        <div class="card-body">
          <?php if (empty($recent_notes)): ?>
            <div class="empty-state"><span class="e-icon">📝</span><p>No nursing notes yet</p></div>
          <?php else: foreach ($recent_notes as $n): ?>
          <div class="note-item">
            <div class="note-top">
              <span class="note-patient"><?= htmlspecialchars($n['patient_name']) ?></span>
              <span class="note-type-chip"><?= htmlspecialchars($n['note_type']) ?></span>
            </div>
            <div class="note-text"><?= htmlspecialchars($n['note_text']) ?></div>
            <div class="note-time"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- PATIENT LOAD CHART -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📊 Patient Load (7 Days)</div>
          <div class="card-action">Live</div>
        </div>
        <div class="card-body">
          <div style="position:relative;width:100%;height:200px;">
            <canvas id="loadChart"></canvas>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ─── MODALS ─── -->

<!-- VITALS MODAL -->
<div class="modal-overlay" id="vitals-modal">
  <div class="modal">
    <div class="modal-title">💓 Record Patient <span>Vitals</span></div>
    <div class="form-group">
      <label class="form-label">Select Patient *</label>
      <select class="form-control" id="vitals-patient">
        <option value="">— Choose Patient —</option>
        <?php foreach ($patients as $p): ?>
          <option value="<?= $p['patient_id'] ?>"><?= htmlspecialchars($p['full_name']) ?><?= $p['room_number'] ? ' — Room '.htmlspecialchars($p['room_number']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Heart Rate (bpm)</label><input class="form-control" type="number" id="v-hr" placeholder="e.g. 76"></div>
      <div class="form-group"><label class="form-label">Blood Pressure</label><input class="form-control" type="text" id="v-bp" placeholder="e.g. 120/80"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Temperature (°C)</label><input class="form-control" type="number" step="0.1" id="v-temp" placeholder="e.g. 37.0"></div>
      <div class="form-group"><label class="form-label">SpO₂ (%)</label><input class="form-control" type="number" id="v-spo2" placeholder="e.g. 98"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Blood Glucose (mmol/L)</label><input class="form-control" type="number" step="0.1" id="v-glucose" placeholder="e.g. 5.4"></div>
      <div class="form-group"><label class="form-label">Respiratory Rate</label><input class="form-control" type="number" id="v-rr" placeholder="e.g. 16"></div>
    </div>
    <div class="modal-error" id="vitals-error"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('vitals-modal')">Cancel</button>
      <button class="btn-save" id="vitals-save-btn" onclick="saveVitals()">Save Vitals</button>
    </div>
  </div>
</div>

<!-- MEDICATION MODAL -->
<div class="modal-overlay" id="med-modal">
  <div class="modal">
    <div class="modal-title">💊 Administer <span>Medication</span></div>
    <div class="form-group">
      <label class="form-label">Select Scheduled Dose *</label>
      <select class="form-control" id="med-select">
        <option value="">— Choose Medication —</option>
        <?php foreach ($medications as $m): if ($m['status']==='done') continue; ?>
          <option value="<?= $m['schedule_id'] ?>">
            <?= htmlspecialchars($m['medicine_name']) ?> <?= $m['dose']?'('.htmlspecialchars($m['dose']).')':'' ?> — <?= htmlspecialchars($m['patient_name']) ?> @ <?= date('h:i A', strtotime($m['scheduled_time'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="modal-error" id="med-error"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('med-modal')">Cancel</button>
      <button class="btn-save" id="med-save-btn" onclick="saveMed()">Confirm Administration</button>
    </div>
  </div>
</div>

<!-- NURSING NOTE MODAL (Activity) -->
<div class="modal-overlay" id="note-modal">
  <div class="modal">
    <div class="modal-title">📝 Write <span>Nursing Note</span></div>
    <div class="form-group">
      <label class="form-label">Patient *</label>
      <select class="form-control" id="note-patient">
        <option value="">— Choose Patient —</option>
        <?php foreach ($patients as $p): ?>
          <option value="<?= $p['patient_id'] ?>"><?= htmlspecialchars($p['full_name']) ?><?= $p['room_number'] ? ' — Room '.htmlspecialchars($p['room_number']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Note Type</label>
      <select class="form-control" id="note-type">
        <option>General Observation</option>
        <option>Post-Procedure Note</option>
        <option>Medication Note</option>
        <option>Incident Report</option>
        <option>Handover Note</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Note *</label>
      <textarea class="form-control" rows="5" id="note-text" placeholder="Write your nursing note here…"></textarea>
    </div>
    <div class="modal-error" id="note-error"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('note-modal')">Cancel</button>
      <button class="btn-save" id="note-save-btn" onclick="saveNote()">Save Note</button>
    </div>
  </div>
</div>

<!-- ALLOCATE BED MODAL (Activity) -->
<div class="modal-overlay" id="bed-modal">
  <div class="modal">
    <div class="modal-title">🛏️ Allocate <span>Bed</span></div>
    <div class="form-group">
      <label class="form-label">Patient *</label>
      <select class="form-control" id="bed-patient">
        <option value="">— Choose Patient —</option>
        <?php foreach ($patients as $p): ?>
          <option value="<?= $p['patient_id'] ?>"><?= htmlspecialchars($p['full_name']) ?><?= $p['room_number'] ? ' (currently Room '.htmlspecialchars($p['room_number']).')' : ' (unassigned)' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Available Bed *</label>
      <select class="form-control" id="bed-select">
        <option value="">— Choose Bed —</option>
        <?php foreach ($available_beds as $b): ?>
          <option value="<?= $b['bed_id'] ?>">Room <?= htmlspecialchars($b['room_number']) ?> — Bed <?= htmlspecialchars($b['bed_number']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($available_beds)): ?><div style="font-size:11px;color:var(--amber);margin-top:6px;">No beds currently available.</div><?php endif; ?>
    </div>
    <div class="modal-error" id="bed-error"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('bed-modal')">Cancel</button>
      <button class="btn-save" id="bed-save-btn" onclick="allocateBed()">Allocate Bed</button>
    </div>
  </div>
</div>

<!-- ADMIT PATIENT MODAL -->
<div class="modal-overlay" id="admit-modal">
  <div class="modal">
    <div class="modal-title">🛏️ <span>Admit Patient</span></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Full Name *</label><input class="form-control" type="text" id="admit-name" placeholder="Patient name"></div>
      <div class="form-group"><label class="form-label">Age</label><input class="form-control" type="number" id="admit-age" placeholder="Years"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Gender</label>
        <select class="form-control" id="admit-gender"><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select>
      </div>
      <div class="form-group">
        <label class="form-label">Bed *</label>
        <select class="form-control" id="admit-bed">
          <option value="">— Choose Bed —</option>
          <?php foreach ($available_beds as $b): ?>
            <option value="<?= $b['bed_id'] ?>">Room <?= htmlspecialchars($b['room_number']) ?> — Bed <?= htmlspecialchars($b['bed_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Condition</label>
      <input class="form-control" type="text" id="admit-condition" placeholder="e.g. Hypertension, Post-Surgical…">
    </div>
    <div class="modal-error" id="admit-error"></div>
    <div class="modal-success-note" id="admit-success" style="display:none;"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('admit-modal')">Cancel</button>
      <button class="btn-save" id="admit-save-btn" onclick="admitPatient()">Admit Patient</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast">
  <span id="toast-icon">✅</span>
  <span id="toast-msg">Action completed</span>
</div>

<script>
// ── Real data passed from PHP (no fake records) ──
const PATIENTS = <?= json_encode($patients) ?>;
const LATEST_VITALS = <?= json_encode($latest_vitals) ?>; // keyed by patient_id

function vitalTrend(label, val, low, high) {
  if (val === null || val === undefined || val === '') return 'na';
  val = parseFloat(val);
  if (val < low) return 'warn';
  if (val > high) return 'bad';
  return 'ok';
}

function renderVitalsSnapshot(patientId) {
  const box = document.getElementById('vitals-snapshot');
  if (!patientId || !LATEST_VITALS[patientId]) {
    box.innerHTML = `<div class="empty-state"><span class="e-icon">💓</span><p>No vitals recorded yet for this patient</p></div>`;
    return;
  }
  const v = LATEST_VITALS[patientId];
  const rows = [
    { icon:'❤️', label:'Heart Rate', val:v.heart_rate, unit:'bpm', trend: vitalTrend('hr', v.heart_rate, 60, 100) },
    { icon:'🩺', label:'Blood Pressure', val:v.blood_pressure, unit:'mmHg', trend: 'na' },
    { icon:'🌡️', label:'Temperature', val:v.temperature, unit:'°C', trend: vitalTrend('t', v.temperature, 36, 37.5) },
    { icon:'🫁', label:'SpO₂', val:v.spo2, unit:'%', trend: vitalTrend('s', v.spo2, 94, 100) },
    { icon:'🩸', label:'Blood Glucose', val:v.blood_sugar, unit:'mmol/L', trend: vitalTrend('g', v.blood_sugar, 4, 7.8) },
  ];
  box.innerHTML = rows.map(r => `
    <div class="vital-row">
      <div class="vital-icon">${r.icon}</div>
      <div class="vital-info">
        <div class="vital-label">${r.label}</div>
        <div class="vital-val">${r.val ?? '—'} <span class="vital-unit">${r.unit}</span></div>
      </div>
      <div class="vital-trend ${r.trend}">${r.trend==='ok'?'✓ Normal':r.trend==='warn'?'↓ Low':r.trend==='bad'?'⚠ High':'No data'}</div>
    </div>
  `).join('');
}

// ── Search ──
document.getElementById('patient-search').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#patient-tbody tr').forEach(tr => {
    tr.style.display = tr.dataset.search.includes(q) ? '' : 'none';
  });
});

// ── Modals ──
function openVitalsModal(patientId) {
  document.getElementById('vitals-error').textContent = '';
  if (patientId) document.getElementById('vitals-patient').value = patientId;
  document.getElementById('vitals-modal').classList.add('open');
}
function openMedModal() { document.getElementById('med-error').textContent=''; document.getElementById('med-modal').classList.add('open'); }
function openNoteModal() { document.getElementById('note-error').textContent=''; document.getElementById('note-modal').classList.add('open'); }
function openBedModal()  { document.getElementById('bed-error').textContent=''; document.getElementById('bed-modal').classList.add('open'); }
function openAdmitModal(){
  document.getElementById('admit-error').textContent='';
  document.getElementById('admit-success').style.display='none';
  document.getElementById('admit-modal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// ── Save vitals (real AJAX) ──
function saveVitals() {
  const patient_id = document.getElementById('vitals-patient').value;
  const errBox = document.getElementById('vitals-error');
  if (!patient_id) { errBox.textContent = 'Please select a patient.'; return; }

  const btn = document.getElementById('vitals-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const body = new URLSearchParams({
    ajax_action: 'save_vitals',
    patient_id,
    heart_rate: document.getElementById('v-hr').value,
    blood_pressure: document.getElementById('v-bp').value,
    temperature: document.getElementById('v-temp').value,
    spo2: document.getElementById('v-spo2').value,
    glucose: document.getElementById('v-glucose').value,
    resp_rate: document.getElementById('v-rr').value
  });

  fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.textContent = 'Save Vitals';
      if (!data.success) { errBox.textContent = data.error || 'Could not save vitals.'; return; }
      closeModal('vitals-modal');
      showToast('Vitals recorded — reloading…', 'success');
      setTimeout(() => location.reload(), 700);
    })
    .catch(() => { btn.disabled = false; btn.textContent = 'Save Vitals'; errBox.textContent = 'Network error.'; });
}

// ── Administer med (table button) ──
function administerMed(scheduleId, btnEl) {
  if (!confirm('Mark this medication as administered?')) return;
  btnEl.disabled = true; btnEl.textContent = 'Saving…';
  const body = new URLSearchParams({ ajax_action:'administer_med', schedule_id: scheduleId });
  fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showToast(data.error || 'Failed to update.', 'error'); btnEl.disabled=false; btnEl.textContent='Pending'; return; }
      showToast('Medication marked as administered', 'success');
      setTimeout(() => location.reload(), 600);
    })
    .catch(() => { showToast('Network error.', 'error'); btnEl.disabled=false; });
}

// ── Save med (modal dropdown version) ──
function saveMed() {
  const sel = document.getElementById('med-select');
  const errBox = document.getElementById('med-error');
  if (!sel.value) { errBox.textContent = 'Please choose a medication.'; return; }
  const btn = document.getElementById('med-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';
  const body = new URLSearchParams({ ajax_action:'administer_med', schedule_id: sel.value });
  fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.textContent = 'Confirm Administration';
      if (!data.success) { errBox.textContent = data.error || 'Failed to update.'; return; }
      closeModal('med-modal');
      showToast('Medication administration logged', 'success');
      setTimeout(() => location.reload(), 700);
    })
    .catch(() => { btn.disabled=false; btn.textContent='Confirm Administration'; errBox.textContent='Network error.'; });
}

// ── Save nursing note (Activity #1, real AJAX) ──
function saveNote() {
  const patient_id = document.getElementById('note-patient').value;
  const note_type  = document.getElementById('note-type').value;
  const note_text  = document.getElementById('note-text').value.trim();
  const errBox = document.getElementById('note-error');

  if (!patient_id || !note_text) { errBox.textContent = 'Please select a patient and write a note.'; return; }

  const btn = document.getElementById('note-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const body = new URLSearchParams({ ajax_action:'save_note', patient_id, note_type, note_text });
  fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.textContent = 'Save Note';
      if (!data.success) { errBox.textContent = data.error || 'Could not save note.'; return; }
      closeModal('note-modal');
      document.getElementById('note-text').value = '';
      showToast('Nursing note saved', 'success');
      setTimeout(() => location.reload(), 700);
    })
    .catch(() => { btn.disabled=false; btn.textContent='Save Note'; errBox.textContent='Network error.'; });
}

// ── Allocate bed (Activity #2, real AJAX) ──
function allocateBed() {
  const patient_id = document.getElementById('bed-patient').value;
  const bed_id     = document.getElementById('bed-select').value;
  const errBox = document.getElementById('bed-error');

  if (!patient_id || !bed_id) { errBox.textContent = 'Please choose a patient and a bed.'; return; }

  const btn = document.getElementById('bed-save-btn');
  btn.disabled = true; btn.textContent = 'Allocating…';

  const body = new URLSearchParams({ ajax_action:'allocate_bed', patient_id, bed_id });
  fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.textContent = 'Allocate Bed';
      if (!data.success) { errBox.textContent = data.error || 'Could not allocate bed.'; return; }
      closeModal('bed-modal');
      showToast(`Allocated Room ${data.room_number}, Bed ${data.bed_number}`, 'success');
      setTimeout(() => location.reload(), 800);
    })
    .catch(() => { btn.disabled=false; btn.textContent='Allocate Bed'; errBox.textContent='Network error.'; });
}

// ── Admit patient (real AJAX) ──
function admitPatient() {
  const full_name = document.getElementById('admit-name').value.trim();
  const age       = document.getElementById('admit-age').value;
  const gender    = document.getElementById('admit-gender').value;
  const bed_id    = document.getElementById('admit-bed').value;
  const condition = document.getElementById('admit-condition').value.trim();
  const errBox = document.getElementById('admit-error');

  if (!full_name || !bed_id) { errBox.textContent = 'Name and bed are required.'; return; }

  const btn = document.getElementById('admit-save-btn');
  btn.disabled = true; btn.textContent = 'Admitting…';

  const body = new URLSearchParams({ ajax_action:'admit_patient', full_name, age, gender, bed_id, condition });
  fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.textContent = 'Admit Patient';
      if (!data.success) { errBox.textContent = data.error || 'Could not admit patient.'; return; }
      const note = document.getElementById('admit-success');
      note.style.display = 'block';
      note.innerHTML = `Patient admitted. Login: <strong>${data.username}</strong> / Temp password: <strong>${data.temp_password}</strong>`;
      showToast('Patient admitted successfully', 'success');
      setTimeout(() => location.reload(), 1400);
    })
    .catch(() => { btn.disabled=false; btn.textContent='Admit Patient'; errBox.textContent='Network error.'; });
}

// ── Toast ──
let toastTimer;
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  document.getElementById('toast-msg').textContent = msg;
  document.getElementById('toast-icon').textContent = type === 'error' ? '❌' : '✅';
  t.className = `toast ${type} show`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Date chip ──
(function updateDate(){
  const now = new Date();
  document.getElementById('date-chip').textContent = now.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' });
})();

// ── Patient load chart (real data, last 7 days) ──
Chart.defaults.color = '#6B7280';
Chart.defaults.font.family = "'Inter', sans-serif";
new Chart(document.getElementById('loadChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      label: 'Assigned Patients',
      data: <?= json_encode($chart_values) ?>,
      borderColor: '#3A9E94',
      backgroundColor: 'rgba(91,191,181,0.18)',
      fill: true, tension: 0.35, pointBackgroundColor: '#3A9E94', pointRadius: 4
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display:false }, tooltip: { backgroundColor:'#1F2937', padding:9, cornerRadius:7 } },
    scales: {
      x: { grid: { display:false } },
      y: { beginAtZero:true, ticks:{ precision:0 }, grid:{ color:'rgba(91,191,181,0.1)' } }
    }
  }
});
</script>
</body>
</html>