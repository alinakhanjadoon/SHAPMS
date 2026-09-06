<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---------------- Session & Auth Check ----------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

// ---------------- Database Connection ----------------
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// ---------------- Pediatric BMI-for-age reference table ----------------
// Approximate 5th / 85th / 95th percentile BMI cutoffs by whole-year age and sex,
// modeled on the shape of published CDC BMI-for-age growth charts (ages 2–20).
// These are reference approximations for demonstration purposes — for clinical-grade
// accuracy, swap this for the official CDC LMS growth chart dataset.
$pediatricBmiTable = [
    'male' => [
        2=>[14.7,17.5,18.6], 3=>[14.3,17.0,18.2], 4=>[14.0,16.9,17.9], 5=>[13.8,16.9,18.0],
        6=>[13.7,17.0,18.4], 7=>[13.7,17.4,19.1], 8=>[13.9,17.9,19.9], 9=>[14.0,18.6,21.0],
        10=>[14.2,19.4,22.1],11=>[14.5,20.2,23.2],12=>[14.8,21.0,24.3],13=>[15.2,21.8,25.2],
        14=>[15.7,22.5,26.0],15=>[16.2,23.1,26.6],16=>[16.6,23.6,27.1],17=>[16.9,24.0,27.5],
        18=>[17.1,24.3,27.8],19=>[17.3,24.6,28.1],20=>[17.5,24.9,28.4],
    ],
    'female' => [
        2=>[14.5,17.3,18.3], 3=>[14.1,16.9,18.0], 4=>[13.8,16.8,17.9], 5=>[13.6,16.9,18.2],
        6=>[13.5,17.1,18.8], 7=>[13.5,17.6,19.6], 8=>[13.7,18.3,20.6], 9=>[13.9,19.1,21.8],
        10=>[14.2,20.0,23.0],11=>[14.6,20.9,24.1],12=>[15.0,21.8,25.1],13=>[15.5,22.6,25.9],
        14=>[16.0,23.3,26.7],15=>[16.4,23.9,27.3],16=>[16.8,24.4,27.8],17=>[17.1,24.8,28.2],
        18=>[17.3,25.1,28.5],19=>[17.5,25.4,28.8],20=>[17.6,25.6,29.0],
    ],
];

// Linearly interpolates [P5, P85, P95] BMI cutoffs for a fractional age.
function getPediatricCutoffs($age, $genderLower, $table) {
    if (!isset($table[$genderLower])) return null;
    $ages = $table[$genderLower];
    $age = max(2, min(20, (float)$age));
    $lower = (int)floor($age);
    $upper = (int)ceil($age);
    if (!isset($ages[$lower]) || !isset($ages[$upper])) return null;
    if ($lower === $upper) return $ages[$lower];
    $frac = $age - $lower;
    return [
        round($ages[$lower][0] + ($ages[$upper][0] - $ages[$lower][0]) * $frac, 1),
        round($ages[$lower][1] + ($ages[$upper][1] - $ages[$lower][1]) * $frac, 1),
        round($ages[$lower][2] + ($ages[$upper][2] - $ages[$lower][2]) * $frac, 1),
    ];
}

// Returns [cutoffs [low,mid,high], scaleMax, isPediatric] for a given age/sex,
// falling back to fixed adult cutoffs when age/gender don't support a pediatric lookup.
function resolveBmiCutoffs($age, $genderLower, $table) {
    $age = is_numeric($age) ? (float)$age : null;
    if ($age !== null && $age >= 2 && $age < 20 && in_array($genderLower, ['male', 'female'])) {
        $ped = getPediatricCutoffs($age, $genderLower, $table);
        if ($ped) {
            $scaleMax = max(25, ceil(($ped[2] * 1.3) / 5) * 5);
            return [$ped, $scaleMax, true];
        }
    }
    return [[18.5, 25, 30], 40, false];
}

// Classifies a BMI value against a set of [low, mid, high] cutoffs.
function classifyBmi($bmi, $cutoffs) {
    if ($bmi <= 0) return "Unknown";
    list($low, $mid, $high) = $cutoffs;
    if ($bmi < $low) return "Underweight";
    if ($bmi < $mid) return "Healthy";
    if ($bmi < $high) return "Overweight";
    return "Obese";
}

