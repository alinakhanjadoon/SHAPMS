<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SHAPMS — Smart Hospital & Pharmacy Management System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
  --navy-950:#070f1a;
  --navy-900:#0b1b2b;
  --navy-800:#122436;
  --navy-700:#1b3348;
  --teal:#2dd4bf;
  --teal-soft:#7fe9db;
  --pulse:#ff4d6d;
  --pulse-soft:#ff9baa;
  --ink:#eef4f4;
  --slate:#8ea0ac;
  --glass:rgba(255,255,255,0.045);
  --glass-border:rgba(255,255,255,0.09);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
  background:var(--navy-950);
  color:var(--ink);
  font-family:'Inter',sans-serif;
  overflow-x:hidden;
  position:relative;
}
body::before{
  content:'';
  position:fixed;
  inset:0;
  background:
    radial-gradient(circle at 15% 20%, rgba(45,212,191,0.10), transparent 40%),
    radial-gradient(circle at 85% 10%, rgba(255,77,109,0.08), transparent 35%),
    radial-gradient(circle at 50% 90%, rgba(45,212,191,0.06), transparent 45%);
  pointer-events:none;
  z-index:0;
}
h1,h2,h3,.display{font-family:'Space Grotesk',sans-serif;}
.mono{font-family:'JetBrains Mono',monospace;}
.wrap{max-width:1240px;margin:0 auto;padding:0 32px;}
a{text-decoration:none;color:inherit;}

@media (prefers-reduced-motion: reduce){
  *{animation-duration:0.001s !important; animation-iteration-count:1 !important; transition-duration:0.001s !important;}
}

