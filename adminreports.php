<?php
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$report_type = $_GET['report_type'] ?? 'appointments';
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$doctor = $_GET['doctor'] ?? '';
$department = $_GET['department'] ?? '';
$search = trim($_GET['search'] ?? '');

$reports = [];
$total_records = 0;
$statusCounts = [];   // generic bucket, keyed by label, used for the chart
$chart_title  = '';

/* ================= REAL-TIME SUMMARY CARDS (always live, independent of filters) ================= */
$totalPatients     = $conn->query("SELECT COUNT(*) total FROM patients")->fetch_assoc()['total'];
$totalAppointments = $conn->query("SELECT COUNT(*) total FROM appointments")->fetch_assoc()['total'];
$totalDoctors      = $conn->query("SELECT COUNT(*) total FROM users WHERE role='doctor' AND status='active'")->fetch_assoc()['total'];
$totalStaff        = $conn->query("SELECT COUNT(*) total FROM users WHERE role IN ('doctor','nurse','pharmacist','receptionist') AND status='active'")->fetch_assoc()['total'];

$lowStockCount   = $conn->query("SELECT COUNT(*) total FROM medicines WHERE quantity <= min_stock")->fetch_assoc()['total'];
$expiredMeds     = $conn->query("SELECT COUNT(*) total FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetch_assoc()['total'];
$pharmacyRevenue = $conn->query("SELECT COALESCE(SUM(total_amount),0) total FROM pharmacy_bills WHERE payment_status='paid'")->fetch_assoc()['total'];
$pendingPO       = $conn->query("SELECT COUNT(*) total FROM purchase_orders WHERE status='pending'")->fetch_assoc()['total'];
$billingPaid     = $conn->query("SELECT COALESCE(SUM(amount+doctor_fee),0) total FROM billing WHERE payment_status='paid'")->fetch_assoc()['total'];
$billingUnpaid   = $conn->query("SELECT COALESCE(SUM(amount+doctor_fee),0) total FROM billing WHERE payment_status='unpaid'")->fetch_assoc()['total'];
$pendingLab      = $conn->query("SELECT COUNT(*) total FROM lab_reports WHERE status='pending'")->fetch_assoc()['total'];

/* DROPDOWNS */
$doctorList = [];
$dq = $conn->query("
    SELECT doc.doctor_id, u.full_name
    FROM doctors doc
    JOIN users u ON doc.user_id = u.user_id
    WHERE u.status='active'
    ORDER BY u.full_name
");
while($row=$dq->fetch_assoc()){ $doctorList[]=$row; }

$departments=[];
$dep=$conn->query("SELECT department_id,department_name FROM departments ORDER BY department_name");
while($row=$dep->fetch_assoc()){ $departments[]=$row; }

/* ================= APPOINTMENT REPORT ================= */
if($report_type=="appointments"){
    $sql="SELECT a.appointment_id, u.full_name AS patient_name, du.full_name AS doctor_name,
          dep.department_name, a.appointment_date, a.status, a.reason
          FROM appointments a
          JOIN patients p ON a.patient_id=p.patient_id
          JOIN users u ON p.user_id=u.user_id
          JOIN doctors doc ON a.doctor_id=doc.doctor_id
          JOIN users du ON doc.user_id=du.user_id
          LEFT JOIN departments dep ON du.department_id=dep.department_id
          WHERE DATE(a.appointment_date) BETWEEN ? AND ?";

    $params=[$from,$to];
    $types="ss";

    if($status!==""){ $sql.=" AND a.status=?"; $types.="s"; $params[]=$status; }
    if($doctor!==""){ $sql.=" AND a.doctor_id=?"; $types.="i"; $params[]=$doctor; }
    if($department!==""){ $sql.=" AND dep.department_id=?"; $types.="i"; $params[]=$department; }
    if($search!==""){ $sql.=" AND (u.full_name LIKE ? OR du.full_name LIKE ?)"; $types.="ss"; $params[]="%$search%"; $params[]="%$search%"; }

    $sql.=" ORDER BY a.appointment_date DESC";

    $stmt=$conn->prepare($sql);
    $stmt->bind_param($types,...$params);
    $stmt->execute();
    $result=$stmt->get_result();

    $statusCounts = ['scheduled'=>0,'approved'=>0,'completed'=>0,'cancelled'=>0];
    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $st = strtolower($row['status']);
        if(isset($statusCounts[$st])) $statusCounts[$st]++;
    }
    $total_records=count($reports);
    $chart_title = "Appointments by Status";
}

/* ================= PATIENT REPORT ================= */
if($report_type=="patients"){
    $sql="SELECT u.full_name, u.email, p.age, p.gender, p.contact, p.`condition`, p.clinical_status
          FROM patients p JOIN users u ON p.user_id=u.user_id";
    $conds=[]; $params=[]; $types="";
    if($search!==""){ $conds[]="(u.full_name LIKE ? OR p.`condition` LIKE ?)"; $types.="ss"; $params[]="%$search%"; $params[]="%$search%"; }
    if($status!==""){ $conds[]="p.clinical_status=?"; $types.="s"; $params[]=$status; }
    if($conds) $sql.=" WHERE ".implode(" AND ",$conds);
    $sql.=" ORDER BY u.full_name";

    if($params){ $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result(); }
    else { $result=$conn->query($sql); }

    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $cs = $row['clinical_status'] ?: 'stable';
        $statusCounts[$cs] = ($statusCounts[$cs] ?? 0) + 1;
    }
    $total_records=count($reports);
    $chart_title = "Patients by Clinical Status";
}

