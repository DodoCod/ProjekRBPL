<?php
require_once __DIR__ . '/includes/db_config.php'; 

// --- PENGATURAN DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$news_item = null;
$error_message = '';

// 1. Ambil IdBerita dari parameter URL
if (isset($_GET['IdBerita']) && !empty($_GET['IdBerita'])) {
    $IdBerita = $_GET['IdBerita'];

    // Pastikan IdBerita adalah integer untuk mencegah SQL Injection
    if (!filter_var($IdBerita, FILTER_VALIDATE_INT)) {
        $error_message = "ID berita tidak valid.";
    } else {
        // Mengakses variabel koneksi global
        global $conn;

        try {
            // 2. Ambil detail berita dari database
            $stmt = $conn->prepare("SELECT Judul, Img, Text, Date FROM `berita` WHERE IdBerita = ?");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan statement: " . $conn->error);
            }
            $stmt->bind_param("i", $IdBerita);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                // Sanitasi dan format data untuk tampilan
                $news_item = [
                    'Judul' => htmlspecialchars($row['Judul']),
                    'Img' => (empty($row['Img']) || $row['Img'] === '0')
                                ? BASE_URL . '/public/images/default_news_placeholder.jpg'
                                : BASE_URL . $row['Img'],
                    'Text' => htmlspecialchars($row['Text']),
                    'Date' => htmlspecialchars(date('d F Y', strtotime($row['Date'])))
                ];
            } else {
                $error_message = "Berita tidak ditemukan.";
            }
            $stmt->close();

        } catch (Exception $e) {
            error_log("Error fetching news detail: " . $e->getMessage());
            $error_message = "Terjadi kesalahan saat memuat berita. Mohon coba lagi nanti.";
        } finally {
            // Tutup koneksi jika belum ditutup
            if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
                $conn->close();
            }
        }
    }
} else {
    $error_message = "Parameter ID berita tidak diberikan.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $news_item ? $news_item['Judul'] . ' - ExpertUs' : 'Berita Tidak Ditemukan'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom CSS Variables */
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

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
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

        /* --- Navbar --- */
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

        /* Fixed WhatsApp Button */
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

        /* Main Content Styling */
        .news-detail-section {
            padding: 4rem 0;
            background-color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-top: 6rem; 
            border-radius: 0.75rem;
            margin-bottom: 2rem;
        }

        .news-detail-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .news-detail-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1.3;
        }

        .news-meta {
            font-size: 0.9rem;
            color: #777;
            margin-bottom: 1.5rem;
        }
        .news-meta .author {
            font-weight: 500;
            color: var(--primary-green-dark);
        }

        .news-image {
            width: 100%;
            max-height: 400px; 
            object-fit: cover;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .news-content {
            font-size: 1.05rem;
            line-height: 1.8;
            color: #333;
            text-align: justify;
        }
        .news-content p {
            margin-bottom: 1.5rem;
        }

        /* Error/No Data Message */
        .message {
            text-align: center;
            padding: 2rem;
            font-size: 1.2rem;
            color: #EF4444; 
            background-color: #FEE2E2;
            border: 1px solid #FCA5A5;
            border-radius: 0.5rem;
            margin-top: 4rem;
        }

        /* Tombol Kembali */
        .back-button-container {
            text-align: center;
            margin-top: 2rem;
            padding-bottom: 2rem;
        }
        .back-button {
            display: inline-block;
            background-color: var(--accent-green);
            color: white;
            padding: 0.8rem 1.8rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .back-button:hover {
            background-color: #7bc13f;
            transform: translateY(-2px);
        }

        /* --- Footer (hijau solid tanpa batik) --- */
        .footer {
            background-color: var(--primary-green-dark);
            color: white;
            padding: 2.5rem 0;
            text-align: center;
            font-size: 0.85rem;
            margin-top: 4rem;
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

        /* --- Responsive Design --- */
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
            .news-detail-section {
                margin-top: 4rem; 
                padding-top: 2rem;
                padding-bottom: 2rem;
            }
            .news-detail-header h1 {
                font-size: 1.8rem;
            }
            .news-image {
                height: 250px;
            }
            .news-content {
                font-size: 0.95rem;
            }
        }

        @media (max-width: 480px) {
            .navbar .logo h1 {
                font-size: 1.3rem;
            }
            .news-detail-header h1 {
                font-size: 1.5rem;
            }
            .news-image {
                height: 200px;
            }
            .news-content {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="fixed-whatsapp">
        <a href="https://wa.me/62882220595895?text=Hai%20ExpertUs%2C%20aku%20tertarik%20untuk%20menggunakan%20layanan%20perusahaan%20Anda." target="_blank" rel="noopener noreferrer" title="Chat via WhatsApp">
            <i class="fab fa-whatsapp"></i>
        </a>
    </div>

    <nav class="navbar scrolled"> <div class="container navbar-container">
            <div class="logo">
                <img id="navbarLogoDetail" src="<?php echo BASE_URL; ?>/public/Logo/Logo2.png" alt="ExpertUs Logo">
            </div>
            <div class="navbar-links">
                <a href="<?php echo BASE_URL; ?>/index.php">Home</a>
                <a href="<?php echo BASE_URL; ?>/index.php#news">Lihat Berita Lainnya</a>
                <a href="https://wa.me/6282220595895?text=Hai%20ExpertUs%2C%20aku%20tertarik%20untuk%20menggunakan%20layanan%20perusahaan%20Anda." target="_blank" rel="noopener noreferrer" class="whatsapp-icon-nav">
                    <i class="fab fa-whatsapp"></i>
                </a>
            </div>
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <main>
        <section class="news-detail-section">
            <div class="container">
                <?php if ($news_item): ?>
                    <div class="news-detail-header">
                        <h1><?php echo $news_item['Judul']; ?></h1>
                        <p class="news-meta">Diposting pada <span class="date"><?php echo $news_item['Date']; ?></span></p>
                    </div>
                    <?php if (!empty($news_item['Img']) && $news_item['Img'] !== BASE_URL . '/public/images/default_news_placeholder.jpg'): ?>
                        <img src="<?php echo $news_item['Img']; ?>" alt="<?php echo $news_item['Judul']; ?>" class="news-image">
                    <?php endif; ?>
                    <div class="news-content">
                        <p><?php echo nl2br($news_item['Text']); ?></p>
                    </div>
                <?php else: ?>
                    <div class="message">
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php endif; ?>
                <div class="back-button-container">
                    <a href="<?php echo BASE_URL; ?>/index.php#news" class="back-button">Kembali ke Berita</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="logo">
                <img src="<?php echo BASE_URL; ?>/public/Logo/Logo2.png" alt="ExpertUs Logo">
            </div>
            <div class="footer-links">
                <a href="<?php echo BASE_URL; ?>/index.php#company">Company</a>
                <a href="<?php echo BASE_URL; ?>/index.php#about">About Us</a>
                <a href="<?php echo BASE_URL; ?>/index.php#news">Blog</a>
                <a href="<?php echo BASE_URL; ?>/index.php#portfolio">Portfolio</a>
                <a href="<?php echo BASE_URL; ?>/index.php#services">Services</a>
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
        // Smooth scrolling for navigation links (sedikit dimodifikasi untuk halaman detail)
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
                } else if (targetId.startsWith('#')) {
                    e.preventDefault();
                }
            });
        });

        // Efek Navbar saat scroll (mungkin tidak terlalu relevan di halaman detail jika konten pendek)
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            const navbarLogo = document.getElementById('navbarLogoDetail'); // ID yang disesuaikan
            const scrolledClass = 'scrolled';
            const logoDefault = '<?php echo BASE_URL; ?>/public/Logo/Logo3.png'; // Logo default (putih)
            const logoScrolled = '<?php echo BASE_URL; ?>/public/Logo/Logo2.png'; // Logo saat discroll (hijau gelap)

            if (window.scrollY > 50) { 
                navbar.classList.add(scrolledClass);
                if (navbarLogo.src !== logoScrolled) {
                    navbarLogo.src = logoScrolled;
                }
            } else { // Jika kembali ke atas
                navbar.classList.remove(scrolledClass);
                if (navbarLogo.src !== logoDefault) {
                    navbarLogo.src = logoDefault;
                }
            }
        });

        // Responsive Navbar Toggle (untuk hamburger menu di mobile)
        const menuToggle = document.querySelector('.menu-toggle');
        const navLinksContainer = document.querySelector('.navbar-links');

        if (menuToggle && navLinksContainer) {
            menuToggle.addEventListener('click', () => {
                navLinksContainer.classList.toggle('active');
            });
            // Tutup nav saat link diklik di mobile
            navLinksContainer.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    if (navLinksContainer.classList.contains('active')) {
                        navLinksContainer.classList.remove('active');
                    }
                });
            });
        }
    </script>
</body>
</html>