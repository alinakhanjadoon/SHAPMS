<?php
session_start();
$conn = new mysqli("localhost","root","","SHAPMS");

$sender = (int) $_SESSION['user_id'];
$receiver = isset($_POST['receiver']) ? (int)$_POST['receiver'] : 0;
$message = trim($_POST['message']);

if ($receiver == 0 || $message == "") {
    exit("Invalid input");
}

if ($receiver == $sender) {
    exit("You cannot chat with yourself");
}

$stmt = $conn->prepare("
INSERT INTO messages (sender_id, receiver_id, message)
VALUES (?, ?, ?)
");

$stmt->bind_param("iis", $sender, $receiver, $message);
$stmt->execute();
?>