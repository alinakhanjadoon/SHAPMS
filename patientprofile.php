<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

/* ---------- FETCH PATIENT DATA ---------- */
$stmt = $conn->prepare("
    SELECT full_name, username, email, age, gender, contact, address
    FROM users
    WHERE user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ---------- UPDATE PROFILE ---------- */
$success = $error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($contact) || empty($address) || empty($age) || empty($gender)) {
        $error = "All fields except password are required!";
    } else {
        // 1️⃣ Update users table
        if (!empty($password)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET contact=?, address=?, age=?, gender=?, password_hash=? WHERE user_id=?");
            $stmt->bind_param("ssissi", $contact, $address, $age, $gender, $hashedPassword, $_SESSION['user_id']);
        } else {
            $stmt = $conn->prepare("UPDATE users SET contact=?, address=?, age=?, gender=? WHERE user_id=?");
            $stmt->bind_param("ssisi", $contact, $address, $age, $gender, $_SESSION['user_id']);
        }

        if ($stmt->execute()) {
            $stmt->close();

            // 2️⃣ Update patients table
            $stmt2 = $conn->prepare("UPDATE patients SET age=?, gender=?, contact=?, address=? WHERE user_id=?");
            $stmt2->bind_param("isssi", $age, $gender, $contact, $address, $_SESSION['user_id']);
            $stmt2->execute();
            $stmt2->close();

            $success = "Profile updated successfully!";

            // Refresh patient data
            $stmt = $conn->prepare("SELECT full_name, username, email, age, gender, contact, address FROM users WHERE user_id=?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $patient = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $error = "Failed to update profile. Please try again.";
        }
    }
}

/* ---------- DERIVED DISPLAY DATA ---------- */
$fullName = trim($patient['full_name'] ?? '');
$nameParts = $fullName !== '' ? preg_split('/\s+/', $fullName) : [];
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') $initials = 'P';

// Simple profile-completeness meter for the four editable fields
$editableFields = [$patient['age'] ?? '', $patient['gender'] ?? '', $patient['contact'] ?? '', $patient['address'] ?? ''];
$filledCount = count(array_filter($editableFields, fn($v) => trim((string)$v) !== ''));
$completeness = (int) round(($filledCount / max(count($editableFields), 1)) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile | Patient Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --bg: #f3f0fb;
    --bg2: #ece8f8;
    --purple-deep: #7c3aed;
    --purple-mid: #a78bfa;
    --purple-light: #ddd6fe;
    --purple-soft: #ede9fe;
    --lilac: #c4b5fd;
    --accent-pink: #f472b6;
    --text-dark: #1e1b3a;
    --text-mid: #5b5278;
    --text-light: #9c8fc0;
    --border: #e8e2f8;
    --shadow: rgba(124,58,237,0.10);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text-dark);
    min-height: 100vh;
    position: relative;
    overflow-x: hidden;
}

/* Animated Gradient Background */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: 
        radial-gradient(circle at 20% 30%, rgba(167,139,250,0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(244,114,182,0.12) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(124,58,237,0.08) 0%, transparent 60%);
    z-index: 0;
    pointer-events: none;
}

/* Floating ambient blobs for extra depth */
.blob { position: fixed; border-radius: 50%; filter: blur(76px); opacity: 0.5; z-index: 0; pointer-events: none; }
.blob-a { width: 380px; height: 380px; top: -140px; right: -100px; background: var(--purple-light); animation: floatA 24s ease-in-out infinite; }
.blob-b { width: 300px; height: 300px; bottom: -120px; left: -80px; background: #fde2f0; animation: floatB 28s ease-in-out infinite; }
@keyframes floatA { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(30px,40px) scale(1.08); } }
@keyframes floatB { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-30px,-25px) scale(1.1); } }

/* Entrance choreography */
@keyframes riseIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
.reveal { opacity: 0; animation: riseIn 0.6s cubic-bezier(.22,1,.36,1) forwards; }
.reveal-d1 { animation-delay: .05s; }
.reveal-d2 { animation-delay: .15s; }
.reveal-d3 { animation-delay: .25s; }

/* HEADER */
header {
    position: relative;
    z-index: 2;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    padding: 18px 28px;
    text-align: center;
    font-size: 1.3rem;
    font-weight: 600;
    border-bottom: 1px solid var(--border);
    box-shadow: 0 4px 20px var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

header .logo-area {
    display: flex;
    align-items: center;
    gap: 10px;
}

header .logo-area i {
    font-size: 28px;
    color: var(--purple-deep);
    animation: heartbeatIcon 1.8s ease-in-out infinite;
}
@keyframes heartbeatIcon {
    0%,100% { transform: scale(1); }
    15% { transform: scale(1.2); }
    30% { transform: scale(0.96); }
    45% { transform: scale(1.12); }
    60% { transform: scale(1); }
}

header .logo-area span {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--text-dark);
}

header a {
    background: linear-gradient(135deg, var(--purple-deep), var(--accent-pink));
    color: white;
    padding: 8px 20px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.25s;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 10px rgba(124,58,237,0.3);
}

