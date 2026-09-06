<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "db.php";

/* ================= AUTH CHECK ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'department_head') {
    header("Location: login.php");
    exit();
}

/* ================= FILTER ================= */
$filter = $_GET['role'] ?? 'all';

/* ================= QUERY ================= */
if ($filter === 'doctor' || $filter === 'nurse') {
    $stmt = $conn->prepare("
        SELECT user_id, full_name, role, status
        FROM users
        WHERE role = ?
        ORDER BY user_id DESC
    ");
    $stmt->bind_param("s", $filter);
} else {
    $stmt = $conn->prepare("
        SELECT user_id, full_name, role, status
        FROM users
        WHERE role IN ('doctor','nurse')
        ORDER BY user_id DESC
    ");
}

$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Management — Zaman Medical</title>

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

/* ─── SIDEBAR (same as medicaldashboard) ────────────────────────────────── */
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

/* Profile card (compact version) */
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

/* ─── MAIN (with left margin for sidebar) ───────────────────────────────── */
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

/* Filter Bar (same glass style) */
.filter-card{
    background:var(--g-bg2);backdrop-filter:blur(20px);
    border:1px solid var(--g-border);border-radius:var(--r-lg);
    padding:18px 24px;margin-bottom:22px;
    display:flex;align-items:center;gap:20px;
    flex-wrap:wrap;
}
.filter-card label{font-size:12px;font-weight:600;color:var(--txt-mid);letter-spacing:.5px;}
.filter-card select{
    background:rgba(9,25,41,.7);border:1px solid var(--g-border);
    padding:8px 28px 8px 14px;border-radius:var(--r-md);color:var(--txt-hi);
    font-family:var(--ff-b);font-size:13px;cursor:pointer;outline:none;
}
.filter-card select:focus{border-color:var(--sky);}
.filter-card .filter-icon{color:var(--sky2);font-size:16px;}

/* Table (enhanced glassmorphic) */
.table-wrap{
    background:var(--g-bg2);backdrop-filter:blur(20px);
    border:1px solid var(--g-border);border-radius:var(--r-lg);
    overflow:hidden;box-shadow:var(--g-shadow);
}
.table-head{
    padding:18px 24px;border-bottom:1px solid var(--g-border);
    display:flex;justify-content:space-between;align-items:center;
    background:rgba(0,0,0,.1);
}
.table-head h3{font-family:var(--ff-d);font-size:15px;font-weight:700;color:var(--txt-hi);}
table{width:100%;border-collapse:collapse;}
th{padding:11px 24px;text-align:left;font-size:10px;font-weight:600;letter-spacing:1.3px;text-transform:uppercase;color:var(--txt-lo);background:rgba(0,0,0,.1);border-bottom:1px solid var(--g-border);}
td{padding:14px 24px;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px;color:var(--txt-mid);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.02);}
td strong{color:var(--txt-hi);font-weight:600;}

/* Badges (matching medicaldashboard) */
.badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:100px;font-size:11px;
    font-weight:700;letter-spacing:.3px;
}
.doctor,.badge-doctor{background:rgba(42,159,214,.12);color:var(--sky2);border:1px solid rgba(42,159,214,.22);}
.nurse,.badge-nurse{background:rgba(14,198,198,.12);color:var(--teal);border:1px solid rgba(14,198,198,.22);}
.active,.badge-active{background:rgba(48,217,136,.12);color:var(--success);border:1px solid rgba(48,217,136,.26);}
.inactive,.badge-inactive{background:rgba(245,158,66,.12);color:var(--warn);border:1px solid rgba(245,158,66,.26);}
.rejected,.badge-rejected{background:rgba(240,92,120,.12);color:var(--danger);border:1px solid rgba(240,92,120,.26);}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(42,159,214,.28);border-radius:99px;}
</style>

</head>
<body>

