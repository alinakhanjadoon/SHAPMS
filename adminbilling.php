<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors',1);

/* ===========================
   ADMIN AUTH
=========================== */
if(!isset($_SESSION['user_id']) || $_SESSION['role']!='admin'){
    header("Location: login.php");
    exit();
}

/* ===========================
   DATABASE
=========================== */
$conn=new mysqli("localhost","root","","SHAPMS");

if($conn->connect_error){
    die("Database Connection Failed");
}

/* ===========================
   DELETE BILL
=========================== */
if(isset($_GET['delete'])){

    $id=(int)$_GET['delete'];

    $conn->query("DELETE FROM billing WHERE bill_id=$id");

    header("Location: adminbilling.php");
    exit();
}

/* ===========================
   SEARCH
=========================== */

$search="";

$where="";

if(isset($_GET['search']) && $_GET['search']!==""){

    $search=$conn->real_escape_string($_GET['search']);

    $where=" AND (
        p.full_name LIKE '%$search%'
        OR b.bill_id LIKE '%$search%'
    )";
}

/* ===========================
   STATUS FILTER
=========================== */

$status="";

if(isset($_GET['status']) && $_GET['status']!=""){

    $status=$conn->real_escape_string($_GET['status']);

    $where.=" AND b.payment_status='$status'";
}

/* ===========================
   LOAD BILLING
=========================== */

$sql="

SELECT

b.*,

(b.amount + IFNULL(b.doctor_fee,0)) AS total_amount,

u.full_name

FROM billing b

LEFT JOIN patients p
ON p.patient_id=b.patient_id

LEFT JOIN users u
ON u.user_id=p.user_id

WHERE 1=1

$where

ORDER BY b.created_at DESC

";

$bills=$conn->query($sql);

/* ===========================
   TOTALS
=========================== */

$totalRevenue=0;
$totalPaid=0;
$totalUnpaid=0;