header a:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(124,58,237,0.4);
}

/* MAIN CONTAINER */
.container {
    position: relative;
    z-index: 2;
    max-width: 760px;
    margin: 50px auto;
    padding: 38px 42px 42px;
    background: white;
    border-radius: 28px;
    border: 1px solid var(--border);
    box-shadow: 0 20px 40px var(--shadow);
    transition: transform 0.25s, box-shadow 0.25s;
}

.container:hover {
    transform: translateY(-3px);
    box-shadow: 0 25px 50px rgba(124,58,237,0.15);
}

/* ---------- PROFILE HERO ---------- */
.profile-hero {
    display: flex;
    align-items: center;
    gap: 22px;
    padding-bottom: 26px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border);
}

.avatar-ring {
    position: relative;
    width: 84px;
    height: 84px;
    flex-shrink: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.avatar-ring::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: conic-gradient(from 0deg, var(--purple-deep), var(--accent-pink), var(--lilac), var(--purple-deep));
    animation: spinRing 7s linear infinite;
    opacity: 0.85;
}
@keyframes spinRing { to { transform: rotate(360deg); } }

.avatar-circle {
    position: relative;
    z-index: 1;
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Playfair Display', serif;
    font-size: 26px;
    font-weight: 600;
    border: 3px solid white;
    box-shadow: 0 4px 14px rgba(124,58,237,0.35);
}

.hero-info { flex: 1; min-width: 0; }

.hero-info h2 {
    font-family: 'Playfair Display', serif;
    font-size: 22px;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 6px;
    text-align: left;
    display: block;
}

.role-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--purple-soft);
    color: var(--purple-deep);
    padding: 3px 12px;
    border-radius: 99px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
    margin-bottom: 10px;
}

.hero-meta { display: flex; flex-wrap: wrap; gap: 16px; }
.hero-meta span {
    font-size: 12.5px;
    color: var(--text-mid);
    display: flex;
    align-items: center;
    gap: 6px;
}
.hero-meta i { color: var(--purple-mid); font-size: 12px; }

/* Completeness meter */
.completeness-wrap { margin-top: 12px; max-width: 220px; }
.completeness-track {
    width: 100%;
    height: 6px;
    border-radius: 99px;
    background: var(--purple-soft);
    overflow: hidden;
}
.completeness-fill {
    height: 100%;
    border-radius: 99px;
    background: linear-gradient(90deg, var(--purple-deep), var(--accent-pink));
    transition: width 0.6s cubic-bezier(.22,1,.36,1);
}
.completeness-label {
    font-size: 10.5px;
    color: var(--text-light);
    font-weight: 600;
    margin-top: 5px;
    display: block;
}

/* TITLE (kept for section framing) */
.section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-light);
    margin: 26px 0 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ---------- READ-ONLY ACCOUNT INFO CHIPS ---------- */
.readonly-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 12px;
    margin-bottom: 6px;
}
.readonly-chip {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.readonly-chip .rc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.readonly-chip .rc-label i { color: var(--purple-mid); font-size: 10px; }
.readonly-chip .rc-value {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-dark);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* FORM STYLES */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 18px;
    margin-top: 8px;
}
.field-full { grid-column: 1 / -1; }

form label {
    display: block;
    margin-top: 18px;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-mid);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

form label i {
    margin-right: 6px;
    color: var(--purple-mid);
}

form input,
form textarea,
form select {
    width: 100%;
    padding: 12px 16px;
    margin-top: 6px;
    border-radius: 14px;
    border: 1.5px solid var(--border);
    background: var(--bg);
    color: var(--text-dark);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    transition: all 0.2s;
    outline: none;
}

form input:focus,
form textarea:focus,
form select:focus {
    border-color: var(--purple-mid);
    box-shadow: 0 0 0 3px rgba(167,139,250,0.15);
    background: white;
}

form input:disabled {
    background: rgba(167,139,250,0.08);
    color: var(--text-light);
    cursor: not-allowed;
}

form textarea {
    resize: vertical;
    min-height: 80px;
}

/* SELECT DROPDOWN */
select {
    cursor: pointer;
    appearance: none;
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%235b5278" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    background-repeat: no-repeat;
    background-position: right 16px center;
}

/* BUTTON */
.btn-update {
    grid-column: 1 / -1;
    margin-top: 28px;
    padding: 14px 24px;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    background-size: 200% 200%;
    border: none;
    border-radius: 40px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 700;
    font-size: 15px;
    color: white;
    cursor: pointer;
    transition: all 0.25s;
    box-shadow: 0 6px 18px rgba(124,58,237,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
}

.btn-update:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(124,58,237,0.45);
    background-position: 100% 50%;
}
.btn-update:active { transform: translateY(0) scale(0.98); }

