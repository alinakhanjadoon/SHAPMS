<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$doctor_user_id = $_SESSION['user_id'];

// Get doctor name
$dq = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$dq->bind_param("i", $doctor_user_id);
$dq->execute();
$dq->bind_result($doctor_name);
$dq->fetch();
$dq->close();

// Get all nurses
$nurses = [];
$nq = $conn->query("SELECT user_id, full_name FROM users WHERE role = 'nurse' ORDER BY full_name");
while ($row = $nq->fetch_assoc()) $nurses[] = $row;

// Selected nurse
$selected_user_id = isset($_GET['nurse']) ? (int)$_GET['nurse'] : ($nurses[0]['user_id'] ?? 0);
$selected_name = '';
foreach ($nurses as $n) {
    if ($n['user_id'] == $selected_user_id) $selected_name = $n['full_name'];
}

// AJAX: send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'send_message') {
    header('Content-Type: application/json');
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $message     = trim($_POST['message'] ?? '');
    if (!$receiver_id || !$message) {
        echo json_encode(['success' => false, 'error' => 'Missing data.']);
        exit();
    }
    $ins = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
    $ins->bind_param("iis", $doctor_user_id, $receiver_id, $message);
    $ok = $ins->execute();
    echo json_encode(['success' => $ok, 'time' => date('h:i A'), 'error' => $ok ? null : $conn->error]);
    $ins->close();
    exit();
}

// AJAX: poll new messages
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax_action'] ?? '') === 'fetch_messages') {
    header('Content-Type: application/json');
    $other_id = (int)($_GET['other_user_id'] ?? 0);
    $after_id = (int)($_GET['after_id'] ?? 0);
    $mq = $conn->prepare("
        SELECT id, sender_id, message, created_at FROM messages
        WHERE id > ?
        AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
        ORDER BY id ASC
    ");
    $mq->bind_param("iiiii", $after_id, $doctor_user_id, $other_id, $other_id, $doctor_user_id);
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
        SELECT id, sender_id, message, created_at FROM messages
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ORDER BY id ASC
    ");
    $mq->bind_param("iiii", $doctor_user_id, $selected_user_id, $selected_user_id, $doctor_user_id);
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
<title>SHAPMS — Doctor Chat with Nurse</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --b1:#0a0f2a; --b2:#11163d; --b5:#3949ab; --b6:#5c6bc0;
    --b7:#7986cb; --b8:#9fa8da; --b9:#c5cae9;
    --acc:#7e57c2; --acc2:#b39ddb;
    --gb:rgba(255,255,255,.09); --gh:rgba(255,255,255,.07);
    --txt:#e8eaf6; --mut:#9fa8da;
    --glow:rgba(57,73,171,.6);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Outfit', sans-serif;
    background: var(--b1);
    color: var(--txt);
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 90% 70% at 10% 10%, rgba(57,73,171,.28) 0%, transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%, rgba(126,87,194,.22) 0%, transparent 55%);
    pointer-events: none; z-index: 0;
}

