<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$user_id = (int)$_SESSION['user_id'];

/* ---------- GET PATIENT ID ---------- */
$res = $conn->prepare("SELECT patient_id FROM patients WHERE user_id=?");
$res->bind_param("i", $user_id);
$res->execute();
$res->bind_result($patient_id);
$res->fetch();
$res->close();

/* ---------- MARK ALL READ ---------- */
if (isset($_GET['mark_all'])) {
    $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND role='patient'")->execute();
    // bind properly
    $m = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND role='patient'");
    $m->bind_param("i", $user_id);
    $m->execute();
    $m->close();
    header("Location: patientnotification.php?msg=read");
    exit();
}

/* ---------- MARK SINGLE READ ---------- */
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    $m = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    $m->bind_param("ii", $nid, $user_id);
    $m->execute();
    $m->close();
    header("Location: patientnotification.php");
    exit();
}

/* ---------- FETCH NOTIFICATIONS ---------- */
$filter = $_GET['filter'] ?? 'all';
$valid_filters = ['all', 'unread', 'appointment', 'leave'];
if (!in_array($filter, $valid_filters)) $filter = 'all';

$where = "WHERE user_id=? AND role='patient'";
if ($filter === 'unread')      $where .= " AND is_read=0";
if ($filter === 'appointment') $where .= " AND type='appointment'";
if ($filter === 'leave')       $where .= " AND type='leave'";

$stmt = $conn->prepare("SELECT id, type, title, message, link, is_read, created_at FROM notifications $where ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- COUNTS ---------- */
$count_stmt = $conn->prepare("SELECT 
    COUNT(*) AS total,
    SUM(is_read=0) AS unread,
    SUM(type='appointment') AS appt_count,
    SUM(type='leave') AS leave_count
    FROM notifications WHERE user_id=? AND role='patient'");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$counts = $count_stmt->get_result()->fetch_assoc();
$count_stmt->close();

/* ---------- UPCOMING APPOINTMENTS WITH DOCTOR LEAVE WARNING ---------- */
$upcoming = $conn->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.status,
        u.full_name AS doctor_name,
        d.doctor_id,
        dl.leave_date,
        dl.status AS leave_status
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN users u ON d.user_id = u.user_id
    LEFT JOIN doctor_leaves dl 
        ON dl.doctor_id = a.doctor_id 
        AND dl.leave_date = DATE(a.appointment_date)
        AND dl.status = 'approved'
    WHERE a.patient_id = ?
    AND a.status IN ('scheduled','approved')
    AND a.appointment_date >= NOW()
    ORDER BY a.appointment_date ASC
");
$upcoming->bind_param("i", $patient_id);
$upcoming->execute();
$upcoming_appts = $upcoming->get_result()->fetch_all(MYSQLI_ASSOC);
$upcoming->close();

/* ---------- FETCH PATIENT NAME ---------- */
$un = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
$un->bind_param("i", $user_id);
$un->execute();
$un->bind_result($patient_name);
$un->fetch();
$un->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Notifications — SHAPMS</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg: #f3f0fb;
    --purple: #7c3aed;
    --purple-soft: #ede9fe;
    --purple-mid: #a78bfa;
    --purple-light: #ddd6fe;
    --accent-pink: #f472b6;
    --text-dark: #1e1b3a;
    --text-mid: #5b5278;
    --text-light: #9c8fc0;
    --border: #e8e2f8;
    --white: #ffffff;
    --shadow: rgba(124,58,237,0.10);
    --green: #10b981;
    --yellow: #f59e0b;
    --red: #ef4444;
    --blue: #3b82f6;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text-dark);
    min-height: 100vh;
}

body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(circle at 20% 30%, rgba(167,139,250,0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(244,114,182,0.12) 0%, transparent 50%);
    z-index: 0; pointer-events: none;
}

header {
    position: relative; z-index: 2;
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(12px);
    padding: 16px 28px;
    border-bottom: 1px solid var(--border);
    box-shadow: 0 4px 20px var(--shadow);
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
}

.logo { display: flex; align-items: center; gap: 10px; }
.logo i { font-size: 24px; color: var(--purple); }
.logo span { font-size: 18px; font-weight: 700; color: var(--text-dark); }

.header-right { display: flex; align-items: center; gap: 12px; }

.back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; border-radius: 40px;
    background: linear-gradient(135deg, var(--purple), var(--accent-pink));
    color: white; text-decoration: none; font-size: 13px; font-weight: 600;
    box-shadow: 0 2px 10px rgba(124,58,237,0.3);
    transition: all 0.2s;
}
.back-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(124,58,237,0.4); }

