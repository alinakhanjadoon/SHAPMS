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

/* ---------- ADD MEDICINE ---------- */
if (isset($_POST['add_medicine'])) {

    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $expiry = $_POST['expiry_date'];

    $imagePath = "uploads/medicines/default.png";

    if (!empty($_FILES['image']['name'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imagePath = "uploads/medicines/" . time() . "_" . rand(1000,9999) . "." . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);
    }

    $stmt = $conn->prepare("
        INSERT INTO medicines (name, description, quantity, price, expiry_date, image)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssidss", $name, $description, $quantity, $price, $expiry, $imagePath);
    $stmt->execute();
    $stmt->close();

    header("Location: pharmacistmedicine.php");
    exit();
}

/* ---------- EDIT MEDICINE ---------- */
if (isset($_POST['edit_medicine'])) {
    $id = (int)$_POST['medicine_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $expiry = $_POST['expiry_date'];

    $stmt = $conn->prepare("SELECT image FROM medicines WHERE medicine_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $oldImage = $stmt->get_result()->fetch_assoc()['image'];
    $stmt->close();

    $imagePath = $oldImage;
    if (!empty($_FILES['image']['name'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imagePath = "uploads/medicines/" . time() . "_" . rand(1000,9999) . "." . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);
    }

    $stmt = $conn->prepare("
        UPDATE medicines 
        SET name=?, description=?, quantity=?, price=?, expiry_date=?, image=? 
        WHERE medicine_id=?
    ");
    $stmt->bind_param("ssidssi", $name, $description, $quantity, $price, $expiry, $imagePath, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: pharmacistmedicine.php");
    exit();
}
/* ---------- DELETE MEDICINE ---------- */
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM medicines WHERE medicine_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: pharmacistmedicine.php");
    exit();
}

/* ---------- FETCH MEDICINES ---------- */
$medicines = $conn->query("SELECT * FROM medicines ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pharmacy Medicines | Earthen Luxe</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
  content: '⚕️';
  position: absolute;
  bottom: 15px;
  right: 25px;
  font-size: 80px;
  opacity: 0.10;
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
  color: rgba(255,255,255,0.78);
  letter-spacing: 0.03em;
}

.btn-dashboard {
  background: rgba(255,255,255,0.12);
  border: 1px solid rgba(255,255,255,0.25);
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
}

.btn-dashboard:hover {
  background: var(--bronze);
  border-color: var(--bronze);
  color: #0A0F22;
}

/* Form Card */
.form-card {
  background: var(--cream);
  border-radius: 24px;
  padding: 28px 32px;
  margin-bottom: 40px;
  border: 1px solid #1F2A4A;
  box-shadow: var(--shadow-sm);
}

.form-card h5 {
  font-family: 'Cormorant Garamond', serif;
  font-size: 22px;
  font-weight: 600;
  color: var(--bronze-light);
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.form-card h5 i {
  color: var(--bronze);
}

.form-label {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--taupe);
  margin-bottom: 6px;
}

.form-control, .form-select {
  background: #0E1530;
  border: 1px solid #1F2A4A;
  border-radius: 12px;
  padding: 10px 14px;
  font-size: 13px;
  color: var(--onyx);
  transition: all 0.2s;
}

.form-control::placeholder { color: var(--silken); }

.form-control:focus, .form-select:focus {
  border-color: var(--bronze);
  box-shadow: 0 0 0 3px rgba(56,189,248,0.20);
  background: #0E1530;
  color: var(--onyx);
}

.btn-submit {
  background: var(--moss);
  border: none;
  color: white;
  padding: 12px 28px;
  border-radius: 40px;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.03em;
  transition: all 0.25s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.btn-submit:hover {
  background: var(--moss-deep);
  transform: translateY(-2px);
}

/* Medicine Cards */
.medicine-card {
  background: var(--cream);
  border-radius: 24px;
  border: 1px solid #1F2A4A;
  overflow: hidden;
  transition: all 0.3s ease;
  box-shadow: var(--shadow-sm);
  height: 100%;
  position: relative;
}

.medicine-card:hover {
  transform: translateY(-6px);
  box-shadow: var(--shadow-md);
  border-color: var(--moss);
}

.badge-status {
  position: absolute;
  top: 16px;
  right: 16px;
  padding: 6px 14px;
  border-radius: 40px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  z-index: 10;
}

.badge-expired {
  background: #F87171;
  color: white;
}

.badge-lowstock {
  background: #FBBF24;
  color: #1E201E;
}

.badge-available {
  background: #34D399;
  color: #0A0F22;
}

.medicine-img {
  height: 180px;
  width: 100%;
  object-fit: contain;
  background: #0E1530;
  padding: 20px;
  border-bottom: 1px solid #1F2A4A;
}

.medicine-body {
  padding: 20px;
}

.medicine-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 18px;
  font-weight: 700;
  color: var(--bronze-light);
  margin-bottom: 6px;
}

.medicine-desc {
  font-size: 12px;
  color: var(--taupe);
  margin-bottom: 16px;
  line-height: 1.4;
}

.info-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 10px;
  font-size: 12px;
}

.info-label {
  color: var(--silken);
  font-weight: 500;
}

.info-value {
  font-weight: 600;
  color: var(--onyx);
}

.price-value {
  color: var(--bronze);
  font-weight: 700;
}

.divider {
  height: 1px;
  background: #1F2A4A;
  margin: 14px 0;
}

.btn-edit {
  background: rgba(56,189,248,0.12);
  border: 1px solid rgba(56,189,248,0.30);
  color: var(--bronze-light);
  padding: 8px 16px;
  border-radius: 40px;
  font-size: 12px;
  font-weight: 600;
  transition: all 0.2s;
}

.btn-edit:hover {
  background: var(--bronze);
  color: #0A0F22;
}

.btn-delete {
  background: rgba(94,106,145,0.12);
  border: 1px solid rgba(94,106,145,0.30);
  color: var(--taupe);
  padding: 8px 16px;
  border-radius: 40px;
  font-size: 12px;
  font-weight: 600;
  transition: all 0.2s;
}

.btn-delete:hover {
  background: #F87171;
  border-color: #F87171;
  color: white;
}

/* Modal Styling */
.modal-content {
  background: var(--cream);
  border-radius: 24px;
  border: 1px solid #1F2A4A;
  box-shadow: var(--shadow-lg);
}

.modal-header-custom {
  padding: 24px 28px;
  border-bottom: 1px solid #1F2A4A;
}

.modal-header-custom h5 {
  font-family: 'Cormorant Garamond', serif;
  font-size: 22px;
  font-weight: 600;
  color: var(--bronze-light);
}

.btn-close {
  filter: invert(1) grayscale(100%) brightness(1.8);
}

/* Grid spacing */
.row.g-4 {
  --bs-gutter-y: 1.5rem;
}

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
  .form-card {
    padding: 20px;
  }
}
</style>
</head>

<body>

<!-- HEADER -->
<div class="header">
    <div>
        <h3><i class="fa-solid fa-pills"></i> Pharmacy Medicines</h3>
        <small>Manage inventory, track expiry, and update stock</small>
    </div>
    <a href="pharmacistdashboard.php" class="btn-dashboard">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- ADD MEDICINE FORM -->
<div class="form-card">
    <h5><i class="fa-solid fa-plus-circle"></i> Add New Medicine</h5>
    <form method="post" enctype="multipart/form-data">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Medicine Name</label>
                <input type="text" name="name" class="form-control" required placeholder="e.g., Paracetamol">
            </div>
            <div class="col-md-3">
                <label class="form-label">Quantity</label>
                <input type="number" name="quantity" class="form-control" required placeholder="Units">
            </div>
            <div class="col-md-3">
                <label class="form-label">Price (Rs)</label>
                <input type="number" step="0.01" name="price" class="form-control" required placeholder="0.00">
            </div>
            <div class="col-md-2">
                <label class="form-label">Expiry Date</label>
                <input type="date" name="expiry_date" class="form-control" required>
            </div>
            <div class="col-md-8">
                <label class="form-label">Description / Dosage</label>
                <input type="text" name="description" class="form-control" required placeholder="Dosage instructions, composition...">
            </div>
            <div class="col-md-4">
                <label class="form-label">Medicine Image</label>
                <input type="file" name="image" class="form-control" accept="image/*">
            </div>
            <div class="col-12">
                <button class="btn-submit" name="add_medicine">
                    <i class="fa-solid fa-save"></i> Add Medicine
                </button>
            </div>
        </div>
    </form>
</div>

<!-- MEDICINES GRID -->
<div class="row g-4">
<?php while($m = $medicines->fetch_assoc()):
    $expired = strtotime($m['expiry_date']) < time();
    $low = $m['quantity'] <= 10;
    $critical = $m['quantity'] == 0;
?>
<div class="col-md-4 col-lg-3">
    <div class="medicine-card">
        
        <?php if($expired): ?>
            <span class="badge-status badge-expired"><i class="fa-regular fa-calendar-xmark"></i> Expired</span>
        <?php elseif($critical): ?>
            <span class="badge-status badge-expired" style="background: #9B8B7A;"><i class="fa-solid fa-skull"></i> Out of Stock</span>
        <?php elseif($low): ?>
            <span class="badge-status badge-lowstock"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock</span>
        <?php else: ?>
            <span class="badge-status badge-available"><i class="fa-regular fa-circle-check"></i> Available</span>
        <?php endif; ?>

        <img src="<?= htmlspecialchars($m['image']) ?>" class="medicine-img" alt="<?= htmlspecialchars($m['name']) ?>">

        <div class="medicine-body">
            <div class="medicine-name"><?= htmlspecialchars($m['name']) ?></div>
            <div class="medicine-desc"><?= htmlspecialchars($m['description']) ?></div>
            
            <div class="divider"></div>
            
            <div class="info-row">
                <span class="info-label"><i class="fa-solid fa-boxes"></i> Stock:</span>
                <span class="info-value <?= $low ? 'text-warning' : '' ?>"><?= number_format($m['quantity']) ?> units</span>
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fa-solid fa-tag"></i> Price:</span>
                <span class="info-value price-value">Rs <?= number_format($m['price'], 2) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fa-regular fa-calendar"></i> Expiry:</span>
                <span class="info-value <?= $expired ? 'text-danger' : '' ?>"><?= date('M d, Y', strtotime($m['expiry_date'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fa-solid fa-chart-line"></i> Inventory Value:</span>
                <span class="info-value">Rs <?= number_format($m['price'] * $m['quantity'], 2) ?></span>
            </div>
            
            <div class="divider"></div>
            
            <div class="d-flex justify-content-between gap-2">
                <button class="btn-edit flex-grow-1" data-bs-toggle="modal" data-bs-target="#editModal<?= $m['medicine_id'] ?>">
                    <i class="fa-solid fa-pen"></i> Edit
                </button>
                <a href="?delete_id=<?= $m['medicine_id'] ?>" class="btn-delete" onclick="return confirm('⚠️ Delete this medicine permanently?')">
                    <i class="fa-solid fa-trash"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- EDIT MODAL (Logic Unchanged) -->
<div class="modal fade" id="editModal<?= $m['medicine_id'] ?>" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header-custom">
        <h5><i class="fa-solid fa-pen-to-square"></i> Edit Medicine</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div style="padding: 24px 28px;">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="medicine_id" value="<?= $m['medicine_id'] ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Medicine Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($m['name']) ?>" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" value="<?= $m['quantity'] ?>" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Price (Rs)</label>
                    <input type="number" step="0.01" name="price" value="<?= $m['price'] ?>" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" value="<?= $m['expiry_date'] ?>" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Description / Dosage</label>
                    <input type="text" name="description" value="<?= htmlspecialchars($m['description']) ?>" class="form-control" required>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Change Image (optional)</label>
                    <input type="file" name="image" class="form-control" accept="image/*">
                    <small class="text-muted">Current: <?= basename($m['image']) ?></small>
                </div>
                <div class="col-12">
                    <button type="submit" name="edit_medicine" class="btn-submit w-100">
                        <i class="fa-solid fa-save"></i> Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php endwhile; ?>

<?php if ($medicines->num_rows === 0): ?>
<div class="col-12">
    <div class="text-center py-5" style="background: var(--cream); border-radius: 24px;">
        <i class="fa-solid fa-pills" style="font-size: 64px; color: var(--silken); margin-bottom: 16px;"></i>
        <p style="color: var(--taupe);">No medicines found. Add your first medicine above.</p>
    </div>
</div>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>