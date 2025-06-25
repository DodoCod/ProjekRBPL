<?php
if (!defined('BASE_URL')) {
    require_once dirname(__DIR__) . '/includes/db_config.php'; // Atau sertakan langsung BASE_URL jika db_config.php tidak selalu di-include
}

// Fungsi untuk memeriksa apakah admin sudah login
function checkAdminLogin() {
    session_start(); // Pastikan sesi sudah dimulai
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('location: ' . BASE_URL . '/admin/auth_login.php'); // Menggunakan BASE_URL
        exit;
    }
}

// Fungsi untuk memeriksa apakah tim pengerjaan sudah login
function checkTimLogin() {
    session_start(); // Pastikan sesi sudah dimulai
    if (!isset($_SESSION['tim_logged_in']) || $_SESSION['tim_logged_in'] !== true) {
        header('location: ' . BASE_URL . '/tim/auth_login.php'); // Menggunakan BASE_URL
        exit;
    }
}

// Fungsi untuk membersihkan input (mencegah XSS)
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
?>