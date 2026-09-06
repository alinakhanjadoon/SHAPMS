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

/* ---------- TOTAL PRESCRIPTIONS AND REVENUE ---------- */
$prescriptions = $conn->query("SELECT * FROM prescriptions");
$total_prescriptions = $prescriptions->num_rows;

$total_revenue = 0;
$medicine_sales = [];

while ($pr = $prescriptions->fetch_assoc()) {
    $items = explode(",", $pr['prescription_details']);
    foreach ($items as $item) {
        $parts = explode(":", $item);
        if (count($parts) === 3) {
            [$medicine_name, $quantity, $price] = $parts;
            $quantity = (float)$quantity;
            $price = (float)$price;
            $total_revenue += $quantity * $price;

            $medicine_sales[$medicine_name] = ($medicine_sales[$medicine_name] ?? 0) + $quantity;
        }
    }
}

/* ---------- GET STOCK STATUS ---------- */
$stock = $conn->query("SELECT name, quantity, expiry_date FROM medicines ORDER BY name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pharmacy Dashboard | SHAPMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

<style>
/* ----- BODY & GENERAL ----- */
body {
    background: #f0f2f5;
    font-family: 'Poppins', sans-serif;
}
.container-fluid { padding: 2rem; }

/* ----- NAVBAR / DASHBOARD BUTTONS ----- */
.navbar-custom {
    background: #fff;
    border-radius: 1rem;
    padding: 0.5rem 1rem;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    margin-bottom: 2rem;
}
.navbar-custom a {
    color: #6c757d;
    font-weight: 500;
    margin-right: 1rem;
    padding: 0.5rem 1rem;
    border-radius: 0.75rem;
    transition: 0.3s;
    text-decoration: none;
}
.navbar-custom a.active,
.navbar-custom a:hover {
    background: #6366f1;
    color: #fff;
}

/* ----- DASHBOARD HEADER ----- */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}
.dashboard-header h2 {
    font-weight: 600;
    font-size: 1.8rem;
}
.dashboard-header small {
    color: #6c757d;
}

/* ----- STAT CARDS ----- */
.stat-card {
    border-radius: 1rem;
    padding: 2rem 1.5rem;
    color: #fff;
    position: relative;
    overflow: hidden;
    cursor: default;
    transition: transform 0.3s, box-shadow 0.3s;
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}
.stat-card i {
    font-size: 2.5rem;
    position: absolute;
    top: 1rem;
    right: 1rem;
    opacity: 0.2;
}
.card-prescriptions { background: linear-gradient(135deg,#6366f1,#8b5cf6); }
.card-revenue { background: linear-gradient(135deg,#16a34a,#22c55e); }

/* ----- TABLES ----- */
.table-wrapper {
    background: #fff;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: 0 8px 25px rgba(0,0,0,0.05);
}
.table th, .table td { vertical-align: middle; }
.status-badge {
    padding: 0.4rem 0.9rem;
    border-radius: 1rem;
    font-weight: 500;
    font-size: 0.85rem;
}
.status-available { background-color: #d1fae5; color: #065f46; }
.status-low { background-color: #fef3c7; color: #78350f; }
.status-expired { background-color: #fee2e2; color: #991b1b; }

/* ----- CHART CARD ----- */
.chart-card {
    background: #fff;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: 0 8px 25px rgba(0,0,0,0.05);
}

/* ----- RESPONSIVE ----- */
@media (max-width: 768px){
    .dashboard-header { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
    .navbar-custom { overflow-x: auto; white-space: nowrap; }
}
</style>
</head>
<body>

<div class="container-fluid">

    <!-- DASHBOARD NAV BUTTONS -->
    <div class="navbar-custom d-flex mb-4">
        <a href="pharmacistdashboard.php" class="active"><i class="fa-solid fa-gauge me-1"></i> Dashboard</a>
      
    </div>

    <!-- DASHBOARD HEADER -->
    <div class="dashboard-header">
        <h2><i class="fa-solid fa-pills me-2"></i>Pharmacy Dashboard</h2>
        <small><?= date('l, F j, Y') ?></small>
    </div>

    <!-- STAT CARDS -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card card-prescriptions text-center">
                <h6>Total Prescriptions</h6>
                <h3 class="mt-2"><?= $total_prescriptions ?></h3>
                <i class="fa-solid fa-file-prescription"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card card-revenue text-center">
                <h6>Total Revenue</h6>
                <h3 class="mt-2">Rs <?= number_format($total_revenue,2) ?></h3>
                <i class="fa-solid fa-money-bill-wave"></i>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6 class="text-muted mb-3">Top Medicines Sold</h6>
                <canvas id="medicineChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <!-- STOCK TABLE -->
    <div class="table-wrapper mb-4">
        <h5 class="mb-3">Medicine Stock Status</h5>
        <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th>Quantity</th>
                    <th>Expiry Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($m = $stock->fetch_assoc()):
                $status_class = 'status-available';
                $status_text = 'Available';

                if ($m['quantity'] <= 10) {
                    $status_class = 'status-low';
                    $status_text = 'Low Stock';
                }

                if ($m['expiry_date'] && strtotime($m['expiry_date']) < time()) {
                    $status_class = 'status-expired';
                    $status_text = 'Expired';
                }
            ?>
                <tr>
                    <td><?= htmlspecialchars($m['name']) ?></td>
                    <td><?= $m['quantity'] ?></td>
                    <td><?= $m['expiry_date'] ?: 'N/A' ?></td>
                    <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>

<script>
// Chart: Top Medicines Sold
const ctx = document.getElementById('medicineChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($medicine_sales)) ?>,
        datasets: [{
            label: 'Quantity Sold',
            data: <?= json_encode(array_values($medicine_sales)) ?>,
            backgroundColor: '#6366f1'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: { display: false }
        },
        scales: {
            y: { beginAtZero: true },
            x: { ticks: { autoSkip: false } }
        }
    }
});
</script>

</body>
</html>