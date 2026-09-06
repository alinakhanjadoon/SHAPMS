<?php
ob_start();
session_start();
$error = "";

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_input = trim($_POST['otp'] ?? '');

    // Validate OTP session
    if (!isset($_SESSION['patient_otp'], $_SESSION['patient_temp'])) {
        $error = "No OTP found. Please register again.";
    } elseif ($otp_input != $_SESSION['patient_otp']) {
        $error = "Invalid OTP!";
    } elseif (strtotime($_SESSION['patient_otp_expiry']) < time()) {
        $error = "OTP expired! Please register again.";
    } else {
        // Connect to DB
        $conn = new mysqli('localhost', 'root', '', 'SHAPMS');
        if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

        $data = $_SESSION['patient_temp'];

        // Insert new patient into users
        $stmt = $conn->prepare("
            INSERT INTO users (full_name, username, email, password_hash, role, status)
            VALUES (?, ?, ?, ?, 'patient', 'active')
        ");
        $stmt->bind_param(
            "ssss",
            $data['full_name'],
            $data['username'],
            $data['email'],
            $data['password'] // already hashed?
        );
        $stmt->execute();

        // Get the new user ID
        $new_user_id = $conn->insert_id;

        // Set session to log in the user
        $_SESSION['user_id'] = $new_user_id;
        $_SESSION['role'] = 'patient';

        // Clear OTP temporary session
        unset($_SESSION['patient_otp'], $_SESSION['patient_otp_expiry'], $_SESSION['patient_temp']);

        $stmt->close();
        $conn->close();

        // Redirect to patient dashboard
        header("Location: /hospital/patientdashboard.php");
        exit();
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Verify OTP | SHAPMS</title>

<!-- Google Fonts + Font Awesome (purely visual) -->
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
/* ----- MODERN UI RESET (only styling changes, no logic alterations) ----- */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    margin: 0;
    padding: 1.5rem;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    background: linear-gradient(135deg, rgba(6, 28, 45, 0.82), rgba(2, 18, 30, 0.88)), url('h2.avif') no-repeat center center fixed;
    background-size: cover;
    position: relative;
}

/* Premium glassmorphism card (replaces .container) */
.container {
    position: relative;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(2px);
    padding: 2rem 2rem 2.2rem;
    border-radius: 2rem;
    box-shadow: 0 30px 55px -15px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.3);
    width: 100%;
    max-width: 420px;
    text-align: center;
    transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), box-shadow 0.3s ease;
    z-index: 1;
}

.container:hover {
    transform: translateY(-5px);
    box-shadow: 0 40px 65px -18px rgba(0, 0, 0, 0.45);
}

/* Elegant header with gradient */
h2 {
    text-align: center;
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    background: linear-gradient(115deg, #0f3b4f, #1a7f9e, #0f5f7a);
    background-clip: text;
    -webkit-background-clip: text;
    color: transparent;
    margin-bottom: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
}

h2 i {
    background: none;
    -webkit-background-clip: unset;
    color: #1a7f9e;
    font-size: 1.9rem;
}

/* OTP subtitle */
.otp-sub {
    text-align: center;
    font-size: 0.85rem;
    font-weight: 500;
    color: #5f7f9a;
    margin-bottom: 1.8rem;
    padding-bottom: 0.6rem;
    border-bottom: 2px solid #eef3fc;
    display: inline-block;
    width: auto;
    margin-left: auto;
    margin-right: auto;
    letter-spacing: -0.2px;
}

/* Input styling - modern rounded fields */
input {
    width: 100%;
    font-family: 'Inter', monospace;
    padding: 0.9rem 1.2rem;
    margin: 0.5rem 0 1rem 0;
    border-radius: 3rem;
    border: 1.8px solid #e9eef3;
    background: #ffffff;
    font-size: 1rem;
    transition: all 0.25s ease;
    outline: none;
    color: #1a2f3f;
    font-weight: 500;
    text-align: center;
    letter-spacing: 2px;
}

input:focus {
    border-color: #2c9bc4;
    box-shadow: 0 0 0 4px rgba(44, 155, 196, 0.12);
    background-color: #ffffff;
}

input::placeholder {
    color: #c2d3e2;
    font-weight: 450;
    letter-spacing: normal;
}

/* Primary button - sleek gradient */
input[type="submit"] {
    width: 100%;
    padding: 0.95rem;
    margin-top: 0.4rem;
    border: none;
    border-radius: 3rem;
    background: linear-gradient(105deg, #0f3f55, #1b789b);
    color: white;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.7rem;
    letter-spacing: 0.4px;
    box-shadow: 0 8px 20px rgba(0, 50, 70, 0.25);
    font-family: 'Inter', sans-serif;
}

input[type="submit"]:hover {
    background: linear-gradient(105deg, #0c3346, #14647f);
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(0, 45, 65, 0.3);
}

input[type="submit"]:active {
    transform: translateY(1px);
}

/* Modern error message */
.error {
    background: linear-gradient(95deg, #c0392b, #a93226);
    color: #fff;
    padding: 0.8rem 1.2rem;
    border-radius: 3rem;
    margin-bottom: 1.4rem;
    text-align: center;
    font-weight: 600;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    box-shadow: 0 4px 12px rgba(192, 57, 43, 0.2);
}

/* Info note */
.info-note {
    font-size: 0.7rem;
    color: #8aa4bc;
    text-align: center;
    margin-top: 1.2rem;
    padding-top: 0.9rem;
    border-top: 1.5px solid #edf3f8;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
}

.info-note i {
    color: #1b789b;
    font-size: 0.7rem;
}

/* responsive touch */
@media (max-width: 480px) {
    .container {
        padding: 1.6rem;
        margin: 0 0.8rem;
    }
    h2 {
        font-size: 1.7rem;
    }
    input {
        padding: 0.75rem 1rem;
    }
}
</style>

</head>
<body>
<div class="container">
    <h2>
        <i class="fas fa-key"></i> 
        Verify OTP
    </h2>
    <div class="otp-sub"><i class="fas fa-envelope-open-text"></i> Enter the 6-digit code sent to your email</div>
    
    <?php if($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="text" name="otp" placeholder="Enter 6-digit OTP" maxlength="6" autocomplete="off" required>
        <input type="submit" value="Verify OTP">
    </form>
    
    <div class="info-note">
        <i class="fas fa-clock"></i> OTP expires in 5 minutes
    </div>
</div>
</body>
</html>