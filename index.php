<?php
// expertus-app/index.php

require_once __DIR__ . '/includes/db_config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$review_status_message = '';
$review_status_type = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $service = isset($_POST['service']) ? trim($_POST['service']) : ''; // Ini sudah diambil dengan benar
    $captions = isset($_POST['captions']) ? trim($_POST['captions']) : '';

    if (empty($name) || empty($captions)) {
        $review_status_type = 'error';
        $review_status_message = "Nama dan ulasan tidak boleh kosong.";
    } elseif (strlen($name) > 100) {
        $review_status_type = 'error';
        $review_status_message = "Nama terlalu panjang (maksimal 100 karakter).";
    } elseif (strlen($captions) > 500) {
        $review_status_type = 'error';
        $review_status_message = "Ulasan terlalu panjang (maksimal 500 karakter).";
    } else {
        global $conn;

        // Pastikan koneksi hidup sebelum memulai transaksi
        if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_errno) {
            require_once __DIR__ . '/includes/db_config.php';
            global $conn;
        }

        if (!$conn || $conn->connect_errno) {
            $review_status_type = 'error';
            $review_status_message = "Gagal terhubung ke database untuk memproses ulasan. Silakan coba lagi.";
        } else {
            $conn->begin_transaction();

            try {
                $idCust = null;

                $stmt_check_cust = $conn->prepare("SELECT IdCust FROM `customer` WHERE Nama = ?");
                if (!$stmt_check_cust) { throw new Exception("Gagal menyiapkan statement cek customer: " . $conn->error); }
                $stmt_check_cust->bind_param("s", $name);
                $stmt_check_cust->execute();
                $result_check_cust = $stmt_check_cust->get_result();

                if ($result_check_cust->num_rows > 0) {
                    $row = $result_check_cust->fetch_assoc();
                    $idCust = $row['IdCust'];
                } else {
                    $stmt_insert_cust = $conn->prepare("INSERT INTO `customer` (Nama) VALUES (?)");
                    if (!$stmt_insert_cust) { throw new Exception("Gagal menyiapkan statement insert customer: " . $conn->error); }
                    $stmt_insert_cust->bind_param("s", $name);
                    if (!$stmt_insert_cust->execute()) { throw new Exception("Gagal memasukkan customer baru: " . $stmt_insert_cust->error); }
                    $idCust = $conn->insert_id;
                }

                // PERBAIKAN DI SINI: Sertakan JenisLayanan di query INSERT dan bind_param
                $stmt_insert_testimoni = $conn->prepare("INSERT INTO `testimoni` (IdCust, Text, JenisLayanan) VALUES (?, ?, ?)");
                if (!$stmt_insert_testimoni) { throw new Exception("Gagal menyiapkan statement insert testimoni: " . $conn->error); }
                $stmt_insert_testimoni->bind_param("iss", $idCust, $captions, $service); // 'iss' -> integer, string, string

                if ($stmt_insert_testimoni->execute()) {
                    $conn->commit();
                    $review_status_type = 'success';
                    $review_status_message = "Ulasan Anda berhasil dikirim! Akan ditinjau sebelum ditampilkan.";
                } else {
                    throw new Exception("Gagal memasukkan testimoni: " . $stmt_insert_testimoni->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Error saat memproses ulasan: " . $e->getMessage());
                $review_status_type = 'error';
                $review_status_message = "Terjadi kesalahan saat mengirim ulasan Anda. Silakan coba lagi.";
            } finally {
                // Biarkan koneksi tetap terbuka untuk bagian GET di bawah jika belum ditutup
            }
        }
    }
}

// Koneksi perlu diinisialisasi ulang jika ditutup di blok POST dan ada error
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    require_once __DIR__ . '/includes/db_config.php';
}
global $conn;


// Ambil Testimoni (dari customer yang terdaftar)
$testimonials = [];
try {
    // Di sini Anda juga bisa mengambil JenisLayanan jika ingin menampilkannya
    $sql_testimonials = "SELECT t.Text, c.Nama, t.JenisLayanan FROM `testimoni` t JOIN `customer` c ON t.IdCust = c.IdCust ORDER BY t.IdTest DESC LIMIT 4";
    $result_testimonials = $conn->query($sql_testimonials);
    if ($result_testimonials) {
        while ($row = $result_testimonials->fetch_assoc()) {
            $testimonials[] = $row;
        }
        $result_testimonials->free();
    } else {
        error_log("Error fetching testimonials for display: " . $conn->error);
    }
} catch (mysqli_sql_exception $e) {
    error_log("SQL Error fetching testimonials for display: " . $e->getMessage());
}