.wrap {
    position: relative; z-index: 2;
    max-width: 900px; margin: 28px auto; padding: 0 20px 60px;
}

/* PAGE TITLE */
.page-title {
    display: flex; align-items: center; gap: 14px; margin-bottom: 24px;
}
.pt-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, var(--purple), var(--purple-mid));
    display: grid; place-items: center; font-size: 22px; color: white;
    box-shadow: 0 4px 16px rgba(124,58,237,0.3);
}
.pt-title { font-size: 22px; font-weight: 700; color: var(--text-dark); }
.pt-sub { font-size: 13px; color: var(--text-light); margin-top: 2px; }

/* STATS */
.stats-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
    margin-bottom: 20px;
}
.stat-card {
    background: var(--white); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: 0 2px 10px var(--shadow);
    transition: transform 0.2s;
}
.stat-card:hover { transform: translateY(-2px); }
.stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: grid; place-items: center; font-size: 16px; flex-shrink: 0;
}
.stat-val { font-size: 24px; font-weight: 700; color: var(--text-dark); line-height: 1; }
.stat-lbl { font-size: 11px; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 3px; }

/* WARNING BANNER */
.warning-banner {
    background: #fffbeb; border: 1px solid #fcd34d;
    border-left: 4px solid var(--yellow);
    border-radius: 12px; padding: 16px 18px;
    margin-bottom: 20px;
}
.wb-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 14px; font-weight: 700; color: #92400e; margin-bottom: 12px;
}
.wb-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 10px 12px; background: white;
    border: 1px solid #fde68a; border-radius: 8px;
    margin-bottom: 8px;
}
.wb-item:last-child { margin-bottom: 0; }
.wb-icon {
    width: 32px; height: 32px; border-radius: 8px;
    background: #fef3c7; display: grid; place-items: center;
    font-size: 14px; color: var(--yellow); flex-shrink: 0;
}
.wb-doc { font-size: 13px; font-weight: 600; color: #92400e; }
.wb-date { font-size: 12px; color: #b45309; margin-top: 2px; }
.wb-rebbook {
    margin-left: auto; padding: 5px 12px;
    background: var(--purple); color: white;
    border-radius: 20px; font-size: 11px; font-weight: 600;
    text-decoration: none; white-space: nowrap; align-self: center;
    transition: background 0.2s;
}
.wb-rebbook:hover { background: #5b21b6; }

/* FILTER TABS */
.filter-row {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 16px; flex-wrap: wrap;
}
.filter-tab {
    padding: 6px 16px; border-radius: 20px;
    font-size: 13px; font-weight: 500;
    text-decoration: none; border: 1px solid var(--border);
    color: var(--text-mid); background: var(--white);
    transition: all 0.15s; display: flex; align-items: center; gap: 6px;
}
.filter-tab:hover { border-color: var(--purple); color: var(--purple); }
.filter-tab.active { background: var(--purple); color: white; border-color: var(--purple); }
.filter-tab .fc {
    font-size: 11px; font-weight: 700;
    background: rgba(255,255,255,0.25); padding: 1px 6px; border-radius: 10px;
}
.filter-tab:not(.active) .fc { background: var(--purple-soft); color: var(--purple); }

.mark-all-btn {
    margin-left: auto; padding: 6px 16px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
    background: var(--purple-soft); color: var(--purple);
    border: 1px solid var(--purple-light); text-decoration: none;
    transition: all 0.15s;
}
.mark-all-btn:hover { background: var(--purple); color: white; }

/* NOTIFICATION LIST */
.notif-list { display: flex; flex-direction: column; gap: 10px; }

.notif-item {
    background: var(--white); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px 18px;
    display: flex; align-items: flex-start; gap: 14px;
    box-shadow: 0 2px 8px var(--shadow);
    transition: all 0.2s; text-decoration: none; color: inherit;
    position: relative;
}
.notif-item:hover { transform: translateY(-1px); box-shadow: 0 6px 20px var(--shadow); }
.notif-item.unread { border-left: 4px solid var(--purple); background: #faf8ff; }

.unread-dot {
    position: absolute; top: 16px; right: 16px;
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--purple);
}

.ni-icon {
    width: 42px; height: 42px; border-radius: 12px;
    display: grid; place-items: center; font-size: 18px; flex-shrink: 0;
}
.ni-icon.appointment { background: #ede9fe; color: var(--purple); }
.ni-icon.leave       { background: #fef3c7; color: var(--yellow); }
.ni-icon.cancelled   { background: #fee2e2; color: var(--red); }
.ni-icon.completed   { background: #d1fae5; color: var(--green); }
.ni-icon.shift       { background: #dbeafe; color: var(--blue); }
.ni-icon.default     { background: var(--purple-soft); color: var(--purple); }

.ni-content { flex: 1; }
.ni-title { font-size: 14px; font-weight: 600; color: var(--text-dark); margin-bottom: 4px; }
.ni-msg   { font-size: 13px; color: var(--text-mid); line-height: 1.5; }
.ni-time  { font-size: 11px; color: var(--text-light); margin-top: 6px; display: flex; align-items: center; gap: 4px; }

.empty-state {
    text-align: center; padding: 60px 20px;
    background: var(--white); border: 1px solid var(--border);
    border-radius: 14px;
}
.empty-state i { font-size: 48px; color: var(--purple-light); margin-bottom: 14px; display: block; }
.empty-state strong { display: block; font-size: 16px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
.empty-state p { font-size: 13px; color: var(--text-light); }

@media (max-width: 680px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    header { flex-wrap: wrap; }
}
</style>
</head>
<body>

<header>
    <div class="logo">
        <i class="fas fa-heartbeat"></i>
        <span>SHAPMS</span>
    </div>
    <div class="header-right">
        <script>window.NOTIF_API_PATH = 'notifications_api.php';</script>
        <?php include __DIR__ . '/includes/notification_widget.php'; ?>
        <a href="patientdashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>
</header>

<div class="wrap">

    <!-- PAGE TITLE -->
    <div class="page-title">
        <div class="pt-icon"><i class="fas fa-bell"></i></div>
        <div>
            <div class="pt-title">My Notifications</div>
            <div class="pt-sub">Welcome, <?= htmlspecialchars($patient_name) ?> — stay updated on your appointments</div>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-bell"></i></div>
            <div>
                <div class="stat-val"><?= (int)($counts['total'] ?? 0) ?></div>
                <div class="stat-lbl">Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2;color:#ef4444;"><i class="fas fa-circle-exclamation"></i></div>
            <div>
                <div class="stat-val"><?= (int)($counts['unread'] ?? 0) ?></div>
                <div class="stat-lbl">Unread</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-calendar-check"></i></div>
            <div>
                <div class="stat-val"><?= (int)($counts['appt_count'] ?? 0) ?></div>
                <div class="stat-lbl">Appointment</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-calendar-xmark"></i></div>
            <div>
                <div class="stat-val"><?= (int)($counts['leave_count'] ?? 0) ?></div>
                <div class="stat-lbl">Leave Alerts</div>
            </div>
        </div>
    </div>

    <!-- UPCOMING APPOINTMENTS WITH LEAVE WARNING -->
    <?php
    $warned = array_filter($upcoming_appts, fn($a) => !empty($a['leave_date']));
    if (!empty($warned)):
    ?>
    <div class="warning-banner">
        <div class="wb-title">
            <i class="fas fa-triangle-exclamation"></i>
            Doctor Not Available — Action Required
        </div>
        <?php foreach ($warned as $appt): ?>
        <div class="wb-item">
            <div class="wb-icon"><i class="fas fa-user-doctor"></i></div>
            <div>
                <div class="wb-doc">Dr. <?= htmlspecialchars($appt['doctor_name']) ?> is on approved leave</div>
                <div class="wb-date">
                    Your appointment on
                    <strong><?= date("d M Y", strtotime($appt['appointment_date'])) ?></strong>
                    at <strong><?= date("h:i A", strtotime($appt['appointment_date'])) ?></strong>
                    may be affected. Please rebook with another doctor.
                </div>
            </div>
            <a href="bookappointment.php" class="wb-rebbook">
                <i class="fas fa-calendar-plus"></i> Rebook
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- FILTER TABS -->
    <div class="filter-row">
        <a href="?filter=all"         class="filter-tab <?= $filter==='all'?'active':'' ?>">
            <i class="fas fa-list"></i> All <span class="fc"><?= (int)($counts['total'] ?? 0) ?></span>
        </a>
        <a href="?filter=unread"      class="filter-tab <?= $filter==='unread'?'active':'' ?>">
            <i class="fas fa-circle-dot"></i> Unread <span class="fc"><?= (int)($counts['unread'] ?? 0) ?></span>
        </a>
        <a href="?filter=appointment" class="filter-tab <?= $filter==='appointment'?'active':'' ?>">
            <i class="fas fa-calendar"></i> Appointments <span class="fc"><?= (int)($counts['appt_count'] ?? 0) ?></span>
        </a>
        <a href="?filter=leave"       class="filter-tab <?= $filter==='leave'?'active':'' ?>">
            <i class="fas fa-calendar-xmark"></i> Leave Alerts <span class="fc"><?= (int)($counts['leave_count'] ?? 0) ?></span>
        </a>
        <?php if ((int)($counts['unread'] ?? 0) > 0): ?>
        <a href="?mark_all=1" class="mark-all-btn">
            <i class="fas fa-check-double"></i> Mark all read
        </a>
        <?php endif; ?>
    </div>

    <!-- NOTIFICATION LIST -->
    <div class="notif-list">
        <?php if (empty($notifications)): ?>
        <div class="empty-state">
            <i class="fas fa-bell-slash"></i>
            <strong>No notifications yet</strong>
            <p>You're all caught up! Notifications about your appointments will appear here.</p>
        </div>
        <?php else: ?>
            <?php foreach ($notifications as $n):
                // Pick icon class based on title/type
                $icon_class = 'default';
                $icon = 'fa-bell';
                if ($n['type'] === 'appointment') {
                    if (stripos($n['title'], 'cancel') !== false || stripos($n['title'], 'leave') !== false) {
                        $icon_class = 'cancelled'; $icon = 'fa-calendar-xmark';
                    } elseif (stripos($n['title'], 'complet') !== false) {
                        $icon_class = 'completed'; $icon = 'fa-circle-check';
                    } else {
                        $icon_class = 'appointment'; $icon = 'fa-calendar-check';
                    }
                } elseif ($n['type'] === 'leave') {
                    $icon_class = 'leave'; $icon = 'fa-calendar-xmark';
                } elseif ($n['type'] === 'shift') {
                    $icon_class = 'shift'; $icon = 'fa-clock';
                }

                // Time ago
                $diff = time() - strtotime($n['created_at']);
                if ($diff < 60) $time_ago = 'just now';
                elseif ($diff < 3600) $time_ago = floor($diff/60) . 'm ago';
                elseif ($diff < 86400) $time_ago = floor($diff/3600) . 'h ago';
                else $time_ago = date("d M Y", strtotime($n['created_at']));
            ?>
            <a href="?read=<?= $n['id'] ?><?= $n['link'] ? '&goto='.urlencode($n['link']) : '' ?>"
               class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
               onclick="<?= $n['link'] ? "setTimeout(()=>window.location='".htmlspecialchars($n['link'])."',100)" : '' ?>">
                <?php if (!$n['is_read']): ?>
                <div class="unread-dot"></div>
                <?php endif; ?>
                <div class="ni-icon <?= $icon_class ?>">
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <div class="ni-content">
                    <div class="ni-title"><?= htmlspecialchars($n['title']) ?></div>
                    <div class="ni-msg"><?= htmlspecialchars($n['message']) ?></div>
                    <div class="ni-time">
                        <i class="fas fa-clock" style="font-size:9px"></i>
                        <?= $time_ago ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>