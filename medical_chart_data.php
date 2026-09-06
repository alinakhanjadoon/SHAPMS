<?php
header("Content-Type: application/json");

$conn = new mysqli("localhost","root","","SHAPMS");

if ($conn->connect_error) {
    echo json_encode([
        "error" => true,
        "message" => "Database connection failed"
    ]);
    exit;
}

/* active doctors */
$doctorCount = $conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE role='doctor'
    AND department_id=1
    AND status='active'
")->fetch_row()[0];

/* active nurses */
$nurseCount = $conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE role='nurse'
    AND department_id=1
    AND status='active'
")->fetch_row()[0];

/* actual pending approvals */
$pendingCount = $conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE department_id=1
    AND role IN ('doctor','nurse')
    AND status='inactive'
")->fetch_row()[0];

/* rejected */
$rejectedCount = $conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE department_id=1
    AND status='rejected'
")->fetch_row()[0];

/* monthly */
$months = [];
$monthly = array_fill(0,6,0);

for($i=5;$i>=0;$i--){
    $months[] = date("M",strtotime("-$i month"));
}

$res = $conn->query("
    SELECT MONTH(created_at) month_num, COUNT(*) total
    FROM users
    WHERE department_id=1
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY MONTH(created_at)
");

if($res){
    while($row=$res->fetch_assoc()){
        $index = 5 - (date('n') - $row['month_num']);

        if(isset($monthly[$index])){
            $monthly[$index] = (int)$row['total'];
        }
    }
}

echo json_encode([
    "error" => false,
    "doctors" => $doctorCount,
    "nurses" => $nurseCount,
    "pendingCount" => $pendingCount,
    "rejected" => $rejectedCount,
    "active" => $doctorCount + $nurseCount,
    "months" => $months,
    "monthly" => $monthly
]);