/* ---------- NAV ---------- */
header{
  position:fixed; top:0; left:0; right:0; z-index:100;
  backdrop-filter:blur(14px);
  background:rgba(7,15,26,0.65);
  border-bottom:1px solid var(--glass-border);
}
.nav{display:flex;align-items:center;justify-content:space-between;padding:18px 32px;max-width:1240px;margin:0 auto;}
.logo{display:flex;align-items:center;gap:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.25rem;letter-spacing:0.5px;}
.logo .pulse-dot{
  width:10px;height:10px;border-radius:50%;background:var(--pulse);
  box-shadow:0 0 0 0 rgba(255,77,109,0.6);
  animation:dotbeat 1.15s ease-in-out infinite;
}
@keyframes dotbeat{
  0%,100%{transform:scale(1); box-shadow:0 0 0 0 rgba(255,77,109,0.55);}
  30%{transform:scale(1.35); box-shadow:0 0 0 8px rgba(255,77,109,0);}
}
.navlinks{display:flex;gap:36px;font-size:0.92rem;color:var(--slate);}
.navlinks a{transition:color .25s;}
.navlinks a:hover{color:var(--teal-soft);}
.nav-cta{
  position:relative;
  display:inline-flex;align-items:center;gap:8px;
  background:linear-gradient(135deg,var(--teal),#1ea896);
  color:var(--navy-950);
  font-weight:600; font-size:0.9rem;
  padding:10px 20px;border-radius:999px;
  box-shadow:0 0 0 0 rgba(45,212,191,0.55);
  animation:ctabeat 1.15s ease-in-out infinite;
}
@keyframes ctabeat{
  0%,100%{box-shadow:0 0 0 0 rgba(45,212,191,0.45);}
  30%{box-shadow:0 0 0 10px rgba(45,212,191,0);}
}
.nav-cta:hover{filter:brightness(1.08);}
:focus-visible{outline:2px solid var(--teal-soft); outline-offset:3px;}

/* ---------- HERO ---------- */
.hero{
  position:relative;
  min-height:100vh;
  display:flex; flex-direction:column; justify-content:center;
  padding-top:130px; padding-bottom:80px;
  z-index:1;
}
.ecg-strip{
  position:absolute; top:96px; left:0; right:0;
  height:90px;
  opacity:0.9;
  z-index:0;
  mask-image:linear-gradient(90deg, transparent, black 8%, black 92%, transparent);
}
.ecg-strip svg{width:200%; height:100%; display:block;}
.ecg-line{
  fill:none; stroke:var(--pulse); stroke-width:2;
  stroke-linecap:round; stroke-linejoin:round;
  filter:drop-shadow(0 0 6px rgba(255,77,109,0.55));
  animation:ecgmove 6s linear infinite;
}
@keyframes ecgmove{ from{transform:translateX(0);} to{transform:translateX(-50%);} }

.eyebrow{
  display:inline-flex; align-items:center; gap:8px;
  color:var(--teal-soft); font-size:0.8rem; letter-spacing:1.5px; text-transform:uppercase;
  font-family:'JetBrains Mono',monospace;
  border:1px solid var(--glass-border); background:var(--glass);
  padding:6px 14px; border-radius:999px; margin-bottom:26px;
  width:fit-content;
}
.hero-grid{display:grid; grid-template-columns:1.1fr 0.9fr; gap:40px; align-items:center; position:relative;}
.hero-copy h1{
  font-size:clamp(2.6rem,5vw,4.1rem);
  line-height:1.05; font-weight:700; letter-spacing:-1px;
  margin-bottom:22px;
}
.hero-copy h1 .accent{color:var(--teal);}
.hero-copy p{
  color:var(--slate); font-size:1.08rem; max-width:520px; line-height:1.65; margin-bottom:34px;
}
.hero-actions{display:flex; align-items:center; gap:22px; flex-wrap:wrap;}
.btn-primary{
  position:relative;
  display:inline-flex; align-items:center; gap:10px;
  background:linear-gradient(135deg,var(--teal),#189384);
  color:var(--navy-950); font-weight:700; font-size:1rem;
  padding:16px 30px; border-radius:14px;
  box-shadow:0 10px 30px -8px rgba(45,212,191,0.5);
  animation:heartbeatbtn 1.15s ease-in-out infinite;
  transition:transform .2s;
}
.btn-primary:hover{transform:translateY(-2px) scale(1.02);}
@keyframes heartbeatbtn{
  0%,100%{transform:scale(1);}
  14%{transform:scale(1.045);}
  28%{transform:scale(1);}
  42%{transform:scale(1.03);}
  56%{transform:scale(1);}
}
.btn-ghost{
  display:inline-flex; align-items:center; gap:8px;
  color:var(--ink); font-size:0.95rem; font-weight:500;
  border-bottom:1px solid var(--glass-border); padding-bottom:4px;
  transition:border-color .2s, color .2s;
}
.btn-ghost:hover{color:var(--teal-soft); border-color:var(--teal-soft);}

/* floating dashboard cards */
.card-field{position:relative; height:520px;}
.float-card{
  position:absolute;
  background:var(--glass);
  border:1px solid var(--glass-border);
  backdrop-filter:blur(16px);
  border-radius:16px;
  padding:16px 18px;
  width:206px;
  box-shadow:0 20px 45px -20px rgba(0,0,0,0.6);
  animation:floaty 5.5s ease-in-out infinite;
  opacity:0;
  animation-fill-mode:forwards;
}
.float-card .fc-label{font-size:0.72rem; color:var(--slate); text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;}
.float-card .fc-value{font-family:'JetBrains Mono',monospace; font-size:1.55rem; font-weight:700;}
.float-card .fc-icon{
  width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center;
  margin-bottom:12px; font-size:0.95rem;
}
.fc1{top:0; left:10%; animation-delay:.1s, 0s;}
.fc2{top:38%; left:52%; animation-delay:.3s, 1.2s;}
.fc3{top:66%; left:4%; animation-delay:.5s, 2.1s;}
.fc4{top:12%; left:66%; animation-delay:.7s, .6s;}
.float-card{animation-name:cardIn, floaty;}
@keyframes cardIn{ to{opacity:1;} }
@keyframes floaty{
  0%,100%{transform:translateY(0);}
  50%{transform:translateY(-14px);}
}
.fc1 .fc-icon{background:rgba(45,212,191,0.16); color:var(--teal-soft);}
.fc2 .fc-icon{background:rgba(255,77,109,0.16); color:var(--pulse-soft);}
.fc3 .fc-icon{background:rgba(122,162,255,0.16); color:#a9c0ff;}
.fc4 .fc-icon{background:rgba(255,201,102,0.16); color:#ffd479;}

/* subtle parallax cross glow */
.cross-glow{
  position:absolute; right:-60px; bottom:-60px; width:340px; height:340px;
  background:radial-gradient(circle, rgba(45,212,191,0.14), transparent 70%);
  z-index:-1; pointer-events:none;
}

/* ---------- CINEMATIC SCENE (video) ---------- */
.scene{
  position:relative; height:560px;
  display:flex; align-items:center; justify-content:center;
}
.video-frame{
  position:relative; width:100%; height:100%;
  border-radius:22px; overflow:hidden;
  border:1px solid var(--glass-border);
  box-shadow:0 30px 70px -30px rgba(0,0,0,0.7), 0 0 60px -20px rgba(45,212,191,0.25);
}
.scene-video{
  width:100%; height:100%; object-fit:cover; display:block;
  filter:saturate(1.08) contrast(1.05);
}
.video-overlay{
  position:absolute; inset:0;
  background:
    linear-gradient(180deg, rgba(7,15,26,0.15), rgba(7,15,26,0.55) 78%, rgba(7,15,26,0.85)),
    linear-gradient(90deg, rgba(7,15,26,0.35), transparent 40%);
  z-index:2;
}

/* scanning sweep across whole scene */
.scan-sweep{
  position:absolute; left:0; right:0; height:2px;
  background:linear-gradient(90deg, transparent, rgba(45,212,191,0.9), transparent);
  filter:drop-shadow(0 0 8px rgba(45,212,191,0.7));
  animation:sweep 4s ease-in-out infinite;
  z-index:3;
}
@keyframes sweep{
  0%{top:0; opacity:0;}
  8%{opacity:1;}
  50%{top:100%; opacity:1;}
  58%{opacity:0;}
  100%{top:100%; opacity:0;}
}

/* vitals chips floating over the video */
.vital-chip{
  position:absolute;
  background:rgba(11,27,43,0.55); border:1px solid var(--glass-border);
  backdrop-filter:blur(10px);
  border-radius:12px; padding:8px 12px;
  font-family:'JetBrains Mono',monospace; font-size:0.78rem;
  color:var(--teal-soft); z-index:4;
  animation:chipfloat 3.6s ease-in-out infinite;
}
.vc1{top:8%; left:6%; animation-delay:0s;}
.vc2{top:16%; right:8%; animation-delay:.6s; color:var(--pulse-soft);}
.vc3{bottom:14%; left:6%; animation-delay:1.1s;}
@keyframes chipfloat{
  0%,100%{transform:translateY(0);}
  50%{transform:translateY(-10px);}
}

/* hologram dashboard panel, sits over video bottom-right */
.holo-panel{
  position:absolute; right:6%; bottom:8%; width:220px; z-index:4;
  background:linear-gradient(160deg, rgba(45,212,191,0.14), rgba(11,27,43,0.72));
  border:1px solid rgba(45,212,191,0.4);
  border-radius:14px; padding:14px 16px;
  box-shadow:0 0 30px -6px rgba(45,212,191,0.4);
  animation:holofloat 4.2s ease-in-out infinite;
}
@keyframes holofloat{
  0%,100%{transform:translateY(0);}
  50%{transform:translateY(-12px);}
}
.holo-title{
  font-family:'JetBrains Mono',monospace; font-size:0.68rem; letter-spacing:1.5px;
  text-transform:uppercase; color:var(--teal-soft); margin-bottom:10px;
  display:flex; align-items:center; gap:6px;
}
.holo-title .live-dot{
  width:6px; height:6px; border-radius:50%; background:var(--pulse);
  animation:dotbeat 1.15s ease-in-out infinite;
}
.holo-bars{display:flex; align-items:flex-end; gap:5px; height:52px; margin-bottom:10px;}
.holo-bars span{
  flex:1; background:linear-gradient(180deg, var(--teal), rgba(45,212,191,0.15));
  border-radius:3px 3px 0 0;
  animation:barrise 2.4s ease-in-out infinite;
}
.holo-bars span:nth-child(1){height:40%; animation-delay:0s;}
.holo-bars span:nth-child(2){height:70%; animation-delay:.15s;}
.holo-bars span:nth-child(3){height:55%; animation-delay:.3s;}
.holo-bars span:nth-child(4){height:90%; animation-delay:.45s;}
.holo-bars span:nth-child(5){height:65%; animation-delay:.6s;}
.holo-bars span:nth-child(6){height:80%; animation-delay:.75s;}
@keyframes barrise{
  0%,100%{transform:scaleY(0.85);}
  50%{transform:scaleY(1);}
}
.holo-line{width:100%; height:26px;}
.holo-line path{
  fill:none; stroke:var(--pulse); stroke-width:1.6;
  stroke-dasharray:200; stroke-dashoffset:200;
  animation:drawline 2.6s ease-in-out infinite;
  filter:drop-shadow(0 0 4px rgba(255,77,109,0.6));
}
@keyframes drawline{
  0%{stroke-dashoffset:200;}
  60%{stroke-dashoffset:0;}
  100%{stroke-dashoffset:0;}
}

/* ---------- SECTION SHELL ---------- */
section{position:relative; z-index:1; padding:110px 0;}
.section-head{max-width:640px; margin-bottom:64px;}
.section-eyebrow{
  font-family:'JetBrains Mono',monospace; color:var(--pulse-soft); font-size:0.8rem;
  letter-spacing:2px; text-transform:uppercase; margin-bottom:14px; display:block;
}
.section-head h2{font-size:clamp(1.9rem,3.4vw,2.6rem); font-weight:700; letter-spacing:-0.5px; margin-bottom:14px;}
.section-head p{color:var(--slate); font-size:1.02rem; line-height:1.65;}

/* ---------- WORKFLOW ---------- */
.workflow{display:flex; align-items:stretch; gap:0; overflow-x:auto; padding-bottom:8px;}
.wf-node{
  flex:1; min-width:180px; position:relative;
  background:var(--glass); border:1px solid var(--glass-border); border-radius:16px;
  padding:26px 20px; text-align:center; margin-right:28px;
  transition:transform .3s, border-color .3s, background .3s;
}
.wf-node:last-child{margin-right:0;}
.wf-node:hover{transform:translateY(-6px); border-color:rgba(45,212,191,0.4); background:rgba(45,212,191,0.05);}
.wf-node::after{
  content:'';
  position:absolute; right:-28px; top:50%; transform:translateY(-50%);
  width:28px; height:2px;
  background:repeating-linear-gradient(90deg, var(--teal) 0 6px, transparent 6px 11px);
}
.wf-node:last-child::after{display:none;}
.wf-num{font-family:'JetBrains Mono',monospace; color:var(--teal-soft); font-size:0.78rem; margin-bottom:10px;}
.wf-icon{font-size:1.6rem; color:var(--teal-soft); margin-bottom:14px;}
.wf-node h3{font-size:1rem; font-weight:600; margin-bottom:6px;}
.wf-node p{font-size:0.84rem; color:var(--slate); line-height:1.5;}

/* ---------- STATS ---------- */
.stats{display:grid; grid-template-columns:repeat(4,1fr); gap:24px;}
.stat-card{
  background:var(--glass); border:1px solid var(--glass-border); border-radius:18px;
  padding:32px 24px; text-align:left;
}
.stat-num{font-family:'JetBrains Mono',monospace; font-size:2.4rem; font-weight:700; color:var(--teal-soft);}
.stat-label{color:var(--slate); font-size:0.88rem; margin-top:8px;}

/* ---------- FEATURES ---------- */
.features-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:24px;}
.flip-card{perspective:1200px; height:210px;}
.flip-inner{
  position:relative; width:100%; height:100%; transition:transform .6s cubic-bezier(.4,.2,.2,1); transform-style:preserve-3d;
}
.flip-card:hover .flip-inner{transform:rotateY(180deg);}
.flip-front,.flip-back{
  position:absolute; inset:0; backface-visibility:hidden; border-radius:18px;
  padding:26px; border:1px solid var(--glass-border);
}
.flip-front{background:var(--glass); display:flex; flex-direction:column; justify-content:space-between;}
.flip-back{
  background:linear-gradient(145deg, rgba(45,212,191,0.14), rgba(255,77,109,0.08));
  transform:rotateY(180deg); display:flex; align-items:center; justify-content:center; text-align:center;
}
.flip-front .f-icon{font-size:1.5rem; color:var(--teal-soft);}
.flip-front h3{font-size:1.05rem; font-weight:600; margin:14px 0 6px;}
.flip-front p{font-size:0.85rem; color:var(--slate);}
.flip-back p{font-size:0.9rem; color:var(--ink); line-height:1.55;}

/* ---------- CTA BAND ---------- */
.cta-band{
  background:linear-gradient(135deg, rgba(45,212,191,0.08), rgba(255,77,109,0.06));
  border:1px solid var(--glass-border);
  border-radius:28px; padding:64px 48px; text-align:center;
  position:relative; overflow:hidden;
}
.cta-band h2{font-size:clamp(1.7rem,3vw,2.3rem); margin-bottom:14px;}
.cta-band p{color:var(--slate); margin-bottom:30px;}

/* ---------- FOOTER ---------- */
footer{border-top:1px solid var(--glass-border); padding:50px 0 34px; position:relative; z-index:1;}
.footer-top{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:24px; margin-bottom:30px;}
.footer-icons{display:flex; gap:16px;}
.footer-icons a{
  width:40px; height:40px; border-radius:50%; border:1px solid var(--glass-border);
  display:flex; align-items:center; justify-content:center; color:var(--slate);
  transition:all .25s;
}
.footer-icons a:hover{color:var(--teal-soft); border-color:var(--teal); box-shadow:0 0 16px rgba(45,212,191,0.35);}
.footer-bottom{color:var(--slate); font-size:0.82rem; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;}

/* reveal on scroll */
.reveal{opacity:0; transform:translateY(24px); transition:opacity .7s ease, transform .7s ease;}
.reveal.in{opacity:1; transform:translateY(0);}

@media(max-width:900px){
  .hero-grid{grid-template-columns:1fr;}
  .scene{height:380px; margin-top:20px;}
  .holo-panel{width:170px; padding:10px 12px;}
  .vital-chip{font-size:0.7rem; padding:6px 9px;}
  .stats{grid-template-columns:repeat(2,1fr);}
  .features-grid{grid-template-columns:1fr;}
  .navlinks{display:none;}
  .workflow{flex-direction:column;}
  .wf-node{margin-right:0; margin-bottom:20px;}
  .wf-node::after{display:none;}
}
</style>
</head>
<body>

<header>
  <nav class="nav">
    <a href="#" class="logo"><span class="pulse-dot"></span> SHAPMS</a>
    <div class="navlinks">
      <a href="#workflow">Workflow</a>
      <a href="#features">Features</a>
      <a href="#stats">Impact</a>
    </div>
    <a href="login.php" class="nav-cta"><i class="fas fa-arrow-right-to-bracket"></i> Get Started</a>
  </nav>
</header>

<main>
  <section class="hero wrap" style="max-width:1240px; margin:0 auto;">
    <div class="ecg-strip">
      <svg viewBox="0 0 1200 90" preserveAspectRatio="none">
        <path class="ecg-line" d="M0,45 L60,45 L80,45 L95,15 L110,75 L125,45 L160,45 L180,45 L195,20 L205,70 L215,45 L260,45
          L600,45 L660,45 L680,45 L695,15 L710,75 L725,45 L760,45 L780,45 L795,20 L805,70 L815,45 L860,45
          L1200,45"/>
      </svg>
    </div>

    <div class="hero-grid">
      <div class="hero-copy">
        <span class="eyebrow"><i class="fas fa-heart-pulse"></i> Every heartbeat, tracked in real time</span>
        <h1>One system. <span class="accent">Every</span> ward, department & pharmacy — <span class="accent">in sync.</span></h1>
        <p>SHAPMS watches vitals, routes patients, and keeps reception, doctors, lab and pharmacy reading from the same live record — no paperwork gap, no delay.</p>
        <div class="hero-actions">
          <a href="login.php" class="btn-primary"><i class="fas fa-heart-pulse"></i> Get Started</a>
          <a href="#workflow" class="btn-ghost"><i class="fas fa-arrow-down"></i> See how it flows</a>
        </div>
      </div>

      <div class="scene">
        <div class="video-frame">
          <video class="scene-video" autoplay muted loop playsinline preload="auto">
            <source src="kling_20260730_VIDEO_Scene__A_l_3788_0.mp4" type="video/mp4">
          </video>
          <div class="video-overlay"></div>
          <div class="scan-sweep"></div>
        </div>

        <!-- vitals chips -->
        <div class="vital-chip vc1"><i class="fas fa-heart-pulse"></i> 78 BPM</div>
        <div class="vital-chip vc2"><i class="fas fa-lungs"></i> SpO2 98%</div>
        <div class="vital-chip vc3"><i class="fas fa-wave-square"></i> Stable rhythm</div>

        <!-- hologram dashboard -->
        <div class="holo-panel">
          <div class="holo-title"><span class="live-dot"></span> Vitals Monitor · Live</div>
          <div class="holo-bars"><span></span><span></span><span></span><span></span><span></span><span></span></div>
          <svg class="holo-line" viewBox="0 0 200 40" preserveAspectRatio="none">
            <path d="M0,25 L25,25 L32,8 L40,35 L48,25 L70,25 L165,25 L172,10 L180,32 L188,25 L200,25"/>
          </svg>
        </div>
      </div>
    </div>
  </section>

  <section id="workflow" class="wrap">
    <div class="section-head reveal">
      <span class="section-eyebrow">Patient journey</span>
      <h2>Five steps, one continuous record</h2>
      <p>No department works from a different sheet. Every action updates the same patient timeline, instantly.</p>
    </div>
    <div class="workflow reveal">
      <div class="wf-node"><div class="wf-num">01</div><div class="wf-icon"><i class="fas fa-user"></i></div><h3>Patient</h3><p>Arrives or books online, profile created once.</p></div>
      <div class="wf-node"><div class="wf-num">02</div><div class="wf-icon"><i class="fas fa-bell-concierge"></i></div><h3>Reception</h3><p>Check-in, billing verification, queue routing.</p></div>
      <div class="wf-node"><div class="wf-num">03</div><div class="wf-icon"><i class="fas fa-stethoscope"></i></div><h3>Doctor</h3><p>Consultation, diagnosis, orders logged live.</p></div>
      <div class="wf-node"><div class="wf-num">04</div><div class="wf-icon"><i class="fas fa-microscope"></i></div><h3>Lab</h3><p>Sample tracking, OCR-scanned report upload.</p></div>
      <div class="wf-node"><div class="wf-num">05</div><div class="wf-icon"><i class="fas fa-prescription-bottle-medical"></i></div><h3>Pharmacy</h3><p>Prescription dispense, stock auto-updates.</p></div>
    </div>
  </section>

  <section id="stats" class="wrap">
    <div class="section-head reveal">
      <span class="section-eyebrow">Impact</span>
      <h2>Built for real hospital load</h2>
    </div>
    <div class="stats reveal">
      <div class="stat-card"><div class="stat-num" data-count="9">0</div><div class="stat-label">Role-based modules, one system</div></div>
      <div class="stat-card"><div class="stat-num" data-count="2540">0</div><div class="stat-label">Patient records under management</div></div>
      <div class="stat-card"><div class="stat-num" data-count="98" data-suffix="%">0</div><div class="stat-label">Pharmacy stock accuracy</div></div>
      <div class="stat-card"><div class="stat-num" data-count="24" data-suffix="/7"></div><div class="stat-label">Notification & alert coverage</div></div>
    </div>
  </section>

  <section id="features" class="wrap">
    <div class="section-head reveal">
      <span class="section-eyebrow">Inside SHAPMS</span>
      <h2>Every role, its own dashboard</h2>
      <p>Hover a card to see what it replaces.</p>
    </div>
    <div class="features-grid reveal">
      <div class="flip-card"><div class="flip-inner">
        <div class="flip-front"><i class="f-icon fas fa-table-columns"></i><div><h3>Role Dashboards</h3><p>Doctor, nurse, receptionist, pharmacist, lab, dept. head.</p></div></div>
        <div class="flip-back"><p>Replaces six separate registers with one login per role.</p></div>
      </div></div>
      <div class="flip-card"><div class="flip-inner">
        <div class="flip-front"><i class="f-icon fas fa-camera"></i><div><h3>OCR Lab Reports</h3><p>Scan and auto-read lab reports in seconds.</p></div></div>
        <div class="flip-back"><p>No more manual retyping of test results into the system.</p></div>
      </div></div>
      <div class="flip-card"><div class="flip-inner">
        <div class="flip-front"><i class="f-icon fas fa-bell"></i><div><h3>Live Notifications</h3><p>Every module talks to every other, instantly.</p></div></div>
        <div class="flip-back"><p>Doctors, pharmacy and reception stay in sync automatically.</p></div>
      </div></div>
      <div class="flip-card"><div class="flip-inner">
        <div class="flip-front"><i class="f-icon fas fa-receipt"></i><div><h3>Billing Verification</h3><p>Reception confirms payment before consultation.</p></div></div>
        <div class="flip-back"><p>Closes the gap between front desk and finance records.</p></div>
      </div></div>
      <div class="flip-card"><div class="flip-inner">
        <div class="flip-front"><i class="f-icon fas fa-calendar-xmark"></i><div><h3>Leave Approvals</h3><p>Doctors request leave, dept. head approves.</p></div></div>
        <div class="flip-back"><p>Staffing gaps are visible before they affect scheduling.</p></div>
      </div></div>
      <div class="flip-card"><div class="flip-inner">
        <div class="flip-front"><i class="f-icon fas fa-shield-halved"></i><div><h3>Secure by Design</h3><p>Session hardening and protected patient records.</p></div></div>
        <div class="flip-back"><p>Built to a full security audit, not bolted on after launch.</p></div>
      </div></div>
    </div>
  </section>

  <section class="wrap">
    <div class="cta-band reveal">
      <h2>Ready to bring your hospital into one system?</h2>
      <p>Reception, doctors, lab and pharmacy — one login away.</p>
      <a href="login.php" class="btn-primary"><i class="fas fa-heart-pulse"></i> Get Started</a>
    </div>
  </section>
</main>

<footer class="wrap">
  <div class="footer-top">
    <a href="#" class="logo"><span class="pulse-dot"></span> SHAPMS</a>
    <div class="footer-icons">
      <a href="#" aria-label="Email"><i class="fas fa-envelope"></i></a>
      <a href="#" aria-label="Phone"><i class="fas fa-phone"></i></a>
      <a href="#" aria-label="Location"><i class="fas fa-location-dot"></i></a>
      <a href="#" aria-label="GitHub"><i class="fab fa-github"></i></a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© 2026 SHAPMS — Smart Hospital and Pharmacy Management System</span>
    <span>Final Year Project · COMSATS University Islamabad</span>
  </div>
</footer>

<script>
// Count-up animation
function animateCount(el){
  const target = parseFloat(el.dataset.count);
  const suffix = el.dataset.suffix || '';
  const duration = 1400;
  const start = performance.now();
  function tick(now){
    const p = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - p, 3);
    const val = Math.round(target * eased);
    el.textContent = val.toLocaleString() + suffix;
    if(p < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

const countEls = document.querySelectorAll('[data-count]');
const countObserver = new IntersectionObserver((entries)=>{
  entries.forEach(entry=>{
    if(entry.isIntersecting){
      animateCount(entry.target);
      countObserver.unobserve(entry.target);
    }
  });
},{threshold:0.4});
countEls.forEach(el=>countObserver.observe(el));

// Scroll reveal
const revealEls = document.querySelectorAll('.reveal');
const revealObserver = new IntersectionObserver((entries)=>{
  entries.forEach(entry=>{
    if(entry.isIntersecting){
      entry.target.classList.add('in');
      revealObserver.unobserve(entry.target);
    }
  });
},{threshold:0.15});
revealEls.forEach(el=>revealObserver.observe(el));

// Subtle mouse parallax on hero cards
const cardField = document.querySelector('.card-field');
if(cardField){
  document.querySelector('.hero').addEventListener('mousemove', (e)=>{
    const rect = cardField.getBoundingClientRect();
    const cx = rect.left + rect.width/2;
    const cy = rect.top + rect.height/2;
    const dx = (e.clientX - cx) / rect.width;
    const dy = (e.clientY - cy) / rect.height;
    document.querySelectorAll('.float-card').forEach((card, i)=>{
      const depth = (i+1)*4;
      card.style.transform = `translate(${dx*depth}px, ${dy*depth}px)`;
    });
  });
}
</script>

</body>
</html>