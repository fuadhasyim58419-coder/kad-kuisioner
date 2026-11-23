<?php
// ============================================================================
// SCRIPT PENGUJIAN KONEKSI GOOGLE SPREADSHEET
// PT Kaltim Adhiguna Dermaga
// ============================================================================

require_once __DIR__ . '/google_sheets.php';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uji Coba Koneksi Google Sheets - PT KAD</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f1f5f9;
            padding: 40px 20px;
            color: #1e293b;
        }
        .container {
            max-width: 650px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        h2 { margin-bottom: 8px; color: #0a2540; }
        p { color: #64748b; font-size: 0.95rem; margin-bottom: 24px; }
        .box {
            padding: 16px 20px;
            border-radius: 8px;
            font-size: 0.92rem;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .box-success {
            background: #ecfdf5;
            border: 1.5px solid #10b981;
            color: #065f46;
        }
        .box-error {
            background: #fef2f2;
            border: 1.5px solid #ef4444;
            color: #991b1b;
        }
        .btn {
            display: inline-block;
            background: #005b96;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
        }
        .btn:hover { background: #0a2540; }
        code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 0.88rem; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Uji Coba Koneksi Google Spreadsheet</h2>
        <p>Halaman ini akan mengirim 1 baris data percobaan langsung ke Google Spreadsheet Anda.</p>

        <?php
        try {
            $sheets = new GoogleSheetsService();
            
            // Mengirim 1 data uji coba
            $sheets->kirimDataSurvei([
                'nama_responden'    => 'Pengujian Sistem (Test)',
                'nama_perusahaan'   => 'PT Kaltim Adhiguna Dermaga (Internal)',
                'alamat_perusahaan' => 'Bontang, Kalimantan Timur',
                'q1'  => 4, 'q2'  => 4, 'q3'  => 4, 'q4'  => 4,
                'q5'  => 4, 'q6'  => 4, 'q7'  => 4, 'q8'  => 4,
                'q9'  => 4, 'q10' => 4, 'q11' => 4, 'q12' => 4,
                'q13' => 4, 'q14' => 4, 'q15' => 4, 'q16' => 4,
                'q17' => 4, 'q18' => 4, 'q19' => 4, 'q20' => 4,
                'saran_masukan'     => 'Koneksi Google Spreadsheet API berhasil terhubung dengan sempurna!'
            ]);

            echo '<div class="box box-success">
                <h3 style="margin-bottom: 6px;">&#10004; BERHASIL TERHUBUNG!</h3>
                <p style="margin-bottom: 0; color: #065f46;">Satu baris data uji coba telah berhasil dikirim ke Google Spreadsheet Anda. Silakan buka file spreadsheet Anda untuk melihat baris data baru yang masuk!</p>
            </div>';

        } catch (Exception $e) {
            echo '<div class="box box-error">
                <h3 style="margin-bottom: 6px;">&#9888; GAGAL TERHUBUNG</h3>
                <p style="margin-bottom: 12px; color: #991b1b;">' . htmlspecialchars($e->getMessage()) . '</p>
                <div style="font-size: 0.88rem;">
                    <strong>Petunjuk:</strong> Pastikan Anda telah mengganti <code>MASUKKAN_CLIENT_SECRET_ANDA_DISINI</code> di file <code>.env</code> dengan Client Secret asli Anda.
                </div>
            </div>';
        }
        ?>

        <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="test_sheets.php" class="btn" style="background: #0284c7;">&#128260; Coba Uji Lagi</a>
            <a href="https://docs.google.com/spreadsheets/d/135of_Rmc1xkDKzswc8f6gAeyckDpr1AhhTliBcLvSCc/edit" target="_blank" class="btn" style="background: #059669;">Buka Google Spreadsheet &rarr;</a>
            <a href="index.html" class="btn" style="background: #0a2540;">Buka Form Kuesioner</a>
        </div>
    </div>
</body>
</html>
