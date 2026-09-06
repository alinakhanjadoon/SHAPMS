<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------------- AUTH ---------------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

/* ---------------- DB ---------------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("Database Connection Failed: " . $conn->connect_error);

/* ---------------- ADMIN DATA ---------------- */
$user_id = (int)$_SESSION['user_id'];

$admin = $conn->query("SELECT full_name, email, profile_image FROM users WHERE user_id=$user_id")->fetch_assoc();

$name = $admin['full_name'] ?? '';
$email = $admin['email'] ?? '';
$profile = $admin['profile_image'] ?? 'default-avatar.png';

/* ---------------- UPDATE PROFILE ---------------- */
if (isset($_POST['update_profile'])) {
    $new_name = trim($_POST['full_name']);
    $new_email = trim($_POST['email']);

    $stmt = $conn->prepare("UPDATE users SET full_name=?, email=? WHERE user_id=?");
    $stmt->bind_param("ssi", $new_name, $new_email, $user_id);
    $stmt->execute();

    header("Location: adminsettings.php?success=profile");
    exit();
}

/* ---------------- CHANGE PASSWORD ---------------- */
if (isset($_POST['change_password'])) {
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];

    $check = $conn->query("SELECT password FROM users WHERE user_id=$user_id")->fetch_assoc();

    if (password_verify($old, $check['password'])) {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$hashed' WHERE user_id=$user_id");
        header("Location: adminsettings.php?success=password");
        exit();
    } else {
        header("Location: adminsettings.php?error=wrong_password");
        exit();
    }
}

date_default_timezone_set("Asia/Karachi");
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Settings — Zaman Medical</title>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Fraunces:wght@300;400;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
/* Same theme base (trimmed for clarity) */
body {
  margin:0;
  font-family:'Plus Jakarta Sans',sans-serif;
  background:#F5F0F8;
  display:flex;
}

/* SIDEBAR (simplified reuse feel) */
.sidebar {
  width:240px;
  height:100vh;
  background:#fff;
  border-right:1px solid #eee;
  position:fixed;
  padding:20px;
}
.sidebar a {
  display:block;
  padding:10px;
  text-decoration:none;
  color:#444;
  margin-bottom:6px;
  border-radius:8px;
}
.sidebar a:hover {
  background:#f2e6ea;
  color:#C62A2A;
}

/* MAIN */
.main {
  margin-left:240px;
  width:100%;
}

/* TOPBAR */
.topbar {
  height:60px;
  background:#fff;
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:0 20px;
  border-bottom:1px solid #eee;
}

/* CONTENT */
.content {
  padding:30px;
}

.title {
  font-size:28px;
  font-family:'Fraunces',serif;
  margin-bottom:20px;
}

/* CARD */
.card {
  background:#fff;
  padding:20px;
  border-radius:16px;
  margin-bottom:20px;
  box-shadow:0 4px 20px rgba(0,0,0,0.05);
}

input {
  width:100%;
  padding:10px;
  margin-top:8px;
  margin-bottom:15px;
  border:1px solid #ddd;
  border-radius:10px;
}

button {
  padding:10px 18px;
  border:none;
  border-radius:10px;
  background:#C62A2A;
  color:#fff;
  font-weight:600;
  cursor:pointer;
}
button:hover { background:#a61f1f; }

.success {
  color:green;
  margin-bottom:10px;
}
.error {
  color:red;
  margin-bottom:10px;
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">
  <h3>Zaman Admin</h3>
  <a href="admindashboard.php">Dashboard</a>
  <a href="adminreports.php">Reports</a>
  <a href="adminstaffapproval.php">Staff Approval</a>
  <a href="adminsettings.php"><b>Settings</b></a>
  <a href="logout.php">Logout</a>
</div>

<!-- MAIN -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div><?= $greeting ?>, <?= htmlspecialchars(explode(' ', $name)[0]) ?> 👋</div>
    <div><i class="fa-solid fa-gear"></i> Settings</div>
  </div>

  <!-- CONTENT -->
  <div class="content">

    <div class="title">Admin Settings</div>

    <?php if (isset($_GET['success'])): ?>
      <div class="success">✔ Action completed successfully</div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
      <div class="error">✖ Incorrect old password</div>
    <?php endif; ?>

    <!-- PROFILE UPDATE -->
    <div class="card">
      <h3>Update Profile</h3>
      <form method="POST">
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?= htmlspecialchars($name) ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>

        <button type="submit" name="update_profile">Save Changes</button>
      </form>
    </div>

    <!-- PASSWORD CHANGE -->
    <div class="card">
      <h3>Change Password</h3>
      <form method="POST">
        <label>Old Password</label>
        <input type="password" name="old_password" required>

        <label>New Password</label>
        <input type="password" name="new_password" required>

        <button type="submit" name="change_password">Update Password</button>
      </form>
    </div>

    <!-- SYSTEM SETTINGS -->
    <div class="card">
      <h3>System Settings</h3>
      <p style="color:#777;font-size:13px;">
        Future options: hospital name, email SMTP, notifications, theme control.
      </p>
    </div>

  </div>
</div>

</body>
</html>