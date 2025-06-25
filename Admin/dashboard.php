<?php
// expertus-app/admin/dashboard.php

require_once dirname(__DIR__) . '/includes/auth_check.php';
checkAdminLogin(); // Memeriksa status login admin

// Sertakan db_config.php untuk BASE_URL dan koneksi DB ($conn)
require_once dirname(__DIR__) . '/includes/db_config.php';

// --- PENGATURAN DEBUGGING (AKTIFKAN SAAT PENGEMBANGAN, NONAKTIFKAN DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$admin_email = $_SESSION['admin_email'] ?? '';

// 1. Ambil Data Orders (Maksimal 5 pesanan belum selesai)
$recent_orders = [];
try {
    $sql_recent_orders = "SELECT
                                c.IdConsult,
                                cust.Nama AS customer_name,
                                cust.Email AS customer_email,
                                cust.NoTelp AS customer_phone,
                                cust.NamaUsaha,
                                cust.Lokasi,
                                cust.JenisLayanan,
                                c.StatusPengerjaan AS order_status
                              FROM consult AS c
                              JOIN customer AS cust ON c.IdCust = cust.IdCust
                              WHERE c.StatusPengerjaan NOT IN ('Finished', 'Cancelled') -- Tampilkan yang masih On Progress atau Pending
                              ORDER BY c.created_at DESC
                              LIMIT 5";
    $stmt_recent_orders = $conn->prepare($sql_recent_orders);
    $stmt_recent_orders->execute();
    $result_recent_orders = $stmt_recent_orders->get_result();

    if ($result_recent_orders->num_rows > 0) {
        while ($row = $result_recent_orders->fetch_assoc()) {
            $recent_orders[] = [
                'id' => '#' . htmlspecialchars($row['IdConsult']),
                'raw_id' => $row['IdConsult'], 
                'customer' => htmlspecialchars($row['customer_name']),
                'email' => htmlspecialchars($row['customer_email']),
                'phone' => htmlspecialchars($row['customer_phone']),
                'details_brand' => htmlspecialchars($row['NamaUsaha']),
                'details_location' => htmlspecialchars($row['Lokasi']),
                'service' => htmlspecialchars($row['JenisLayanan']),
                'status' => htmlspecialchars($row['order_status'])
            ];
        }
    }
    $stmt_recent_orders->close();
} catch (mysqli_sql_exception $e) {
    error_log("Database error fetching recent orders: " . $e->getMessage());
}

// 2. Hitung Monthly & Yearly Revenue
$monthly_revenue = ['total' => '0', 'orders' => 0];
$yearly_revenue = ['total' => '0', 'orders' => 0];

try {
    // Monthly Revenue
    $sql_monthly_revenue = "SELECT
                                    SUM(cust.Harga) AS total_revenue,
                                    COUNT(c.IdConsult) AS total_orders
                                FROM
                                    consult AS c
                                JOIN
                                    customer AS cust ON c.IdCust = cust.IdCust
                                WHERE
                                    c.StatusPengerjaan = 'Finished' -- Kondisi 1
                                    AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH);";
    $stmt_monthly_revenue = $conn->prepare($sql_monthly_revenue);
    $stmt_monthly_revenue->execute();
    $result_monthly_revenue = $stmt_monthly_revenue->get_result()->fetch_assoc();
    if ($result_monthly_revenue) {
        $monthly_revenue['total'] = number_format($result_monthly_revenue['total_revenue'] ?? 0, 0, ',', '.');
        $monthly_revenue['orders'] = $result_monthly_revenue['total_orders'] ?? 0;
    }
    $stmt_monthly_revenue->close();

    // Yearly Revenue
    $sql_yearly_revenue = "SELECT
                                    SUM(cust.Harga) AS total_revenue,
                                    COUNT(c.IdConsult) AS total_orders
                                FROM
                                    consult AS c
                                JOIN
                                    customer AS cust ON c.IdCust = cust.IdCust
                                WHERE
                                    c.StatusPengerjaan = 'Finished' -- Kondisi 1
                                    AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR);";
    $stmt_yearly_revenue = $conn->prepare($sql_yearly_revenue);
    $stmt_yearly_revenue->execute();
    $result_yearly_revenue = $stmt_yearly_revenue->get_result()->fetch_assoc();
    if ($result_yearly_revenue) {
        $yearly_revenue['total'] = number_format($result_yearly_revenue['total_revenue'] ?? 0, 0, ',', '.');
        $yearly_revenue['orders'] = $result_yearly_revenue['total_orders'] ?? 0;
    }
    $stmt_yearly_revenue->close();

} catch (mysqli_sql_exception $e) {
    error_log("Database error fetching revenue: " . $e->getMessage());
}


