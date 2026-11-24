<?php
// ============================================================================
// HELPER INTEGRASI GOOGLE SHEETS API (NATIVE PHP STREAM / cURL)
// PT Kaltim Adhiguna Dermaga
// Mendukung Tab Tahunan Otomatis ("Data Responden YYYY"), Arsip, & Cloud Vercel
// ============================================================================

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Makassar'); // WITA (Bontang, Kalimantan Timur)
} else {
    @date_default_timezone_set('Asia/Makassar');
}

class GoogleSheetsService {
    private $clientId;
    private $clientSecret;
    private $refreshToken;
    private $spreadsheetId;
    private $sheetName;
    private $cachedAccessToken = null;
    private $cachedSheetsMap = null;

    /**
     * Menghasilkan nama tab tahunan otomatis, misal: "Data Responden 2026", "Data Responden 2027", dst.
     */
    public static function getDefaultYearlySheetName($year = null) {
        $targetYear = $year ?: date('Y');
        return 'Data Responden ' . $targetYear;
    }

    public function __construct($customSheetName = null) {
        // Coba load file .env jika ada (misal di localhost / XAMPP)
        $envPaths = [
            __DIR__ . '/.env',
            __DIR__ . '/../.env'
        ];
        foreach ($envPaths as $envPath) {
            if (file_exists($envPath)) {
                $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        if (strpos(trim($line), '#') === 0) continue;
                        $parts = explode('=', $line, 2);
                        if (count($parts) === 2) {
                            $key = trim($parts[0]);
                            $val = trim($parts[1]);
                            if (!getenv($key)) {
                                putenv("$key=$val");
                                $_ENV[$key] = $val;
                            }
                        }
                    }
                }
            }
        }

        // Ambil kredensial dari Environment Variables (Vercel / System / .env lokal)
        $this->clientId      = getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? '');
        $this->clientSecret  = getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
        $this->refreshToken  = getenv('GOOGLE_REFRESH_TOKEN') ?: ($_ENV['GOOGLE_REFRESH_TOKEN'] ?? '');
        $this->spreadsheetId = getenv('GOOGLE_SPREADSHEET_ID') ?: ($_ENV['GOOGLE_SPREADSHEET_ID'] ?? '');

        // Gunakan nama sheet khusus jika dipilih (misal di Dashboard), atau otomatis "Data Responden YYYY"
        $custom = trim((string)($customSheetName ?? ''));
        $this->sheetName = $custom !== '' ? $custom : self::getDefaultYearlySheetName();
    }

    public function getSheetName() {
        return $this->sheetName;
    }

    /**
     * Mengamankan nama sheet yang mengandung spasi/tanda kurung dalam notasi A1 Google Sheets
     */
    private function formatA1Range($sheetName, $cellRange) {
        $escaped = str_replace("'", "''", $sheetName);
        return "'{$escaped}'!{$cellRange}";
    }

    /**
     * HTTP Request Helper yang fleksibel (otomatis gunakan cURL atau file_get_contents)
     */
    private function httpRequest($url, $method = 'GET', $headers = [], $body = null) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['code' => $httpCode, 'body' => $response];
        }

        $headerStr = "";
        foreach ($headers as $h) {
            $headerStr .= $h . "\r\n";
        }

        $options = [
            'http' => [
                'method'        => $method,
                'header'        => $headerStr,
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => 20
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
            $httpCode = isset($match[1]) ? (int)$match[1] : 0;
        }

        return ['code' => $httpCode, 'body' => $response];
    }

    /**
     * Mendapatkan Access Token baru dari Google menggunakan Refresh Token
     */
    private function getAccessToken() {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        $tokenUrl = 'https://oauth2.googleapis.com/token';

        $postData = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token'
        ]);

        $res = $this->httpRequest(
            $tokenUrl,
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            $postData
        );

        $data = json_decode($res['body'], true);

        if ($res['code'] === 200 && isset($data['access_token'])) {
            $this->cachedAccessToken = $data['access_token'];
            return $this->cachedAccessToken;
        }

        $err = $data['error_description'] ?? $data['error'] ?? 'Gagal mendapatkan access token';
        throw new Exception("Google OAuth Error ({$res['code']}): " . $err);
    }

    /**
     * Mengambil peta seluruh tab sheet di Spreadsheet: ['Nama Tab' => sheetId]
     */
    private function getSheetsMap($accessToken, $forceRefresh = false) {
        if ($this->cachedSheetsMap !== null && !$forceRefresh) {
            return $this->cachedSheetsMap;
        }

        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}?fields=sheets.properties(sheetId,title)";
        $res = $this->httpRequest(
            $url,
            'GET',
            ["Authorization: Bearer {$accessToken}"]
        );

        if ($res['code'] !== 200) {
            $data = json_decode($res['body'], true);
            $msg = $data['error']['message'] ?? 'Gagal mengambil daftar tab Google Sheets';
            throw new Exception("Google Sheets Metadata Error ({$res['code']}): " . $msg);
        }

        $data = json_decode($res['body'], true);
        $map = [];
        foreach (($data['sheets'] ?? []) as $sheet) {
            if (isset($sheet['properties']['title'])) {
                $map[$sheet['properties']['title']] = (int)$sheet['properties']['sheetId'];
            }
        }

        $this->cachedSheetsMap = $map;
        return $map;
    }

    /**
     * Mengembalikan daftar nama semua tab yang tersedia di Google Spreadsheet
     */
    public function daftarSemuaSheet() {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken) || empty($this->spreadsheetId)) {
            return [$this->sheetName];
        }

        try {
            $accessToken = $this->getAccessToken();
            $map = $this->getSheetsMap($accessToken);
            return array_keys($map);
        } catch (Throwable $e) {
            error_log("Gagal membaca daftar sheet: " . $e->getMessage());
            return [$this->sheetName];
        }
    }

    /**
     * Memastikan tab tahunan/tujuan sudah ada di Google Spreadsheet.
     * Jika belum ada (misal ganti tahun ke 2027), otomatis dibuatkan tab baru beserta header kolomnya.
     */
    public function pastikanSheetTersedia($sheetName = null) {
        $target = $sheetName ?: $this->sheetName;
        $accessToken = $this->getAccessToken();
        return $this->ensureSheetExists($accessToken, $target);
    }

    private function ensureSheetExists($accessToken, $sheetName) {
        $map = $this->getSheetsMap($accessToken);
        if (isset($map[$sheetName])) {
            return $map[$sheetName];
        }

        // Buat tab baru via batchUpdate (addSheet)
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate";
        $payload = json_encode([
            'requests' => [
                [
                    'addSheet' => [
                        'properties' => [
                            'title' => $sheetName
                        ]
                    ]
                ]
            ]
        ]);

        $res = $this->httpRequest(
            $url,
            'POST',
            [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: application/json"
            ],
            $payload
        );

        $data = json_decode($res['body'], true);
        if ($res['code'] !== 200) {
            $msg = $data['error']['message'] ?? "Gagal membuat tab baru '{$sheetName}'";
            throw new Exception("Google Sheets API Error ({$res['code']}): " . $msg);
        }

        $newId = (int)($data['replies'][0]['addSheet']['properties']['sheetId'] ?? 0);
        $this->cachedSheetsMap[$sheetName] = $newId;

        // Pastikan baris header otomatis tertulis di tab baru
        $prevSheet = $this->sheetName;
        $this->sheetName = $sheetName;
        $this->ensureHeaderExists($accessToken);
        $this->sheetName = $prevSheet;

        return $newId;
    }

    /**
     * Memastikan header kolom ada jika tab spreadsheet masih kosong
     */
    private function ensureHeaderExists($accessToken) {
        $a1 = $this->formatA1Range($this->sheetName, 'A1:AB1');
        $range = urlencode($a1);
        $checkUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}";

        $res = $this->httpRequest(
            $checkUrl,
            'GET',
            ["Authorization: Bearer {$accessToken}"]
        );

        $data = json_decode($res['body'], true);
        if (empty($data['values'])) {
            $headers = [
                'Timestamp', 'Nama Responden', 'Nama Perusahaan', 'Alamat Perusahaan',
                'Q1 (Respons Komunikasi)', 'Q2 (Administrasi & Pemesanan)', 'Q3 (Proaktif Update Operasional)', 'Q4 (Kesigapan Kendala Operasional)',
                'Q5 (Zero Damage & Profesionalisme)', 'Q6 (Keamanan Kargo & K3)', 'Q7 (Kompetensi & Sertifikasi Operator)', 'Q8 (Transparansi Tarif & Invoicing)',
                'Q9 (Kemudahan Koordinasi Tim/Manajemen)', 'Q10 (Pemahaman Kebutuhan Kargo)', 'Q11 (Kepedulian Solusi Keluhan)', 'Q12 (Pelayanan Ramah, Kooperatif, Adil)',
                'Q13 (Ketepatan Jadwal Target)', 'Q14 (Kecukupan Personil TKBM/Operator)', 'Q15 (Akurasi Laporan Tally Sheet/SOF)', 'Q16 (Konsistensi Kualitas Layanan SLA)',
                'Q17 (Kondisi Laik Alat Angkat & Angkut)', 'Q18 (Kerapian Seragam & APD)', 'Q19 (Kebersihan Area Kerja Dermaga/Gudang)', 'Q20 (Kenyamanan Fasilitas Kantor)',
                'Total Skor (TS)', 'Nilai %', 'Rating Skala', 'Saran dan Masukan'
            ];
            $this->appendRowData($accessToken, $headers);
        }
    }

    /**
     * Mengirimkan satu baris data ke Google Spreadsheet
     */
    private function appendRowData($accessToken, $rowValues) {
        $a1 = $this->formatA1Range($this->sheetName, 'A1');
        $range = urlencode($a1);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS";

        $payload = json_encode([
            'range'          => $a1,
            'majorDimension' => 'ROWS',
            'values'         => [$rowValues]
        ]);

        $res = $this->httpRequest(
            $url,
            'POST',
            [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: application/json"
            ],
            $payload
        );

        $result = json_decode($res['body'], true);
        if ($res['code'] !== 200) {
            $msg = $result['error']['message'] ?? 'Gagal menambahkan baris ke Google Sheets';
            throw new Exception("Google Sheets API Error ({$res['code']}): " . $msg);
        }

        return true;
    }

    /**
     * Fungsi publik untuk mengirim data survei langsung ke tab tahun berjalan ("Data Responden YYYY")
     */
    public function kirimDataSurvei($surveyData) {
        if (empty($this->clientSecret) || $this->clientSecret === 'MASUKKAN_CLIENT_SECRET_ANDA_DISINI') {
            throw new Exception("GOOGLE_CLIENT_SECRET belum diisi di file .env");
        }

        $accessToken = $this->getAccessToken();
        $this->ensureSheetExists($accessToken, $this->sheetName);
        $this->ensureHeaderExists($accessToken);

        $totalSkor = 0;
        for ($i = 1; $i <= 20; $i++) {
            $totalSkor += (int)($surveyData["q{$i}"] ?? 0);
        }
        $persen = ($totalSkor / 80) * 100;

        if ($persen >= 75.1) $rating = "Sangat Puas (SP)";
        elseif ($persen >= 50.1) $rating = "Puas (P)";
        elseif ($persen >= 25.1) $rating = "Cukup Puas (CP)";
        else $rating = "Tidak Puas (TP)";

        $row = [
            date('Y-m-d H:i:s'),
            $surveyData['nama_responden'] ?? '-',
            $surveyData['nama_perusahaan'] ?? '-',
            $surveyData['alamat_perusahaan'] ?? '-',
            $surveyData['q1'], $surveyData['q2'], $surveyData['q3'], $surveyData['q4'],
            $surveyData['q5'], $surveyData['q6'], $surveyData['q7'], $surveyData['q8'],
            $surveyData['q9'], $surveyData['q10'], $surveyData['q11'], $surveyData['q12'],
            $surveyData['q13'], $surveyData['q14'], $surveyData['q15'], $surveyData['q16'],
            $surveyData['q17'], $surveyData['q18'], $surveyData['q19'], $surveyData['q20'],
            $totalSkor,
            number_format($persen, 2) . '%',
            $rating,
            $surveyData['saran_masukan'] ?? '-'
        ];

        return $this->appendRowData($accessToken, $row);
    }

    /**
     * Membaca semua baris data survei dari tab yang aktif (Mulai baris 2 hingga akhir)
     */
    public function ambilSemuaDataSurvei() {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken) || empty($this->spreadsheetId)) {
            return [];
        }

        try {
            $accessToken = $this->getAccessToken();
            $a1 = $this->formatA1Range($this->sheetName, 'A2:AB');
            $range = urlencode($a1);
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}";

            $res = $this->httpRequest(
                $url,
                'GET',
                ["Authorization: Bearer {$accessToken}"]
            );

            if ($res['code'] !== 200) {
                return [];
            }

            $data = json_decode($res['body'], true);
            $values = $data['values'] ?? [];

            $rows = [];
            foreach ($values as $index => $row) {
                if (empty($row) || (!isset($row[1]) && !isset($row[2]))) continue;
                $rows[] = [
                    'id'                => $index + 1,
                    'created_at'        => $row[0] ?? date('Y-m-d H:i:s'),
                    'nama_responden'    => $row[1] ?? '',
                    'nama_perusahaan'   => $row[2] ?? '',
                    'alamat_perusahaan' => $row[3] ?? '',
                    'resp_1'            => (int)($row[4] ?? 0),
                    'resp_2'            => (int)($row[5] ?? 0),
                    'resp_3'            => (int)($row[6] ?? 0),
                    'resp_4'            => (int)($row[7] ?? 0),
                    'assu_1'            => (int)($row[8] ?? 0),
                    'assu_2'            => (int)($row[9] ?? 0),
                    'assu_3'            => (int)($row[10] ?? 0),
                    'assu_4'            => (int)($row[11] ?? 0),
                    'empa_1'            => (int)($row[12] ?? 0),
                    'empa_2'            => (int)($row[13] ?? 0),
                    'empa_3'            => (int)($row[14] ?? 0),
                    'empa_4'            => (int)($row[15] ?? 0),
                    'reli_1'            => (int)($row[16] ?? 0),
                    'reli_2'            => (int)($row[17] ?? 0),
                    'reli_3'            => (int)($row[18] ?? 0),
                    'reli_4'            => (int)($row[19] ?? 0),
                    'tang_1'            => (int)($row[20] ?? 0),
                    'tang_2'            => (int)($row[21] ?? 0),
                    'tang_3'            => (int)($row[22] ?? 0),
                    'tang_4'            => (int)($row[23] ?? 0),
                    'saran_masukan'     => $row[27] ?? ($row[24] ?? '')
                ];
            }
            return $rows;
        } catch (Throwable $e) {
            error_log("Gagal membaca Google Sheets: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mengarsipkan tab aktif ke tab salinan baru (misal: "Arsip Data Responden 2026 (24-11-2026 15.05)")
     * lalu mengosongkan baris A2:AB pada tab aktif (Header baris 1 tetap dipertahankan).
     */
    public function arsipkanDanResetData() {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken) || empty($this->spreadsheetId)) {
            throw new Exception("Kredensial Google Sheets API belum dikonfigurasi.");
        }

        $accessToken = $this->getAccessToken();
        $map = $this->getSheetsMap($accessToken, true);

        if (!isset($map[$this->sheetName])) {
            throw new Exception("Tab '{$this->sheetName}' tidak ditemukan di Google Spreadsheet.");
        }

        // Cek apakah ada data untuk diarsipkan
        $existingRows = $this->ambilSemuaDataSurvei();
        if (empty($existingRows)) {
            return ['archived' => false, 'archiveName' => null];
        }

        $sourceSheetId = $map[$this->sheetName];
        $baseArchiveName = 'Arsip ' . $this->sheetName . ' (' . date('d-m-Y H.i') . ')';
        $archiveName = $baseArchiveName;
        if (isset($map[$archiveName])) {
            $archiveName = 'Arsip ' . $this->sheetName . ' (' . date('d-m-Y H.i.s') . ')';
        }

        // 1. Duplikasi seluruh sheet sebagai tab arsip
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate";
        $payload = json_encode([
            'requests' => [
                [
                    'duplicateSheet' => [
                        'sourceSheetId'    => $sourceSheetId,
                        'insertSheetIndex' => count($map),
                        'newSheetName'     => $archiveName
                    ]
                ]
            ]
        ]);

        $res = $this->httpRequest(
            $url,
            'POST',
            [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: application/json"
            ],
            $payload
        );

        if ($res['code'] !== 200) {
            $result = json_decode($res['body'], true);
            $msg = $result['error']['message'] ?? 'Gagal membuat tab arsip di Google Sheets';
            throw new Exception("Google Sheets Archive Error ({$res['code']}): " . $msg);
        }

        // 2. Setelah arsip dipastikan sukses tersimpan, kosongkan data tab aktif (baris 2 ke bawah)
        $this->hapusSemuaDataSurvei();

        return ['archived' => true, 'archiveName' => $archiveName];
    }

    /**
     * Menghapus seluruh baris data responden pada tab aktif di Google Spreadsheet (Mulai baris 2 hingga akhir)
     * Baris 1 (Header kolom) tetap dipertahankan agar struktur tabel tidak rusak.
     */
    public function hapusSemuaDataSurvei() {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken) || empty($this->spreadsheetId)) {
            throw new Exception("Kredensial Google Sheets API belum dikonfigurasi.");
        }

        $accessToken = $this->getAccessToken();
        $a1 = $this->formatA1Range($this->sheetName, 'A2:AB');
        $range = urlencode($a1);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}:clear";

        $res = $this->httpRequest(
            $url,
            'POST',
            [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: application/json"
            ],
            '{}'
        );

        if ($res['code'] !== 200) {
            $result = json_decode($res['body'], true);
            $msg = $result['error']['message'] ?? 'Gagal mengosongkan data di Google Sheets';
            throw new Exception("Google Sheets API Error ({$res['code']}): " . $msg);
        }

        return true;
    }
}
