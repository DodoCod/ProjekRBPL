<?php
// expertus-app/tim/auth_login.php

session_start();

if (isset($_SESSION['tim_logged_in']) && $_SESSION['tim_logged_in'] === true) {
    header('location: dashboard.php');
    exit;
}

require_once dirname(__DIR__) . '/includes/db_config.php';
require_once dirname(__DIR__) . '/includes/auth_check.php';

$email = $password = '';
$email_err = $password_err = $login_err = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize_input($_POST['email']);
    $password = sanitize_input($_POST['password']);

    if (empty($email)) {
        $email_err = 'Masukkan email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email_err = 'Format email tidak valid.';
    }

    if (empty($password)) {
        $password_err = 'Masukkan password.';
    }

    if (empty($email_err) && empty($password_err)) {
        $sql = "SELECT IdEmployee, Nama, Email, Password FROM timpengerjaan WHERE Email = ?";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('s', $param_email);
            $param_email = $email;

            if ($stmt->execute()) {
                $stmt->store_result();

                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id_tim, $nama_tim, $email_tim, $hashed_password);
                    if ($stmt->fetch()) {
                        if ($password == $hashed_password) {
                            $_SESSION['tim_logged_in'] = true;
                            $_SESSION['tim_id'] = $id_tim;
                            $_SESSION['tim_nama'] = $nama_tim;
                            $_SESSION['tim_email'] = $email_tim;

                            header('location: dashboard.php');
                            exit;
                        } else {
                            $login_err = 'Kredensial yang diberikan tidak cocok dengan catatan kami.';
                        }
                    }
                } else {
                    $login_err = 'Kredensial yang diberikan tidak cocok dengan catatan kami.';
                }
            } else {
                $login_err = 'Terjadi kesalahan sistem, silakan coba lagi nanti.';
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Tim Pengerjaan ExpertUs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <!-- Link CSS -->
     <link rel="stylesheet" href="../Public/css/form-login.css">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

</head>
<body class="flex items-center justify-center min-h-screen hero">
    <div class="overlay">
        <div class="login-card">
            <!-- Desain Sebelah Form -->
            <div class="login-shape">
                <!-- Logo -->
                <div class="logo absolute">
                    <img src="../Public/Logo/Logo1.png" alt="">
                </div>
            </div>

            <!-- Container Form -->
            <div class="login-content">
                <!-- Tulisan Login -->
                <h2 class="text-3xl font-bold text-center mb-8">Login Tim Pengerjaan</h2>

                <?php if (!empty($login_err)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm" role="alert">
                        <?php echo htmlspecialchars($login_err); ?>
                    </div>
                <?php endif; ?>

                <!-- Form Login -->
                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <div class="mb-4">
                        <input type="email" id="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($email); ?>" class="input-field <?php echo (!empty($email_err)) ? 'border-red-500' : ''; ?>" required autofocus>
                        <?php if (!empty($email_err)): ?><p class="text-red-500 text-xs italic mt-1"><?php echo htmlspecialchars($email_err); ?></p><?php endif; ?>
                    </div>

                    <div class="mb-6">
                        <input type="password" id="password" name="password" placeholder="Password" class="input-field <?php echo (!empty($password_err)) ? 'border-red-500' : ''; ?>" required>
                        <?php if (!empty($password_err)): ?><p class="text-red-500 text-xs italic mt-1"><?php echo htmlspecialchars($password_err); ?></p><?php endif; ?>
                    </div>

                    <div class="flex items-center justify-center">
                        <button type="submit" class="login-button">
                            Login 
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>