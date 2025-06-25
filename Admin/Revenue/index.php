<?php
// expertus-app/admin/revenue/index.php

require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
checkAdminLogin(); // Memeriksa status login admin

require_once dirname(dirname(__DIR__)) . '/includes/db_config.php'; // Sertakan db_config untuk BASE_URL dan koneksi DB ($conn)

// Sertakan autoloader Composer untuk Dompdf
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Mulai output buffering untuk mencegah "Headers already sent" errors
ob_start();

$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$admin_email = $_SESSION['admin_email'] ?? '';

// --- PENGATURAN DEBUGGING (OPSIONAL, HAPUS DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 1. Ambil Data Monthly & Yearly Revenue dari Database
$monthly_revenue_data = ['total' => '0', 'orders' => 0];
$yearly_revenue_data = ['total' => '0', 'orders' => 0];

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
                                 AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
    $stmt_monthly_revenue = $conn->prepare($sql_monthly_revenue);
    $stmt_monthly_revenue->execute();
    $result_monthly_revenue = $stmt_monthly_revenue->get_result()->fetch_assoc();
    if ($result_monthly_revenue) {
        $monthly_revenue_data['total'] = number_format($result_monthly_revenue['total_revenue'] ?? 0, 0, ',', '.');
        $monthly_revenue_data['orders'] = $result_monthly_revenue['total_orders'] ?? 0;
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
                                 AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
    $stmt_yearly_revenue = $conn->prepare($sql_yearly_revenue);
    $stmt_yearly_revenue->execute();
    $result_yearly_revenue = $stmt_yearly_revenue->get_result()->fetch_assoc();
    if ($result_yearly_revenue) {
        $yearly_revenue_data['total'] = number_format($result_yearly_revenue['total_revenue'] ?? 0, 0, ',', '.');
        $yearly_revenue_data['orders'] = $result_yearly_revenue['total_orders'] ?? 0;
    }
    $stmt_yearly_revenue->close();

} catch (mysqli_sql_exception $e) {
    error_log("Database error fetching revenue summary: " . $e->getMessage());
    // Anda bisa mengatur pesan error untuk ditampilkan di UI jika perlu
}

// 2. Ambil Data Recent Transactions (Maksimal 10)
$recent_transactions = [];
try {
    $sql_recent_transactions = "SELECT
                                c.IdConsult,
                                cust.Nama AS customer_name,
                                c.updated_at AS transaction_date,
                                cust.Harga AS amount
                            FROM consult AS c
                            JOIN customer AS cust ON c.IdCust = cust.IdCust
                            WHERE c.StatusPembayaran = 'Lunas'
                                AND c.StatusPengerjaan = 'Finished'
                            ORDER BY c.updated_at DESC
                            LIMIT 10"; // Ambil 10 transaksi terbaru yang sudah lunas dan selesai
    $stmt_recent_transactions = $conn->prepare($sql_recent_transactions);
    $stmt_recent_transactions->execute();
    $result_recent_transactions = $stmt_recent_transactions->get_result();

    if ($result_recent_transactions->num_rows > 0) {
        while ($row = $result_recent_transactions->fetch_assoc()) {
            $recent_transactions[] = [
                'order_id' => '#' . htmlspecialchars($row['IdConsult']),
                'customer_name' => htmlspecialchars($row['customer_name'] ?? 'N/A'),
                'date' => htmlspecialchars(date('d F Y', strtotime($row['transaction_date'] ?? 'now'))),
                'amount' => htmlspecialchars(number_format($row['amount'] ?? 0, 0, ',', '.'))
            ];
        }
    }
    $stmt_recent_transactions->close();
} catch (mysqli_sql_exception $e) {
    error_log("Database error fetching recent transactions: " . $e->getMessage());
}

// 3. Ambil Data untuk Grafik (Revenue Trend)
$chart_labels = [];
$chart_data_revenue = [];
$chart_data_orders = [];

