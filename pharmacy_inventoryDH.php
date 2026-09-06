
<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['role']) || $_SESSION['role']!= 'department_head') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("DB Error: ". $conn->connect_error);
}

/* ================= ADD MEDICINE ================= */
if (isset($_POST['add_medicine'])) {

    $stmt = $conn->prepare("
        INSERT INTO medicines
        (name, batch_no, quantity, min_stock, expiry_date, controlled_drug)
        VALUES (?,?,?)
    ");

    $stmt->bind_param(
        "ssii si",
        $_POST['name'],
        $_POST['batch_no'],
        $_POST['quantity'],
        $_POST['min_stock'],
        $_POST['expiry_date'],
        $_POST['controlled_drug']
    );

    $stmt->execute();
    $stmt->close();
}

/* ================= UPDATE STOCK ================= */
if (isset($_POST['update_stock'])) {

    $stmt = $conn->prepare("UPDATE medicines SET quantity=? WHERE medicine_id=?");
    $stmt->bind_param("ii", $_POST['quantity'], $_POST['medicine_id']);
    $stmt->execute();
    $stmt->close();
}

/* ================= SET MIN STOCK ================= */
if (isset($_POST['set_min'])) {

    $stmt = $conn->prepare("UPDATE medicines SET min_stock=? WHERE medicine_id=?");
    $stmt->bind_param("ii", $_POST['min_stock'], $_POST['medicine_id']);
    $stmt->execute();
    $stmt->close();
}

/* ================= DELETE EXPIRED ================= */
if (isset($_POST['delete_expired'])) {
    $conn->query("DELETE FROM medicines WHERE expiry_date < CURDATE()");
}

/* ================= DATA QUERIES ================= */
$medicines = $conn->query("SELECT * FROM medicines ORDER BY medicine_id DESC");

$low_stock = $conn->query("
    SELECT * FROM medicines
    WHERE quantity <= min_stock
");

$expiring = $conn->query("
    SELECT * FROM medicines
    WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");

/* IMPORTANT FIX */
$purchases = $conn->query("SELECT * FROM purchase_requests ORDER BY id DESC");

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
$initials = strtoupper(substr($parts[0],0,1). (isset($parts[1])? substr($parts[1],0,1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Control — Pharmacy Department</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            /* Donezo Light Theme */
            --bg: #f7f8fc;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --primary: #0a7a4f;
            --primary-light: #e6f5ee;
            --primary-dark: #065f3d;
            --text-dark: #1a1f36;
            --text-muted: #8b95a7;
            --border: #e8eaf1;
            --shadow: 0 4px 20px rgba(0,0,0,0.04);
            --shadow-hover: 0 8px 30px rgba(0,0,0,0.08);
            --radius: 16px;
            --sidebar-width: 260px;
            --danger: #dc2626;
            --warning: #f59e0b;
            --info: #3b82f6;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
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

        /* SIDEBAR */
      .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
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
            border-bottom: 1px solid var(--border);
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
            border: 3px solid var(--primary);
            background: var(--primary-light);
        }

      .profile-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            border: 3px solid var(--primary);
        }

      .profile-upload-overlay {
            position: absolute;
            bottom: -8px;
            right: -8px;
            background: var(--primary);
            width: 32px;
            height: 32px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

      .profile-upload-overlay:hover {
            transform: scale(1.1);
            background: var(--primary-dark);
        }

      .profile-upload-overlay i {
            font-size: 14px;
            color: #fff;
        }

      .sidebar-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

      .sidebar-role {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--primary);
            background: var(--primary-light);
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
            color: var(--text-muted);
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
            background: var(--primary-light);
            color: var(--primary);
        }

      .nav-item.active {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 600;
        }

      .nav-item.logout {
            margin-top: 20px;
            color: var(--danger);
        }

      .nav-item.logout:hover {
            background: #fef1f2;
            color: var(--danger);
        }

        /* MAIN CONTENT */
      .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 32px;
        }

      .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

      .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

      .page-header p {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* Cards */
      .card-modern {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

      .card-modern:hover {
            box-shadow: var(--shadow-hover);
        }

      .card-header-custom {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

      .card-header-custom h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

      .card-header-custom h3 i {
            color: var(--primary);
            font-size: 18px;
        }

        /* Forms */
      .form-grid {
            padding: 24px;
        }

      .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            align-items: end;
        }

      .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

      .form-control-modern {
            width: 100%;
            padding: 10px 14px;
            background: #fafbfe;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
            color: var(--text-dark);
            transition: all 0.2s;
        }

      .form-control-modern:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 122, 79, 0.1);
        }

      .form-control-modern::placeholder {
            color: var(--text-muted);
        }

        select.form-control-modern {
            cursor: pointer;
        }

      .btn-modern {
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

      .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(10, 122, 79, 0.25);
        }

      .btn-modern-danger {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

      .btn-modern-danger:hover {
            background: var(--danger);
            color: #fff;
            transform: translateY(-2px);
        }

      .btn-modern-sm {
            padding: 6px 14px;
            font-size: 11px;
        }

        /* Alert Lists */
      .alerts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

      .alert-list {
            padding: 16px 24px;
        }

      .alert-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }

      .alert-item:last-child {
            border-bottom: none;
        }

      .alert-icon {
            width: 36px;
            height: 36px;
            background: #fff7ed;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: var(--warning);
        }

      .alert-content {
            flex: 1;
        }

      .alert-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 2px;
            font-size: 14px;
        }

      .alert-detail {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Table Styles */
      .table-responsive {
            overflow-x: auto;
        }

      .inventory-table {
            width: 100%;
            border-collapse: collapse;
        }

      .inventory-table thead th {
            text-align: left;
            padding: 14px 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            background: #fafbfe;
            border-bottom: 1px solid var(--border);
        }

      .inventory-table tbody td {
            padding: 16px 20px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            color: var(--text-dark);
            vertical-align: middle;
        }

      .inventory-table tbody tr:hover {
            background: #fafbfe;
        }

      .medicine-name {
            font-weight: 600;
            color: var(--text-dark);
        }

      .controlled-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

      .controlled-yes {
            background: #fff7ed;
            color: var(--warning);
        }

      .controlled-no {
            background: #f0fdf4;
            color: var(--primary);
        }

      .inline-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

      .inline-input {
            width: 80px;
            padding: 8px 10px;
            background: #fafbfe;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 12px;
            color: var(--text-dark);
        }

      .inline-input:focus {
            outline: none;
            border-color: var(--primary);
        }

      .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

      .status-pending {
            background: #fff7ed;
            color: var(--warning);
        }

      .status-approved {
            background: #f0fdf4;
            color: var(--primary);
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
                padding: 20px;
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
                <a href="pharmacydepartmentdashboard.php" class="nav-item">
                    <i class="fa-solid fa-chart-line"></i> Dashboard
                </a>
                <a href="pharmacyDHstaff_management.php" class="nav-item">
                    <i class="fa-solid fa-users"></i> Staff Management
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">PHARMACY</div>
                <a href="pharmacy_inventoryDH.php" class="nav-item active">
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
                <h1>Inventory Control</h1>
                <p>Manage medicine stock, track expirations, and approve purchase requests</p>
            </div>
            <div>
                <input type="file" id="profileInput" style="display:none;" accept="image/*">
            </div>
        </div>

        <!-- ADD MEDICINE CARD -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-plus-circle"></i> Add New Medicine</h3>
            </div>
            <div class="form-grid">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Medicine Name</label>
                            <input name="name" class="form-control-modern" placeholder="e.g., Paracetamol" required>
                        </div>
                        <div class="form-group">
                            <label>Batch Number</label>
                            <input name="batch_no" class="form-control-modern" placeholder="BATCH-001" required>
                        </div>
                        <div class="form-group">
                            <label>Quantity</label>
                            <input name="quantity" type="number" class="form-control-modern" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label>Min Stock</label>
                            <input name="min_stock" type="number" class="form-control-modern" placeholder="10">
                        </div>
                        <div class="form-group">
                            <label>Expiry Date</label>
                            <input name="expiry_date" type="date" class="form-control-modern">
                        </div>
                        <div class="form-group">
                            <label>Drug Type</label>
                            <select name="controlled_drug" class="form-control-modern">
                                <option value="0">Normal</option>
                                <option value="1">Controlled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button name="add_medicine" class="btn-modern">
                                <i class="fa-solid fa-save"></i> Add Medicine
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ALERTS: LOW STOCK & EXPIRING -->
        <div class="alerts-grid">
            <!-- LOW STOCK ALERT -->
            <div class="card-modern">
                <div class="card-header-custom">
                    <h3><i class="fa-solid fa-exclamation-triangle" style="color: var(--warning);"></i> Low Stock Alerts</h3>
                </div>
                <div class="alert-list">
                    <?php if ($low_stock->num_rows > 0):?>
                        <?php while($row = $low_stock->fetch_assoc()):?>
                            <div class="alert-item">
                                <div style="display: flex; align-items: center;">
                                    <div class="alert-icon">
                                        <i class="fa-solid fa-capsules"></i>
                                    </div>
                                    <div class="alert-content">
                                        <div class="alert-title"><?= htmlspecialchars($row['name'])?></div>
                                        <div class="alert-detail">Only <?= $row['quantity']?> units remaining (Min: <?= $row['min_stock']?>)</div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile;?>
                    <?php else:?>
                        <div class="empty-state" style="padding: 32px;">
                            <i class="fa-solid fa-check-circle"></i>
                            <p>All stock levels are healthy</p>
                        </div>
                    <?php endif;?>
                </div>
            </div>

            <!-- EXPIRING SOON ALERT -->
            <div class="card-modern">
                <div class="card-header-custom">
                    <h3><i class="fa-solid fa-calendar-exclamation" style="color: var(--info);"></i> Expiring Soon</h3>
                </div>
                <div class="alert-list">
                    <?php if ($expiring->num_rows > 0):?>
                        <?php while($row = $expiring->fetch_assoc()):?>
                            <div class="alert-item">
                                <div style="display: flex; align-items: center;">
                                    <div class="alert-icon" style="background: #eff6ff; color: var(--info);">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>
                                    <div class="alert-content">
                                        <div class="alert-title"><?= htmlspecialchars($row['name'])?></div>
                                        <div class="alert-detail">Expires on <?= date('M d, Y', strtotime($row['expiry_date']))?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile;?>
                        <div style="margin-top: 16px; text-align: right;">
                            <form method="POST">
                                <button name="delete_expired" class="btn-modern btn-modern-danger btn-modern-sm">
                                    <i class="fa-solid fa-trash"></i> Remove Expired Medicines
                                </button>
                            </form>
                        </div>
                    <?php else:?>
                        <div class="empty-state" style="padding: 32px;">
                            <i class="fa-solid fa-calendar-check"></i>
                            <p>No medicines expiring soon</p>
                        </div>
                    <?php endif;?>
                </div>
            </div>
        </div>

        <!-- INVENTORY TABLE -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-warehouse"></i> Medicine Inventory</h3>
                <span style="font-size: 12px; color: var(--text-muted);">
                    <i class="fa-solid fa-prescription-bottle"></i> <?= $medicines->num_rows?> items
                </span>
            </div>
            <div class="table-responsive">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Batch No.</th>
                            <th>Quantity</th>
                            <th>Min Stock</th>
                            <th>Expiry Date</th>
                            <th>Type</th>
                            <th>Update Qty</th>
                            <th>Set Min</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($medicines->num_rows > 0):?>
                            <?php while($row = $medicines->fetch_assoc()):?>
                                <tr>
                                    <td class="medicine-name"><?= htmlspecialchars($row['name']?? '')?></td>
                                    <td><?= htmlspecialchars($row['batch_no']?? '')?></td>
                                    <td>
                                        <span style="font-weight: 600; color: <?= ($row['quantity'] <= $row['min_stock'])? 'var(--warning)' : 'var(--text-dark)'?>">
                                            <?= $row['quantity']?>
                                        </span>
                                    </td>
                                    <td><?= $row['min_stock']?? 0?></td>
                                    <td>
                                        <?php if ($row['expiry_date']):?>
                                            <span style="color: <?= (strtotime($row['expiry_date']) < strtotime('+30 days'))? 'var(--warning)' : 'inherit'?>">
                                                <?= date('M d, Y', strtotime($row['expiry_date']))?>
                                            </span>
                                        <?php else:?>
                                            —
                                        <?php endif;?>
                                    </td>
                                    <td>
                                        <span class="controlled-badge <?= ($row['controlled_drug']?? 0)? 'controlled-yes' : 'controlled-no'?>">
                                            <?= ($row['controlled_drug']?? 0)? "Controlled" : "Normal"?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="medicine_id" value="<?= $row['medicine_id']?>">
                                            <input name="quantity" value="<?= $row['quantity']?>" class="inline-input" type="number">
                                            <button name="update_stock" class="btn-modern btn-modern-sm" style="background: #eff6ff; color: var(--info);">
                                                <i class="fa-solid fa-arrow-up"></i> Update
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="medicine_id" value="<?= $row['medicine_id']?>">
                                            <input name="min_stock" class="inline-input" placeholder="Min" type="number">
                                            <button name="set_min" class="btn-modern btn-modern-sm" style="background: #fffbeb; color: var(--warning);">
                                                <i class="fa-solid fa-gear"></i> Set
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile;?>
                        <?php else:?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-box-open"></i>
                                        <p>No medicines in inventory. Add your first medicine above.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif;?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PURCHASE REQUESTS -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-file-invoice"></i> Purchase Requests</h3>
            </div>
            <div class="table-responsive">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($purchases && $purchases->num_rows > 0):?>
                            <?php while($row = $purchases->fetch_assoc()):?>
                                <tr>
                                    <td class="medicine-name"><?= htmlspecialchars($row['medicine_name'])?></td>
                                    <td><?= $row['quantity']?></td>
                                    <td>
                                        <span class="status-badge <?= $row['status'] == 'pending'? 'status-pending' : 'status-approved'?>">
                                            <?= ucfirst($row['status'])?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] == 'pending'):?>
                                            <form method="POST">
                                                <input type="hidden" name="id" value="<?= $row['id']?>">
                                                <button name="approve_purchase" class="btn-modern btn-modern-sm">
                                                    <i class="fa-solid fa-check"></i> Approve
                                                </button>
                                            </form>
                                        <?php else:?>
                                            <span style="color: var(--text-muted); font-size: 12px;">
                                                <i class="fa-solid fa-check-circle"></i> Processed
                                            </span>
                                        <?php endif;?>
                                    </td>
                                </tr>
                            <?php endwhile;?>
                        <?php else:?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-inbox"></i>
                                        <p>No purchase requests found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif;?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
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
                if (preview) {
                    preview.src = data.url;
                    preview.style.display = "block";
                }
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