# Pasang Tanggungan Siswa di VM baru

Satu aplikasi = satu VM, sama seperti Jurnal, BK, TU, dan PKL. Bendahara, kepala sekolah, dan wali kelas masuk lewat
**Pusat Sekolah**; admin lokal hanya untuk keadaan darurat saat Pusat mati.

| | Nilai |
|---|---|
| VM / IP | tanggungan / `192.168.10.26` |
| Folder | `/var/www/tanggungan` |
| Database / user | `tanggungan` / `tanggungan` (password acak, dibuat skrip) |
| Domain | `tanggungan.smkibupakusari.sch.id` |

## 1. VM baru

Ubuntu Server 24.04 / 26.04 (atau Debian 13), IP tetap `192.168.10.26/24`, gateway `192.168.10.254`.

```bash
sudo mkdir -p /var/www/tanggungan && sudo chown $USER:$USER /var/www/tanggungan
git config --global credential.helper store      # repo privat: Username = akun GitHub, Password = token
git clone https://github.com/andieawan/tanggungan-pusat.git /var/www/tanggungan
cd /var/www/tanggungan
sudo DOMAIN=tanggungan.smkibupakusari.sch.id APP_URL=https://tanggungan.smkibupakusari.sch.id PROXY_IP=192.168.10.10 bash deploy/install.sh
```

Skrip memasang Nginx, PHP, MariaDB, cron, membuat database dengan password acak, menulis `.env`, dan menjadwalkan
backup harian 01:45. Aman diulang.

Di **NPMplus**: Proxy Host `tanggungan.smkibupakusari.sch.id` → `192.168.10.26`, port 80, SSL Let's Encrypt, **Force SSL**.

## 2. Daftarkan di Pusat (VM Pusat)

```bash
cd /var/www/pusat-sekolah && sudo bash deploy/update.sh
sudo -u www-data php artisan pusat:aplikasi-baru tanggungan --base-url=https://tanggungan.smkibupakusari.sch.id
```

Templat "tanggungan" memberi data **dasar** saja (kelas, siswa tanpa data pribadi, guru & staf, akun) dan peran
`admin`, `bendahara`, `kepsek`, `wali_kelas`. Salin `client_id`, `client_secret`, `webhook_secret` (tampil sekali) ke `.env`:

```ini
PUSAT_ENABLED=true
PUSAT_URL=https://pusat.smkibupakusari.sch.id
PUSAT_CLIENT_ID=...
PUSAT_CLIENT_SECRET=...
PUSAT_WEBHOOK_SECRET=...
TRUSTED_PROXIES=192.168.10.10
```

## 3. Aktifkan

```bash
cd /var/www/tanggungan
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan tanggungan:periksa
sudo -u www-data php artisan tanggungan:sinkron      # salin kelas, siswa, guru dari Pusat
```

## 4. Akun & peran (Pusat → Akun, aplikasi Tanggungan Siswa)

- **bendahara**: mencatat pembayaran, mencetak kuitansi, keringanan, tarif, impor data lama.
- **kepsek**: melihat semua siswa, rekap, dan kuitansi (tanpa mengubah).
- **wali_kelas**: melihat siswa kelas perwaliannya (kelas di Pusat harus tercatat dengan wali tersebut, dan akunnya
  tertaut ke data pegawainya).
- **admin**: semua, termasuk Integrasi Pusat.

## 5. Mulai tahun ajaran

1. **Pengaturan → Tarif**: isi daftar ulang (setahun) dan iuran syariah (per bulan) untuk kelas X, XI, XII. Centang bulan
   tanpa iuran (bawaan: Juli & Agustus kelas X, karena termasuk daftar ulang). Simpan.
2. **Buat tagihan untuk semua siswa aktif.** Aman diulang: siswa baru ikut dibuatkan, yang sudah ada tidak berubah.
3. Bendahara: **Siswa** → cari nama → **Terima pembayaran**. Isi jumlah per tagihan (tombol *Lunas* per baris, atau
   *Bagi* untuk membagi uang yang diterima ke tagihan tertua). Simpan → kuitansi bernomor siap dicetak.
4. Salah catat: buka kuitansi → **Batalkan** dengan alasan (kuitansi tetap tercatat), lalu catat ulang.
5. Keringanan / beasiswa / bebas iuran: di kartu siswa → *Keringanan*, dengan alasan.

## 6. Data lama (sebelum aplikasi dipakai)

**Pengaturan → Impor data lama** → pilih kelas → **Unduh templat**. Templat berisi siswa aktif kelas itu, satu baris per
tahun ajaran sejak kelas X, dengan lembar PETUNJUK, DAFTAR ULANG, dan SYARIAH. Isi, lalu **Periksa saja** (tidak
menyimpan) → bila sudah benar, **Impor**. Pembayaran lama dicatat sebagai kuitansi `IMP-…` dan tidak masuk kas harian.
Tagihan yang sudah punya pembayaran di aplikasi tidak ditimpa.

## Pembaruan & cadangan

```bash
cd /var/www/tanggungan && sudo bash deploy/update.sh
```

Backup harian 01:45 ke `/var/backups/tanggungan/`. Uji pulih: [`UJI-PULIH.md`](UJI-PULIH.md).
