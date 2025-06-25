<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

checkAdminLogin();

// Sertakan PHPMailer Classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP; // Penting untuk konfigurasi SMTP

// Sertakan Dompdf Classes
use Dompdf\Dompdf;
use Dompdf\Options;

// Autoloader Composer harus sudah di sini
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

// --- PENGATURAN DEBUGGING (AKTIFKAN SAAT PENGEMBANGAN, NONAKTIFKAN DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Mulai output buffering untuk mencegah "Headers already sent" errors
ob_start();

// Mengambil nama admin yang sedang login dari sesi
$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$admin_email = $_SESSION['admin_email'] ?? '';

// --- LOGIKA UNTUK MENANGANI UPDATE STATUS & PENGIRIMAN NOTA ---
$update_success = false;
$update_error = '';
$send_note_success = '';
$send_note_error = '';

// --- START: Ambil ID order dari URL dan ambil data dari DATABASE ---
$consult_id_from_url = filter_var(isset($_GET['IdConsult']) ? $_GET['IdConsult'] : null, FILTER_VALIDATE_INT);

$order_detail = null; 

if ($consult_id_from_url !== false && $consult_id_from_url !== null) {
    global $conn;

    // Query untuk mengambil semua detail yang dibutuhkan dari tabel customer dan consult
    $sql_get_order_detail = "SELECT
                                c.IdCust,
                                c.Nama AS customer_name,
                                c.Email AS customer_email,
                                c.NoTelp AS customer_phone,
                                c.NamaUsaha AS nama_usaha,
                                c.BidangUsaha AS bidang_usaha,
                                c.Lokasi AS lokasi_usaha,
                                c.JenisLayanan AS jenis_layanan,
                                c.Harga AS price,
                                con.IdConsult,
                                con.HasilConsult,      
                                con.CatatanRevisi AS revision_text, 
                                con.Revisi1 AS revision_file_1,
                                con.Revisi2 AS revision_file_2,
                                con.HasilPengerjaan AS order_result_file, 
                                con.PembayaranDP,      
                                con.BayarLunas,        
                                con.StatusPengerjaan AS status_pengerjaan,
                                con.StatusPembayaran AS status_pembayaran
                            FROM `consult` AS con
                            JOIN `customer` AS c ON con.IdCust = c.IdCust
                            WHERE con.IdConsult = ?";

    if ($stmt_detail = $conn->prepare($sql_get_order_detail)) {
        $stmt_detail->bind_param('i', $consult_id_from_url);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();

        if ($result_detail->num_rows > 0) {
            $order_detail = $result_detail->fetch_assoc();

            // Konversi path file yang kosong/0 dari DB menjadi null untuk konsistensi
            foreach (['HasilConsult', 'revision_file_1', 'revision_file_2', 'order_result_file', 'PembayaranDP', 'BayarLunas'] as $field) {
                if (isset($order_detail[$field]) && (empty($order_detail[$field]) || $order_detail[$field] === '0' || $order_detail[$field] === '0.00')) {
                    $order_detail[$field] = null;
                }
            }

            // Memformat harga 
            $order_detail['price'] = number_format((float)$order_detail['price'], 0, ',', '.'); // Contoh format: 50.000
            
            // service_name di dummy sama dengan jenis_layanan, kita buat mapping kalau perlu
            $order_detail['service_name'] = $order_detail['jenis_layanan'];

        } else {
            error_log("DEBUG: Order dengan ID " . $consult_id_from_url . " tidak ditemukan di database.");
        }
        $stmt_detail->close();
    } else {
        $update_error = "Kesalahan persiapan query detail order: " . $conn->error;
        error_log("ERROR: Kesalahan persiapan query detail order: " . $conn->error);
    }
}

// Jika order tidak ditemukan atau ID tidak valid setelah query database
if (!$order_detail) {
    // Redirect ke halaman daftar orders dengan pesan error
    $error_msg = "Order dengan ID " . htmlspecialchars($consult_id_from_url) . " tidak ditemukan atau ID tidak valid.";
    ob_end_clean(); 
    header('location: ' . BASE_URL . '/admin/orders/index.php?error=' . urlencode($error_msg));
    exit(); 
}

