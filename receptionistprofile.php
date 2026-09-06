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

/* ---------- UPDATE PROFILE INFO ---------- */
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);

    if ($full_name === '' || $email === '') {
        $error = "Name and email are required.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, contact=? WHERE user_id=?");
        $stmt->bind_param("sssi", $full_name, $email, $phone, $user_id);
        $stmt->execute();
        $stmt->close();
        $success = "Profile updated successfully.";
    }
}

/* ---------- FETCH CURRENT USER ---------- */
$stmt = $conn->prepare("SELECT full_name, email, contact, profile_image, created_at FROM users WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($full_name, $email, $phone, $profile_image, $created_at);
$stmt->fetch();
$stmt->close();

$profilePic = !empty($profile_image) ? $profile_image : "uploads/default.png";
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile - SHAPMS Reception</title>
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
.page-wrap { max-width: 880px; margin: 0 auto; padding: 28px 20px 60px; }
.back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--sky-dark); font-weight: 700; font-size: 12.5px; text-decoration: none; margin-bottom: 18px; }
.back-link:hover { opacity: 0.7; }
.page-title { font-size: 22px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
.page-sub { font-size: 12.5px; color: var(--ink40); margin-bottom: 24px; }
.card { background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 26px; margin-bottom: 18px; }
.profile-head { display: flex; align-items: center; gap: 20px; margin-bottom: 8px; }
.photo-wrap { position: relative; width: 88px; height: 88px; flex-shrink: 0; }
.photo-wrap img { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; border: 3px solid var(--sky-pale); }
.photo-edit-btn { position: absolute; bottom: -2px; right: -2px; width: 28px; height: 28px; background: var(--sky); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; cursor: pointer; border: 2px solid white; }
.profile-head-text .name { font-size: 18px; font-weight: 700; color: var(--ink); }
.profile-head-text .role { font-size: 11.5px; font-weight: 600; color: var(--sky-dark); margin-top: 2px; }
.profile-head-text .joined { font-size: 11px; color: var(--ink40); margin-top: 4px; }
.section-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; margin-bottom: 16px; }
.section-title i { color: var(--sky); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1 / -1; }
label { font-size: 11px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.5px; }
input[type="text"], input[type="email"], input[type="tel"] {
  border: 1px solid var(--border2); border-radius: 10px; padding: 10px 14px; font-size: 13px; color: var(--ink);
  font-family: 'Inter', sans-serif; transition: all 0.2s; background: var(--bg);
}
input:focus { outline: none; border-color: var(--sky); box-shadow: 0 0 0 3px rgba(14,165,233,0.12); background: white; }
.btn-save { background: var(--sky); border: none; color: white; padding: 11px 28px; border-radius: 99px; font-size: 12.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; margin-top: 8px; }
.btn-save:hover { background: var(--sky-dark); transform: translateY(-1px); }
.alert { border-radius: 12px; padding: 12px 18px; font-size: 12.5px; font-weight: 600; margin-bottom: 18px; }
.alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
</style>
</head>
<body>
<div class="page-wrap">

  <a href="receptionistdasboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

  <div class="page-title">My Profile</div>
  <div class="page-sub">View and update your account details</div>

  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <div class="profile-head">
      <div class="photo-wrap">
        <img id="profilePreview" src="<?= htmlspecialchars($profilePic) ?>" alt="Profile">
        <div class="photo-edit-btn" onclick="document.getElementById('profileInput').click()">
          <i class="fas fa-camera"></i>
        </div>
        <input type="file" id="profileInput" style="display:none;" accept="image/*">
      </div>
      <div class="profile-head-text">
        <div class="name"><?= htmlspecialchars($full_name) ?></div>
        <div class="role">Receptionist</div>
        <div class="joined">Joined <?= date('F Y', strtotime($created_at)) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="section-title"><i class="fas fa-id-card"></i> Account Information</div>
    <form method="post">
      <div class="form-grid">
        <div class="form-group full">
          <label>Full Name</label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($full_name) ?>" required>
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" name="phone" value="<?= htmlspecialchars($phone ?? '') ?>">
        </div>
      </div>
      <button type="submit" name="update_profile" class="btn-save">
        <i class="fas fa-save"></i> Save Changes
      </button>
    </form>
  </div>

  <div class="card">
    <div class="section-title"><i class="fas fa-shield-halved"></i> Security</div>
    <p style="font-size:12.5px;color:var(--ink70);margin-bottom:14px;">Need to change your password?</p>
    <a href="receptionistchangepassword.php" class="btn-save" style="text-decoration:none;">
      <i class="fas fa-lock"></i> Change Password
    </a>
  </div>

</div>

<script>
document.getElementById('profileInput').addEventListener('change', function() {
  let file = this.files[0];
  if (!file) return;
  let formData = new FormData();
  formData.append("profile_image", file);
  fetch("upload_profile.php", {
    method: "POST",
    body: formData,
    credentials: "same-origin"
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      document.getElementById("profilePreview").src = data.url + "?t=" + new Date().getTime();
      alert("Profile photo updated successfully!");
    } else {
      alert(data.message || "Upload failed");
    }
  })
  .catch(() => alert("Upload error. Please try again."));
});
</script>

</body>
</html>