<!-- ═════ SIDEBAR (identical to medicaldashboard except active state) ═════ -->
<div class="sidebar">
    <div class="logo">
        <div class="logo-icon"><i class="fa-solid fa-hospital-user"></i></div>
        <div class="logo-text">
            Zaman Medical
            <span>Management System</span>
        </div>
    </div>

    <!-- Profile Card (without upload to keep it clean, same style) -->
    <div class="sp-card">
        <div class="av-wrap" style="cursor:default;">
            <?php
            $full_name = $_SESSION['full_name'] ?? 'Department Head';
            $user_id   = $_SESSION['user_id'] ?? 0;
            // fetch profile image for sidebar
            $img_q = $conn->query("SELECT profile_image FROM users WHERE user_id=$user_id");
            $img_row = $img_q ? $img_q->fetch_assoc() : null;
            $img = $img_row['profile_image'] ?? '';
            $imgSrc = $img ? 'uploads/profile/'.$img : 'https://ui-avatars.com/api/?name='.urlencode($full_name).'&background=2A9FD6&color=fff&size=128';
            ?>
            <img src="<?= $imgSrc ?>" alt="Profile">
        </div>
        <div class="sp-name"><?= htmlspecialchars($full_name) ?></div>
        <div class="sp-role"><i class="fa-solid fa-user-tie" style="font-size:9px"></i> Department Head</div>
        <div class="sp-status"><div class="online-dot"></div> Online</div>
    </div>

    <div class="nav">
        <span class="nav-label">Overview</span>
        <a href="medicaldashboard.php"> <!-- adjust if dashboard filename differs -->
            <div class="ni"><i class="fa-solid fa-table-columns"></i></div>
            Dashboard
        </a>
        <span class="nav-label">Staff</span>
        <a class="active" href="medicaldepartment_staff_management.php">
            <div class="ni"><i class="fa-solid fa-users"></i></div>
            Staff Management
        </a>
        <?php
        // get pending count to show badge (same as dashboard)
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
        <a href="medicaldepartment_profile.php">
            <div class="ni"><i class="fa-solid fa-circle-user"></i></div>
            My Profile
        </a>
        <a href="logout.php" class="nav-danger">
            <div class="ni"><i class="fa-solid fa-right-from-bracket"></i></div>
            Logout
        </a>
    </div>
</div>

<!-- ═════ MAIN CONTENT (Staff Management) ═════ -->
<div class="main">
    <!-- Top bar -->
    <div class="topbar">
        <div class="topbar-left">
            <?php
            $hour = date("H");
            $greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
            $firstName = htmlspecialchars(explode(' ', $full_name)[0]);
            ?>
            <h2><?= $greeting ?>, <?= $firstName ?> 👋</h2>
            <small><?= date("l, d F Y") ?> &nbsp;·&nbsp; Manage medical staff</small>
        </div>
        <div class="topbar-right">
            <div>
                <div class="clock-time" id="liveClock"><?= date("h:i:s A") ?></div>
                <div class="clock-label">Live Time</div>
            </div>
            <img src="<?= $imgSrc ?>" class="topbar-av" alt="Profile">
        </div>
    </div>

    <!-- Filter (glass card style) -->
    <div class="filter-card">
        <i class="fa-solid fa-filter filter-icon"></i>
        <label>Filter Staff Role:</label>
        <form method="GET" id="filterForm" style="display:inline;">
            <select name="role" onchange="this.form.submit()">
                <option value="all" <?= $filter=='all'?'selected':'' ?>>All (Doctors + Nurses)</option>
                <option value="doctor" <?= $filter=='doctor'?'selected':'' ?>>Doctors</option>
                <option value="nurse" <?= $filter=='nurse'?'selected':'' ?>>Nurses</option>
            </select>
        </form>
    </div>

    <!-- Staff Table (glass table) -->
    <div class="table-wrap">
        <div class="table-head">
            <h3><i class="fa-solid fa-users" style="margin-right:8px;"></i> Staff Directory</h3>
            <span class="badge badge-doctor" style="background:rgba(42,159,214,.12);">
                <i class="fa-solid fa-user-doctor"></i> <?= ($filter=='all' || $filter=='doctor') ? 'Doctors + Nurses' : ucfirst($filter) ?>
            </span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['user_id']) ?></td>
                    <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                    <td>
                        <span class="badge <?= $row['role'] === 'doctor' ? 'doctor' : 'nurse' ?>">
                            <i class="fa-solid <?= $row['role'] === 'doctor' ? 'fa-user-doctor' : 'fa-user-nurse' ?>" style="font-size:9px;"></i>
                            <?= ucfirst($row['role']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $row['status'] === 'active' ? 'active' : ($row['status'] === 'inactive' ? 'inactive' : 'rejected') ?>">
                            <?= ucfirst($row['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($result->num_rows === 0): ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding:48px 24px;">
                        <div style="font-size:14px; color:var(--txt-mid);">
                            <i class="fa-solid fa-user-slash" style="font-size:28px; margin-bottom:12px; display:block;"></i>
                            No staff members found for this role.
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// live clock (same as dashboard)
function updateClock(){
    const clockEl = document.getElementById("liveClock");
    if(clockEl) clockEl.innerText = new Date().toLocaleTimeString("en-US",{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock,1000);
updateClock();
</script>

</body>
</html>