// Logika untuk menangani permintaan POST (Update Status atau Kirim Nota)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    global $conn; // Pastikan $conn tersedia

    // Untuk POST, pastikan mengambil ID yang benar. ID order di form hidden
    $current_order_id_post = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);

    // Jika ID dari POST tidak valid, tangani error
    if ($current_order_id_post === false || $current_order_id_post === null) {
        $update_error = "ID Order tidak valid dari pengiriman formulir.";
        error_log("ERROR: ID Order tidak valid dari POST: " . ($_POST['order_id'] ?? 'null'));
        ob_end_clean();
        header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&update_error=' . urlencode($update_error));
        exit;
    }
    
    if ($_POST['action'] == 'update_status') {
        $new_status_pengerjaan = sanitize_input($_POST['status_pengerjaan']);
        $new_status_pembayaran = sanitize_input($_POST['status_pembayaran']);

        error_log("DEBUG UPDATE STATUS: Menerima POST update_status.");
        error_log("DEBUG UPDATE STATUS: Order ID: " . $current_order_id_post);
        error_log("DEBUG UPDATE STATUS: New Pengerjaan Status: " . $new_status_pengerjaan);
        error_log("DEBUG UPDATE STATUS: New Pembayaran Status: " . $new_status_pembayaran);

        $sql_update = "UPDATE consult SET StatusPengerjaan = ?, StatusPembayaran = ?, updated_at = NOW() WHERE IdConsult = ?";

        if ($stmt_update = $conn->prepare($sql_update)) {
            $stmt_update->bind_param('ssi', $new_status_pengerjaan, $new_status_pembayaran, $current_order_id_post);

            if ($stmt_update->execute()) {
                $update_success = true;
                $order_detail['status_pengerjaan'] = $new_status_pengerjaan;
                $order_detail['status_pembayaran'] = $new_status_pembayaran;
                error_log("DEBUG UPDATE STATUS: Status berhasil diperbarui.");
            } else {
                $update_error = "Gagal memperbarui status: " . $stmt_update->error;
                error_log("ERROR UPDATE STATUS (DB Execute): " . $stmt_update->error);
            }
            $stmt_update->close();
        } else {
            $update_error = "Kesalahan persiapan query update: " . $conn->error;
            error_log("ERROR UPDATE STATUS (DB Prepare): " . $conn->error);
        }
        ob_end_clean();
        if ($update_success) {
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&update_success=true');
        } else {
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&update_error=' . urlencode($update_error));
        }
        exit;
    } 
    elseif ($_POST['action'] == 'send_note_email') {
        if (!$order_detail) {
            $send_note_error = 'Detail order tidak ditemukan untuk pengiriman email.';
            ob_end_clean();
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&send_error=' . urlencode($send_note_error));
            exit;
        }

        $mail = new PHPMailer(true); 

        try {
            // Konfigurasi Server SMTP
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            // Menerjemahkan string 'ssl'/'tls' menjadi konstanta PHPMailer
            if (MAIL_SMTP_SECURE === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif (MAIL_SMTP_SECURE === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false; 
            }
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = 'UTF-8'; 

            // Penerima
            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            $mail->addAddress($order_detail['customer_email'], $order_detail['customer_name']);

            // Konten Email
            $mail->isHTML(true); // Set email format to HTML
            $mail->Subject = "Nota Pesanan ExpertUs Anda (" . htmlspecialchars('#' . $order_detail['IdConsult']) . ")";

            // Konten HTML untuk email
            $email_body = "
            <html>
            <head>
                <title>Nota Pesanan ExpertUs Anda</title>
                <style>
                    body { font-family: 'Poppins', sans-serif; line-height: 1.6; color: #333; }
                    .container { width: 80%; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 8px; background-color: #fff; }
                    .header { background-color: #204447; color: #fff; padding: 15px; text-align: center; border-radius: 8px 8px 0 0; }
                    .content { padding: 20px; }
                    .footer { font-size: 0.8em; text-align: center; color: #777; margin-top: 20px; }
                    .button { display: inline-block; padding: 10px 20px; margin: 10px 0; background-color: #91D047; color: #ffffff; text-decoration: none; border-radius: 5px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Nota Pesanan ExpertUs</h2>
                    </div>
                    <div class='content'>
                        <p>Halo <strong>" . htmlspecialchars($order_detail['customer_name']) . "</strong>,</p>
                        <p>Terima kasih telah menggunakan layanan ExpertUs. Berikut adalah ringkasan pesanan Anda:</p>
                        <p><strong>ID Pesanan:</strong> #" . htmlspecialchars($order_detail['IdConsult']) . "</p>
                        <p><strong>Jenis Layanan:</strong> " . htmlspecialchars($order_detail['service_name']) . "</p>
                        <p><strong>Total Harga:</strong> Rp" . htmlspecialchars($order_detail['price']) . "</p>
                        <p><strong>Status Pengerjaan:</strong> " . htmlspecialchars($order_detail['status_pengerjaan']) . "</p>
                        <p><strong>Status Pembayaran:</strong> " . htmlspecialchars($order_detail['status_pembayaran']) . "</p>";

            if (!empty($order_detail['order_result_file'])) {
                $email_body .= "<p>Hasil pengerjaan Anda dapat diunduh di link berikut: <a class='button' href='" . BASE_URL . htmlspecialchars($order_detail['order_result_file']) . "'>Unduh Hasil Pengerjaan</a></p>";
            }
            if ($order_detail['status_pembayaran'] == 'Lunas' && !empty($order_detail['BayarLunas'])) {
                $email_body .= "<p>Invoice lunas Anda dapat diunduh di link berikut: <a class='button' href='" . BASE_URL . htmlspecialchars($order_detail['BayarLunas']) . "'>Unduh Nota Lunas</a></p>";
            }
            $email_body .= "
                        <p>Jika ada pertanyaan lebih lanjut, jangan ragu untuk menghubungi kami.</p>
                        <p>Terima kasih,</p>
                        <p><strong>Tim ExpertUs</strong></p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " ExpertUs. Semua hak dilindungi.</p>
                    </div>
                </div>
            </body>
            </html>";

            $mail->Body = $email_body;
            $mail->AltBody = strip_tags($email_body); 

            $mail->send();
            $send_note_success = 'Nota via Email berhasil dikirim!';

        } catch (Exception $e) {
            $send_note_error = "Gagal mengirim nota via Email. Mailer Error: {$mail->ErrorInfo}";
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
        }

        ob_end_clean(); 
        if (!empty($send_note_success)) {
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&send_success=true');
        } else {
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&send_error=' . urlencode($send_note_error));
        }
        exit;
    } 
    elseif ($_POST['action'] == 'send_note_whatsapp') {
        // --- START: GENERATE PDF INVOICE ---
        ob_start(); 

        // Tanggal dan nama admin untuk invoice yang akan dibuat PDF
        $current_invoice_date_pdf = date('d F Y');
        $admin_nama_pdf = $_SESSION['admin_nama'] ?? 'Admin';

        // Konten HTML invoice (ini adalah versi yang digabungkan dari invoice_template.php)
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Invoice - Order #<?php echo htmlspecialchars($order_detail['IdConsult']); ?></title>
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=DM+Sans:wght@700&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'Poppins', sans-serif; margin: 0; padding: 20px; background-color: #f4f7f6; color: #333; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .invoice-container { width: 100%; max-width: 800px; margin: 20px auto; background-color: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); padding: 40px; box-sizing: border-box; position: relative; }
                .invoice-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; border-bottom: 2px solid #204447; padding-bottom: 20px; }
                .invoice-header .logo { display: flex; align-items: center; gap: 10px; }
                .invoice-header .logo img { height: 40px; }
                .invoice-header .logo h1 { font-family: 'DM Sans', sans-serif; font-size: 28px; font-weight: 700; color: #204447; margin: 0; }
                .invoice-header .invoice-details { text-align: right; }
                .invoice-header .invoice-details h2 { font-size: 24px; color: #204447; margin: 0 0 10px 0; font-weight: 700; }
                .invoice-header .invoice-details p { margin: 2px 0; font-size: 14px; color: #555; }
                .invoice-address-section { display: flex; justify-content: space-between; margin-bottom: 30px; }
                .invoice-address-section div { width: 48%; }
                .invoice-address-section h3 { font-size: 18px; color: #204447; margin-bottom: 10px; font-weight: 600; }
                .invoice-address-section p { font-size: 14px; margin: 2px 0; color: #555; }
                .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                .invoice-table th, .invoice-table td { border: 1px solid #ddd; padding: 12px; text-align: left; font-size: 14px; }
                .invoice-table th { background-color: #204447; color: #fff; font-weight: 600; }
                .invoice-table tr:nth-child(even) { background-color: #f9f9f9; }
                .invoice-total-section { display: flex; justify-content: flex-end; margin-bottom: 30px; }
                .invoice-total { width: 100%; max-width: 300px; }
                .invoice-total div { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; font-size: 15px; }
                .invoice-total div:last-child { border-bottom: none; font-size: 18px; font-weight: 700; color: #204447; padding-top: 15px; }
                .invoice-footer { text-align: center; padding-top: 20px; border-top: 1px solid #ddd; font-size: 13px; color: #777; }
                .invoice-footer p { margin: 5px 0; }
                .invoice-stamp { display: flex; justify-content: flex-start; align-items: flex-end; margin-top: 30px;} /* Tanda tangan di kiri */
                .invoice-stamp .signature-area { text-align: center; width: 200px; }
                .invoice-stamp .signature-line { border-bottom: 1px solid #333; margin-top: 60px; margin-bottom: 5px; }
                .invoice-stamp .signature-name { font-weight: 600; }
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
                        <p>Order ID: <strong>#<?php echo htmlspecialchars($order_detail['IdConsult']); ?></strong></p>
                        <p>Tanggal: <?php echo htmlspecialchars($current_invoice_date_pdf); ?></p> </div>
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
                        <p><strong><?php echo htmlspecialchars($order_detail['customer_name']); ?></strong></p>
                        <p><?php echo htmlspecialchars($order_detail['nama_usaha'] ?? 'N/A'); ?></p>
                        <p><?php echo htmlspecialchars($order_detail['lokasi_usaha'] ?? 'N/A'); ?></p>
                        <p>Email: <?php echo htmlspecialchars($order_detail['customer_email']); ?></p>
                        <p>Tel: <?php echo htmlspecialchars($order_detail['customer_phone']); ?></p>
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
                            <td><?php echo htmlspecialchars($order_detail['service_name']); ?></td>
                            <td><?php echo htmlspecialchars($order_detail['price']); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="invoice-total-section">
                    <div class="invoice-total">
                        <div><span>Subtotal:</span><span><?php echo htmlspecialchars($order_detail['price']); ?></span></div>
                        <?php if ((float)$order_detail['PembayaranDP'] > 0): ?>
                            <div><span>DP Dibayar:</span><span>Rp<?php echo number_format((float)$order_detail['PembayaranDP'], 0, ',', '.'); ?></span></div>
                        <?php endif; ?>
                        <?php if ($order_detail['status_pembayaran'] == 'Lunas' && (float)$order_detail['BayarLunas'] > 0): ?>
                            <div><span>Pembayaran Lunas:</span><span>Rp<?php echo number_format((float)$order_detail['BayarLunas'], 0, ',', '.'); ?></span></div>
                        <?php endif; ?>
                        <?php 
                        $remaining_payment_display = (float)$order_detail['price'] - (float)$order_detail['PembayaranDP'];
                        if ($order_detail['status_pembayaran'] == 'Lunas') {
                            $remaining_payment_display = 0;
                        }
                        if ($remaining_payment_display > 0): ?>
                            <div><span>Sisa Pembayaran:</span><span>Rp<?php echo number_format($remaining_payment_display, 0, ',', '.'); ?></span></div>
                        <?php endif; ?>
                        <div><span>Total Tagihan:</span><span><?php echo htmlspecialchars($order_detail['price']); ?></span></div>
                        <div><span>Status Pembayaran:</span><span><strong><?php echo htmlspecialchars($order_detail['status_pembayaran']); ?></strong></span></div>
                    </div>
                </div>

                <div class="invoice-stamp">
                    <div class="signature-area">
                        <p>Hormat kami,</p>
                        <div class="signature-line"></div>
                        <p class="signature-name"><?php echo htmlspecialchars($admin_nama_pdf); ?> (Admin ExpertUs)</p>
                    </div>
                </div>

                <div class="invoice-footer">
                    <p>Terima kasih atas kepercayaan Anda kepada ExpertUs. Kami siap melayani kebutuhan bisnis Anda.</p>
                    <p>Ini adalah nota yang dihasilkan secara otomatis dan mungkin tidak memerlukan tanda tangan fisik.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        $invoice_content = ob_get_clean(); // Tangkap semua output HTML ke variabel $invoice_content

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($invoice_content); 
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Tentukan lokasi penyimpanan PDF di server
        $pdf_filename = 'invoice_' . $order_detail['IdConsult'] . '_' . date('YmdHis') . '.pdf';
        $pdf_physical_path = dirname(dirname(dirname(__DIR__))) . '/public/files/invoices/' . $pdf_filename;
        $pdf_relative_url_path_for_link = '/public/files/invoices/' . $pdf_filename;

        // Pastikan direktori 'invoices' ada
        $invoice_dir = dirname($pdf_physical_path);
        if (!is_dir($invoice_dir)) {
            if (!mkdir($invoice_dir, 0775, true)) {
                $send_note_error = 'Gagal membuat direktori penyimpanan invoice PDF.';
                ob_end_clean();
                header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&send_error=' . urlencode($send_note_error));
                exit;
            }
        }

        // Simpan PDF ke file
        if (file_put_contents($pdf_physical_path, $dompdf->output()) === false) {
            $send_note_error = 'Gagal menyimpan file invoice PDF ke server.';
            ob_end_clean();
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&send_error=' . urlencode($send_note_error));
            exit;
        }

        $phone_number = preg_replace('/[^0-9]/', '', $order_detail['customer_phone']);
        if (!empty($phone_number) && substr($phone_number, 0, 1) == '0') {
            $phone_number = '62' . substr($phone_number, 1);
        } elseif (empty($phone_number)) {
            $send_note_error = 'Nomor telepon pelanggan tidak tersedia.';
            ob_end_clean();
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&send_error=' . urlencode($send_note_error));
            exit;
        }
        
        $whatsapp_message = "Halo " . $order_detail['customer_name'] . ",%0A%0A";
        $whatsapp_message .= "Berikut adalah nota pesanan Anda dengan ID: *" . htmlspecialchars('#' . $order_detail['IdConsult']) . "*.%0A";
        $whatsapp_message .= "Anda dapat mengunduh nota lengkapnya dalam format PDF melalui tautan ini:%0A";
        $whatsapp_message .= BASE_URL . urlencode($pdf_relative_url_path_for_link) . "%0A%0A"; // Link ke PDF yang baru dibuat

        $whatsapp_message .= "Status Pengerjaan: *" . $order_detail['status_pengerjaan'] . "*%0A";
        $whatsapp_message .= "Status Pembayaran: *" . $order_detail['status_pembayaran'] . "*%0A%0A";
        $whatsapp_message .= "Detail Layanan: " . $order_detail['service_name'] . "%0A";
        $whatsapp_message .= "Total Harga: Rp" . $order_detail['price'] . "%0A%0A";
        
        $whatsapp_message .= "Terima kasih telah menggunakan layanan ExpertUs.%0A";
        $whatsapp_message .= "Hormat kami,%0ATim ExpertUs";
        
        $whatsapp_url = "https://wa.me/" . $phone_number . "?text=" . urlencode(str_replace('%0A', "\n", $whatsapp_message));
        
        ob_end_clean();
        header('location: ' . $whatsapp_url);
        exit;
    }

    elseif ($_POST['action'] == 'save_revision_notes') {
        $revision_text = sanitize_input($_POST['revision_text'] ?? '');

        $sql_update_revision = "UPDATE consult SET CatatanRevisi = ?, updated_at = NOW() WHERE IdConsult = ?";

        if ($stmt_update_revision = $conn->prepare($sql_update_revision)) {
            $stmt_update_revision->bind_param('si', $revision_text, $current_order_id_post);

            if ($stmt_update_revision->execute()) {
                $update_success = true;
                $order_detail['revision_text'] = $revision_text;
            } else {
                $update_error = "Gagal menyimpan catatan revisi: " . $stmt_update_revision->error;
            }
            $stmt_update_revision->close();
        } else {
            $update_error = "Kesalahan persiapan query simpan catatan revisi: " . $conn->error;
        }

        ob_end_clean();
        if ($update_success) {
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&update_success=true');
        } else {
            header('location: ' . BASE_URL . '/admin/orders/detail.php?IdConsult=' . urlencode($consult_id_from_url) . '&update_error=' . urlencode($update_error));
        }
        exit;
    }
}

// Tangani pesan success/error dari redirect GET
if (isset($_GET['update_success'])) {
    if ($_GET['update_success'] == 'true') {
        $update_success = true;
        $update_error = '';
    } else {
        $update_error = htmlspecialchars(urldecode($_GET['update_success'])); 
        $update_success = false;
    }
}
if (isset($_GET['update_error'])) {
    $update_error = htmlspecialchars(urldecode($_GET['update_error']));
    $update_success = false;
}

if (isset($_GET['send_success'])) {
    if ($_GET['send_success'] == 'true') {
        $send_note_success = 'Nota berhasil dikirim!';
        $send_note_error = '';
    } else {
        $send_note_error = isset($_GET['send_error']) ? htmlspecialchars(urldecode($_GET['send_error'])) : 'Gagal mengirim nota.';
        $send_note_success = '';
    }
}
if (isset($_GET['send_error'])) {
    $send_note_error = htmlspecialchars(urldecode($_GET['send_error']));
    $send_note_success = '';
}


// Fungsi helper untuk mendapatkan kelas CSS dot status
function getStatusDotClass($status_value) {
    switch (strtolower($status_value)) {
        case 'on progress':
            return 'on-progress';
        case 'finished':
            return 'completed';
        case 'pending':
            return 'pending';
        case 'cancelled':
            return 'cancelled';
        case 'lunas':
            return 'lunas';
        case 'dp dibayar':
            return 'dp-dibayar';
        case 'belum dibayar':
        case 'belum lunas':
            return 'belum-dibayar';
        default:
            return '';
    }
}

// Fungsi helper untuk mendapatkan ekstensi file
function getFileExtension($path) {
    if (empty($path)) return '';
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

// Fungsi helper untuk mendapatkan icon path berdasarkan ekstensi (Hanya untuk ikon, bukan preview gambar asli)
function getFileIconPath($extension) {
    global $BASE_URL;
    switch ($extension) {
        case 'pdf':
            return BASE_URL . '/public/images/icons/logopdf.png';
        case 'zip':
        case 'rar':
        case '7z':
            return BASE_URL . '/public/images/icons/archive-icon.png';
        default:
            return BASE_URL . '/public/images/icons/default-file-icon.png';
    }
}

// Fungsi helper untuk mendapatkan nama file dari path URL
function getFileNameFromUrl($url_path) {
    if (empty($url_path)) return 'Tidak ada file';
    return basename($url_path);
}

// Setelah semua logika PHP selesai, render HTML
ob_end_flush(); // Akhiri output buffering dan kirimkan output ke browser
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - Order <?php echo htmlspecialchars('#' . $order_detail['IdConsult']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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

        /* Order Details Page Specific Styles - Revised */
        .order-detail-card {
            background-color: white; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 2rem; margin-bottom: 2rem; opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.2s forwards;
        }
        .detail-header {
            display: flex; align-items: center; margin-bottom: 2rem; color: #1F2937;
        }
        .detail-header a {
            color: #4B5563; margin-right: 1rem; font-size: 1.5rem; text-decoration: none; transition: color 0.2s;
        }
        .detail-header a:hover { color: #1F2937; }
        .detail-header h2 { font-size: 1.5rem; font-weight: 600; }
        .detail-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;
        }
        .detail-column-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .detail-section-card {
            background-color: white; border-radius: 0.5rem; border: 1px solid #E5E7EB;
            padding: 1.5rem; box-shadow: none; display: flex; flex-direction: column;
        }
        .detail-section-card h3 {
            font-size: 1.125rem; font-weight: 600; color: #1F2937; margin-bottom: 1.25rem;
        }
        .detail-item {
            display: flex; align-items: center; margin-bottom: 0.8rem;
            color: #4B5563; font-size: 0.95rem;
        }
        .detail-item:last-child { margin-bottom: 0; }
        .detail-item i {
            color: #6B7280; width: 24px; text-align: left; margin-right: 0.75rem;
            font-size: 1rem; flex-shrink: 0;
        }
        .detail-item span { flex-grow: 1; }
        .detail-item strong { font-weight: 500; color: #1F2937; }

        /* STATUS FIELDS */
        .status-fields-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: flex-start;
        }
        .status-field-group {
            flex: 1;
            min-width: 140px;
            position: relative; 
        }
        .status-field-group label {
            font-size: 0.85rem;
            color: #6B7280;
            font-weight: 500;
            display: block;
            margin-bottom: 0.5rem;
        }
        /* Wrapper yang menampung select, display, dan panah */
        .custom-select-wrapper {
            position: relative;
            width: 100%;
            height: 42px; 
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            background-color: white; 
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .custom-select-wrapper:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
        }

        /* Select asli (tetap di atas, padding besar agar titik tidak bertabrakan) */
        .status-field-group select.status-select {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            padding: 0.75rem 1rem; 
            padding-left: 2.2rem; 
            background-color: transparent;
            color: transparent; 
            border: none;
            outline: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            cursor: pointer;
            z-index: 5;

            /* Tambahan untuk memastikan opsi terlihat di Safari/iOS */
            -webkit-text-fill-color: initial;
            opacity: 1;
        }
        /* Gaya untuk option di dalam select (khusus untuk memastikan teks terlihat) */
        .status-field-group select.status-select option {
            background-color: white; 
            color: #4B5563; 
            padding: 0.5rem 1rem;
        }


        /* Elemen yang menampilkan teks dan titik warna (yang terlihat) */
        .select-display {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            padding: 0.75rem 1rem; 
            padding-left: 2.2rem; 
            display: flex;
            align-items: center;
            background-color: white;
            color: #4B5563; 
            font-size: 0.95rem;
            font-weight: 500;
            pointer-events: none;
            z-index: 1; 
        }
        .select-display .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
        /* Panah kustom untuk select (Font Awesome) */
        .custom-select-wrapper .select-arrow {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6B7280; 
            font-size: 0.8rem;
            z-index: 6; 
            pointer-events: none;
        }

        /* Warna titik status (di dalam select-display) */
        .select-display .status-dot.on-progress { background-color: #3b82f6; } 
        .select-display .status-dot.completed { background-color: #10B981; } 
        .select-display .status-dot.pending { background-color: #f59e0b; } 
        .select-display .status-dot.cancelled { background-color: #ef4444; }
        .select-display .status-dot.lunas { background-color: #10B981; } 
        .select-display .status-dot.dp-dibayar { background-color: #3b82f6; } 
        .select-display .status-dot.belum-dibayar { background-color: #ef4444; }


        /* FILE DOWNLOAD CARD - FINAL REVISED DESIGN */
        .file-card-item {
            background-color: white;
            border: 1px dotted #D1D5DB;
            border-radius: 0.5rem;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: background-color 0.2s;
            cursor: pointer;
            width: 100%;
            box-sizing: border-box;
            min-height: 120px;
            justify-content: center;
            text-decoration: none;
        }
        .file-card-item:hover {
            background-color: #F3F4F6;
        }

        .file-icon-wrapper {
            background-color: #fee2e2; 
            border-radius: 0.375rem;
            width: 64px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: 0.75rem;
        }
        /* Menggunakan tag <img> untuk ikon PDF */
        .file-icon-wrapper img.file-type-icon { 
            width: 70%;
            height: 70%;
            object-fit: contain;
            padding: 0;
        }
        .file-icon-wrapper img.file-preview-image { 
            width: 100%;
            height: 100%;
            object-fit: cover; 
            border-radius: 0.375rem;
            padding: 0;
        }


        .file-name-text {
            font-size: 0.95rem;
            color: #4b5563;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            display: block;
        }
        .download-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            cursor: pointer;
        }
        .file-card-item:hover .download-overlay {
            opacity: 1;
        }
        .download-overlay i.fas.fa-download {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            font-size: 2.5rem;
            color: white;
            margin: 0;
        }
        .download-overlay i.fas.fa-download::before {
            content: "\f019";
        }


        /* Revision Text */
        .revision-text {
            color: #4B5563; font-size: 0.95rem; line-height: 1.5; font-weight: 400;
        }

        /* Buttons Container */
        .button-group-bottom {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-right: 1.5rem;
        }
        .action-button {
            background-color: #3B82F6;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 9999px; 
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.2s, box-shadow 0.2s, border-radius 0.2s;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3), 0 2px 4px -1px rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: none; 
            cursor: pointer; 
        }
        .action-button:hover {
            background-color: #2563EB;
            box-shadow: 0 6px 8px -2px rgba(59, 130, 246, 0.4), 0 3px 5px -2px rgba(59, 130, 246, 0.2);
        }
        .action-button.send-note {
            background-color: #10B981;
        }
        .action-button.send-note:hover {
            background-color: #059669;
        }
        /* Style untuk tombol "Simpan Catatan" */
        .detail-section-card form button[type="submit"] {
            background-color: #91D047; 
            color: white;
            padding: 0.6rem 1.2rem; 
            font-weight: 600;
            border-radius: 0.5rem; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: background-color 0.2s ease-in-out;
        }
        .detail-section-card form button[type="submit"]:hover {
            background-color: #7bc13f; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .action-button.edit-button {
            background-color: #6B7280;
        }
        .action-button.edit-button:hover {
            background-color: #4B5563;
        }
        .action-button.view-invoice-button {
            background-color: #60A5FA; 
        }
        .action-button.view-invoice-button:hover {
            background-color: #3B82F6; 
        }


        /* Responsive adjustments */
        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .status-fields-container {
                flex-direction: column;
                align-items: flex-start;
            }
            .button-group-bottom {
                flex-direction: column;
                gap: 0.75rem;
                text-align: center;
                padding-right: 0;
            }
            .action-button {
                width: 100%;
                justify-content: center;
            }
            .file-card-item {
                flex-direction: column;
                padding: 1rem;
                min-height: auto;
            }
            .file-icon-wrapper {
                margin-bottom: 0.75rem;
                margin-right: 0;
            }
            .file-name-text {
                text-align: center;
            }
        }
    </style>
</head>
<body class="flex">
    <?php include dirname(dirname(__DIR__)) . '/admin/includes/sidebar.php'; ?>

    <div class="content">
        <?php if ($update_success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                Status berhasil diperbarui!
            </div>
        <?php endif; ?>
        <?php if (!empty($update_error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <?php echo htmlspecialchars($update_error); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($send_note_success)): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative mb-4" role="alert">
                <?php echo htmlspecialchars($send_note_success); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($send_note_error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <?php echo htmlspecialchars($send_note_error); ?>
            </div>
        <?php endif; ?>


        <div class="order-detail-card">
            <div class="detail-header">
                <a href="<?php echo BASE_URL; ?>/admin/orders/index.php"><i class="fas fa-chevron-left"></i></a>
                <h2>Order Details - #<?php echo htmlspecialchars($order_detail['IdConsult']); ?></h2>
            </div>

            <div class="detail-grid">
                <div class="detail-column-wrapper">
                    <div class="detail-section-card">
                        <h3>Customer Details</h3>
                        <div class="detail-item">
                            <i class="fas fa-user"></i> <span><strong><?php echo htmlspecialchars($order_detail['customer_name']); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-envelope"></i> <span><?php echo htmlspecialchars($order_detail['customer_email']); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-phone"></i> <span><?php echo htmlspecialchars($order_detail['customer_phone']); ?></span>
                        </div>
                    </div>

                    <div class="detail-section-card">
                        <h3>Status</h3>
                        <form action="" method="POST" id="statusUpdateForm">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">
                            
                            <div class="status-fields-container">
                                <div class="status-field-group">
                                    <label for="status_pengerjaan">Status Pengerjaan:</label>
                                    <div class="custom-select-wrapper">
                                        <select id="status_pengerjaan" name="status_pengerjaan" class="status-select">
                                            <option value="On Progress" <?php echo ($order_detail['status_pengerjaan'] == 'On Progress') ? 'selected' : ''; ?>>On Progress</option>
                                            <option value="Finished" <?php echo ($order_detail['status_pengerjaan'] == 'Finished') ? 'selected' : ''; ?>>Finished</option>
                                            <option value="Pending" <?php echo ($order_detail['status_pengerjaan'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Cancelled" <?php echo ($order_detail['status_pengerjaan'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                        <div class="select-display">
                                            <span class="status-dot"></span>
                                            <span class="status-text-content"></span>
                                        </div>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>

                                <div class="status-field-group">
                                    <label for="status_pembayaran">Status Pembayaran:</label>
                                    <div class="custom-select-wrapper">
                                        <select id="status_pembayaran" name="status_pembayaran" class="status-select">
                                            <option value="Belum Dibayar" <?php echo ($order_detail['status_pembayaran'] == 'Belum Dibayar') ? 'selected' : ''; ?>>Belum Dibayar</option>
                                            <option value="DP Dibayar" <?php echo ($order_detail['status_pembayaran'] == 'DP Dibayar') ? 'selected' : ''; ?>>DP Dibayar</option>
                                            <option value="Lunas" <?php echo ($order_detail['status_pembayaran'] == 'Lunas') ? 'selected' : ''; ?>>Lunas</option>
                                        </select>
                                        <div class="select-display">
                                            <span class="status-dot"></span>
                                            <span class="status-text-content"></span>
                                        </div>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="detail-section-card">
                        <h3>Additional Information</h3>
                        <div class="detail-item">
                            <i class="fas fa-building"></i> <span>Nama Usaha: <strong><?php echo htmlspecialchars($order_detail['nama_usaha'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-industry"></i> <span>Bidang Usaha: <strong><?php echo htmlspecialchars($order_detail['bidang_usaha'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-map-marker-alt"></i> <span>Lokasi: <strong><?php echo htmlspecialchars($order_detail['lokasi_usaha'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-cogs"></i> <span>Jenis Layanan: <strong><?php echo htmlspecialchars($order_detail['jenis_layanan'] ?? 'N/A'); ?></strong></span>
                        </div>

                        <h3 class="mt-4 mb-2">File Konsultasi</h3>
                        <?php if (!empty($order_detail['HasilConsult'])): ?>
                            <?php
                            $file_url = BASE_URL . $order_detail['HasilConsult']; // URL lengkap
                            $file_ext = getFileExtension($order_detail['HasilConsult']);
                            $file_name = getFileNameFromUrl($order_detail['HasilConsult']);
                            ?>
                            <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" class="file-card-item">
                                <div class="file-icon-wrapper">
                                    <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                        <img src="<?php echo htmlspecialchars($file_url); ?>" alt="Preview Gambar" class="file-preview-image">
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars(getFileIconPath($file_ext)); ?>" alt="<?php echo htmlspecialchars($file_ext); ?> Icon" class="file-type-icon">
                                    <?php endif; ?>
                                </div>
                                <span class="file-name-text"><?php echo htmlspecialchars($file_name); ?></span>
                                <div class="download-overlay"><i class="fas fa-download"></i></div>
                            </a>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm mt-2">Tidak ada file konsultasi.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-column-wrapper">
                    <div class="detail-section-card">
                        <h3>Results</h3>
                        <div class="detail-item">
                            <i class="fas fa-tag"></i> <span>Service: <strong><?php echo htmlspecialchars($order_detail['service_name'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-dollar-sign"></i> <span>Price: <strong>Rp<?php echo htmlspecialchars($order_detail['price'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <h3 class="mt-4 mb-2">Order Result File</h3>
                        <?php if (!empty($order_detail['order_result_file'])): ?>
                            <?php
                            $file_url = BASE_URL . $order_detail['order_result_file'];
                            $file_ext = getFileExtension($order_detail['order_result_file']);
                            $file_name = getFileNameFromUrl($order_detail['order_result_file']);
                            ?>
                            <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" class="file-card-item">
                                <div class="file-icon-wrapper">
                                    <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                        <img src="<?php echo htmlspecialchars($file_url); ?>" alt="Preview Gambar" class="file-preview-image">
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars(getFileIconPath($file_ext)); ?>" alt="<?php echo htmlspecialchars($file_ext); ?> Icon" class="file-type-icon">
                                    <?php endif; ?>
                                </div>
                                <span class="file-name-text"><?php echo htmlspecialchars($file_name); ?></span>
                                <div class="download-overlay"><i class="fas fa-download"></i></div>
                            </a>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm mt-2">Hasil order belum diunggah.</p>
                        <?php endif; ?>
                    </div>

                    <div class="detail-section-card">
                        <h3>Revision Notes</h3> <form action="" method="POST">
                            <input type="hidden" name="action" value="save_revision_notes">
                            <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">
                            
                            <div class="form-group mb-4">
                                <label for="revision_text" class="block text-sm font-medium text-gray-700 mb-1">Catatan Revisi:</label>
                                <textarea id="revision_text" name="revision_text" rows="4" 
                                          class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                          placeholder="Masukkan catatan revisi di sini..."><?php echo htmlspecialchars($order_detail['revision_text'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-500 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Simpan Catatan
                            </button>
                        </form>

                        <h3 class="mt-6 mb-2">Revision Files</h3> <div class="grid grid-cols-1 gap-2">
                            <?php if (!empty($order_detail['revision_file_1'])): ?>
                                <?php
                                $file_url = BASE_URL . $order_detail['revision_file_1'];
                                $file_ext = getFileExtension($order_detail['revision_file_1']);
                                $file_name = getFileNameFromUrl($order_detail['revision_file_1']);
                                ?>
                                <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" class="file-card-item">
                                    <div class="file-icon-wrapper">
                                        <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                            <img src="<?php echo htmlspecialchars($file_url); ?>" alt="Preview Gambar" class="file-preview-image">
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars(getFileIconPath($file_ext)); ?>" alt="<?php echo htmlspecialchars($file_ext); ?> Icon" class="file-type-icon">
                                        <?php endif; ?>
                                    </div>
                                    <span class="file-name-text"><?php echo htmlspecialchars($file_name); ?></span>
                                    <div class="download-overlay"><i class="fas fa-download"></i></div>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($order_detail['revision_file_2'])): ?>
                                <?php
                                $file_url = BASE_URL . $order_detail['revision_file_2'];
                                $file_ext = getFileExtension($order_detail['revision_file_2']);
                                $file_name = getFileNameFromUrl($order_detail['revision_file_2']);
                                ?>
                                <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" class="file-card-item">
                                    <div class="file-icon-wrapper">
                                        <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                            <img src="<?php echo htmlspecialchars($file_url); ?>" alt="Preview Gambar" class="file-preview-image">
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars(getFileIconPath($file_ext)); ?>" alt="<?php echo htmlspecialchars($file_ext); ?> Icon" class="file-type-icon">
                                        <?php endif; ?>
                                    </div>
                                    <span class="file-name-text"><?php echo htmlspecialchars($file_name); ?></span>
                                    <div class="download-overlay"><i class="fas fa-download"></i></div>
                                </a>
                            <?php endif; ?>
                            <?php if (empty($order_detail['revision_file_1']) && empty($order_detail['revision_file_2'])): ?>
                                <p class="text-gray-500 text-sm col-span-1 mt-2">Tidak ada file revisi yang diunggah.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="button-group-bottom">
                <form action="" method="POST" style="display:inline-block;">
                    <input type="hidden" name="action" value="send_note_email">
                    <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">
                    <button type="submit" class="action-button send-note">
                        <i class="fas fa-envelope"></i> Kirim Nota via Email
                    </button>
                </form>
                <form action="" method="POST" style="display:inline-block;">
                    <input type="hidden" name="action" value="send_note_whatsapp">
                    <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">
                    <button type="submit" class="action-button send-note">
                        <i class="fab fa-whatsapp"></i> Kirim Nota via WhatsApp
                    </button>
                </form>
                <a href="<?php echo BASE_URL; ?>/admin/orders/invoice_template.php?IdConsult=<?php echo htmlspecialchars($order_detail['IdConsult']); ?>" target="_blank" class="action-button view-invoice-button">
                    <i class="fas fa-file-invoice"></i> Lihat Nota PDF
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/orders/edit.php?IdConsult=<?php echo urlencode($order_detail['IdConsult']); ?>" class="action-button edit-button">
                    <i class="fas fa-edit"></i> Edit Data
                </a>
            </div>

        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Fungsi untuk mendapatkan kelas warna berdasarkan status (digunakan di JS)
            function getStatusColorClass(statusText) {
                // Mengganti spasi dengan tanda hubung dan mengubah ke lowercase untuk kelas CSS
                const statusMap = {
                    'on progress': 'on-progress',
                    'finished': 'completed', 
                    'pending': 'pending',
                    'active': 'on-progress', 
                    'cancelled': 'cancelled',
                    'lunas': 'lunas',
                    'dp dibayar': 'dp-dibayar',
                    'belum dibayar': 'belum-dibayar'
                };
                return statusMap[statusText.toLowerCase()] || ''; // Return empty string if no match
            }

            // Inisialisasi dan perbarui tampilan status pada setiap custom-select-wrapper
            document.querySelectorAll('.custom-select-wrapper').forEach(wrapper => {
                const selectElement = wrapper.querySelector('select.status-select');
                const selectDisplay = wrapper.querySelector('.select-display'); 
                const statusDot = selectDisplay.querySelector('.status-dot'); 
                const statusTextContent = selectDisplay.querySelector('.status-text-content'); 

                // Fungsi untuk memperbarui tampilan (titik dan teks)
                function updateSelectAppearance(el) {
                    // Update teks yang terlihat di div display
                    statusTextContent.textContent = el.options[el.selectedIndex].textContent;

                    // Hapus semua kelas warna dari titik dan tambahkan yang baru
                    statusDot.className = 'status-dot'; 
                    const colorClass = getStatusColorClass(el.value);
                    if (colorClass) {
                        statusDot.classList.add(colorClass);
                    }
                }
                
                // Panggil saat DOMContentLoaded untuk inisialisasi awal
                updateSelectAppearance(selectElement); 

                // Menambahkan event listener untuk mengubah tampilan saat pilihan select berubah
                // Ini juga akan otomatis submit form ketika status diubah
                selectElement.addEventListener('change', function() {
                    updateSelectAppearance(this);
                    this.form.submit(); 
                });

                // Tambahkan event listener untuk efek fokus pada wrapper
                selectElement.addEventListener('focus', function() {
                    wrapper.classList.add('focused');
                });
                selectElement.addEventListener('blur', function() {
                    wrapper.classList.remove('focused');
                });
            });
        });
    </script>
</body>
</html>