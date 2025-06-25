<?php
// expertus-app/tim/dashboard.php

// Path perbaikan: dirname(dirname(__DIR__)) akan mengarah ke C:\xampp\htdocs\ExpertUs-App\
// Jadi /includes/auth_check.php akan menjadi C:\xampp\htdocs\ExpertUs-App\includes\auth_check.php
require_once dirname(__DIR__) . '/includes/auth_check.php';
checkTimLogin(); // Menggunakan checkTimLogin

require_once dirname(__DIR__) . '/includes/db_config.php'; // Path ke db_config

// Inisialisasi variabel untuk tampilan sidebar tim
$tim_nama = $_SESSION['tim_nama'] ?? 'Tim Pengerjaan';
$tim_email = $_SESSION['tim_email'] ?? '';

// --- LOGIKA DASHBOARD TIM ---

// Menghitung total tugas yang masih belum selesai (Pending atau On Progress)
$unfinished_orders_count = 0;
global $conn; // Akses koneksi database

// Query untuk menghitung order dengan status 'Pending' atau 'On Progress'
$sql_count_unfinished = "SELECT COUNT(*) FROM `consult` WHERE `StatusPengerjaan` IN ('Pending', 'On Progress')";

   
if ($result = $conn->query($sql_count_unfinished)) {
    $row = $result->fetch_row();
    $unfinished_orders_count = $row[0];
    $result->free();
} else {
    error_log("Gagal menghitung order: " . $conn->error);
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Tim Pengerjaan</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F8FAFB;
        }
        @keyframes fadeInMoveUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Styling Sidebar */
        .sidebar {
            width: 250px; background-color: #26474E; background-image: linear-gradient(to bottom, #26474E, #1A365D);
            color: white; padding: 1.5rem 0; position: fixed; height: 100%; display: flex;
            flex-direction: column; border-top-right-radius: 1rem; border-bottom-right-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            opacity: 0; animation: fadeInMoveUp 0.6s ease-out 0.1s forwards;
        }
        .sidebar-profile { margin-bottom: 2.5rem; text-align: center; padding: 0 1.5rem; }
        .sidebar-profile .avatar {
            width: 80px; height: 80px; background-color: #6B7280; border-radius: 50%;
            margin: 0 auto 0.75rem; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; color: white;
        }
        .sidebar-profile .team-label { font-size: 0.875rem; color: #D1D5DB; } /* Label 'Team' */
        .sidebar-profile .team-name { font-size: 1.125rem; font-weight: 600; color: white; } /* Nama Tim */
        .sidebar-nav { flex-grow: 1; padding: 0 1rem; }
        .sidebar-nav a {
            display: flex; align-items: center; padding: 0.75rem 1rem; margin-bottom: 0.5rem;
            border-radius: 0.5rem; color: #D1D5DB; text-decoration: none;
            transition: background-color 0.2s, color 0.2s; font-weight: 600;
        }
        .sidebar-nav a:hover, a.active { background-color: #3B5F6C; color: white; }
        .sidebar-nav a i { margin-right: 1rem; font-size: 1.35rem; }
        .content {
            margin-left: 250px; padding: 2.5rem; min-height: 100vh; width: calc(100% - 250px);
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
                align-items: center;
            }
            .sidebar-profile .team-label,
            .sidebar-profile .team-name {
                display: none;
            }
            .sidebar-nav a span {
                display: none;
            }
            .sidebar-nav a i {
                margin-right: 0;
            }
            .content {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-radius: 0;
                box-shadow: none;
                flex-direction: row;
                justify-content: space-around;
                padding: 1rem 0;
            }
            .sidebar-profile {
                display: none;
            }
            .sidebar-nav {
                display: flex;
                flex-direction: row;
                padding: 0;
                flex-wrap: wrap;
                justify-content: center;
            }
            .sidebar-nav a {
                padding: 0.5rem 0.75rem;
                margin: 0.25rem;
            }
            .content {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body class="flex">
    <?php
    $current_page_tim = 'dashboard'; // Tandai halaman aktif untuk sidebar
    ?>
    <div class="sidebar">
        <div class="sidebar-profile">
            <div class="avatar"><i class="fas fa-user"></i></div>
            <span class="team-label">Tim Pengerjaan</span>
            <div class="team-name"><?php echo htmlspecialchars($tim_nama); ?></div>
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>/tim/dashboard.php" class="<?php echo ($current_page_tim == 'dashboard') ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tim/orders/index.php" class="<?php echo ($current_page_tim == 'orders') ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> <span>Orders</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tim/logout.php" class="">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </nav>
    </div>

    <div class="content">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Dashboard Tim Pengerjaan</h1>
        <div class="bg-white p-6 rounded-lg shadow-md">
            <p class="text-gray-700">Selamat datang, <?php echo htmlspecialchars($tim_nama); ?>!</p>
            <p class="text-gray-700">Anda memiliki <span class="font-bold text-orange-500"><?php echo $unfinished_orders_count; ?></span> order yang belum selesai.</p>
            <p class="text-gray-700 mt-4">Gunakan menu di samping untuk mengelola tugas Anda.</p>
        </div>
    </div>
</body>
</html>