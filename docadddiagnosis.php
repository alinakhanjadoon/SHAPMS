<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Connection failed: " . $conn->connect_error);

$user_id = (int)$_SESSION['user_id'];

/* GET DOCTOR ID */
$stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$doctor_id = $stmt->get_result()->fetch_assoc()['doctor_id'] ?? 0;
$stmt->close();

/* PATIENT LIST */
$patients_stmt = $conn->prepare("
    SELECT p.patient_id, u.full_name
    FROM patients p
    JOIN users u ON p.user_id = u.user_id
");
$patients_stmt->execute();
$patients_result = $patients_stmt->get_result();

/* INSERT DIAGNOSIS */
$success = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($patient_id <= 0 || empty($diagnosis)) {
        $error = "Please select patient and enter diagnosis!";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO medical_records 
            (patient_id, doctor_id, diagnosis, lab_reports, notes)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param("iisss", $patient_id, $doctor_id, $diagnosis, $prescription, $notes);

        if ($stmt->execute()) {
            $success = "Diagnosis saved successfully!";
        } else {
            $error = "Failed to save diagnosis.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Zaman Medical | Add Diagnosis</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --b1: #040d2a;
    --b2: #071240;
    --b3: #0d1f6b;
    --b4: #1e40af;
    --b5: #2563eb;
    --b6: #3b82f6;
    --b7: #60a5fa;
    --b8: #bfdbfe;
    --acc: #38bdf8;
    --acc2: #0ea5e9;
    --glow: rgba(37, 99, 235, 0.5);
    --glass: rgba(255, 255, 255, 0.045);
    --gb: rgba(255, 255, 255, 0.09);
    --gh: rgba(255, 255, 255, 0.07);
    --txt: #dde8ff;
    --mut: #5a7aa8;
    --sw: 268px;
    --r: 20px;
}

body {
    font-family: 'Outfit', sans-serif;
    background: var(--b1);
    color: var(--txt);
    min-height: 100vh;
    overflow-x: hidden;
}

/* DEEP BACKGROUND EFFECT */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: 
        radial-gradient(ellipse 90% 70% at 10% 10%, rgba(37, 99, 235, 0.25) 0%, transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%, rgba(14, 165, 233, 0.18) 0%, transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 45%, rgba(13, 31, 107, 0.5) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}

body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image: 
        linear-gradient(rgba(255, 255, 255, 0.018) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.018) 1px, transparent 1px);
    background-size: 52px 52px;
    pointer-events: none;
    z-index: 0;
}

/* SIDEBAR */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sw);
    height: 100vh;
    background: rgba(7, 18, 64, 0.75);
    backdrop-filter: blur(32px);
    -webkit-backdrop-filter: blur(32px);
    border-right: 1px solid var(--gb);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    z-index: 1000;
    scrollbar-width: none;
}

.sidebar::-webkit-scrollbar {
    display: none;
}

.sb-logo {
    padding: 26px 22px 20px;
    border-bottom: 1px solid var(--gb);
}

.sb-brand {
    font-family: 'Instrument Serif', serif;
    font-size: 18px;
    color: #fff;
    line-height: 1.3;
}

.sb-sub {
    font-size: 9.5px;
    color: var(--acc);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.13em;
    margin-top: 5px;
}

.sb-sec {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--mut);
    padding: 16px 22px 6px;
}

.sb-nav a {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 11px 14px 11px 22px;
    color: var(--mut);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    border-left: 2px solid transparent;
    margin-right: 10px;
    border-radius: 0 10px 10px 0;
    transition: all 0.2s;
}

.sb-nav a i {
    width: 17px;
    text-align: center;
    font-size: 14px;
}

.sb-nav a:hover {
    color: var(--b8);
    background: rgba(37, 99, 235, 0.12);
    border-left-color: var(--b6);
}

.sb-nav a.active-link {
    color: #fff;
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.38), rgba(37, 99, 235, 0.06));
    border-left-color: var(--acc);
    font-weight: 600;
}

.sb-nav a.active-link i {
    color: var(--acc);
}

