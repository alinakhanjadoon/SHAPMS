<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= AUTH ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'department_head') {
    header("Location: login.php");
    exit();
}

/* ================= DB ================= */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];

/* ================= GET USER ================= */
$user = $conn->query("
    SELECT * FROM users WHERE user_id = $user_id
")->fetch_assoc();

/* ================= UPDATE PROFILE INFO ================= */
if (isset($_POST['update_profile'])) {

    $name  = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $contact = $conn->real_escape_string($_POST['contact']);

    $conn->query("
        UPDATE users 
        SET full_name='$name', email='$email', contact='$contact'
        WHERE user_id=$user_id
    ");

    $_SESSION['full_name'] = $name;

    header("Location: medicaldepartment_profile.php");
    exit();
}

/* ================= PASSWORD UPDATE ================= */
if (isset($_POST['update_password'])) {

    $newPass = $_POST['new_password'];

    if (!empty($newPass)) {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);

        $conn->query("
            UPDATE users 
            SET password_hash='$hash'
            WHERE user_id=$user_id
        ");
    }

    header("Location: medicaldepartment_profile.php");
    exit();
}

/* ================= IMAGE UPLOAD ================= */
if (isset($_POST['upload_image'])) {

    if (!empty($_FILES['profile_image']['name'])) {

        $targetDir = "uploads/profile/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = time() . "_" . basename($_FILES["profile_image"]["name"]);
        $targetFile = $targetDir . $fileName;

        $imageType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif'];

        if (in_array($imageType, $allowed)) {

            if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $targetFile)) {

                $conn->query("
                    UPDATE users 
                    SET profile_image='$fileName'
                    WHERE user_id=$user_id
                ");

                header("Location: medicaldepartment_profile.php");
                exit();
            }
        }
    }
}

/* ================= IMAGE ================= */
$img = $user['profile_image'] ?? '';
$imgSrc = $img 
    ? "uploads/profile/" . $img
    : "https://ui-avatars.com/api/?name=" . urlencode($user['full_name']) . "&background=2A9FD6&color=fff&size=128";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Zaman Medical</title>

<!-- FONTS & ICONS -->
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
:root{
    --navy:     #091929;
    --navy2:    #0D2137;
    --navy3:    #112845;
    --sky:      #2A9FD6;
    --sky2:     #4DBCE9;
    --teal:     #0EC6C6;
    --accent:   #36D9B0;
    --warn:     #F59E42;
    --danger:   #F05C78;
    --success:  #30D988;
    --g-bg:     rgba(255,255,255,.055);
    --g-bg2:    rgba(255,255,255,.08);
    --g-border: rgba(255,255,255,.11);
    --g-shadow: 0 8px 32px rgba(0,0,0,.4);
    --txt-hi:   #EEF4FF;
    --txt-mid:  #8AAFC8;
    --txt-lo:   #4E6F88;
    --sw:       278px;
    --r-lg:     20px;
    --r-md:     13px;
    --r-sm:     9px;
    --ff-d:     'Sora', sans-serif;
    --ff-b:     'DM Sans', sans-serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;font-family:var(--ff-b);background:var(--navy);color:var(--txt-hi);overflow-x:hidden;}
body::before{
    content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
    background:
        radial-gradient(ellipse 900px 700px at 8% 15%,  rgba(42,159,214,.16) 0%,transparent 60%),
        radial-gradient(ellipse 600px 600px at 88% 82%, rgba(14,198,198,.12) 0%,transparent 55%),
        radial-gradient(ellipse 500px 400px at 55% 5%,  rgba(54,217,176,.07) 0%,transparent 50%);
}

/* ─── SIDEBAR ─────────────────────────────────────────── */
.sidebar{
    position:fixed;left:0;top:0;width:var(--sw);height:100vh;
    background:rgba(9,25,41,.9);
    backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);
    border-right:1px solid var(--g-border);
    display:flex;flex-direction:column;
    padding:22px 16px;z-index:100;overflow-y:auto;
}
.sidebar::-webkit-scrollbar{width:4px;}
.sidebar::-webkit-scrollbar-thumb{background:rgba(42,159,214,.22);border-radius:4px;}

.logo{display:flex;align-items:center;gap:10px;margin-bottom:22px;padding:0 4px;}
.logo-icon{
    width:38px;height:38px;border-radius:10px;flex-shrink:0;
    background:linear-gradient(135deg,var(--sky),var(--teal));
    display:flex;align-items:center;justify-content:center;font-size:17px;
    box-shadow:0 0 16px rgba(42,159,214,.38);
}
.logo-text{font-family:var(--ff-d);font-size:15px;font-weight:700;color:var(--txt-hi);line-height:1.25;}
.logo-text span{display:block;font-size:10px;font-weight:400;color:var(--txt-mid);letter-spacing:.3px;}