// 3. Ambil Data Information (Berita) - Maksimal 5 berita terbaru
$recent_information = [];
try {
    // PERBAIKAN 1: Sesuaikan nama kolom SQL dengan nama kolom di database Anda
    $sql_recent_info = "SELECT IdBerita, Img, Judul, Text, Date
                         FROM berita
                         ORDER BY Date DESC
                         LIMIT 5";
    $stmt_recent_info = $conn->prepare($sql_recent_info);
    $stmt_recent_info->execute();
    $result_recent_info = $stmt_recent_info->get_result();

    if ($result_recent_info->num_rows > 0) {
        while ($row = $result_recent_info->fetch_assoc()) {
            // PERBAIKAN 2: Gabungkan BASE_URL dengan path gambar yang diambil dari DB
            // Asumsi $row['Img'] adalah path relatif dari root web server, contoh: /public/files/Berita/nama_gambar.jpg
            $image_path_for_display = (empty($row['Img']) || $row['Img'] === '0')
                                    ? BASE_URL . '/public/images/default_placeholder.jpg' // Placeholder jika tidak ada gambar
                                    : BASE_URL . $row['Img'];

            $recent_information[] = [
                'id_berita' => htmlspecialchars($row['IdBerita']), // Tambah ID untuk tombol aksi
                'image' => $image_path_for_display, // Ini sudah path lengkap untuk tampilan
                'title' => htmlspecialchars($row['Judul']),
                'text' => $row['Text'], 
                'date' => htmlspecialchars(date('l, d F Y', strtotime($row['Date']))) // Format tanggal
            ];
        }
    }
    $stmt_recent_info->close();
} catch (mysqli_sql_exception $e) {
    error_log("Database error fetching recent information: " . $e->getMessage());
    // Anda bisa mengatur pesan error untuk ditampilkan di UI jika perlu
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard ExpertUs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F8FAFB; 
        }

        /* Definisi Keyframes untuk Fade In */
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

        /* Styling Sidebar */
        .sidebar {
            width: 250px;
            background-color: #26474E; 
            background-image: linear-gradient(to bottom, #26474E, #1A365D); 
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            border-top-right-radius: 1rem; 
            border-bottom-right-radius: 1rem; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.1s forwards; 
        }
        .sidebar-profile {
            margin-bottom: 2.5rem; 
            text-align: center;
            padding: 0 1.5rem;
        }
        .sidebar-profile .avatar {
            width: 80px; 
            height: 80px;
            background-color: #6B7280;
            border-radius: 50%;
            margin: 0 auto 0.75rem; 
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white; 
        }
        .sidebar-profile .admin-label {
            font-size: 0.875rem;
            color: #D1D5DB; 
        }
        .sidebar-profile .admin-name {
            font-size: 1.125rem; 
            font-weight: 600; 
            color: white;
        }
        .sidebar-nav {
            flex-grow: 1;
            padding: 0 1rem;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            color: #D1D5DB; 
            text-decoration: none;
            transition: background-color 0.2s, color 0.2s;
            font-weight: 600; 
        }
        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background-color: #3B5F6C; 
            color: white;
        }
        .sidebar-nav a i {
            margin-right: 1rem; 
            font-size: 1.35rem; 
        }
        .content {
            margin-left: 250px;
            padding: 2.5rem; 
            min-height: 100vh;
            width: calc(100% - 250px); 
        }

        /* Animasi untuk elemen-elemen di dalam konten utama */
        .dashboard-section {
            opacity: 0; 
            animation: fadeInMoveUp 0.6s ease-out forwards;
            background-color: white; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem; margin-bottom: 2rem;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            color: #1F2937; 
        }
        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
        }
        .section-header .see-all {
            color: #9CA3AF; 
            text-decoration: none;
            font-size: 0.875rem; 
            font-weight: 600;
        }
        .order-item {
            display: grid;
            grid-template-columns: 1fr 1fr 1.2fr auto;
            align-items: center;
            padding: 1.25rem 0;
            border-bottom: 1px solid #E5E7EB;
            gap: 1rem; 
            font-size: 0.95rem; 
        }
        .order-item:last-child {
            border-bottom: none;
        }

        .order-customer-info strong {
            color: #1F2937; 
            font-size: 1.05rem;
            font-weight: 600;
        }
        .order-customer-info .contact {
            color: #6B7280; 
            font-size: 0.85rem;
        }

        .order-details-info .brand {
            color: #4B5563; 
            font-size: 0.95rem;
        }
        .order-details-info .location {
            color: #6B7280; 
            font-size: 0.85rem;
        }

        .order-service-tag {
            background-color: #D1FAE5;
            color: #065F46; 
            padding: 0.4rem 1.2rem; 
            border-radius: 9999px;
            font-size: 0.85rem; 
            font-weight: 600;
            white-space: nowrap;
        }
        .order-status-text {
            color: #4B5563; 
            font-weight: 600;
            white-space: nowrap;
            font-size: 0.875rem;
        }
        .order-item-link {
            text-decoration: none;
            color: inherit; 
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        .order-item-link:hover {
            background-color: #F3F4F6; 
        }


        /* CSS untuk Bagian Revenue */
        .revenue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .revenue-card {
            opacity: 0; 
            animation: fadeInMoveUp 0.6s ease-out forwards;
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            color: #1F2937; 
            position: relative;
            overflow: hidden; 
            text-decoration: none; 
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .revenue-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.06);
        }
        .revenue-card.blue {
            background-color: #EEF7FF;
            color: #1E40AF; 
        }
        .revenue-card.green {
            background-color: #D1FAE5;
            color: #065F46; 
        }
        .revenue-card h3 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #1F2937; 
        }
        .revenue-card-content {
            display: flex;
            justify-content: space-between;
            width: 100%;
        }
        .revenue-card-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1; 
        }
        .revenue-card-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            font-size: 0.875rem;
            color: #6B7280; 
        }
        .revenue-card-info .total-order {
            font-weight: 600;
            color: #1F2937; 
        }
        .revenue-card-background-pattern {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0; 
            opacity: 0.1; 
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2" fill="%23fff" /></svg>'); /* Pola titik putih */
            background-size: 20px 20px;
            background-position: 5px 5px;
            pointer-events: none; 
        }

        /* Tambahan untuk ikon kanan atas di card Revenue */
        .revenue-card .top-right-icon {
            position: absolute;
            top: 1.2rem;
            right: 1.2rem;
            color: #9CA3AF; 
            font-size: 1.2rem;
            z-index: 10;
        }

        /* CSS untuk Information Section */
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table thead th {
            padding: 1rem;
            text-align: left;
            background-color: #F9FAFB;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6B7280; 
            font-weight: 600;
        }
        .info-table tbody td {
            padding: 1rem;
            vertical-align: top;
            border-bottom: 1px solid #E5E7EB;
            font-size: 0.875rem;
            color: #4B5563; 
        }
        .info-table tbody tr:last-child td {
            border-bottom: none;
        }
        .info-table img {
            width: 100px; 
            height: 70px;
            object-fit: cover;
            border-radius: 0.25rem;
        }
        .action-buttons {
            display: flex;
            gap: 0.75rem; 
        }
        .action-buttons a,
        .action-buttons form button { 
            background-color: #EEF2FF; 
            color: #4F46E5; 
            padding: 0.6rem 0.75rem; 
            border-radius: 0.375rem; 
            text-decoration: none;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s, color 0.2s;
            border: none; 
            cursor: pointer; 
        }
        .action-buttons a:hover,
        .action-buttons form button:hover {
            background-color: #E0E7FF;
        }
        /* Spesifik untuk tombol delete */
        .action-buttons form button.delete {
            background-color: #FEF2F2;
            color: #DC2626; 
        }
        .action-buttons form button.delete:hover {
            background-color: #FEE2E2; 
        }
        .no-data-message {
            text-align: center;
            color: #6B7280;
            padding: 20px;
            font-size: 1rem;
        }
    </style>