// Ambil Berita / What's New
$recent_news = [];
try {
    $sql_news = "SELECT IdBerita, Judul, Img, Text, Date FROM `berita` ORDER BY Date DESC LIMIT 6";
    $result_news = $conn->query($sql_news);
    if ($result_news) {
        while ($row = $result_news->fetch_assoc()) {
            $img_path = (empty($row['Img']) || $row['Img'] === '0')
                                ? BASE_URL . '/public/images/default_news_placeholder.jpg'
                                : BASE_URL . $row['Img'];

            $recent_news[] = [
                'IdBerita' => htmlspecialchars($row['IdBerita']),
                'Judul' => htmlspecialchars($row['Judul']),
                'Img' => htmlspecialchars($img_path),
                'Text' => htmlspecialchars($row['Text']),
                'Date' => htmlspecialchars(date('d F Y', strtotime($row['Date'])))
            ];
        }
        $result_news->free();
    } else {
        error_log("Error fetching news for display: " . $conn->error);
    }
} catch (mysqli_sql_exception $e) {
    error_log("SQL Error fetching news for display: " . $e->getMessage());
}

// Data Portofolio (Dummy/Static)
$portfolio_items_display = [
    BASE_URL . '/public/Portofolio/Portofolio2.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
    BASE_URL . '/public/Portofolio/Portofolio1.png',
];

// Data Layanan (Static)
$services_display = [
    ['icon' => 'fas fa-gavel', 'title' => 'HAKI & Legalitas'],
    ['icon' => 'fas fa-share-alt', 'title' => 'Social Media Management'],
    ['icon' => 'fas fa-camera-retro', 'title' => 'Commercial Photography'],
    ['icon' => 'fas fa-palette', 'title' => 'Visual Branding & Design'],
    ['icon' => 'fas fa-shopping-cart', 'title' => 'E-Commerce Utilization'],
];

// Ambil daftar unik Jenis Layanan untuk dropdown form review
// Anda dapat menghapus opsi statis di HTML jika semua layanan sudah ada di sini
$service_types = [];
try {
    $sql_service_types = "SELECT DISTINCT JenisLayanan FROM `consult` WHERE JenisLayanan IS NOT NULL AND JenisLayanan != '' ORDER BY JenisLayanan ASC";
    $result_service_types = $conn->query($sql_service_types);
    if ($result_service_types) {
        while ($row = $result_service_types->fetch_assoc()) {
            $service_types[] = htmlspecialchars($row['JenisLayanan']);
        }
        $result_service_types->free();
    } else {
        error_log("Error fetching service types: " . $conn->error);
    }
} catch (mysqli_sql_exception $e) {
    error_log("SQL Error fetching service types: " . $e->getMessage());
}