/* TOPBAR */
.topbar {
    background: rgba(15,21,66,.9);
    backdrop-filter: blur(20px);
    height: 62px;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
    border-bottom: 1px solid var(--gb);
    flex-shrink: 0;
    z-index: 10;
    position: relative;
}
.back-btn {
    display: flex; align-items: center; gap: 8px;
    color: var(--b8); font-weight: 600; font-size: 13px;
    text-decoration: none;
    padding: 7px 14px; border-radius: 10px;
    background: rgba(57,73,171,.2);
    border: 1px solid rgba(57,73,171,.35);
    transition: all 0.2s;
}
.back-btn:hover { background: rgba(57,73,171,.4); color: #fff; }
.topbar-title {
    font-size: 17px; font-weight: 800; color: #fff; flex: 1;
    letter-spacing: -.02em;
}
.topbar-title span { color: var(--acc2); }
.logged-as { font-size: 12px; color: var(--mut); }

/* LAYOUT */
.chat-layout {
    display: flex; flex: 1; overflow: hidden;
    position: relative; z-index: 1;
}

/* NURSE SIDEBAR */
.nurse-list {
    width: 270px;
    background: rgba(10,15,42,.85);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--gb);
    display: flex; flex-direction: column;
    flex-shrink: 0; overflow-y: auto;
}
.nurse-list-header {
    padding: 14px 18px 10px;
    font-size: 10px; font-weight: 700;
    color: var(--mut);
    text-transform: uppercase; letter-spacing: 1.2px;
    border-bottom: 1px solid var(--gb);
}
.nurse-item {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 18px;
    text-decoration: none;
    border-bottom: 1px solid rgba(255,255,255,.04);
    border-left: 3px solid transparent;
    transition: all 0.2s; cursor: pointer;
}
.nurse-item:hover { background: rgba(57,73,171,.15); }
.nurse-item.active {
    background: rgba(57,73,171,.25);
    border-left-color: var(--acc);
}
.nurse-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    background: linear-gradient(135deg, var(--b5), var(--acc));
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.nurse-name { font-size: 13px; font-weight: 600; color: #fff; }
.nurse-role { font-size: 11px; color: var(--mut); margin-top: 2px; }

/* CHAT AREA */
.chat-area {
    flex: 1; display: flex; flex-direction: column;
    overflow: hidden;
    background: rgba(10,15,42,.6);
}

/* CHAT HEADER */
.chat-header {
    background: rgba(15,21,66,.85);
    backdrop-filter: blur(20px);
    padding: 14px 24px;
    border-bottom: 1px solid var(--gb);
    display: flex; align-items: center; gap: 14px;
    flex-shrink: 0;
}
.chat-header-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg, var(--b5), var(--acc));
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff;
}
.chat-header-name { font-size: 15px; font-weight: 700; color: #fff; }
.chat-header-role { font-size: 11px; color: var(--mut); margin-top: 2px; }
.online-dot {
    width: 9px; height: 9px; border-radius: 50%;
    background: #4ade80;
    box-shadow: 0 0 0 2px rgba(74,222,128,.3);
    margin-left: auto;
}

/* MESSAGES */
.messages-box {
    flex: 1; overflow-y: auto;
    padding: 24px; display: flex;
    flex-direction: column; gap: 10px;
}
.messages-box::-webkit-scrollbar { width: 4px; }
.messages-box::-webkit-scrollbar-thumb { background: rgba(57,73,171,.4); border-radius: 2px; }

.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.sent { flex-direction: row-reverse; }

.msg-avatar-sm {
    width: 28px; height: 28px; border-radius: 50%;
    background: linear-gradient(135deg, var(--b5), var(--acc));
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.msg-avatar-sm.nurse-av {
    background: linear-gradient(135deg, #10b981, #059669);
}

.msg-bubble {
    max-width: 62%; padding: 10px 14px; border-radius: 16px;
    font-size: 13px; line-height: 1.5; word-break: break-word;
}
.msg-row.received .msg-bubble {
    background: rgba(255,255,255,.06);
    border: 1px solid var(--gb);
    color: var(--txt);
    border-bottom-left-radius: 4px;
}
.msg-row.sent .msg-bubble {
    background: linear-gradient(135deg, var(--b5), var(--acc));
    color: #fff;
    border-bottom-right-radius: 4px;
    box-shadow: 0 4px 18px rgba(57,73,171,.4);
}
.msg-time {
    font-size: 10px; color: var(--mut);
    margin-top: 3px;
}
.msg-row.sent .msg-time { text-align: right; padding-right: 38px; }
.msg-row.received .msg-time { padding-left: 38px; }

.date-sep {
    text-align: center; font-size: 10.5px; color: var(--mut);
    font-weight: 600; display: flex; align-items: center; gap: 10px;
    margin: 6px 0;
}
.date-sep::before, .date-sep::after {
    content: ''; flex: 1; height: 1px;
    background: rgba(255,255,255,.08);
}

.empty-chat {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: var(--mut); gap: 12px;
}
.empty-chat .e-icon { font-size: 46px; opacity: .5; }
.empty-chat p { font-size: 14px; }

/* INPUT BAR */
.input-bar {
    background: rgba(15,21,66,.9);
    backdrop-filter: blur(20px);
    padding: 14px 24px;
    border-top: 1px solid var(--gb);
    display: flex; gap: 10px; align-items: flex-end;
    flex-shrink: 0;
}
.msg-input {
    flex: 1; padding: 10px 16px;
    border-radius: 24px;
    border: 1px solid rgba(57,73,171,.4);
    background: rgba(255,255,255,.06);
    color: #fff; font-size: 13px;
    font-family: 'Outfit', sans-serif;
    outline: none; resize: none; max-height: 100px;
    line-height: 1.5; transition: border-color 0.2s;
}
.msg-input::placeholder { color: var(--mut); }
.msg-input:focus { border-color: var(--acc); background: rgba(255,255,255,.09); }
.send-btn {
    width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg, var(--b5), var(--acc));
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; color: #fff; flex-shrink: 0;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 4px 18px rgba(57,73,171,.5);
}
.send-btn:hover { transform: scale(1.08); box-shadow: 0 6px 24px rgba(57,73,171,.7); }
.send-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.no-selection {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: var(--mut); gap: 14px;
}
.no-selection .big-icon { font-size: 52px; opacity: .4; }
.no-selection p { font-size: 15px; font-weight: 600; }

@media(max-width:640px) {
    .nurse-list { width: 64px; }
    .nurse-name, .nurse-role, .nurse-list-header { display: none; }
    .nurse-item { justify-content: center; }
}
</style>
</head>
<body>

<div class="topbar">
    <a href="doctordashboard.php" class="back-btn">← Back</a>
    <div class="topbar-title">Chat with <span>Nurse</span></div>
    <div class="logged-as">Dr. <?= htmlspecialchars($doctor_name) ?></div>
</div>

<div class="chat-layout">

    <!-- NURSE LIST -->
    <div class="nurse-list">
        <div class="nurse-list-header">Nurses</div>
        <?php foreach ($nurses as $n):
            $ini = strtoupper(substr($n['full_name'], 0, 2));
            $isActive = $n['user_id'] == $selected_user_id;
        ?>
        <a href="?nurse=<?= $n['user_id'] ?>"
           class="nurse-item <?= $isActive ? 'active' : '' ?>">
            <div class="nurse-avatar"><?= htmlspecialchars($ini) ?></div>
            <div>
                <div class="nurse-name"><?= htmlspecialchars($n['full_name']) ?></div>
                <div class="nurse-role">Nurse</div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- CHAT AREA -->
    <div class="chat-area">

        <?php if (!$selected_user_id || empty($nurses)): ?>
        <div class="no-selection">
            <div class="big-icon">💬</div>
            <p>Select a nurse to start chatting</p>
        </div>

        <?php else: ?>

        <div class="chat-header">
            <div class="chat-header-avatar"><?= strtoupper(substr($selected_name, 0, 2)) ?></div>
            <div>
                <div class="chat-header-name"><?= htmlspecialchars($selected_name) ?></div>
                <div class="chat-header-role">Nurse</div>
            </div>
            <div class="online-dot"></div>
        </div>

        <div class="messages-box" id="messages-box">
            <?php if (empty($messages)): ?>
            <div class="empty-chat">
                <div class="e-icon">💬</div>
                <p>No messages yet — say hello!</p>
            </div>
            <?php else:
                $last_date = '';
                $doc_ini   = strtoupper(substr($doctor_name, 0, 2));
                $nur_ini   = strtoupper(substr($selected_name, 0, 2));
                foreach ($messages as $m):
                    $isSent  = $m['sender_id'] == $doctor_user_id;
                    $msgDate = date('M d, Y', strtotime($m['created_at']));
                    $msgTime = date('h:i A', strtotime($m['created_at']));
            ?>
                <?php if ($msgDate !== $last_date): $last_date = $msgDate; ?>
                <div class="date-sep"><?= $msgDate ?></div>
                <?php endif; ?>

                <div class="msg-row <?= $isSent ? 'sent' : 'received' ?>">
                    <div class="msg-avatar-sm <?= $isSent ? '' : 'nurse-av' ?>">
                        <?= $isSent ? $doc_ini : $nur_ini ?>
                    </div>
                    <div>
                        <div class="msg-bubble"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
                <div class="msg-time msg-row <?= $isSent ? 'sent' : 'received' ?>"><?= $msgTime ?></div>

            <?php endforeach; endif; ?>
        </div>

        <div class="input-bar">
            <textarea
                class="msg-input" id="msg-input" rows="1"
                placeholder="Message <?= htmlspecialchars($selected_name) ?>…"
                onkeydown="handleKey(event)"
            ></textarea>
            <button class="send-btn" id="send-btn" onclick="sendMessage()">➤</button>
        </div>

        <?php endif; ?>
    </div>
</div>

<script>
const DOCTOR_USER_ID  = <?= $doctor_user_id ?>;
const SELECTED_NUR_ID = <?= $selected_user_id ?>;
const DOC_INI         = "<?= strtoupper(substr($doctor_name, 0, 2)) ?>";
const NUR_INI         = "<?= strtoupper(substr($selected_name, 0, 2)) ?>";
let lastId            = <?= $last_id ?>;

function scrollBottom() {
    const box = document.getElementById('messages-box');
    if (box) box.scrollTop = box.scrollHeight;
}
scrollBottom();

const inp = document.getElementById('msg-input');
if (inp) {
    inp.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function sendMessage() {
    const input = document.getElementById('msg-input');
    const btn   = document.getElementById('send-btn');
    const text  = input.value.trim();
    if (!text || !SELECTED_NUR_ID) return;
    btn.disabled = true;

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ ajax_action: 'send_message', receiver_id: SELECTED_NUR_ID, message: text })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.success) { alert(data.error || 'Failed to send.'); return; }
        input.value = ''; input.style.height = 'auto';
        appendMessage(text, data.time, true);
    })
    .catch(() => { btn.disabled = false; alert('Network error.'); });
}

function appendMessage(text, time, isSent) {
    const box = document.getElementById('messages-box');
    const empty = box.querySelector('.empty-chat');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = `msg-row ${isSent ? 'sent' : 'received'}`;
    row.innerHTML = `
        <div class="msg-avatar-sm ${isSent ? '' : 'nurse-av'}">${isSent ? DOC_INI : NUR_INI}</div>
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

function pollMessages() {
    if (!SELECTED_NUR_ID) return;
    fetch(`?ajax_action=fetch_messages&other_user_id=${SELECTED_NUR_ID}&after_id=${lastId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.messages.length > 0) {
                data.messages.forEach(m => {
                    if (m.sender_id != DOCTOR_USER_ID) {
                        const time = new Date(m.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                        appendMessage(m.message, time, false);
                    }
                    lastId = Math.max(lastId, m.id);
                });
            }
        })
        .catch(() => {});
}

if (SELECTED_NUR_ID) setInterval(pollMessages, 4000);
</script>
</body>
</html>