</head>
<body class="flex">
    <?php include dirname(__DIR__) . '/admin/includes/sidebar.php'; ?>

    <div class="content">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Main Analytic Dashboard</h1>

        <div class="dashboard-section" style="animation-delay: 0.2s;">
            <div class="section-header">
                <h2>Orders (On Progress / Pending)</h2>
                <a href="<?php echo BASE_URL; ?>/admin/orders/index.php" class="see-all">See all <i class="fas fa-chevron-right ml-1"></i></a>
            </div>
            <?php if (!empty($recent_orders)): ?>
                <?php foreach ($recent_orders as $order): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/orders/detail.php?IdConsult=<?php echo $order['raw_id']; ?>" class="order-item order-item-link">
                        <div class="order-customer-info">
                            <strong><?php echo $order['customer']; ?></strong><br>
                            <span class="contact"><?php echo $order['email']; ?> | <?php echo $order['phone']; ?></span>
                        </div>
                        <div class="order-details-info">
                            <span class="brand"><?php echo $order['details_brand']; ?></span><br>
                            <span class="location"><?php echo $order['details_location']; ?></span>
                        </div>
                        <span class="order-service-tag"><?php echo $order['service']; ?></span>
                        <span class="order-status-text"><?php echo $order['status']; ?></span>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-data-message">Tidak ada pesanan aktif.</p>
            <?php endif; ?>
        </div>

        <div class="revenue-grid">
            <a href="<?php echo BASE_URL; ?>/admin/orders/index.php?status_pembayaran=Lunas&status_pengerjaan=Finished&periode=monthly" class="revenue-card blue" style="animation-delay: 0.4s;">
                <div class="top-right-icon"><i class="fas fa-chevron-right"></i></div>
                <div class="revenue-card-background-pattern"></div>
                <h3>Monthly Revenue</h3>
                <div class="revenue-card-content">
                    <span class="revenue-card-value">Rp<?php echo htmlspecialchars($monthly_revenue['total']); ?></span>
                    <span class="revenue-card-info">Total Order: <strong class="total-order"><?php echo htmlspecialchars($monthly_revenue['orders']); ?></strong></span>
                </div>
            </a>

            <a href="<?php echo BASE_URL; ?>/admin/orders/index.php?status_pembayaran=Lunas&status_pengerjaan=Finished&periode=yearly" class="revenue-card green" style="animation-delay: 0.6s;">
                <div class="top-right-icon"><i class="fas fa-chevron-right"></i></div>
                <div class="revenue-card-background-pattern"></div>
                <h3>Yearly Revenue</h3>
                <div class="revenue-card-content">
                    <span class="revenue-card-value">Rp<?php echo htmlspecialchars($yearly_revenue['total']); ?></span>
                    <span class="revenue-card-info">Total Order: <strong class="total-order"><?php echo htmlspecialchars($yearly_revenue['orders']); ?></strong></span>
                </div>
            </a>
        </div>

        <div class="dashboard-section" style="animation-delay: 0.8s;">
            <div class="section-header">
                <h2>Information</h2>
                <a href="<?php echo BASE_URL; ?>/admin/Berita/index.php" class="see-all">See all <i class="fas fa-chevron-right ml-1"></i></a>
            </div>
            <?php if (!empty($recent_information)): ?>
                <table class="info-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Title</th>
                            <th>Text</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_information as $info): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($info['image'])): ?>
                                        <img src="<?php echo htmlspecialchars($info['image']); ?>" alt="<?php echo htmlspecialchars($info['title']); ?>">
                                    <?php else: ?>
                                        <div style="width:100px; height:70px; background-color:#e0e0e0; display:flex; align-items:center; justify-content:center; border-radius:0.25rem;">No Image</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($info['title']); ?></td>
                                <td><?php echo htmlspecialchars(mb_strimwidth($info['text'], 0, 100, '...')); ?></td>
                                <td><?php echo htmlspecialchars($info['date']); ?></td>
                                <td class="action-buttons">
                                    <a href="<?php echo BASE_URL; ?>/admin/Berita/input.php?id=<?php echo htmlspecialchars($info['id_berita']); ?>" class="edit"><i class="fas fa-pencil-alt"></i></a>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/admin/Berita/index.php" onsubmit="return confirm('Apakah Anda yakin ingin menghapus berita ini?');">
                                        <input type="hidden" name="action" value="delete_berita">
                                        <input type="hidden" name="berita_id" value="<?php echo htmlspecialchars($info['id_berita']); ?>">
                                        <button type="submit" class="delete"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="no-data-message">Tidak ada informasi terbaru.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>