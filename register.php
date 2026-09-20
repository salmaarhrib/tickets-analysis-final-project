<?php
session_start();
require 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = $_POST['role'] ?? 'Standard Employee';
    $teamCode = '';

    if ($password !== $confirm) {
        $error = 'Passwords do not match. Please try again.';
    } else {
        // Validate email format: first_letter.lastname@leyton.com
        $parts = preg_split('/\s+/', $fullName);
        $isValidEmail = false;
        if (count($parts) >= 2) {
            $firstLetter   = strtolower(substr($parts[0], 0, 1));
            $lastNameClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', end($parts)));
            $expectedEmail = $firstLetter . '.' . $lastNameClean . '@leyton.com';
            $isValidEmail  = ($email === $expectedEmail);
        }

        if (!$isValidEmail) {
            $error = "Email must follow the format: first_initial.lastname@leyton.com (e.g. s.arhrib@leyton.com).";
        } else {
            $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $chk->bind_param("s", $email);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error = 'This email address is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $conn->prepare("INSERT INTO users (full_name, email, password, role, team_code, is_morocco) VALUES (?, ?, ?, ?, ?, 1)");
                $ins->bind_param("sssss", $fullName, $email, $hash, $role, $teamCode);
                if ($ins->execute()) {
                    $success = 'Account created successfully!';
                } else {
                    $error = 'Registration failed. Please try again.';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Leyton — Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        html, body {
            width: 100%;
            height: 100vh;
            overflow: hidden;
            background-color: #ffffff;
        }

        /* ── Full Screen 50/50 Split Container ── */
        .auth-container {
            display: flex;
            width: 100vw;
            height: 100vh;
        }

        /* ── LEFT: 50% Full-height Navy Brand & Graphic Panel ── */
        .auth-left {
            width: 50%;
            height: 100%;
            background: radial-gradient(circle at 40% 40%, #0d2242 0%, #061224 100%);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: 45px 50px 35px 50px;
            position: relative;
            overflow: hidden;
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* Top branding */
        .brand-header {
            text-align: center;
            z-index: 5;
        }

        .brand-logo {
            font-size: 46px;
            font-weight: 800;
            letter-spacing: 1px;
            line-height: 1;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo .ley { color: #ffffff; }
        .brand-logo .ton { color: #ffffff; display: flex; align-items: center; }
        .brand-logo .o-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: #f05a28;
            border-radius: 50%;
            color: #ffffff;
            font-size: 24px;
            font-weight: 800;
            margin: 0 2px;
        }

        .brand-slogan {
            color: #cbd5e1;
            font-size: 16px;
            font-weight: 400;
            letter-spacing: 0.5px;
        }

        /* Graphic Illustration Container */
        .graphic-container {
            width: 100%;
            max-width: 540px;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 3;
            margin: 10px 0;
        }

        .graphic-svg {
            width: 100%;
            height: 100%;
            max-height: 480px;
        }

        /* ── RIGHT: 50% Full-height Clean Form Panel ── */
        .auth-right {
            width: 50%;
            height: 100%;
            background-color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 30px 40px;
            overflow-y: auto;
        }

        .form-wrapper {
            width: 100%;
            max-width: 400px;
        }

        .auth-title {
            font-size: 30px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }

        .helper-text {
            font-size: 11px;
            color: #f05a28;
            font-weight: 500;
            margin-left: 4px;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 11px 16px;
            padding-right: 42px;
            background-color: #eef3f8;
            border: 1.5px solid #d4dfec;
            border-radius: 12px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: all 0.2s ease;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: #0b1f38;
            box-shadow: 0 0 0 3.5px rgba(11, 31, 56, 0.08);
        }

        .form-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b' width='16px' height='16px'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        .field-icon {
            position: absolute;
            right: 14px;
            color: #94a3b8;
            cursor: pointer;
            font-size: 16px;
            user-select: none;
        }

        .field-icon.check {
            color: #10b981;
            font-weight: bold;
            font-size: 16px;
            cursor: default;
        }

        .btn-submit {
            width: 100%;
            background-color: #091a33;
            color: #f05a28;
            border: none;
            padding: 13px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.3px;
            margin-top: 6px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(9, 26, 51, 0.15);
        }

        .btn-submit:hover {
            background-color: #11284d;
            box-shadow: 0 6px 18px rgba(9, 26, 51, 0.25);
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        .auth-footer {
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
            color: #64748b;
        }

        .auth-footer a {
            color: #091a33;
            font-weight: 700;
            text-decoration: none;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .alert-error {
            background-color: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 14px;
        }

        .alert-success {
            background-color: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 14px;
        }

        /* Pulse glow for map nodes */
        @keyframes pulseGlow {
            0% { r: 4; opacity: 1; }
            50% { r: 8; opacity: 0.4; }
            100% { r: 4; opacity: 1; }
        }

        .pulsing-node {
            animation: pulseGlow 2.5s infinite ease-in-out;
        }
    </style>
</head>
<body>

    <div class="auth-container">
        
        <!-- ══ LEFT: 50% Navy Branding + Global Network Graphic ══ -->
        <div class="auth-left">
            <div class="brand-header">
                <div class="brand-logo">
                    <span class="ley">LEY</span>
                    <span class="ton">T<span class="o-circle">O</span>N</span>
                </div>
                <div class="brand-slogan">Empower your future</div>
            </div>

            <div class="graphic-container">
                <svg class="graphic-svg" viewBox="0 0 550 420" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <!-- Orange Gradients -->
                        <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f05a28" stop-opacity="0.6"/>
                            <stop offset="100%" stop-color="#f05a28" stop-opacity="0.02"/>
                        </linearGradient>
                        <linearGradient id="barGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f97316"/>
                            <stop offset="100%" stop-color="#ea580c"/>
                        </linearGradient>
                        <linearGradient id="cardBgGradient" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#152e52" stop-opacity="0.8"/>
                            <stop offset="100%" stop-color="#0a1a33" stop-opacity="0.9"/>
                        </linearGradient>
                        <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
                            <feGaussianBlur stdDeviation="3" result="blur"/>
                            <feComposite in="SourceGraphic" in2="blur" operator="over"/>
                        </filter>
                    </defs>

                    <!-- ── 1. Holographic 3D Analytics Chart Card (Top Left) ── -->
                    <g transform="translate(30, 20)">
                        <!-- Isometric Card Box -->
                        <path d="M 0,40 L 160,0 L 210,35 L 50,75 Z" fill="url(#cardBgGradient)" stroke="#f05a28" stroke-width="1.2" stroke-opacity="0.7"/>
                        <path d="M 50,75 L 210,35 L 210,135 L 50,175 Z" fill="url(#cardBgGradient)" stroke="#f05a28" stroke-width="1.2" stroke-opacity="0.7"/>
                        <path d="M 0,40 L 50,75 L 50,175 L 0,140 Z" fill="#0c1e38" stroke="#f05a28" stroke-width="1.2" stroke-opacity="0.5"/>

                        <!-- Grid Lines on Chart Face -->
                        <line x1="50" y1="95" x2="210" y2="55" stroke="rgba(240,90,40,0.18)" stroke-width="1" stroke-dasharray="3 3"/>
                        <line x1="50" y1="120" x2="210" y2="80" stroke="rgba(240,90,40,0.18)" stroke-width="1" stroke-dasharray="3 3"/>
                        <line x1="50" y1="145" x2="210" y2="105" stroke="rgba(240,90,40,0.18)" stroke-width="1" stroke-dasharray="3 3"/>

                        <!-- 3D Glowing Bars -->
                        <!-- Bar 1 -->
                        <path d="M 65,160 L 77,157 L 77,135 L 65,138 Z" fill="url(#barGradient)"/>
                        <path d="M 65,138 L 77,135 L 85,137 L 73,140 Z" fill="#ff9800"/>
                        <!-- Bar 2 -->
                        <path d="M 95,152 L 107,149 L 107,110 L 95,113 Z" fill="url(#barGradient)"/>
                        <path d="M 95,113 L 107,110 L 115,112 L 103,115 Z" fill="#ff9800"/>
                        <!-- Bar 3 -->
                        <path d="M 125,145 L 137,142 L 137,90 L 125,93 Z" fill="url(#barGradient)"/>
                        <path d="M 125,93 L 137,90 L 145,92 L 133,95 Z" fill="#ff9800"/>
                        <!-- Bar 4 -->
                        <path d="M 155,137 L 167,134 L 167,105 L 155,108 Z" fill="url(#barGradient)"/>
                        <path d="M 155,108 L 167,134 L 175,136 L 163,110 Z" fill="#ff9800"/>
                        <!-- Bar 5 -->
                        <path d="M 185,130 L 197,127 L 197,70 L 185,73 Z" fill="url(#barGradient)"/>
                        <path d="M 185,73 L 197,70 L 205,72 L 193,75 Z" fill="#ff9800"/>

                        <!-- Glowing Trend Line -->
                        <path d="M 60,145 Q 90,130 110,105 T 140,85 T 170,100 T 200,60" fill="none" stroke="#ffb74d" stroke-width="2.5" filter="url(#glow)"/>
                        <path d="M 60,145 Q 90,130 110,105 T 140,85 T 170,100 T 200,60 L 200,135 L 60,170 Z" fill="url(#chartGradient)"/>
                    </g>

                    <!-- ── 2. Dotted Vector World Map ── -->
                    <g transform="translate(10, 160)" opacity="0.85">
                        <!-- North America -->
                        <path d="M 70,30 Q 90,15 130,10 Q 155,20 170,45 Q 160,80 135,100 Q 105,105 80,75 Z" fill="#132c4e" stroke="#254a78" stroke-width="1"/>
                        <!-- South America -->
                        <path d="M 125,120 Q 155,115 165,145 Q 155,200 135,225 Q 115,195 115,145 Z" fill="#132c4e" stroke="#254a78" stroke-width="1"/>
                        <!-- Europe -->
                        <path d="M 230,15 Q 275,5 295,30 Q 285,65 250,70 Q 225,50 230,15 Z" fill="#132c4e" stroke="#254a78" stroke-width="1"/>
                        <!-- Africa (Morocco highlighted) -->
                        <path d="M 225,85 Q 275,80 290,120 Q 275,195 245,215 Q 215,175 215,115 Z" fill="#132c4e" stroke="#254a78" stroke-width="1"/>
                        <!-- Asia -->
                        <path d="M 305,10 Q 405,0 440,45 Q 425,110 365,115 Q 315,85 305,10 Z" fill="#132c4e" stroke="#254a78" stroke-width="1"/>
                        <!-- Australia -->
                        <path d="M 390,150 Q 440,145 450,180 Q 425,210 385,195 Z" fill="#132c4e" stroke="#254a78" stroke-width="1"/>

                        <!-- Network Connection Arcs -->
                        <path d="M 230,95 Q 170,55 110,60" fill="none" stroke="#f05a28" stroke-width="1.6" stroke-dasharray="4 2" opacity="0.85"/>
                        <path d="M 230,95 Q 240,50 260,35" fill="none" stroke="#f05a28" stroke-width="1.6" opacity="0.9"/>
                        <path d="M 260,35 Q 320,15 365,55" fill="none" stroke="#f05a28" stroke-width="1.6" stroke-dasharray="4 2" opacity="0.85"/>
                        <path d="M 230,95 Q 290,120 405,170" fill="none" stroke="#f05a28" stroke-width="1.4" opacity="0.75"/>
                        <path d="M 230,95 Q 180,120 140,150" fill="none" stroke="#f05a28" stroke-width="1.4" opacity="0.75"/>

                        <!-- Glowing Network Nodes (Hubs) -->
                        <!-- Casablanca Hub (Main Glowing Center) -->
                        <circle cx="230" cy="95" r="5" fill="#f05a28" filter="url(#glow)"/>
                        <circle cx="230" cy="95" r="9" stroke="#f05a28" stroke-width="1.5" fill="none" class="pulsing-node"/>
                        
                        <!-- Paris / Europe Hub -->
                        <circle cx="260" cy="35" r="4.5" fill="#f97316" filter="url(#glow)"/>
                        <circle cx="260" cy="35" r="7" stroke="#f97316" stroke-width="1.2" fill="none"/>

                        <!-- London / UK Hub -->
                        <circle cx="248" cy="24" r="3.5" fill="#ff9800"/>

                        <!-- New York / Americas Hub -->
                        <circle cx="110" cy="60" r="4.5" fill="#f97316" filter="url(#glow)"/>

                        <!-- South America Hub -->
                        <circle cx="140" cy="150" r="4" fill="#f97316"/>

                        <!-- Asia Hub -->
                        <circle cx="365" cy="55" r="4.5" fill="#f97316" filter="url(#glow)"/>

                        <!-- Australia Hub -->
                        <circle cx="405" cy="170" r="4" fill="#f97316"/>

                        <!-- ── Callout Technical Labels ── -->
                        <!-- Callout 1: Global Reach (Top) -->
                        <line x1="260" y1="35" x2="295" y2="-20" stroke="#f05a28" stroke-width="1"/>
                        <line x1="295" y1="-20" x2="370" y2="-20" stroke="#f05a28" stroke-width="1"/>
                        <text x="300" y="-25" fill="#ffffff" font-size="10" font-weight="700" letter-spacing="0.5">GLOBAL REACH</text>
                        <text x="300" y="-10" fill="#94a3b8" font-size="7.5">Casablanca, Paris, London, Madrid, Warsaw</text>

                        <!-- Callout 2: Consulting Excellence (Bottom) -->
                        <line x1="230" y1="105" x2="230" y2="185" stroke="#f05a28" stroke-width="1"/>
                        <line x1="230" y1="185" x2="310" y2="185" stroke="#f05a28" stroke-width="1"/>
                        <text x="235" y="180" fill="#ffffff" font-size="10" font-weight="700" letter-spacing="0.5">CONSULTING EXCELLENCE</text>
                        <text x="235" y="195" fill="#94a3b8" font-size="7.5">Empowering digital operations and IT excellence</text>

                        <!-- Callout 3: Global Network (Left) -->
                        <line x1="110" y1="60" x2="70" y2="135" stroke="#f05a28" stroke-width="1"/>
                        <line x1="70" y1="135" x2="20" y2="135" stroke="#f05a28" stroke-width="1"/>
                        <text x="20" y="130" fill="#ffffff" font-size="9" font-weight="700">GLOBAL NETWORK</text>
                        <text x="20" y="143" fill="#94a3b8" font-size="7">Worldwide IT delivery</text>
                    </g>
                </svg>
            </div>
        </div>

        <!-- ══ RIGHT: 50% White Register Form Panel ══ -->
        <div class="auth-right">
            <div class="form-wrapper">
                <h1 class="auth-title">Join Leyton</h1>

                <?php if ($error): ?>
                    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert-success"><?= htmlspecialchars($success) ?> <a href="login.php" style="color:#091a33;font-weight:700;">Sign In</a></div>
                <?php endif; ?>

                <form method="POST" action="register.php" id="regForm">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <div class="input-wrap">
                            <input type="text" id="full_name" name="full_name" class="form-control" required placeholder="e.g. Samir Arhrib" oninput="autoEmail()">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">Corporate Email <span id="hint" class="helper-text"></span></label>
                        <div class="input-wrap">
                            <input type="email" id="email" name="email" class="form-control" required placeholder="s.arhrib@leyton.com">
                            <span class="field-icon check" id="email-check" style="display:none;">&#10003;</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role" class="form-select" required>
                            <option value="Standard Employee">Standard Employee</option>
                            <option value="Team Leader">Team Leader</option>
                            <option value="Manager">Manager</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <input type="password" id="password" name="password" class="form-control" required placeholder="Password">
                            <span class="field-icon" onclick="togglePassword('password', this)">&#128065;</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="Confirm Password" oninput="checkMatch()">
                            <span class="field-icon check" id="confirm-check" style="display:none;">&#10003;</span>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Create Account</button>
                </form>

                <div class="auth-footer">
                    Already have an account? <a href="login.php">Sign In</a>
                </div>
            </div>
        </div>

    </div>

    <script>
        let userEditedEmail = false;
        document.getElementById('email').addEventListener('input', () => { 
            userEditedEmail = true; 
            checkEmailMatch(); 
        });

        function autoEmail() {
            const name  = document.getElementById('full_name').value.trim();
            const parts = name.split(/\s+/).filter(p => p);
            const hint  = document.getElementById('hint');
            const emailInput = document.getElementById('email');

            if (parts.length >= 2) {
                const fl   = parts[0].charAt(0).toLowerCase();
                const last = parts[parts.length-1].toLowerCase().replace(/[^a-z0-9]/g,'');
                const gen  = fl + '.' + last + '@leyton.com';
                hint.textContent = '(' + gen + ')';
                if (!userEditedEmail) { 
                    emailInput.value = gen; 
                    checkEmailMatch(); 
                }
            } else {
                hint.textContent = '';
                if (!userEditedEmail) emailInput.value = '';
                checkEmailMatch();
            }
        }

        function checkEmailMatch() {
            const emailInput = document.getElementById('email');
            const check = document.getElementById('email-check');
            const val = emailInput.value;
            check.style.display = val.endsWith('@leyton.com') ? 'block' : 'none';
        }

        function checkMatch() {
            const p = document.getElementById('password').value;
            const c = document.getElementById('confirm_password').value;
            const chk = document.getElementById('confirm-check');
            chk.style.display = (c && p === c) ? 'block' : 'none';
        }

        function togglePassword(id, icon) {
            const input = document.getElementById(id);
            if (input.type === 'password') {
                input.type = 'text';
                icon.style.opacity = '1';
            } else {
                input.type = 'password';
                icon.style.opacity = '0.5';
            }
        }
    </script>
</body>
</html>
