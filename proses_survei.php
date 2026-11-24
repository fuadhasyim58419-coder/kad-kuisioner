<?php
// Cegah akses langsung jika bukan melalui metode POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html");
    exit;
}

// 1. Menangkap input Identitas Responden (Nama Responden bersifat opsional)
$nama_responden_raw = trim($_POST['nama_responden'] ?? '');
$nama_responden     = $nama_responden_raw !== '' ? $nama_responden_raw : '-';
$nama_perusahaan    = trim($_POST['nama_perusahaan'] ?? '');
$alamat_perusahaan  = trim($_POST['alamat_perusahaan'] ?? '');

// 2. Menangkap input Dimensi Daya Tanggap (Responsiveness)
$resp_1 = isset($_POST['resp_1']) ? (int)$_POST['resp_1'] : null;
$resp_2 = isset($_POST['resp_2']) ? (int)$_POST['resp_2'] : null;
$resp_3 = isset($_POST['resp_3']) ? (int)$_POST['resp_3'] : null;
$resp_4 = isset($_POST['resp_4']) ? (int)$_POST['resp_4'] : null;

// 3. Menangkap input Dimensi Jaminan dan Kepastian (Assurance)
$assu_1 = isset($_POST['assu_1']) ? (int)$_POST['assu_1'] : null;
$assu_2 = isset($_POST['assu_2']) ? (int)$_POST['assu_2'] : null;
$assu_3 = isset($_POST['assu_3']) ? (int)$_POST['assu_3'] : null;
$assu_4 = isset($_POST['assu_4']) ? (int)$_POST['assu_4'] : null;

// 4. Menangkap input Dimensi Empati (Empathy)
$empa_1 = isset($_POST['empa_1']) ? (int)$_POST['empa_1'] : null;
$empa_2 = isset($_POST['empa_2']) ? (int)$_POST['empa_2'] : null;
$empa_3 = isset($_POST['empa_3']) ? (int)$_POST['empa_3'] : null;
$empa_4 = isset($_POST['empa_4']) ? (int)$_POST['empa_4'] : null;

// 5. Menangkap input Dimensi Kehandalan (Reliability)
$reli_1 = isset($_POST['reli_1']) ? (int)$_POST['reli_1'] : null;
$reli_2 = isset($_POST['reli_2']) ? (int)$_POST['reli_2'] : null;
$reli_3 = isset($_POST['reli_3']) ? (int)$_POST['reli_3'] : null;
$reli_4 = isset($_POST['reli_4']) ? (int)$_POST['reli_4'] : null;

// 6. Menangkap input Dimensi Bukti Fisik (Tangible)
$tang_1 = isset($_POST['tang_1']) ? (int)$_POST['tang_1'] : null;
$tang_2 = isset($_POST['tang_2']) ? (int)$_POST['tang_2'] : null;
$tang_3 = isset($_POST['tang_3']) ? (int)$_POST['tang_3'] : null;
$tang_4 = isset($_POST['tang_4']) ? (int)$_POST['tang_4'] : null;

// 7. Menangkap input Saran dan Masukan Terbuka
$saran_masukan = $_POST['saran_masukan'] ?? null;

// ============================================================================
// KONEKSI & PENYIMPANAN KE DATABASE MYSQL (Opsional / Non-blocking)
// ============================================================================
$host = getenv('MYSQL_HOST') ?: "localhost";
$user = getenv('MYSQL_USER') ?: "root";     // Default user XAMPP
$pass = getenv('MYSQL_PASSWORD') !== false ? getenv('MYSQL_PASSWORD') : "";
$db   = getenv('MYSQL_DATABASE') ?: "db_survei_kad";
$port = (int)(getenv('MYSQL_PORT') ?: 3306);

$mysqlSuccess = false;
$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $db, $port);
    if ($conn && !$conn->connect_error) {
        // Otomatisasi struktur database:
        $conn->query("CREATE TABLE IF NOT EXISTS survei_kepuasan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_responden VARCHAR(150) NULL,
            nama_perusahaan VARCHAR(200) NULL,
            alamat_perusahaan TEXT NULL,
            resp_1 TINYINT NOT NULL,
            resp_2 TINYINT NOT NULL,
            resp_3 TINYINT NOT NULL,
            resp_4 TINYINT NOT NULL,
            assu_1 TINYINT NOT NULL,
            assu_2 TINYINT NOT NULL,
            assu_3 TINYINT NOT NULL,
            assu_4 TINYINT NOT NULL,
            empa_1 TINYINT NOT NULL,
            empa_2 TINYINT NOT NULL,
            empa_3 TINYINT NOT NULL,
            empa_4 TINYINT NOT NULL,
            reli_1 TINYINT NOT NULL,
            reli_2 TINYINT NOT NULL,
            reli_3 TINYINT NOT NULL,
            reli_4 TINYINT NOT NULL,
            tang_1 TINYINT NOT NULL,
            tang_2 TINYINT NOT NULL,
            tang_3 TINYINT NOT NULL,
            tang_4 TINYINT NOT NULL,
            saran_masukan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $checkCol = $conn->query("SHOW COLUMNS FROM survei_kepuasan LIKE 'nama_responden'");
        if ($checkCol && $checkCol->num_rows == 0) {
            $conn->query("ALTER TABLE survei_kepuasan 
                ADD COLUMN nama_responden VARCHAR(150) NULL AFTER id,
                ADD COLUMN nama_perusahaan VARCHAR(200) NULL AFTER nama_responden,
                ADD COLUMN alamat_perusahaan TEXT NULL AFTER nama_perusahaan");
        }

        $sql = "INSERT INTO survei_kepuasan (
                    nama_responden, nama_perusahaan, alamat_perusahaan,
                    resp_1, resp_2, resp_3, resp_4,
                    assu_1, assu_2, assu_3, assu_4,
                    empa_1, empa_2, empa_3, empa_4,
                    reli_1, reli_2, reli_3, reli_4,
                    tang_1, tang_2, tang_3, tang_4,
                    saran_masukan, created_at
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, NOW()
                )";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "sssiiiiiiiiiiiiiiiiiiiis",
                $nama_responden, $nama_perusahaan, $alamat_perusahaan,
                $resp_1, $resp_2, $resp_3, $resp_4,
                $assu_1, $assu_2, $assu_3, $assu_4,
                $empa_1, $empa_2, $empa_3, $empa_4,
                $reli_1, $reli_2, $reli_3, $reli_4,
                $tang_1, $tang_2, $tang_3, $tang_4,
                $saran_masukan
            );
            $stmt->execute();
            $stmt->close();
            $mysqlSuccess = true;
        }
    }
} catch (Throwable $e) {
    // Database MySQL tidak tersedia (misal di hosting serverless seperti Vercel)
    // Non-blocking: sistem tetap berjalan aman dan mengirim ke Google Spreadsheet
    error_log("Database notice: " . $e->getMessage());
} finally {
    if ($conn && !$conn->connect_error) {
        @$conn->close();
    }
}

