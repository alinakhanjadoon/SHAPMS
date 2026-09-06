<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// DB Connection
$conn = new mysqli('localhost', 'root', '', 'SHAPMS');
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role       = $_POST['role'] ?? '';
    $username   = trim($_POST['username'] ?? '');
    $password   = trim($_POST['password'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);

    if (empty($role) || empty($username) || empty($password) || ($role === 'department_head' && !$department_id)) {
        $error = "All fields are required!";
    } else {

        /* =========================
           ADMIN LOGIN (HARDCODED)
        ========================== */
        if ($role === 'admin') {

            $allowedAdmins = ['Yasar Khan'=>1, 'Alina Khan'=>2];
            $adminPasswords = ['Yasar Khan'=>'1234567890', 'Alina Khan'=>'1234567890'];

            if (!isset($allowedAdmins[$username])) {
                $error = "Unauthorized admin access!";
            } elseif ($password !== $adminPasswords[$username]) {
                $error = "Incorrect admin password!";
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $allowedAdmins[$username];
                $_SESSION['role'] = 'admin';
                $_SESSION['full_name'] = $username;

                header("Location: admindashboard.php");
                exit();
            }

        } else {

            /* =========================
               NORMAL USER LOGIN
            ========================== */

            if ($role === 'department_head') {
                $stmt = $conn->prepare("SELECT user_id, full_name, role, status, department_id, password_hash 
                                        FROM users WHERE username=? AND role=? AND department_id=?");
                $stmt->bind_param("ssi", $username, $role, $department_id);
            } else {
                $stmt = $conn->prepare("SELECT user_id, full_name, role, status, department_id, password_hash 
                                        FROM users WHERE username=? AND role=?");
                $stmt->bind_param("ss", $username, $role);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {

                $user = $result->fetch_assoc();

                // Password check
                if (!password_verify($password, $user['password_hash'])) {
                    $error = "Incorrect password!";
                }

                // Approval check
                elseif ($user['status'] !== 'active') {

                    if ($user['status'] === 'inactive') {

                        // Role-based messages
                        if ($role === 'doctor' || $role === 'nurse') {
                            $error = "Your account is pending approval from Medical Department Head.";
                        } elseif ($role === 'pharmacist') {
                            $error = "Your account is pending approval from Pharmacy Department Head.";
                        } elseif ($role === 'receptionist') {
                            $error = "Your account is pending approval from Reception Department Head.";
                        } elseif ($role === 'department_head') {
                            $error = "Your account is pending admin approval.";
                        } else {
                            $error = "Your account is pending approval.";
                        }

                    } else {
                        $error = "Your account has been rejected.";
                    }

                } else {

                    // SUCCESS LOGIN
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    /* =========================
                       REDIRECTION
                    ========================== */

                    if ($role === 'department_head') {

                        switch ($user['department_id']) {
                            case 1:
                                header("Location: medicaldashboard.php");
                                break;
                            case 2:
                                header("Location: pharmacydepartmentdashboard.php");
                                break;
                            case 3:
                                header("Location: receptionistDHdashboard.php");
                                break;
                            
                                $error = "Invalid department!";
                        }

                    } else {

                        switch ($role) {
                            case 'doctor':
                                header("Location: doctordashboard.php");
                                break;
                            case 'nurse':
                                header("Location: nursedashboard.php");
                                break;
                            case 'receptionist':
                                header("Location: receptionistdasboard.php");
                                break;
                            case 'pharmacist':
                                header("Location: pharmacistdashboard.php");
                                break;
                            case 'patient':
                                header("Location: patientdashboard.php");
                                break;
                                
                            default:
                                $error = "Invalid role!";
                        }

                    }

                    exit();
                }

            } else {
                $error = "User not found. Please sign up first.";
            }
        }
    }
}

/* =========================
   FETCH DEPARTMENTS
========================= */
$deptResult = $conn->query("SELECT department_id, department_name FROM departments 
                            WHERE department_name IN ('Medical','Pharmacy','Reception')");
$departments = [];

while ($d = $deptResult->fetch_assoc()) {
    $departments[] = $d;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Login | SHAPMS</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
  :root{
    --ink-950:#061c22;
    --ink-900:#0b2b33;
    --ink-800:#123844;
    --teal-500:#12b3a8;
    --teal-400:#3fd6c9;
    --cyan-400:#4dd9ff;
    --coral-500:#ff6b6b;
    --paper:#f7fbfc;
    --slate-500:#5f7f8a;
    --slate-300:#c3dde0;
    --mint-500:#3ddc97;
  }

  *{box-sizing:border-box;}

  html,body{
    margin:0;
    min-height:100%;
    font-family:'Inter',sans-serif;
    background:var(--paper);
    color:#0f2b33;
  }

  .stage{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:40px 20px;
  }

  .panel{
    width:100%;
    max-width:1080px;
    background:#fff;
    border-radius:28px;
    overflow:hidden;
    display:grid;
    grid-template-columns:1.05fr 1fr;
    box-shadow:0 30px 70px -25px rgba(11,43,51,.35);
  }

  @media (max-width:860px){
    .panel{grid-template-columns:1fr;}
    .brand-side{display:none;}
  }

  /* ---------- LEFT: brand / vitals monitor panel ---------- */
  .brand-side{
    position:relative;
    color:#fff;
    padding:52px 48px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    overflow:hidden;
    background:
      radial-gradient(circle at 18% 12%, rgba(18,179,168,.4), transparent 45%),
      radial-gradient(circle at 85% 88%, rgba(77,217,255,.25), transparent 50%),
      linear-gradient(160deg, var(--ink-950) 0%, var(--ink-900) 55%, var(--ink-800) 100%);
  }

  .brand-side::before{
    content:"";
    position:absolute;
    inset:0;
    z-index:0;
    background-image:
      linear-gradient(rgba(77,217,255,.06) 1px, transparent 1px),
      linear-gradient(90deg, rgba(77,217,255,.06) 1px, transparent 1px);
    background-size:28px 28px;
    opacity:.6;
  }

  .vitals{
    position:absolute;
    inset:0;
    z-index:1;
    opacity:.95;
  }

  .vitals .ekg-line{
    fill:none;
    stroke-width:2;
    stroke-linecap:round;
    stroke-linejoin:round;
    stroke-dasharray:14 10;
    animation:ekgTravel 3.2s linear infinite;
  }

  .vitals .ekg-line.line2{
    stroke-width:1.4;
    opacity:.5;
    animation-duration:4.1s;
    animation-delay:.4s;
  }

  .vitals .ekg-line.line3{
    stroke-width:1.2;
    opacity:.3;
    animation-duration:5.3s;
    animation-delay:.8s;
  }

  @keyframes ekgTravel{
    to{stroke-dashoffset:-240;}
  }

  .vitals circle.node{
    filter:drop-shadow(0 0 6px rgba(77,217,255,.8));
    animation:pulseNode 2.2s ease-in-out infinite;
  }

  .vitals circle.node:nth-child(2){animation-delay:.3s;}
  .vitals circle.node:nth-child(3){animation-delay:.6s;}
  .vitals circle.node:nth-child(4){animation-delay:.9s;}
  .vitals circle.node:nth-child(5){animation-delay:1.2s;}

  @keyframes pulseNode{
    0%,100%{opacity:.4; r:2.5;}
    50%{opacity:1; r:4.5;}
  }

  .brand-mark{
    display:flex;
    align-items:center;
    gap:12px;
    font-family:'Space Grotesk',sans-serif;
    font-weight:600;
    font-size:1.1rem;
    z-index:2;
    position:relative;
  }

  .brand-mark .heart-dot{
    position:relative;
    width:12px;height:12px;
    display:flex;
    align-items:center;
    justify-content:center;
  }

  .brand-mark .heart-dot i{
    font-size:.85rem;
    color:var(--coral-500);
    filter:drop-shadow(0 0 6px rgba(255,107,107,.8));
    animation:heartbeat 1.15s ease-in-out infinite;
  }

  @keyframes heartbeat{
    0%,100%{transform:scale(1);}
    20%{transform:scale(1.3);}
    35%{transform:scale(.95);}
    50%{transform:scale(1.15);}
    65%{transform:scale(1);}
  }

  .eyebrow{
    font-family:'Space Grotesk',sans-serif;
    font-size:.72rem;
    letter-spacing:.22em;
    text-transform:uppercase;
    color:var(--teal-400);
    margin-bottom:16px;
    z-index:2;
    position:relative;
  }

  .headline{
    font-family:'Space Grotesk',sans-serif;
    font-weight:700;
    font-size:2.3rem;
    line-height:1.15;
    max-width:400px;
    margin:0 0 16px;
    z-index:2;
    position:relative;
  }

  .headline span{
    background:linear-gradient(90deg, var(--cyan-400), var(--teal-400));
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
  }

  .sub{
    color:var(--slate-300);
    max-width:360px;
    line-height:1.6;
    font-size:.95rem;
    margin:0 0 32px;
    z-index:2;
    position:relative;
  }

  .feature-list{
    display:flex;
    flex-direction:column;
    gap:14px;
    z-index:2;
    position:relative;
  }

  .feature{
    display:flex;
    align-items:center;
    gap:12px;
    font-size:.92rem;
    color:#e9fbf9;
  }

  .feature i{
    width:30px;height:30px;
    border-radius:9px;
    background:rgba(18,179,168,.2);
    border:1px solid rgba(77,217,255,.35);
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--cyan-400);
    font-size:.8rem;
    flex-shrink:0;
  }

  .foot-note{
    z-index:2;
    position:relative;
    font-size:.8rem;
    color:rgba(255,255,255,.45);
    margin-top:36px;
    display:flex;
    align-items:center;
    gap:8px;
  }

  /* ---------- RIGHT: form side ---------- */
  .form-side{
    padding:52px 48px;
    display:flex;
    flex-direction:column;
    justify-content:center;
  }

  .form-side h2{
    font-family:'Space Grotesk',sans-serif;
    font-weight:700;
    font-size:1.85rem;
    margin:0 0 6px;
    display:flex;
    align-items:center;
    gap:10px;
  }

  .form-side h2 i{
    color:var(--teal-500);
    font-size:1.55rem;
  }

  .form-side p.lead{
    color:var(--slate-500);
    margin:0 0 26px;
    font-size:.94rem;
  }

  .message{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:13px 16px;
    border-radius:14px;
    font-size:.88rem;
    margin-bottom:22px;
    line-height:1.4;
    background:#fff0f0;
    color:#b3352f;
    border:1px solid #ffd6d3;
  }

  .field{
    margin-bottom:20px;
  }

  .field label{
    display:block;
    font-size:.82rem;
    font-weight:600;
    color:#274a52;
    margin-bottom:8px;
  }

  .field label i{
    color:var(--teal-500);
    margin-right:6px;
  }

  .input-wrap{
    position:relative;
  }

  .field input, .field select{
    width:100%;
    padding:13px 44px 13px 44px;
    border-radius:13px;
    border:1.5px solid #dcecee;
    background:#fff;
    font-size:.95rem;
    font-family:'Inter',sans-serif;
    color:#0f2b33;
    outline:none;
    transition:border-color .2s ease, box-shadow .2s ease;
  }

  .field select{
    cursor:pointer;
    appearance:none;
    background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%235f7f8a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    background-repeat:no-repeat;
    background-position:right 16px center;
  }

  .field input::placeholder{color:#a9c4c9;}

  .field input:focus, .field select:focus{
    border-color:var(--teal-500);
    box-shadow:0 0 0 4px rgba(18,179,168,.14);
  }

  .field i.icon{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    color:#a9c4c9;
    font-size:.9rem;
    z-index:1;
  }

  .input-wrap:focus-within i.icon{
    color:var(--teal-500);
  }

  .toggle-pass{
    position:absolute;
    right:14px;
    top:50%;
    transform:translateY(-50%);
    background:none;
    border:none;
    color:#a9c4c9;
    cursor:pointer;
    font-size:.9rem;
    padding:4px;
  }

  #departmentDiv{
    transition:opacity .2s ease;
  }

  .submit-btn{
    width:100%;
    padding:14px;
    border:none;
    border-radius:13px;
    background:linear-gradient(90deg, var(--teal-500), #0f8f86);
    color:#fff;
    font-weight:600;
    font-size:.98rem;
    font-family:'Space Grotesk',sans-serif;
    letter-spacing:.01em;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    position:relative;
    overflow:hidden;
    transition:transform .18s ease, box-shadow .18s ease;
    box-shadow:0 10px 24px -8px rgba(18,179,168,.55);
    margin-top:6px;
  }

  .submit-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 14px 28px -8px rgba(18,179,168,.65);
  }

  .submit-btn:active{transform:translateY(0);}

  .submit-btn::after{
    content:"";
    position:absolute;
    top:0; left:-60%;
    width:40%; height:100%;
    background:linear-gradient(120deg, transparent, rgba(255,255,255,.35), transparent);
    transform:skewX(-20deg);
    transition:left .6s ease;
  }

  .submit-btn:hover::after{left:120%;}

  .switch-line{
    text-align:center;
    margin-top:22px;
    font-size:.9rem;
    color:var(--slate-500);
    padding-top:18px;
    border-top:1.5px solid #edf6f7;
  }

  .switch-line a{
    color:var(--teal-500);
    font-weight:600;
    text-decoration:none;
  }

  .switch-line a:hover{text-decoration:underline;}

  @media (prefers-reduced-motion: reduce){
    *{animation:none !important; transition:none !important;}
  }
</style>

<script>
// toggleDepartment function remains intact (no logic changes, only UI enhancement)
function toggleDepartment() {
    const roleSelect = document.querySelector('select[name="role"]');
    if (!roleSelect) return;
    const role = roleSelect.value;
    const deptDiv = document.getElementById('departmentDiv');
    if (deptDiv) {
        deptDiv.style.display = (role === 'department_head') ? 'block' : 'none';
    }
}
</script>

</head>

<body>

<div class="stage">
  <div class="panel">

    <!-- Left Side -->
    <div class="brand-side">

      <svg class="vitals" viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice">
        <path class="ekg-line" stroke="var(--cyan-400)"
          d="M0,220 L60,220 L80,220 L95,190 L110,255 L125,150 L140,270 L155,220 L600,220" />
        <path class="ekg-line line2" stroke="var(--teal-400)"
          d="M0,420 L60,420 L80,420 L95,395 L110,450 L125,360 L140,470 L155,420 L600,420" />
        <path class="ekg-line line3" stroke="var(--cyan-400)"
          d="M0,620 L60,620 L80,620 L95,595 L110,650 L125,565 L140,665 L155,620 L600,620" />

        <circle class="node" cx="125" cy="150" r="4" fill="#4dd9ff" />
        <circle class="node" cx="125" cy="360" r="4" fill="#3fd6c9" />
        <circle class="node" cx="125" cy="565" r="4" fill="#4dd9ff" />
        <circle class="node" cx="300" cy="220" r="3" fill="#ff6b6b" />
        <circle class="node" cx="450" cy="420" r="3" fill="#4dd9ff" />

        <circle r="5" fill="#4dd9ff" filter="drop-shadow(0 0 8px rgba(77,217,255,.9))">
          <animateMotion dur="3.2s" repeatCount="indefinite"
            path="M0,220 L60,220 L80,220 L95,190 L110,255 L125,150 L140,270 L155,220 L600,220" />
        </circle>
      </svg>

      <div style="position:relative; z-index:2;">
        <div class="brand-mark">
          <span class="heart-dot"><i class="fa-solid fa-heart-pulse"></i></span>
          SHAPMS
        </div>

        <div style="margin-top:34px;">
          <div class="eyebrow">Welcome back</div>
          <h1 class="headline">Sign in to keep every <span>patient on the pulse.</span></h1>
          <p class="sub">Access your dashboard, manage records, and stay on top of every department in real time.</p>
        </div>

        <div class="feature-list">
          <div class="feature"><i class="fa-solid fa-shield-heart"></i> Role-based secure access</div>
          <div class="feature"><i class="fa-solid fa-hospital"></i> All departments, one platform</div>
          <div class="feature"><i class="fa-solid fa-notes-medical"></i> Encrypted patient &amp; staff records</div>
        </div>
      </div>

      <div class="foot-note">
        <i class="fa-solid fa-lock"></i>
        Your session is private and encrypted.
      </div>

    </div>

    <!-- Right Side -->
    <div class="form-side">

      <h2><i class="fa-solid fa-hospital-user"></i> Sign In</h2>
      <p class="lead">Secure access to your SHAPMS dashboard.</p>

      <?php if ($error): ?>
        <div class="message">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>

        <div class="field">
          <label for="role"><i class="fa-solid fa-user-tie"></i>Role</label>
          <div class="input-wrap">
            <i class="fa-solid fa-users icon"></i>
            <select name="role" id="role" required onchange="toggleDepartment()">
                <option value="">Select Role</option>
                <option value="patient">Patient</option>
                <option value="admin">Admin</option>
                <option value="doctor">Doctor</option>
                <option value="nurse">Nurse</option>
                <option value="receptionist">Receptionist</option>
                <option value="pharmacist">Pharmacist</option>
                <option value="department_head">Department Head</option>
            </select>
          </div>
        </div>

        <div id="departmentDiv" class="field" style="display:none;">
          <label for="department_id"><i class="fa-solid fa-building"></i>Department</label>
          <div class="input-wrap">
            <i class="fa-solid fa-building icon"></i>
            <select name="department_id" id="department_id">
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['department_id'] ?>">
                        <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label for="username"><i class="fa-solid fa-user"></i>Username</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user icon"></i>
            <input type="text" id="username" name="username" placeholder="Enter your username" required>
          </div>
        </div>

        <div class="field">
          <label for="password"><i class="fa-solid fa-lock"></i>Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-lock icon"></i>
            <input type="password" id="password" name="password" placeholder="••••••••" required>
            <button type="button" class="toggle-pass" onclick="togglePass()"><i class="fa-solid fa-eye" id="eyeIcon"></i></button>
          </div>
        </div>

        <button type="submit" class="submit-btn">
          Login to Dashboard
          <i class="fa-solid fa-arrow-right-to-bracket"></i>
        </button>

        <p class="switch-line">
          <i class="fa-solid fa-user-plus"></i> Not registered yet? <a href="signup.php">Sign Up</a>
        </p>

      </form>

    </div>

  </div>
</div>

<script>
// ensure toggleDepartment runs after page load to set initial visibility (preserves original behavior)
document.addEventListener('DOMContentLoaded', function() {
    toggleDepartment();
});

function togglePass(){
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}
</script>

</body>
</html>