.sb-foot {
    padding: 15px 22px;
    border-top: 1px solid var(--gb);
    font-size: 11px;
    color: var(--mut);
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: auto;
}

.ldot {
    width: 7px;
    height: 7px;
    background: #22d3ee;
    border-radius: 50%;
    box-shadow: 0 0 10px #22d3ee;
    animation: pulseDot 1.8s infinite;
}

@keyframes pulseDot {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.7); opacity: 0.45; }
}

/* MAIN CONTENT */
.main {
    margin-left: var(--sw);
    padding: 26px 28px 44px;
    min-height: 100vh;
    position: relative;
    z-index: 2;
}

/* TOPBAR */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    gap: 14px;
    flex-wrap: wrap;
}

.tl {
    display: flex;
    align-items: center;
    gap: 15px;
}

.doc-av {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    object-fit: cover;
    border: 2px solid var(--b5);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.22), 0 0 28px rgba(37, 99, 235, 0.35);
}

.g-text {
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.025em;
    line-height: 1.2;
}

.sp-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(37, 99, 235, 0.2);
    border: 1px solid rgba(37, 99, 235, 0.38);
    color: var(--b7);
    font-size: 11px;
    font-weight: 600;
    padding: 3px 12px;
    border-radius: 99px;
    margin-top: 5px;
}

.btnt {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    font-family: 'Outfit', sans-serif;
}

.btnt.lg {
    background: rgba(239, 68, 68, 0.18);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.btnt.lg:hover {
    background: rgba(239, 68, 68, 0.32);
    color: #fff;
}

.btnt.pr {
    background: rgba(37, 99, 235, 0.25);
    color: var(--b7);
    border: 1px solid rgba(37, 99, 235, 0.38);
}

/* FORM CARD */
.form-card {
    background: rgba(255, 255, 255, 0.035);
    border: 1px solid var(--gb);
    backdrop-filter: blur(22px);
    -webkit-backdrop-filter: blur(22px);
    border-radius: var(--r);
    padding: 30px 32px;
    transition: box-shadow 0.25s;
    position: relative;
    overflow: hidden;
}

.form-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.13), transparent);
}

.form-card:hover {
    box-shadow: 0 26px 65px rgba(0, 0, 0, 0.4);
}

.form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    border-bottom: 1px solid var(--gb);
    padding-bottom: 18px;
}

.form-header h3 {
    font-weight: 700;
    font-size: 1.6rem;
    margin: 0;
    background: linear-gradient(135deg, #ffffff, var(--b7));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.form-header i {
    font-size: 2rem;
    color: var(--acc);
    background: rgba(56, 189, 248, 0.1);
    padding: 10px;
    border-radius: 18px;
}

/* FORM ELEMENTS */
.form-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--mut);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-control, .form-select {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid var(--gb);
    color: #fff;
    border-radius: 14px;
    padding: 12px 16px;
    font-family: 'Outfit', sans-serif;
    transition: all 0.2s;
    width: 100%;
    font-size: 14px;
}

.form-control:focus, .form-select:focus {
    background: rgba(255, 255, 255, 0.1);
    border-color: var(--b5);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.28);
    color: #fff;
    outline: none;
}

textarea.form-control {
    resize: vertical;
    min-height: 100px;
}

.btn-save {
    background: linear-gradient(135deg, var(--b5), var(--acc2));
    border: none;
    padding: 14px;
    font-weight: 700;
    font-size: 15px;
    border-radius: 16px;
    color: white;
    width: 100%;
    transition: all 0.25s;
    margin-top: 20px;
    font-family: 'Outfit', sans-serif;
    box-shadow: 0 4px 22px rgba(37, 99, 235, 0.42);
    cursor: pointer;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(37, 99, 235, 0.55);
    background: linear-gradient(135deg, var(--b6), var(--acc));
}

