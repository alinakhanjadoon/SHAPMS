<?php
session_start();
include('db.php');

// Only Department Head access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'department_head') {
    die("Access denied. Department Head only.");
}

// Get user profile data for sidebar
$full_name = $_SESSION['full_name'];
$userId = $_SESSION['user_id'];
$profilePic = "uploads/default.png";
$stmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!empty($res['profile_image'])) { $profilePic = $res['profile_image']; }
$stmt->close();

$parts = explode(' ', trim($full_name));
$initials = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));

// Date filters
$today = date('Y-m-d');
$currentMonth = date('Y-m');
$last30Days = date('Y-m-d', strtotime('-30 days'));

// -------------------------
// KPI CALCULATIONS (Real-time from DB)
// -------------------------
$totalMedicines = $conn->query("SELECT COUNT(*) AS c FROM medicines")->fetch_assoc()['c'];
$totalStock = $conn->query("SELECT SUM(quantity) AS s FROM medicines")->fetch_assoc()['s'];
$totalStockValue = $conn->query("SELECT SUM(price * quantity) AS value FROM medicines")->fetch_assoc()['value'];

// EXPIRY REPORT (Real expired medicines)
$expired = $conn->query("
    SELECT * FROM medicines
    WHERE expiry_date < '$today'
    ORDER BY expiry_date ASC
")->fetch_all(MYSQLI_ASSOC);
$expiredCount = count($expired);

// EXPIRING SOON (Next 30 days)
$expiringSoon = $conn->query("
    SELECT * FROM medicines
    WHERE expiry_date BETWEEN '$today' AND DATE_ADD('$today', INTERVAL 30 DAY)
    ORDER BY expiry_date ASC
")->fetch_all(MYSQLI_ASSOC);
$expiringSoonCount = count($expiringSoon);

// LOW STOCK REPORT (quantity <= min_stock)
$lowStock = $conn->query("
    SELECT * FROM medicines
    WHERE quantity <= min_stock AND quantity > 0
    ORDER BY (quantity / min_stock) ASC
")->fetch_all(MYSQLI_ASSOC);
$lowStockCount = count($lowStock);

// CRITICAL STOCK (quantity = 0)
$criticalStock = $conn->query("
    SELECT * FROM medicines
    WHERE quantity = 0
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);
$criticalCount = count($criticalStock);

// REVENUE REPORT (from purchase orders - approved and delivered)
$revenue = $conn->query("
    SELECT COALESCE(SUM(price * quantity), 0) AS total_revenue
    FROM purchase_orders
    WHERE status IN ('approved', 'delivered')
")->fetch_assoc();

// MONTHLY REVENUE (Last 6 months for chart)
$monthlyRevenue = $conn->query("
    SELECT DATE_FORMAT(created_at, '%b') as month, 
           COALESCE(SUM(price * quantity), 0) as revenue
    FROM purchase_orders
    WHERE status IN ('approved', 'delivered') 
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%b')
    ORDER BY MIN(created_at)
");

$revenueMonths = [];
$revenueValues = [];
while($row = $monthlyRevenue->fetch_assoc()) {
    $revenueMonths[] = $row['month'];
    $revenueValues[] = $row['revenue'];
}

// CONTROLLED DRUG AUDIT
$controlled = $conn->query("
    SELECT * FROM medicines
    WHERE controlled_drug = 1
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);
$controlledCount = count($controlled);

// DAILY ACTIVITY (Last 7 days prescription activity)
$dailyActivity = $conn->query("
    SELECT DATE(created_at) as date, COUNT(*) as count
    FROM prescriptions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");

$activityDates = [];
$activityCounts = [];
while($row = $dailyActivity->fetch_assoc()) {
    $activityDates[] = date('M d', strtotime($row['date']));
    $activityCounts[] = $row['count'];
}

// TOP MEDICINES BY USAGE
$topMedicines = $conn->query("
    SELECT name, quantity, price
    FROM medicines
    ORDER BY quantity DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// PENDING PURCHASE ORDERS
$pendingOrders = $conn->query("
    SELECT COUNT(*) as count FROM purchase_orders WHERE status = 'pending'
")->fetch_assoc()['count'];

// TODAY'S PRESCRIPTIONS
$todayPrescriptions = $conn->query("
    SELECT COUNT(*) as count FROM prescriptions WHERE DATE(created_at) = '$today'
")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Reports & KPI — Department Head</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-primary: #0a1f14;
            --bg-secondary: #0d2818;
            --bg-tertiary: #123a22;
            --bg-card: #0e2a1a;
            --bg-card-hover: #143a24;
            --accent-primary: #2ecc71;
            --accent-secondary: #27ae60;
            --accent-glow: rgba(46, 204, 113, 0.15);
            --accent-gradient: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            --text-primary: #ffffff;
            --text-secondary: #b0d4c0;
            --text-muted: #7aa88a;
            --border-light: rgba(46, 204, 113, 0.15);
            --border-medium: rgba(46, 204, 113, 0.25);
            --danger: #e74c3c;
            --danger-dim: rgba(231, 76, 60, 0.1);
            --warning: #f39c12;
            --warning-dim: rgba(243, 156, 18, 0.1);
            --success: #2ecc71;
            --success-dim: rgba(46, 204, 113, 0.1);
            --info: #3498db;
            --info-dim: rgba(52, 152, 219, 0.1);
            --sidebar-width: 260px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            min-height: 100vh;
            color: var(--text-secondary);
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-secondary); }
        ::-webkit-scrollbar-thumb { background: var(--accent-secondary); border-radius: 10px; }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar-profile {
            padding: 28px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-light);
            background: linear-gradient(135deg, rgba(46, 204, 113, 0.05) 0%, rgba(39, 174, 96, 0.02) 100%);
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
            border-radius: 20px;
            object-fit: cover;
            border: 3px solid var(--accent-primary);
            box-shadow: 0 0 20px rgba(46, 204, 113, 0.3);
            background: var(--bg-tertiary);
        }

        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 20px;
            background: var(--accent-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: var(--bg-primary);
            border: 3px solid var(--accent-primary);
        }

        .profile-upload-overlay {
            position: absolute;
            bottom: -8px;
            right: -8px;
            background: var(--accent-primary);
            width: 32px;
            height: 32px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid var(--bg-secondary);
        }

        .profile-upload-overlay:hover {
            transform: scale(1.1);
            background: var(--accent-secondary);
        }

        .profile-upload-overlay i {
            font-size: 14px;
            color: var(--bg-primary);
        }

        .sidebar-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .sidebar-role {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--accent-primary);
            background: rgba(46, 204, 113, 0.1);
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 16px;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            padding: 8px 12px;
            margin-bottom: 8px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            margin: 4px 0;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
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
            background: rgba(46, 204, 113, 0.1);
            color: var(--accent-primary);
        }

        .nav-item.active {
            background: rgba(46, 204, 113, 0.15);
            color: var(--accent-primary);
            border: 1px solid var(--border-medium);
        }

        .nav-item.logout {
            margin-top: 20px;
            color: var(--danger);
        }

        .nav-item.logout:hover {
            background: var(--danger-dim);
            color: var(--danger);
        }

        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 24px 32px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-light);
        }

        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .page-header p {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 20px;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--accent-primary);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-gradient);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .stat-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            background: var(--success-dim);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-primary);
            font-size: 20px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .chart-card {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 20px;
            border: 1px solid var(--border-light);
        }

        .chart-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-title i {
            color: var(--accent-primary);
        }

        canvas {
            max-height: 260px;
        }

        /* Report Cards */
        .report-card {
            background: var(--bg-card);
            border-radius: 20px;
            border: 1px solid var(--border-light);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .report-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-header h3 i {
            color: var(--accent-primary);
            font-size: 18px;
        }

        .badge-count {
            background: var(--bg-tertiary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead th {
            text-align: left;
            padding: 14px 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            background: rgba(46, 204, 113, 0.05);
            border-bottom: 1px solid var(--border-light);
        }

        .data-table tbody td {
            padding: 12px 20px;
            font-size: 13px;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-secondary);
        }

        .data-table tbody tr:hover {
            background: rgba(46, 204, 113, 0.05);
        }

        .status-danger {
            background: var(--danger-dim);
            color: var(--danger);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .status-warning {
            background: var(--warning-dim);
            color: var(--warning);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            .main-content {
                margin-left: 0;
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-profile">
            <div class="profile-image-container" onclick="document.getElementById('profileInput').click()">
                <?php if ($profilePic !== 'uploads/default.png'): ?>
                    <img id="profilePreview" class="profile-image" src="<?= htmlspecialchars($profilePic) ?>" alt="Profile">
                <?php else: ?>
                    <div id="profilePlaceholder" class="profile-image-placeholder">
                        <?= $initials ?>
                    </div>
                    <img id="profilePreview" class="profile-image" src="" style="display:none;">
                <?php endif; ?>
                <div class="profile-upload-overlay">
                    <i class="fa-solid fa-camera"></i>
                </div>
            </div>
            <div class="sidebar-name"><?= htmlspecialchars($full_name) ?></div>
            <div class="sidebar-role">Department Head</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">MAIN</div>
                <a href="pharmacydepartmentdashboard.php" class="nav-item">
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
                
                <a href="pharmacy_reportingDH.php" class="nav-item active">
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
                <h1>Reports & KPI Dashboard</h1>
                <p>Real-time analytics, performance metrics, and inventory insights</p>
            </div>
            <div>
                <input type="file" id="profileInput" style="display:none;" accept="image/*">
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Total Medicines</span>
                    <div class="stat-icon"><i class="fa-solid fa-capsules"></i></div>
                </div>
                <div class="stat-value"><?= number_format($totalMedicines) ?></div>
                <div class="stat-sub">Unique formulations</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Total Stock</span>
                    <div class="stat-icon"><i class="fa-solid fa-boxes"></i></div>
                </div>
                <div class="stat-value"><?= number_format($totalStock) ?></div>
                <div class="stat-sub">Units in inventory</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Inventory Value</span>
                    <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
                </div>
                <div class="stat-value">$<?= number_format($totalStockValue ?? 0, 2) ?></div>
                <div class="stat-sub">Total asset value</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Low Stock Items</span>
                    <div class="stat-icon"><i class="fa-solid fa-exclamation-triangle"></i></div>
                </div>
                <div class="stat-value" style="color: var(--warning);"><?= $lowStockCount ?></div>
                <div class="stat-sub">Below minimum level</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Expired Items</span>
                    <div class="stat-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                </div>
                <div class="stat-value" style="color: var(--danger);"><?= $expiredCount ?></div>
                <div class="stat-sub">Needs disposal</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Total Revenue</span>
                    <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
                </div>
                <div class="stat-value">$<?= number_format($revenue['total_revenue'] ?? 0, 2) ?></div>
                <div class="stat-sub">From purchase orders</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-title">
                    <i class="fa-solid fa-chart-line"></i> Monthly Revenue Trend
                </div>
                <canvas id="revenueChart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-title">
                    <i class="fa-solid fa-chart-bar"></i> Prescription Activity (Last 7 Days)
                </div>
                <canvas id="activityChart"></canvas>
            </div>
        </div>

        <!-- Critical Stock Alert -->
        <?php if ($criticalCount > 0): ?>
        <div class="report-card" style="border-left: 4px solid var(--danger);">
            <div class="report-header">
                <h3><i class="fa-solid fa-bell" style="color: var(--danger);"></i> Critical Stock Alert</h3>
                <span class="badge-count"><?= $criticalCount ?> items out of stock</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>Medicine Name</th><th>Current Stock</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($criticalStock as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['name']) ?></td>
                            <td><?= $item['quantity'] ?> units</td>
                            <td><span class="status-danger"><i class="fa-solid fa-circle-exclamation"></i> OUT OF STOCK</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Low Stock Report -->
        <div class="report-card">
            <div class="report-header">
                <h3><i class="fa-solid fa-exclamation-triangle"></i> Low Stock Report</h3>
                <span class="badge-count"><?= $lowStockCount ?> items</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>Medicine Name</th><th>Current Stock</th><th>Min Stock</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($lowStock) > 0): ?>
                            <?php foreach($lowStock as $row): ?>
                            <tr>
                                <td><?= $row['medicine_id'] ?></td>
                                <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                <td style="color: var(--warning); font-weight: 600;"><?= $row['quantity'] ?></td>
                                <td><?= $row['min_stock'] ?></td>
                                <td><span class="status-warning"><i class="fa-solid fa-clock"></i> Reorder Soon</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-check-circle"></i><p>All stock levels are healthy</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Expired Medicines Report -->
        <div class="report-card">
            <div class="report-header">
                <h3><i class="fa-solid fa-calendar-xmark"></i> Expired Medicines</h3>
                <span class="badge-count"><?= $expiredCount ?> expired</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>Medicine Name</th><th>Quantity</th><th>Expiry Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($expired) > 0): ?>
                            <?php foreach($expired as $row): ?>
                            <tr>
                                <td><?= $row['medicine_id'] ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= $row['quantity'] ?></td>
                                <td style="color: var(--danger);"><?= date('M d, Y', strtotime($row['expiry_date'])) ?></td>
                                <td><span class="status-danger"><i class="fa-solid fa-skull"></i> Expired</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-calendar-check"></i><p>No expired medicines found</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Expiring Soon Report -->
        <div class="report-card">
            <div class="report-header">
                <h3><i class="fa-solid fa-hourglass-half"></i> Expiring Soon (Next 30 Days)</h3>
                <span class="badge-count"><?= $expiringSoonCount ?> items</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>Medicine Name</th><th>Quantity</th><th>Expiry Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($expiringSoon) > 0): ?>
                            <?php foreach($expiringSoon as $row): ?>
                            <tr>
                                <td><?= $row['medicine_id'] ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= $row['quantity'] ?></td>
                                <td style="color: var(--warning);"><?= date('M d, Y', strtotime($row['expiry_date'])) ?></td>
                                <td><span class="status-warning"><i class="fa-solid fa-bell"></i> Expiring Soon</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-calendar-check"></i><p>No medicines expiring in the next 30 days</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Controlled Drug Audit -->
        <div class="report-card">
            <div class="report-header">
                <h3><i class="fa-solid fa-shield-halved"></i> Controlled Drug Audit</h3>
                <span class="badge-count"><?= $controlledCount ?> controlled items</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>Medicine Name</th><th>Quantity</th><th>Batch No.</th><th>Expiry Date</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($controlled) > 0): ?>
                            <?php foreach($controlled as $row): ?>
                            <tr>
                                <td><?= $row['medicine_id'] ?></td>
                                <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                <td><?= $row['quantity'] ?></td>
                                <td><?= htmlspecialchars($row['batch_no'] ?? 'N/A') ?></td>
                                <td><?= date('M d, Y', strtotime($row['expiry_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-check-shield"></i><p>No controlled substances in inventory</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Medicines by Stock -->
        <div class="report-card">
            <div class="report-header">
                <h3><i class="fa-solid fa-trophy"></i> Top Medicines by Stock Volume</h3>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>Medicine Name</th><th>Current Stock</th><th>Unit Price</th><th>Total Value</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($topMedicines as $item): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                            <td><?= number_format($item['quantity']) ?> units</td>
                            <td>$<?= number_format($item['price'] ?? 0, 2) ?></td>
                            <td>$<?= number_format(($item['price'] ?? 0) * $item['quantity'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
// Revenue Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($revenueMonths) ?>,
        datasets: [{
            label: 'Revenue ($)',
            data: <?= json_encode($revenueValues) ?>,
            borderColor: '#2ecc71',
            backgroundColor: 'rgba(46, 204, 113, 0.05)',
            borderWidth: 3,
            pointBackgroundColor: '#2ecc71',
            pointBorderColor: '#0a1f14',
            pointRadius: 5,
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { labels: { color: '#b0d4c0', font: { size: 11 } } }
        },
        scales: {
            y: { grid: { color: 'rgba(46, 204, 113, 0.1)' }, ticks: { color: '#b0d4c0' } },
            x: { grid: { color: 'rgba(46, 204, 113, 0.1)' }, ticks: { color: '#b0d4c0' } }
        }
    }
});

// Activity Chart
// Activity Chart
const activityCtx = document.getElementById('activityChart').getContext('2d');

new Chart(activityCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($activityDates) ?>,
        datasets: [{
            label: 'Prescriptions',
            data: <?= json_encode($activityCounts) ?>,
            backgroundColor: 'rgba(46, 204, 113, 0.7)',
            borderColor: '#2ecc71',
            borderWidth: 2,
            borderRadius: 8,
            hoverBackgroundColor: 'rgba(46, 204, 113, 0.9)',
            maxBarThickness: 40
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: true,
                labels: {
                    color: '#b0d4c0',
                    font: {
                        size: 11,
                        weight: '600'
                    }
                }
            },
            tooltip: {
                backgroundColor: '#0d2818',
                titleColor: '#ffffff',
                bodyColor: '#b0d4c0',
                borderColor: '#2ecc71',
                borderWidth: 1
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(46, 204, 113, 0.1)'
                },
                ticks: {
                    color: '#b0d4c0',
                    stepSize: 1
                }
            },
            x: {
                grid: {
                    color: 'rgba(46, 204, 113, 0.05)'
                },
                ticks: {
                    color: '#b0d4c0'
                }
            }
        }
    }
});

// Profile Image Upload
document.getElementById('profileInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append("profile_image", file);

    fetch("upload_profile.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const preview = document.getElementById("profilePreview");
            const placeholder = document.getElementById("profilePlaceholder");

            if (preview) {
                preview.src = data.url + "?t=" + new Date().getTime(); // prevent cache
                preview.style.display = "block";
            }

            if (placeholder) {
                placeholder.style.display = "none";
            }

            alert("Profile updated successfully!");
        } else {
            alert(data.message || "Upload failed.");
        }
    })
    .catch(error => {
        console.error(error);
        alert("Upload failed. Please try again.");
    });
});
</script>

</body>
</html>

<?php
$conn->close();
?>