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

/* ---------- PROCESS PASSWORD CHANGE ---------- */
$success = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required!";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirmation do not match!";
    } else {
        // Fetch current password hash
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result || !password_verify($current_password, $result['password_hash'])) {
            $error = "Current password is incorrect!";
        } else {
            // Update password
            $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hashedPassword, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $success = "Password changed successfully!";
            } else {
                $error = "Failed to update password. Please try again.";
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password | Patient Dashboard</title>
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
    --success: #10b981;
    --error: #ef4444;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text-dark);
    min-height: 100vh;
    position: relative;
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

/* Header */
header {
    position: relative;
    z-index: 2;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    padding: 18px 28px;
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

/* Main Container */
.container {
    position: relative;
    z-index: 2;
    max-width: 500px;
    margin: 60px auto;
    padding: 0 24px;
}

/* Password Card */
.password-card {
    background: white;
    border-radius: 28px;
    border: 1px solid var(--border);
    box-shadow: 0 20px 40px var(--shadow);
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
}

.password-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 25px 50px rgba(124,58,237,0.15);
}

/* Card Header */
.card-header {
    padding: 24px 28px;
    background: linear-gradient(135deg, var(--purple-soft), rgba(167,139,250,0.1));
    border-bottom: 1px solid var(--border);
    text-align: center;
}

.card-header i {
    font-size: 48px;
    color: var(--purple-deep);
    margin-bottom: 12px;
    display: block;
}

.card-header h2 {
    font-family: 'Playfair Display', serif;
    font-size: 24px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}

.card-header p {
    font-size: 13px;
    color: var(--text-mid);
    margin-top: 8px;
}

/* Card Body */
.card-body {
    padding: 28px;
}

/* Form Styles */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    font-size: 12px;
    color: var(--text-mid);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.form-group label i {
    margin-right: 6px;
    color: var(--purple-mid);
}

.input-wrapper {
    position: relative;
}

.input-wrapper i.input-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
    font-size: 14px;
}

.input-wrapper input {
    width: 100%;
    padding: 12px 16px 12px 40px;
    border-radius: 14px;
    border: 1.5px solid var(--border);
    background: var(--bg);
    color: var(--text-dark);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    transition: all 0.2s;
    outline: none;
}

.input-wrapper input:focus {
    border-color: var(--purple-mid);
    box-shadow: 0 0 0 3px rgba(167,139,250,0.15);
}

/* Submit Button */
.btn-submit {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--purple-deep), var(--purple-mid));
    color: white;
    border: none;
    border-radius: 40px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.25s;
    box-shadow: 0 6px 18px rgba(124,58,237,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-family: 'DM Sans', sans-serif;
    margin-top: 10px;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(124,58,237,0.45);
}

/* Alert Messages */
.alert {
    padding: 12px 16px;
    border-radius: 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    font-size: 13px;
}

.alert-success {
    background: rgba(16,185,129,0.1);
    color: var(--success);
    border: 1px solid rgba(16,185,129,0.3);
}

.alert-error {
    background: rgba(239,68,68,0.1);
    color: var(--error);
    border: 1px solid rgba(239,68,68,0.3);
}

/* Security Note */
.security-note {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 11px;
    color: var(--text-light);
}

.security-note i {
    color: var(--purple-mid);
}

/* Responsive */
@media (max-width: 640px) {
    header {
        flex-direction: column;
        text-align: center;
    }
    .container {
        padding: 0 16px;
        margin: 40px auto;
    }
    .card-header {
        padding: 20px;
    }
    .card-body {
        padding: 20px;
    }
}
</style>
</head>
<body>

<header>
    <div class="logo-area">
        <i class="fas fa-heartbeat"></i>
        <span>SHAPMS</span>
    </div>
    <a href="patientdashboard.php">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</header>

<div class="container">
    <div class="password-card">
        <div class="card-header">
            <i class="fas fa-lock"></i>
            <h2>Change Password</h2>
            <p>Keep your account secure with a strong password</p>
        </div>
        <div class="card-body">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-key"></i> Current Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="current_password" placeholder="Enter your current password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-pen-alt"></i> New Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-plus-circle input-icon"></i>
                        <input type="password" name="new_password" placeholder="Enter new password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-check-double"></i> Confirm New Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-check-circle input-icon"></i>
                        <input type="password" name="confirm_password" placeholder="Confirm your new password" required>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update Password
                </button>
            </form>

            <div class="security-note">
                <i class="fas fa-shield-alt"></i>
                <span>Your password is encrypted and securely stored</span>
                <i class="fas fa-database"></i>
            </div>
        </div>
    </div>
</div>

</body>
</html>