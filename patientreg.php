<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------- DATABASE ----------------
$conn = new mysqli('localhost', 'root', '', 'SHAPMS');
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// ---------------- DEFAULT VALUES ----------------
$full_name = $email = $age = $gender = $contact = $address = $username = $password = "";
$error = "";

// ---------------- REGISTRATION ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $age       = trim($_POST['age'] ?? '');
    $gender    = strtolower(trim($_POST['gender'] ?? ''));
    $contact   = trim($_POST['contact'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = trim($_POST['password'] ?? '');

    // -------------- VALIDATION --------------
    if (
        empty($full_name) || empty($email) || empty($age) ||
        empty($gender) || empty($contact) || empty($address) ||
        empty($username) || empty($password)
    ) {
        $error = "All fields are required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format!";
    } elseif (!in_array($gender, ['male','female','other'])) {
        $error = "Invalid gender!";
    } else {

        // ---------- CHECK USERNAME ----------
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username=?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "Username already exists!";
        } else {

            // ---------- CHECK EMAIL ----------
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email=?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = "Email already exists!";
            } else {

                // ---------- GENERATE OTP ----------
                $otp = rand(100000, 999999);
                $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

                // ---------- STORE TEMP DATA ----------
                $_SESSION['patient_temp'] = [
                    'full_name' => $full_name,
                    'email' => $email,
                    'age' => $age,
                    'gender' => $gender,
                    'contact' => $contact,
                    'address' => $address,
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT)
                ];
                $_SESSION['patient_otp'] = $otp;
                $_SESSION['patient_otp_expiry'] = $expiry;

                // ---------- SEND OTP EMAIL ----------
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'alinaamjad814@gmail.com';           // your Gmail
                   $mail->Password = 'gqpn ooar ismh wlis';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('alinaamjad814@gmail.com', 'Zaman Medical Center');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'Patient Registration OTP';
                    $mail->Body = "
                        <h2>Hello $full_name</h2>
                        <p>Your OTP for patient registration is:</p>
                        <h1 style='color:#0077cc'>$otp</h1>
                        <p>This OTP will expire in 5 minutes.</p>
                    ";

                    $mail->send();

                    // redirect to OTP verification
                    header("Location: otppatient.php");
                    exit();

                } catch (Exception $e) {
                    $error = "Failed to send OTP. Mailer Error: " . $mail->ErrorInfo;
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
<title>Patient Registration | SHAPMS</title>

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
    background: linear-gradient(135deg, rgba(6, 28, 45, 0.82), rgba(2, 18, 30, 0.88)), url('hospital.jpg') no-repeat center center fixed;
    background-size: cover;
    position: relative;
}

/* Premium glassmorphism card (replaces .form-container) */
.form-container {
    position: relative;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(2px);
    padding: 2rem 2rem 2.2rem;
    border-radius: 2rem;
    box-shadow: 0 30px 55px -15px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.3);
    width: 100%;
    max-width: 520px;
    transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), box-shadow 0.3s ease;
    z-index: 1;
}

.form-container:hover {
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

/* registration subtitle */
.reg-sub {
    text-align: center;
    font-size: 0.85rem;
    font-weight: 500;
    color: #5f7f9a;
    margin-bottom: 1.6rem;
    padding-bottom: 0.6rem;
    border-bottom: 2px solid #eef3fc;
    display: inline-block;
    width: auto;
    margin-left: auto;
    margin-right: auto;
    letter-spacing: -0.2px;
}

/* Input, select, textarea styling - modern rounded fields */
input, select, textarea {
    width: 100%;
    font-family: 'Inter', monospace;
    padding: 0.85rem 1.2rem;
    margin: 0.4rem 0 0.9rem 0;
    border-radius: 3rem;
    border: 1.8px solid #e9eef3;
    background: #ffffff;
    font-size: 0.95rem;
    transition: all 0.25s ease;
    outline: none;
    color: #1a2f3f;
    font-weight: 500;
}

textarea {
    border-radius: 1.5rem;
    resize: vertical;
    min-height: 80px;
}

select {
    cursor: pointer;
    appearance: none;
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%235f7f9a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    background-repeat: no-repeat;
    background-position: right 1.3rem center;
    background-size: 14px;
}

input:focus, select:focus, textarea:focus {
    border-color: #2c9bc4;
    box-shadow: 0 0 0 4px rgba(44, 155, 196, 0.12);
    background-color: #ffffff;
}

input::placeholder, textarea::placeholder {
    color: #c2d3e2;
    font-weight: 450;
}

/* Labels styling */
label {
    font-weight: 600;
    font-size: 0.8rem;
    color: #1a4e62;
    letter-spacing: -0.2px;
    display: block;
    margin-top: 0.2rem;
    margin-left: 0.5rem;
}

label i {
    margin-right: 6px;
    color: #2c9bc4;
    font-size: 0.75rem;
}

/* Primary button - sleek gradient */
input[type="submit"] {
    width: 100%;
    padding: 0.95rem;
    margin-top: 0.6rem;
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

/* responsive touch */
@media (max-width: 550px) {
    .form-container {
        padding: 1.6rem;
        margin: 0 0.8rem;
    }
    h2 {
        font-size: 1.7rem;
    }
    input, select, textarea {
        padding: 0.75rem 1rem;
    }
}

/* hide browser default spinner for number input */
input[type="number"] {
    -moz-appearance: textfield;
}
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
</style>

</head>
<body>
<div class="form-container">
    <h2>
        <i class="fas fa-user-plus"></i> 
        Patient Registration
    </h2>
    <div class="reg-sub"><i class="fas fa-notes-medical"></i> Create your patient account</div>
    
    <?php if($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <label><i class="fas fa-user"></i> Full Name:</label>
        <input type="text" name="full_name" placeholder="Enter your full name" value="<?= htmlspecialchars($full_name) ?>" required>
        
        <label><i class="fas fa-envelope"></i> Email:</label>
        <input type="email" name="email" placeholder="your@email.com" value="<?= htmlspecialchars($email) ?>" required>
        
        <label><i class="fas fa-calendar-alt"></i> Age:</label>
        <input type="number" name="age" placeholder="Enter your age" value="<?= htmlspecialchars($age) ?>" required>
        
        <label><i class="fas fa-venus-mars"></i> Gender:</label>
        <select name="gender" required>
            <option value="">Select Gender</option>
            <option value="male" <?= ($gender == 'male') ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($gender == 'female') ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= ($gender == 'other') ? 'selected' : '' ?>>Other</option>
        </select>
        
        <label><i class="fas fa-phone-alt"></i> Contact:</label>
        <input type="text" name="contact" placeholder="Phone number" value="<?= htmlspecialchars($contact) ?>" required>
        
        <label><i class="fas fa-map-marker-alt"></i> Address:</label>
        <textarea name="address" placeholder="Your residential address"><?= htmlspecialchars($address) ?></textarea>
        
        <label><i class="fas fa-user-circle"></i> Username:</label>
        <input type="text" name="username" placeholder="Choose a username" value="<?= htmlspecialchars($username) ?>" required>
        
        <label><i class="fas fa-lock"></i> Password:</label>
        <input type="password" name="password" placeholder="Create a strong password" required>
        
        <input type="submit" value="Register & Verify OTP">
    </form>
</div>
</body>
</html>