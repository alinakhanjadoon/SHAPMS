
<?php
session_start();

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'department_head'){
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost","root","","SHAPMS");

$user_id = $_SESSION['user_id'];

// get department id of logged user
$stmt = $conn->prepare("SELECT department_id, full_name FROM users WHERE user_id=?");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$department_id = $user['department_id'];
$head_name = $user['full_name'];

// get department name
$stmt2 = $conn->prepare("SELECT department_name FROM departments WHERE department_id=?");
$stmt2->bind_param("i",$department_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$dept = $res2->fetch_assoc();
$department_name = $dept['department_name'];

// pending staff
$pending = $conn->prepare("
SELECT user_id, full_name, role, email
FROM users
WHERE department_id=? AND status='pending'
AND role!='department_head'
");

$pending->bind_param("i",$department_id);
$pending->execute();
$pendingResult = $pending->get_result();

// statistics
$totalStaff = $conn->query("SELECT COUNT(*) as total FROM users WHERE department_id=$department_id AND status='active'")->fetch_assoc()['total'];
$pendingCount = $conn->query("SELECT COUNT(*) as total FROM users WHERE department_id=$department_id AND status='pending'")->fetch_assoc()['total'];
?>

<!DOCTYPE html>
<html>
<head>

<title>Department Dashboard</title>

<script src="https://cdn.tailwindcss.com"></script>

</head>

<body class="bg-gray-100">

<div class="flex">

<!-- SIDEBAR -->

<div class="w-64 bg-blue-900 min-h-screen text-white">

<div class="p-6 text-xl font-bold border-b border-blue-700">
Hospital System
</div>

<ul class="mt-6">

<li class="p-4 hover:bg-blue-700 cursor-pointer">Dashboard</li>
<li class="p-4 hover:bg-blue-700 cursor-pointer">Approve Staff</li>
<li class="p-4 hover:bg-blue-700 cursor-pointer">Staff List</li>
<li class="p-4 hover:bg-blue-700 cursor-pointer">Profile</li>
<li class="p-4 hover:bg-red-600 cursor-pointer">
<a href="logout.php">Logout</a>
</li>

</ul>

</div>

<!-- MAIN CONTENT -->

<div class="flex-1">

<!-- HEADER -->

<div class="bg-white shadow p-6 flex justify-between">

<div>
<h1 class="text-2xl font-bold">
<?php echo $department_name ?> Department Dashboard
</h1>

<p class="text-gray-500">
Welcome <?php echo $head_name; ?>
</p>
</div>

</div>

<!-- CARDS -->

<div class="grid grid-cols-3 gap-6 p-6">

<div class="bg-white shadow rounded-lg p-6">

<h2 class="text-gray-500">Total Staff</h2>

<p class="text-3xl font-bold text-blue-600">
<?php echo $totalStaff ?>
</p>

</div>

<div class="bg-white shadow rounded-lg p-6">

<h2 class="text-gray-500">Pending Requests</h2>

<p class="text-3xl font-bold text-yellow-500">
<?php echo $pendingCount ?>
</p>

</div>

<div class="bg-white shadow rounded-lg p-6">

<h2 class="text-gray-500">Department</h2>

<p class="text-2xl font-bold text-green-600">
<?php echo $department_name ?>
</p>

</div>

</div>

<!-- PENDING STAFF TABLE -->

<div class="p-6">

<div class="bg-white shadow rounded-lg p-6">

<h2 class="text-xl font-bold mb-4">
Pending Staff Approvals
</h2>

<table class="w-full border">

<thead class="bg-gray-200">

<tr>

<th class="p-3">Name</th>
<th class="p-3">Role</th>
<th class="p-3">Email</th>
<th class="p-3">Action</th>

</tr>

</thead>

<tbody>

<?php while($row = $pendingResult->fetch_assoc()) { ?>

<tr class="border-t text-center">

<td class="p-3"><?php echo $row['full_name'] ?></td>

<td class="p-3 capitalize"><?php echo $row['role'] ?></td>

<td class="p-3"><?php echo $row['email'] ?></td>

<td class="p-3 space-x-2">

<a href="approve_staff.php?id=<?php echo $row['user_id'] ?>">
<button class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded">
Approve
</button>
</a>

<a href="reject_staff.php?id=<?php echo $row['user_id'] ?>">
<button class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">
Reject
</button>
</a>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

</body>
</html>