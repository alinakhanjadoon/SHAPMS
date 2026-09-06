<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role']!== 'department_head') {
    header("Location: login.php");
    exit();
}

$full_name = $_SESSION['full_name'];

/* ---------- DB ---------- */
$conn = new mysqli('localhost','root','','SHAPMS');
if ($conn->connect_error) { die("DB Error: ". $conn->connect_error); }

/* ---------- PROFILE IMAGE ---------- */
$profilePic = "uploads/default.png";
$userId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!empty($res['profile_image'])) { $profilePic = $res['profile_image']; }
$stmt->close();

/* ---------- ACTIONS ---------- */
if (isset($_POST['approve_id'])) {
    $id = intval($_POST['approve_id']);
    $conn->query("UPDATE users SET status='active' WHERE user_id=$id");
    header("Location: pharmacydepartmentdashboard.php"); exit();
}
if (isset($_POST['reject_id'])) {
    $id = intval($_POST['reject_id']);
    $conn->query("UPDATE users SET status='rejected' WHERE user_id=$id");
    header("Location: pharmacydepartmentdashboard.php"); exit();
}

/* ---------- DATA ---------- */
$pendingResult = $conn->query("
    SELECT user_id, full_name, role
    FROM users
    WHERE role='pharmacist' AND status='inactive' AND department_id=2
");

$totalPharmacists = $conn->query("
    SELECT COUNT(*) FROM users
    WHERE role='pharmacist' AND status='active' AND department_id=2
")->fetch_row()[0];

$pendingCount = $conn->query("
    SELECT COUNT(*) FROM users
    WHERE status='inactive' AND department_id=2
")->fetch_row()[0];

/* Additional stats for charts */
$monthlyJoins = $conn->query("
    SELECT DATE_FORMAT(created_at, '%b') as month, COUNT(*) as count
    FROM users
    WHERE role='pharmacist' AND status='active' AND department_id=2 AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%b')
    ORDER BY MIN(created_at)
");

$monthlyData = [];
while($row = $monthlyJoins->fetch_assoc()) {
    $monthlyData[$row['month']] = $row['count'];
}

$parts = explode(' ', trim($full_name));
$initials = strtoupper(substr($parts[0],0,1). (isset($parts[1])? substr($parts[1],0,1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Dashboard — Zaman Medical</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            /* Refined emerald theme — deep green sidebar, crisp light workspace */
            --bg: #f4faf7;
            --sidebar-bg: linear-gradient(200deg, #06231a 0%, #0a3a29 45%, #0f5138 100%);
            --card-bg: #ffffff;

            --primary: #0a7a4f;
            --primary-2: #065f3d;
            --primary-light: #e6f5ee;
            --primary-grad: linear-gradient(135deg, #10a86b 0%, #0a7a4f 100%);

            --emerald: #0a7a4f;
            --emerald-light: #e6f5ee;
            --emerald-grad: linear-gradient(135deg, #14c48a 0%, #0a7a4f 100%);

            --amber: #d97706;
            --amber-light: #fff4e0;
            --amber-grad: linear-gradient(135deg, #fbbf24 0%, #d97706 100%);

            --rose: #dc2626;
            --rose-light: #fef2f2;

            --text-dark: #142a22;
            --text-muted: #7e9184;
            --border: #e3efe8;
            --shadow: 0 6px 24px rgba(10, 122, 79, 0.07);
            --shadow-hover: 0 16px 40px rgba(10, 122, 79, 0.16);
            --radius: 18px;
            --sidebar-width: 264px;
            --font-head: 'Sora', sans-serif;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            background-image:
                radial-gradient(circle at 100% 0%, rgba(10,122,79,0.06) 0%, transparent 45%),
                radial-gradient(circle at 0% 100%, rgba(217,119,6,0.05) 0%, transparent 45%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

       .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR - deep aurora gradient */
       .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 4px 0 24px rgba(27, 17, 64, 0.18);
        }

       .sidebar-profile {
            padding: 32px 20px 26px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

       .profile-image-container {
            position: relative;
            width: 88px;
            height: 88px;
            margin: 0 auto 16px;
            cursor: pointer;
        }

       .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 24px;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.25);
            background: var(--primary-light);
        }

       .profile-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 24px;
            background: linear-gradient(135deg, #14c48a 0%, #0a7a4f 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            border: 3px solid rgba(255,255,255,0.25);
            font-family: var(--font-head);
        }

       .profile-upload-overlay {
            position: absolute;
            bottom: -8px;
            right: -8px;
            background: var(--amber-grad);
            width: 32px;
            height: 32px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid #0a3a29;
            box-shadow: 0 2px 10px rgba(0,0,0,0.35);
        }

       .profile-upload-overlay:hover {
            transform: scale(1.12) rotate(-4deg);
        }

       .profile-upload-overlay i {
            font-size: 14px;
            color: #fff;
        }

       .sidebar-name {
            font-family: var(--font-head);
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 6px;
        }

       .sidebar-role {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #a7f0d4;
            background: rgba(255,255,255,0.1);
            display: inline-block;
            padding: 5px 14px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.14);
        }

       .sidebar-nav {
            flex: 1;
            padding: 24px 16px;
        }

       .nav-section {
            margin-bottom: 26px;
        }

       .nav-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.6px;
            color: rgba(255,255,255,0.35);
            padding: 8px 12px;
            margin-bottom: 8px;
        }

       .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            margin: 4px 0;
            color: rgba(255,255,255,0.72);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

       .nav-item i {
            width: 18px;
            font-size: 14px;
            text-align: center;
        }

       .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
            padding-left: 18px;
        }

       .nav-item.active {
            background: linear-gradient(135deg, rgba(20,196,138,0.85), rgba(10,122,79,0.85));
            color: #fff;
            font-weight: 600;
            box-shadow: 0 6px 16px rgba(10,122,79,0.35);
        }

       .nav-item.logout {
            margin-top: 20px;
            color: #ff9eb0;
        }

       .nav-item.logout:hover {
            background: rgba(225,29,72,0.15);
            color: #ff9eb0;
        }

        /* MAIN CONTENT */
       .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 32px 36px;
        }

       .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

       .page-header h1 {
            font-family: var(--font-head);
            font-size: 30px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 5px;
            letter-spacing: -0.02em;
        }

       .page-header p {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* Stats Cards */
       .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

       .stat-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

       .stat-card::after {
            content: '';
            position: absolute;
            top: -30px; right: -30px;
            width: 110px; height: 110px;
            border-radius: 50%;
            background: currentColor;
            opacity: 0.06;
        }

       .stat-card.card-violet { color: var(--primary); }
       .stat-card.card-amber { color: var(--amber); }
       .stat-card.card-emerald { color: var(--emerald); }

       .stat-card:first-child {
            background: var(--primary-grad);
            color: #fff;
            border: none;
        }
       .stat-card:first-child::after { background: #fff; opacity: 0.08; }
       .stat-card:first-child.stat-label,
       .stat-card:first-child.stat-sub {
            color: rgba(255,255,255,0.85);
        }
       .stat-card:first-child.stat-icon {
            background: rgba(255,255,255,0.18);
            color: #fff;
        }
       .stat-card:first-child .stat-value { color: #fff; }

       .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

       .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

       .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

       .stat-icon {
            width: 44px;
            height: 44px;
            background: var(--primary-light);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 19px;
        }

        .card-amber .stat-icon { background: var(--amber-light); color: var(--amber); }
        .card-emerald .stat-icon { background: var(--emerald-light); color: var(--emerald); }

       .stat-value {
            font-family: var(--font-head);
            font-size: 40px;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1;
            margin-bottom: 8px;
        }

       .stat-sub {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Charts Section */
       .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

       .chart-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

       .chart-title {
            font-family: var(--font-head);
            font-size: 15px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

       .chart-title i {
            color: var(--primary);
            background: var(--primary-light);
            width: 30px; height: 30px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }

        canvas {
            max-height: 280px;
        }

        /* Table Panel */
       .panel {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

       .panel-header {
            padding: 22px 26px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(90deg, rgba(124,58,237,0.04), transparent);
        }

       .panel-title {
            font-family: var(--font-head);
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
        }

       .pending-badge {
            background: var(--amber-grad);
            color: #fff;
            padding: 7px 16px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 0 6px 16px rgba(217,119,6,0.3);
        }

        /* Table Styles */
       .data-table {
            width: 100%;
            border-collapse: collapse;
        }

       .data-table thead th {
            text-align: left;
            padding: 14px 26px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
            background: #fafaff;
            border-bottom: 1px solid var(--border);
        }

       .data-table tbody td {
            padding: 16px 26px;
            font-size: 13.5px;
            border-bottom: 1px solid var(--border);
            color: var(--text-dark);
        }

       .data-table tbody tr {
            transition: background 0.15s;
       }
       .data-table tbody tr:hover {
            background: #faf9ff;
        }

       .role-badge {
            background: var(--primary-light);
            color: var(--primary);
            padding: 5px 13px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
        }

       .action-buttons {
            display: flex;
            gap: 10px;
        }

       .btn-approve,.btn-reject {
            padding: 7px 17px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

       .btn-approve {
            background: var(--emerald-light);
            color: var(--emerald);
            border-color: #a6ede1;
        }

       .btn-approve:hover {
            background: var(--emerald-grad);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 6px 14px rgba(13,148,136,0.35);
        }

       .btn-reject {
            background: var(--rose-light);
            color: var(--rose);
            border-color: #ffc2cf;
        }

       .btn-reject:hover {
            background: var(--rose);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 6px 14px rgba(225,29,72,0.3);
        }

       .empty-state {
            text-align: center;
            padding: 52px;
            color: var(--text-muted);
        }

       .empty-state i {
            font-size: 46px;
            margin-bottom: 16px;
            color: var(--emerald);
            opacity: 0.6;
        }

        @media (max-width: 1024px) {
           .charts-grid {
                grid-template-columns: 1fr;
            }
           .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
           .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
           .main-content {
                margin-left: 0;
                padding: 20px;
            }
           .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- SIDEBAR with Profile at top -->
    <aside class="sidebar">
        <div class="sidebar-profile">
            <div class="profile-image-container" onclick="document.getElementById('profileInput').click()">
                <?php if ($profilePic!== 'uploads/default.png'):?>
                    <img id="profilePreview" class="profile-image" src="<?= htmlspecialchars($profilePic)?>" alt="Profile">
                <?php else:?>
                    <div id="profilePlaceholder" class="profile-image-placeholder">
                        <?= $initials?>
                    </div>
                    <img id="profilePreview" class="profile-image" src="" style="display:none;">
                <?php endif;?>
                <div class="profile-upload-overlay">
                    <i class="fa-solid fa-camera"></i>
                </div>
            </div>
            <div class="sidebar-name"><?= htmlspecialchars($full_name)?></div>
            <div class="sidebar-role">Department Head</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">MAIN</div>
                <a href="pharmacydepartmentdashboard.php" class="nav-item active">
                    <i class="fa-solid fa-chart-line"></i> Dashboard
                </a>
                <a href="pharmacyDHstaff_management.php" class="nav-item">
                    <i class="fa-solid fa-users"></i> Staff Management
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">PHARMACY</div>
                <a href="pharmacy_inventoryDH.php" class="nav-item">
                    <i class="fa-solid fa-boxes-stacked"></i> Inventory Control
                </a>
                <a href="pharmacy_supplier_purchasingDH.php" class="nav-item">
                    <i class="fa-solid fa-truck"></i> Suppliers
                </a>

                <a href="pharmacy_reportingDH.php" class="nav-item">
                    <i class="fa-solid fa-chart-simple"></i> Reports & KPI
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">ADMIN</div>
                <a href="logout.php" class="nav-item logout">
                    <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                </a>
            </div>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="top-bar">
            <div class="page-header">
                <h1>Dashboard</h1>
                <p>Plan, prioritize, and manage pharmacy operations with ease</p>
            </div>
            <div>
                <input type="file" id="profileInput" style="display:none;" accept="image/*">
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Active Staff</span>
                    <div class="stat-icon"><i class="fa-solid fa-user-md"></i></div>
                </div>
                <div class="stat-value"><?= $totalPharmacists?></div>
                <div class="stat-sub">Verified Pharmacists</div>
            </div>
            <div class="stat-card card-amber">
                <div class="stat-header">
                    <span class="stat-label">Pending Approvals</span>
                    <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                </div>
                <div class="stat-value"><?= $pendingCount?></div>
                <div class="stat-sub">Awaiting Review</div>
            </div>
            <div class="stat-card card-emerald">
                <div class="stat-header">
                    <span class="stat-label">Department</span>
                    <div class="stat-icon"><i class="fa-solid fa-hospital"></i></div>
                </div>
                <div class="stat-value" style="font-size: 26px;">Pharmacy Unit</div>
                <div class="stat-sub">Operational</div>
            </div>
        </div>

        <!-- Real-time Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-title">
                    <i class="fa-solid fa-chart-line"></i> Staff Growth (Last 6 Months)
                </div>
                <canvas id="staffGrowthChart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-title">
                    <i class="fa-solid fa-chart-pie"></i> Department Distribution
                </div>
                <canvas id="distributionChart"></canvas>
            </div>
        </div>

        <!-- Pending Approvals Table -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-user-plus" style="margin-right: 8px; color: var(--primary);"></i>
                    Pending Pharmacist Approvals
                </div>
                <?php if ($pendingResult->num_rows > 0):?>
                    <span class="pending-badge"><?= $pendingResult->num_rows?> Requests</span>
                <?php endif;?>
            </div>

            <?php if ($pendingResult->num_rows > 0):?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $pendingResult->fetch_assoc()):?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['full_name'])?></strong></td>
                        <td><span class="role-badge"><i class="fa-solid fa-pills"></i> <?= ucfirst($row['role'])?></span></td>
                        <td>
                            <div class="action-buttons">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="approve_id" value="<?= $row['user_id']?>">
                                    <button type="submit" class="btn-approve">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                </form>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="reject_id" value="<?= $row['user_id']?>">
                                    <button type="submit" class="btn-reject">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile;?>
                </tbody>
            </table>
            <?php else:?>
                <div class="empty-state">
                    <i class="fa-solid fa-circle-check"></i>
                    <p>All caught up — no pending approvals right now.</p>
                </div>
            <?php endif;?>
        </div>
    </main>
</div>

<script>
// Pass monthly data to JS
const monthlyData = <?= json_encode($monthlyData)?>;

// Staff Growth Chart - smooth line with gradient fill
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const last6Months = [];
const counts = [];

const now = new Date();
for (let i = 5; i >= 0; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const monthName = d.toLocaleString('default', { month: 'short' });
    last6Months.push(monthName);
    counts.push(monthlyData[monthName] || 0);
}

const ctx1 = document.getElementById('staffGrowthChart').getContext('2d');
const gradient = ctx1.createLinearGradient(0, 0, 0, 280);
gradient.addColorStop(0, 'rgba(10, 122, 79, 0.30)');
gradient.addColorStop(1, 'rgba(10, 122, 79, 0.02)');

new Chart(ctx1, {
    type: 'line',
    data: {
        labels: last6Months,
        datasets: [{
            label: 'New Pharmacists',
            data: counts,
            borderColor: '#0a7a4f',
            backgroundColor: gradient,
            borderWidth: 3,
            pointBackgroundColor: '#0a7a4f',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                labels: { color: '#8a8fb3', font: { size: 12, weight: '500' } }
            }
        },
        scales: {
            y: {
                grid: { color: '#f0f0fa', drawBorder: false },
                ticks: { color: '#8a8fb3', font: { size: 11 } },
                beginAtZero: true
            },
            x: {
                grid: { display: false },
                ticks: { color: '#8a8fb3', font: { size: 11 } }
            }
        }
    }
});

// Distribution Chart - doughnut with modern style
const ctx2 = document.getElementById('distributionChart').getContext('2d');
const totalStaff = <?= $totalPharmacists?>;
const pending = <?= $pendingCount?>;

new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: ['Active Pharmacists', 'Pending Approvals'],
        datasets: [{
            data: [totalStaff, pending],
            backgroundColor: ['#0a7a4f', '#f59e0b'],
            borderColor: '#fff',
            borderWidth: 3,
            hoverOffset: 10,
            cutout: '70%'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#8a8fb3',
                    font: { size: 12, weight: '500' },
                    padding: 20
                }
            },
            tooltip: {
                backgroundColor: '#1a1a2e',
                padding: 12,
                cornerRadius: 8,
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.raw;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percent = total > 0? ((value / total) * 100).toFixed(1) : 0;
                        return `${label}: ${value} (${percent}%)`;
                    }
                }
            }
        }
    }
});

// Profile Image Upload
document.getElementById('profileInput').addEventListener('change', function () {
    let file = this.files[0];
    if (!file) return;
    let formData = new FormData();
    formData.append("profile_image", file);
    fetch("upload_profile.php", { method: "POST", body: formData })
       .then(res => res.json())
       .then(data => {
            if (data.success) {
                let preview = document.getElementById("profilePreview");
                let placeholder = document.getElementById("profilePlaceholder");
                preview.src = data.url;
                preview.style.display = "block";
                if (placeholder) placeholder.style.display = "none";
                alert("Profile updated successfully!");
            } else {
                alert(data.message || "Upload failed");
            }
        })
       .catch(() => alert("Upload failed. Please try again."));
});
</script>
</body>
</html>