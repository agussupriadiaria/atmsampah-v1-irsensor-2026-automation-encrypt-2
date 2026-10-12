# 📘 Dokumentasi Backup Raspberry Pi dengan `shrink-backup`

## 📎 Konteks Dokumen

Dokumentasi ini dibuat berdasarkan percakapan sebelumnya (di DeepSeek) mengenai backup penuh Raspberry Pi untuk proyek `atmsampah-v1-irsensor-2026-automation`. Isinya adalah panduan lengkap mulai dari instalasi tool, format flashdisk, backup pertama, update backup, unmount, hingga restore ke media baru — sehingga bisa dipakai ulang untuk project Raspberry Pi lainnya.

Dokumen ini berisi panduan lengkap untuk melakukan backup penuh (full backup) Raspberry Pi menggunakan tool `shrink-backup`, mulai dari persiapan, proses backup, update backup, hingga tips pemulihan (restore).

---

## 📌 Konteks & Tujuan

- **Tujuan**: Membuat backup penuh yang mencakup **OS, aplikasi, konfigurasi, dan data** pada Raspberry Pi.
- **Hasil**: Sebuah file image (`.img`) yang bisa di-restore persis seperti kondisi saat backup dilakukan.
- **Tool**: [`shrink-backup`](https://github.com/UnconnectedBedna/shrink-backup) — skrip open-source yang efisien, ringan, dan mendukung incremental update.
- **Media Backup**: Flashdisk USB (disarankan minimal 16 GB, diformat **ext4**).
- **Target Sistem**: Raspberry Pi OS (Debian-based).

---

## 🎯 Kenapa `shrink-backup`?

| Fitur | Keterangan |
| :--- | :--- |
| **Ringan** | Menggunakan `rsync`, hanya menyalin data terpakai (bukan seluruh kapasitas disk). |
| **Cepat** | Backup pertama ~1 menit untuk data ±11 GB; update bisa hanya hitungan detik. |
| **Aman** | Mendukung autoexpand filesystem saat restore ke media yang lebih besar. |
| **Fleksibel** | Bisa exclude folder tertentu, incremental update, dan auto-calculate ukuran image. |

---

## ⚠️ Aturan Penting Sebelum Mulai

1. **Format Flashdisk wajib `ext4`**. Jangan gunakan NTFS atau FAT32:
   - **NTFS** → menyebabkan proses macet (stuck) karena masalah izin file Linux.
   - **FAT32** → tidak bisa menyimpan file di atas 4 GB.
2. **Pastikan kapasitas flashdisk cukup**: minimal sama dengan total data terpakai di sistem.
3. **Jangan cabut flashdisk atau matikan Raspberry Pi** selama proses backup berjalan.
4. **Selalu unmount flashdisk** sebelum dicabut.
5. **Gunakan `screen`** untuk backup via SSH, agar proses tidak mati saat koneksi terputus.

---

## 🛠️ Langkah 1: Instalasi `shrink-backup`

```bash
# Unduh skrip versi terbaru
wget https://github.com/UnconnectedBedna/shrink-backup/releases/download/v1.4/shrink-backup.v1.4.tar.gz

# Ekstrak
tar -xzf shrink-backup.v1.4.tar.gz

# Masuk direktori dan beri izin eksekusi
cd shrink-backup.v1.4
chmod +x shrink-backup

# (Opsional) Pindahkan ke PATH agar bisa dipanggil dari mana saja
sudo mv shrink-backup /usr/local/bin/
```

---

## 💾 Langkah 2: Format Flashdisk ke ext4

⚠️ **HATI-HATI**: Pastikan yang diformat adalah flashdisk (`/dev/sda1`), BUKAN kartu SD utama (`/dev/mmcblk0`).

```bash
# 1. Cek nama device flashdisk
lsblk
# Contoh output: sda1 (14.9G) — inilah flashdisk Anda

# 2. Lepas mount (jika sedang ter-mount)
sudo umount /dev/sda1

# 3. Format ke ext4
sudo mkfs.ext4 /dev/sda1
# Ketik 'y' jika diminta konfirmasi

# 4. Cabut dan colok ulang flashdisk
# 5. Cek ulang path mount-nya
lsblk
```

Setelah diformat, flashdisk akan otomatis ter-mount di path seperti:

```
/media/<user>/<UUID-flashdisk>
```

---

## 🚀 Langkah 3: Backup Pertama

💡 Untuk koneksi SSH: gunakan `screen` agar backup tidak mati saat koneksi terputus.
Untuk monitor lokal: bisa langsung jalankan tanpa `screen`.

### Opsi A: Menggunakan `screen` (Direkomendasikan via SSH)

```bash
# Instal screen (jika belum ada)
sudo apt update && sudo apt install screen -y

# Buat sesi screen baru
screen -S backup_rpi

# Jalankan backup di dalam screen
sudo shrink-backup -a /media/<user>/<UUID-flashdisk>/backup_rpi.img

# Setelah proses mulai berjalan, detach dengan:
# Tekan Ctrl + A, lalu lepas, lalu tekan D

# Untuk kembali melihat progress:
screen -r backup_rpi
```

### Opsi B: Tanpa `screen` (Monitor Lokal)

```bash
sudo shrink-backup -a /media/<user>/<UUID-flashdisk>/backup_rpi.img
```

**Penjelasan opsi:**
- `-a` → Auto-calculate ukuran image (aman, menghitung sendiri ukuran yang dibutuhkan).
- `/media/<user>/<UUID-flashdisk>/backup_rpi.img` → Lokasi dan nama file backup.

**Selama proses berjalan:**
- Akan muncul banyak teks berjalan (nama file yang sedang disalin). Normal.
- Estimasi waktu: ±1 menit untuk data 11 GB (tergantung kecepatan flashdisk).
- JANGAN cabut flashdisk, tutup terminal, atau tekan Ctrl+C.

**Tanda proses selesai:**
- Prompt terminal muncul kembali.
- Output menampilkan: `## Backup done.` dan `## Done.`

---

## 🔄 Langkah 4: Update Backup (Setelah Ada Perubahan)

Jika ada perubahan (aplikasi baru, konfigurasi, update sistem), cukup update backup-nya, tidak perlu ulang dari nol:

```bash
sudo shrink-backup -U /media/<user>/<UUID-flashdisk>/backup_rpi.img
```

- `-U` (huruf U besar) = Update, hanya menyalin file yang berubah.
- Proses jauh lebih cepat (hitungan detik hingga menit).
- ⚠️ Jangan tertukar antara `-a` (backup baru) dan `-U` (update).

---

## 🧹 Langkah 5: Unmount Sebelum Cabut

JANGAN langsung cabut flashdisk! Selalu unmount terlebih dahulu:

```bash
sudo umount /media/<user>/<UUID-flashdisk>
```

Verifikasi dengan:

```bash
lsblk
```

Jika kolom `MOUNTPOINTS` untuk sda1 sudah kosong, flashdisk aman dicabut.

**Jika muncul error `target is busy`:**

```bash
# Cari proses yang menahan
sudo lsof /media/<user>/<UUID-flashdisk>

# Matikan paksa jika perlu
sudo kill -9 <PID>

# Atau gunakan lazy unmount
sudo umount -l /media/<user>/<UUID-flashdisk>
```

---

## 🖥️ Catatan: Mencolok Flashdisk ke Windows

Flashdisk berformat ext4 TIDAK akan terbaca langsung di Windows:

- Windows akan menampilkan pop-up: *"You need to format the disk before you can use it."*
- **JANGAN KLIK "FORMAT DISK"** — itu akan menghapus seluruh isi backup!

**Jika ingin membaca isinya di Windows**, instal software pembaca ext4:
- DiskInternals Linux Reader (read-only, gratis)
- Ext2Fsd (open source, read/write)

---

## 🚑 Langkah 6: Restore Backup ke Media Baru

1. Siapkan SSD/kartu SD baru dan hubungkan ke komputer.
2. Buka aplikasi Raspberry Pi Imager atau balenaEtcher.
3. Pilih opsi "Use Custom" atau "Flash from file".
4. Arahkan ke file `backup_rpi.img` di flashdisk.
5. Pilih target (SSD/kartu SD baru), lalu klik Flash.
6. Setelah selesai, masukkan media baru ke Raspberry Pi dan nyalakan.
7. Sistem akan boot dan otomatis memperluas partisi (autoexpand) untuk mengisi seluruh kapasitas media baru.
8. Semua aplikasi, konfigurasi, dan data kembali seperti saat backup terakhir.

---

## 📊 Contoh Ringkasan Backup Berhasil

```
## Backup done.
# Backup location: /media/<user>/.../backup_rpi.img
# Estimated root usage: 11149MiB
# Image size: 12428MiB with a root partition of 11908MiB
# Autoexpand filesystem at boot: true
# Elapsed time: 52.12 s
```

- **Root usage**: Data aktual yang terpakai.
- **Image size**: Ukuran file .img yang dihasilkan.
- **Autoexpand**: Aktif → partisi otomatis diperluas saat restore.
- **Elapsed time**: Durasi proses backup.

---

## 📝 Ringkasan Perintah Penting

| Tujuan | Perintah |
| :--- | :--- |
| Cek device | `lsblk` |
| Format ke ext4 | `sudo mkfs.ext4 /dev/sda1` |
| Backup baru | `sudo shrink-backup -a /media/<user>/<UUID>/backup_rpi.img` |
| Update backup | `sudo shrink-backup -U /media/<user>/<UUID>/backup_rpi.img` |
| Unmount | `sudo umount /media/<user>/<UUID>` |
| Jalankan screen | `screen -S backup_rpi` |
| Kembali ke screen | `screen -r backup_rpi` |
| Detach screen | `Ctrl + A`, lalu `D` |

---

## 💡 Tips Tambahan

- **Backup rutin**: Lakukan update backup setiap selesai menambah fitur atau aplikasi baru.
- **Cadangan ganda**: Simpan salinan file `.img` di hard disk eksternal atau cloud storage.
- **Uji restore**: Setidaknya sekali, coba restore ke kartu SD lain untuk memastikan backup benar-benar berfungsi.
- **Jangan gunakan `dd`** untuk backup rutin — lambat, boros ruang, dan tidak efisien.
- **Label flashdisk**: Beri label fisik pada flashdisk agar mudah dikenali (misal: nama project + tahun).

---

## 📚 Referensi

- Repository resmi: [github.com/UnconnectedBedna/shrink-backup](https://github.com/UnconnectedBedna/shrink-backup)
- Dokumentasi Raspberry Pi: [raspberrypi.com/documentation](https://raspberrypi.com/documentation)

---

*Dokumen ini bersifat generik dan bisa diterapkan ke project Raspberry Pi lain — cukup sesuaikan path flashdisk (`/media/<user>/<UUID>`), nama file image, dan nama project pada bagian label/catatan.*
