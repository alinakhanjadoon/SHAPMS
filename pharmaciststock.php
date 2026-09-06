<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'pharmacist') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli('localhost', 'root', '', 'SHAPMS');
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

/* ---------- FETCH MEDICINES ---------- */
$search = trim($_GET['search'] ?? '');
$query = "SELECT * FROM medicines WHERE 1";
$params = [];
$types = "";

if ($search !== '') {
    $query .= " AND (name LIKE ? OR description LIKE ?)";
    $search_param = "%$search%";
    $params[] = &$search_param;
    $params[] = &$search_param;
    $types .= "ss";
}

$query .= " ORDER BY name ASC";

$stmt = $conn->prepare($query);
if ($params) {
    array_unshift($params, $types);
    call_user_func_array([$stmt, 'bind_param'], $params);
}
$stmt->execute();
$result = $stmt->get_result();

/* ---------- STOCK STATS ---------- */
$total_medicines = $conn->query("SELECT COUNT(*) FROM medicines")->fetch_row()[0] ?? 0;
$total_quantity = $conn->query("SELECT SUM(quantity) FROM medicines")->fetch_row()[0] ?? 0;
$total_value = $conn->query("SELECT SUM(quantity*price) FROM medicines")->fetch_row()[0] ?? 0;
$low_stock = $conn->query("SELECT COUNT(*) FROM medicines WHERE quantity<=10")->fetch_row()[0] ?? 0;
$expired = $conn->query("SELECT COUNT(*) FROM medicines WHERE expiry_date<CURDATE()")->fetch_row()[0] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pharmacy Stock | Earthen Luxe</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ────────── EARTHEN LUXE PALETTE ────────── */
/* ────────── DARK NAVY / BLUE PALETTE ────────── */
:root {
  --alabaster:    #0A0F22;
  --cream:        #131A30;
  --silken:       #5C6A91;
  --taupe:        #94A3C2;
  --moss:         #3B82F6;
  --moss-deep:    #2563EB;
  --juniper:      #1E3A8A;
  --juniper-dark: #16213E;
  --onyx:         #F1F5F9;
  --bronze:       #38BDF8;
  --bronze-light: #7DD3FC;
  --mint:         #34D399;
  --amber:        #FBBF24;
  --danger:       #F87171;
  --border:       #1F2A4A;
  --shadow-sm:    0 4px 20px rgba(0,0,0,0.35);
  --shadow-md:    0 8px 30px rgba(0,0,0,0.45);
  --shadow-lg:    0 15px 35px rgba(0,0,0,0.55);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Inter', sans-serif;
  background: radial-gradient(circle at 20% 0%, #142049 0%, #0A0F22 45%, #070A16 100%);
  color: var(--onyx);
  padding: 28px 32px;
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
}

/* Decorative floating glow orbs (pure visual, no logic) */
.deco-bubble {
  position: fixed;
  border-radius: 50%;
  pointer-events: none;
  z-index: 0;
  opacity: 0.28;
  filter: blur(2px);
  animation: floatGlow 9s ease-in-out infinite;
}
.db1 { width:220px;height:220px; background:radial-gradient(circle,#3B82F6,transparent); top:-60px; right:120px; }
.db2 { width:150px;height:150px; background:radial-gradient(circle,#38BDF8,transparent); bottom:60px; right:40px; animation-delay: 1.5s; }
.db3 { width:110px;height:110px; background:radial-gradient(circle,#34D399,transparent); bottom:240px; left:280px; animation-delay: 3s; }
@keyframes floatGlow {
  0%, 100% { transform: translateY(0px); }
  50% { transform: translateY(-18px); }
}

.header, .search-bar, .stat-card, .chart-card, .value-card, .table-card {
  position: relative;
  z-index: 1;
}

/* Header */
.header {
  background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 55%, #38BDF8 100%);
  color: var(--onyx);
  padding: 32px 36px;
  border-radius: 24px;
  margin-bottom: 32px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: var(--shadow-lg);
  position: relative;
  overflow: hidden;
}

.header::before {
  content: '📦';
  position: absolute;
  bottom: 15px;
  right: 25px;
  font-size: 80px;
  opacity: 0.10;
  pointer-events: none;
}

.header::after {
  content: '';
  position: absolute;
  top: -50px; left: 30%;
  width: 180px; height: 180px;
  background: rgba(255,255,255,0.08);
  border-radius: 50%;
  pointer-events: none;
}

.header h3 {
  font-family: 'Cormorant Garamond', serif;
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -0.01em;
  margin-bottom: 6px;
  color: #FFFFFF;
}

.header small {
  font-size: 13px;
  color: rgba(255,255,255,0.80);
  letter-spacing: 0.03em;
}

.btn-dashboard {
  background: rgba(255,255,255,0.14);
  border: 1px solid rgba(255,255,255,0.28);
  color: #FFFFFF;
  padding: 10px 24px;
  border-radius: 40px;
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  transition: all 0.25s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  backdrop-filter: blur(6px);
}

.btn-dashboard:hover {
  background: var(--bronze);
  border-color: var(--bronze);
  color: #0A0F22;
  transform: translateY(-2px);
}

/* Search Bar */
.search-bar {
  background: var(--cream);
  border-radius: 20px;
  padding: 20px 24px;
  margin-bottom: 28px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
}

.search-input {
  background: #0E1530;
  border: 1px solid var(--border);
  border-radius: 40px;
  padding: 12px 20px;
  font-size: 13px;
  color: var(--onyx);
  transition: all 0.2s;
}

.search-input::placeholder { color: var(--silken); }

.search-input:focus {
  border-color: var(--bronze);
  box-shadow: 0 0 0 3px rgba(56,189,248,0.20);
  background: #0E1530;
  color: var(--onyx);
}

.btn-search {
  background: linear-gradient(135deg, var(--moss), var(--bronze));
  border: none;
  color: white;
  padding: 12px 28px;
  border-radius: 40px;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.25s;
  box-shadow: 0 6px 18px rgba(59,130,246,0.35);
}

.btn-search:hover {
  filter: brightness(1.1);
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(56,189,248,0.45);
}

/* Stat Cards */
.stat-card {
  background: var(--cream);
  border-radius: 20px;
  padding: 24px 20px;
  text-align: center;
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: var(--accent);
}

.stat-card::after {
  content: '';
  position: absolute;
  top: -30px; right: -30px;
  width: 90px; height: 90px;
  border-radius: 50%;
  background: var(--accent);
  opacity: 0.10;
}

.stat-card:hover {
  transform: translateY(-6px) scale(1.02);
  box-shadow: var(--shadow-md);
  border-color: var(--accent);
}

.stat-card.green { --accent: var(--mint); }
.stat-card.bronze { --accent: var(--amber); }
.stat-card.taupe { --accent: var(--danger); }
.stat-card.juniper { --accent: var(--moss); }

.stat-icon {
  width: 54px;
  height: 54px;
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 12px;
  font-size: 24px;
  position: relative;
  z-index: 1;
}

.stat-card.green .stat-icon { background: rgba(52,211,153,0.14); color: var(--mint); }
.stat-card.bronze .stat-icon { background: rgba(251,191,36,0.14); color: var(--amber); }
.stat-card.taupe .stat-icon { background: rgba(248,113,113,0.14); color: var(--danger); }
.stat-card.juniper .stat-icon { background: rgba(59,130,246,0.14); color: var(--moss); }

.stat-number {
  font-family: 'Cormorant Garamond', serif;
  font-size: 38px;
  font-weight: 700;
  color: var(--onyx);
  line-height: 1.1;
  margin-bottom: 6px;
}

.stat-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--taupe);
}

/* Chart Cards */
.chart-card {
  background: var(--cream);
  border-radius: 20px;
  padding: 20px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
  height: 100%;
  transition: all 0.25s;
}
.chart-card:hover {
  transform: translateY(-4px);
  box-shadow: var(--shadow-md);
  border-color: var(--moss);
}

.chart-title {
  font-family: 'Cormorant Garamond', serif;
  font-size: 16px;
  font-weight: 600;
  color: var(--bronze-light);
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.chart-title i {
  color: var(--bronze);
}

.value-card {
  background: linear-gradient(160deg, var(--cream) 0%, #16213E 100%);
  border-radius: 20px;
  padding: 28px;
  text-align: center;
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: center;
  position: relative;
  overflow: hidden;
}

.value-card::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 140px; height: 140px;
  background: radial-gradient(circle, rgba(56,189,248,0.18), transparent);
  border-radius: 50%;
}

.value-title {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--taupe);
  margin-bottom: 12px;
  position: relative;
  z-index: 1;
}

.value-amount {
  font-family: 'Cormorant Garamond', serif;
  font-size: 42px;
  font-weight: 700;
  background: linear-gradient(135deg, #60A5FA, #38BDF8);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  margin-bottom: 8px;
  position: relative;
  z-index: 1;
}

.value-sub {
  font-size: 12px;
  color: var(--silken);
  position: relative;
  z-index: 1;
}

/* Table Card */
.table-card {
  background: var(--cream);
  border-radius: 24px;
  border: 1px solid var(--border);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  margin-top: 28px;
}

.table-header {
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(90deg, rgba(59,130,246,0.08), transparent);
}

.table-header h5 {
  font-family: 'Cormorant Garamond', serif;
  font-size: 18px;
  font-weight: 600;
  color: var(--bronze-light);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.table-header h5 i {
  color: var(--bronze);
}

.stock-table {
  width: 100%;
  border-collapse: collapse;
}

.stock-table thead th {
  background: #0E1530;
  padding: 14px 16px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--taupe);
  border-bottom: 1px solid var(--border);
}

.stock-table tbody td {
  padding: 14px 16px;
  font-size: 13px;
  border-bottom: 1px solid var(--border);
  color: var(--onyx);
}

.stock-table tbody tr {
  transition: background 0.2s;
}

.stock-table tbody tr:hover {
  background: rgba(59,130,246,0.08);
}

.stock-table tbody tr.low-stock-row {
  background: rgba(251,191,36,0.07);
}

.stock-table tbody tr.expired-row {
  background: rgba(248,113,113,0.07);
}

.status-badge {
  display: inline-block;
  padding: 5px 14px;
  border-radius: 40px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.03em;
}

.status-available {
  background: rgba(52,211,153,0.15);
  color: var(--mint);
}

.status-low {
  background: rgba(251,191,36,0.15);
  color: var(--amber);
}

.status-expired {
  background: rgba(248,113,113,0.15);
  color: var(--danger);
}

.medicine-name {
  font-weight: 600;
  color: var(--bronze-light);
}

.text-warning { color: var(--amber) !important; }
.text-danger { color: var(--danger) !important; }

/* Responsive */
@media (max-width: 768px) {
  body {
    padding: 16px;
  }
  .header {
    flex-direction: column;
    text-align: center;
    gap: 16px;
    padding: 24px;
  }
  .header::before {
    display: none;
  }
  .stock-table thead th,
  .stock-table tbody td {
    padding: 10px 12px;
  }
}
</style>
</head>

<body>
<div class="deco-bubble db1"></div>
<div class="deco-bubble db2"></div>
<div class="deco-bubble db3"></div>
<!-- HEADER -->
<div class="header">
    <div>
        <h3><i class="fa-solid fa-warehouse"></i> Pharmacy Stock</h3>
        <small>Real-time medicine inventory & stock management</small>
    </div>
    <a href="pharmacistdashboard.php" class="btn-dashboard">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- SEARCH BAR -->
<div class="search-bar">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-8">
            <label class="form-label" style="font-size: 12px; font-weight: 600; color: var(--taupe); margin-bottom: 6px;">
                <i class="fa-solid fa-search"></i> Search Medicine
            </label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control search-input" placeholder="Search by medicine name or description...">
        </div>
        <div class="col-md-4">
            <button class="btn-search w-100"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </div>
    </form>
</div>

<!-- STATS CARDS -->
<div class="row g-4 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-pills"></i></div>
            <div class="stat-number"><?= $total_medicines ?></div>
            <div class="stat-label">Total Medicines</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card juniper">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div class="stat-number"><?= number_format($total_quantity) ?></div>
            <div class="stat-label">Total Units</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bronze">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-number"><?= $low_stock ?></div>
            <div class="stat-label">Low Stock Items</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card taupe">
            <div class="stat-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
            <div class="stat-number"><?= $expired ?></div>
            <div class="stat-label">Expired Items</div>
        </div>
    </div>
</div>

<!-- CHARTS + VALUE SECTION -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="chart-card">
            <div class="chart-title">
                <i class="fa-solid fa-chart-pie"></i> Stock Distribution
            </div>
            <canvas id="stockChart" style="max-height: 260px;"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="value-card">
            <div class="value-title">
                <i class="fa-solid fa-chart-line"></i> Total Inventory Value
            </div>
            <div class="value-amount">Rs <?= number_format($total_value, 2) ?></div>
            <div class="value-sub">Current market valuation</div>
            <hr style="width: 60px; margin: 16px auto; background: var(--silken); height: 1px; opacity: 0.3;">
            <small style="color: var(--taupe);">
                <i class="fa-regular fa-clock"></i> Updated in real-time
            </small>
        </div>
    </div>
</div>

<!-- STOCK TABLE -->
<div class="table-card">
    <div class="table-header">
        <h5><i class="fa-solid fa-clipboard-list"></i> Medicine Inventory Details</h5>
    </div>
    <div style="overflow-x: auto;">
        <table class="stock-table">
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th>Description</th>
                    <th>Qty (Units)</th>
                    <th>Price (Rs)</th>
                    <th>Stock Value</th>
                    <th>Expiry Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php while($m = $result->fetch_assoc()):
                $expiredFlag = ($m['expiry_date'] && strtotime($m['expiry_date']) < time());
                $lowFlag = ($m['quantity'] <= 10);
                $critical = ($m['quantity'] == 0);
                $rowClass = $expiredFlag ? "expired-row" : ($lowFlag ? "low-stock-row" : "");
            ?>
            <tr class="<?= $rowClass ?>">
                <td class="medicine-name"><?= htmlspecialchars($m['name']) ?></td>
                <td style="max-width: 200px;"><?= htmlspecialchars(substr($m['description'], 0, 50)) ?><?= strlen($m['description']) > 50 ? '...' : '' ?></td>
                <td class="<?= $critical ? 'text-danger' : ($lowFlag ? 'text-warning' : '') ?>">
                    <strong><?= number_format($m['quantity']) ?></strong>
                </td>
                <td>Rs <?= number_format($m['price'], 2) ?></td>
                <td>Rs <?= number_format($m['quantity'] * $m['price'], 2) ?></td>
                <td><?= $m['expiry_date'] ? date('M d, Y', strtotime($m['expiry_date'])) : 'N/A' ?></td>
                <td>
                    <?php if($expiredFlag): ?>
                        <span class="status-badge status-expired"><i class="fa-regular fa-calendar-xmark"></i> Expired</span>
                    <?php elseif($critical): ?>
                        <span class="status-badge status-expired"><i class="fa-solid fa-skull"></i> Out of Stock</span>
                    <?php elseif($lowFlag): ?>
                        <span class="status-badge status-low"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock</span>
                    <?php else: ?>
                        <span class="status-badge status-available"><i class="fa-regular fa-circle-check"></i> Available</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            
            <?php if ($result->num_rows === 0): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 48px;">
                    <i class="fa-solid fa-pills" style="font-size: 48px; color: var(--silken); margin-bottom: 12px; display: block;"></i>
                    <p style="color: var(--taupe);">No medicines found matching your search.</p>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Stock Distribution Chart
const available = <?= $total_medicines - $low_stock - $expired ?>;
const lowStock = <?= $low_stock ?>;
const expiredCount = <?= $expired ?>;

new Chart(document.getElementById('stockChart'), {
    type: 'doughnut',
    data: {
        labels: ['Available Stock', 'Low Stock Alert', 'Expired'],
        datasets: [{
            data: [available, lowStock, expiredCount],
            backgroundColor: ['#3B82F6', '#FBBF24', '#F87171'],
            borderColor: '#131A30',
            borderWidth: 3,
            cutout: '65%',
            hoverOffset: 8,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#94A3C2',
                    font: { size: 11, family: "'Inter', sans-serif" },
                    usePointStyle: true,
                    pointStyle: 'circle',
                    padding: 14
                }
            },
            tooltip: {
                backgroundColor: '#0E1530',
                titleColor: '#FFFFFF',
                bodyColor: '#94A3C2',
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.raw;
                        const total = available + lowStock + expiredCount;
                        const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                        return `${label}: ${value} items (${percent}%)`;
                    }
                }
            }
        }
    }
});
</script>

</body>
</html>