<?php
// Pastikan direktori session dapat ditulis di lingkungan serverless (misal Vercel /tmp)
if (session_status() === PHP_SESSION_NONE) {
    $savePath = session_save_path();
    if (empty($savePath) || !@is_writable($savePath)) {
        if (is_dir('/tmp') && is_writable('/tmp')) {
            @session_save_path('/tmp');
        }
    }
    @session_start();
}

$secret_key = getenv('ADMIN_SECRET') ?: 'pt_kad_stevedoring_secret_2026';
$default_username = getenv('ADMIN_USER') ?: "admin";

// Proteksi Keamanan: Periksa apakah admin sudah login via Session atau Signed Auth Cookie
$is_logged = false;
if (isset($_SESSION['kad_admin_logged']) && $_SESSION['kad_admin_logged'] === true) {
    $is_logged = true;
} elseif (isset($_COOKIE['kad_admin_auth'])) {
    $expected_cookie = hash_hmac('sha256', 'kad_admin_authorized_' . $default_username, $secret_key);
    if (hash_equals($expected_cookie, $_COOKIE['kad_admin_auth'])) {
        $_SESSION['kad_admin_logged'] = true;
        $_SESSION['admin_user'] = $default_username;
        $is_logged = true;
    }
}

if (!$is_logged) {
    header("Location: login.php");
    exit;
}

// ----------------------------------------------------------------------------
// FITUR LOGOUT ADMIN
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['kad_admin_logged']);
    unset($_SESSION['admin_user']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
    setcookie('kad_admin_auth', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true
    ]);
    header("Location: login.php");
    exit;
}

// ============================================================================
// DASHBOARD ANALISIS KEPUASAN PELANGGAN - PT KALTIM ADHIGUNA DERMAGA
// Metode Perhitungan Skala Likert 4 Kategori (SP=4, P=3, CP=2, TP=1)
// Dinamis menyesuaikan dengan seluruh jumlah perusahaan responden (N)
// ============================================================================

$host = getenv('MYSQL_HOST') ?: "localhost";
$user = getenv('MYSQL_USER') ?: "root";
$pass = getenv('MYSQL_PASSWORD') !== false ? getenv('MYSQL_PASSWORD') : "";
$db   = getenv('MYSQL_DATABASE') ?: "db_survei_kad";
$port = (int)(getenv('MYSQL_PORT') ?: 3306);

$conn = null;
$dbConnected = false;

try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $db, $port);
    if ($conn && !$conn->connect_error) {
        $dbConnected = true;
    }
} catch (Throwable $e) {
    $dbConnected = false;
}

// ----------------------------------------------------------------------------
// FITUR RESET DATA PERCOBAAN (TRUNCATE TABLE) JIKA MYSQL TERHUBUNG
// ----------------------------------------------------------------------------
if ($dbConnected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset']) && $_POST['action_reset'] === 'confirm') {
    $conn->query("TRUNCATE TABLE survei_kepuasan");
    header("Location: dashboard.php?msg=reset_ok");
    exit;
}

// Helper: konversi nilai angka 1-4 ke kode singkatan
function scoreToCode($val) {
    switch ((int)$val) {
        case 4: return 'SP';
        case 3: return 'P';
        case 2: return 'CP';
        case 1: return 'TP';
        default: return '-';
    }
}

// Helper: konversi nilai persentase ke rating skala
function getRatingScale($percent) {
    if ($percent >= 75.1) return ['code' => 'SP', 'label' => 'Sangat Puas', 'badge' => 'badge-sp'];
    if ($percent >= 50.1) return ['code' => 'P',  'label' => 'Puas',        'badge' => 'badge-p'];
    if ($percent >= 25.1) return ['code' => 'CP', 'label' => 'Cukup Puas',  'badge' => 'badge-cp'];
    return ['code' => 'TP', 'label' => 'Tidak Puas', 'badge' => 'badge-tp'];
}

// ----------------------------------------------------------------------------
// AMBIL SEMUA DATA RESPONDEN (DARI MYSQL ATAU GOOGLE SPREADSHEET LIVE)
// ----------------------------------------------------------------------------
$rows = [];
$dataSource = "";

if ($dbConnected) {
    $query = "SELECT * FROM survei_kepuasan ORDER BY id ASC";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $dataSource = "MySQL Database";
    }
}

// Fallback otomatis jika database offline/kosong (seperti di Vercel Serverless)
if (empty($rows)) {
    require_once __DIR__ . '/google_sheets.php';
    try {
        $sheetsService = new GoogleSheetsService();
        $sheetRows = $sheetsService->ambilSemuaDataSurvei();
        if (!empty($sheetRows)) {
            $rows = $sheetRows;
            $dataSource = "Google Spreadsheet (Live Cloud API)";
        }
    } catch (Throwable $e) {
        error_log("Gagal membaca Google Sheets: " . $e->getMessage());
    }
}

if (empty($dataSource)) {
    $dataSource = $dbConnected ? "MySQL (Belum Ada Data)" : "Google Spreadsheet (Belum Ada Data)";
}

// Jumlah total responden dinamis (N)
$N = count($rows);

// Inisialisasi frekuensi dan total skor untuk 20 butir pertanyaan
$questions = [
    'resp_1', 'resp_2', 'resp_3', 'resp_4',
    'assu_1', 'assu_2', 'assu_3', 'assu_4',
    'empa_1', 'empa_2', 'empa_3', 'empa_4',
    'reli_1', 'reli_2', 'reli_3', 'reli_4',
    'tang_1', 'tang_2', 'tang_3', 'tang_4'
];

