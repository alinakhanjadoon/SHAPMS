<?php
session_start();
$conn = new mysqli("localhost","root","","SHAPMS");

$user      = (int) $_SESSION['user_id'];
$chat_with = isset($_GET['user']) ? (int)$_GET['user'] : 0;

if ($chat_with === 0) exit();

$stmt = $conn->prepare("
    SELECT m.*, u.full_name
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    ORDER BY m.id ASC
");
$stmt->bind_param("iiii", $user, $chat_with, $chat_with, $user);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo '<div class="empty-chat">
            <i class="fa-solid fa-comments"></i>
            <p>No messages yet. Say hello!</p>
          </div>';
    exit();
}

while ($row = $res->fetch_assoc()):
    $isSent = ((int)$row['sender_id'] === $user);
    $class  = $isSent ? 'sent' : 'received';
    $time   = date('h:i A', strtotime($row['created_at']));
?>
    <div class="message <?= $class ?>">
        <?php if (!$isSent): ?>
            <div class="sender-name"><?= htmlspecialchars($row['full_name']) ?></div>
        <?php endif; ?>
        <div class="bubble"><?= htmlspecialchars($row['message']) ?></div>
        <div class="message-time"><?= $time ?></div>
    </div>
<?php endwhile;

$stmt->close();
$conn->close();
?>