// Tutup koneksi setelah semua data diambil
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $conn->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExpertUs - Go Legal, Go Digital With Us</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-green-dark: #204447;
            --primary-green-light: #4A6C6F;
            --accent-green: #91D047;
            --text-dark: #1A202C;
            --text-light: #D1D5DB;
            --off-white: #F8FAFB;
            --light-green-bg: #EBF3E5;
            --whatsapp-green: #25D366;
            --cream-bg: #EFE3C2;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            min-height: 100vh;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            background-image: url('<?php echo BASE_URL; ?>/public/Background/backgroundbatik1.png');
            background-repeat: repeat;
            background-size: auto;
            line-height: 1.6;
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'DM Sans', sans-serif;
            color: var(--text-dark);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        @keyframes fadeInMoveUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-on-load {
            opacity: 0;
            animation: fadeInMoveUp 0.8s ease-out forwards;
        }

        .hero-section { animation-delay: 0.2s; }
        .our-story { animation-delay: 0.4s; }
        .portfolio-section { animation-delay: 0.6s; }
        .reviews-section { animation-delay: 0.8s; }
        .review-form-section { animation-delay: 1s; }
        .styled-news-section { animation-delay: 1.2s; }
        .services-section { animation-delay: 1.4s; }
        .footer { animation-delay: 1.6s; }


        .bg-batik-pattern { 
            background-image: url('<?php echo BASE_URL; ?>/public/Background/backgroundbatik1.png');
            background-repeat: repeat;
            background-size: auto;
        }

        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 100;
            padding: 1rem 0;
            background-color: transparent;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .navbar.scrolled {
            background-color: var(--primary-green-dark);
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .navbar .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 1.5rem;
            color: var(--primary-green-dark);
        }
        .navbar.scrolled .logo {
            color: white;
        }
        .navbar .logo img {
            height: 30px;
            width: auto;
            transition: src 0.3s ease;
        }
        .navbar .logo h1 {
            font-size: 1.4rem;
            color: inherit;
            font-weight: 700;
        }
        .navbar-links {
            display: flex;
            gap: 1.5rem;
            margin-right: 1.5rem;
            align-items: center;
        }
        .navbar-links a {
            color: var(--primary-green-dark);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            font-size: 0.95rem;
        }
        .navbar.scrolled .navbar-links a {
            color: white;
        }
        .navbar-links a:hover {
            color: var(--accent-green);
        }

        .navbar-links .whatsapp-icon-nav {
            color: var(--primary-green-dark);
            font-size: 1.6rem;
            margin-left: 1rem;
            transition: color 0.3s ease;
        }
        .navbar.scrolled .navbar-links .whatsapp-icon-nav {
            color: white;
        }
        .navbar-links .whatsapp-icon-nav:hover {
            color: var(--whatsapp-green);
        }

        .menu-toggle {
            display: none;
            font-size: 2rem;
            color: var(--primary-green-dark);
            cursor: pointer;
            margin-right: 1.5rem;
        }
        .navbar.scrolled .menu-toggle {
            color: white;
        }

        .fixed-whatsapp {
            position: fixed;
            left: 20px;
            bottom: 20px;
            z-index: 1000;
        }
        .fixed-whatsapp a {
            background-color: var(--whatsapp-green);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            text-decoration: none;
            font-size: 1.5rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .fixed-whatsapp a:hover {
            background-color: #128C7E;
            transform: translateY(-2px);
        }

        .hero-section {
            background-color: var(--cream-bg);
            color: var(--text-dark);
            padding: 8rem 0 4rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            min-height: 50vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('<?php echo BASE_URL; ?>/public/Background/backgroundbatik1.png');
            background-repeat: repeat;
            background-size: auto;
            opacity: 0.15;
            z-index: 1;
        }
        .hero-content-flex {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            width: 100%;
            position: relative;
            z-index: 2;
        }
        @media (min-width: 769px) {
            .hero-content-flex {
                flex-direction: row;
                text-align: left;
                justify-content: center;
            }
            .hero-heading-col, .hero-text-col {
                flex: 1;
                max-width: 500px;
            }
            .hero-heading-col {
                padding-right: 2rem;
            }
            .hero-text-col {
                padding-left: 2rem;
            }
        }
        .hero-content h1 {
            font-family: 'DM Sans', sans-serif;
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 0;
            line-height: 1.2;
            color: var(--text-dark);
            text-shadow: none;
        }
        .hero-content p {
            font-size: 1.1rem;
            max-width: none;
            margin-bottom: 0;
            color: var(--text-dark);
        }

        section {
            padding: 4rem 0;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        section h2.section-title {
            font-family: 'DM Sans', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }
        section p.section-description {
            font-size: 0.95rem;
            line-height: 1.7;
            max-width: 700px;
            margin: 0 auto 2.5rem;
            color: var(--text-dark);
        }

        .overlay {
            position: absolute;
            background: url('<?php echo BASE_URL; ?>/Public/Background/backgroundbatik1.png');
        }

        .our-story {
            background: linear-gradient(to bottom, var(--cream-bg) 0%, var(--primary-green-dark) 100%);
            position: relative;
            opacity: 0.9;
            z-index: 0;
            color: white;
            padding-top: 4rem;
            padding-bottom: 4rem;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .our-story .section-title {
            color: white;
            margin-bottom: 2.5rem;
        }
        .story-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            text-align: left;
            max-width: 1000px;
            margin: 0 auto;
        }
        @media (min-width: 769px) {
            .story-content {
                flex-direction: row;
                justify-content: center;
            }
            .story-image-wrapper {
                order: 1;
            }
            .story-text {
                order: 2;
            }
        }
        .story-text {
            flex: 1;
            min-width: 300px;
            max-width: 550px;
            color: rgba(255,255,255,0.9);
        }
        .story-text h2 {
             color: inherit;
        }
        .story-text p {
            margin-bottom: 1rem;
            font-size: 0.95rem;
            color: inherit;
        }
        .story-image-wrapper {
            flex: 0 0 auto;
            width: 450px;
            height: 300px;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .story-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .portfolio-section {
            background-color: white;
            padding: 4rem 0;
        }

        .portfolio-section .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .portfolio-grid-main {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* Responsif */
            gap: 1rem; /* Jarak antar item */
            max-height: 600px; /* Tinggi maksimal untuk scroll */
            overflow-y: auto; /* Aktifkan scroll vertikal jika konten melebihi max-height */
            padding-right: 15px; /* Memberikan ruang untuk scrollbar */
            scrollbar-width: thin; /* Gaya scrollbar Firefox */
            scrollbar-color: var(--accent-green) transparent; /* Warna scrollbar Firefox */
            border-radius: 0.75rem;
            box-shadow: inset 0 0 8px rgba(0,0,0,0.05); /* Bayangan dalam */
        }

        /* Styling untuk Webkit (Chrome, Safari, Edge) scrollbar */
        .portfolio-grid-main::-webkit-scrollbar {
            width: 8px;
        }

        .portfolio-grid-main::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 10px;
        }

        .portfolio-grid-main::-webkit-scrollbar-thumb {
            background-color: var(--accent-green);
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }

        .portfolio-grid-main::-webkit-scrollbar-thumb:hover {
            background-color: #7bc13f;
        }

        .portfolio-grid-item {
            position: relative;
            width: 100%;
            padding-bottom: 75%; /* Menjaga aspek rasio 4:3 (height = 75% of width) */
            overflow: hidden; /* Penting untuk menyembunyikan bagian gambar yang keluar */
            border-radius: 0.5rem;
            box-shadow: 0 3px 8px rgba(0,0,0,0.08); /* Bayangan item */
            cursor: pointer;
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out; /* Transisi lebih halus */
        }
        .portfolio-grid-item:hover {
            transform: scale(1.02); /* Sedikit membesar saat hover */
            box-shadow: 0 5px 15px rgba(0,0,0,0.15); /* Bayangan lebih tebal saat hover */
        }
        .portfolio-grid-item img {
            position: absolute; /* Menempatkan gambar secara absolut di dalam item grid */
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover; /* Penting! Memastikan gambar mengisi area tanpa terdistorsi */
            transition: transform 0.3s ease-in-out; /* Animasi zoom pada gambar saat hover */
        }
        .portfolio-grid-item:hover img {
            transform: scale(1.05); /* Gambar sedikit zoom saat hover pada item */
        }

        /* Overlay transparan pada item grid */
        .portfolio-grid-item::before {
            content: '';
            position: absolute;
            inset: 0; /* shorthand for top, right, bottom, left: 0 */
            background-color: rgba(0,0,0,0.2); /* Overlay gelap standar */
            transition: background-color 0.3s ease-in-out;
            z-index: 1; /* Pastikan overlay di atas gambar */
        }
        .portfolio-grid-item:hover::before {
            background-color: rgba(0,0,0,0); /* Hilangkan overlay saat hover */
        }
        /* Overlay khusus untuk item tertentu (misal untuk menunjukkan highlight) */
        .portfolio-grid-item:nth-child(3n)::before, /* Setiap item ke-3 */
        .portfolio-grid-item:nth-child(7n)::before { /* Setiap item ke-7 */
            background-color: rgba(145, 208, 71, 0.3); /* Overlay hijau transparan */
        }
        /* Efek saat hover pada item dengan overlay khusus */
        .portfolio-grid-item:nth-child(3n):hover::before,
        .portfolio-grid-item:nth-child(7n):hover::before {
            background-color: rgba(145, 208, 71, 0.05); /* Sedikit lebih transparan saat hover */
        }

        .reviews-section {
            background-color: var(--off-white);
            padding: 5rem 0;
            text-align: center;
        }

        .review-message {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
            font-weight: 500;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .review-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .review-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin: 0 auto;
            max-width: 900px;
            padding: 0 1.5rem;
        }

        .review-card {
            background-color: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-align: left;
            transition: transform 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }
        .review-card:hover {
            transform: translateY(-3px);
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .review-header .reviewer-info {
            flex-grow: 1;
        }
        .review-header strong {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--text-dark);
            display: block;
            margin-bottom: 0.1rem;
        }
        .review-header .service-name {
            font-size: 0.85rem;
            color: #777;
            display: block;
        }

        .review-date {
            font-size: 0.8rem;
            color: #999;
            white-space: nowrap;
            margin-left: 1rem;
        }

        .review-text {
            font-size: 0.9rem;
            color: #555;
            line-height: 1.6;
        }

        .review-form-section {
            background-color: var(--off-white);
            padding: 3rem 0;
            text-align: center;
        }
        .review-form-section .section-title {
            font-size: 1.8rem;
            color: var(--primary-green-dark);
            margin-bottom: 1rem;
        }
        .review-form {
            max-width: 500px;
            margin: 0 auto;
            padding: 1.5rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 3px 8px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            text-align: left;
        }
        .review-form input,
        .review-form select,
        .review-form textarea {
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 0.3rem;
            font-size: 0.9rem;
            width: 100%;
            box-sizing: border-box;
            color: var(--text-dark);
        }
        .review-form select {
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#333" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></svg>');
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 0.8rem;
            padding-right: 2.5rem;
        }
        .review-form textarea {
            min-height: 100px;
            resize: vertical;
        }
        .review-form button {
            background-color: var(--accent-green);
            color: white;
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 0.3rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: background-color 0.3s ease;
            width: auto;
            align-self: flex-end;
        }
        .review-form button:hover {
            background-color: #7bc13f;
        }
        .review-form .form-row {
            display: flex;
            gap: 0.8rem;
        }
        .review-form .form-row input,
        .review-form .form-row select {
            flex: 1;
        }
        .review-form-section p.text-xs {
            font-size: 0.75rem;
            color: #777;
            margin-top: 1rem;
            text-align: center;
        }

        .styled-news-section {
            padding: 4rem 0;
            background-color: var(--off-white);
            text-align: center;
        }

        .styled-news-slider-container {
            position: relative;
            max-width: 900px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .styled-news-slider::-webkit-scrollbar {
            display: none;
        }
        .styled-news-slider {
            -ms-overflow-style: none;
            scrollbar-width: none;

            display: flex;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .styled-news-card {
            flex: 0 0 calc(50% - 0.5rem);
            height: 250px;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            scroll-snap-align: start;
            position: relative;
            background-color: #eee;
        }

        .styled-news-card .card-image {
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            width: 100%;
            height: 100%;
            transition: transform 0.3s ease-in-out;
            display: flex;
            align-items: flex-end;
            justify-content: flex-start;
        }

        .styled-news-card:hover .card-image {
            transform: scale(1.05);
        }

        .styled-news-card .card-overlay {
            width: 100%;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.7) 0%, transparent 100%);
            color: white;
            padding: 1rem;
            text-align: left;
        }

        .styled-news-card .card-overlay h3 {
            font-size: 1.1rem;
            margin-bottom: 0;
            font-weight: 500;
            color: white;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }

        .slider-navigation {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.8);
            color: var(--primary-green-dark);
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.3rem;
            cursor: pointer;
            z-index: 10;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .slider-navigation:hover {
            background-color: var(--accent-green);
            color: white;
            transform: translateY(-50%) scale(1.1);
        }

        .slider-prev {
            left: -17px;
        }

        .slider-next {
            right: -17px;
        }

        .slider-dots {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .slider-dots .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #ccc;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .slider-dots .dot.active {
            background-color: var(--accent-green);
            transform: scale(1.2);
        }
        .slider-dots .dot:hover {
            background-color: var(--accent-green);
            opacity: 0.8;
        }

        .services-section {
            background-color: white;
        }
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }
        .service-card {
            background-color: #F9FAFB;
            padding: 1.5rem 1rem;
            border-radius: 0.5rem;
            box-shadow: 0 3px 8px rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: inherit;
        }
        .service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(0,0,0,0.1);
        }
        .service-card i {
            font-size: 2.5rem;
            color: var(--accent-green);
            margin-bottom: 0.8rem;
        }
        .service-card h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-green-dark);
            text-align: center;
            line-height: 1.3;
        }

        .footer {
            background-color: var(--primary-green-dark);
            color: white;
            padding: 2.5rem 0;
            text-align: center;
            font-size: 0.85rem;
        }
        .footer .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .footer .logo img {
            height: 28px;
            width: auto;
        }
        .footer .logo h1 {
            font-size: 1.3rem;
            color: white;
            font-weight: 700;
        }
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 1.2rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .footer-links a {
            color: white;
            text-decoration: none;
            font-weight: 400;
            transition: color 0.3s ease;
            font-size: 0.8rem;
        }
        .footer-links a:hover {
            color: var(--accent-green);
        }
        .social-links {
            display: flex;
            justify-content: center;
            gap: 0.8rem;
            margin-bottom: 1rem;
        }
        .social-links a {
            color: white;
            font-size: 1.3rem;
            transition: color 0.3s ease;
        }
        .social-links a:hover {
            color: var(--accent-green);
        }
        .copyright {
            color: #D1D5DB;
            font-size: 0.75rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 0.8rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 769px) {
            .navbar-container {
                padding: 0 1.5rem;
            }
            .menu-toggle {
                display: none !important;
            }
            .navbar-links {
                display: flex !important;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 0.8rem 0;
            }
            .navbar-container {
                flex-direction: column;
                align-items: center;
                padding: 0 1rem;
            }
            .navbar .logo {
                margin-left: 0;
                margin-bottom: 0.5rem;
                display: flex;
                width: 100%;
                justify-content: center;
            }
            .navbar-links {
                display: none;
                flex-direction: column;
                align-items: center;
                width: 100%;
                margin-right: 0;
                padding-bottom: 1rem;
                background-color: rgba(32, 68, 71, 0.95);
                position: absolute;
                top: 100%;
                left: 0;
            }
            .navbar-links.active {
                display: flex;
            }
            .menu-toggle {
                display: block !important;
                position: absolute;
                top: 1rem;
                right: 1rem;
            }

            .hero-section {
                padding-top: 6rem;
            }
            .hero-content-flex {
                flex-direction: column;
                text-align: center;
            }
            .hero-heading-col, .hero-text-col {
                padding-right: 0 !important;
                padding-left: 0 !important;
            }

            .story-content {
                flex-direction: column;
            }
            .story-text {
                text-align: center;
            }
            .story-image-wrapper {
                width: 100%;
                height: 250px;
            }

            section {
                padding: 3rem 0;
            }
            section h2.section-title {
                font-size: 2rem;
            }

            .portfolio-grid-main {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); /* Ukuran lebih kecil di mobile */
                max-height: 400px;
            }

            .reviews-grid {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            .review-card {
                padding: 1.2rem;
            }
            .review-header strong {
                font-size: 1rem;
            }
            .review-header .service-name, .review-date, .review-text {
                font-size: 0.85rem;
            }

            .styled-news-card {
                flex: 0 0 100%;
                height: 200px;
            }

            .slider-navigation {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .hero-content h1 {
                font-size: 2.2rem;
            }
            .navbar .logo h1 {
                font-size: 1.3rem;
            }
            .review-form .form-row {
                flex-direction: column;
                gap: 0;
            }
            .review-form .form-row input,
            .review-form .form-row select {
                margin-bottom: 0.8rem;
            }
            .portfolio-grid-main {
                grid-template-columns: 1fr;
                max-height: 300px;
            }
            .review-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.2rem;
            }
            .review-date {
                margin-left: 0;
                margin-top: 0.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="fixed-whatsapp">
        <a href="https://wa.me/6282220595895?text=Hai%20ExpertUs%2C%20aku%20tertarik%20untuk%20menggunakan%20layanan%20perusahaan%20Anda." target="_blank" rel="noopener noreferrer" title="Chat via WhatsApp">
            <i class="fab fa-whatsapp"></i>
        </a>
    </div>

    <nav class="navbar">
        <div class="container navbar-container">
            <div class="logo">
                <img id="navbarLogo" src="<?php echo BASE_URL; ?>/public/Logo/Logo3.png" alt="ExpertUs Logo">
            </div>
            <div class="navbar-links">
                <a href="#">Home</a>
                <a href="#company">Company</a>
                <a href="#portfolio">Portafolio</a>
                <a href="#reviews">Reviews</a>
                <a href="#services">Services</a>
                <a href="#about">About Us</a>
                <a href="https://wa.me/6282220595895?text=Hai%20ExpertUs%2C%20aku%20tertarik%20untuk%20menggunakan%20layanan%20perusahaan%20Anda." target="_blank" rel="noopener noreferrer" class="whatsapp-icon-nav">
                    <i class="fab fa-whatsapp"></i>
                </a>
            </div>
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <section class="hero-section">
        <div class="container hero-content-flex">
            <div class="hero-heading-col">
                <h1 class="hero-content">Go Legal <br> Go Digital <br> With Us</h1>
            </div>
            <div class="separator"></div>
            <div class="hero-text-col">
                <p class="hero-content">Expert Us adalah one stop services bagi kamu yang mau cepat tanpa ribet dalam mengurus legalitas usaha, rebranding merek, foto katalog produk, dan aktivitas sosial media</p>
            </div>
        </div>
    </section>

    <section class="overlay"></section>

    <section id="company" class="our-story">
        <div class="container">
            <h2 class="section-title">Our Story</h2>
            <div class="story-content">
                <div class="story-image-wrapper">
                    <img src="<?php echo BASE_URL; ?>/Public/images/Portofolio/CompanyFounder.png" alt="Our Story Image"> </div>
                <div class="story-text">
                    <p>Didirikan dari semangat kewirausahaan mahasiswa, Expert Us adalah konsultan bisnis yang berfokus pada pengembangan Industri Kecil Menengah (IKM) di Yogyakarta, khususnya dalam sub-sektor jasa dan perdagangan. Perjalanan kami dimulai sejak dimanifestasikan sebagai salah satu tim terbaik dari UPN "Veteran" Yogyakarta dalam Program Pembinaan Mahasiswa Wirausaha (p2MW) 2023 oleh kemenaltibud.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="portfolio" class="portfolio-section">
        <div class="container">
            <h2 class="section-title">Our Portfolio</h2>
            <p class="section-description">Lihat beberapa hasil kerja kami yang telah membantu UMKM sukses tampil lebih profesional dan menarik.</p>
            <div class="portfolio-grid-main">
                <?php foreach ($portfolio_items_display as $item_url): ?>
                    <div class="portfolio-grid-item">
                        <img src="<?php echo htmlspecialchars($item_url); ?>" alt="Portfolio Item">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="reviews" class="reviews-section">
        <div class="container">
            <h2 class="section-title">Customer Reviews</h2>
            <p class="section-description">Dengarkan apa kata pelanggan kami tentang layanan ExpertUs.</p>

            <?php if (!empty($review_status_message)): ?>
                <div class="review-message <?php echo $review_status_type; ?>">
                    <?php echo $review_status_message; ?>
                </div>
            <?php endif; ?>

            <div class="reviews-grid">
                <?php if (empty($testimonials)): ?>
                    <p class="no-data-message col-span-full">Belum ada testimoni tersedia.</p>
                <?php else: ?>
                    <?php foreach ($testimonials as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="reviewer-info">
                                    <strong><?php echo htmlspecialchars($review['Nama']); ?></strong>
                                    <?php if (!empty($review['JenisLayanan'])): ?>
                                        <span class="service-name"><?php echo htmlspecialchars($review['JenisLayanan']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="review-date">11 March 2025</span> </div>
                            <p class="review-text">"<?php echo htmlspecialchars($review['Text']); ?>"</p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="review-form-section">
        <div class="container">
            <h2 class="section-title">Bagikan review kamu di sini!</h2>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="review-form">
                <div class="form-row">
                    <input type="text" name="name" placeholder="Name" required>
                    <select name="service" required>
                        <option value="" disabled selected>-- Select Services --</option>
                        <?php foreach ($service_types as $service_type): ?>
                            <option value="<?php echo htmlspecialchars($service_type); ?>"><?php echo htmlspecialchars($service_type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <textarea name="captions" rows="4" placeholder="Captions" required></textarea>
                <button type="submit">Submit Review</button>
            </form>
            <p class="text-xs text-gray-500 mt-4">Catatan: Ulasan Anda akan ditinjau sebelum ditampilkan.</p>
        </div>
    </section>

    <section id="news" class="whats-new-section styled-news-section">
        <div class="container">
            <h2 class="section-title">What's New in ExpertUs</h2>
            <p class="section-description">Berita dan informasi terbaru seputar ExpertUs dan dunia bisnis UMKM.</p>
            <div class="styled-news-slider-container">
                <div class="slider-navigation slider-prev">
                    <i class="fas fa-chevron-left"></i>
                </div>
                <div class="styled-news-slider">
                    <?php if (empty($recent_news)): ?>
                        <p class="no-data-message col-span-full">Belum ada berita terbaru.</p>
                    <?php else: ?>
                        <?php foreach ($recent_news as $index => $news_item): ?>
                            <div class="styled-news-card">
                                <a href="<?php echo BASE_URL; ?>/detail_news.php?IdBerita=<?php echo $news_item['IdBerita']; ?>" style="text-decoration: none; color: inherit; display: block; height: 100%;">
                                    <div class="card-image" style="background-image: url('<?php echo $news_item['Img']; ?>');">
                                        <div class="card-overlay">
                                            <h3><?php echo $news_item['Judul']; ?></h3>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="slider-navigation slider-next">
                    <i class="fas fa-chevron-right"></i>
                </div>
                <?php if (!empty($recent_news) && count($recent_news) > 1): ?>
                <div class="slider-dots">
                    <?php for ($i = 0; $i < count($recent_news); $i++): ?>
                        <span class="dot <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>"></span>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section id="services" class="services-section">
        <div class="container">
            <h2 class="section-title">Our Services</h2>
            <p class="section-description">Kami menyediakan berbagai layanan untuk mendukung pertumbuhan bisnis Anda, mulai dari legalitas hingga pemasaran digital.</p>
            <div class="services-grid">
                <?php foreach ($services_display as $service): ?>
                    <a href="#" class="service-card">
                        <i class="<?php echo htmlspecialchars($service['icon']); ?>"></i>
                        <h3><?php echo htmlspecialchars($service['title']); ?></h3>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <div class="logo">
                <img src="<?php echo BASE_URL; ?>/public/Logo/Logo2.png" alt="ExpertUs Logo">
                <h1>ExpertUs</h1>
            </div>
            <div class="footer-links">
                <a href="#">Company</a>
                <a href="#about">About Us</a>
                <a href="#news">Blog</a>
                <a href="#portfolio">Portfolio</a>
                <a href="#services">Services</a>
            </div>
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-linkedin-in"></i></a>
            </div>
            <p class="copyright">© <?php echo date('Y'); ?> ExpertUs. All rights reserved.</p>
        </div>
    </footer>

    <script>
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const targetId = this.getAttribute('href');

                if (targetId === '#') {
                    e.preventDefault();
                    window.scrollTo({
                        top: 0,
                        behavior: "smooth"
                    });
                    return;
                }

                const targetElement = document.querySelector(targetId);

                if (targetElement) {
                    e.preventDefault();
                    const navbarHeight = document.querySelector('.navbar').offsetHeight;
                    const elementPosition = targetElement.getBoundingClientRect().top + window.pageYOffset;
                    const offsetPosition = elementPosition - navbarHeight - 20;

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: "smooth"
                    });
                }
                else if (targetId.startsWith('#')) {
                    e.preventDefault();
                }
            });
        });
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            const navbarLogo = document.getElementById('navbarLogo');
            const scrolledClass = 'scrolled';
            const logoDefault = '<?php echo BASE_URL; ?>/public/Logo/Logo3.png';
            const logoScrolled = '<?php echo BASE_URL; ?>/public/Logo/Logo2.png';

            if (window.scrollY > 50) {
                navbar.classList.add(scrolledClass);
                if (navbarLogo.src !== logoScrolled) {
                    navbarLogo.src = logoScrolled;
                }
            } else {
                navbar.classList.remove(scrolledClass);
                if (navbarLogo.src !== logoDefault) {
                    navbarLogo.src = logoDefault;
                }
            }
        });

        const menuToggle = document.querySelector('.menu-toggle');
        const navLinksContainer = document.querySelector('.navbar-links');

        if (menuToggle && navLinksContainer) {
            menuToggle.addEventListener('click', () => {
                navLinksContainer.classList.toggle('active');
            });
            navLinksContainer.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    if (navLinksContainer.classList.contains('active')) {
                        navLinksContainer.classList.remove('active');
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const slider = document.querySelector('.styled-news-slider');
            const prevBtn = document.querySelector('.slider-prev');
            const nextBtn = document.querySelector('.slider-next');
            const dotsContainer = document.querySelector('.slider-dots');
            const slides = document.querySelectorAll('.styled-news-card');

            if (!slider || slides.length === 0) return;

            const getSlideStepWidth = () => {
                const firstCard = slides[0];
                if (!firstCard) return 0;
                const cardStyle = window.getComputedStyle(firstCard);
                const gap = parseFloat(cardStyle.marginRight) || 0;
                const cardWidth = firstCard.offsetWidth;
                const effectiveGapPerItem = 16 / 2;

                return cardWidth + effectiveGapPerItem;
            };

            let currentSlide = 0;

            function updateSliderPosition() {
                const slideWidth = getSlideStepWidth();
                slider.scrollTo({
                    left: currentSlide * slideWidth,
                    behavior: 'smooth'
                });
                updateDots();
            }

            function updateDots() {
                if (!dotsContainer) return;
                Array.from(dotsContainer.children).forEach((dot, index) => {
                    if (index === currentSlide) {
                        dot.classList.add('active');
                    } else {
                        dot.classList.remove('active');
                    }
                });
            }

            if (slides.length <= 2) {
                if (prevBtn) prevBtn.style.display = 'none';
                if (nextBtn) nextBtn.style.display = 'none';
                if (dotsContainer) dotsContainer.style.display = 'none';
            } else {
                if (nextBtn) {
                    nextBtn.addEventListener('click', () => {
                        if (currentSlide < slides.length - 2) {
                            currentSlide++;
                        } else {
                            currentSlide = 0;
                        }
                        updateSliderPosition();
                    });
                }

                if (prevBtn) {
                    prevBtn.addEventListener('click', () => {
                        if (currentSlide > 0) {
                            currentSlide--;
                        } else {
                            currentSlide = slides.length - 2;
                            if (currentSlide < 0) currentSlide = 0;
                        }
                        updateSliderPosition();
                    });
                }
            }

            if (dotsContainer) {
                dotsContainer.addEventListener('click', (e) => {
                    if (e.target.classList.contains('dot')) {
                        currentSlide = parseInt(e.target.dataset.index);
                        updateSliderPosition();
                    }
                });
            }

            slider.addEventListener('scroll', () => {
                const scrollLeft = slider.scrollLeft;
                const slideWidth = getSlideStepWidth();
                const newCurrentSlide = Math.round(scrollLeft / slideWidth);
                if (newCurrentSlide !== currentSlide) {
                    currentSlide = newCurrentSlide;
                    updateDots();
                }
            });

            window.addEventListener('resize', updateSliderPosition);

            updateDots();
        });
    </script>
</body>
</html>