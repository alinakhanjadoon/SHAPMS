<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    $_SESSION['signup_role'] = $role; // store role temporarily

    switch ($role) {
        case 'patient':
            header("Location: patientreg.php");
            exit();
        case 'admin':
            header("Location: adminreg.php");
            exit();
        case 'doctor':
        case 'nurse':
        case 'receptionist':
        case 'pharmacist':
        case 'lab':
        case 'department_head': // Department heads and staff go to staff.php
            header("Location: staff.php");
            exit();
        default:
            header("Location: signup.php");
            exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Sign Up | SHAPMS</title>

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
    max-width:980px;
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
    margin:0 0 30px;
    font-size:.94rem;
  }

  .field{
    margin-bottom:24px;
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

  .field select{
    width:100%;
    padding:13px 44px 13px 44px;
    border-radius:13px;
    border:1.5px solid #dcecee;
    background:#fff;
    font-size:.95rem;
    font-family:'Inter',sans-serif;
    color:#0f2b33;
    outline:none;
    cursor:pointer;
    appearance:none;
    transition:border-color .2s ease, box-shadow .2s ease;
    background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%235f7f8a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    background-repeat:no-repeat;
    background-position:right 16px center;
  }

  .field select:focus{
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

  @media (prefers-reduced-motion: reduce){
    *{animation:none !important; transition:none !important;}
  }
</style>
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
          <div class="eyebrow">Get started</div>
          <h1 class="headline">Every account starts with the <span>right role.</span></h1>
          <p class="sub">Tell us who you are and we'll take you to the right registration form — patient, staff, or admin.</p>
        </div>

        <div class="feature-list">
          <div class="feature"><i class="fa-solid fa-user-injured"></i> Patients get instant access</div>
          <div class="feature"><i class="fa-solid fa-user-doctor"></i> Staff accounts need department approval</div>
          <div class="feature"><i class="fa-solid fa-user-shield"></i> Admins manage the whole system</div>
        </div>
      </div>

      <div class="foot-note">
        <i class="fa-solid fa-lock"></i>
        Your information is encrypted and confidential.
      </div>

    </div>

    <!-- Right Side -->
    <div class="form-side">

      <h2><i class="fa-solid fa-user-plus"></i> Create Account</h2>
      <p class="lead">Join the Healthcare System — select your role to continue.</p>

      <form method="POST" novalidate>

        <div class="field">
          <label for="role"><i class="fa-solid fa-user-tie"></i>Select Role</label>
          <div class="input-wrap">
            <i class="fa-solid fa-users icon"></i>
            <select name="role" id="role" required>
              <option value="">Choose your role</option>
              <option value="patient">Patient</option>
              <option value="admin">Admin</option>
              <option value="doctor">Doctor</option>
              <option value="nurse">Nurse</option>
              <option value="receptionist">Receptionist</option>
              <option value="pharmacist">Pharmacist</option>
              <option value="lab">Lab Staff</option>
              <option value="department_head">Department Head</option>
            </select>
          </div>
        </div>

        <button type="submit" class="submit-btn">
          Proceed to Registration
          <i class="fa-solid fa-arrow-right"></i>
        </button>

      </form>

    </div>

  </div>
</div>

</body>
</html>