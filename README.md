# Sistem Kuesioner Kepuasan Pelanggan (Stevedoring) - PT Kaltim Adhiguna Dermaga

Aplikasi web survei kepuasan pelanggan operasional bongkar muat (*stevedoring*) PT Kaltim Adhiguna Dermaga (PT KAD) dengan visual standar Pelindo Maritime, perhitungan Skala Likert 4 Kategori (SP, P, CP, TP), dan integrasi otomatis ke **Google Sheets API**.

---

## 🌟 Fitur Utama
1. **Formulir Kuesioner 2 Langkah (Step-by-Step)**:
   - Langkah 1: Pengisian Identitas Responden (Nama, Perusahaan, Alamat).
   - Langkah 2: 20 Butir Pertanyaan Evaluasi yang mencakup 5 Dimensi Mutu Pelayanan (*Responsiveness*, *Assurance*, *Empathy*, *Reliability*, *Tangible*).
2. **Sinkronisasi Otomatis Cloud (Google Sheets API)**:
   - Data jawaban langsung terkirim secara *real-time* ke Google Spreadsheet resmi PT KAD tanpa dependensi database lokal.
3. **Portal Admin & Analisis Likert Interaktif**:
   - Menghitung Nilai Frekuensi Jawaban, Total Skor (TS), Skor Maksimum (Y), dan Persentase Kepuasan Pelanggan.
   - Grafik batang & radar 5 dimensi mutu layanan.
   - Tabel rincian jawaban per responden.
   - Ekspor laporan berformat Excel (.csv) resmi.
   - Fitur Cetak / Simpan PDF yang otomatis menyertakan lembar pengesahan Direktur PT Kaltim Adhiguna Dermaga.
4. **Siap Deploy ke Vercel (Serverless)**:
   - Dikonfigurasi penuh dengan `vercel-php@0.7.4`.
   - Menggunakan signed cookie authentication agar login admin tetap stabil di lingkungan serverless.
   - Fallback otomatis ke Google Sheets jika database MySQL tidak terhubung.

---

## 🚀 Panduan Deploy ke Vercel

### Langkah 1: Push Project ke GitHub
Buka terminal / Command Prompt di folder proyek ini:
```bash
git push -u origin main
```

### Langkah 2: Hubungkan ke Vercel
1. Kunjungi [vercel.com](https://vercel.com) dan login dengan akun GitHub Anda.
2. Klik tombol **"Add New..."** lalu pilih **"Project"**.
3. Cari repository `kad-kuisioner` Anda, lalu klik **"Import"**.

### Langkah 3: Tambahkan Environment Variables di Vercel
Pada bagian **Environment Variables** sebelum klik Deploy, masukkan variabel lingkungan yang dibutuhkan:
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REFRESH_TOKEN`
- `GOOGLE_SPREADSHEET_ID`
- `GOOGLE_SHEET_NAME` (Sheet1)
- `ADMIN_USER` (admin)
- `ADMIN_PASS` (kad12345)
- `ADMIN_SECRET` (pt_kad_stevedoring_secret_2026)

### Langkah 4: Klik Deploy!
Tunggu sekitar 1–2 menit hingga Vercel selesai melakukan build. Website survei Anda langsung online dengan domain resmi `https://nama-proyek-anda.vercel.app`!