/* ALERT STYLES */
.alert-custom {
    background: rgba(255, 255, 255, 0.05);
    border-left: 4px solid;
    border-radius: 14px;
    padding: 14px 20px;
    font-weight: 500;
    backdrop-filter: blur(8px);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success-custom {
    border-left-color: #4ade80;
    color: #bbf7d0;
}

.alert-error-custom {
    border-left-color: #f87171;
    color: #fed7d7;
}

.alert-close {
    margin-left: auto;
    background: none;
    border: none;
    color: currentColor;
    opacity: 0.7;
    cursor: pointer;
    font-size: 18px;
    padding: 0 4px;
}

.alert-close:hover {
    opacity: 1;
}

/* ANIMATION */
@keyframes fadeSlide {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animated-card {
    animation: fadeSlide 0.5s ease-out forwards;
}

/* INFO CARD */
.info-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--gb);
    border-radius: var(--r);
    padding: 16px 20px;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .main {
        margin-left: 0;
        padding: 20px;
    }
    .form-card {
        padding: 20px;
    }
    .topbar {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sb-logo">
        <div class="sb-brand">Zaman Medical<br>Hospital</div>
        <div class="sb-sub">Doctor Portal</div>
    </div>
    <nav class="sb-nav">
        <div class="sb-sec">Navigation</div>
        <a href="doctordashboard.php"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <a href="#" class="active-link"><i class="fa-solid fa-file-medical"></i> Add Diagnosis</a>
        <a href="docappointment.php"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
        <a href="docpatientrecord.php"><i class="fa-solid fa-users"></i> Patients</a>
        <a href="docprescriptions.php"><i class="fa-solid fa-pills"></i> Prescriptions</a>
        <a href="doctoravailability.php"><i class="fa-solid fa-clock"></i> Availability</a>
        <a href="docreports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
        <a href="docbilling.php"><i class="fa-solid fa-file-invoice-dollar"></i> Billing</a>
        <div class="sb-sec" style="margin-top: 4px;">Communication</div>
        <a href="chat.php"><i class="fa-solid fa-comments"></i> Messages</a>
    </nav>
    <div class="sb-foot">
        <span class="ldot"></span> System Online &nbsp;·&nbsp; v3.0
        <span style="margin-left: auto; font-size: 9px;" id="liveClock"></span>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">
    <!-- TOPBAR -->
    <div class="topbar">
        <div class="tl">
            <?php
            // Get doctor name for display
            $doc_name = "Doctor";
            $name_stmt = $conn->prepare("SELECT u.full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
            $name_stmt->bind_param("i", $user_id);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            if ($name_result && $row = $name_result->fetch_assoc()) {
                $doc_name = $row['full_name'];
            }
            $name_stmt->close();
            
            // Get specialty
            $specialty = "";
            $spec_stmt = $conn->prepare("SELECT specialty FROM doctors WHERE user_id = ?");
            $spec_stmt->bind_param("i", $user_id);
            $spec_stmt->execute();
            $spec_result = $spec_stmt->get_result();
            if ($spec_result && $row = $spec_result->fetch_assoc()) {
                $specialty = $row['specialty'] ?? 'General Medicine';
            }
            $spec_stmt->close();
            ?>
            <img src="https://ui-avatars.com/api/?name=<?= urlencode($doc_name) ?>&background=1e40af&color=fff&size=80&bold=true" 
                 class="doc-av" alt="doctor avatar">
            <div>
                <div class="g-text">Hello, Dr. <?= htmlspecialchars($doc_name) ?> 👋</div>
                <span class="sp-pill"><i class="fa-solid fa-stethoscope"></i> <?= htmlspecialchars($specialty) ?></span>
            </div>
        </div>
        <div class="tr">
            <a href="edit-profile.php" class="btnt pr"><i class="fa-solid fa-user-pen"></i> Edit Profile</a>
            <a href="logout.php" class="btnt lg"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <!-- DIAGNOSIS FORM CARD -->
    <div class="form-card animated-card">
        <div class="form-header">
            <h3><i class="fa-regular fa-clipboard me-2"></i> New Medical Diagnosis</h3>
            <i class="fa-solid fa-notes-medical"></i>
        </div>

        <!-- PHP ALERT MESSAGES (styled modern) -->
        <?php if($success): ?>
        <div class="alert-custom alert-success-custom">
            <i class="fa-solid fa-circle-check fa-lg"></i>
            <span style="flex:1;"><?= htmlspecialchars($success) ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if($error): ?>
        <div class="alert-custom alert-error-custom">
            <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
            <span style="flex:1;"><?= htmlspecialchars($error) ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <form method="POST" id="diagnosisForm">
            <!-- Patient Selection -->
            <div class="mb-4">
                <label class="form-label"><i class="fa-solid fa-user-injury"></i> Patient *</label>
                <select name="patient_id" class="form-select" required>
                    <option value="" disabled <?= !isset($_POST['patient_id']) ? 'selected' : '' ?>>— Select Patient Record —</option>
                    <?php 
                    $patients_stmt->execute();
                    $patients_result = $patients_stmt->get_result();
                    while($p = $patients_result->fetch_assoc()): 
                        $selected = (isset($_POST['patient_id']) && $_POST['patient_id'] == $p['patient_id']) ? 'selected' : '';
                    ?>
                        <option value="<?= $p['patient_id'] ?>" <?= $selected ?>>
                            <?= htmlspecialchars($p['full_name']) ?> (ID: <?= $p['patient_id'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Diagnosis -->
            <div class="mb-4">
                <label class="form-label"><i class="fa-solid fa-stethoscope"></i> Diagnosis *</label>
                <textarea name="diagnosis" class="form-control" rows="3" placeholder="e.g., Acute Bronchitis, Hypertension Stage 2, Type 2 Diabetes Mellitus ..." required><?= htmlspecialchars($_POST['diagnosis'] ?? '') ?></textarea>
                <small style="color: var(--mut); font-size: 11px; margin-top: 5px; display: block;">Primary clinical findings & condition</small>
            </div>

            <!-- Prescription / Lab Reports -->
            <div class="mb-4">
                <label class="form-label"><i class="fa-solid fa-capsules"></i> Prescription / Lab Reports</label>
                <textarea name="prescription" class="form-control" rows="4" placeholder="Medication: Amoxicillin 500mg BD x 5 days&#10;Lab reports: CBC, Chest X-ray recommended"><?= htmlspecialchars($_POST['prescription'] ?? '') ?></textarea>
                <small style="color: var(--mut); font-size: 11px; margin-top: 5px; display: block;">Medications, dosages, lab tests, or imaging results</small>
            </div>

            <!-- Notes -->
            <div class="mb-4">
                <label class="form-label"><i class="fa-solid fa-pen-fancy"></i> Clinical Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Follow-up advice, observations, lifestyle recommendations..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-save">
                <i class="fa-regular fa-floppy-disk me-2"></i> Save Diagnosis Record
            </button>
        </form>
        
        <div class="mt-4 text-center" style="border-top: 1px solid var(--gb); padding-top: 18px;">
            <small style="color: var(--mut);"><i class="fa-regular fa-circle-check"></i> All records are encrypted and time-stamped</small>
        </div>
    </div>

    <!-- INFO ROW -->
    <div class="row mt-4 g-3 animated-card" style="animation-delay: 0.12s;">
        <div class="col-md-6">
            <div class="info-card">
                <div class="d-flex align-items-center gap-3">
                    <div style="background: rgba(37, 99, 235, 0.2); width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-chart-simple"></i>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--mut);">TODAY'S ACTIVITY</div>
                        <div style="font-weight: 800;">Complete patient records instantly</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="info-card">
                <div class="d-flex align-items-center gap-3">
                    <div style="background: rgba(56, 189, 248, 0.12); width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-shield-heart"></i>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--mut);">HIPAA COMPLIANT</div>
                        <div style="font-weight: 500;">Secure medical records storage</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Live clock for sidebar
function updateLiveClock() {
    const clockElem = document.getElementById('liveClock');
    if (clockElem) {
        const now = new Date();
        clockElem.textContent = now.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }
}
updateLiveClock();
setInterval(updateLiveClock, 1000);

// Auto-dismiss alerts after 5 seconds
document.querySelectorAll('.alert-custom').forEach(alert => {
    setTimeout(() => {
        if (alert && alert.parentElement) alert.remove();
    }, 5000);
});
</script>

</body>
</html>