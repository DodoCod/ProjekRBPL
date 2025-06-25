<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

checkAdminLogin(); // Memastikan admin sudah login

// --- PENGATURAN DEBUGGING (AKTIFKAN SAAT PENGEMBANGAN, NONAKTIFKAN DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Tanggal hari ini untuk nota (tanggal saat nota/invoice ini dibuat/dirender)
// Ini adalah tanggal yang akan ditampilkan di HTML invoice
$current_invoice_date = date('d F Y'); // Contoh: 20 Juni 2025
$current_invoice_time = date('H:i:s'); // Contoh: 13:00:00

// Mengambil nama admin yang sedang login dari sesi (untuk tanda tangan)
$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';

// Ambil ID order dari URL parameter (misal: ?IdConsult=123)
$consult_id = filter_var(isset($_GET['IdConsult']) ? $_GET['IdConsult'] : null, FILTER_VALIDATE_INT);

$invoice_data = null; // Inisialisasi variabel untuk menampung data invoice

// Periksa apakah ID konsultasi valid dan ada
if ($consult_id !== false && $consult_id !== null) {
    global $conn; // Mengakses variabel koneksi global

    // Query untuk mengambil semua detail yang dibutuhkan dari tabel customer dan consult
    $sql_invoice = "SELECT
                        con.IdConsult,
                        cust.Nama AS customer_name,
                        cust.Email AS customer_email,
                        cust.NoTelp AS customer_phone,
                        cust.NamaUsaha,
                        cust.BidangUsaha,
                        cust.Lokasi,
                        cust.JenisLayanan AS service_type,
                        cust.Harga AS total_price,
                        con.StatusPembayaran AS payment_status,
                        con.PembayaranDP AS dp_amount,
                        con.BayarLunas AS full_payment_amount,
                        con.created_at AS order_date, -- Tanggal order awal dari DB
                        con.updated_at AS last_updated -- Tanggal update terakhir dari DB
                    FROM consult AS con
                    JOIN customer AS cust ON con.IdCust = cust.IdCust
                    WHERE con.IdConsult = ?";

    if ($stmt_invoice = $conn->prepare($sql_invoice)) {
        $stmt_invoice->bind_param('i', $consult_id);
        $stmt_invoice->execute();
        $result_invoice = $stmt_invoice->get_result();

        if ($result_invoice->num_rows > 0) {
            $invoice_data = $result_invoice->fetch_assoc();
            
            // Format data harga (penting: cast ke float dulu)
            $invoice_data['total_price_formatted'] = 'Rp' . number_format((float)$invoice_data['total_price'], 0, ',', '.');
            $invoice_data['dp_amount_formatted'] = 'Rp' . number_format((float)$invoice_data['dp_amount'], 0, ',', '.');
            $invoice_data['full_payment_amount_formatted'] = 'Rp' . number_format((float)$invoice_data['full_payment_amount'], 0, ',', '.');
            
            // Hitung sisa pembayaran (penting: cast ke float dulu)
            $invoice_data['remaining_payment'] = (float)$invoice_data['total_price'] - (float)$invoice_data['dp_amount'];
            if ($invoice_data['payment_status'] == 'Lunas') {
                $invoice_data['remaining_payment'] = 0; // Jika sudah lunas, sisa pembayaran 0
            }
            $invoice_data['remaining_payment_formatted'] = 'Rp' . number_format((float)$invoice_data['remaining_payment'], 0, ',', '.');

        } else {
            // Jika invoice tidak ditemukan di database, redirect dengan pesan error
            header('location: ' . BASE_URL . '/admin/orders/index.php?error=' . urlencode('Invoice tidak ditemukan untuk ID ' . htmlspecialchars($consult_id) . '.'));
            exit();
        }
        $stmt_invoice->close();
    } else {
        // Jika terjadi kesalahan saat persiapan query, redirect dengan pesan error
        header('location: ' . BASE_URL . '/admin/orders/index.php?error=' . urlencode('Kesalahan persiapan query invoice: ' . $conn->error));
        exit();
    }
} else {
    // Jika ID tidak valid atau tidak diberikan di URL, redirect dengan pesan error
    header('location: ' . BASE_URL . '/admin/orders/index.php?error=' . urlencode('ID invoice tidak valid atau tidak diberikan.'));
    exit();
}

