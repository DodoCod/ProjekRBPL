<?php
// expertus-app/tim/includes/sidebar.php

// Asumsi variabel $current_page_tim, $tim_nama, $tim_email sudah didefinisikan
// di file yang meng-include sidebar ini (misal: tim/dashboard.php, tim/orders/index.php)

// Jika variabel belum didefinisikan (misal jika diakses langsung atau ada kesalahan include)
$current_page_tim = $current_page_tim ?? '';
$tim_nama = $tim_nama ?? 'Tim Pengerjaan';
$tim_email = $tim_email ?? 'tim@expertus.com'; // Sesuaikan default email

// Pastikan BASE_URL terdefinisi
if (!defined('BASE_URL')) {
    // Ini fallback, seharusnya sudah didefinisikan oleh db_config.php
    define('BASE_URL', 'http://localhost/expertus-app'); 
}
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
        <a href="<?php echo BASE_URL; ?>/tim/auth_login.php?action=logout" class="">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </nav>
</div>