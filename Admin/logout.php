<?php
// expertus-app/logout.php

session_start();

// Hapus semua variabel sesi
$_SESSION = array();
include('../Includes/db_config.php');

// Hapus sesi cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Hancurkan sesi
session_destroy();

// Menggunakan BASE_URL untuk redirect
header('location: ./auth_login.php');
exit;
?>