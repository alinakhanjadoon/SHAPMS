<?php
session_start();

/* ---------- DH AUTH ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role']!== 'department_head') {
    header("Location: login.php");
    exit();
}

/* ---------- DB ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("Connection failed: ". $conn->connect_error);
}

/* ---------- ADD SUPPLIER ---------- */
if (isset($_POST['add_supplier'])) {
    $stmt = $conn->prepare("INSERT INTO suppliers(name, contact, email, address) VALUES(?,?,?,?)");
    $stmt->bind_param("ssss",
        $_POST['name'],
        $_POST['contact'],
        $_POST['email'],
        $_POST['address']
    );
    $stmt->execute();
}

/* ---------- CREATE PURCHASE ORDER ---------- */
if (isset($_POST['create_po'])) {
    $emergency = isset($_POST['emergency'])? 1 : 0;

    $stmt = $conn->prepare("
        INSERT INTO purchase_orders
        (supplier_id, medicine_name, quantity, price, is_emergency)
        VALUES (?,?,?,?,?)
    ");

    $stmt->bind_param(
        "isidi",
        $_POST['supplier_id'],
        $_POST['medicine_name'],
        $_POST['quantity'],
        $_POST['price'],
        $emergency
    );

    $stmt->execute();
}

/* ---------- ACTIONS ---------- */
if (isset($_POST['action'])) {

    $id = intval($_POST['po_id']);

    switch ($_POST['action']) {

        case "approve":
            $conn->query("UPDATE purchase_orders SET status='approved' WHERE po_id=$id");
            break;

        case "reject":
            $conn->query("UPDATE purchase_orders SET status='rejected' WHERE po_id=$id");
            break;

        case "deliver":
            $conn->query("UPDATE purchase_orders SET status='delivered' WHERE po_id=$id");
            break;

        case "invoice":
            $conn->query("UPDATE purchase_orders SET invoice_verified=1 WHERE po_id=$id");
            break;

        case "pay":
            $conn->query("UPDATE purchase_orders SET payment_status='paid' WHERE po_id=$id");
            break;
    }
}

/* ---------- DATA ---------- */
$suppliers = $conn->query("SELECT * FROM suppliers ORDER BY supplier_id DESC");

$orders = $conn->query("
    SELECT po.*, s.name AS supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    ORDER BY po.po_id DESC
");

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
    <title>Supplier & Purchasing — Pharmacy Department</title>
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
            --dark: #4b5563;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

     .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 8px 0;
        }

     .checkbox-label input {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--primary);
        }

     .checkbox-label span {
            font-size: 13px;
            color: var(--text-dark);
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

     .btn-modern-sm {
            padding: 6px 12px;
            font-size: 11px;
            border-radius: 8px;
        }

     .btn-success-custom {
            background: #f0fdf4;
            color: var(--primary);
            border: 1px solid #bbf7d0;
        }

     .btn-success-custom:hover {
            background: var(--primary);
            color: #fff;
        }

     .btn-danger-custom {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

     .btn-danger-custom:hover {
            background: var(--danger);
            color: #fff;
        }

     .btn-info-custom {
            background: #eff6ff;
            color: var(--info);
            border: 1px solid #bfdbfe;
        }

     .btn-info-custom:hover {
            background: var(--info);
            color: #fff;
        }

     .btn-warning-custom {
            background: #fffbeb;
            color: var(--warning);
            border: 1px solid #fed7aa;
        }

     .btn-warning-custom:hover {
            background: var(--warning);
            color: #fff;
        }

     .btn-dark-custom {
            background: #f3f4f6;
            color: var(--dark);
            border: 1px solid #d1d5db;
        }

     .btn-dark-custom:hover {
            background: var(--dark);
            color: #fff;
        }

        /* Table Styles */
     .table-responsive {
            overflow-x: auto;
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
            background: #fafbfe;
            border-bottom: 1px solid var(--border);
        }

     .data-table tbody td {
            padding: 16px 20px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            color: var(--text-dark);
            vertical-align: middle;
        }

     .data-table tbody tr:hover {
            background: #fafbfe;
        }

     .supplier-name {
            font-weight: 600;
            color: var(--text-dark);
        }

     .emergency-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

     .emergency-yes {
            background: #fef2f2;
            color: var(--danger);
        }

     .emergency-no {
            background: #f0fdf4;
            color: var(--primary);
        }

     .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

     .status-approved {
            background: #f0fdf4;
            color: var(--primary);
        }

     .status-rejected {
            background: #fef2f2;
            color: var(--danger);
        }

     .status-delivered {
            background: #eff6ff;
            color: var(--info);
        }

     .status-pending {
            background: #fffbeb;
            color: var(--warning);
        }

     .invoice-verified {
            background: #f0fdf4;
            color: var(--primary);
        }

     .invoice-pending {
            background: #fffbeb;
            color: var(--warning);
        }

     .payment-paid {
            background: #f0fdf4;
            color: var(--primary);
        }

     .payment-pending {
            background: #fffbeb;
            color: var(--warning);
        }

     .action-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

     .inline-form {
            display: inline-block;
            margin: 0;
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
                <a href="pharmacy_inventoryDH.php" class="nav-item">
                    <i class="fa-solid fa-boxes-stacked"></i> Inventory Control
                </a>
                <a href="pharmacy_supplier_purchasingDH.php" class="nav-item active">
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
                <h1>Supplier & Purchasing</h1>
                <p>Manage medicine suppliers, create purchase orders, and track procurement workflow</p>
            </div>
            <div>
                <input type="file" id="profileInput" style="display:none;" accept="image/*">
            </div>
        </div>

        <!-- ADD SUPPLIER CARD -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-truck"></i> Register New Supplier</h3>
            </div>
            <div class="form-grid">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Supplier Name</label>
                            <input name="name" class="form-control-modern" placeholder="e.g., Pharma Distributors Ltd" required>
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input name="contact" class="form-control-modern" placeholder="+92 XXX XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input name="email" class="form-control-modern" placeholder="supplier@example.com">
                        </div>
                        <div class="form-group">
                            <label>Address</label>
                            <input name="address" class="form-control-modern" placeholder="City, Country">
                        </div>
                        <div class="form-group">
                            <button name="add_supplier" class="btn-modern">
                                <i class="fa-solid fa-save"></i> Add Supplier
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- CREATE PURCHASE ORDER CARD -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-file-invoice"></i> Create Purchase Order</h3>
            </div>
            <div class="form-grid">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Supplier</label>
                            <select name="supplier_id" class="form-control-modern" required>
                                <option value="">— Choose Supplier —</option>
                                <?php
                                $suppliers->data_seek(0);
                                while($s = $suppliers->fetch_assoc()):
                              ?>
                                    <option value="<?= $s['supplier_id']?>">
                                        <?= htmlspecialchars($s['name'])?>
                                    </option>
                                <?php endwhile;?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Medicine Name</label>
                            <input name="medicine_name" class="form-control-modern" placeholder="Medicine name" required>
                        </div>
                        <div class="form-group">
                            <label>Quantity</label>
                            <input name="quantity" type="number" class="form-control-modern" placeholder="0" required>
                        </div>
                        <div class="form-group">
                            <label>Price (per unit)</label>
                            <input name="price" type="number" class="form-control-modern" placeholder="0.00" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="emergency">
                                <span><i class="fa-solid fa-fire"></i> Emergency Order</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <button name="create_po" class="btn-modern">
                                <i class="fa-solid fa-cart-plus"></i> Create Order
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- PURCHASE ORDERS TABLE -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-clipboard-list"></i> Purchase Orders</h3>
                <span style="font-size: 12px; color: var(--text-muted);">
                    <i class="fa-solid fa-chart-line"></i> Track procurement status
                </span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Medicine</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Emergency</th>
                            <th>Status</th>
                            <th>Invoice</th>
                            <th>Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders->num_rows > 0):?>
                            <?php while($o = $orders->fetch_assoc()):?>
                                <tr>
                                    <td class="supplier-name"><?= htmlspecialchars($o['supplier_name']?? 'Unknown')?></td>
                                    <td><?= htmlspecialchars($o['medicine_name'])?></td>
                                    <td><?= $o['quantity']?></td>
                                    <td>$<?= number_format($o['price'], 2)?></td>
                                    <td>
                                        <span class="emergency-badge <?= $o['is_emergency']? 'emergency-yes' : 'emergency-no'?>">
                                            <?= $o['is_emergency']? "🔥 Emergency" : "Normal"?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge
                                            <?= $o['status'] == 'approved'? 'status-approved' :
                                                ($o['status'] == 'rejected'? 'status-rejected' :
                                                ($o['status'] == 'delivered'? 'status-delivered' : 'status-pending'))?>">
                                            <?= ucfirst($o['status'])?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $o['invoice_verified']? 'invoice-verified' : 'invoice-pending'?>">
                                            <?= $o['invoice_verified']? "Verified" : "Pending"?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $o['payment_status'] == 'paid'? 'payment-paid' : 'payment-pending'?>">
                                            <?= ucfirst($o['payment_status'])?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="po_id" value="<?= $o['po_id']?>">
                                                <button name="action" value="approve" class="btn-modern btn-modern-sm btn-success-custom">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="po_id" value="<?= $o['po_id']?>">
                                                <button name="action" value="reject" class="btn-modern btn-modern-sm btn-danger-custom">
                                                    <i class="fa-solid fa-times"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="po_id" value="<?= $o['po_id']?>">
                                                <button name="action" value="deliver" class="btn-modern btn-modern-sm btn-info-custom">
                                                    <i class="fa-solid fa-truck"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="po_id" value="<?= $o['po_id']?>">
                                                <button name="action" value="invoice" class="btn-modern btn-modern-sm btn-warning-custom">
                                                    <i class="fa-solid fa-file-invoice"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="po_id" value="<?= $o['po_id']?>">
                                                <button name="action" value="pay" class="btn-modern btn-modern-sm btn-dark-custom">
                                                    <i class="fa-solid fa-credit-card"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile;?>
                        <?php else:?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-box-open"></i>
                                        <p>No purchase orders found. Create your first order above.</p>
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

<?php $conn->close();?>