/* Profile card */
.sp-card{
    background:var(--g-bg2);border:1px solid var(--g-border);
    border-radius:var(--r-lg);padding:20px 16px 16px;
    margin-bottom:20px;
    display:flex;flex-direction:column;align-items:center;gap:10px;
    position:relative;overflow:hidden;
}
.sp-card::before{
    content:'';position:absolute;top:-40px;right:-40px;
    width:140px;height:140px;border-radius:50%;
    background:var(--sky);opacity:.07;filter:blur(35px);pointer-events:none;
}
.av-wrap{position:relative;width:78px;height:78px;border-radius:50%;cursor:pointer;flex-shrink:0;}
.av-wrap img{width:78px;height:78px;border-radius:50%;object-fit:cover;display:block;border:2.5px solid rgba(42,159,214,.5);box-shadow:0 0 20px rgba(42,159,214,.28);}
.sp-name{font-family:var(--ff-d);font-size:13px;font-weight:700;color:var(--txt-hi);text-align:center;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sp-role{display:inline-flex;align-items:center;gap:5px;background:rgba(42,159,214,.14);border:1px solid rgba(42,159,214,.26);color:var(--sky2);font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;}
.sp-status{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--txt-mid);}
.online-dot{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 6px var(--success);}

/* Navigation */
.nav{display:flex;flex-direction:column;gap:2px;flex:1;}
.nav-label{font-size:9px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--txt-lo);margin:10px 0 4px 10px;}
.nav a{
    display:flex;align-items:center;gap:10px;text-decoration:none;
    color:var(--txt-mid);padding:10px 12px;border-radius:var(--r-md);
    font-size:13px;font-weight:500;transition:all .18s;position:relative;overflow:hidden;
}
.nav a .ni{
    width:28px;height:28px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;background:var(--g-bg);transition:all .18s;flex-shrink:0;
}
.nav a:hover{color:var(--txt-hi);background:var(--g-bg2);}
.nav a:hover .ni{background:rgba(42,159,214,.18);color:var(--sky2);}
.nav a.active{color:#fff;background:linear-gradient(135deg,rgba(42,159,214,.22),rgba(14,198,198,.13));border:1px solid rgba(42,159,214,.28);}
.nav a.active .ni{background:linear-gradient(135deg,var(--sky),var(--teal));color:#fff;box-shadow:0 0 12px rgba(42,159,214,.4);}
.nav a.active::before{content:'';position:absolute;left:0;top:22%;height:56%;width:3px;border-radius:0 3px 3px 0;background:linear-gradient(180deg,var(--sky),var(--teal));}
.nav a.nav-danger{color:rgba(240,92,120,.75);}
.nav a.nav-danger:hover{color:var(--danger);background:rgba(240,92,120,.08);}
.nav a.nav-danger:hover .ni{background:rgba(240,92,120,.16);color:var(--danger);}
.nav-badge{margin-left:auto;background:var(--warn);color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;}
.nav-divider{height:1px;background:var(--g-border);margin:8px 0;}

/* ─── MAIN ────────────────────────────────────────────── */
.main{margin-left:var(--sw);padding:24px 28px;position:relative;z-index:1;min-height:100vh;}

/* Topbar */
.topbar{
    background:var(--g-bg2);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid var(--g-border);border-radius:var(--r-lg);
    padding:17px 24px;display:flex;justify-content:space-between;align-items:center;
    margin-bottom:22px;box-shadow:var(--g-shadow);
}
.topbar-left h2{font-family:var(--ff-d);font-size:20px;font-weight:700;color:var(--txt-hi);}
.topbar-left small{font-size:12px;color:var(--txt-mid);margin-top:2px;display:block;}
.topbar-right{display:flex;align-items:center;gap:16px;}
.clock-time{font-family:var(--ff-d);font-size:24px;font-weight:700;color:var(--sky2);letter-spacing:1px;}
.clock-label{font-size:9px;color:var(--txt-mid);text-align:right;letter-spacing:.5px;text-transform:uppercase;}
.topbar-av{width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(42,159,214,.42);box-shadow:0 0 14px rgba(42,159,214,.3);}

/* Profile Container (Glass Card) */
.profile-container{
    max-width:750px;
    margin:0 auto;
    background:var(--g-bg2);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid var(--g-border);
    border-radius:var(--r-lg);
    overflow:hidden;
    box-shadow:var(--g-shadow);
}

.profile-header{
    background:linear-gradient(135deg,rgba(42,159,214,.15),rgba(14,198,198,.08));
    padding:30px;
    text-align:center;
    border-bottom:1px solid var(--g-border);
}
.profile-header h2{
    font-family:var(--ff-d);
    font-size:24px;
    font-weight:700;
    color:var(--txt-hi);
    margin-bottom:5px;
}
.profile-header p{
    font-size:13px;
    color:var(--txt-mid);
}

.profile-image-section{
    text-align:center;
    padding:25px 30px 15px;
    border-bottom:1px solid var(--g-border);
}
.profile-img{
    width:130px;
    height:130px;
    border-radius:50%;
    object-fit:cover;
    border:3px solid var(--sky);
    box-shadow:0 0 25px rgba(42,159,214,.3);
    margin-bottom:15px;
}
.image-upload-form{
    display:flex;
    justify-content:center;
    gap:12px;
    flex-wrap:wrap;
    margin-top:8px;
}
.file-input{
    background:rgba(9,25,41,.7);
    border:1px solid var(--g-border);
    padding:8px 12px;
    border-radius:var(--r-sm);
    color:var(--txt-hi);
    font-family:var(--ff-b);
    font-size:12px;
    cursor:pointer;
}
.file-input::-webkit-file-upload-button{
    background:rgba(42,159,214,.2);
    border:none;
    padding:4px 12px;
    border-radius:var(--r-sm);
    color:var(--sky2);
    font-weight:600;
    cursor:pointer;
}
.btn-upload{
    background:linear-gradient(135deg,rgba(42,159,214,.2),rgba(14,198,198,.14));
    border:1px solid rgba(42,159,214,.28);
    color:var(--sky2);
    padding:8px 20px;
    border-radius:var(--r-sm);
    font-size:12px;
    font-weight:600;
    font-family:var(--ff-b);
    cursor:pointer;
    transition:all .18s;
}
.btn-upload:hover{
    background:linear-gradient(135deg,rgba(42,159,214,.33),rgba(14,198,198,.24));
    box-shadow:0 0 12px rgba(42,159,214,.22);
    transform:translateY(-1px);
}

/* Form Sections */
.form-section{
    padding:25px 30px;
    border-bottom:1px solid var(--g-border);
}
.form-section:last-child{
    border-bottom:none;
}
.section-title{
    font-family:var(--ff-d);
    font-size:16px;
    font-weight:600;
    color:var(--txt-hi);
    margin-bottom:18px;
    display:flex;
    align-items:center;
    gap:8px;
}
.section-title i{
    color:var(--sky);
    font-size:18px;
}
.form-group{
    margin-bottom:18px;
}
.form-group label{
    display:block;
    font-size:11px;
    font-weight:600;
    letter-spacing:1px;
    text-transform:uppercase;
    color:var(--txt-mid);
    margin-bottom:6px;
}
.form-group input{
    width:100%;
    background:rgba(9,25,41,.7);
    border:1px solid var(--g-border);
    padding:12px 16px;
    border-radius:var(--r-sm);
    font-family:var(--ff-b);
    font-size:14px;
    color:var(--txt-hi);
    transition:all .18s;
}
.form-group input:focus{
    outline:none;
    border-color:var(--sky);
    box-shadow:0 0 0 2px rgba(42,159,214,.2);
}
.form-group input::placeholder{
    color:var(--txt-lo);
}
.btn-primary{
    background:linear-gradient(135deg,var(--sky),var(--teal));
    border:none;
    color:#fff;
    padding:10px 24px;
    border-radius:var(--r-sm);
    font-size:13px;
    font-weight:600;
    font-family:var(--ff-b);
    cursor:pointer;
    transition:all .18s;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.btn-primary:hover{
    transform:translateY(-1px);
    box-shadow:0 5px 15px rgba(42,159,214,.3);
}
.btn-secondary{
    background:rgba(42,159,214,.15);
    border:1px solid rgba(42,159,214,.3);
    color:var(--sky2);
    padding:10px 24px;
    border-radius:var(--r-sm);
    font-size:13px;
    font-weight:600;
    font-family:var(--ff-b);
    cursor:pointer;
    transition:all .18s;
}
.btn-secondary:hover{
    background:rgba(42,159,214,.25);
    transform:translateY(-1px);
}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(42,159,214,.28);border-radius:99px;}
</style>

</head>
<body>

<!-- ═════ SIDEBAR ═════ -->
<div class="sidebar">
    <div class="logo">
        <div class="logo-icon"><i class="fa-solid fa-hospital-user"></i></div>
        <div class="logo-text">
            Zaman Medical
            <span>Management System</span>
        </div>
    </div>

    <!-- Profile Card -->
    <div class="sp-card">
        <div class="av-wrap" style="cursor:default;">
            <img src="<?= $imgSrc ?>" alt="Profile">
        </div>
        <div class="sp-name"><?= htmlspecialchars($user['full_name'] ?? 'Department Head') ?></div>
        <div class="sp-role"><i class="fa-solid fa-user-tie" style="font-size:9px"></i> Department Head</div>
        <div class="sp-status"><div class="online-dot"></div> Online</div>
    </div>

    <div class="nav">
        <span class="nav-label">Overview</span>
        <a href="medicaldashboard.php">
            <div class="ni"><i class="fa-solid fa-table-columns"></i></div>
            Dashboard
        </a>
        <span class="nav-label">Staff</span>
        <a href="medicaldepartment_staff_management.php">
            <div class="ni"><i class="fa-solid fa-users"></i></div>
            Staff Management
        </a>
        <?php
        $pendingCount = $conn->query("SELECT COUNT(*) FROM users WHERE status='inactive' AND department_id=1")->fetch_row()[0] ?? 0;
        ?>
        <a href="medicaldepartment_pending_approvals.php">
            <div class="ni"><i class="fa-solid fa-hourglass-half"></i></div>
            Pending Approvals
            <?php if($pendingCount > 0): ?>
                <span class="nav-badge"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <span class="nav-label">Operations</span>
        <a href="medicaldepartment_appointments.php">
            <div class="ni"><i class="fa-solid fa-calendar-check"></i></div>
            Appointments
        </a>
        <a href="medicaldepartment_reports.php">
            <div class="ni"><i class="fa-solid fa-chart-line"></i></div>
            Reports &amp; Analytics
        </a>
        <a href="medicaldepartment_departments.php">
            <div class="ni"><i class="fa-solid fa-building-columns"></i></div>
            Departments
        </a>
        <div class="nav-divider"></div>
        <span class="nav-label">Account</span>
        <a class="active" href="medicaldepartment_profile.php">
            <div class="ni"><i class="fa-solid fa-circle-user"></i></div>
            My Profile
        </a>
        <a href="logout.php" class="nav-danger">
            <div class="ni"><i class="fa-solid fa-right-from-bracket"></i></div>
            Logout
        </a>
    </div>
</div>

<!-- ═════ MAIN CONTENT ═════ -->
<div class="main">
    <!-- Top bar -->
    <div class="topbar">
        <div class="topbar-left">
            <?php
            $hour = date("H");
            $greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
            $firstName = htmlspecialchars(explode(' ', $user['full_name'] ?? 'Department Head')[0]);
            ?>
            <h2><?= $greeting ?>, <?= $firstName ?> 👋</h2>
            <small><?= date("l, d F Y") ?> &nbsp;·&nbsp; Manage your account settings</small>
        </div>
        <div class="topbar-right">
            <div>
                <div class="clock-time" id="liveClock"><?= date("h:i:s A") ?></div>
                <div class="clock-label">Live Time</div>
            </div>
            <img src="<?= $imgSrc ?>" class="topbar-av" alt="Profile">
        </div>
    </div>

    <!-- Profile Container -->
    <div class="profile-container">
        
        <div class="profile-header">
            <h2>My Profile</h2>
            <p>Manage your personal information and account settings</p>
        </div>

        <!-- Image Upload Section -->
        <div class="profile-image-section">
            <img src="<?= $imgSrc ?>" class="profile-img" alt="Profile Image">
            <form method="POST" enctype="multipart/form-data" class="image-upload-form">
                <input type="file" name="profile_image" class="file-input" accept="image/*" required>
                <button type="submit" name="upload_image" class="btn-upload">
                    <i class="fa-solid fa-upload"></i> Upload New Image
                </button>
            </form>
        </div>

        <!-- Profile Update Section -->
        <div class="form-section">
            <div class="section-title">
                <i class="fa-solid fa-user-pen"></i>
                Personal Information
            </div>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fa-regular fa-user"></i> Full Name</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Enter your full name">
                </div>
                <div class="form-group">
                    <label><i class="fa-regular fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="Enter your email">
                </div>
                <div class="form-group">
                    <label><i class="fa-regular fa-phone"></i> Contact Number</label>
                    <input type="text" name="contact" value="<?= htmlspecialchars($user['contact'] ?? '') ?>" placeholder="Enter your contact number">
                </div>
                <button type="submit" name="update_profile" class="btn-primary">
                    <i class="fa-solid fa-save"></i> Update Profile
                </button>
            </form>
        </div>

        <!-- Password Update Section -->
        <div class="form-section">
            <div class="section-title">
                <i class="fa-solid fa-lock"></i>
                Change Password
            </div>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fa-solid fa-key"></i> New Password</label>
                    <input type="password" name="new_password" placeholder="Enter new password">
                </div>
                <button type="submit" name="update_password" class="btn-secondary">
                    <i class="fa-solid fa-rotate-right"></i> Change Password
                </button>
            </form>
        </div>

    </div>
</div>

<script>
// Live clock
function updateClock(){
    const clockEl = document.getElementById("liveClock");
    if(clockEl) clockEl.innerText = new Date().toLocaleTimeString("en-US",{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock,1000);
updateClock();
</script>

</body>
</html>