/* ================= STAFF REPORT ================= */
if($report_type=="staff"){
    $sql="SELECT full_name, role, email, contact, status, created_at FROM users WHERE role!='patient'";
    $conds=[]; $params=[]; $types="";
    if($search!==""){ $conds[]="full_name LIKE ?"; $types.="s"; $params[]="%$search%"; }
    if($status!==""){ $conds[]="role=?"; $types.="s"; $params[]=$status; }
    if($conds) $sql.=" AND ".implode(" AND ",$conds);
    $sql.=" ORDER BY role, full_name";

    if($params){ $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result(); }
    else { $result=$conn->query($sql); }

    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $statusCounts[$row['role']] = ($statusCounts[$row['role']] ?? 0) + 1;
    }
    $total_records=count($reports);
    $chart_title = "Staff by Role";
}

/* ================= PHARMACY INVENTORY REPORT ================= */
if($report_type=="pharmacy_inventory"){
    $sql="SELECT medicine_id, name, batch_no, quantity, min_stock, expiry_date, price, controlled_drug FROM medicines";
    $conds=[]; $params=[]; $types="";
    if($search!==""){ $conds[]="name LIKE ?"; $types.="s"; $params[]="%$search%"; }
    if($status==="low_stock"){ $conds[]="quantity <= min_stock"; }
    elseif($status==="expired"){ $conds[]="expiry_date < CURDATE()"; }
    elseif($status==="controlled"){ $conds[]="controlled_drug = 1"; }
    if($conds) $sql.=" WHERE ".implode(" AND ",$conds);
    $sql.=" ORDER BY name";

    if($params){ $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result(); }
    else { $result=$conn->query($sql); }

    $okN=0; $lowN=0; $expN=0; $invValue=0;
    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $invValue += $row['quantity']*$row['price'];
        $isExpired = $row['expiry_date'] && $row['expiry_date'] < date('Y-m-d');
        $isLow = $row['quantity'] <= $row['min_stock'];
        if($isExpired) $expN++;
        elseif($isLow) $lowN++;
        else $okN++;
    }
    $total_records=count($reports);
    $statusCounts = ['In Stock'=>$okN, 'Low Stock'=>$lowN, 'Expired'=>$expN];
    $chart_title = "Inventory Health";
    $inventoryValue = $invValue;
}

/* ================= PHARMACY SALES REPORT ================= */
if($report_type=="pharmacy_sales"){
    $sql="SELECT pb.bill_id, u.full_name AS patient_name, pb.pharmacist_id, pb.subtotal,
          pb.discount_amount, pb.total_amount, pb.payment_status, pb.payment_method, pb.created_at
          FROM pharmacy_bills pb
          JOIN patients p ON pb.patient_id=p.patient_id
          JOIN users u ON p.user_id=u.user_id
          WHERE DATE(pb.created_at) BETWEEN ? AND ?";
    $params=[$from,$to]; $types="ss";
    if($status!==""){ $sql.=" AND pb.payment_status=?"; $types.="s"; $params[]=$status; }
    if($search!==""){ $sql.=" AND u.full_name LIKE ?"; $types.="s"; $params[]="%$search%"; }
    $sql.=" ORDER BY pb.created_at DESC";

    $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result();

    $paidTotal=0; $unpaidTotal=0;
    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        if($row['payment_status']==='paid') $paidTotal += $row['total_amount'];
        else $unpaidTotal += $row['total_amount'];
    }
    $total_records=count($reports);
    $statusCounts = ['Paid'=>round($paidTotal,2), 'Unpaid'=>round($unpaidTotal,2)];
    $chart_title = "Pharmacy Revenue (PKR): Paid vs Unpaid";
}

/* ================= PATIENT BILLING REPORT ================= */
if($report_type=="billing"){
    $sql="SELECT b.bill_id, u.full_name AS patient_name, b.amount, b.doctor_fee, b.payment_status,
          b.payment_method, b.verification_status, b.payment_date, b.created_at
          FROM billing b JOIN patients p ON b.patient_id=p.patient_id JOIN users u ON p.user_id=u.user_id
          WHERE DATE(b.created_at) BETWEEN ? AND ?";
    $params=[$from,$to]; $types="ss";
    if($status!==""){ $sql.=" AND b.payment_status=?"; $types.="s"; $params[]=$status; }
    if($search!==""){ $sql.=" AND u.full_name LIKE ?"; $types.="s"; $params[]="%$search%"; }
    $sql.=" ORDER BY b.created_at DESC";

    $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result();

    $paidAmt=0; $unpaidAmt=0;
    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $tot = $row['amount'] + $row['doctor_fee'];
        if($row['payment_status']==='paid') $paidAmt += $tot;
        else $unpaidAmt += $tot;
    }
    $total_records=count($reports);
    $statusCounts = ['Paid'=>round($paidAmt,2), 'Unpaid'=>round($unpaidAmt,2)];
    $chart_title = "Patient Billing (PKR): Paid vs Unpaid";
}

