<?php
session_start();

/* ---------- AUTH ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role']!= 'department_head') {
    header("Location: login.php");
    exit();
}

/* ---------- DB ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");

if ($conn->connect_error) {
    die("Connection failed: ". $conn->connect_error);
}

/* ---------- ACTIONS ---------- */

// UPDATE STATUS
function updateStatus($conn, $status, $id) {
    $stmt = $conn->prepare("UPDATE users SET status=? WHERE user_id=?");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    $stmt->close();
}

// UPDATE RESPONSIBILITY
function updateResponsibility($conn, $value, $id) {
    $stmt = $conn->prepare("UPDATE users SET responsibility=? WHERE user_id=?");
    $stmt->bind_param("si", $value, $id);
    $stmt->execute();
    $stmt->close();
}

// UPDATE SHIFT
function updateShift($conn, $value, $id) {
    $stmt = $conn->prepare("UPDATE users SET shift=? WHERE user_id=?");
    $stmt->bind_param("si", $value, $id);
    $stmt->execute();
    $stmt->close();
}

// UPDATE RATING
function updateRating($conn, $value, $id) {
    if ($value >= 1 && $value <= 5) {
        $stmt = $conn->prepare("UPDATE users SET performance_rating=? WHERE user_id=?");
        $stmt->bind_param("ii", $value, $id);
        $stmt->execute();
        $stmt->close();
    }
}

/* ---------- HANDLE POST ---------- */

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $id = isset($_POST['user_id'])? intval($_POST['user_id']) : 0;

    if (isset($_POST['approve'])) {
        updateStatus($conn, "active", $id);
    }

    if (isset($_POST['reject'])) {
        updateStatus($conn, "rejected", $id);
    }

    if (isset($_POST['suspend'])) {
        updateStatus($conn, "suspended", $id);
    }

    if (isset($_POST['activate'])) {
        updateStatus($conn, "active", $id);
    }

    if (isset($_POST['assign_role'])) {
        updateResponsibility($conn, $_POST['responsibility'], $id);
    }

    if (isset($_POST['assign_shift'])) {
        updateShift($conn, $_POST['shift'], $id);
    }

    if (isset($_POST['rate'])) {
        updateRating($conn, intval($_POST['rating']), $id);
    }
}

/* ---------- SEARCH / FILTER ---------- */

$search = "";
$status = "";

if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

if (isset($_GET['status'])) {
    $status = trim($_GET['status']);
}

/* ---------- QUERY ---------- */

$sql = "SELECT user_id, full_name, status, responsibility, shift, performance_rating
        FROM users
        WHERE role='pharmacist' AND department_id=2";

if ($search!= "") {
    $sql.= " AND full_name LIKE?";
}
if ($status!= "") {
    $sql.= " AND status=?";
}

$sql.= " ORDER BY user_id DESC";

$stmt = $conn->prepare($sql);

if ($search!= "" && $status!= "") {
    $like = "%$search%";
    $stmt->bind_param("ss", $like, $status);
} elseif ($search!= "") {
    $like = "%$search%";
    $stmt->bind_param("s", $like);
} elseif ($status!= "") {
    $stmt->bind_param("s", $status);
}

$stmt->execute();
$result = $stmt->get_result();

// Get user profile data for sidebar
$full_name = $_SESSION['full_name'];
$userId = $_SESSION['user_id'];
$profilePic = "uploads/default.png";
$stmt2 = $conn->prepare("SELECT profile_image FROM users WHERE user_id=?");
$stmt2->bind_param("i", $userId);
$stmt2->execute();
$res = $stmt2->get_result()->fetch_assoc();
if (!empty($res['profile_image'])) { $profilePic = $res['profile_image']; }
$stmt2->close();

