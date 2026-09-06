<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['signup_role'])) {
    header("Location: signup.php");
    exit();
}

$role = $_SESSION['signup_role'];
$success = '';
$error = '';

// DB Connection
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("DB Connection Failed: " . $conn->connect_error);
}

// Load departments
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
if (!$departments) die("DB Error: " . $conn->error);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);
    $cnic = trim($_POST['cnic'] ?? '');

    if (!$full_name || !$username || !$email || !$password || !$cnic) {
        $error = "Please fill in all required fields.";
    } else {

        /* ---------- CHECK DUPLICATE USERNAME ---------- */
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username already exists. Please choose another.";
        } else {

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $status = 'pending';

            /* ---------- ROLE LOGIC ---------- */
            if ($role === 'patient') {
                $status = 'active';
                $department_id = NULL;
            } elseif ($role === 'department_head') {
                if (!$department_id) {
                    $error = "Please select a department.";
                }
            } elseif ($role === 'doctor' || $role === 'nurse') {
                $department_id = 1;
            } elseif ($role === 'pharmacist') {
                $department_id = 2;
            } elseif ($role === 'receptionist') {
           $department_id = 3; // Reception
           } elseif ($role === 'lab') {
            $department_id = 4; // Laboratory
}

            if (!$error) {

                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (full_name, username, email, cnic, password_hash, role, department_id, status) 
                    VALUES (?,?,?,?,?,?,?,?)
                ");

                $stmt->bind_param(
                    "ssssssis",
                    $full_name,
                    $username,
                    $email,
                    $cnic,
                    $hashedPassword,
                    $role,
                    $department_id,
                    $status
                );

                if ($stmt->execute()) {

                    $user_id = $conn->insert_id;

                    /* ---------- ROLE TABLE INSERTS ---------- */
                    if ($role === 'doctor') {
                        $conn->query("INSERT INTO doctors (user_id) VALUES ($user_id)");
                    } elseif ($role === 'patient') {
                        $conn->query("INSERT INTO patients (user_id) VALUES ($user_id)");
                    } elseif ($role === 'nurse') {
                        $conn->query("INSERT INTO nurses (user_id) VALUES ($user_id)");
                   } elseif ($role === 'pharmacist') {
    $conn->query("INSERT INTO pharmacists (user_id) VALUES ($user_id)");
} elseif ($role === 'lab') {
    $conn->query("INSERT INTO lab_staff (user_id) VALUES ($user_id)");
} elseif ($role === 'receptionist') {
    $conn->query("INSERT INTO receptionists (user_id) VALUES ($user_id)");
} elseif ($role === 'department_head') {
    $conn->query("INSERT INTO department_heads (user_id, department_id) VALUES ($user_id, $department_id)");
}

                    /* ---------- SUCCESS MESSAGE ---------- */
                    if ($role === 'patient') {
                        $success = "Registration successful! You can login now.";
                    } elseif ($role === 'department_head') {
                        $success = "Registration submitted! Awaiting ADMIN approval.";
                    } else {
                        $success = "Registration submitted! Awaiting Department Head approval.";
                    }

                    unset($_SESSION['signup_role']);

                } else {
                    $error = "Database Error: " . $stmt->error;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Registration | SHAPMS</title>

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
    max-width:1120px;
    background:#fff;
    border-radius:28px;
    overflow:hidden;
    display:grid;
    grid-template-columns:1fr 1.05fr;
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

  /* faint monitor grid */
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
    font-size:1.8rem;
    margin:0 0 6px;
    display:flex;
    align-items:center;
    gap:10px;
  }

  .form-side h2 i{
    color:var(--teal-500);
    font-size:1.5rem;
  }

  .form-side p.lead{
    color:var(--slate-500);
    margin:0 0 26px;
    font-size:.94rem;
  }

  .alert{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:13px 16px;
    border-radius:14px;
    font-size:.88rem;
    margin-bottom:22px;
    line-height:1.4;
  }

  .alert.error{
    background:#fff0f0;
    color:#b3352f;
    border:1px solid #ffd6d3;
  }

  .alert.success{
    background:#ecfdf5;
    color:#177350;
    border:1px solid #baf3d8;
  }

  form{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px 16px;
  }

  .field{
    grid-column:span 2;
  }

  .field.half{
    grid-column:span 1;
  }

  @media (max-width:480px){
    .field.half{grid-column:span 2;}
  }

  .field label{
    display:block;
    font-size:.82rem;
    font-weight:600;
    color:#274a52;
    margin-bottom:8px;
  }

  .input-wrap{
    position:relative;
  }

  .field input, .field select{
    width:100%;
    padding:13px 16px 13px 44px;
    border-radius:13px;
    border:1.5px solid #dcecee;
    background:#fff;
    font-size:.95rem;
    font-family:'Inter',sans-serif;
    color:#0f2b33;
    outline:none;
    transition:border-color .2s ease, box-shadow .2s ease;
    appearance:none;
  }

  .field select{
    cursor:pointer;
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

  #deptDiv{
    grid-column:span 2;
    transition:opacity .2s ease;
  }

  .submit-btn{
    grid-column:span 2;
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

  .role-note{
    grid-column:span 2;
    font-size:.78rem;
    color:#6f8f96;
    text-align:center;
    margin-top:4px;
    padding-top:14px;
    border-top:1.5px solid #edf6f7;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
  }

  .role-note i{
    color:var(--teal-500);
  }

  @media (prefers-reduced-motion: reduce){
    *{animation:none !important; transition:none !important;}
  }
</style>

<script>
function toggleFields() {
    let role = "<?= $role ?>";
    let deptDiv = document.getElementById('deptDiv');

    if (role === 'patient') {
        if (deptDiv) deptDiv.style.display = 'none';
    } else {
        if (deptDiv) deptDiv.style.display = 'block';
    }
}

function togglePass(){
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}
</script>

</head>

<body onload="toggleFields()">

<div class="stage">
  <div class="panel">

    <!-- Left Side -->
    <div class="brand-side">

      <svg class="vitals" viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice">
        <!-- ECG traces -->
        <path class="ekg-line" stroke="var(--cyan-400)"
          d="M0,220 L60,220 L80,220 L95,190 L110,255 L125,150 L140,270 L155,220 L600,220" />
        <path class="ekg-line line2" stroke="var(--teal-400)"
          d="M0,420 L60,420 L80,420 L95,395 L110,450 L125,360 L140,470 L155,420 L600,420" />
        <path class="ekg-line line3" stroke="var(--cyan-400)"
          d="M0,620 L60,620 L80,620 L95,595 L110,650 L125,565 L140,665 L155,620 L600,620" />

        <!-- pulse nodes marking the QRS peaks -->
        <circle class="node" cx="125" cy="150" r="4" fill="#4dd9ff" />
        <circle class="node" cx="125" cy="360" r="4" fill="#3fd6c9" />
        <circle class="node" cx="125" cy="565" r="4" fill="#4dd9ff" />
        <circle class="node" cx="300" cy="220" r="3" fill="#ff6b6b" />
        <circle class="node" cx="450" cy="420" r="3" fill="#4dd9ff" />

        <!-- traveling glow along the top trace -->
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
          <div class="eyebrow">Join the care team</div>
          <h1 class="headline">Registration keeps every <span>record on the pulse.</span></h1>
          <p class="sub">Create your account to access patient records, schedules, and department tools built for hospital staff.</p>
        </div>

        <div class="feature-list">
          <div class="feature"><i class="fa-solid fa-shield-heart"></i> Role-based secure access</div>
          <div class="feature"><i class="fa-solid fa-user-check"></i> Admin &amp; department-head approval</div>
          <div class="feature"><i class="fa-solid fa-notes-medical"></i> Encrypted patient &amp; staff records</div>
        </div>
      </div>

      <div class="foot-note">
        <i class="fa-solid fa-lock"></i>
        Your information is encrypted and confidential.
      </div>

    </div>

    <!-- Right Side -->
    <div class="form-side">

      <h2><i class="fa-solid fa-id-card"></i> <?= ucfirst($role) ?> Registration</h2>
      <p class="lead">Complete your profile to join SHAPMS.</p>

      <?php if ($success): ?>
        <div class="alert success">
          <i class="fa-solid fa-circle-check"></i>
          <span><?= $success ?></span>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert error">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span><?= $error ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>

        <div class="field">
          <label for="full_name">Full name</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user icon"></i>
            <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" required>
          </div>
        </div>

        <div class="field half">
          <label for="username">Username</label>
          <div class="input-wrap">
            <i class="fa-solid fa-at icon"></i>
            <input type="text" id="username" name="username" placeholder="Choose a username" required>
          </div>
        </div>

        <div class="field half">
          <label for="email">Email</label>
          <div class="input-wrap">
            <i class="fa-solid fa-envelope icon"></i>
            <input type="email" id="email" name="email" placeholder="example@email.com" required>
          </div>
        </div>

        <div class="field half">
          <label for="cnic">CNIC</label>
          <div class="input-wrap">
            <i class="fa-solid fa-id-badge icon"></i>
            <input type="text" id="cnic" name="cnic" placeholder="35201-1234567-1" required>
          </div>
        </div>

        <div class="field half">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-lock icon"></i>
            <input type="password" id="password" name="password" placeholder="Create password" required>
            <button type="button" class="toggle-pass" onclick="togglePass()"><i class="fa-solid fa-eye" id="eyeIcon"></i></button>
          </div>
        </div>

        <div id="deptDiv" class="field">
          <label for="department_id">Department</label>
          <div class="input-wrap">
            <i class="fa-solid fa-building icon"></i>
            <select name="department_id" id="department_id">
                <option value="">Select Department</option>
                <?php
                // Reset pointer because departments may have been fetched already
                $departments->data_seek(0);
                while ($d = $departments->fetch_assoc()): ?>
                    <option value="<?= $d['department_id'] ?>">
                        <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
          </div>
        </div>

        <button type="submit" class="submit-btn">
          Register Account
          <i class="fa-solid fa-arrow-right"></i>
        </button>

        <div class="role-note">
          <i class="fa-solid fa-circle-info"></i>
          <?php if ($role === 'patient'): ?>
            Patients are activated immediately after registration.
          <?php elseif ($role === 'department_head'): ?>
            Department Head accounts require admin approval.
          <?php else: ?>
            Staff accounts require approval from Department Head.
          <?php endif; ?>
        </div>

      </form>

    </div>

  </div>
</div>

</body>
</html>