/* SUCCESS / ERROR MESSAGES */
.success {
    background: linear-gradient(135deg, #10b98120, #05966920);
    color: #059669;
    text-align: center;
    font-weight: 600;
    padding: 12px;
    border-radius: 16px;
    margin-bottom: 20px;
    border: 1px solid #10b98140;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.error {
    background: linear-gradient(135deg, #ef444420, #dc262620);
    color: #dc2626;
    text-align: center;
    font-weight: 600;
    padding: 12px;
    border-radius: 16px;
    margin-bottom: 20px;
    border: 1px solid #ef444440;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* Info Banner */
.info-banner {
    background: var(--purple-soft);
    border-radius: 16px;
    padding: 14px 18px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-left: 4px solid var(--purple-deep);
}

.info-banner i {
    font-size: 20px;
    color: var(--purple-deep);
}

.info-banner p {
    font-size: 13px;
    color: var(--text-mid);
    margin: 0;
}

/* Responsive */
@media (max-width: 640px) {
    .container {
        margin: 30px 20px;
        padding: 25px 20px;
    }
    .profile-hero { flex-direction: column; text-align: center; }
    .hero-info h2 { text-align: center; }
    .hero-meta { justify-content: center; }
    .readonly-grid { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    header {
        flex-direction: column;
        text-align: center;
    }
}
</style>
</head>
<body>

<div class="blob blob-a"></div>
<div class="blob blob-b"></div>

<header>
    <div class="logo-area">
        <i class="fas fa-heartbeat"></i>
        <span>SHAPMS</span>
    </div>
    <a href="patientdashboard.php">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</header>

<div class="container reveal">

    <!-- Hero -->
    <div class="profile-hero reveal reveal-d1">
        <div class="avatar-ring">
            <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        </div>
        <div class="hero-info">
            <h2><?= htmlspecialchars($fullName ?: 'Patient') ?></h2>
            <span class="role-pill"><i class="fas fa-id-badge"></i> Patient Account</span>
            <div class="hero-meta">
                <span><i class="fas fa-at"></i> <?= htmlspecialchars($patient['username'] ?? '—') ?></span>
                <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($patient['email'] ?? '—') ?></span>
            </div>
            <div class="completeness-wrap">
                <div class="completeness-track">
                    <div class="completeness-fill" style="width: <?= $completeness ?>%;"></div>
                </div>
                <span class="completeness-label"><?= $completeness ?>% of your profile details are filled in</span>
            </div>
        </div>
    </div>

    <div class="info-banner reveal reveal-d2">
        <i class="fas fa-info-circle"></i>
        <p>Your personal information is kept secure and confidential. You can update your contact details, address, and password below.</p>
    </div>

    <?php if($success): ?>
        <div class="success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="section-title reveal reveal-d2"><i class="fas fa-lock" style="color:var(--purple-mid)"></i> Account Info</div>
    <div class="readonly-grid reveal reveal-d2">
        <div class="readonly-chip">
            <span class="rc-label"><i class="fas fa-user"></i> Full Name</span>
            <span class="rc-value"><?= htmlspecialchars($fullName ?: '—') ?></span>
        </div>
        <div class="readonly-chip">
            <span class="rc-label"><i class="fas fa-at"></i> Username</span>
            <span class="rc-value"><?= htmlspecialchars($patient['username'] ?? '—') ?></span>
        </div>
        <div class="readonly-chip">
            <span class="rc-label"><i class="fas fa-envelope"></i> Email</span>
            <span class="rc-value"><?= htmlspecialchars($patient['email'] ?? '—') ?></span>
        </div>
    </div>

    <div class="section-title reveal reveal-d3"><i class="fas fa-pen" style="color:var(--purple-mid)"></i> Editable Details</div>
    <form method="POST" class="reveal reveal-d3">
        <div class="form-grid">
            <div>
                <label><i class="fas fa-calendar-alt"></i> Age</label>
                <input type="number" name="age" value="<?= htmlspecialchars($patient['age'] ?? '') ?>" placeholder="Enter your age">
            </div>

            <div>
                <label><i class="fas fa-venus-mars"></i> Gender</label>
                <select name="gender">
                    <option value="male" <?= ($patient['gender'] == 'male') ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= ($patient['gender'] == 'female') ? 'selected' : '' ?>>Female</option>
                    <option value="other" <?= ($patient['gender'] == 'other') ? 'selected' : '' ?>>Other</option>
                </select>
            </div>

            <div>
                <label><i class="fas fa-phone-alt"></i> Contact Number</label>
                <input type="text" name="contact" value="<?= htmlspecialchars($patient['contact'] ?? '') ?>" placeholder="e.g. 0300-1234567">
            </div>

            <div>
                <label><i class="fas fa-lock"></i> New Password</label>
                <input type="password" name="password" placeholder="Leave blank to keep current password">
            </div>

            <div class="field-full">
                <label><i class="fas fa-map-marker-alt"></i> Address</label>
                <textarea name="address" placeholder="Your complete address"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-update"><i class="fas fa-save"></i> Update Profile</button>
        </div>
    </form>
</div>

</body>
</html>