// ---------------- Handle AJAX Vitals Save ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'save_vitals') {
    $weight = floatval($_POST['weight'] ?? 0);
    $height = floatval($_POST['height'] ?? 0);
    $calories = intval($_POST['calories'] ?? 0);
    $blood_sugar = floatval($_POST['blood_sugar'] ?? 0);
    $heart_rate = intval($_POST['heart_rate'] ?? 0);
    $blood_pressure = $_POST['blood_pressure'] ?? '';
    $hemoglobin = floatval($_POST['hemoglobin'] ?? 0);
    $today = date('Y-m-d');

    // Look up this patient's age/gender so minors get pediatric BMI-for-age cutoffs
    $ageGenderStmt = $conn->prepare("SELECT age, gender FROM users WHERE user_id=?");
    $ageGenderStmt->bind_param("i", $_SESSION['user_id']);
    $ageGenderStmt->execute();
    $ageGenderStmt->bind_result($patientAgeForSave, $patientGenderForSave);
    $ageGenderStmt->fetch();
    $ageGenderStmt->close();
    $genderLowerForSave = strtolower(trim($patientGenderForSave ?? ''));

    $bmi = 0;
    $bmiStatus = "Unknown";
    if ($height > 0) {
        $heightInMeters = $height / 100;
        $bmi = round($weight / ($heightInMeters * $heightInMeters), 1);
        list($cutoffsForSave, , ) = resolveBmiCutoffs($patientAgeForSave, $genderLowerForSave, $pediatricBmiTable);
        $bmiStatus = classifyBmi($bmi, $cutoffsForSave);
    }

    $updateHeight = $conn->prepare("UPDATE users SET height=? WHERE user_id=?");
    $updateHeight->bind_param("di", $height, $_SESSION['user_id']);
    $updateHeight->execute();
    $updateHeight->close();

    $stmt = $conn->prepare("INSERT INTO patient_vitals (user_id, date, weight, height, calories, blood_sugar, heart_rate, blood_pressure, hemoglobin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) { echo json_encode(['success' => false, 'message' => $conn->error]); exit(); }
    $stmt->bind_param("isddiissd", $_SESSION['user_id'], $today, $weight, $height, $calories, $blood_sugar, $heart_rate, $blood_pressure, $hemoglobin);
    $success = $stmt->execute();
    if ($success) {
        echo json_encode(['success' => true, 'vitals' => ['day' => date('M d'), 'weight' => $weight, 'height' => $height, 'bmi' => $bmi, 'bmi_status' => $bmiStatus, 'calories' => $calories, 'blood_sugar' => $blood_sugar, 'heart_rate' => $heart_rate, 'hemoglobin' => $hemoglobin]]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $stmt->close();
    exit();
}



// ---------------- Fetch Patient Info ----------------
$patient = ['user_id'=>'','full_name'=>'Patient','email'=>'','role'=>'','status'=>'','age'=>'','gender'=>'','contact'=>'','profile_image'=>'default.png','address'=>'','height'=>''];
$stmt = $conn->prepare("SELECT user_id, full_name, email, role, status, age, gender, contact, profile_image, address, height FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($id, $name, $email, $role, $status, $age, $gender, $contact, $profile_image, $address, $height);
if ($stmt->fetch()) {
    $patient = ['user_id'=>$id,'full_name'=>$name ?: 'Patient','email'=>$email ?: '','role'=>$role ?: '','status'=>$status ?: '','age'=>$age ?: '','gender'=>$gender ?: '','contact'=>$contact ?: '','profile_image'=>$profile_image ?: 'uploads/profile/default.png','address'=>$address ?: '','height'=>$height ?: ''];
}
$stmt->close();

// ---------------- Fetch last 10 vitals ----------------
$stmt = $conn->prepare("SELECT DATE_FORMAT(date,'%b %d') as day, weight, height, calories, blood_sugar, heart_rate, hemoglobin FROM patient_vitals WHERE user_id=? ORDER BY date DESC LIMIT 10");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$days = $weights = $heights = $bmiArr = $caloriesArr = $bloodSugarArr = $heartRateArr = $hemoglobinArr = [];
while ($row = $result->fetch_assoc()) {
    $bmiValue = 0;
    if ($row['height'] > 0) { $h = $row['height'] / 100; $bmiValue = round($row['weight'] / ($h * $h), 1); }
    $days[] = $row['day'] ?: '';
    $weights[] = $row['weight'] ?: 0;
    $heights[] = $row['height'] ?: 0;
    $bmiArr[] = $bmiValue;
    $caloriesArr[] = $row['calories'] ?: 0;
    $bloodSugarArr[] = $row['blood_sugar'] ?: 0;
    $heartRateArr[] = $row['heart_rate'] ?: 0;
    $hemoglobinArr[] = $row['hemoglobin'] ?: 0;
}
$stmt->close();
$conn->close();

// ---------------- BMI Gauge Calculations ----------------
// Reuses $pediatricBmiTable / resolveBmiCutoffs() / classifyBmi() defined near the top of this file.
$currentBMI = !empty($bmiArr) ? $bmiArr[0] : 0; // $bmiArr[0] is the most recent entry (DESC order)
$patientAge = is_numeric($patient['age']) ? (float)$patient['age'] : null;
$genderLower = strtolower(trim($patient['gender']));

list($cutoffs, $scaleMax, $isPediatric) = resolveBmiCutoffs($patientAge, $genderLower, $pediatricBmiTable);
list($cutLow, $cutMid, $cutHigh) = $cutoffs;

$bmiStatusColorMap = ['Underweight'=>'#3b82f6','Healthy'=>'#1fae8e','Overweight'=>'#f2b840','Obese'=>'#ff6363'];
$bmiStatusText  = $currentBMI > 0 ? classifyBmi($currentBMI, $cutoffs) : "No Data";
$bmiStatusColor = $bmiStatusColorMap[$bmiStatusText] ?? '#8fa39d';

if ($isPediatric) {
    $bmiGaugeTitle = ($genderLower === 'male' ? "Boy's" : "Girl's") . " BMI-for-Age (Age " . (int)$patientAge . ")";
} elseif ($genderLower === 'male') {
    $bmiGaugeTitle = "Male BMI Scale";
} elseif ($genderLower === 'female') {
    $bmiGaugeTitle = "Female BMI Scale";
} else {
    $bmiGaugeTitle = "BMI Scale";
}

// Positions on the scale (0–$scaleMax), clamped 0–100%
$bmiPointerPercent = min(max(($currentBMI / $scaleMax) * 100, 0), 100);
$gaugeStop1 = round(($cutLow  / $scaleMax) * 100, 2);
$gaugeStop2 = round(($cutMid  / $scaleMax) * 100, 2);
$gaugeStop3 = round(($cutHigh / $scaleMax) * 100, 2);

// ---------------- Modern radial (semicircle) BMI gauge geometry ----------------
// Angle convention: -90deg = left end of the arc, 0deg = top (12 o'clock), 90deg = right end.
function polarPoint($cx, $cy, $r, $angleDeg) {
    $angleRad = ($angleDeg - 90) * M_PI / 180;
    return [round($cx + $r * cos($angleRad), 2), round($cy + $r * sin($angleRad), 2)];
}
function describeArc($cx, $cy, $r, $startAngle, $endAngle) {
    list($x1, $y1) = polarPoint($cx, $cy, $r, $endAngle);
    list($x2, $y2) = polarPoint($cx, $cy, $r, $startAngle);
    $largeArc = ($endAngle - $startAngle) <= 180 ? 0 : 1;
    return "M $x1 $y1 A $r $r 0 $largeArc 0 $x2 $y2";
}
function pctToAngle($pct) { return -90 + ($pct / 100) * 180; }

$gaugeCx = 150; $gaugeCy = 150; $gaugeR = 118;
$arcZone1 = describeArc($gaugeCx, $gaugeCy, $gaugeR, pctToAngle(0), pctToAngle(max($gaugeStop1, 0.01)));
$arcZone2 = describeArc($gaugeCx, $gaugeCy, $gaugeR, pctToAngle($gaugeStop1), pctToAngle($gaugeStop2));
$arcZone3 = describeArc($gaugeCx, $gaugeCy, $gaugeR, pctToAngle($gaugeStop2), pctToAngle($gaugeStop3));
$arcZone4 = describeArc($gaugeCx, $gaugeCy, $gaugeR, pctToAngle($gaugeStop3), pctToAngle(100));
$needleAngle = pctToAngle($bmiPointerPercent);
list($needleX, $needleY) = polarPoint($gaugeCx, $gaugeCy, $gaugeR - 34, $needleAngle);

$rev_days = array_reverse($days);
$rev_weights = array_reverse($weights);
$rev_heights = array_reverse($heights);
$rev_bmi = array_reverse($bmiArr);
$rev_calories = array_reverse($caloriesArr);
$rev_bloodSugar = array_reverse($bloodSugarArr);
$rev_heartRate = array_reverse($heartRateArr);
$rev_hemoglobin = array_reverse($hemoglobinArr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Dashboard — SHAPMS</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ============================================================
   1. TOKENS — Living Pulse palette
   ============================================================ */
:root {
  --bg:           #faf8fd;
  --bg-blob-a:    #e6d9f8;
  --bg-blob-b:    #f6e9ff;
  --sidebar:      #ffffff;
  --card:         #ffffff;
  --teal-900:     #2f1f47;
  --teal-700:     #7c4dff;
  --teal-600:     #9061f9;
  --teal-500:     #a78bfa;
  --teal-200:     #e4d7fa;
  --teal-100:     #f3edfd;
  --coral:        #ec4899;
  --coral-soft:   #fbe3f0;
  --gold:         #c9a6f7;
  --violet:       #8b5cf6;
  --violet-soft:  #f5f0ff;
  --blue:         #818cf8;
  --blue-soft:    #e9e6fd;
  --text-dark:    #2c1f3d;
  --text-mid:     #6f5f85;
  --text-light:   #a596b8;
  --border:       #ece2f7;
  --shadow:       rgba(90,50,140,0.09);
  --shadow-md:    rgba(90,50,140,0.16);
}

/* ============================================================
   2. RESET & BASE
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
}

body {
  font-family: 'Plus Jakarta Sans', sans-serif;
  background: var(--bg);
  min-height: 100vh;
  color: var(--text-dark);
  position: relative;
  overflow-x: hidden;
}

/* ============================================================
   3. AMBIENT BACKGROUND
   ============================================================ */
.ambient-blob {
  position: fixed;
  border-radius: 50%;
  filter: blur(70px);
  opacity: 0.55;
  z-index: 0;
  pointer-events: none;
}
.blob-1 { width: 480px; height: 480px; top: -160px; right: -120px; background: var(--bg-blob-a); animation: float1 22s ease-in-out infinite; }
.blob-2 { width: 380px; height: 380px; bottom: -140px; left: 20%; background: var(--bg-blob-b); animation: float2 26s ease-in-out infinite; }
.blob-3 { width: 260px; height: 260px; top: 40%; left: -100px; background: var(--teal-200); opacity: 0.4; animation: float1 30s ease-in-out infinite reverse; }

@keyframes float1 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(40px,50px) scale(1.08); } }
@keyframes float2 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-50px,-30px) scale(1.1); } }

/* ============================================================
   4. LAYOUT & ENTRANCE CHOREOGRAPHY
   ============================================================ */
.layout { display: flex; min-height: 100vh; position: relative; z-index: 1; }

