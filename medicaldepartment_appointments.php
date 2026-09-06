<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= AUTH ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'department_head') {
    header("Location: login.php");
    exit();
}

/* ================= DB CONNECTION ================= */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ================= FILTER ================= */
$statusFilter = $_GET['status'] ?? 'all';
$where = "1=1";
if ($statusFilter !== 'all') {
    $statusFilter = $conn->real_escape_string($statusFilter);
    $where .= " AND a.status='$statusFilter'";
}

/* ================= DATA ================= */
$result = $conn->query("
    SELECT 
        a.appointment_id,
        a.patient_id,
        a.doctor_id,
        a.appointment_date,
        a.status,
        a.reason,
        pu.full_name AS patient_name,
        du.full_name AS doctor_name
    FROM appointments a
    LEFT JOIN patients pt ON a.patient_id = pt.patient_id
    LEFT JOIN users pu    ON pt.user_id   = pu.user_id
    LEFT JOIN doctors doc ON a.doctor_id  = doc.doctor_id
    LEFT JOIN users du    ON doc.user_id  = du.user_id
    WHERE $where
    ORDER BY a.appointment_date DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments — Zaman Medical</title>

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

/* Filter Bar (glass style) */
.filter-card{
    background:var(--g-bg2);backdrop-filter:blur(20px);
    border:1px solid var(--g-border);border-radius:var(--r-lg);
    padding:18px 24px;margin-bottom:22px;
    display:flex;align-items:center;gap:20px;
    flex-wrap:wrap;
}
.filter-card .filter-label{font-size:12px;font-weight:600;color:var(--txt-mid);letter-spacing:.5px;}
.filter-buttons{display:flex;flex-wrap:wrap;gap:8px;}
.filter-btn{
    padding:6px 16px;border-radius:100px;text-decoration:none;
    font-size:12px;font-weight:600;font-family:var(--ff-b);
    background:rgba(9,25,41,.6);border:1px solid var(--g-border);
    color:var(--txt-mid);transition:all .18s;
}
.filter-btn:hover{background:rgba(42,159,214,.16);color:var(--sky2);border-color:rgba(42,159,214,.3);}
.filter-btn.active{background:linear-gradient(135deg,rgba(42,159,214,.22),rgba(14,198,198,.13));color:var(--sky2);border-color:rgba(42,159,214,.4);}

/* Table (glassmorphic) */
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

/* Status Badges */
.badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 12px;border-radius:100px;font-size:11px;
    font-weight:700;letter-spacing:.3px;
}
.scheduled{background:rgba(245,158,66,.12);color:var(--warn);border:1px solid rgba(245,158,66,.26);}
.approved{background:rgba(48,217,136,.12);color:var(--success);border:1px solid rgba(48,217,136,.26);}
.completed{background:rgba(42,159,214,.12);color:var(--sky2);border:1px solid rgba(42,159,214,.26);}
.cancelled{background:rgba(240,92,120,.12);color:var(--danger);border:1px solid rgba(240,92,120,.26);}

/* Empty state */
.empty{
    padding:52px 40px;text-align:center;color:var(--txt-lo);
}
.empty-icon{
    width:58px;height:58px;border-radius:50%;background:rgba(42,159,214,.1);
    border:1px solid rgba(42,159,214,.24);display:flex;align-items:center;
    justify-content:center;font-size:24px;color:var(--sky);margin:0 auto 13px;
}
.empty h3{font-family:var(--ff-d);font-size:16px;font-weight:700;color:var(--txt-mid);margin-bottom:5px;}
.empty p{font-size:12px;}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(42,159,214,.28);border-radius:99px;}
</style>

</head>
<body>

<!-- ═════ SIDEBAR (identical to medicaldashboard) ═════ -->
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
            <?php
            $full_name = $_SESSION['full_name'] ?? 'Department Head';
            $user_id   = $_SESSION['user_id'] ?? 0;
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
        <a class="active" href="medicaldepartment_appointments.php">
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

<!-- ═════ MAIN CONTENT ═════ -->
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
            <small><?= date("l, d F Y") ?> &nbsp;·&nbsp; Manage patient appointments</small>
        </div>
        <div class="topbar-right">
            <div>
                <div class="clock-time" id="liveClock"><?= date("h:i:s A") ?></div>
                <div class="clock-label">Live Time</div>
            </div>
            <img src="<?= $imgSrc ?>" class="topbar-av" alt="Profile">
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-card">
        <span class="filter-label"><i class="fa-solid fa-filter"></i> Filter by Status:</span>
        <div class="filter-buttons">
            <a href="?status=all"       class="filter-btn <?= $statusFilter == 'all'       ? 'active' : '' ?>">All</a>
            <a href="?status=scheduled" class="filter-btn <?= $statusFilter == 'scheduled' ? 'active' : '' ?>">Scheduled</a>
            <a href="?status=approved"  class="filter-btn <?= $statusFilter == 'approved'  ? 'active' : '' ?>">Approved</a>
            <a href="?status=completed" class="filter-btn <?= $statusFilter == 'completed' ? 'active' : '' ?>">Completed</a>
            <a href="?status=cancelled" class="filter-btn <?= $statusFilter == 'cancelled' ? 'active' : '' ?>">Cancelled</a>
        </div>
    </div>

    <!-- Appointments Table -->
    <div class="table-wrap">
        <div class="table-head">
            <h3><i class="fa-solid fa-calendar-check" style="margin-right:8px;"></i> Appointment Records</h3>
            <?php
            $count = $result ? $result->num_rows : 0;
            $statusLabel = $statusFilter == 'all' ? 'Total' : ucfirst($statusFilter);
            ?>
            <span class="badge <?= $statusFilter == 'all' ? 'approved' : htmlspecialchars($statusFilter) ?>" style="background:rgba(42,159,214,.12);">
                <i class="fa-solid fa-list"></i> <?= $count ?> <?= $statusLabel ?>
            </span>
        </div>

        <?php if ($result && $result->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Date & Time</th>
                    <th>Status</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                        $patient = !empty($row['patient_name']) ? htmlspecialchars($row['patient_name']) : "Patient #" . $row['patient_id'];
                        $doctor  = !empty($row['doctor_name'])  ? htmlspecialchars($row['doctor_name'])  : "Doctor #"  . $row['doctor_id'];
                        $reason  = htmlspecialchars($row['reason'] ?? '—');
                    ?>
                    <tr>
                        <td><strong>#<?= $row['appointment_id'] ?></strong></td>
                        <td><?= $patient ?></td>
                        <td><?= $doctor ?></td>
                        <td><?= date('d M Y, h:i A', strtotime($row['appointment_date'])) ?></td>
                        <td>
                            <span class="badge <?= htmlspecialchars($row['status']) ?>">
                                <i class="fa-solid <?= $row['status'] == 'scheduled' ? 'fa-clock' : ($row['status'] == 'approved' ? 'fa-check-circle' : ($row['status'] == 'completed' ? 'fa-circle-check' : 'fa-ban')) ?>"></i>
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                        <td style="max-width:200px; white-space:normal;"><?= $reason ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                <h3>No Appointments Found</h3>
                <p><?= $statusFilter == 'all' ? 'No appointment records available.' : 'No ' . ucfirst($statusFilter) . ' appointments found.' ?></p>
            </div>
        <?php endif; ?>
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