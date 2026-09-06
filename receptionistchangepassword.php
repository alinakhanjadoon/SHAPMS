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

$user_id = (int)$_SESSION['user_id'];
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error = "All fields are required.";
    } elseif (strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirmation do not match.";
    } else {
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($hash);
        $stmt->fetch();
        $stmt->close();

        if (!$hash || !password_verify($current_password, $hash)) {
            $error = "Current password is incorrect.";
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE user_id=?");
            $stmt->bind_param("si", $new_hash, $user_id);
            $stmt->execute();
            $stmt->close();
            $success = "Password changed successfully.";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password - SHAPMS Reception</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --sky: #0ea5e9;
  --sky-dark: #0284c7;
  --sky-pale: #e0f2fe;
  --bg: #f0f7ff;
  --white: #ffffff;
  --ink: #0c2d3f;
  --ink70: rgba(12,45,63,0.70);
  --ink40: rgba(12,45,63,0.45);
  --border: rgba(14,165,233,0.14);
  --border2: rgba(14,165,233,0.22);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; font-size: 13px; }
.page-wrap { max-width: 520px; margin: 0 auto; padding: 28px 20px 60px; }
.back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--sky-dark); font-weight: 700; font-size: 12.5px; text-decoration: none; margin-bottom: 18px; }
.back-link:hover { opacity: 0.7; }
.page-title { font-size: 22px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
.page-sub { font-size: 12.5px; color: var(--ink40); margin-bottom: 24px; }
.card { background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 26px; margin-bottom: 18px; }
.section-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; margin-bottom: 16px; }
.section-title i { color: var(--sky); }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
label { font-size: 11px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.5px; }
input[type="password"] {
  border: 1px solid var(--border2); border-radius: 10px; padding: 10px 14px; font-size: 13px; color: var(--ink);
  font-family: 'Inter', sans-serif; transition: all 0.2s; background: var(--bg);
}
input:focus { outline: none; border-color: var(--sky); box-shadow: 0 0 0 3px rgba(14,165,233,0.12); background: white; }
.btn-save { background: var(--sky); border: none; color: white; padding: 11px 28px; border-radius: 99px; font-size: 12.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; margin-top: 8px; width: 100%; justify-content: center; }
.btn-save:hover { background: var(--sky-dark); transform: translateY(-1px); }
.alert { border-radius: 12px; padding: 12px 18px; font-size: 12.5px; font-weight: 600; margin-bottom: 18px; }
.alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.hint { font-size: 11px; color: var(--ink40); margin-top: -8px; margin-bottom: 16px; }
</style>
</head>
<body>
<div class="page-wrap">

  <a href="receptionistprofile.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Profile</a>

  <div class="page-title">Change Password</div>
  <div class="page-sub">Update your account password</div>

  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <div class="section-title"><i class="fas fa-lock"></i> Update Password</div>
    <form method="post">
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="current_password" required>
      </div>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" required>
      </div>
      <div class="hint">Minimum 8 characters.</div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required>
      </div>
      <button type="submit" class="btn-save">
        <i class="fas fa-save"></i> Update Password
      </button>
    </form>
  </div>

</div>
</body>
</html>
