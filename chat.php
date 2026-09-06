<?php
session_start();
$conn = new mysqli("localhost","root","","SHAPMS");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_id = (int) $_SESSION['user_id'];
$chat_with = isset($_GET['user']) ? (int)$_GET['user'] : 0;

$partner_name = "Healthcare Professional";
$partner_role = "";
if ($chat_with > 0) {
    $stmt = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $chat_with);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $partner_name = $row['full_name'];
        $partner_role = $row['role'];
    }
    $stmt->close();
}

$user_name = $_SESSION['full_name'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Secure Messaging | Pharmacy-Doctor Chat</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
:root {
    --primary-dark: #0B4F6C;
    --primary: #1E88E5;
    --primary-light: #64B5F6;

    --primary-glow: rgba(30,136,229,0.15);

    --secondary: #90CAF9;
    --accent: #42A5F5;

    --bg-gradient-start: #F5FAFF;
    --bg-gradient-end: #DDEEFF;

    --surface-glass: rgba(255,255,255,0.92);

    --text-primary: #1F2937;
    --text-secondary: #4B5563;
    --text-muted: #6B7280;

    --border-light: rgba(30,136,229,0.10);
    --border-medium: rgba(30,136,229,0.20);

    --shadow-sm: 0 4px 20px rgba(0,0,0,0.05);
    --shadow-lg: 0 20px 50px rgba(0,0,0,0.12);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Inter', sans-serif;
    height: 100vh;
    background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    position: relative;
    overflow: hidden;
}

body::before {
    content: '';
    position: absolute;
    top: -50%; right: -30%;
    width: 80%; height: 80%;
    background: radial-gradient(circle, rgba(201, 169, 110, 0.08) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

body::after {
    content: '';
    position: absolute;
    bottom: -30%; left: -20%;
    width: 60%; height: 60%;
    background: radial-gradient(circle, rgba(45, 106, 79, 0.06) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

/* ── CHAT CONTAINER ── */
.chat-container {
    width: 550px;
    max-width: 90vw;
    height: 80vh;
    min-height: 600px;
    max-height: 800px;
    background: var(--surface-glass);
    backdrop-filter: blur(20px);
    border-radius: 32px;
    box-shadow: var(--shadow-lg);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.4);
    position: relative;
    z-index: 10;
}

/* ── HEADER ── */
.chat-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    overflow: hidden;
}

.chat-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

.avatar {
    width: 50px; height: 50px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.avatar.doctor {
    background: linear-gradient(135deg, rgba(201,169,110,0.3), rgba(201,169,110,0.1));
}

.avatar.pharmacist {
    background: linear-gradient(135deg, rgba(45,106,79,0.3), rgba(45,106,79,0.1));
}

.header-info { flex: 1; }

.header-info h3 {
    font-size: 18px;
    font-weight: 700;
    color: white;
    margin-bottom: 4px;
    letter-spacing: -0.3px;
}

.header-info p {
    font-size: 11px;
    font-weight: 500;
    color: rgba(255,255,255,0.7);
    display: flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0.3px;
}

.status-dot {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    display: inline-block;
}



.back-btn {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 36px; height: 36px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.back-btn:hover {
    background: rgba(255,255,255,0.25);
    transform: translateX(-2px);
}

/* ── CHAT BOX ── */
.chat-box {
    flex: 1;
    padding: 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: transparent;
}

.chat-box::-webkit-scrollbar { width: 5px; }
.chat-box::-webkit-scrollbar-track { background: rgba(45,106,79,0.05); border-radius: 10px; }
.chat-box::-webkit-scrollbar-thumb { background: var(--primary-light); border-radius: 10px; }

/* ── MESSAGE WRAPPER ── */
.message {
    max-width: 80%;
    display: flex;
    flex-direction: column;
    animation: messagePop 0.25s ease;
}

@keyframes messagePop {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.message.sent     { align-self: flex-end;   align-items: flex-end; }
.message.received { align-self: flex-start; align-items: flex-start; }

/* ── SENT bubble — YOU (dark green, right) ── */
.message.sent .bubble {
    background: linear-gradient(135deg, #2D6A4F, #1B4D3A);
    color: #ffffff;
    border-radius: 20px 20px 4px 20px;
    box-shadow: 0 2px 8px rgba(45, 106, 79, 0.3);
    padding: 12px 18px;
    font-size: 13px;
    line-height: 1.45;
    word-wrap: break-word;
}

/* ── RECEIVED bubble — THEM (white, left) ── */
.message.received .bubble {
    background: #ffffff;
    color: #1E2A2A;
    border-radius: 20px 20px 20px 4px;
    border: 1px solid rgba(45, 106, 79, 0.15);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    padding: 12px 18px;
    font-size: 13px;
    line-height: 1.45;
    word-wrap: break-word;
}

/* ── TIMESTAMPS ── */
.message-time {
    font-size: 10px;
    margin-top: 4px;
    padding: 0 6px;
}

.message.sent .message-time     { color: #7a9a8a; text-align: right; }
.message.received .message-time { color: #8A9E9E; text-align: left; }

/* ── SENDER NAME (received only) ── */
.sender-name {
    font-size: 10px;
    font-weight: 600;
    margin-bottom: 4px;
    padding-left: 6px;
    color: var(--primary);
}

/* ── INPUT AREA ── */
.chat-input {
    padding: 20px 24px;
    background: rgba(255,255,255,0.9);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--border-light);
    display: flex;
    gap: 12px;
    align-items: center;
}

.chat-input input {
    flex: 1;
    padding: 14px 18px;
    border: 1px solid var(--border-medium);
    border-radius: 28px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    outline: none;
    transition: all 0.2s;
    background: white;
}

.chat-input input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}

.chat-input button {
    width: 48px; height: 48px;
    border: none;
    border-radius: 28px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.chat-input button:hover {
    transform: scale(1.04);
    box-shadow: 0 4px 12px rgba(45, 106, 79, 0.3);
}

/* ── EMPTY STATE ── */
.empty-chat {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-chat i {
    font-size: 56px;
    color: var(--border-medium);
    margin-bottom: 16px;
    display: block;
}

.empty-chat p { font-size: 14px; }

/* ── TYPING INDICATOR ── */
.typing-indicator {
    display: none;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: #ffffff;
    border-radius: 20px;
    width: fit-content;
    border: 1px solid var(--border-light);
}

.typing-indicator span {
    width: 6px; height: 6px;
    background: var(--primary-light);
    border-radius: 50%;
    display: inline-block;
    animation: typing 1.4s infinite;
}

.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30%           { transform: translateY(-6px); opacity: 1; }
}

/* ── RESPONSIVE ── */
@media (max-width: 600px) {
    body { padding: 0; }
    .chat-container {
        width: 100vw;
        height: 100vh;
        max-height: none;
        border-radius: 0;
    }
    .message { max-width: 85%; }
}
</style>
</head>

<body>

<div class="chat-container">

    <div class="chat-header">
        <a href="pharmacistdashboard.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="avatar <?= $partner_role == 'doctor' ? 'doctor' : 'pharmacist' ?>">
            <i class="fa-solid <?= $partner_role == 'doctor' ? 'fa-user-md' : 'fa-pills' ?>"></i>
        </div>
        <div class="header-info">
            <h3><?= htmlspecialchars($partner_name) ?></h3>
            <p>
                <span class="status-dot"></span>
                <?= ucfirst($partner_role) ?> · Online
            </p>
        </div>
        <div style="width: 36px;"></div>
    </div>

    <div class="chat-box" id="chatBox">
        <div class="empty-chat" id="emptyState">
            <i class="fa-solid fa-comments"></i>
            <p>Select a conversation to start messaging</p>
        </div>
    </div>

    <div class="chat-input">
        <input type="text" id="message" placeholder="Type your message...">
        <button onclick="sendMessage()">
            <i class="fa-solid fa-paper-plane"></i>
        </button>
    </div>

</div>

<script>
let receiver = <?= json_encode($chat_with) ?>;
let sender = <?= json_encode($user_id) ?>;

let lastChatHTML = "";

function loadChat() {

    if (receiver === 0) {
        return;
    }

    fetch("fetch_messages.php?user=" + receiver)
    .then(response => response.text())
    .then(data => {

        const chatBox = document.getElementById("chatBox");

        // Only update if content changed
        if (data !== lastChatHTML) {

            const isNearBottom =
                chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 150;

            chatBox.innerHTML = data;

            if (isNearBottom) {
                chatBox.scrollTop = chatBox.scrollHeight;
            }

            lastChatHTML = data;
        }

    })
    .catch(error => console.error(error));
}

function sendMessage() {

    let msg = document.getElementById("message").value.trim();

    if (!msg || receiver === 0) {
        return;
    }

    fetch("send_message.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "receiver=" + receiver +
            "&message=" + encodeURIComponent(msg)
    })
    .then(() => {

        document.getElementById("message").value = "";

        // immediate refresh after send
        lastChatHTML = "";
        loadChat();

    });
}

document.getElementById("message")
.addEventListener("keydown", function(e) {

    if (e.key === "Enter") {
        e.preventDefault();
        sendMessage();
    }

});

if (receiver > 0) {

    loadChat();

    setInterval(() => {
        loadChat();
    }, 3000);

}
</script>

</body>
</html>