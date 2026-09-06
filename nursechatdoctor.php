<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

$nurse_user_id = $_SESSION['user_id'];

// Get nurse name
$nq = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$nq->bind_param("i", $nurse_user_id);
$nq->execute();
$nq->bind_result($nurse_name);
$nq->fetch();
$nq->close();

// Get all doctors
$doctors = [];
$dq = $conn->query("
    SELECT d.doctor_id, d.specialty, u.user_id, u.full_name
    FROM doctors d
    JOIN users u ON d.user_id = u.user_id
    ORDER BY u.full_name
");
while ($row = $dq->fetch_assoc()) $doctors[] = $row;

// Selected doctor
$selected_user_id = isset($_GET['doctor']) ? (int)$_GET['doctor'] : ($doctors[0]['user_id'] ?? 0);
$selected_name = '';
$selected_specialty = '';
foreach ($doctors as $d) {
    if ($d['user_id'] == $selected_user_id) {
        $selected_name = $d['full_name'];
        $selected_specialty = $d['specialty'];
    }
}

// AJAX: send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'send_message') {
    header('Content-Type: application/json');
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$receiver_id || !$message) {
        echo json_encode(['success' => false, 'error' => 'Missing data.']);
        exit();
    }
    $ins = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
    $ins->bind_param("iis", $nurse_user_id, $receiver_id, $message);
    $ok = $ins->execute();
    echo json_encode([
        'success' => $ok,
        'message' => $message,
        'time' => date('h:i A'),
        'error' => $ok ? null : $conn->error
    ]);
    $ins->close();
    exit();
}

// AJAX: fetch new messages (polling)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax_action'] ?? '') === 'fetch_messages') {
    header('Content-Type: application/json');
    $other_user_id = (int)($_GET['other_user_id'] ?? 0);
    $after_id = (int)($_GET['after_id'] ?? 0);
    $mq = $conn->prepare("
        SELECT id, sender_id, message, created_at
        FROM messages
        WHERE id > ?
        AND (
            (sender_id = ? AND receiver_id = ?)
            OR
            (sender_id = ? AND receiver_id = ?)
        )
        ORDER BY id ASC
    ");
    $mq->bind_param("iiiii", $after_id, $nurse_user_id, $other_user_id, $other_user_id, $nurse_user_id);
    $mq->execute();
    $msgs = $mq->get_result()->fetch_all(MYSQLI_ASSOC);
    $mq->close();
    echo json_encode(['success' => true, 'messages' => $msgs]);
    exit();
}

// Load existing messages
$messages = [];
if ($selected_user_id) {
    $mq = $conn->prepare("
        SELECT id, sender_id, message, created_at
        FROM messages
        WHERE (sender_id = ? AND receiver_id = ?)
           OR (sender_id = ? AND receiver_id = ?)
        ORDER BY id ASC
    ");
    $mq->bind_param("iiii", $nurse_user_id, $selected_user_id, $selected_user_id, $nurse_user_id);
    $mq->execute();
    $messages = $mq->get_result()->fetch_all(MYSQLI_ASSOC);
    $mq->close();
}

$last_id = !empty($messages) ? end($messages)['id'] : 0;
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SHAPMS — Chat with Doctor</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
<style>
:root {
    --mint:       #5BBFB5;
    --mint-light: #A8DDD8;
    --mint-pale:  #E8F7F6;
    --mint-dark:  #3A9E94;
    --teal-deep:  #2C7873;
    --white:      #FFFFFF;
    --off-white:  #F4FAFA;
    --gray-100:   #F3F4F6;
    --gray-200:   #E5E7EB;
    --gray-400:   #9CA3AF;
    --gray-600:   #6B7280;
    --gray-800:   #1F2937;
    --shadow-sm:  0 1px 3px rgba(91,191,181,0.12);
    --shadow-md:  0 4px 16px rgba(91,191,181,0.18);
    --radius:     14px;
    --radius-sm:  8px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', sans-serif;
    background: var(--off-white);
    color: var(--gray-800);
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* TOPBAR */
.topbar {
    background: var(--white);
    height: 64px;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
    border-bottom: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    flex-shrink: 0;
    z-index: 10;
}
.topbar-back {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--mint-dark);
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    padding: 7px 14px;
    border-radius: 8px;
    background: var(--mint-pale);
    transition: background 0.2s;
}
.topbar-back:hover { background: var(--mint-light); color: var(--teal-deep); }
.topbar-title {
    font-family: 'Poppins', sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--gray-800);
    flex: 1;
}
.topbar-title span { color: var(--mint-dark); }

