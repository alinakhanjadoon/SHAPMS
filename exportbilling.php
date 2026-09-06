<?php
header("Content-Type:text/csv");
header("Content-Disposition:attachment; filename=billing_report.csv");

$conn=new mysqli("localhost","root","","SHAPMS");

$result=$conn->query("SELECT * FROM billing");

$out=fopen("php://output","w");

if($result && $result->num_rows){

fputcsv($out,array_keys($result->fetch_assoc()));
$result->data_seek(0);

while($row=$result->fetch_assoc()){

fputcsv($out,$row);

}

}

fclose($out);
$conn->close();