/* ================= PURCHASE ORDERS REPORT ================= */
if($report_type=="purchase_orders"){
    $sql="SELECT po.po_id, s.name AS supplier_name, po.medicine_name, po.quantity, po.price,
          po.status, po.is_emergency, po.payment_status, po.created_at
          FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.supplier_id
          WHERE DATE(po.created_at) BETWEEN ? AND ?";
    $params=[$from,$to]; $types="ss";
    if($status!==""){ $sql.=" AND po.status=?"; $types.="s"; $params[]=$status; }
    if($search!==""){ $sql.=" AND (po.medicine_name LIKE ? OR s.name LIKE ?)"; $types.="ss"; $params[]="%$search%"; $params[]="%$search%"; }
    $sql.=" ORDER BY po.created_at DESC";

    $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $st = strtolower($row['status']);
        $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
    }
    $total_records=count($reports);
    $chart_title = "Purchase Orders by Status";
}

/* ================= LAB REPORTS ================= */
if($report_type=="lab_reports"){
    $sql="SELECT lr.report_id, u.full_name AS patient_name, lr.test_name, lr.status,
          lr.uploaded_at, lr.report_file, COALESCE(up.full_name,'—') AS uploaded_by_name
          FROM lab_reports lr
          JOIN patients p ON lr.patient_id=p.patient_id
          JOIN users u ON p.user_id=u.user_id
          LEFT JOIN users up ON lr.uploaded_by=up.user_id
          WHERE DATE(lr.uploaded_at) BETWEEN ? AND ?";
    $params=[$from,$to]; $types="ss";
    if($status!==""){ $sql.=" AND lr.status=?"; $types.="s"; $params[]=$status; }
    if($search!==""){ $sql.=" AND (u.full_name LIKE ? OR lr.test_name LIKE ?)"; $types.="ss"; $params[]="%$search%"; $params[]="%$search%"; }
    $sql.=" ORDER BY lr.uploaded_at DESC";

    $stmt=$conn->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $reports[]=$row;
        $st = strtolower($row['status']);
        $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
    }
    $total_records=count($reports);
    $chart_title = "Lab Reports by Status";
}