$questionDetails = [
    1  => ['dim' => 'Daya Tanggap (<i>Responsiveness</i>)', 'text' => 'Kecepatan dan ketepatan petugas dalam merespons komunikasi (telepon, pesan, atau email) dari pelanggan.'],
    2  => ['dim' => 'Daya Tanggap (<i>Responsiveness</i>)', 'text' => 'Kemudahan dan kecepatan dalam proses administrasi serta alur pemesanan layanan bongkar muat.'],
    3  => ['dim' => 'Daya Tanggap (<i>Responsiveness</i>)', 'text' => 'Sikap proaktif petugas dalam memberikan informasi dan <i>update</i> perkembangan kegiatan operasional secara berkala.'],
    4  => ['dim' => 'Daya Tanggap (<i>Responsiveness</i>)', 'text' => 'Kesigapan tim operasional dalam menangani kendala yang terjadi selama kegiatan bongkar muat berlangsung.'],
    5  => ['dim' => 'Jaminan dan Kepastian (<i>Assurance</i>)', 'text' => 'Kehati-hatian dan Profesionalisme petugas dalam menangani dan memindahkan barang untuk meminimalkan risiko kerusakan kargo (<i>zero damage</i>).'],
    6  => ['dim' => 'Jaminan dan Kepastian (<i>Assurance</i>)', 'text' => 'Jaminan keamanan kargo, penerapan standar K3 di lapangan, serta kejelasan tanggung jawab/prosedur kerja.'],
    7  => ['dim' => 'Jaminan dan Kepastian (<i>Assurance</i>)', 'text' => 'Keahlian dan kompetensi petugas serta operator lapangan sesuai standar sertifikasi operasional yang berlaku.'],
    8  => ['dim' => 'Jaminan dan Kepastian (<i>Assurance</i>)', 'text' => 'Transparansi dan kesesuaian penagihan (<i>invoicing</i>) dengan tarif serta kesepakatan kerja awal.'],
    9  => ['dim' => 'Empati (<i>Empathy</i>)', 'text' => 'Kemudahan pelanggan dalam menghubungi dan berkoordinasi dengan tim operasional maupun manajemen.'],
    10 => ['dim' => 'Empati (<i>Empathy</i>)', 'text' => 'Pemahaman petugas terhadap kebutuhan spesifik atau instruksi khusus pelanggan terkait karakteristik kargonya.'],
    11 => ['dim' => 'Empati (<i>Empathy</i>)', 'text' => 'Kepedulian dan kesediaan petugas dalam mendengarkan serta mencarikan solusi atas keluhan pelanggan.'],
    12 => ['dim' => 'Empati (<i>Empathy</i>)', 'text' => 'Sikap pelayanan petugas yang ramah, kooperatif, dan adil tanpa membeda-bedakan pelanggan.'],
    13 => ['dim' => 'Kehandalan (<i>Reliability</i>)', 'text' => 'Ketepatan waktu pelaksanaan dan penyelesaian bongkar muat sesuai jadwal/target yang telah disepakati.'],
    14 => ['dim' => 'Kehandalan (<i>Reliability</i>)', 'text' => 'Kecukupan jumlah personil lapangan (TKBM/operator) yang disediakan untuk mendukung kelancaran pekerjaan.'],
    15 => ['dim' => 'Kehandalan (<i>Reliability</i>)', 'text' => 'Akurasi, kelengkapan, dan kejelasan dokumen atau laporan hasil kegiatan bongkar muat (<i>tally sheet</i>, <i>statement of facts</i>, dll.).'],
    16 => ['dim' => 'Kehandalan (<i>Reliability</i>)', 'text' => 'Konsistensi kualitas layanan yang diberikan dari waktu ke waktu sesuai dengan komitmen awal (<i>Service Level Agreement</i>).'],
    17 => ['dim' => 'Bukti Fisik (<i>Tangible</i>)', 'text' => 'Kelengkapan, kesiapan, dan kondisi fasilitas alat angkat serta angkut (<i>crane</i>, <i>forklift</i>, <i>lifting gear</i>, dll.) dalam keadaan laik operasi.'],
    18 => ['dim' => 'Bukti Fisik (<i>Tangible</i>)', 'text' => 'Kerapian seragam serta kelengkapan pemakaian Alat Pelindung Diri (APD) petugas lapangan sesuai standar keselamatan.'],
    19 => ['dim' => 'Bukti Fisik (<i>Tangible</i>)', 'text' => 'Kerapian, kebersihan, dan ketertiban area kerja (dermaga/gudang) selama dan setelah kegiatan bongkar muat berlangsung.'],
    20 => ['dim' => 'Bukti Fisik (<i>Tangible</i>)', 'text' => 'Kenyamanan fasilitas kantor operasional (ruang tamu, ruang rapat, dan area parkir) bagi pelanggan yang berkunjung.']
];

$freqSP = array_fill(0, 20, 0); // Nilai 4
$freqP  = array_fill(0, 20, 0); // Nilai 3
$freqCP = array_fill(0, 20, 0); // Nilai 2
$freqTP = array_fill(0, 20, 0); // Nilai 1
$scorePerQuestion = array_fill(0, 20, 0);
$totalScoreAll = 0;

