<div class="notif-wrap">
  <button class="notif-bell" id="notifBell" onclick="toggleNotifDropdown()">
    🔔
    <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
  </button>
  <div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dd-head">
      <span>Notifications</span>
      <button onclick="markAllRead()">Mark all read</button>
    </div>
    <div class="notif-dd-list" id="notifList">
      <div class="notif-empty">No notifications yet.</div>
    </div>
  </div>
</div>

<div id="toastContainer" class="toast-container"></div>

<style>
.notif-wrap{position:relative;margin-left:auto;margin-right:14px;}
.notif-bell{position:relative;width:38px;height:38px;border-radius:9px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;font-size:17px;display:grid;place-items:center;}
.notif-bell:hover{background:#f5f7ff;}
.notif-badge{position:absolute;top:-5px;right:-5px;background:#dc2626;color:#fff;font-size:.62rem;font-weight:800;border-radius:10px;padding:1px 6px;min-width:16px;text-align:center;line-height:1.4;}
.notif-dropdown{display:none;position:absolute;top:46px;right:0;width:340px;max-height:420px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.14);overflow:hidden;z-index:999;flex-direction:column;}
.notif-dropdown.open{display:flex;}
.notif-dd-head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #eef1f8;font-weight:800;font-size:.82rem;color:#1e2a4a;}
.notif-dd-head button{background:none;border:none;color:#3b82f6;font-size:.7rem;font-weight:700;cursor:pointer;}
.notif-dd-list{overflow-y:auto;max-height:370px;}
.notif-item{display:flex;flex-direction:column;gap:3px;padding:11px 16px;border-bottom:1px solid #f1f4fb;cursor:pointer;text-decoration:none;}
.notif-item:hover{background:#f7f9ff;}
.notif-item.unread{background:#eef4ff;}
.notif-item .ni-title{font-size:.78rem;font-weight:700;color:#1e2a4a;}
.notif-item .ni-msg{font-size:.72rem;color:#64748b;line-height:1.4;}
.notif-item .ni-time{font-size:.62rem;color:#9babc9;margin-top:2px;}
.notif-empty{padding:30px;text-align:center;font-size:.78rem;color:#9babc9;}

.toast-container{position:fixed;top:18px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:10px;}
.toast{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #3b82f6;border-radius:10px;padding:13px 16px;min-width:280px;max-width:340px;box-shadow:0 10px 30px rgba(0,0,0,.15);animation:toastIn .25s ease;}
.toast .t-title{font-size:.8rem;font-weight:800;color:#1e2a4a;margin-bottom:2px;}
.toast .t-msg{font-size:.74rem;color:#5a6b8c;}
@keyframes toastIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:none}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateX(30px)}}
</style>

<script>
// Set this BEFORE including this widget if your dashboard file is in a subfolder, e.g.:
// set window.NOTIF_API_PATH = '../notifications_api.php' before including this widget
// include '../includes/notification_widget.php'
if (!window.NOTIF_API_PATH) window.NOTIF_API_PATH = 'notifications_api.php';

let lastSeenId = parseInt(localStorage.getItem('notif_last_seen') || '0', 10);

function toggleNotifDropdown() {
  document.getElementById('notifDropdown').classList.toggle('open');
}
document.addEventListener('click', e => {
  if (!e.target.closest('.notif-wrap')) document.getElementById('notifDropdown').classList.remove('open');
});

function timeAgo(dateStr) {
  const diff = (Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}

function showToast(title, msg) {
  const c = document.getElementById('toastContainer');
  const el = document.createElement('div');
  el.className = 'toast';
  el.innerHTML = `<div class="t-title">${title}</div><div class="t-msg">${msg}</div>`;
  c.appendChild(el);
  setTimeout(() => { el.style.animation = 'toastOut .25s ease'; setTimeout(() => el.remove(), 250); }, 5000);
}

function markRead(id, link) {
  fetch(window.NOTIF_API_PATH + '?action=mark_read', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'id=' + id });
  if (link) window.location.href = link;
}

function markAllRead() {
  fetch(window.NOTIF_API_PATH + '?action=mark_all_read', { method: 'POST' }).then(fetchNotifications);
}

function fetchNotifications() {
  fetch(window.NOTIF_API_PATH).then(r => r.json()).then(data => {
    const badge = document.getElementById('notifBadge');
    if (data.unread > 0) { badge.style.display = 'block'; badge.textContent = data.unread > 9 ? '9+' : data.unread; }
    else badge.style.display = 'none';

    const list = document.getElementById('notifList');
    if (!data.items || data.items.length === 0) {
      list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
      return;
    }
    list.innerHTML = data.items.map(n => `
      <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="markRead(${n.id}, '${n.link || ''}')">
        <div class="ni-title">${n.title}</div>
        <div class="ni-msg">${n.message}</div>
        <div class="ni-time">${timeAgo(n.created_at)}</div>
      </div>
    `).join('');

    const newest = data.items[0];
    if (newest && newest.id > lastSeenId) {
      data.items.filter(n => n.id > lastSeenId).reverse().forEach(n => showToast(n.title, n.message));
      lastSeenId = newest.id;
      localStorage.setItem('notif_last_seen', lastSeenId);
    }
  }).catch(() => {});
}

fetchNotifications();
setInterval(fetchNotifications, 5000);
</script>