try {
    // Query untuk mendapatkan total revenue dan orders per bulan selama 12 bulan terakhir
    $sql_chart_data = "SELECT
                            DATE_FORMAT(c.updated_at, '%Y-%m') AS month_year,
                            SUM(c.Harga) AS monthly_revenue,
                            COUNT(c.IdConsult) AS monthly_orders
                        FROM consult AS c
                        WHERE c.StatusPembayaran = 'Lunas'
                            AND c.StatusPengerjaan = 'Finished'
                            AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        GROUP BY month_year
                        ORDER BY month_year ASC";
    $stmt_chart_data = $conn->prepare($sql_chart_data);
    $stmt_chart_data->execute();
    $result_chart_data = $stmt_chart_data->get_result();

    $raw_chart_data = [];
    while ($row = $result_chart_data->fetch_assoc()) {
        $raw_chart_data[$row['month_year']] = [
            'revenue' => $row['monthly_revenue'],
            'orders' => $row['monthly_orders']
        ];
    }
    $stmt_chart_data->close();

    // Isi data untuk 12 bulan terakhir, tambahkan 0 jika tidak ada data
    for ($i = 11; $i >= 0; $i--) {
        $month_date = new DateTime("-$i months");
        $label = $month_date->format('M'); 
        $month_year_key = $month_date->format('Y-m'); 

        $chart_labels[] = $label;
        $chart_data_revenue[] = ($raw_chart_data[$month_year_key]['revenue'] ?? 0) / 1000000; // Dalam juta Rp
        $chart_data_orders[] = $raw_chart_data[$month_year_key]['orders'] ?? 0;
    }

} catch (mysqli_sql_exception $e) {
    error_log("Database error fetching chart data: " . $e->getMessage());
    // $chart_error = "Gagal memuat data grafik. " . $e->getMessage();
}