if ($N > 0) {
    foreach ($rows as $row) {
        for ($i = 0; $i < 20; $i++) {
            $col = $questions[$i];
            $val = (int)($row[$col] ?? 0);
            if ($val === 4) $freqSP[$i]++;
            elseif ($val === 3) $freqP[$i]++;
            elseif ($val === 2) $freqCP[$i]++;
            elseif ($val === 1) $freqTP[$i]++;

            $scorePerQuestion[$i] += $val;
            $totalScoreAll += $val;
        }
    }
}

// ----------------------------------------------------------------------------
// RUMUS PERHITUNGAN SKALA LIKERT PERUSAHAAN (DINAMIS SESUAI JUMLAH N)
// ----------------------------------------------------------------------------
$skorMaksimalY = 4 * 20 * $N;
$skorMinimal   = 1 * 20 * $N;

$indexPercent = $skorMaksimalY > 0 ? ($totalScoreAll / $skorMaksimalY) * 100 : 0;
$ratingAkhir  = getRatingScale($indexPercent);

$isTargetAchieved = $indexPercent >= 50.1;

// Perhitungan 5 Dimensi Layanan
$dimensionMap = [
    'Daya Tanggap (<i>Responsiveness</i>)' => [0, 1, 2, 3],
    'Jaminan & Kepastian (<i>Assurance</i>)' => [4, 5, 6, 7],
    'Empati (<i>Empathy</i>)' => [8, 9, 10, 11],
    'Kehandalan (<i>Reliability</i>)' => [12, 13, 14, 15],
    'Bukti Fisik (<i>Tangible</i>)' => [16, 17, 18, 19]
];

$dimensionScores = [];
foreach ($dimensionMap as $dimName => $indices) {
    $dimScore = 0;
    foreach ($indices as $idx) {
        $dimScore += $scorePerQuestion[$idx];
    }
    $dimY = 4 * 4 * $N;
    $dimPercent = $dimY > 0 ? ($dimScore / $dimY) * 100 : 0;
    $dimensionScores[$dimName] = [
        'score' => $dimScore,
        'y' => $dimY,
        'percent' => $dimPercent,
        'rating' => getRatingScale($dimPercent)
    ];
}