/* LAYOUT */
.chat-layout {
    display: flex;
    flex: 1;
    overflow: hidden;
}

/* DOCTOR SIDEBAR */
.doctor-list {
    width: 280px;
    background: var(--white);
    border-right: 1px solid var(--gray-200);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    overflow-y: auto;
}
.doctor-list-header {
    padding: 16px 18px 12px;
    font-size: 11px;
    font-weight: 700;
    color: var(--gray-400);
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid var(--gray-100);
    background: var(--gray-100);
}
.doctor-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    cursor: pointer;
    border-bottom: 1px solid var(--gray-100);
    text-decoration: none;
    transition: background 0.2s;
    border-left: 3px solid transparent;
}
.doctor-item:hover { background: var(--mint-pale); }
.doctor-item.active {
    background: var(--mint-pale);
    border-left-color: var(--mint-dark);
}
.doctor-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--mint-dark), var(--mint));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: var(--white);
    flex-shrink: 0;
}
.doctor-info { flex: 1; min-width: 0; }
.doctor-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--gray-800);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.doctor-spec {
    font-size: 11px;
    color: var(--gray-400);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* CHAT AREA */
.chat-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--off-white);
}

/* CHAT HEADER */
.chat-header {
    background: var(--white);
    padding: 14px 24px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
    box-shadow: var(--shadow-sm);
}
.chat-header-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--mint-dark), var(--mint));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    color: var(--white);
}
.chat-header-info { flex: 1; }
.chat-header-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--gray-800);
}
.chat-header-spec {
    font-size: 12px;
    color: var(--gray-400);
    margin-top: 2px;
}
.online-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #4ADE80;
    box-shadow: 0 0 0 2px rgba(74,222,128,0.3);
}

/* MESSAGES */
.messages-box {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.msg-row {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}
.msg-row.sent { flex-direction: row-reverse; }

.msg-avatar-sm {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--mint-dark), var(--mint));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: var(--white);
    flex-shrink: 0;
}
.msg-avatar-sm.doctor {
    background: linear-gradient(135deg, #6366F1, #8B5CF6);
}

.msg-bubble {
    max-width: 65%;
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 13px;
    line-height: 1.5;
    word-break: break-word;
}
.msg-row.received .msg-bubble {
    background: var(--white);
    color: var(--gray-800);
    border-bottom-left-radius: 4px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
}
.msg-row.sent .msg-bubble {
    background: linear-gradient(135deg, var(--mint-dark), var(--mint));
    color: var(--white);
    border-bottom-right-radius: 4px;
}
.msg-time {
    font-size: 10px;
    color: var(--gray-400);
    margin-top: 4px;
    text-align: right;
}
.msg-row.received .msg-time { text-align: left; padding-left: 38px; }
.msg-row.sent .msg-time { padding-right: 38px; }

/* DATE SEPARATOR */
.date-sep {
    text-align: center;
    font-size: 11px;
    color: var(--gray-400);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 8px 0;
}
.date-sep::before, .date-sep::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--gray-200);
}

/* EMPTY CHAT */
.empty-chat {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--gray-400);
    gap: 12px;
}
.empty-chat .e-icon { font-size: 48px; }
.empty-chat p { font-size: 14px; }