@keyframes riseIn { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
.reveal { opacity: 0; animation: riseIn 0.65s cubic-bezier(.22,1,.36,1) forwards; }
.reveal-d1 { animation-delay: .05s; }
.reveal-d2 { animation-delay: .12s; }
.reveal-d3 { animation-delay: .18s; }
.reveal-d4 { animation-delay: .24s; }
.reveal-d5 { animation-delay: .30s; }
.reveal-d6 { animation-delay: .36s; }

/* ============================================================
   5. SIDEBAR
   ============================================================ */
.sidebar {
  width: 232px;
  min-height: 100vh;
  background: var(--sidebar);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 100;
  box-shadow: 4px 0 28px var(--shadow);
}

.sidebar-logo {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 24px 22px 20px;
  border-bottom: 1px solid var(--border);
}

.logo-icon {
  width: 40px; height: 40px;
  background: linear-gradient(135deg, var(--teal-700), var(--coral));
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 16px;
  flex-shrink: 0;
  box-shadow: 0 4px 14px rgba(15,122,104,0.35);
  position: relative;
  overflow: hidden;
}
.logo-icon i { animation: heartbeat-icon 1.8s ease-in-out infinite; }
@keyframes heartbeat-icon {
  0%,100% { transform: scale(1); }
  15% { transform: scale(1.22); }
  30% { transform: scale(0.96); }
  45% { transform: scale(1.14); }
  60% { transform: scale(1); }
}

.logo-text {
  font-family: 'Fraunces', serif;
  font-size: 18.5px;
  font-weight: 600;
  color: var(--text-dark);
  letter-spacing: 0.2px;
  line-height: 1.15;
}

.pulse-strip { width: 100%; height: 22px; overflow: hidden; padding: 0 22px; margin-bottom: 4px; }
.pulse-strip svg { width: 220%; height: 100%; animation: pulseScroll 3.2s linear infinite; }
@keyframes pulseScroll { from { transform: translateX(0); } to { transform: translateX(-50%); } }

.nav-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.1px;
  text-transform: uppercase;
  color: var(--text-light);
  padding: 16px 22px 6px;
}

.sidebar-nav { flex: 1; overflow-y: auto; padding: 6px 0; }
.sidebar-nav::-webkit-scrollbar { width: 0; }

.sidebar-nav a {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 10px 22px;
  color: var(--text-mid);
  text-decoration: none;
  font-size: 13.5px;
  font-weight: 500;
  transition: all 0.18s;
  position: relative;
}

.sidebar-nav a i { width: 17px; text-align: center; font-size: 14px; flex-shrink: 0; transition: transform 0.25s ease; }

.sidebar-nav a:hover { background: var(--teal-100); color: var(--teal-700); }
.sidebar-nav a:hover i { transform: scale(1.15) rotate(-4deg); }

.sidebar-nav a.active {
  background: var(--teal-100);
  color: var(--teal-700);
  font-weight: 600;
}

.sidebar-nav a.active::before {
  content: '';
  position: absolute;
  left: 0; top: 5px; bottom: 5px;
  width: 3px;
  background: linear-gradient(180deg, var(--teal-600), var(--coral));
  border-radius: 0 3px 3px 0;
}

.sidebar-bottom {
  padding: 14px 22px 22px;
  border-top: 1px solid var(--border);
}

.sidebar-bottom a {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--coral);
  font-size: 13.5px;
  font-weight: 500;
  text-decoration: none;
  padding: 9px 0;
  transition: opacity 0.2s, transform 0.2s;
}
.sidebar-bottom a:hover { opacity: 0.75; transform: translateX(3px); }

/* ============================================================
   6. MAIN / TOPBAR
   ============================================================ */
.main-content {
  margin-left: 232px;
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.topbar {
  background: rgba(255,255,255,0.85);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--border);
  padding: 14px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0; z-index: 50;
  box-shadow: 0 2px 16px var(--shadow);
}

.topbar-left { display: flex; flex-direction: column; gap: 1px; }
.topbar-title { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 600; color: var(--text-dark); }
.topbar-date { font-size: 12px; color: var(--text-light); }
.topbar-right { display: flex; align-items: center; gap: 14px; }

.topbar-status {
  display: flex;
  align-items: center;
  gap: 7px;
  background: var(--teal-100);
  color: var(--teal-700);
  border: 1px solid var(--teal-200);
  padding: 5px 14px;
  border-radius: 99px;
  font-size: 12.5px;
  font-weight: 600;
}

.status-dot {
  width: 7px; height: 7px;
  background: var(--teal-500);
  border-radius: 50%;
  box-shadow: 0 0 0 0 rgba(31,174,142,0.55);
  animation: statusPulse 1.8s infinite;
}
@keyframes statusPulse {
  0% { box-shadow: 0 0 0 0 rgba(31,174,142,0.55); }
  70% { box-shadow: 0 0 0 7px rgba(31,174,142,0); }
  100% { box-shadow: 0 0 0 0 rgba(31,174,142,0); }
}

.topbar-avatar {
  display: flex;
  align-items: center;
  gap: 9px;
  cursor: pointer;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 99px;
  padding: 5px 14px 5px 5px;
  transition: box-shadow 0.2s, transform 0.2s;
}
.topbar-avatar:hover { box-shadow: 0 4px 16px var(--shadow-md); transform: translateY(-1px); }
.topbar-avatar img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 2px solid var(--teal-200); }
.topbar-avatar span { font-size: 13px; font-weight: 500; color: var(--text-dark); }

/* ============================================================
   7. PAGE BODY / GENERIC CARD / SECTION HEADER
   ============================================================ */