$stats=$conn->query("

SELECT

SUM(amount + IFNULL(doctor_fee,0)) total,

SUM(CASE WHEN payment_status='paid'
THEN amount + IFNULL(doctor_fee,0) ELSE 0 END) paid,

SUM(CASE WHEN payment_status='unpaid'
THEN amount + IFNULL(doctor_fee,0) ELSE 0 END) unpaid

FROM billing

");

if($row=$stats->fetch_assoc()){

$totalRevenue=$row['total']??0;
$totalPaid=$row['paid']??0;
$totalUnpaid=$row['unpaid']??0;

}

/* ===========================
   ADMIN INFO
=========================== */

$id=$_SESSION['user_id'];

$user=$conn->query("

SELECT full_name,profile_image

FROM users

WHERE user_id=$id

")->fetch_assoc();

$adminName=$user['full_name']??'Admin';

$profile=$user['profile_image']??'default-avatar.png';

date_default_timezone_set("Asia/Karachi");

?>

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>Billing Report</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

body{

font-family:Arial,Helvetica,sans-serif;

background:#f4f6f9;

margin:0;

}

.header{

background:#c62828;

color:white;

padding:18px 30px;

display:flex;

justify-content:space-between;

align-items:center;

}

.header h2{

margin:0;

}

.container{

width:95%;

margin:25px auto;

}

.cards{

display:grid;

grid-template-columns:repeat(3,1fr);

gap:15px;

margin-bottom:25px;

}

.card{

background:white;

padding:20px;

border-radius:10px;

box-shadow:0 3px 10px rgba(0,0,0,.08);

}

.card h4{

margin:0;

font-size:14px;

color:#666;

}

.card h2{

margin-top:10px;

color:#c62828;

}

.search-box{

background:white;

padding:15px;

border-radius:10px;

box-shadow:0 3px 10px rgba(0,0,0,.08);

margin-bottom:20px;

display:flex;

gap:10px;

flex-wrap:wrap;

}

.search-box input,

.search-box select{

padding:10px;

border:1px solid #ccc;

border-radius:5px;

}

.search-box button{

padding:10px 20px;

background:#c62828;

color:white;

border:none;

cursor:pointer;

border-radius:5px;

}

.search-box a{

padding:10px 20px;

background:#666;

color:white;

text-decoration:none;

border-radius:5px;

}

table{

width:100%;

background:white;

border-collapse:collapse;

box-shadow:0 3px 10px rgba(0,0,0,.08);

}

th{

background:#c62828;

color:white;

padding:12px;

}

td{

padding:12px;

border-bottom:1px solid #eee;

}

tr:hover{

background:#fafafa;

}

.badge{

padding:5px 10px;

border-radius:20px;

font-size:12px;

color:white;

}

.paid{

background:green;

}

.unpaid{

background:orange;

}

.action{

text-decoration:none;

padding:6px 10px;

border-radius:5px;

font-size:13px;

margin-right:5px;

}

.delete{

background:#d32f2f;

color:white;

}

.print{

background:#1976d2;

color:white;

}

@media(max-width:900px){

.cards{

grid-template-columns:1fr 1fr;

}

}

</style>

</head>

<body>

<div class="header">

<h2><i class="fa fa-file-invoice-dollar"></i>
Billing Report</h2>

<div>

<img src="<?= htmlspecialchars($profile) ?>"
style="width:40px;height:40px;border-radius:50%;vertical-align:middle;">

<strong>

<?= htmlspecialchars($adminName) ?>

</strong>

</div>

</div>

<div class="container">

<div class="cards">

<div class="card">

<h4>Total Revenue</h4>

<h2>Rs.
<?= number_format($totalRevenue,2) ?>
</h2>

</div>

<div class="card">

<h4>Paid</h4>

<h2 style="color:green;">

Rs.
<?= number_format($totalPaid,2) ?>

</h2>

</div>

<div class="card">

<h4>Unpaid</h4>

<h2 style="color:orange;">

Rs.
<?= number_format($totalUnpaid,2) ?>

</h2>

</div>

</div>

<form class="search-box" method="GET">

<input
type="text"
name="search"
placeholder="Search Patient / Bill ID"
value="<?= htmlspecialchars($search) ?>">

<select name="status">

<option value="">All Status</option>

<option value="paid" <?= $status=='paid'?'selected':'' ?>>Paid</option>

<option value="unpaid" <?= $status=='unpaid'?'selected':'' ?>>Unpaid</option>

</select>

<button>

<i class="fa fa-search"></i>

Search

</button>

<a href="adminbilling.php">

Reset

</a>

</form>
<div style="margin-bottom:15px;">

<button onclick="window.print()" class="print">

<i class="fa fa-print"></i>

Print Report

</button>

<a href="exportbilling.php" class="print" style="text-decoration:none;">

<i class="fa fa-download"></i>

Export CSV

</a>

</div>

<table>

<thead>

<tr>

<th>#</th>

<th>Bill No</th>

<th>Patient</th>

<th>Date</th>

<th>Amount</th>

<th>Doctor Fee</th>

<th>Total</th>

<th>Status</th>

<th>Actions</th>

</tr>

</thead>

<tbody>

<?php

if($bills->num_rows>0){

$i=1;

while($bill=$bills->fetch_assoc()){

?>

<tr>

<td>

<?= $i++ ?>

</td>

<td>

#<?= htmlspecialchars($bill['bill_id']) ?>

</td>

<td>

<?= htmlspecialchars($bill['full_name']??'Unknown Patient') ?>

</td>

<td>

<?= htmlspecialchars($bill['created_at']??'') ?>


</td>

<td>

Rs.

<?= number_format($bill['amount']??0,2) ?>

</td>

<td>

Rs.

<?= number_format($bill['doctor_fee']??0,2) ?>

</td>

<td>

Rs.

<?= number_format($bill['total_amount']??0,2) ?>

</td>

<td>

<?php

$status=$bill['payment_status']??'unpaid';

$class=strtolower($status);

?>

<span class="badge <?= $class ?>">

<?= htmlspecialchars(ucfirst($status)) ?>

</span>

</td>

<td>

<a

class="action print"

href="printbill.php?id=<?= $bill['bill_id'] ?>"

target="_blank">

<i class="fa fa-print"></i>

Print

</a>

<a

class="action delete"

onclick="return confirm('Delete this bill?')"

href="?delete=<?= $bill['bill_id'] ?>">

<i class="fa fa-trash"></i>

Delete

</a>

</td>

</tr>

<?php

}

}else{

?>

<tr>

<td colspan="9" style="text-align:center;padding:40px;">

No Billing Records Found

</td>

</tr>

<?php

}

?>

</tbody>

</table>

<br><br>

<div class="card">

<h3 style="margin-top:0;">

Billing Summary

</h3>

<table>

<tr>

<td>

Total Revenue

</td>

<td>

<strong>

Rs. <?= number_format($totalRevenue,2) ?>

</strong>

</td>

</tr>

<tr>

<td>

Paid Revenue

</td>

<td style="color:green;">

<strong>

Rs. <?= number_format($totalPaid,2) ?>

</strong>

</td>

</tr>

<tr>

<td>

Unpaid Revenue

</td>

<td style="color:orange;">

<strong>

Rs. <?= number_format($totalUnpaid,2) ?>

</strong>

</td>

</tr>

</table>

</div>

<br>

<div class="card">

<h3 style="margin-top:0;">

Recent Payment Information

</h3>

<p>

This report shows all hospital bills generated through the Smart Hospital &
Pharmacy Management System. Administrators can search patients,
filter payment status, print bills, delete invalid records
and monitor revenue statistics.

</p>

</div>
<script>

document.querySelectorAll(".delete").forEach(function(btn){

btn.addEventListener("click",function(e){

if(!confirm("Are you sure you want to delete this billing record?")){

e.preventDefault();

}

});

});

function printPage(){

window.print();

}

</script>

<style>

@media print{

body{

background:white;

}

.header{

background:white !important;

color:black !important;

border-bottom:2px solid black;

}

.search-box,

button,

.print,

.delete{

display:none !important;

}

table{

box-shadow:none;

}

th{

background:#ddd !important;

color:black !important;

}

.card{

box-shadow:none;

border:1px solid #ccc;

margin-bottom:15px;

}

}

.footer{

margin-top:40px;

padding:20px;

text-align:center;

color:#777;

font-size:14px;

}

</style>

<div class="footer">

<hr>

<p>

<strong>Zaman Medical Center</strong>

</p>

<p>

Smart Hospital & Pharmacy Management System

</p>

<p>

Billing Management Report

</p>

<p>

Generated on

<?= date("d M Y h:i A") ?>

</p>

</div>

</div>

</body>

</html>

<?php

$conn->close();

?>