/* INPUT BAR */
.input-bar {
    background: var(--white);
    padding: 14px 24px;
    border-top: 1px solid var(--gray-200);
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-shrink: 0;
}
.msg-input {
    flex: 1;
    padding: 10px 16px;
    border-radius: 24px;
    border: 1.5px solid var(--gray-200);
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    outline: none;
    resize: none;
    max-height: 100px;
    line-height: 1.5;
    transition: border-color 0.2s;
}
.msg-input:focus { border-color: var(--mint); }
.send-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--mint-dark), var(--mint));
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: var(--white);
    flex-shrink: 0;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 2px 8px rgba(91,191,181,0.4);
}
.send-btn:hover { transform: scale(1.08); box-shadow: 0 4px 14px rgba(91,191,181,0.5); }
.send-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

/* NO DOCTOR SELECTED */
.no-selection {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--gray-400);
    gap: 14px;
}
.no-selection .big-icon { font-size: 56px; }
.no-selection p { font-size: 15px; font-weight: 500; }
.no-selection small { font-size: 12px; }

::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-thumb { background: var(--mint-light); border-radius: 3px; }

@media(max-width: 640px) {
    .doctor-list { width: 70px; }
    .doctor-name, .doctor-spec, .doctor-list-header { display: none; }
    .doctor-item { justify-content: center; padding: 12px; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="nursedashboard.php" class="topbar-back">← Back</a>
    <div class="topbar-title">Chat with <span>Doctor</span></div>
    <div style="font-size:12px;color:var(--gray-400);">Logged in as <?= htmlspecialchars($nurse_name) ?></div>
</div>

<div class="chat-layout">

    <!-- DOCTOR LIST -->
    <div class="doctor-list">
        <div class="doctor-list-header">Doctors</div>
        <?php foreach ($doctors as $d):
            $ini = strtoupper(substr($d['full_name'], 0, 2));
            $isActive = $d['user_id'] == $selected_user_id;
        ?>
        <a href="?doctor=<?= $d['user_id'] ?>"
           class="doctor-item <?= $isActive ? 'active' : '' ?>">
            <div class="doctor-avatar"><?= htmlspecialchars($ini) ?></div>
            <div class="doctor-info">
                <div class="doctor-name">Dr. <?= htmlspecialchars($d['full_name']) ?></div>
                <div class="doctor-spec"><?= htmlspecialchars($d['specialty'] ?? 'General') ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- CHAT AREA -->
    <div class="chat-area">

        <?php if (!$selected_user_id || empty($doctors)): ?>
        <div class="no-selection">
            <div class="big-icon">💬</div>
            <p>Select a doctor to start chatting</p>
            <small>Your messages are private and secure</small>
        </div>

        <?php else: ?>

        <!-- CHAT HEADER -->
        <div class="chat-header">
            <div class="chat-header-avatar"><?= strtoupper(substr($selected_name, 0, 2)) ?></div>
            <div class="chat-header-info">
                <div class="chat-header-name">Dr. <?= htmlspecialchars($selected_name) ?></div>
                <div class="chat-header-spec"><?= htmlspecialchars($selected_specialty ?? 'General') ?></div>
            </div>
            <div class="online-dot"></div>
        </div>

        <!-- MESSAGES -->
        <div class="messages-box" id="messages-box">
            <?php if (empty($messages)): ?>
            <div class="empty-chat">
                <div class="e-icon">💬</div>
                <p>No messages yet</p>
                <small>Send a message to Dr. <?= htmlspecialchars($selected_name) ?></small>
            </div>
            <?php else:
                $last_date = '';
                $nurse_ini = strtoupper(substr($nurse_name, 0, 2));
                $doc_ini   = strtoupper(substr($selected_name, 0, 2));
                foreach ($messages as $m):
                    $isSent = $m['sender_id'] == $nurse_user_id;
                    $msgDate = date('M d, Y', strtotime($m['created_at']));
                    $msgTime = date('h:i A', strtotime($m['created_at']));
            ?>
                <?php if ($msgDate !== $last_date): $last_date = $msgDate; ?>
                <div class="date-sep"><?= $msgDate ?></div>
                <?php endif; ?>

                <div class="msg-row <?= $isSent ? 'sent' : 'received' ?>">
                    <div class="msg-avatar-sm <?= $isSent ? '' : 'doctor' ?>">
                        <?= $isSent ? $nurse_ini : $doc_ini ?>
                    </div>
                    <div>
                        <div class="msg-bubble"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
                <div class="msg-time <?= $isSent ? 'sent' : 'received' ?>
                    msg-row <?= $isSent ? 'sent' : 'received' ?>"><?= $msgTime ?></div>

            <?php endforeach; endif; ?>
        </div>

        <!-- INPUT BAR -->
        <div class="input-bar">
            <textarea
                class="msg-input"
                id="msg-input"
                placeholder="Type a message to Dr. <?= htmlspecialchars($selected_name) ?>…"
                rows="1"
                onkeydown="handleKey(event)"
            ></textarea>
            <button class="send-btn" id="send-btn" onclick="sendMessage()">➤</button>
        </div>

        <?php endif; ?>
    </div>
</div>

<script>
const NURSE_USER_ID    = <?= $nurse_user_id ?>;
const SELECTED_DOC_ID  = <?= $selected_user_id ?>;
const NURSE_INI        = "<?= strtoupper(substr($nurse_name, 0, 2)) ?>";
const DOC_INI          = "<?= strtoupper(substr($selected_name, 0, 2)) ?>";
let lastId             = <?= $last_id ?>;

// Auto-scroll to bottom
function scrollBottom() {
    const box = document.getElementById('messages-box');
    if (box) box.scrollTop = box.scrollHeight;
}
scrollBottom();

// Auto-resize textarea
const inp = document.getElementById('msg-input');
if (inp) {
    inp.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
}

// Send on Enter (Shift+Enter = newline)
function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function sendMessage() {
    const input = document.getElementById('msg-input');
    const btn   = document.getElementById('send-btn');
    const text  = input.value.trim();
    if (!text || !SELECTED_DOC_ID) return;

    btn.disabled = true;

    const body = new URLSearchParams({
        ajax_action: 'send_message',
        receiver_id: SELECTED_DOC_ID,
        message: text
    });

    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (!data.success) { alert(data.error || 'Failed to send.'); return; }
            input.value = '';
            input.style.height = 'auto';
            appendMessage(text, data.time, true);
        })
        .catch(() => { btn.disabled = false; alert('Network error.'); });
}