// LOGIKA UNDUH LAPORAN PDF
if (isset($_GET['action']) && $_GET['action'] == 'download_pdf') {    
    // Ob_start untuk menangkap output HTML khusus PDF
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Laporan Pendapatan ExpertUs</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            /* Pastikan font Poppins tersedia di Dompdf atau gunakan fallback */
            body { font-family: 'Poppins', sans-serif; font-size: 12px; line-height: 1.5; color: #333; }
            h1, h2, h3 { color: #1F2937; margin-bottom: 10px; }
            .header-pdf { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 10px;}
            .summary-grid-pdf { display: table; width: 100%; border-collapse: separate; border-spacing: 10px 0; margin-bottom: 20px; } /* Use separate and border-spacing */
            .summary-card-pdf { display: table-cell; width: 50%; padding: 15px; border: 1px solid #eee; border-radius: 5px; vertical-align: top; box-sizing: border-box;}
            .summary-card-pdf.blue { background-color: #EEF7FF; color: #1E40AF; }
            .summary-card-pdf.green { background-color: #D1FAE5; color: #065F46; }
            .summary-card-pdf h3 { font-size: 14px; margin-bottom: 5px; color: #1F2937;}
            .summary-card-value-pdf { font-size: 20px; font-weight: bold; margin-bottom: 5px; display: block;}
            .summary-card-info-pdf { font-size: 10px; color: #6B7280; display: block; }
            
            .table-pdf { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .table-pdf th, .table-pdf td { padding: 8px; border: 1px solid #E5E7EB; text-align: left; }
            .table-pdf th { background-color: #F9FAFB; font-weight: bold; }
            .footer-pdf { text-align: center; margin-top: 30px; font-size: 10px; color: #6B7280; border-top: 1px solid #ddd; padding-top: 10px;}
        </style>
    </head>
    <body>
        <div class="header-pdf">
            <h1>Laporan Pendapatan ExpertUs</h1>
            <p>Periode Data: <?php echo date('d M Y H:i:s', strtotime('-12 months')); ?> hingga <?php echo date('d M Y H:i:s'); ?></p>
        </div>

        <div class="summary-grid-pdf">
            <div class="summary-card-pdf blue">
                <h3>Pendapatan Bulanan (Terakhir)</h3>
                <span class="summary-card-value-pdf">Rp<?php echo htmlspecialchars($monthly_revenue_data['total']); ?></span>
                <span class="summary-card-info-pdf">Total Order: <strong><?php echo htmlspecialchars($monthly_revenue_data['orders']); ?></strong></span>
            </div>
            <div class="summary-card-pdf green">
                <h3>Pendapatan Tahunan (Terakhir)</h3>
                <span class="summary-card-value-pdf">Rp<?php echo htmlspecialchars($yearly_revenue_data['total']); ?></span>
                <span class="summary-card-info-pdf">Total Order: <strong><?php echo htmlspecialchars($yearly_revenue_data['orders']); ?></strong></span>
            </div>
        </div>

        <h2>Daftar Transaksi Terbaru</h2>
        <?php if (!empty($recent_transactions)): ?>
        <table class="table-pdf">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Nama Pelanggan</th>
                    <th>Tanggal</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_transactions as $transaction): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($transaction['order_id']); ?></td>
                        <td><?php echo htmlspecialchars($transaction['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($transaction['date']); ?></td>
                        <td>Rp<?php echo htmlspecialchars($transaction['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="text-align:center; color:#6B7280; margin-top:20px;">Tidak ada transaksi yang tercatat dalam periode ini.</p>
        <?php endif; ?>

        <div class="footer-pdf">
            Laporan dihasilkan oleh Sistem ExpertUs pada <?php echo date('d M Y H:i:s'); ?>
        </div>
    </body>
    </html>
    <?php
    $html = ob_get_clean(); // Tangkap output HTML ke variabel

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);     


    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);

    // Render PDF (pilih ukuran dan orientasi)
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output PDF ke browser
    $dompdf->stream('Laporan_Pendapatan_ExpertUs_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
    exit; // Pastikan script berhenti setelah PDF di-stream
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Revenue ExpertUs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F8FAFB;
        }
        @keyframes fadeInMoveUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

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
        .sidebar-profile .admin-label { font-size: 0.875rem; color: #D1D5DB; }
        .sidebar-profile .admin-name { font-size: 1.125rem; font-weight: 600; color: white; }
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

        /* Revenue Page Specific Styles */
        .revenue-container {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0; 
            animation: fadeInMoveUp 0.6s ease-out 0.2s forwards; 
        }
        .revenue-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .revenue-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1F2937;
        }
        .revenue-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .revenue-summary-card {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }
        .revenue-summary-card.blue {
            background-color: #EEF7FF;
            color: #1E40AF; 
        }
        .revenue-summary-card.green {
            background-color: #D1FAE5; 
            color: #065F46; 
        }
        .revenue-summary-card h3 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #1F2937; 
        }
        .revenue-summary-card-content {
            display: flex;
            justify-content: space-between;
            width: 100%;
        }
        .revenue-summary-card-value {
            font-size: 1.75rem; 
            font-weight: 700;
            line-height: 1;
        }
        .revenue-summary-card-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            font-size: 0.875rem;
            color: #6B7280;
        }
        .revenue-summary-card-info .total-order {
            font-weight: 600;
            color: #1F2937;
        }
        .revenue-summary-card-background-pattern {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0;
            opacity: 0.1; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2" fill="%23fff" /></svg>');
            background-size: 20px 20px; background-position: 5px 5px; pointer-events: none;
        }

        /* Chart/Graph Section */
        .chart-section {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.4s forwards; 
        }
        .chart-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 1rem;
        }
        .chart-canvas-wrapper {
            position: relative;
            height: 250px; /* Tinggi grafik */
            width: 100%;
        }

        .transactions-section {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.6s forwards;
        }
        .transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .transactions-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1F2937;
        }
        .download-report-btn {
            background-color: #4B5563;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
            text-decoration: none; 
        }
        .download-report-btn:hover {
            background-color: #1F2937;
        }
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }
        .transactions-table thead th {
            padding: 1rem;
            text-align: left;
            background-color: #F9FAFB; 
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6B7280;
            font-weight: 600;
        }
        .transactions-table tbody td {
            padding: 1rem;
            vertical-align: top;
            border-bottom: 1px solid #E5E7EB;
            font-size: 0.875rem;
            color: #4B5563;
        }
        .transactions-table tbody tr:last-child td {
            border-bottom: none;
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
    <?php include dirname(__DIR__) . '/includes/sidebar.php'; ?>

    <div class="content">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Revenue Overview</h1>

        <div class="revenue-summary-grid">
            <div class="revenue-summary-card blue">
                <div class="revenue-summary-card-background-pattern"></div>
                <h3>Monthly Revenue (Lunas & Finished)</h3>
                <div class="revenue-summary-card-content">
                    <span class="revenue-summary-card-value">Rp<?php echo htmlspecialchars($monthly_revenue_data['total']); ?></span>
                    <span class="revenue-summary-card-info">Total Order: <strong class="total-order"><?php echo htmlspecialchars($monthly_revenue_data['orders']); ?></strong></span>
                </div>
            </div>

            <div class="revenue-summary-card green">
                <div class="revenue-summary-card-background-pattern"></div>
                <h3>Yearly Revenue (Lunas & Finished)</h3>
                <div class="revenue-summary-card-content">
                    <span class="revenue-summary-card-value">Rp<?php echo htmlspecialchars($yearly_revenue_data['total']); ?></span>
                    <span class="revenue-summary-card-info">Total Order: <strong class="total-order"><?php echo htmlspecialchars($yearly_revenue_data['orders']); ?></strong></span>
                </div>
            </div>
        </div>

        <div class="chart-section">
            <h3>Revenue Trend (Last 12 Months)</h3>
            <div class="chart-canvas-wrapper">
                <canvas id="revenueChart"></canvas>
            </div>
             <?php if (empty($chart_labels) || array_sum($chart_data_revenue) == 0): ?>
                <p class="no-data-message mt-4">Tidak ada data pendapatan untuk menampilkan grafik dalam 12 bulan terakhir.</p>
            <?php endif; ?>
        </div>

        <div class="transactions-section">
            <div class="transactions-header">
                <h2>Recent Transactions (Lunas & Finished)</h2>
                <a href="<?php echo BASE_URL; ?>/admin/revenue/index.php?action=download_pdf" class="download-report-btn" target="_blank">
                    <i class="fas fa-file-pdf"></i> Unduh Laporan PDF
                </a>
            </div>
            <?php if (!empty($recent_transactions)): ?>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer Name</th>
                        <th>Date</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($transaction['order_id']); ?></td>
                            <td><?php echo htmlspecialchars($transaction['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($transaction['date']); ?></td>
                            <td>Rp<?php echo htmlspecialchars($transaction['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="no-data-message">Tidak ada transaksi yang tercatat dalam periode ini.</p>
            <?php endif; ?>
        </div>

    </div>

    <script>
        // Data untuk Chart.js
        const chartLabels = <?php echo json_encode($chart_labels); ?>;
        const chartDataRevenue = <?php echo json_encode($chart_data_revenue); ?>;
        const chartDataOrders = <?php echo json_encode($chart_data_orders); ?>;

        // Inisialisasi Chart.js
        document.addEventListener('DOMContentLoaded', function() {
            // Hanya inisialisasi chart jika ada data
            if (chartLabels.length > 0 && (chartDataRevenue.some(val => val > 0) || chartDataOrders.some(val => val > 0))) {
                const ctx = document.getElementById('revenueChart').getContext('2d');
                const revenueChart = new Chart(ctx, {
                    type: 'line', 
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Pendapatan (Juta Rp)',
                                data: chartDataRevenue,
                                borderColor: 'rgb(59, 130, 246)',
                                backgroundColor: 'rgba(59, 130, 246, 0.2)', 
                                tension: 0.3,
                                fill: true, 
                                yAxisID: 'y', 
                            },
                            {
                                label: 'Jumlah Order',
                                data: chartDataOrders,
                                borderColor: 'rgb(16, 185, 129)', 
                                backgroundColor: 'rgba(16, 185, 129, 0.2)',
                                tension: 0.3,
                                fill: true,
                                yAxisID: 'y1', 
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false, 
                        plugins: {
                            title: {
                                display: false,
                                text: 'Grafik Pendapatan dan Order',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.dataset.label.includes('Pendapatan')) {
                                            label += 'Rp' + (context.parsed.y * 1000000).toLocaleString('id-ID'); 
                                        } else {
                                            label += context.parsed.y;
                                        }
                                        return label;
                                    }
                                }
                            },
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false 
                                }
                            },
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Pendapatan (Juta Rp)'
                                },
                                grid: {
                                    color: '#e5e7eb'
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Jumlah Order'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>