/* ================= CSV EXPORT ================= */
if(isset($_GET['export']) && $_GET['export']==='csv'){
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$report_type.'_report_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');

    if($report_type=="appointments"){
        fputcsv($out, ['ID','Patient','Doctor','Department','Date','Status','Reason']);
        foreach($reports as $r){ fputcsv($out, [$r['appointment_id'],$r['patient_name'],$r['doctor_name'],$r['department_name'],$r['appointment_date'],$r['status'],$r['reason']]); }
    } elseif($report_type=="patients"){
        fputcsv($out, ['Name','Email','Age','Gender','Contact','Condition','Clinical Status']);
        foreach($reports as $r){ fputcsv($out, [$r['full_name'],$r['email'],$r['age'],$r['gender'],$r['contact'],$r['condition'],$r['clinical_status']]); }
    } elseif($report_type=="staff"){
        fputcsv($out, ['Name','Role','Email','Contact','Status','Joined']);
        foreach($reports as $r){ fputcsv($out, [$r['full_name'],$r['role'],$r['email'],$r['contact'],$r['status'],$r['created_at']]); }
    } elseif($report_type=="pharmacy_inventory"){
        fputcsv($out, ['Medicine','Batch No','Quantity','Min Stock','Expiry','Unit Price','Value','Controlled Drug']);
        foreach($reports as $r){ fputcsv($out, [$r['name'],$r['batch_no'],$r['quantity'],$r['min_stock'],$r['expiry_date'],$r['price'],$r['quantity']*$r['price'],$r['controlled_drug']?'Yes':'No']); }
    } elseif($report_type=="pharmacy_sales"){
        fputcsv($out, ['Bill ID','Patient','Subtotal','Discount','Total','Payment Status','Method','Date']);
        foreach($reports as $r){ fputcsv($out, [$r['bill_id'],$r['patient_name'],$r['subtotal'],$r['discount_amount'],$r['total_amount'],$r['payment_status'],$r['payment_method'],$r['created_at']]); }
    } elseif($report_type=="billing"){
        fputcsv($out, ['Bill ID','Patient','Amount','Doctor Fee','Total','Payment Status','Verification','Payment Date','Created']);
        foreach($reports as $r){ fputcsv($out, [$r['bill_id'],$r['patient_name'],$r['amount'],$r['doctor_fee'],$r['amount']+$r['doctor_fee'],$r['payment_status'],$r['verification_status'],$r['payment_date'],$r['created_at']]); }
    } elseif($report_type=="purchase_orders"){
        fputcsv($out, ['PO ID','Supplier','Medicine','Quantity','Price','Total','Status','Emergency','Payment Status','Date']);
        foreach($reports as $r){ fputcsv($out, [$r['po_id'],$r['supplier_name'],$r['medicine_name'],$r['quantity'],$r['price'],$r['quantity']*$r['price'],$r['status'],$r['is_emergency']?'Yes':'No',$r['payment_status'],$r['created_at']]); }
    } elseif($report_type=="lab_reports"){
        fputcsv($out, ['Report ID','Patient','Test','Status','Uploaded By','Uploaded At']);
        foreach($reports as $r){ fputcsv($out, [$r['report_id'],$r['patient_name'],$r['test_name'],$r['status'],$r['uploaded_by_name'],$r['uploaded_at']]); }
    }
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Reports</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --bg: #F5F0F8;
  --bg-mesh: radial-gradient(ellipse at 20% 10%, #FFE4E4 0%, transparent 50%),
             radial-gradient(ellipse at 80% 80%, #FFD6E0 0%, transparent 45%),
             radial-gradient(ellipse at 60% 20%, #FFECD2 0%, transparent 40%), #F5F0F8;
  --surface: rgba(255,255,255,0.75);
  --surface-s: rgba(255,255,255,0.55);
  --surface-b: rgba(255,255,255,0.92);
  --border: rgba(180,100,120,0.10);
  --border-s: rgba(180,100,120,0.18);
  --red: #C62A2A; --red-l:#E03E3E; --red-xl:#F06060; --red-2xl:#FFB3B3;
  --grad-1: linear-gradient(145deg,#FF6B6B 0%,#C62A2A 100%);
  --grad-2: linear-gradient(145deg,#FF8FA3 0%,#D63864 100%);
  --grad-3: linear-gradient(145deg,#FFB347 0%,#E07020 100%);
  --grad-4: linear-gradient(145deg,#7EC8E3 0%,#2B6CB0 100%);
  --grad-5: linear-gradient(145deg,#C77DFF 0%,#7B2CBF 100%);
  --grad-6: linear-gradient(145deg,#7ED6A5 0%,#2F9E5C 100%);
  --grad-7: linear-gradient(145deg,#FFD166 0%,#D69300 100%);
  --grad-8: linear-gradient(145deg,#A0C4FF 0%,#3D6FD4 100%);
  --text-1:#1A0A14; --text-2:#5A3550; --text-3:#9B7B90; --text-w:rgba(255,255,255,0.95); --text-wm:rgba(255,255,255,0.72);
  --sw:240px; --radius:22px; --radius-s:14px; --radius-pill:100px;
  --shadow-card:0 8px 32px rgba(180,60,80,0.12),0 2px 8px rgba(180,60,80,0.06);
  --shadow-soft:0 4px 20px rgba(0,0,0,0.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg-mesh);background-attachment:fixed;color:var(--text-1);min-height:100vh;}
.sidebar{position:fixed;left:0;top:0;width:var(--sw);height:100vh;background:var(--surface-b);border-right:1px solid var(--border-s);display:flex;flex-direction:column;z-index:200;}
.sb-logo{padding:20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);}
.sb-logo-mark{width:40px;height:40px;border-radius:12px;background:var(--grad-1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;}
.sb-logo-name{font-size:17px;font-weight:700;}
.sb-nav{flex:1;padding:14px 12px;}
.sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;color:var(--text-2);text-decoration:none;font-size:13px;font-weight:500;border-radius:var(--radius-s);margin-bottom:2px;}
.sb-link i{width:20px;color:var(--text-3);}
.sb-link:hover{background:rgba(198,42,42,0.07);color:var(--red);}
.sb-link.active{background:linear-gradient(135deg,rgba(198,42,42,0.12),rgba(224,62,62,0.06));color:var(--red);font-weight:700;}
.main{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column;}
.topbar{position:sticky;top:0;z-index:100;background:rgba(245,240,248,0.85);border-bottom:1px solid var(--border-s);padding:0 32px;height:68px;display:flex;align-items:center;justify-content:space-between;}
.content{flex:1;padding:28px 32px 48px;display:flex;flex-direction:column;gap:24px;}
.page-title{font-size:28px;font-weight:700;}
.float-cards-row{display:flex;gap:20px;flex-wrap:wrap;}
.float-card{border-radius:22px;padding:22px;color:var(--text-w);flex:1;min-width:180px;min-height:130px;display:flex;flex-direction:column;justify-content:space-between;}
.float-cards-row.row1 .float-card:nth-child(1){background:var(--grad-1);}
.float-cards-row.row1 .float-card:nth-child(2){background:var(--grad-2);}
.float-cards-row.row1 .float-card:nth-child(3){background:var(--grad-3);}
.float-cards-row.row1 .float-card:nth-child(4){background:var(--grad-4);}
.float-cards-row.row2 .float-card:nth-child(1){background:var(--grad-7);}
.float-cards-row.row2 .float-card:nth-child(2){background:var(--grad-6);}
.float-cards-row.row2 .float-card:nth-child(3){background:var(--grad-5);}
.float-cards-row.row2 .float-card:nth-child(4){background:var(--grad-8);}
.fc-label{font-size:10px;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--text-wm);}
.fc-value{font-size:30px;font-weight:700;}
.fc-sub{font-size:11px;color:var(--text-wm);margin-top:2px;}
.section-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);margin-bottom:-8px;}
.panel{background:var(--surface);border:1px solid var(--border-s);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow-card);}
.panel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.panel-title{font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.form-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;align-items:end;}
.form-grid label{font-size:11px;font-weight:700;color:var(--text-3);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;}
.form-grid select,.form-grid input{width:100%;padding:9px 12px;border-radius:var(--radius-s);border:1px solid var(--border-s);background:#fff;font-size:13px;}
.btn-action{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--grad-1);color:#fff;border:none;border-radius:var(--radius-pill);font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:10px 12px;color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid var(--border-s);}
td{padding:10px 12px;border-bottom:1px solid var(--border);}
.status{padding:4px 10px;border-radius:var(--radius-pill);font-size:11px;font-weight:700;display:inline-block;}
.status.scheduled{background:#FFF3CD;color:#856404;}
.status.approved{background:#D1ECF1;color:#0C5460;}
.status.completed{background:#D4EDDA;color:#155724;}
.status.cancelled{background:#F8D7DA;color:#721C24;}
.status.pending{background:#FFF3CD;color:#856404;}
.status.paid{background:#D4EDDA;color:#155724;}
.status.unpaid{background:#F8D7DA;color:#721C24;}
.status.delivered{background:#D4EDDA;color:#155724;}
.status.rejected{background:#F8D7DA;color:#721C24;}
.status.verified{background:#D1ECF1;color:#0C5460;}
.status.dispensed{background:#D4EDDA;color:#155724;}
.status.emergency{background:#F8D7DA;color:#721C24;}
.empty-row td{text-align:center;padding:30px;color:var(--text-3);}
.chart-wrap{position:relative;width:100%;height:280px;margin-top:16px;}
@media(max-width:680px){.chart-wrap{height:220px;}}
.print-header{display:none;}
.no-print{}
@media(max-width:1100px){.form-grid{grid-template-columns:repeat(2,1fr);}.float-card{min-width:calc(50% - 10px);}}
@media print {
  .sidebar, .topbar, form, .no-print { display:none !important; }
  .main{margin-left:0;}
  body{background:#fff;}
  .content{padding:0;}
  .panel{box-shadow:none;border:1px solid #ccc;break-inside:avoid;}
  .float-cards-row{display:none;}
  .print-header{display:block !important;margin-bottom:20px;}
  .print-header h2{font-size:20px;}
  .print-header p{font-size:12px;color:#555;margin-top:4px;}
}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sb-logo">
    <div class="sb-logo-mark"><i class="fa-solid fa-hospital"></i></div>
    <div class="sb-logo-name">SHAPMS</div>
  </div>
  <nav class="sb-nav">
    <a class="sb-link" href="admindashboard.php"><i class="fas fa-home"></i>Dashboard</a>
    <a class="sb-link" href="adminpatients.php"><i class="fas fa-user-injured"></i>Patients</a>
    <a class="sb-link" href="admindoctors.php"><i class="fas fa-user-doctor"></i>Doctors</a>
    <a class="sb-link" href="adminnurses.php"><i class="fas fa-user-nurse"></i>Nurses</a>
    <a class="sb-link" href="adminpharmacists.php"><i class="fas fa-pills"></i>Pharmacy</a>
    <a class="sb-link" href="adminreceptionists.php"><i class="fas fa-building"></i>Receptionists</a>
    <a class="sb-link active" href="adminreports.php"><i class="fas fa-chart-line"></i>Reports</a>
    <a class="sb-link" href="adminsettings.php"><i class="fas fa-cog"></i>Settings</a>
    <a class="sb-link" href="logout.php"><i class="fas fa-right-from-bracket"></i>Logout</a>
  </nav>
</aside>

<div class="main">
  <div class="topbar">
    <div><strong>Hospital Reports</strong></div>
    <div><i class="fa-solid fa-calendar-days"></i> <?= date("l, d M Y"); ?></div>
  </div>

  <div class="content">
    <div class="print-header">
      <h2>SHAPMS — <?= ucfirst(str_replace('_',' ',$report_type)) ?> Report</h2>
      <p><?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?> &middot; Generated <?= date('d M Y, h:i A') ?></p>
    </div>

    <div class="page-title">Reports</div>

    <div class="float-cards-row row1">
      <div class="float-card">
        <i class="fas fa-calendar-check"></i>
        <div><div class="fc-value"><?= $totalAppointments ?></div><div class="fc-label">Appointments</div></div>
      </div>
      <div class="float-card">
        <i class="fas fa-user-injured"></i>
        <div><div class="fc-value"><?= $totalPatients ?></div><div class="fc-label">Patients</div></div>
      </div>
      <div class="float-card">
        <i class="fas fa-user-doctor"></i>
        <div><div class="fc-value"><?= $totalDoctors ?></div><div class="fc-label">Active Doctors</div></div>
      </div>
      <div class="float-card">
        <i class="fas fa-users"></i>
        <div><div class="fc-value"><?= $totalStaff ?></div><div class="fc-label">Total Staff</div></div>
      </div>
    </div>

    <div class="float-cards-row row2">
      <div class="float-card">
        <i class="fas fa-triangle-exclamation"></i>
        <div><div class="fc-value"><?= $lowStockCount ?></div><div class="fc-label">Low Stock Medicines</div><div class="fc-sub"><?= $expiredMeds ?> expired</div></div>
      </div>
      <div class="float-card">
        <i class="fas fa-prescription-bottle-medical"></i>
        <div><div class="fc-value">PKR <?= number_format($pharmacyRevenue) ?></div><div class="fc-label">Pharmacy Revenue (Paid)</div></div>
      </div>
      <div class="float-card">
        <i class="fas fa-truck-fast"></i>
        <div><div class="fc-value"><?= $pendingPO ?></div><div class="fc-label">Pending Purchase Orders</div></div>
      </div>
      <div class="float-card">
        <i class="fas fa-file-invoice-dollar"></i>
        <div><div class="fc-value">PKR <?= number_format($billingPaid) ?></div><div class="fc-label">Patient Billing (Paid)</div><div class="fc-sub">PKR <?= number_format($billingUnpaid) ?> outstanding</div></div>
      </div>
    </div>

    <div class="panel no-print">
      <form method="GET">
        <div class="form-grid">
          <div>
            <label>Report Type</label>
            <select name="report_type" onchange="this.form.submit()">
              <option value="appointments" <?= $report_type=="appointments"?"selected":"" ?>>Appointment Report</option>
              <option value="patients" <?= $report_type=="patients"?"selected":"" ?>>Patient Report</option>
              <option value="staff" <?= $report_type=="staff"?"selected":"" ?>>Staff Report</option>
              <option value="pharmacy_inventory" <?= $report_type=="pharmacy_inventory"?"selected":"" ?>>Pharmacy Inventory</option>
              <option value="pharmacy_sales" <?= $report_type=="pharmacy_sales"?"selected":"" ?>>Pharmacy Sales</option>
              <option value="billing" <?= $report_type=="billing"?"selected":"" ?>>Patient Billing</option>
              <option value="purchase_orders" <?= $report_type=="purchase_orders"?"selected":"" ?>>Purchase Orders</option>
              <option value="lab_reports" <?= $report_type=="lab_reports"?"selected":"" ?>>Lab Reports</option>
            </select>
          </div>
          <div><label>From Date</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
          <div><label>To Date</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>

          <?php if($report_type==="appointments"): ?>
          <div>
            <label>Doctor</label>
            <select name="doctor">
              <option value="">All Doctors</option>
              <?php foreach($doctorList as $dd): ?>
                <option value="<?= $dd['user_id']; ?>" <?= $doctor==$dd['user_id']?'selected':''; ?>><?= htmlspecialchars($dd['full_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Department</label>
            <select name="department">
              <option value="">All Departments</option>
              <?php foreach($departments as $dp): ?>
                <option value="<?= $dp['department_id']; ?>" <?= $department==$dp['department_id']?'selected':''; ?>><?= htmlspecialchars($dp['department_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <div>
            <label>Search</label>
            <input type="text" name="search" placeholder="Name / medicine..." value="<?= htmlspecialchars($search) ?>">
          </div>
          <?php endif; ?>

          <div>
            <label>Status / Filter</label>
            <select name="status">
              <option value="">All</option>
              <?php if($report_type==='appointments'): ?>
                <option value="scheduled" <?= $status=="scheduled"?"selected":"" ?>>Scheduled</option>
                <option value="approved" <?= $status=="approved"?"selected":"" ?>>Approved</option>
                <option value="completed" <?= $status=="completed"?"selected":"" ?>>Completed</option>
                <option value="cancelled" <?= $status=="cancelled"?"selected":"" ?>>Cancelled</option>
              <?php elseif($report_type==='patients'): ?>
                <option value="critical" <?= $status=="critical"?"selected":"" ?>>Critical</option>
                <option value="moderate" <?= $status=="moderate"?"selected":"" ?>>Moderate</option>
                <option value="stable" <?= $status=="stable"?"selected":"" ?>>Stable</option>
                <option value="observation" <?= $status=="observation"?"selected":"" ?>>Observation</option>
              <?php elseif($report_type==='staff'): ?>
                <option value="doctor" <?= $status=="doctor"?"selected":"" ?>>Doctor</option>
                <option value="nurse" <?= $status=="nurse"?"selected":"" ?>>Nurse</option>
                <option value="pharmacist" <?= $status=="pharmacist"?"selected":"" ?>>Pharmacist</option>
                <option value="receptionist" <?= $status=="receptionist"?"selected":"" ?>>Receptionist</option>
                <option value="lab" <?= $status=="lab"?"selected":"" ?>>Lab</option>
                <option value="department_head" <?= $status=="department_head"?"selected":"" ?>>Department Head</option>
              <?php elseif($report_type==='pharmacy_inventory'): ?>
                <option value="low_stock" <?= $status=="low_stock"?"selected":"" ?>>Low Stock</option>
                <option value="expired" <?= $status=="expired"?"selected":"" ?>>Expired</option>
                <option value="controlled" <?= $status=="controlled"?"selected":"" ?>>Controlled Drugs</option>
              <?php elseif(in_array($report_type,['pharmacy_sales','billing'])): ?>
                <option value="paid" <?= $status=="paid"?"selected":"" ?>>Paid</option>
                <option value="unpaid" <?= $status=="unpaid"?"selected":"" ?>>Unpaid</option>
              <?php elseif($report_type==='purchase_orders'): ?>
                <option value="pending" <?= $status=="pending"?"selected":"" ?>>Pending</option>
                <option value="approved" <?= $status=="approved"?"selected":"" ?>>Approved</option>
                <option value="rejected" <?= $status=="rejected"?"selected":"" ?>>Rejected</option>
                <option value="delivered" <?= $status=="delivered"?"selected":"" ?>>Delivered</option>
              <?php elseif($report_type==='lab_reports'): ?>
                <option value="pending" <?= $status=="pending"?"selected":"" ?>>Pending</option>
                <option value="completed" <?= $status=="completed"?"selected":"" ?>>Completed</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <div style="margin-top:16px;"><button class="btn-action"><i class="fas fa-chart-bar"></i> Generate Report</button></div>
      </form>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fas fa-table"></i> Report Results (<?= $total_records ?>)</div>
        <div class="no-print" style="display:flex;gap:10px;">
          <button onclick="window.print()" class="btn-action"><i class="fas fa-print"></i> Print</button>
          <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn-action"><i class="fas fa-file-csv"></i> Export CSV</a>
        </div>
      </div>

      <?php if($report_type=="appointments"): ?>
      <table>
        <thead><tr><th>ID</th><th>Patient</th><th>Doctor</th><th>Department</th><th>Date</th><th>Status</th><th>Reason</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="7">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td><?= $row['appointment_id']; ?></td>
            <td><?= htmlspecialchars($row['patient_name']); ?></td>
            <td><?= htmlspecialchars($row['doctor_name']); ?></td>
            <td><?= htmlspecialchars($row['department_name'] ?? '—'); ?></td>
            <td><?= date("d M Y h:i A",strtotime($row['appointment_date'])); ?></td>
            <td><span class="status <?= strtolower($row['status']); ?>"><?= ucfirst($row['status']); ?></span></td>
            <td><?= htmlspecialchars($row['reason']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if($report_type=="patients"): ?>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Age</th><th>Gender</th><th>Contact</th><th>Condition</th><th>Clinical Status</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="7">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['full_name']); ?></td>
            <td><?= htmlspecialchars($row['email']); ?></td>
            <td><?= $row['age']; ?></td>
            <td><?= $row['gender']; ?></td>
            <td><?= htmlspecialchars($row['contact']); ?></td>
            <td><?= htmlspecialchars($row['condition']); ?></td>
            <td><span class="status <?= $row['clinical_status']=='critical'?'cancelled':($row['clinical_status']=='moderate'?'scheduled':($row['clinical_status']=='observation'?'approved':'completed')); ?>"><?= ucfirst($row['clinical_status']); ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if($report_type=="staff"): ?>
      <table>
        <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Contact</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="6">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['full_name']); ?></td>
            <td><?= ucfirst(str_replace("_"," ",$row['role'])); ?></td>
            <td><?= htmlspecialchars($row['email']); ?></td>
            <td><?= htmlspecialchars($row['contact']); ?></td>
            <td><span class="status <?= $row['status']=='active'?'completed':($row['status']=='pending'?'scheduled':'cancelled'); ?>"><?= ucfirst($row['status']); ?></span></td>
            <td><?= date("d M Y",strtotime($row['created_at'])); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if($report_type=="pharmacy_inventory"): ?>
      <table>
        <thead><tr><th>Medicine</th><th>Batch</th><th>Qty</th><th>Min Stock</th><th>Expiry</th><th>Unit Price</th><th>Value</th><th>Flags</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="8">No records found.</td></tr>
        <?php else: foreach($reports as $row):
          $isExpired = $row['expiry_date'] && $row['expiry_date'] < date('Y-m-d');
          $isLow = $row['quantity'] <= $row['min_stock'];
        ?>
          <tr>
            <td><?= htmlspecialchars($row['name']); ?></td>
            <td><?= htmlspecialchars($row['batch_no'] ?? '—'); ?></td>
            <td><?= $row['quantity']; ?></td>
            <td><?= $row['min_stock']; ?></td>
            <td><?= $row['expiry_date'] ? date('d M Y', strtotime($row['expiry_date'])) : '—'; ?></td>
            <td>PKR <?= number_format($row['price'],2); ?></td>
            <td>PKR <?= number_format($row['quantity']*$row['price'],2); ?></td>
            <td>
              <?php if($isExpired): ?><span class="status cancelled">Expired</span>
              <?php elseif($isLow): ?><span class="status scheduled">Low Stock</span>
              <?php else: ?><span class="status completed">OK</span>
              <?php endif; ?>
              <?php if($row['controlled_drug']): ?> <span class="status approved">Controlled</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php if(!empty($reports)): ?>
      <p style="margin-top:14px;font-size:13px;color:var(--text-2);"><strong>Total Inventory Value:</strong> PKR <?= number_format($inventoryValue,2); ?></p>
      <?php endif; ?>
      <?php endif; ?>

      <?php if($report_type=="pharmacy_sales"): ?>
      <table>
        <thead><tr><th>Bill ID</th><th>Patient</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Payment</th><th>Method</th><th>Date</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="8">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td>#<?= $row['bill_id']; ?></td>
            <td><?= htmlspecialchars($row['patient_name']); ?></td>
            <td>PKR <?= number_format($row['subtotal'],2); ?></td>
            <td>PKR <?= number_format($row['discount_amount'],2); ?></td>
            <td>PKR <?= number_format($row['total_amount'],2); ?></td>
            <td><span class="status <?= $row['payment_status']; ?>"><?= ucfirst($row['payment_status']); ?></span></td>
            <td><?= htmlspecialchars($row['payment_method'] ?? '—'); ?></td>
            <td><?= date("d M Y",strtotime($row['created_at'])); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if($report_type=="billing"): ?>
      <table>
        <thead><tr><th>Bill ID</th><th>Patient</th><th>Amount</th><th>Doctor Fee</th><th>Total</th><th>Payment</th><th>Verification</th><th>Date</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="8">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td>#<?= $row['bill_id']; ?></td>
            <td><?= htmlspecialchars($row['patient_name']); ?></td>
            <td>PKR <?= number_format($row['amount'],2); ?></td>
            <td>PKR <?= number_format($row['doctor_fee'],2); ?></td>
            <td>PKR <?= number_format($row['amount']+$row['doctor_fee'],2); ?></td>
            <td><span class="status <?= $row['payment_status']; ?>"><?= ucfirst($row['payment_status']); ?></span></td>
            <td><span class="status <?= $row['verification_status']=='verified'?'verified':($row['verification_status']=='rejected'?'rejected':'pending'); ?>"><?= ucfirst(str_replace('_',' ',$row['verification_status'])); ?></span></td>
            <td><?= date("d M Y",strtotime($row['created_at'])); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if($report_type=="purchase_orders"): ?>
      <table>
        <thead><tr><th>PO ID</th><th>Supplier</th><th>Medicine</th><th>Qty</th><th>Price</th><th>Total</th><th>Status</th><th>Emergency</th><th>Date</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="9">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td>#<?= $row['po_id']; ?></td>
            <td><?= htmlspecialchars($row['supplier_name'] ?? '—'); ?></td>
            <td><?= htmlspecialchars($row['medicine_name']); ?></td>
            <td><?= $row['quantity']; ?></td>
            <td>PKR <?= number_format($row['price'],2); ?></td>
            <td>PKR <?= number_format($row['quantity']*$row['price'],2); ?></td>
            <td><span class="status <?= strtolower($row['status']); ?>"><?= ucfirst($row['status']); ?></span></td>
            <td><?php if($row['is_emergency']): ?><span class="status emergency">Emergency</span><?php else: ?>—<?php endif; ?></td>
            <td><?= date("d M Y",strtotime($row['created_at'])); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if($report_type=="lab_reports"): ?>
      <table>
        <thead><tr><th>Report ID</th><th>Patient</th><th>Test</th><th>Status</th><th>Uploaded By</th><th>Uploaded At</th><th>File</th></tr></thead>
        <tbody>
        <?php if(empty($reports)): ?>
          <tr class="empty-row"><td colspan="7">No records found.</td></tr>
        <?php else: foreach($reports as $row): ?>
          <tr>
            <td>#<?= $row['report_id']; ?></td>
            <td><?= htmlspecialchars($row['patient_name']); ?></td>
            <td><?= htmlspecialchars($row['test_name']); ?></td>
            <td><span class="status <?= strtolower($row['status']); ?>"><?= ucfirst($row['status']); ?></span></td>
            <td><?= htmlspecialchars($row['uploaded_by_name']); ?></td>
            <td><?= date("d M Y h:i A",strtotime($row['uploaded_at'])); ?></td>
            <td><?php if(!empty($row['report_file'])): ?><a href="../<?= htmlspecialchars($row['report_file']); ?>" target="_blank" class="no-print">View</a><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if(!empty($statusCounts)): ?>
    <div class="panel">
      <div class="panel-title"><i class="fas fa-chart-column"></i> <?= htmlspecialchars($chart_title ?: 'Overview'); ?></div>
      <div class="chart-wrap">
        <canvas id="reportChart"></canvas>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const chartCtx = document.getElementById("reportChart");
if (chartCtx) {
  const labels = <?= json_encode(array_map(function($l){ return ucwords(str_replace('_',' ',$l)); }, array_keys($statusCounts))) ?>;
  const values = <?= json_encode(array_values($statusCounts)) ?>;
  new Chart(chartCtx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: <?= json_encode($chart_title) ?>,
        data: values,
        backgroundColor: ['#FFB347','#7EC8E3','#7ED6A5','#FF6B6B','#C77DFF','#FFD166','#A0C4FF','#F06292'],
        borderWidth: 0,
        borderRadius: 6,
        maxBarThickness: 56,
        categoryPercentage: 0.6,
        barPercentage: 0.9
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { top: 8, right: 8, bottom: 0, left: 0 } },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { size: 12 }, color: '#5A3550' }
        },
        y: {
          beginAtZero: true,
          ticks: { precision: 0, font: { size: 11 }, color: '#9B7B90' },
          grid: { color: 'rgba(180,100,120,0.08)' }
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1A0A14',
          padding: 10,
          cornerRadius: 8,
          displayColors: false
        }
      }
    }
  });
}
</script>

</body>
</html>