// ============================================================================
// PENGIRIMAN DATA KE GOOGLE SPREADSHEET
// ============================================================================
require_once __DIR__ . '/google_sheets.php';
$sheetsSuccess = false;
$sheetsError = null;

try {
    $sheetsService = new GoogleSheetsService();
    $sheetsService->kirimDataSurvei([
        'nama_responden'    => $nama_responden,
        'nama_perusahaan'   => $nama_perusahaan,
        'alamat_perusahaan' => $alamat_perusahaan,
        'q1'  => $resp_1, 'q2'  => $resp_2, 'q3'  => $resp_3, 'q4'  => $resp_4,
        'q5'  => $assu_1, 'q6'  => $assu_2, 'q7'  => $assu_3, 'q8'  => $assu_4,
        'q9'  => $empa_1, 'q10' => $empa_2, 'q11' => $empa_3, 'q12' => $empa_4,
        'q13' => $reli_1, 'q14' => $reli_2, 'q15' => $reli_3, 'q16' => $reli_4,
        'q17' => $tang_1, 'q18' => $tang_2, 'q19' => $tang_3, 'q20' => $tang_4,
        'saran_masukan'     => $saran_masukan
    ]);
    $sheetsSuccess = true;
} catch (Exception $e) {
    $sheetsError = $e->getMessage();
    error_log("Google Sheets Sync Error: " . $sheetsError);
}

// Menampilkan halaman notifikasi sukses dengan branding Pelindo & PT KAD
echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survei Terkirim - PT Kaltim Adhiguna Dermaga</title>
    <link rel="icon" type="image/jpeg" href="assets/img/logo_kad.jpg">
    <link rel="apple-touch-icon" href="assets/img/logo_kad.jpg">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, rgba(0, 45, 98, 0.92) 0%, rgba(0, 80, 140, 0.85) 55%, rgba(0, 140, 200, 0.76) 100%),
                        url("assets/img/dermaga_kad.jpg") center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .notification-card {
            max-width: 500px;
            width: 100%;
            background-color: #ffffff;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            text-align: center;
            padding: 32px 24px;
        }
        .alert-success {
            background-color: #ecfdf5;
            border: 2px solid #10b981;
            color: #065f46;
            padding: 24px 18px;
            border-radius: 12px;
            margin-bottom: 22px;
        }
        .success-icon {
            width: 56px;
            height: 56px;
            background-color: #10b981;
            color: #ffffff;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 14px;
        }
        .alert-success h2 {
            font-size: 1.35rem;
            margin-bottom: 8px;
            color: #047857;
            line-height: 1.3;
        }
        .alert-success p {
            font-size: 0.94rem;
            line-height: 1.55;
        }
        .btn-selesai {
            display: block;
            width: 100%;
            padding: 15px 24px;
            background: linear-gradient(135deg, #002d62 0%, #005b96 100%);
            color: #ffffff;
            text-decoration: none;
            font-weight: 800;
            font-size: 1rem;
            border-radius: 12px;
            box-shadow: 0 6px 16px rgba(0, 45, 98, 0.25);
            transition: all 0.2s ease;
            text-align: center;
            letter-spacing: 0.3px;
        }
        .btn-selesai:hover {
            background: linear-gradient(135deg, #001a38 0%, #004573 100%);
            box-shadow: 0 8px 22px rgba(0, 45, 98, 0.35);
            transform: translateY(-1px);
        }
        .btn-selesai:active {
            transform: translateY(0);
        }
        @media (max-width: 480px) {
            .notification-card {
                padding: 26px 18px;
            }
            .alert-success {
                padding: 20px 14px;
            }
            .alert-success h2 {
                font-size: 1.2rem;
            }
            .alert-success p {
                font-size: 0.88rem;
            }
        }
    </style>
</head>
<body>
    <div class="notification-card">
        <img src="assets/img/logo_kad.jpg" alt="Logo PT KAD" style="height: 50px; margin-bottom: 16px; object-fit: contain;">
        <div class="alert-success">
            <div class="success-icon">&#10003;</div>
            <h2>Terima kasih atas penilaian Anda</h2>
            <p>Masukan dan evaluasi yang Anda berikan telah berhasil kami terima untuk peningkatan mutu layanan operasional bongkar muat (<i>stevedoring</i>) PT Kaltim Adhiguna Dermaga.</p>
        </div>
        <div>
            <a href="index.html" class="btn-selesai">&#10004; Selesai</a>
        </div>
    </div>
</body>
</html>';
exit;
?>