.page-body {
  padding: 26px 30px 40px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.card {
  background: white;
  border-radius: 20px;
  border: 1px solid var(--border);
  box-shadow: 0 4px 24px var(--shadow);
  padding: 24px 26px;
  transition: box-shadow 0.3s ease;
}
.card:hover { box-shadow: 0 10px 34px var(--shadow-md); }

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.section-title {
  font-family: 'Fraunces', serif;
  font-size: 17px;
  font-weight: 600;
  color: var(--text-dark);
}

.section-meta {
  font-size: 12px;
  color: var(--text-light);
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}

/* ============================================================
   8. BENTO GRID — main dashboard organization
   ============================================================ */
.stat-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
.stat-chip {
  background: white;
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 15px 18px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 3px 16px var(--shadow);
  transition: transform 0.25s cubic-bezier(.22,1,.36,1), box-shadow 0.25s;
}
.stat-chip:hover { transform: translateY(-3px); box-shadow: 0 10px 26px var(--shadow-md); }
.stat-chip-icon {
  width: 40px; height: 40px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.stat-chip-body { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.stat-chip-val { font-family: 'Fraunces', serif; font-size: 18px; font-weight: 700; color: var(--text-dark); line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-chip-lbl { font-size: 10.5px; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }

.dashboard-grid {
  display: grid;
  grid-template-columns: 1.65fr 1fr;
  gap: 20px;
  align-items: start;
}
.col-stack { display: flex; flex-direction: column; gap: 20px; }

/* ============================================================
   9. PROFILE CARD
   ============================================================ */
.profile-card {
  background: linear-gradient(135deg, #ffffff 0%, var(--teal-100) 140%);
  border-radius: 22px;
  border: 1px solid var(--border);
  box-shadow: 0 4px 24px var(--shadow);
  padding: 24px 26px;
  display: flex;
  align-items: center;
  gap: 24px;
  position: relative;
  overflow: hidden;
}

.profile-img-wrap { position: relative; flex-shrink: 0; }

.profile-img-wrap::before {
  content: '';
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  background: conic-gradient(from 0deg, var(--teal-500), var(--coral), var(--gold), var(--teal-500));
  animation: spinRing 6s linear infinite;
  opacity: 0.55;
  z-index: 0;
}
@keyframes spinRing { to { transform: rotate(360deg); } }

.profile-img-wrap img {
  width: 84px; height: 84px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid white;
  display: block;
  position: relative;
  z-index: 1;
}

.profile-img-wrap input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; border-radius: 50%; z-index: 2; }

.profile-img-badge {
  position: absolute;
  bottom: 2px; right: 2px;
  background: var(--teal-700);
  color: white;
  border-radius: 50%;
  width: 24px; height: 24px;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px;
  border: 2px solid white;
  pointer-events: none;
  z-index: 3;
}

.profile-info { flex: 1; min-width: 0; }

.profile-info h2 {
  font-family: 'Fraunces', serif;
  font-size: 20px;
  font-weight: 600;
  color: var(--text-dark);
  margin-bottom: 4px;
}

.role-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: white;
  color: var(--teal-700);
  padding: 3px 11px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 14px;
  border: 1px solid var(--teal-200);
}

.profile-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0; }

.profile-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding-right: 16px;
  border-right: 1px solid var(--border);
  margin-right: 16px;
}
.profile-item:last-child { border-right: none; margin-right: 0; padding-right: 0; }

.profile-item .label { font-size: 10px; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.6px; }
.profile-item .value { font-size: 13px; font-weight: 500; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ============================================================
   10. AI ORB — rotating neural / bio-scan visual (replaces skeleton)
   ============================================================ */
.orb-card { display: flex; flex-direction: column; align-items: center; text-align: center; }
.ai-orb-wrap { width: 200px; height: 250px; position: relative; margin: 4px auto 10px; }
.ai-orb-wrap svg { overflow: visible; }

.orb-ambient-svg { position: absolute; top: 50%; left: 50%; width: 168px; height: 168px; transform: translate(-50%, -50%); z-index: 0; }

.orb-ring { fill: none; }
.ring-outer   { stroke: var(--teal-500); stroke-width: 1.1; opacity: 0.5; stroke-dasharray: 3 7; transform-origin: 84px 84px; animation: spin 14s linear infinite; }
.ring-mid     { stroke: var(--coral);    stroke-width: 1.1; opacity: 0.55; stroke-dasharray: 2 5; transform-origin: 84px 84px; animation: spin 9s linear infinite reverse; }
.ring-inner   { stroke: var(--gold);     stroke-width: 1.3; opacity: 0.6; stroke-dasharray: 1 4; transform-origin: 84px 84px; animation: spin 6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.orb-node { fill: white; stroke-width: 2; }
.orb-node.n-outer { stroke: var(--teal-500); }
.orb-node.n-mid   { stroke: var(--coral); }

.orb-particle { fill: var(--teal-600); animation: orbFloat 3.6s ease-in-out infinite; }
@keyframes orbFloat { 0%,100% { transform: translateY(0); opacity: 0.5; } 50% { transform: translateY(-5px); opacity: 1; } }

/* rotating human skeleton — sits centered inside the ambient rings */
.skeleton-rotate-wrap {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  perspective: 700px;
  z-index: 1;
}
.skeleton-rotate-inner {
  width: 106px;
  transform-style: preserve-3d;
  animation: skeletonSpin 9s linear infinite;
}
.skeleton-rotate-inner svg { width: 100%; height: auto; display: block; overflow: visible; }
@keyframes skeletonSpin { from { transform: rotateY(0deg); } to { transform: rotateY(360deg); } }

.skeleton-bone  { fill: none; stroke: var(--teal-600); stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; opacity: 0.9; }
.skeleton-joint { fill: var(--teal-100); stroke: var(--teal-600); stroke-width: 2.5; }
.skeleton-pulse { fill: none; stroke: var(--coral); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.skeleton-heart { fill: var(--coral); transform-origin: center; animation: skeletonHeartbeat 1.8s ease-in-out infinite; }
@keyframes skeletonHeartbeat {
  0%,100% { transform: scale(1); }
  15% { transform: scale(1.3); }
  30% { transform: scale(0.94); }
  45% { transform: scale(1.18); }
  60% { transform: scale(1); }
}

.orb-caption { font-size: 11.5px; color: var(--text-light); margin-bottom: 16px; }
.orb-caption b { color: var(--teal-700); }

/* legend */
.comp-legend { display: flex; flex-direction: column; gap: 9px; width: 100%; }
.comp-legend-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-mid); }
.comp-legend-row .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.comp-legend-row .val { margin-left: auto; font-weight: 700; color: var(--text-dark); }

/* ============================================================
   11. VITALS FORM
   ============================================================ */
.vitals-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }

.input-group { display: flex; flex-direction: column; gap: 5px; }

.input-group label {
  font-size: 11px;
  font-weight: 700;
  color: var(--text-mid);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.input-group input {
  background: var(--bg);
  border: 1.5px solid var(--border);
  border-radius: 10px;
  padding: 10px 13px;
  font-size: 13.5px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  color: var(--text-dark);
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}

.input-group input:focus {
  border-color: var(--teal-500);
  background: white;
  box-shadow: 0 0 0 3px rgba(31,174,142,0.15);
}

.input-group input::placeholder { color: var(--text-light); font-size: 13px; }

.btn-save {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, var(--teal-700), var(--teal-500));
  background-size: 200% 200%;
  color: white;
  border: none;
  border-radius: 12px;
  padding: 13px 24px;
  font-size: 14.5px;
  font-weight: 600;
  font-family: 'Plus Jakarta Sans', sans-serif;
  cursor: pointer;
  box-shadow: 0 6px 20px rgba(15,122,104,0.30);
  transition: all 0.22s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-save:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgba(15,122,104,0.40);
  background-position: 100% 50%;
}
.btn-save:active { transform: translateY(0) scale(0.98); }
.btn-save.is-saving i { animation: spin 0.7s linear infinite; }

/* ============================================================
   12. QUICK ACCESS — compact sidebar version
   ============================================================ */
.activity-grid-compact { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }

.activity-card {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 14px 8px 12px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  text-align: center;
  transition: all 0.25s cubic-bezier(.22,1,.36,1);
  text-decoration: none;
}

.activity-card:hover {
  transform: translateY(-4px) scale(1.02);
  box-shadow: 0 10px 24px var(--shadow-md);
  border-color: var(--teal-200);
  background: white;
}

.activity-icon {
  width: 38px; height: 38px;
  border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  transition: transform 0.3s cubic-bezier(.22,1.4,.36,1);
}

.activity-card:hover .activity-icon { transform: scale(1.15) rotate(-6deg); }

.activity-card h3 { font-size: 10.5px; font-weight: 600; color: var(--text-dark); line-height: 1.25; }

.icon-teal    { background: var(--teal-100); color: var(--teal-700); }
.icon-coral   { background: var(--coral-soft); color: #be185d; }
.icon-mint    { background: #ede4fb; color: #6d28d9; }
.icon-amber   { background: #f4ecff; color: #8b5cf6; }
.icon-sky     { background: #e9e6fd; color: #6366f1; }
.icon-rose    { background: #fbe3f0; color: #be185d; }
.icon-indigo  { background: #e5dcfb; color: #5b21b6; }
.icon-violet  { background: var(--violet-soft); color: var(--violet); }
.icon-gold    { background: #f1e7fc; color: #9333ea; }

/* ============================================================
   13. MODERN RADIAL BMI GAUGE
   ============================================================ */
.bmi-radial-wrap { display: flex; flex-direction: column; align-items: center; }
.bmi-radial-svg { width: 100%; max-width: 300px; }
.bmi-arc-zone { fill: none; stroke-linecap: round; }
.bmi-needle-line { stroke: var(--text-dark); stroke-width: 3; stroke-linecap: round; transition: all 0.9s cubic-bezier(.22,1,.36,1); }
.bmi-needle-pivot { fill: var(--text-dark); }
.bmi-tick-label { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 9.5px; font-weight: 600; fill: var(--text-light); }

.bmi-radial-readout { display: flex; flex-direction: column; align-items: center; gap: 2px; margin-top: -46px; }
.bmi-radial-num { font-family: 'Fraunces', serif; font-size: 34px; font-weight: 700; color: var(--text-dark); line-height: 1; }
.bmi-radial-status { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }

.bmi-legend-row { display: flex; justify-content: center; gap: 14px; flex-wrap: wrap; margin-top: 10px; }
.bmi-legend-item { display: flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 600; color: var(--text-mid); }
.bmi-legend-item i { font-size: 7px; }

.bmi-pediatric-note { font-size: 11px; color: var(--text-light); margin-top: 10px; text-align: center; font-style: italic; }

/* ============================================================
   14. MODERN CHARTS
   ============================================================ */
.charts-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }

.chart-card {
  background: var(--bg);
  border-radius: 14px;
  border: 1px solid var(--border);
  padding: 16px 16px 14px;
  transition: box-shadow 0.22s, transform 0.22s;
}
.chart-card:hover { box-shadow: 0 6px 22px var(--shadow-md); transform: translateY(-3px); }

.chart-card h3 {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--text-mid);
  margin: 0 0 4px;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  display: flex;
  align-items: center;
  gap: 7px;
}

.chart-card h3 span {
  width: 8px; height: 8px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
  animation: dotPulse 1.6s ease-in-out infinite;
}
@keyframes dotPulse { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.4); opacity: 0.6; } }

.chart-latest { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
.chart-latest small { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 11px; font-weight: 600; color: var(--text-light); margin-left: 4px; }

/* ============================================================
   15. FOOTER / TOAST
   ============================================================ */
.page-footer {
  text-align: center;
  padding: 14px 32px 28px;
  font-size: 12.5px;
  color: var(--text-light);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.status-pill { background: var(--teal-100); color: var(--teal-700); padding: 3px 13px; border-radius: 99px; font-weight: 600; font-size: 12px; }

#toast {
  position: fixed;
  bottom: 26px;
  right: 26px;
  z-index: 999;
  background: white;
  border: 1px solid var(--border);
  border-left: 4px solid var(--teal-500);
  box-shadow: 0 12px 34px var(--shadow-md);
  border-radius: 14px;
  padding: 14px 18px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  max-width: 300px;
  transform: translateY(20px) scale(0.95);
  opacity: 0;
  pointer-events: none;
  transition: all 0.35s cubic-bezier(.22,1,.36,1);
}
#toast.show { transform: translateY(0) scale(1); opacity: 1; }
#toast.error { border-left-color: var(--coral); }
#toast .toast-icon { font-size: 16px; color: var(--teal-600); margin-top: 1px; }
#toast.error .toast-icon { color: var(--coral); }
#toast .toast-title { font-size: 13px; font-weight: 700; color: var(--text-dark); }
#toast .toast-body { font-size: 12px; color: var(--text-mid); margin-top: 2px; }

/* ============================================================
   16. RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
  .dashboard-grid { grid-template-columns: 1fr; }
  .stat-strip { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 1100px) {
  .vitals-grid { grid-template-columns: repeat(3, 1fr); }
  .charts-grid { grid-template-columns: repeat(2, 1fr); }
  .profile-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
  .sidebar { width: 64px; }
  .sidebar-logo .logo-text,
  .sidebar-nav a span,
  .sidebar-bottom a span,
  .nav-label, .pulse-strip { display: none; }
  .sidebar-logo { padding: 18px 12px; justify-content: center; }
  .sidebar-nav a { padding: 11px; justify-content: center; }
  .sidebar-bottom { padding: 12px; }
  .main-content { margin-left: 64px; }
  .page-body { padding: 16px; }
  .vitals-grid { grid-template-columns: 1fr 1fr; }
  .activity-grid-compact { grid-template-columns: repeat(3, 1fr); }
  .charts-grid { grid-template-columns: 1fr; }
  .profile-grid { grid-template-columns: repeat(2, 1fr); }
  .profile-card { flex-direction: column; align-items: flex-start; }
  .comp-body { justify-content: center; }
  .stat-strip { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<div class="ambient-blob blob-1"></div>
<div class="ambient-blob blob-2"></div>
<div class="ambient-blob blob-3"></div>

<div class="layout">

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon"><i class="fas fa-heartbeat"></i></div>
    <span class="logo-text">Zaman Medical<br>Center</span>
  </div>

  <div class="pulse-strip">
    <svg viewBox="0 0 300 22" preserveAspectRatio="none">
      <path d="M0,11 L40,11 L48,2 L56,20 L64,4 L72,11 L110,11 L118,2 L126,20 L134,4 L142,11 L180,11 L188,2 L196,20 L204,4 L212,11 L250,11 L258,2 L266,20 L274,4 L282,11 L300,11"
            fill="none" stroke="#1fae8e" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>
    </svg>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a href="#" class="active"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="patientprofile.php"><i class="fas fa-user"></i><span>My Profile</span></a>
    <a href="bookappointment.php"><i class="fas fa-calendar-check"></i><span>Book Appointment</span></a>
    <a href="patientappointment.php"><i class="fas fa-notes-medical"></i><span>My Appointments</span></a>
    <a href="patientprescription.php"><i class="fas fa-pills"></i><span>Prescriptions</span></a>
    <a href="patientmedicalrecords.php"><i class="fas fa-file-medical"></i><span>Medical Records</span></a>
    <div class="nav-label">More</div>
    <a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Billing</span></a>
    <a href="patientbedstatus.php"><i class="fas fa-procedures"></i><span>Bed Status</span></a>
    <a href="patientdoctoravailability.php"><i class="fas fa-user-md"></i><span>Doctor Details</span></a>

    <div class="nav-label">Account</div>
    <a href="patientchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
    <a href="ocr/process.php?patient_id=<?= $_SESSION['user_id'] ?>"><i class="fas fa-upload"></i><span>Upload Lab Report</span></a>
  </nav>

  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main-content">

  <!-- Topbar -->
  <header class="topbar reveal">
    <div class="topbar-left">
      <div class="topbar-title">Dashboard</div>
      <div class="topbar-date"><?= date('l, F d Y') ?></div>
    </div>
    <div class="topbar-right">
      <div class="topbar-status">
        <div class="status-dot"></div>
        <?= htmlspecialchars($patient['status'] ?: 'Active') ?>
      </div>
      <div class="topbar-avatar">
        <img src="<?= htmlspecialchars($patient['profile_image']) ?>" alt="avatar" id="topbarAvatar">
        <span><?= htmlspecialchars($patient['full_name']) ?></span>
        <i class="fas fa-chevron-down" style="font-size:10px;color:var(--text-light)"></i>
      </div>
    </div>
  </header>

  <div class="page-body">

    <!-- Profile Card -->
    <div class="profile-card reveal reveal-d1">
      <div class="profile-img-wrap">
        <img id="profileImage" src="<?= htmlspecialchars($patient['profile_image']) ?>" alt="Profile">
        <div class="profile-img-badge"><i class="fas fa-camera"></i></div>
        <input type="file" id="profileUpload" accept="image/*">
      </div>
      <div class="profile-info">
        <h2><?= htmlspecialchars($patient['full_name']) ?></h2>
        <div class="role-badge">
          <i class="fas fa-id-badge" style="font-size:10px"></i>
          Patient &middot; ID #<?= htmlspecialchars($patient['user_id'] ?: '—') ?>
        </div>
        <div class="profile-grid">
          <div class="profile-item">
            <span class="label">Age</span>
            <span class="value"><?= htmlspecialchars($patient['age'] ?: '—') ?></span>
          </div>
          <div class="profile-item">
            <span class="label">Gender</span>
            <span class="value"><?= htmlspecialchars($patient['gender'] ?: '—') ?></span>
          </div>
          <div class="profile-item">
            <span class="label">Contact</span>
            <span class="value"><?= htmlspecialchars($patient['contact'] ?: '—') ?></span>
          </div>
          <div class="profile-item">
            <span class="label">Email</span>
            <span class="value"><?= htmlspecialchars($patient['email'] ?: '—') ?></span>
          </div>
          <div class="profile-item">
            <span class="label">Address</span>
            <span class="value"><?= htmlspecialchars($patient['address'] ?: '—') ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick stat strip -->
    <div class="stat-strip reveal reveal-d2">
      <div class="stat-chip">
        <div class="stat-chip-icon icon-gold"><i class="fas fa-weight"></i></div>
        <div class="stat-chip-body">
          <span class="stat-chip-val"><?= !empty($weights) ? number_format($weights[0],1).' kg' : '—' ?></span>
          <span class="stat-chip-lbl">Weight</span>
        </div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-icon icon-violet"><i class="fas fa-ruler-vertical"></i></div>
        <div class="stat-chip-body">
          <span class="stat-chip-val"><?= $patient['height'] ? number_format($patient['height'],0).' cm' : '—' ?></span>
          <span class="stat-chip-lbl">Height</span>
        </div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-icon icon-sky"><i class="fas fa-heart-pulse"></i></div>
        <div class="stat-chip-body">
          <span class="stat-chip-val"><?= !empty($heartRateArr) && $heartRateArr[0] > 0 ? (int)$heartRateArr[0].' bpm' : '—' ?></span>
          <span class="stat-chip-lbl">Heart Rate</span>
        </div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-icon icon-rose"><i class="fas fa-vial"></i></div>
        <div class="stat-chip-body">
          <span class="stat-chip-val"><?= !empty($hemoglobinArr) && $hemoglobinArr[0] > 0 ? number_format($hemoglobinArr[0],1) : '—' ?></span>
          <span class="stat-chip-lbl">Hemoglobin</span>
        </div>
      </div>
    </div>

    <!-- Bento layout: main column + side column -->
    <div class="dashboard-grid">

      <!-- ── LEFT / MAIN COLUMN ── -->
      <div class="col-stack">

        <!-- Vitals Form -->
        <div class="card reveal reveal-d3">
          <div class="section-header">
            <div class="section-title">Enter Today's Vitals</div>
            <div class="section-meta">
              <i class="fas fa-calendar-day"></i>
              <?= date('F d, Y') ?>
            </div>
          </div>
          <form id="vitalsForm">
            <div class="vitals-grid">
              <div class="input-group">
                <label><i class="fas fa-weight" style="color:var(--teal-600)"></i>Weight (kg)</label>
                <input type="number" step="0.1" name="weight" placeholder="e.g. 70.5"/>
              </div>
              <div class="input-group">
                <label><i class="fas fa-ruler-vertical" style="color:#7c3aed"></i>Height (cm)</label>
                <input type="number" step="0.1" name="height" value="<?= htmlspecialchars($patient['height']) ?>" placeholder="e.g. 175"/>
              </div>
              <div class="input-group">
                <label><i class="fas fa-fire" style="color:#f97316"></i>Calories</label>
                <input type="number" name="calories" placeholder="e.g. 2000"/>
              </div>
              <div class="input-group">
                <label><i class="fas fa-tint" style="color:var(--coral)"></i>Blood Sugar</label>
                <input type="number" step="0.1" name="blood_sugar" placeholder="e.g. 90"/>
              </div>
              <div class="input-group">
                <label><i class="fas fa-heartbeat" style="color:#ec4899"></i>Heart Rate (bpm)</label>
                <input type="number" name="heart_rate" placeholder="e.g. 72"/>
              </div>
              <div class="input-group">
                <label><i class="fas fa-stethoscope" style="color:#2563eb"></i>Blood Pressure</label>
                <input type="text" name="blood_pressure" placeholder="e.g. 120/80"/>
              </div>
              <div class="input-group">
                <label><i class="fas fa-vial" style="color:var(--gold)"></i>Hemoglobin</label>
                <input type="number" step="0.1" name="hemoglobin" placeholder="e.g. 13.5"/>
              </div>
              <div class="input-group" style="align-self:end">
                <button type="submit" class="btn-save" id="saveVitalsBtn">
                  <i class="fas fa-save"></i> Save Vitals
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- Vitals Charts -->
        <div class="card reveal reveal-d4">
          <div class="section-header">
            <div class="section-title">Vitals Overview</div>
            <div class="section-meta"><i class="fas fa-history"></i> Last 10 entries</div>
          </div>
          <div class="charts-grid">
            <div class="chart-card">
              <h3><span style="background:#c084fc"></span>Weight</h3>
              <div class="chart-latest"><?= !empty($rev_weights) ? number_format(end($rev_weights),1) : '—' ?><small>kg</small></div>
              <canvas id="weightChart" height="150"></canvas>
            </div>
            <div class="chart-card">
              <h3><span style="background:#818cf8"></span>Calories Intake</h3>
              <div class="chart-latest"><?= !empty($rev_calories) ? number_format(end($rev_calories)) : '—' ?><small>kcal</small></div>
              <canvas id="caloriesChart" height="150"></canvas>
            </div>
            <div class="chart-card">
              <h3><span style="background:#7c4dff"></span>BMI</h3>
              <div class="chart-latest"><?= !empty($rev_bmi) ? number_format(end($rev_bmi),1) : '—' ?><small>kg/m²</small></div>
              <canvas id="bmiChart" height="150"></canvas>
            </div>
            <div class="chart-card">
              <h3><span style="background:#ec4899"></span>Blood Sugar</h3>
              <div class="chart-latest"><?= !empty($rev_bloodSugar) ? number_format(end($rev_bloodSugar),1) : '—' ?><small>mg/dL</small></div>
              <canvas id="bloodSugarChart" height="150"></canvas>
            </div>
            <div class="chart-card">
              <h3><span style="background:#a78bfa"></span>Heart Rate</h3>
              <div class="chart-latest"><?= !empty($rev_heartRate) ? number_format(end($rev_heartRate)) : '—' ?><small>bpm</small></div>
              <canvas id="heartRateChart" height="150"></canvas>
            </div>
            <div class="chart-card">
              <h3><span style="background:#9061f9"></span>Hemoglobin</h3>
              <div class="chart-latest"><?= !empty($rev_hemoglobin) ? number_format(end($rev_hemoglobin),1) : '—' ?><small>g/dL</small></div>
              <canvas id="hemoglobinChart" height="150"></canvas>
            </div>
          </div>
        </div>

      </div>
      <!-- /LEFT COLUMN -->

      <!-- ── RIGHT / SIDE COLUMN ── -->
      <div class="col-stack">

        <!-- AI Orb / Body composition -->
        <div class="card orb-card reveal reveal-d3">
          <div class="section-header" style="width:100%">
            <div class="section-title">Body Scan</div>
            <div class="section-meta"><i class="fas fa-satellite-dish"></i> Live</div>
          </div>
          <div class="ai-orb-wrap">
            <svg class="orb-ambient-svg" viewBox="0 0 168 168">
              <circle class="orb-ring ring-outer" cx="84" cy="84" r="78"/>
              <circle class="orb-ring ring-mid" cx="84" cy="84" r="60"/>
              <circle class="orb-ring ring-inner" cx="84" cy="84" r="44"/>

              <!-- orbiting nodes -->
              <g class="ring-outer"><circle class="orb-node n-outer" cx="84" cy="6" r="3.4"/></g>
              <g class="ring-mid"><circle class="orb-node n-mid" cx="144" cy="84" r="3"/></g>
              <g class="ring-outer" style="animation-duration:14s"><circle class="orb-node n-outer" cx="24" cy="84" r="2.6"/></g>

              <!-- floating particles -->
              <circle class="orb-particle" cx="30" cy="40" r="2" style="animation-delay:0s"/>
              <circle class="orb-particle" cx="140" cy="50" r="2.2" style="animation-delay:.8s"/>
              <circle class="orb-particle" cx="136" cy="128" r="1.8" style="animation-delay:1.6s"/>
              <circle class="orb-particle" cx="26" cy="122" r="2" style="animation-delay:2.2s"/>
            </svg>

            <div class="skeleton-rotate-wrap">
              <div class="skeleton-rotate-inner">
                <svg viewBox="0 0 120 260" xmlns="http://www.w3.org/2000/svg">
                  <!-- skull -->
                  <circle class="skeleton-bone" cx="60" cy="22" r="14"/>
                  <path class="skeleton-bone" d="M52,32 L52,38 M68,32 L68,38"/>
                  <!-- spine -->
                  <path class="skeleton-bone" d="M60,36 L60,150"/>
                  <!-- ribcage -->
                  <ellipse class="skeleton-bone" cx="60" cy="76" rx="25" ry="32"/>
                  <path class="skeleton-bone" d="M38,64 L82,64 M36,76 L84,76 M38,88 L82,88"/>
                  <!-- clavicle + shoulders -->
                  <path class="skeleton-bone" d="M40,45 L80,45"/>
                  <circle class="skeleton-joint" cx="40" cy="45" r="4"/>
                  <circle class="skeleton-joint" cx="80" cy="45" r="4"/>
                  <!-- arms -->
                  <path class="skeleton-bone" d="M40,45 L30,90 M30,90 L26,135"/>
                  <path class="skeleton-bone" d="M80,45 L90,90 M90,90 L94,135"/>
                  <circle class="skeleton-joint" cx="30" cy="90" r="4"/>
                  <circle class="skeleton-joint" cx="90" cy="90" r="4"/>
                  <circle class="skeleton-joint" cx="26" cy="135" r="3"/>
                  <circle class="skeleton-joint" cx="94" cy="135" r="3"/>
                  <!-- pelvis -->
                  <path class="skeleton-bone" d="M40,150 L80,150 L74,170 L46,170 Z"/>
                  <circle class="skeleton-joint" cx="46" cy="170" r="4.5"/>
                  <circle class="skeleton-joint" cx="74" cy="170" r="4.5"/>
                  <!-- legs -->
                  <path class="skeleton-bone" d="M46,170 L44,215 M44,215 L42,255"/>
                  <path class="skeleton-bone" d="M74,170 L76,215 M76,215 L78,255"/>
                  <circle class="skeleton-joint" cx="44" cy="215" r="4"/>
                  <circle class="skeleton-joint" cx="76" cy="215" r="4"/>
                  <!-- feet -->
                  <path class="skeleton-bone" d="M36,258 L50,258 M70,258 L84,258"/>
                  <!-- ECG pulse line across the chest -->
                  <path class="skeleton-pulse" d="M20,76 L44,76 L50,60 L58,92 L64,66 L70,76 L100,76"/>
                  <!-- heart marker -->
                  <circle class="skeleton-heart" cx="52" cy="70" r="4"/>
                </svg>
              </div>
            </div>
          </div>
          <div class="orb-caption">Composite reading from <b>4</b> latest vitals</div>
          <div class="comp-legend">
            <div class="comp-legend-row"><span class="dot" style="background:var(--gold)"></span>Weight <span class="val"><?= !empty($weights) ? number_format($weights[0],1).' kg' : '—' ?></span></div>
            <div class="comp-legend-row"><span class="dot" style="background:var(--violet)"></span>Height <span class="val"><?= $patient['height'] ? number_format($patient['height'],0).' cm' : '—' ?></span></div>
            <div class="comp-legend-row"><span class="dot" style="background:var(--blue)"></span>Heart Rate <span class="val"><?= !empty($heartRateArr) && $heartRateArr[0] > 0 ? (int)$heartRateArr[0].' bpm' : '—' ?></span></div>
            <div class="comp-legend-row"><span class="dot" style="background:var(--coral)"></span>Hemoglobin <span class="val"><?= !empty($hemoglobinArr) && $hemoglobinArr[0] > 0 ? number_format($hemoglobinArr[0],1) : '—' ?></span></div>
          </div>
        </div>

        <!-- Modern Radial BMI Gauge -->
        <div class="card reveal reveal-d4">
          <div class="section-header">
            <div class="section-title" style="font-size:15px"><?= htmlspecialchars($bmiGaugeTitle) ?></div>
            <div class="section-meta"><i class="fas fa-notes-medical"></i> Latest</div>
          </div>
          <div class="bmi-radial-wrap">
            <svg class="bmi-radial-svg" viewBox="0 0 300 190">
              <path class="bmi-arc-zone" id="arcZone1" d="<?= $arcZone1 ?>" stroke="#3b82f6" stroke-width="16"/>
              <path class="bmi-arc-zone" id="arcZone2" d="<?= $arcZone2 ?>" stroke="#1fae8e" stroke-width="16"/>
              <path class="bmi-arc-zone" id="arcZone3" d="<?= $arcZone3 ?>" stroke="#f2b840" stroke-width="16"/>
              <path class="bmi-arc-zone" id="arcZone4" d="<?= $arcZone4 ?>" stroke="#ff6363" stroke-width="16"/>

              <text class="bmi-tick-label" x="30" y="168" text-anchor="middle">0</text>
              <text class="bmi-tick-label" x="150" y="24" text-anchor="middle"><?= rtrim(rtrim(number_format(($cutLow+$cutHigh)/2, 1), '0'), '.') ?></text>
              <text class="bmi-tick-label" x="270" y="168" text-anchor="middle"><?= $scaleMax ?></text>

              <line class="bmi-needle-line" id="bmiNeedle" x1="150" y1="150" x2="<?= $needleX ?>" y2="<?= $needleY ?>"/>
              <circle class="bmi-needle-pivot" cx="150" cy="150" r="7"/>
            </svg>
            <div class="bmi-radial-readout">
              <span class="bmi-radial-num" id="bmiValueNum"><?= $currentBMI > 0 ? number_format($currentBMI, 1) : '—' ?></span>
              <span class="bmi-radial-status" id="bmiValueStatus" style="color: <?= $bmiStatusColor ?>"><?= htmlspecialchars($bmiStatusText) ?></span>
            </div>
            <div class="bmi-legend-row">
              <span class="bmi-legend-item" style="color:#3b82f6"><i class="fas fa-circle"></i>Underweight</span>
              <span class="bmi-legend-item" style="color:#1fae8e"><i class="fas fa-circle"></i>Healthy</span>
              <span class="bmi-legend-item" style="color:#f2b840"><i class="fas fa-circle"></i>Overweight</span>
              <span class="bmi-legend-item" style="color:#ff6363"><i class="fas fa-circle"></i>Obese</span>
            </div>
            <?php if ($isPediatric): ?>
            <div class="bmi-pediatric-note">
              <i class="fas fa-info-circle"></i>
              Pediatric range shown as approximate BMI-for-age percentile cutoffs (5th / 85th / 95th).
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Quick Access -->
        <div class="card reveal reveal-d5">
          <div class="section-header">
            <div class="section-title">Quick Access</div>
          </div>
          <div class="activity-grid-compact">
            <a href="patientprofile.php" class="activity-card">
              <div class="activity-icon icon-teal"><i class="fas fa-user"></i></div>
              <h3>Profile</h3>
            </a>
            <a href="bookappointment.php" class="activity-card">
              <div class="activity-icon icon-coral"><i class="fas fa-calendar-check"></i></div>
              <h3>Book</h3>
            </a>
            <a href="patientappointment.php" class="activity-card">
              <div class="activity-icon icon-mint"><i class="fas fa-notes-medical"></i></div>
              <h3>Appointments</h3>
            </a>
            <a href="patientprescription.php" class="activity-card">
              <div class="activity-icon icon-amber"><i class="fas fa-pills"></i></div>
              <h3>Prescriptions</h3>
            </a>
            <a href="patientmedicalrecords.php" class="activity-card">
              <div class="activity-icon icon-sky"><i class="fas fa-file-medical"></i></div>
              <h3>Records</h3>
            </a>
            <a href="billing.php" class="activity-card">
              <div class="activity-icon icon-rose"><i class="fas fa-file-invoice-dollar"></i></div>
              <h3>Billing</h3>
            </a>
            <a href="patientbedstatus.php" class="activity-card">
              <div class="activity-icon icon-indigo"><i class="fas fa-procedures"></i></div>
              <h3>Bed Status</h3>
            </a>
            <a href="patientchangepassword.php" class="activity-card">
              <div class="activity-icon icon-violet"><i class="fas fa-lock"></i></div>
              <h3>Password</h3>
            </a>
            <a href="patientdoctoravailability.php" class="activity-card">
              <div class="activity-icon icon-gold"><i class="fas fa-user-md"></i></div>
              <h3>Doctors</h3>
            </a>
          </div>
        </div>

      </div>
      <!-- /RIGHT COLUMN -->

    </div>
    <!-- /dashboard-grid -->

  </div><!-- /page-body -->

  <footer class="page-footer">
    Account Status: <span class="status-pill"><?= htmlspecialchars($patient['status'] ?: 'Active') ?></span>
    &nbsp;&middot;&nbsp; SHAPMS Patient Portal
  </footer>

</div><!-- /main-content -->
</div><!-- /layout -->

<div id="toast">
  <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
  <div>
    <div class="toast-title" id="toastTitle">Saved</div>
    <div class="toast-body" id="toastBody"></div>
  </div>
</div>

<script>
const days       = <?= json_encode($rev_days) ?>;
const weights    = <?= json_encode($rev_weights) ?>;
const bmiData    = <?= json_encode($rev_bmi) ?>;
const calories   = <?= json_encode($rev_calories) ?>;
const bloodSugar = <?= json_encode($rev_bloodSugar) ?>;
const heartRate  = <?= json_encode($rev_heartRate) ?>;
const hemoglobin = <?= json_encode($rev_hemoglobin) ?>;

// BMI gauge cutoffs for THIS patient (adult fixed values, or pediatric BMI-for-age
// percentile cutoffs when the patient is a minor) — computed server-side so the
// client stays in sync without re-implementing the growth chart table in JS.
const bmiCutLow   = <?= json_encode($cutLow) ?>;
const bmiCutMid   = <?= json_encode($cutMid) ?>;
const bmiCutHigh  = <?= json_encode($cutHigh) ?>;
const bmiScaleMax = <?= json_encode($scaleMax) ?>;
const gaugeCx = 150, gaugeCy = 150, gaugeR = 118 - 34;

function polarPointJs(cx, cy, r, angleDeg) {
  const rad = (angleDeg - 90) * Math.PI / 180;
  return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
}
function pctToAngleJs(pct) { return -90 + (pct / 100) * 180; }

Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#8fa39d';

// ── Modern smooth area/line chart factory ──
function createAreaChart(ctxId, label, data, colorHex) {
  const canvas = document.getElementById(ctxId);
  const ctx = canvas.getContext('2d');
  const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height || 150);
  gradient.addColorStop(0, colorHex + '4D');
  gradient.addColorStop(1, colorHex + '00');
  return new Chart(ctx, {
    type: 'line',
    data: {
      labels: days,
      datasets: [{
        label, data,
        borderColor: colorHex,
        backgroundColor: gradient,
        fill: true,
        tension: 0.42,
        borderWidth: 2.5,
        pointRadius: 3,
        pointHoverRadius: 5,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: colorHex,
        pointBorderWidth: 2
      }]
    },
    options: {
      responsive: true,
      animation: { duration: 900, easing: 'easeOutQuart' },
      interaction: { intersect: false, mode: 'index' },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#10231f',
          titleColor: '#bdeee3',
          bodyColor: '#f2f7f5',
          padding: 10,
          cornerRadius: 8,
          displayColors: false
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#8fa39d' } },
        y: { grid: { color: 'rgba(31,174,142,0.10)' }, ticks: { color: '#8fa39d' }, beginAtZero: false }
      }
    }
  });
}

const weightChart     = createAreaChart('weightChart',    'Weight (kg)', weights,    '#c084fc');
const bmiChart        = createAreaChart('bmiChart',       'BMI',         bmiData,    '#7c4dff');
const caloriesChart   = createAreaChart('caloriesChart',  'Calories',    calories,   '#818cf8');
const bloodSugarChart = createAreaChart('bloodSugarChart','Blood Sugar', bloodSugar, '#ec4899');
const heartRateChart  = createAreaChart('heartRateChart', 'Heart Rate',  heartRate,  '#a78bfa');
const hemoglobinChart = createAreaChart('hemoglobinChart','Hemoglobin',  hemoglobin, '#9061f9');

function updateChartLatest(cardH3Selector, value, digits) {
  // no-op placeholder kept for clarity; latest values are updated inline below on save
}

// ── Toast helper ──
let toastTimer = null;
function showToast(title, body, isError) {
  const toast = document.getElementById('toast');
  document.getElementById('toastTitle').textContent = title;
  document.getElementById('toastBody').textContent = body;
  toast.querySelector('.toast-icon i').className = isError ? 'fas fa-circle-exclamation' : 'fas fa-check-circle';
  toast.classList.toggle('error', !!isError);
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 3600);
}

// ── Count-up animation for the BMI number ──
function animateNumber(el, from, to, duration) {
  const start = performance.now();
  function tick(now) {
    const p = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - p, 3);
    el.textContent = (from + (to - from) * eased).toFixed(1);
    if (p < 1) requestAnimationFrame(tick);
    else el.textContent = to.toFixed(1);
  }
  requestAnimationFrame(tick);
}

// AJAX Vitals Submit
document.getElementById('vitalsForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  formData.append('ajax', 'save_vitals');
  const btn = document.getElementById('saveVitalsBtn');
  btn.classList.add('is-saving');
  btn.querySelector('i').className = 'fas fa-spinner';
  fetch('patientdashboard.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
      btn.classList.remove('is-saving');
      btn.querySelector('i').className = 'fas fa-save';
      if (data.success) {
        const v = data.vitals;

        // Update the radial BMI gauge (uses this patient's adult or pediatric cutoffs)
        const bmiVal = parseFloat(v.bmi);
        const prevBmi = parseFloat(document.getElementById('bmiValueNum').textContent) || 0;
        const bmiPercent = Math.min(Math.max((bmiVal / bmiScaleMax) * 100, 0), 100);
        const needleAngle = pctToAngleJs(bmiPercent);
        const [nx, ny] = polarPointJs(gaugeCx, gaugeCy, gaugeR, needleAngle);
        const needleEl = document.getElementById('bmiNeedle');
        needleEl.setAttribute('x2', nx.toFixed(2));
        needleEl.setAttribute('y2', ny.toFixed(2));
        animateNumber(document.getElementById('bmiValueNum'), prevBmi, bmiVal, 700);

        let bmiColor = '#8fa39d';
        let bmiStatusText = v.bmi_status;
        if (bmiVal > 0) {
          if (bmiVal < bmiCutLow) { bmiColor = '#3b82f6'; bmiStatusText = 'Underweight'; }
          else if (bmiVal < bmiCutMid) { bmiColor = '#1fae8e'; bmiStatusText = 'Healthy'; }
          else if (bmiVal < bmiCutHigh) { bmiColor = '#f2b840'; bmiStatusText = 'Overweight'; }
          else { bmiColor = '#ff6363'; bmiStatusText = 'Obese'; }
        }
        const bmiStatusEl = document.getElementById('bmiValueStatus');
        bmiStatusEl.textContent = bmiStatusText;
        bmiStatusEl.style.color = bmiColor;

        days.push(v.day);
        weights.push(parseFloat(v.weight));
        bmiData.push(parseFloat(v.bmi));
        calories.push(parseFloat(v.calories));
        bloodSugar.push(parseFloat(v.blood_sugar));
        heartRate.push(parseFloat(v.heart_rate));
        hemoglobin.push(parseFloat(v.hemoglobin));
        if (days.length > 10) {
          days.shift(); weights.shift(); bmiData.shift();
          calories.shift(); bloodSugar.shift(); heartRate.shift(); hemoglobin.shift();
        }
        weightChart.update(); bmiChart.update(); caloriesChart.update();
        bloodSugarChart.update(); heartRateChart.update(); hemoglobinChart.update();
        showToast('Vitals saved', 'BMI ' + v.bmi + ' · ' + v.bmi_status, false);
        this.reset();
      } else {
        showToast('Save failed', data.message || 'Please try again.', true);
      }
    })
    .catch(() => {
      btn.classList.remove('is-saving');
      btn.querySelector('i').className = 'fas fa-save';
      showToast('Network error', 'Could not reach the server.', true);
    });
});

// Profile upload
document.getElementById('profileUpload').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('profile_image', file);
  fetch('upload_profile.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        const t = d.url + '?v=' + Date.now();
        const img = document.getElementById('profileImage');
        img.style.opacity = 0;
        setTimeout(() => { img.src = t; img.style.transition = 'opacity .4s ease'; img.style.opacity = 1; }, 120);
        document.querySelector('.topbar-avatar img').src = t;
        showToast('Photo updated', 'Your profile picture was changed.', false);
      } else {
        showToast('Upload failed', d.message || 'Please try a different image.', true);
      }
    })
    .catch(err => {
      showToast('Network error', 'Upload could not complete.', true);
    });
});
</script>
</body>
</html>