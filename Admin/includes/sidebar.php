<?php
// expertus-app/admin/includes/sidebar.php

// Sertakan db_config.php untuk mendapatkan BASE_URL
require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

// Pastikan $admin_nama sudah tersedia dari dashboard.php
$profile_image_path = '/public/images/profile_placeholder.jpg';

// Tentukan halaman aktif untuk highlight di sidebar
$request_uri = $_SERVER['REQUEST_URI'];

// Logika untuk menandai link aktif
$is_dashboard_active = str_contains($request_uri, BASE_URL . '/admin/dashboard.php');
$is_orders_active = str_contains($request_uri, BASE_URL . '/admin/orders/index.php');
$is_information_active = str_contains($request_uri, BASE_URL . '/admin/berita/index.php');
$is_revenue_active = str_contains($request_uri, BASE_URL . '/admin/revenue/index.php'); // URL halaman Revenue


?>
<div class="sidebar">
    <div class="sidebar-profile">
        <div class="avatar">
            <?php if (file_exists(dirname(dirname(__DIR__)) . $profile_image_path)): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . $profile_image_path); ?>" alt="Profile" class="rounded-full w-full h-full object-cover">
            <?php else: ?>
                <i class="fas fa-user"></i>
            <?php endif; ?>
        </div>
        <div class="admin-name"><?php echo htmlspecialchars($admin_nama); ?></div>
        <div class="admin-label">Admin</div>
    </div>
    <div class="sidebar-nav">
        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="<?php echo $is_dashboard_active ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Dashboard
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/orders/index.php" class="<?php echo $is_orders_active ? 'active' : ''; ?>">
            <i class="fas fa-box"></i> Orders
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/berita/index.php" class="<?php echo $is_information_active ? 'active' : ''; ?>">
            <i class="fas fa-info-circle"></i> Information
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/revenue/index.php" class="<?php echo $is_revenue_active ? 'active' : ''; ?>"> <i class="fas fa-money-bill-wave"></i> Revenue
        </a>
    </div>
    <div class="mt-auto px-4 py-4 border-t border-gray-700">
        <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="text-gray-400 hover:text-white flex items-center">
            <i class="fas fa-sign-out-alt mr-2"></i> Logout
        </a>
    </div>
</div>