function appendMessage(text, time, isSent) {
    const box = document.getElementById('messages-box');

    // Remove empty chat state if present
    const empty = box.querySelector('.empty-chat');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = `msg-row ${isSent ? 'sent' : 'received'}`;
    row.innerHTML = `
        <div class="msg-avatar-sm ${isSent ? '' : 'doctor'}">${isSent ? NURSE_INI : DOC_INI}</div>
        <div><div class="msg-bubble">${escHtml(text)}</div></div>
    `;
    box.appendChild(row);

    const timeRow = document.createElement('div');
    timeRow.className = `msg-time msg-row ${isSent ? 'sent' : 'received'}`;
    timeRow.textContent = time;
    box.appendChild(timeRow);

    scrollBottom();
}

function escHtml(t) {
    return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Poll for new messages every 4 seconds
function pollMessages() {
    if (!SELECTED_DOC_ID) return;
    fetch(`?ajax_action=fetch_messages&other_user_id=${SELECTED_DOC_ID}&after_id=${lastId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.messages.length > 0) {
                data.messages.forEach(m => {
                    if (m.sender_id != NURSE_USER_ID) {
                        const time = new Date(m.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                        appendMessage(m.message, time, false);
                    }
                    lastId = Math.max(lastId, m.id);
                });
            }
        })
        .catch(() => {});
}

if (SELECTED_DOC_ID) setInterval(pollMessages, 4000);


</script>
</body>ﬁ

</html>