$parts = explode(' ', trim($full_name));
$initials = strtoupper(substr($parts[0],0,1). (isset($parts[1])? substr($parts[1],0,1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management — Pharmacy Department</title>
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

        /* Dashboard Layout */
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

        /* Top Bar */
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

        /* Search Form */
       .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

       .search-input {
            flex: 1;
            min-width: 200px;
        }

       .search-input input,.search-select select {
            width: 100%;
            padding: 10px 16px;
            background: #fafbfe;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
            color: var(--text-dark);
            transition: all 0.2s;
        }

       .search-input input:focus,.search-select select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 122, 79, 0.1);
        }

       .search-input input::placeholder {
            color: var(--text-muted);
        }

       .search-select select {
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
        }

       .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(10, 122, 79, 0.25);
        }

        /* Table Styles */
       .table-responsive {
            overflow-x: auto;
        }

       .staff-table {
            width: 100%;
            border-collapse: collapse;
        }

       .staff-table thead th {
            text-align: left;
            padding: 14px 24px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            background: #fafbfe;
            border-bottom: 1px solid var(--border);
        }

       .staff-table tbody td {
            padding: 16px 24px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            color: var(--text-dark);
            vertical-align: middle;
        }

       .staff-table tbody tr:hover {
            background: #fafbfe;
        }

       .staff-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Status Badges */
       .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

       .status-active {
            background: #f0fdf4;
            color: var(--primary);
        }

       .status-inactive {
            background: #fff7ed;
            color: var(--warning);
        }

       .status-suspended {
            background: #eff6ff;
            color: var(--info);
        }

       .status-rejected {
            background: #fef2f2;
            color: var(--danger);
        }

        /* Form Controls in Table */
       .form-control-sm-custom {
            background: #fafbfe;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 12px;
            color: var(--text-dark);
            transition: all 0.2s;
        }

       .form-control-sm-custom:focus {
            outline: none;
            border-color: var(--primary);
        }

       .form-select-sm-custom {
            background: #fafbfe;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 12px;
            color: var(--text-dark);
            cursor: pointer;
        }

       .rating-input {
            width: 70px;
            display: inline-block;
            margin-right: 8px;
        }

       .btn-sm-custom {
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

       .btn-success-custom {
            background: #f0fdf4;
            color: var(--primary);
            border-color: #bbf7d0;
        }

       .btn-success-custom:hover {
            background: var(--primary);
            color: #fff;
        }

       .btn-danger-custom {
            background: #fef2f2;
            color: var(--danger);
            border-color: #fecaca;
        }

       .btn-danger-custom:hover {
            background: var(--danger);
            color: #fff;
        }

       .btn-warning-custom {
            background: #fffbeb;
            color: var(--warning);
            border-color: #fed7aa;
        }

       .btn-warning-custom:hover {
            background: var(--warning);
            color: #fff;
        }

       .btn-primary-custom {
            background: #eff6ff;
            color: var(--info);
            border-color: #bfdbfe;
        }

       .btn-primary-custom:hover {
            background: var(--info);
            color: #fff;
        }

       .action-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

       .inline-form {
            display: inline-block;
            margin: 0;
        }

       .rating-form {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
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
                <a href="pharmacyDHstaff_management.php" class="nav-item active">
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
                <h1>Staff Management</h1>
                <p>Manage pharmacist assignments, shifts, performance ratings, and approvals</p>
            </div>
            <div>
                <input type="file" id="profileInput" style="display:none;" accept="image/*">
            </div>
        </div>

        <!-- Search / Filter Card -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-magnifying-glass"></i> Filter Staff</h3>
            </div>
            <div style="padding: 20px 24px;">
                <form method="GET" class="search-form">
                    <div class="search-input">
                        <input type="text" name="search" placeholder="Search by name..." value="<?php echo htmlspecialchars($search);?>">
                    </div>
                    <div class="search-select">
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status == 'active'? 'selected' : '';?>>Active</option>
                            <option value="inactive" <?php echo $status == 'inactive'? 'selected' : '';?>>Inactive</option>
                            <option value="suspended" <?php echo $status == 'suspended'? 'selected' : '';?>>Suspended</option>
                            <option value="rejected" <?php echo $status == 'rejected'? 'selected' : '';?>>Rejected</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-modern">
                        <i class="fa-solid fa-search"></i> Search
                    </button>
                </form>
            </div>
        </div>

        <!-- Staff Table Card -->
        <div class="card-modern">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-users"></i> Pharmacist Directory</h3>
                <span style="font-size: 12px; color: var(--text-muted);">
                    <i class="fa-solid fa-user-check"></i> Manage assignments
                </span>
            </div>
            <div class="table-responsive">
                <table class="staff-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Responsibility</th>
                            <th>Shift</th>
                            <th>Rating (1-5)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0):?>
                            <?php while($row = $result->fetch_assoc()):?>
                                <tr>
                                    <td class="staff-name"><?php echo htmlspecialchars($row['full_name']);?></td>
                                    <td>
                                        <span class="status-badge
                                            <?php
                                                echo $row['status'] == 'active'? 'status-active' :
                                                    ($row['status'] == 'inactive'? 'status-inactive' :
                                                    ($row['status'] == 'suspended'? 'status-suspended' : 'status-rejected'));
                                           ?>">
                                            <?php echo ucfirst($row['status']);?>
                                        </span>
                                    </td>

                                    <!-- RESPONSIBILITY -->
                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                            <select name="responsibility" class="form-select-sm-custom" onchange="this.form.submit()">
                                                <option value="">— Select —</option>
                                                <option value="Inventory Pharmacist" <?php echo $row['responsibility'] == 'Inventory Pharmacist'? 'selected' : '';?>>Inventory</option>
                                                <option value="Dispensing Pharmacist" <?php echo $row['responsibility'] == 'Dispensing Pharmacist'? 'selected' : '';?>>Dispensing</option>
                                                <option value="Clinical Pharmacist" <?php echo $row['responsibility'] == 'Clinical Pharmacist'? 'selected' : '';?>>Clinical</option>
                                            </select>
                                            <input type="hidden" name="assign_role">
                                        </form>
                                    </td>

                                    <!-- SHIFT -->
                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                            <select name="shift" class="form-select-sm-custom" onchange="this.form.submit()">
                                                <option value="">— Select —</option>
                                                <option value="Morning" <?php echo $row['shift'] == 'Morning'? 'selected' : '';?>>Morning</option>
                                                <option value="Evening" <?php echo $row['shift'] == 'Evening'? 'selected' : '';?>>Evening</option>
                                                <option value="Night" <?php echo $row['shift'] == 'Night'? 'selected' : '';?>>Night</option>
                                            </select>
                                            <input type="hidden" name="assign_shift">
                                        </form>
                                    </td>

                                    <!-- RATING -->
                                    <td>
                                        <form method="POST" class="rating-form">
                                            <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                            <input type="number" name="rating" min="1" max="5" class="form-control-sm-custom rating-input" placeholder="1-5" value="<?php echo $row['performance_rating']?: '';?>">
                                            <button type="submit" name="rate" class="btn-sm-custom btn-primary-custom">
                                                <i class="fa-solid fa-star"></i> Save
                                            </button>
                                        </form>
                                    </td>

                                    <!-- ACTIONS -->
                                    <td>
                                        <div class="action-group">
                                            <?php if($row['status']!= "active"):?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                                    <button type="submit" name="approve" class="btn-sm-custom btn-success-custom">
                                                        <i class="fa-solid fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                                    <button type="submit" name="reject" class="btn-sm-custom btn-danger-custom">
                                                        <i class="fa-solid fa-times"></i> Reject
                                                    </button>
                                                </form>
                                            <?php endif;?>

                                            <?php if($row['status'] == "active"):?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                                    <button type="submit" name="suspend" class="btn-sm-custom btn-warning-custom">
                                                        <i class="fa-solid fa-pause"></i> Suspend
                                                    </button>
                                                </form>
                                            <?php endif;?>

                                            <?php if($row['status'] == "suspended"):?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id'];?>">
                                                    <button type="submit" name="activate" class="btn-sm-custom btn-success-custom">
                                                        <i class="fa-solid fa-play"></i> Activate
                                                    </button>
                                                </form>
                                            <?php endif;?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile;?>
                        <?php else:?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-user-slash"></i>
                                        <p>No staff members found matching your criteria.</p>
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

<?php
$stmt->close();
$conn->close();
?>