// ----------------------------------------------------------------------------
// FITUR EKSPOR EXCEL (CSV) SESUAI STRUKTUR LAPORAN PERUSAHAAN
// ----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Laporan_Kepuasan_Pelanggan_PT_KAD_' . date('Ymd_His') . '.csv');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    fputcsv($output, ['HASIL ANGKET KUISIONER KEPUASAN PELANGGAN'], ';');
    fputcsv($output, ['PT. KALTIM ADHIGUNA DERMAGA'], ';');
    fputcsv($output, [], ';');
    fputcsv($output, ['Jumlah Responden', ': ' . $N . ' Perusahaan'], ';');
    fputcsv($output, ['Jumlah Kuisioner yang kembali', ': ' . $N . ' Angket'], ';');
    fputcsv($output, ['Jumlah Pertanyaan penilaian', ': 20 Pertanyaan'], ';');
    fputcsv($output, ['Target Minimal Perusahaan', ': Pelanggan Merasa "PUAS" (>= 50,1%)'], ';');
    fputcsv($output, [], ';');

    fputcsv($output, ['A. Jawaban Koresponden (Matriks Kode Jawaban)'], ';');
    $headRow1 = ['No. Responden', 'Nama Responden', 'Nama Perusahaan', 'Responsiveness', '', '', '', 'Assurance', '', '', '', 'Empathy', '', '', '', 'Reliability', '', '', '', 'Tangibles', '', '', '', 'Total Skor'];
    fputcsv($output, $headRow1, ';');

    $headRow2 = ['', '', '', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', ''];
    fputcsv($output, $headRow2, ';');

    $no = 1;
    foreach ($rows as $r) {
        $rowExport = [
            $no++,
            $r['nama_responden'],
            $r['nama_perusahaan']
        ];
        $subtotal = 0;
        for ($i = 0; $i < 20; $i++) {
            $val = (int)($r[$questions[$i]] ?? 0);
            $subtotal += $val;
            $rowExport[] = scoreToCode($val);
        }
        $rowExport[] = $subtotal;
        fputcsv($output, $rowExport, ';');
    }
    fputcsv($output, [], ';');

    fputcsv($output, ['B. Jumlah Jawaban berdasarkan Kriteria Jawaban'], ';');
    fputcsv($output, array_merge(['Jawaban Kuisioner', 'Bobot'], range(1, 20), ['Jumlah']), ';');

    fputcsv($output, array_merge(['Sangat Puas (SP)', '4'], $freqSP, [array_sum($freqSP)]), ';');
    fputcsv($output, array_merge(['Puas (P)', '3'], $freqP, [array_sum($freqP)]), ';');
    fputcsv($output, array_merge(['Cukup Puas (CP)', '2'], $freqCP, [array_sum($freqCP)]), ';');
    fputcsv($output, array_merge(['Tidak Puas (TP)', '1'], $freqTP, [array_sum($freqTP)]), ';');
    fputcsv($output, [], ';');

    fputcsv($output, ['D. Jumlah Jawaban Berdasarkan Indeks Nilai (Skala Likert)'], ';');
    fputcsv($output, array_merge(['Parameter'], range(1, 20), ['Total']), ';');
    
    fputcsv($output, array_merge(['Nilai Skor (TS)'], $scorePerQuestion, [$totalScoreAll]), ';');
    
    $percentPerQ = [];
    $ratingPerQ = [];
    for ($i = 0; $i < 20; $i++) {
        $qPct = ($N > 0) ? ($scorePerQuestion[$i] / (4 * $N)) * 100 : 0;
        $percentPerQ[] = number_format($qPct, 2) . '%';
        $ratingPerQ[] = getRatingScale($qPct)['code'];
    }
    fputcsv($output, array_merge(['Nilai %'], $percentPerQ, [number_format($indexPercent, 2) . '%']), ';');
    fputcsv($output, array_merge(['Rating Skala'], $ratingPerQ, [$ratingAkhir['code']]), ';');
    fputcsv($output, [], ';');

    fputcsv($output, ['Formula Perhitungan Skala Likert:'], ';');
    fputcsv($output, ['Rumus Index %', '= Total Skor (TS) / Y * 100'], ';');
    fputcsv($output, ['Y (Skor Maksimal Ideal)', '= 4 * 20 * ' . $N . ' = ' . $skorMaksimalY], ';');
    fputcsv($output, ['Total Skor (TS)', '= ' . $totalScoreAll], ';');
    fputcsv($output, ['Hasil Index %', '= (' . $totalScoreAll . ' / ' . $skorMaksimalY . ') * 100 = ' . number_format($indexPercent, 2) . '%'], ';');
    fputcsv($output, ['Predikat Kesimpulan', '= ' . $ratingAkhir['label'] . ' (' . $ratingAkhir['code'] . ')'], ';');
    fputcsv($output, [], ';');
    fputcsv($output, ['Kesimpulan Resmi:', 'Sesuai nilai skor dan prosentase diatas didapat hasil rata-rata pelanggan "' . $ratingAkhir['label'] . '" dengan pelayanan jasa operasional stevedoring PT. Kaltim Adhiguna Dermaga.'], ';');
    fputcsv($output, [], ';');
    fputcsv($output, ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Bontang, ' . date('d F Y')], ';');
    fputcsv($output, ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'PT Kaltim Adhiguna Dermaga'], ';');
    fputcsv($output, ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Direktur Adm & Keu'], ';');

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Angket Kuesioner Kepuasan Pelanggan - PT Kaltim Adhiguna Dermaga</title>
    <style>
        :root {
            --primary-navy: #0a2540;
            --primary-blue: #005b96;
            --secondary-blue: #0088cc;
            --light-blue: #f1f7fc;
            --border-color: #cbd5e1;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --white: #ffffff;
            --gold: #f59e0b;
            --green: #10b981;
            --red: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: var(--text-dark);
            line-height: 1.5;
            padding-bottom: 50px;
        }

        /* Navbar Header */
        .navbar {
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-blue));
            color: var(--white);
            padding: 18px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            border-bottom: 4px solid var(--gold);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
        }

        .navbar-brand h1 {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .navbar-brand p {
            font-size: 0.88rem;
            opacity: 0.9;
        }

        .nav-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 15px;
            border-radius: 8px;
            font-size: 0.86rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-gold {
            background-color: var(--gold);
            color: #1e293b;
        }

        .btn-gold:hover {
            background-color: #d97706;
            color: #fff;
        }

        .btn-print {
            background-color: #0284c7;
            color: var(--white);
        }

        .btn-print:hover {
            background-color: #0369a1;
        }

        .btn-danger-outline {
            background-color: rgba(239, 68, 68, 0.15);
            color: #fee2e2;
            border: 1px solid rgba(239, 68, 68, 0.5);
        }

        .btn-danger-outline:hover {
            background-color: var(--red);
            color: #fff;
        }

        .btn-outline-white {
            background-color: rgba(255, 255, 255, 0.15);
            color: var(--white);
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        .btn-outline-white:hover {
            background-color: var(--white);
            color: var(--primary-navy);
        }

        .main-container {
            max-width: 1280px;
            margin: 28px auto;
            padding: 0 20px;
        }

        .alert-banner {
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Kotak Ringkasan Utama (KPI) */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 26px;
        }

        .kpi-card {
            background: var(--white);
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 5px;
            background-color: var(--primary-blue);
        }

        .kpi-card.green::before { background-color: var(--green); }
        .kpi-card.gold::before { background-color: var(--gold); }
        .kpi-card.navy::before { background-color: var(--primary-navy); }

        .kpi-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .kpi-value {
            font-size: 2.1rem;
            font-weight: 800;
            color: var(--primary-navy);
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .kpi-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Badges Rating */
        .badge-sp { background-color: #d1fae5; color: #065f46; font-weight: 800; padding: 4px 10px; border-radius: 6px; }
        .badge-p  { background-color: #dbeafe; color: #1e40af; font-weight: 800; padding: 4px 10px; border-radius: 6px; }
        .badge-cp { background-color: #fef3c7; color: #92400e; font-weight: 800; padding: 4px 10px; border-radius: 6px; }
        .badge-tp { background-color: #fee2e2; color: #991b1b; font-weight: 800; padding: 4px 10px; border-radius: 6px; }

        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
            margin-bottom: 26px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1.5px solid #e2e8f0;
            padding-bottom: 14px;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary-navy);
        }

        .scale-interval-box {
            background-color: var(--light-blue);
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            font-size: 0.88rem;
        }

        .interval-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .interval-item strong {
            color: var(--primary-navy);
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        table.tbl-matrix {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
            text-align: center;
        }

        table.tbl-matrix th, table.tbl-matrix td {
            border: 1px solid #cbd5e1;
            padding: 8px 6px;
            white-space: nowrap;
        }

        table.tbl-matrix th {
            background-color: #e2e8f0;
            color: var(--primary-navy);
            font-weight: 700;
        }

        table.tbl-matrix th.th-dim {
            background-color: #005b96;
            color: #ffffff;
            font-size: 0.86rem;
            padding: 9px 6px;
        }

        table.tbl-matrix tr:hover td {
            background-color: #f1f7fc;
        }

        .code-sp { color: #059669; font-weight: 800; }
        .code-p  { color: #2563eb; font-weight: 800; }
        .code-cp { color: #d97706; font-weight: 800; }
        .code-tp { color: #dc2626; font-weight: 800; }

        .dim-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .dim-card {
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            border-top: 4px solid var(--primary-blue);
        }

        .dim-card h4 {
            font-size: 0.9rem;
            color: var(--primary-navy);
            margin-bottom: 8px;
        }

        .dim-card .dim-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-navy);
            margin-bottom: 4px;
        }

        /* Lembar Kesimpulan & Tanda Tangan */
        .signature-section {
            background-color: #f8fafc;
            border: 1.5px dashed var(--primary-blue);
            border-radius: 10px;
            padding: 26px;
            margin-top: 30px;
        }

        .conclusion-statement {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--primary-navy);
            line-height: 1.6;
            margin-bottom: 24px;
            padding: 16px 20px;
            background-color: #ffffff;
            border-left: 4px solid var(--green);
            border-radius: 6px;
        }

        .signature-box {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .signature-person {
            text-align: center;
            min-width: 250px;
        }

        .signature-person .date {
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .signature-person .title {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 60px;
        }

        .signature-person .name {
            font-weight: 800;
            font-size: 1rem;
            text-decoration: underline;
        }

        .signature-person .role {
            font-size: 0.86rem;
            color: var(--text-muted);
        }

        /* Modal Dialog Konfirmasi Reset */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(10, 37, 64, 0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            max-width: 460px;
            width: 100%;
            padding: 26px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .modal-box h3 {
            color: var(--red);
            font-size: 1.3rem;
            margin-bottom: 10px;
        }

        .modal-box p {
            color: var(--text-dark);
            font-size: 0.92rem;
            margin-bottom: 22px;
            line-height: 1.5;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        /* PRINT STYLES (RAMAH CETAK & PDF) */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding-bottom: 0;
            }

            .navbar, .nav-actions, .alert-banner, .btn, .modal-overlay {
                display: none !important;
            }

            .main-container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .card, .kpi-card {
                box-shadow: none !important;
                border: 1px solid #94a3b8 !important;
                break-inside: avoid;
                margin-bottom: 16px !important;
            }

            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }

            table.tbl-matrix th, table.tbl-matrix td {
                border: 1px solid #000000 !important;
                color: #000000 !important;
                font-size: 0.75rem !important;
                padding: 5px 3px !important;
            }

            table.tbl-matrix th.th-dim {
                background-color: #e2e8f0 !important;
                color: #000000 !important;
            }

            .signature-section {
                border: 1px solid #000000 !important;
                background: none !important;
                break-inside: avoid;
            }

            .conclusion-statement {
                border-left: 4px solid #000 !important;
                background: none !important;
            }
        }

        .print-header {
            display: none;
        }
    </style>
</head>
<body>

    <!-- Header Dokumen Saat Dicetak -->
    <div class="print-header">
        <h2 style="font-size: 1.3rem; margin-bottom: 2px;">PT KALTIM ADHIGUNA DERMAGA</h2>
        <h3 style="font-size: 1.1rem; font-weight: normal; margin-bottom: 4px;">LAPORAN HASIL ANGKET KUESIONER KEPUASAN PELANGGAN (STEVEDORING)</h3>
        <p style="font-size: 0.85rem; color: #555;">Dokumen Resmi Hasil Penilaian Pelayanan Operasional Bongkar Muat</p>
    </div>

    <!-- Header Navbar Layar -->
    <header class="navbar">
        <div class="navbar-brand" style="display: flex; align-items: center; gap: 14px;">
            <img src="assets/img/logo_kad.jpg" alt="Logo PT KAD" style="height: 42px; background: #ffffff; padding: 2px 6px; border-radius: 6px; object-fit: contain;">
            <div>
                <h1>PT KALTIM ADHIGUNA DERMAGA</h1>
                <p>Portal Manajemen Kepuasan Pelanggan (Stevedoring) &bull; Login sebagai: <strong><?= htmlspecialchars($_SESSION['admin_user'] ?? 'Admin') ?></strong> <span style="display: inline-block; background: rgba(255,255,255,0.18); font-size: 0.74rem; padding: 2px 8px; border-radius: 4px; margin-left: 6px; font-weight: 600;">&#128225; <?= htmlspecialchars($dataSource) ?></span></p>
            </div>
        </div>
        <div class="nav-actions">
            <button onclick="window.print()" class="btn btn-print" title="Cetak atau Simpan ke PDF">&#128424; Cetak / Simpan PDF</button>
            <a href="dashboard.php?export=csv" class="btn btn-gold" title="Ekspor data ke file Excel CSV">&#128190; Ekspor Excel (CSV)</a>
            <button onclick="openResetModal()" class="btn btn-danger-outline" title="Kosongkan data kuesioner">&#128465; Reset Data Uji</button>
            <a href="dashboard.php?action=logout" class="btn btn-outline-white" title="Keluar dari portal">&#128274; Keluar</a>
        </div>
    </header>

    <main class="main-container">

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'reset_ok'): ?>
            <div class="alert-banner">
                <span>&#10004;</span> Seluruh data kuesioner berhasil dikosongkan! Dashboard kini siap menerima data survei baru.
            </div>
        <?php endif; ?>

        <!-- Baris Kartu Metrik Perhitungan Dinamis -->
        <section class="kpi-grid">
            <div class="kpi-card navy">
                <div class="kpi-label">Jumlah Responden (N)</div>
                <div class="kpi-value"><?= number_format($N) ?> <span style="font-size: 1.1rem; font-weight: normal; color: var(--text-muted);">Perusahaan</span></div>
                <div class="kpi-sub">Total kuisioner angket yang kembali</div>
            </div>

            <div class="kpi-card gold">
                <div class="kpi-label">Total Skor Likert (TS / Y)</div>
                <div class="kpi-value"><?= number_format($totalScoreAll) ?> <span style="font-size: 1.1rem; font-weight: normal; color: var(--text-muted);">/ <?= number_format($skorMaksimalY) ?></span></div>
                <div class="kpi-sub">Skor Maksimal Ideal Y = 4 &times; 20 &times; <?= $N ?></div>
            </div>

            <div class="kpi-card green">
                <div class="kpi-label">Nilai Index Kepuasan %</div>
                <div class="kpi-value"><?= number_format($indexPercent, 2) ?>%</div>
                <div class="kpi-sub">
                    <span class="<?= $ratingAkhir['badge'] ?>"><?= $ratingAkhir['label'] ?> (<?= $ratingAkhir['code'] ?>)</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Target Minimal Perusahaan</div>
                <div class="kpi-value" style="font-size: 1.35rem; margin-top: 6px; color: <?= $isTargetAchieved ? 'var(--green)' : 'var(--red)' ?>;">
                    <?= $isTargetAchieved ? '&#10004; TERCAPAI' : '&#9888; BELUM TERCAPAI' ?>
                </div>
                <div class="kpi-sub">Standar: Pelanggan Minimal Merasa "PUAS" (&ge; 50,1%)</div>
            </div>
        </section>

        <!-- Penjelasan Formula & Rentang Interval Skala Likert -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">C. Penentuan Skor Jawaban & Interval Skala Likert</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">Rumus Skala Likert: Total Skor (TS) / Y &times; 100</span>
            </div>

            <div class="scale-interval-box">
                <div class="interval-item">
                    <strong>Sangat Puas (SP)</strong>
                    <span>Skor: <strong>4</strong> &bull; Rentang: <strong>75,1% s/d 100%</strong></span>
                </div>
                <div class="interval-item">
                    <strong>Puas (P)</strong>
                    <span>Skor: <strong>3</strong> &bull; Rentang: <strong>50,1% s/d 75,0%</strong></span>
                </div>
                <div class="interval-item">
                    <strong>Cukup Puas (CP)</strong>
                    <span>Skor: <strong>2</strong> &bull; Rentang: <strong>25,1% s/d 50,0%</strong></span>
                </div>
                <div class="interval-item">
                    <strong>Tidak Puas (TP)</strong>
                    <span>Skor: <strong>1</strong> &bull; Rentang: <strong>0% s/d 25,0%</strong></span>
                </div>
            </div>

            <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--primary-navy); margin-bottom: 12px;">Rekapitulasi 5 Dimensi Layanan (Dinamis N = <?= $N ?>):</h3>
            <div class="dim-grid">
                <?php foreach ($dimensionScores as $dimName => $d): ?>
                    <div class="dim-card">
                        <h4><?= $dimName ?></h4>
                        <div class="dim-val"><?= number_format($d['percent'], 1) ?>%</div>
                        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 8px;">Skor: <?= $d['score'] ?> / <?= $d['y'] ?></div>
                        <span class="<?= $d['rating']['badge'] ?>" style="font-size: 0.78rem;"><?= $d['rating']['label'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- B. Jumlah Jawaban Berdasarkan Kriteria Jawaban -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">B. Jumlah Jawaban Berdasarkan Kriteria Jawaban</h2>
                <span style="font-size: 0.84rem; color: var(--text-muted);">Sebaran Frekuensi Jawaban untuk 20 Butir Soal</span>
            </div>

            <div class="table-responsive">
                <table class="tbl-matrix">
                    <thead>
                        <tr>
                            <th rowspan="2" style="text-align: left; min-width: 150px;">Jawaban Kuisioner</th>
                            <th rowspan="2">Bobot</th>
                            <th colspan="4" class="th-dim"><i>Responsiveness</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0088cc;"><i>Assurance</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0a2540;"><i>Empathy</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #005b96;"><i>Reliability</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0088cc;"><i>Tangibles</i></th>
                            <th rowspan="2">Jumlah</th>
                        </tr>
                        <tr>
                            <?php for ($i = 1; $i <= 20; $i++): ?>
                                <th title="<?= htmlspecialchars(strip_tags($questionDetails[$i]['dim'] . ': ' . $questionDetails[$i]['text'])) ?>" style="cursor: help;"><?= $i ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="text-align: left;"><strong>Sangat Puas (SP)</strong></td>
                            <td><strong>4</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): ?>
                                <td class="code-sp"><?= $freqSP[$i] ?></td>
                            <?php endfor; ?>
                            <td class="code-sp" style="font-size: 0.95rem; background: #f8fafc;"><?= array_sum($freqSP) ?></td>
                        </tr>
                        <tr>
                            <td style="text-align: left;"><strong>Puas (P)</strong></td>
                            <td><strong>3</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): ?>
                                <td class="code-p"><?= $freqP[$i] ?></td>
                            <?php endfor; ?>
                            <td class="code-p" style="font-size: 0.95rem; background: #f8fafc;"><?= array_sum($freqP) ?></td>
                        </tr>
                        <tr>
                            <td style="text-align: left;"><strong>Cukup Puas (CP)</strong></td>
                            <td><strong>2</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): ?>
                                <td class="code-cp"><?= $freqCP[$i] ?></td>
                            <?php endfor; ?>
                            <td class="code-cp" style="font-size: 0.95rem; background: #f8fafc;"><?= array_sum($freqCP) ?></td>
                        </tr>
                        <tr>
                            <td style="text-align: left;"><strong>Tidak Puas (TP)</strong></td>
                            <td><strong>1</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): ?>
                                <td class="code-tp"><?= $freqTP[$i] ?></td>
                            <?php endfor; ?>
                            <td class="code-tp" style="font-size: 0.95rem; background: #f8fafc;"><?= array_sum($freqTP) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- D. Jumlah Jawaban Berdasarkan Indeks Nilai -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">D. Jumlah Jawaban Berdasarkan Indeks Nilai Per Butir Soal</h2>
                <span style="font-size: 0.84rem; color: var(--text-muted);">Skor Maksimal per Soal = 4 &times; <?= $N ?> = <?= 4 * $N ?></span>
            </div>

            <div class="table-responsive">
                <table class="tbl-matrix">
                    <thead>
                        <tr>
                            <th rowspan="2" style="text-align: left; min-width: 150px;">Hasil Perhitungan</th>
                            <th colspan="4" class="th-dim"><i>Responsiveness</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0088cc;"><i>Assurance</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0a2540;"><i>Empathy</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #005b96;"><i>Reliability</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0088cc;"><i>Tangibles</i></th>
                            <th rowspan="2">Total / Rata-rata</th>
                        </tr>
                        <tr>
                            <?php for ($i = 1; $i <= 20; $i++): ?>
                                <th title="<?= htmlspecialchars(strip_tags($questionDetails[$i]['dim'] . ': ' . $questionDetails[$i]['text'])) ?>" style="cursor: help;"><?= $i ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="text-align: left;"><strong>Nilai Skor (TS)</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): ?>
                                <td><strong><?= $scorePerQuestion[$i] ?></strong></td>
                            <?php endfor; ?>
                            <td style="font-weight: 800; background: #f8fafc; font-size: 0.95rem; color: var(--primary-blue);"><?= $totalScoreAll ?></td>
                        </tr>
                        <tr>
                            <td style="text-align: left;"><strong>Nilai %</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): 
                                $pct = ($N > 0) ? ($scorePerQuestion[$i] / (4 * $N)) * 100 : 0;
                            ?>
                                <td><?= number_format($pct, 1) ?>%</td>
                            <?php endfor; ?>
                            <td style="font-weight: 800; background: #f8fafc; font-size: 0.95rem;"><?= number_format($indexPercent, 2) ?>%</td>
                        </tr>
                        <tr>
                            <td style="text-align: left;"><strong>Rating Skala</strong></td>
                            <?php for ($i = 0; $i < 20; $i++): 
                                $pct = ($N > 0) ? ($scorePerQuestion[$i] / (4 * $N)) * 100 : 0;
                                $r = getRatingScale($pct);
                            ?>
                                <td><span class="<?= $r['badge'] ?>" style="font-size: 0.76rem; padding: 2px 6px;"><?= $r['code'] ?></span></td>
                            <?php endfor; ?>
                            <td style="background: #f8fafc;"><span class="<?= $ratingAkhir['badge'] ?>"><?= $ratingAkhir['code'] ?></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- A. Jawaban Koresponden (Matriks Lengkap) -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">A. Jawaban Koresponden (Matriks Lengkap Seluruh Responden)</h2>
                <span style="font-size: 0.84rem; color: var(--text-muted);"><?= $N ?> Data Perusahaan Pengisi</span>
            </div>

            <div class="table-responsive">
                <table class="tbl-matrix">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 40px;">No</th>
                            <th rowspan="2" style="text-align: left; min-width: 140px;">Nama Responden</th>
                            <th rowspan="2" style="text-align: left; min-width: 180px;">Nama Perusahaan</th>
                            <th colspan="4" class="th-dim"><i>Responsiveness</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0088cc;"><i>Assurance</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0a2540;"><i>Empathy</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #005b96;"><i>Reliability</i></th>
                            <th colspan="4" class="th-dim" style="background-color: #0088cc;"><i>Tangibles</i></th>
                            <th rowspan="2">Total Skor</th>
                        </tr>
                        <tr>
                            <?php for ($i = 1; $i <= 20; $i++): ?>
                                <th title="<?= htmlspecialchars(strip_tags($questionDetails[$i]['dim'] . ': ' . $questionDetails[$i]['text'])) ?>" style="cursor: help;"><?= $i ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($N > 0): ?>
                            <?php $no = 1; foreach ($rows as $row): ?>
                                <?php $userTotal = 0; ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td style="text-align: left;"><strong><?= htmlspecialchars($row['nama_responden'] ?: '-') ?></strong></td>
                                    <td style="text-align: left;"><?= htmlspecialchars($row['nama_perusahaan'] ?: '-') ?></td>
                                    <?php for ($i = 0; $i < 20; $i++): 
                                        $val = (int)($row[$questions[$i]] ?? 0);
                                        $userTotal += $val;
                                        $code = scoreToCode($val);
                                        $cls = 'code-' . strtolower($code);
                                    ?>
                                        <td class="<?= $cls ?>"><?= $code ?></td>
                                    <?php endfor; ?>
                                    <td style="font-weight: 800; background-color: #f8fafc; color: var(--primary-navy);"><?= $userTotal ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="24" style="padding: 30px; color: var(--text-muted);">
                                    Belum ada data kuesioner yang masuk.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Daftar 20 Butir Instrumen Pertanyaan Resmi -->
        <section class="card" style="margin-top: 24px;">
            <div class="card-header" style="cursor: pointer;" onclick="toggleQuestionsList()">
                <div>
                    <h2 class="card-title">&#128221; Daftar 20 Butir Instrumen Pertanyaan Survei Resmi</h2>
                    <span style="font-size: 0.84rem; color: var(--text-muted);">5 Dimensi Layanan Bongkar Muat (Stevedoring) PT Kaltim Adhiguna Dermaga</span>
                </div>
                <button type="button" class="btn" style="background: #eef6fb; color: var(--primary-blue); font-size: 0.85rem; padding: 6px 14px;" id="toggle-q-btn">Tampilkan / Sembunyikan &darr;</button>
            </div>
            <div id="questions-list-body" style="display: block; padding-top: 10px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
                    <?php 
                    $currentDim = '';
                    foreach ($questionDetails as $num => $q): 
                        if ($currentDim !== $q['dim']):
                            $currentDim = $q['dim'];
                    ?>
                        <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 16px;">
                            <h4 style="color: var(--primary-blue); font-size: 0.92rem; margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;"><?= $currentDim ?></h4>
                            <ul style="list-style: none; padding-left: 0; margin: 0; font-size: 0.86rem; color: var(--text-dark); line-height: 1.45;">
                                <?php 
                                for ($k = 1; $k <= 20; $k++):
                                    if ($questionDetails[$k]['dim'] === $currentDim):
                                ?>
                                    <li style="margin-bottom: 8px;"><strong><?= $k ?>.</strong> <?= $questionDetails[$k]['text'] ?></li>
                                <?php 
                                    endif;
                                endfor; 
                                ?>
                            </ul>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>
        </section>

        <!-- Kesimpulan Resmi & Pengesahan Direksi -->
        <section class="signature-section">
            <div class="conclusion-statement">
                &ldquo;Sesuai nilai skor <strong><?= number_format($totalScoreAll) ?></strong> dari skor maksimal <strong><?= number_format($skorMaksimalY) ?></strong> dan nilai prosentase <strong><?= number_format($indexPercent, 2) ?>%</strong> di atas, didapat hasil rata-rata pelanggan <strong>&ldquo;<?= strtoupper($ratingAkhir['label']) ?>&rdquo;</strong> dengan pelayanan jasa operasional stevedoring PT. Kaltim Adhiguna Dermaga.&rdquo;
            </div>

            <div class="signature-box">
                <div class="signature-person">
                    <div class="date">Bontang, <?= date('d F Y') ?></div>
                    <div class="title">PT Kaltim Adhiguna Dermaga</div>
                    <div class="name">Heru Herjanto</div>
                    <div class="role">Direktur Adm &amp; Keu</div>
                </div>
            </div>
        </section>

    </main>

    <!-- Dialog Konfirmasi Reset Data -->
    <div id="modal-reset" class="modal-overlay">
        <div class="modal-box">
            <h3>&#9888; Konfirmasi Reset Data</h3>
            <p>Apakah Anda yakin ingin <strong>menghapus seluruh data survei</strong> saat ini? Tindakan ini akan mengosongkan seluruh riwayat dan mengatur ulang perhitungan $N = 0$.</p>
            <form method="POST" action="dashboard.php">
                <input type="hidden" name="action_reset" value="confirm">
                <div class="modal-actions">
                    <button type="button" onclick="closeResetModal()" class="btn btn-outline-white" style="color: #1e293b; border-color: #cbd5e1;">Batal</button>
                    <button type="submit" class="btn" style="background-color: var(--red); color: #fff;">Ya, Hapus Semua Data</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openResetModal() {
            document.getElementById('modal-reset').classList.add('active');
        }
        function closeResetModal() {
            document.getElementById('modal-reset').classList.remove('active');
        }
        function toggleQuestionsList() {
            const body = document.getElementById('questions-list-body');
            body.style.display = (body.style.display === 'none') ? 'block' : 'none';
        }
    </script>

</body>
</html>