// Tutup koneksi database setelah semua data diambil dan diproses
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $conn->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - Order #<?php echo htmlspecialchars($invoice_data['IdConsult']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=DM+Sans:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Base styles for the invoice document */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6; 
            color: #333; 
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact; 
        }
        .invoice-container {
            width: 100%;
            max-width: 800px;
            margin: 20px auto; 
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); 
            padding: 40px; 
            box-sizing: border-box;
        }
        /* Header section with logo and invoice details */
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            border-bottom: 2px solid #204447;
            padding-bottom: 20px;
        }
        .invoice-header .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .invoice-header .logo img {
            height: 40px;
        }
        .invoice-header .logo h1 {
            font-family: 'DM Sans', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: #204447; 
            margin: 0;
        }
        .invoice-header .invoice-details {
            text-align: right;
        }
        .invoice-header .invoice-details h2 {
            font-size: 24px;
            color: #204447;
            margin: 0 0 10px 0;
            font-weight: 700;
        }
        .invoice-header .invoice-details p {
            margin: 2px 0;
            font-size: 14px;
            color: #555;
        }
        /* Address section for company and customer */
        .invoice-address-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .invoice-address-section div {
            width: 48%; 
        }
        .invoice-address-section h3 {
            font-size: 18px;
            color: #204447;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .invoice-address-section p {
            font-size: 14px;
            margin: 2px 0;
            color: #555;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .invoice-table th, .invoice-table td {
            border: 1px solid #eee; 
            padding: 12px;
            text-align: left;
            font-size: 14px;
        }
        .invoice-table th {
            background-color: #204447;
            color: #fff;
            font-weight: 600;
        }
        .invoice-table tr:nth-child(even) {
            background-color: #f9f9f9; 
        }
        /* Total section for price breakdown */
        .invoice-total-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }
        .invoice-total {
            width: 100%;
            max-width: 300px; 
        }
        .invoice-total div {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 15px;
        }
        .invoice-total div:last-child {
            border-bottom: none; 
            font-size: 18px;
            font-weight: 700;
            color: #204447;
            padding-top: 15px;
        }
        /* Footer section */
        .invoice-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 13px;
            color: #777;
        }
        .invoice-footer p {
            margin: 5px 0;
        }
        /* Signature/stamp area */
        .invoice-stamp {
            margin-top: 30px;
            display: flex;
            justify-content: flex-start; 
            align-items: flex-end;
        }
        .invoice-stamp .signature-area {
            text-align: center;
            width: 200px;
        }
        .invoice-stamp .signature-line {
            border-bottom: 1px solid #333;
            margin-top: 60px; 
            margin-bottom: 5px;
        }
        .invoice-stamp .signature-name {
            font-weight: 600;
        }

        @media print {
            body {
                background-color: #fff;
                padding: 0;
            }
            .invoice-container {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <div class="logo">
                <img src="<?php echo BASE_URL; ?>/public/Logo/Logo3.png" alt="ExpertUs Logo">
                <h1>ExpertUs</h1>
            </div>
            <div class="invoice-details">
                <h2>INVOICE</h2>
                <p>Order ID: <strong>#<?php echo htmlspecialchars($invoice_data['IdConsult']); ?></strong></p>
                <p>Tanggal: <?php echo htmlspecialchars($current_invoice_date); ?></p>
            </div>
        </div>

        <div class="invoice-address-section">
            <div>
                <h3>Informasi Perusahaan</h3>
                <p>ExpertUs</p>
                <p>Jl. Contoh Alamat No. 123</p>
                <p>Yogyakarta, Indonesia</p>
                <p>Email: info@expertus.com</p>
                <p>Tel: +62 812-3456-7890</p>
            </div>
            <div>
                <h3>Kepada:</h3>
                <p><strong><?php echo htmlspecialchars($invoice_data['customer_name']); ?></strong></p>
                <p><?php echo htmlspecialchars($invoice_data['NamaUsaha'] ?? 'N/A'); ?></p>
                <p><?php echo htmlspecialchars($invoice_data['Lokasi'] ?? 'N/A'); ?></p>
                <p>Email: <?php echo htmlspecialchars($invoice_data['customer_email']); ?></p>
                <p>Tel: <?php echo htmlspecialchars($invoice_data['customer_phone']); ?></p>
            </div>
        </div>

        <table class="invoice-table">
            <thead>
                <tr>
                    <th>Deskripsi Layanan</th>
                    <th>Harga</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo htmlspecialchars($invoice_data['service_type']); ?></td>
                    <td><?php echo htmlspecialchars($invoice_data['total_price_formatted']); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="invoice-total-section">
            <div class="invoice-total">
                <div><span>Subtotal:</span><span><?php echo htmlspecialchars($invoice_data['total_price_formatted']); ?></span></div>
                <?php if ((float)$invoice_data['dp_amount'] > 0): ?>
                    <div><span>DP Dibayar:</span><span><?php echo htmlspecialchars($invoice_data['dp_amount_formatted']); ?></span></div>
                <?php endif; ?>
                <?php if ($invoice_data['payment_status'] == 'Lunas' && (float)$invoice_data['full_payment_amount'] > 0): ?>
                    <div><span>Pembayaran Lunas:</span><span><?php echo htmlspecialchars($invoice_data['full_payment_amount_formatted']); ?></span></div>
                <?php endif; ?>
                <?php if ((float)$invoice_data['remaining_payment'] > 0): ?>
                    <div><span>Sisa Pembayaran:</span><span><?php echo htmlspecialchars($invoice_data['remaining_payment_formatted']); ?></span></div>
                <?php endif; ?>
                <div><span>Total Tagihan:</span><span><?php echo htmlspecialchars($invoice_data['total_price_formatted']); ?></span></div>
                <div><span>Status Pembayaran:</span><span><strong><?php echo htmlspecialchars($invoice_data['payment_status']); ?></strong></span></div>
            </div>
        </div>

        <div class="invoice-stamp">
            <div class="signature-area">
                <p>Hormat kami,</p>
                <div class="signature-line"></div>
                <p class="signature-name"><?php echo htmlspecialchars($admin_nama); ?> (Admin ExpertUs)</p>
            </div>
        </div>

        <div class="invoice-footer">
            <p>Terima kasih atas kepercayaan Anda kepada ExpertUs. Kami siap melayani kebutuhan bisnis Anda.</p>
            <p>Ini adalah nota yang dihasilkan secara otomatis dan mungkin tidak memerlukan tanda tangan fisik.</p>
        </div>
    </div>
</body>
</html>