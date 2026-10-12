# Dokumentasi Sistem ATM Sampah - Encrypted QR Code Integration

**Status**: ✅ Berfungsi & sudah diuji (decrypt, isi hidden field, submit tersimpan dengan nilai asli)  
**Last Updated**: September 28, 2026  
**Environment**: Raspberry Pi + WordPress + Forminator 1.57.3 (Form ID 2797)  

---

## Table of Contents

1. [Ringkasan Sistem](#ringkasan-sistem)
2. [Arsitektur & Komponen](#arsitektur--komponen)
3. [Flow/Alur Kerja](#flowalur-kerja)
4. [Setup & Konfigurasi](#setup--konfigurasi)
5. [Code Lengkap](#code-lengkap)
6. [Keamanan](#keamanan)
7. [Kendala & Solusi](#kendala--solusi)
8. [Testing & Verification](#testing--verification)
9. [Troubleshooting](#troubleshooting)
10. [Maintenance & Future Improvements](#maintenance--future-improvements)

---

## Ringkasan Sistem

### Konteks
Sistem ini mengintegrasikan **Aplikasi GUI Raspberry Pi (ATM Sampah)** dengan **WordPress Forminator** untuk otomasi claim poin pengguna berdasarkan sampah yang dikumpulkan.

**Tantangan Awal**:
- Data transaksi (kode unik, poin, tanggal) tidak boleh dikirim plain text di URL
- Perlu verifikasi bahwa data berasal dari ATM, bukan user yang memanipulasi URL
- Hidden field di Forminator perlu terisi otomatis tanpa user mengetik
- Nilai yang tersimpan di database harus nilai asli (plaintext) hasil decrypt, bukan placeholder

### Solusi
Menggunakan **Encrypted QR Code** dengan skema:
1. **Raspberry Pi** generate transaksi → **Encrypt** (AES-256-CBC + HMAC-SHA256) → **Generate QR**
2. User scan QR pakai HP → **Browser** buka `pilahsampah.com/transactions/?token=...`
3. **WordPress PHP** decrypt token → **Verifikasi HMAC** → **Isi 3 Hidden Field** saat halaman dibuka
4. Saat user **Submit** (Forminator AJAX), token **didecrypt ulang di server** dan nilai field **ditimpa** dengan hasil decrypt sebelum disimpan ke database
5. Nilai dari browser tidak dipercaya, jadi user tidak bisa memalsukan data walau mengubah hidden field lewat DevTools

---

## Arsitektur & Komponen

```
┌─────────────────────────────────────────────────────────────────┐
│                     RASPBERRY PI (ATM)                           │
├─────────────────────────────────────────────────────────────────┤
│  15-atm-button3-encrypt.py (GUI)                                 │
│  ├─ Read GPIO sensors (botol, tutup)                            │
│  ├─ Generate transaction ID + count bottles                     │
│  ├─ Button "E-Struk" → Call encryption                          │
│  └─ showQRPopup() → Display encrypted QR                        │
│                                                                   │
│  config.py                                                       │
│  ├─ ENCRYPTION_KEY (32-byte, base64 encoded)                   │
│  ├─ HMAC_KEY (32-byte, base64 encoded)                         │
│  └─ WEBSITE_URL = "https://pilahsampah.com/transactions/"      │
│                                                                   │
│  encryption_aes.py                                              │
│  └─ encrypt_code(code, point, date) → token                    │
│     └─ JSON {code, point, date} → AES encrypt → HMAC digest    │
└─────────────────────────────────────────────────────────────────┘
                              ↓
                        [QR CODE]
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│                    USER SMARTPHONE                               │
├─────────────────────────────────────────────────────────────────┤
│  1. Scan QR                                                      │
│  2. Browser navigate: pilahsampah.com/transactions/?token=...   │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│               WORDPRESS SERVER + FORMINATOR                      │
├─────────────────────────────────────────────────────────────────┤
│  Snippet "ATM Sampah Decrypt Token" (Code Snippets)             │
│  ├─ atm_find_token(): token dari ?token= atau dari URL          │
│  │   halaman (referer) saat submit AJAX                         │
│  ├─ atm_decrypt_data(): verifikasi HMAC + decrypt AES-256-CBC   │
│  ├─ Render : filter forminator_field_hidden_field_value         │
│  └─ Submit : init + forminator_prepared_data +                  │
│             forminator_custom_form_submit_before_set_fields     │
│                                                                   │
│  Forminator Form ID 2797 (AJAX aktif)                           │
│  ├─ hidden-5 : {trxcode}  (kode transaksi)                      │
│  ├─ hidden-3 : {point}    (poin)                                │
│  └─ hidden-6 : {date}     (tanggal)                             │
└─────────────────────────────────────────────────────────────────┘
                              ↓
                    [Form Submission]
                              ↓
              [Database: nilai asli hasil decrypt]
                              ↓
        [Snippet "Pilah Topup" - terpisah, proses poin]
```

### Komponen Utama

| Komponen | Lokasi | Fungsi |
|---|---|---|
| **15-atm-button3-encrypt.py** | Raspberry Pi: `/home/aria/Desktop/atmsampah-v1-irsensor-2026/` | GUI utama, GPIO polling, trigger QR generate |
| **config.py** | Raspberry Pi: folder yang sama | Kunci enkripsi + HMAC + website URL |
| **encryption_aes.py** | Raspberry Pi: folder yang sama | Modul enkripsi AES-256-CBC + HMAC-SHA256 |
| **ATM Sampah Decrypt Token** | WordPress: Code Snippets plugin | Decrypt token, isi hidden field (render), timpa nilai (submit) |
| **Pilah Topup** | WordPress: Code Snippets plugin (snippet terpisah) | Memproses poin setelah entry tersimpan, termasuk pengecekan token yang dipakai berulang. Di luar cakupan dokumen ini |
| **Forminator Form 2797** | WordPress: `/transactions/` | Hidden field yang diisi otomatis + submit |

---

## Flow/Alur Kerja

### 1. User Interaction di ATM (Raspberry Pi)

```
START
  ↓
Display main screen (idle screensaver 60s)
  ↓
User klik "Mulai" button
  ↓
GPIO sensor reading loop (50ms interval)
  ├─ Sensor 1 (botol) = LOW ?
  ├─ Sensor 2 (tutup) = LOW ?
  ├─ Both LOW = VALID transaksi
  │  └─ bottleCounter() → bottle++, saldo += POIN_PER_BOTOL
  └─ Loop terus...
  ↓
User klik "E-Struk" button
  ↓
IF bottle > 0:
  ├─ _startPulse(START_PIN) → signal ke Arduino
  ├─ showQRPopup():
  │  ├─ Transaction ID (trxId): 5 digit random (10000-99999)
  │  ├─ Get current date (dd/mm/yyyy)
  │  ├─ Get total saldo (poin)
  │  ├─ Call encrypt_code(trxId, saldo, date, ENCRYPTION_KEY, HMAC_KEY)
  │  │  └─ JSON: {"code": "...", "point": ..., "date": "..."}
  │  │  └─ AES-256-CBC encrypt (random IV setiap kali)
  │  │  └─ HMAC-SHA256 digest (IV + ciphertext)
  │  │  └─ Base64 URL-safe encode: IV + ciphertext + HMAC
  │  ├─ Build URL: https://pilahsampah.com/transactions/?token=<encrypted>
  │  ├─ Generate QR code dari URL
  │  └─ Show popup 20 detik
  ├─ Reset bottle = 0, saldo = 0
  └─ Generate new transaction ID
ELSE:
  └─ Show message: "Belum ada botol"
  ↓
END
```

### 2. QR Scan & Web Flow

```
User scans QR code on HP
  ↓
Browser opens: https://pilahsampah.com/transactions/?token=...
  ↓
[FASE 1: Halaman dibuka - request GET]
  ├─ atm_get_data()
  │  ├─ atm_find_token(): baca $_GET['token']
  │  ├─ atm_decrypt_data(token)
  │  │  ├─ Base64 URL-safe decode
  │  │  ├─ Parse: IV (16 byte) + ciphertext + HMAC (32 byte)
  │  │  ├─ Verify HMAC (SHA256) → if mismatch: return false
  │  │  ├─ Decrypt ciphertext (AES-256-CBC)
  │  │  └─ Parse JSON → {code, point, date}
  │  └─ Hasil di-cache untuk request ini
  │
  └─ Forminator render hidden field
     └─ Filter forminator_field_hidden_field_value:
        {trxcode} → code, {point} → point, {date} → date
  ↓
User klik Submit
  ↓
[FASE 2: Submit - request AJAX baru dari Forminator]
  Catatan: token TIDAK ada di $_GET pada request ini
  ├─ atm_find_token(): ambil token dari URL halaman
  │  (urutan: _wp_http_referer → current_url → HTTP_REFERER)
  ├─ Decrypt ulang di server
  ├─ init (priority 1)                 → timpa $_POST hidden-5/3/6
  ├─ forminator_prepared_data          → timpa data submit
  └─ forminator_custom_form_submit_before_set_fields
                                       → timpa data entry sebelum disimpan
  ↓
Entry tersimpan di database dengan nilai asli (bukan placeholder)
  ↓
Snippet "Pilah Topup" memproses poin (terpisah)
```

**Kenapa perlu Fase 2?** Forminator memakai AJAX. Request submit adalah request baru tanpa `?token=` dan tanpa `$_POST` buatan dari Fase 1. Selain itu Forminator menyimpan hidden field berdasarkan pengaturan field ("Custom Value"), sehingga tanpa penimpaan di Fase 2 yang tersimpan adalah teks placeholder `{trxcode}`, `{point}`, `{date}`.

---

## Setup & Konfigurasi

### Prerequisites
- **Raspberry Pi**: Python 3.8+, pycryptodome, qrcode, PIL, RPi.GPIO
- **WordPress**: Forminator plugin, Code Snippets plugin
- **PHP**: OpenSSL extension enabled (untuk AES decrypt)

### A. Setup Raspberry Pi

#### 1. Install Python Dependencies
```bash
# SSH to Raspberry Pi
ssh aria@raspberry-ip

# Navigate to project folder
cd /home/Desktop/atmsampah-v1-irsensor-2026

# Install packages
pip3 install pycryptodome qrcode[pil] pillow RPi.GPIO
```

#### 2. Generate Kunci Enkripsi (CRITICAL)

**Jalankan SATU KALI saja untuk generate kunci unik:**

```bash
python3 << 'EOF'
import base64
from Crypto.Random import get_random_bytes

# Generate 32 byte random untuk encryption key
encryption_key = get_random_bytes(32)
encryption_key_b64 = base64.b64encode(encryption_key).decode()

# Generate 32 byte random untuk HMAC key
hmac_key = get_random_bytes(32)
hmac_key_b64 = base64.b64encode(hmac_key).decode()

print("=" * 70)
print("COPY SELURUH CODE INI KE config.py:")
print("=" * 70)
print()
print("import base64")
print()
print('ENCRYPTION_KEY = base64.b64decode("' + encryption_key_b64 + '")')
print('HMAC_KEY = base64.b64decode("' + hmac_key_b64 + '")')
print()
print('WEBSITE_URL = "https://pilahsampah.com/transactions/"')
print()
print("=" * 70)
print("SIMPAN KUNCI INI JUGA DI SINI (untuk PHP nanti)")
print('ENCRYPTION_KEY base64: ' + encryption_key_b64)
print('HMAC_KEY base64: ' + hmac_key_b64)
print("=" * 70)
EOF
```

**Copy output-nya, buat file `config.py`:**
```bash
nano config.py
```

Paste isi yang di-generate, save.

#### 3. Test Enkripsi
```bash
python3 encryption_aes.py
```

**Expected Output:**
```
[TEST ENCRYPTION]
Code:  TRXAB12CD34EF56
Point: 250
Date:  23/09/2026

Token: eyJhbGciOiJI...(panjang base64url)
Token Length: 150+
```

#### 4. Jalankan App
```bash
python3 15-atm-button3-encrypt.py
```

Harusnya GUI muncul. Jangan close window, lanjut ke WordPress setup.

---

### B. Setup WordPress + Forminator

#### 1. Install Code Snippets Plugin (jika belum)
```
WordPress Admin → Plugins → Add New → Search "Code Snippets" → Install & Activate
```

#### 2. Tambah Code Snippet untuk Decrypt

Di **Plugins → Code Snippets → Add New**:
- **Title**: `ATM Sampah Decrypt Token`
- **Description**: Decrypt encrypted QR token dan isi/timpa field Forminator
- **Code**: paste snippet PHP dari section "Code Lengkap" (poin 4). **Jangan** sertakan tag `<?php`
- **Run Everywhere**: ✅ Yes, everywhere
- **Save & Activate**

Gunakan **satu snippet saja** untuk decrypt + isi field, karena keduanya saling bergantung pada fungsi yang sama. Snippet **Pilah Topup** tetap terpisah.

#### 3. Setup Forminator Form (Form ID 2797)

**Pengaturan form**:
- **Enable AJAX**: ✅ aktif (`enable-ajax = true`). Snippet ini dibuat untuk mode AJAX
- **Simpan submission**: ✅ aktif
- **Kondisi Submit**: tombol submit disembunyikan jika `hidden-5` kosong (mencegah submit tanpa token)

**Hidden field** (jangan digabung, semua *Required* = ❌ NO, Default Value = **Custom Value**):

| Field ID | Label | Custom Value | Diisi oleh |
|---|---|---|---|
| `hidden-5` | TID | `{trxcode}` | Snippet decrypt (kode transaksi) |
| `hidden-3` | Point | `{point}` | Snippet decrypt (poin) |
| `hidden-6` | Date | `{date}` | Snippet decrypt (tanggal) |
| `hidden-1` | user_id | Forminator: `user_id` | Otomatis oleh Forminator |
| `hidden-2` | user_name | Forminator: `user_login` | Otomatis oleh Forminator |
| `hidden-4` | transaction_type | Forminator: `embed_title` | Otomatis oleh Forminator |

Custom Value harus **persis** `{trxcode}`, `{point}`, `{date}` (huruf kecil, tanpa spasi). Jika ID field atau Form ID berbeda, ubah konstanta `ATM_FORM_ID`, `ATM_FIELD_CODE`, `ATM_FIELD_POINT`, `ATM_FIELD_DATE` di bagian atas snippet.

Field HTML (`html-1`, `html-2`, `html-3`) hanya menampilkan `{hidden-5}`, `{hidden-3}`, `{hidden-6}` ke user sebagai info.

#### 4. Verify Halaman Form
- Pastikan form ditaruh di halaman: **`https://pilahsampah.com/transactions/`**
- QR dari Raspberry Pi akan point ke URL ini dengan `?token=...`

#### 5. Aktifkan Debug Log WordPress
Di `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```
Log ada di `wp-content/debug.log`. Nonaktifkan setelah selesai debugging.

---

## Code Lengkap

### 1. config.py (Raspberry Pi)

```python
import base64

# ===== KUNCI ENKRIPSI (HARUS IDENTIK DENGAN PHP) =====
# Generate dengan script di atas, jangan hardcode!
ENCRYPTION_KEY = base64.b64decode("DC+jHfP07+jZCi3rhVWAPDXWlZUCqSlR9keAyA4A6wI=")
HMAC_KEY = base64.b64decode("4nw6aJ3X+D9k3A6gIH3mB0yGUPqfVb9e+CV361Ltl7Q=")

# Website URL
WEBSITE_URL = "https://pilahsampah.com/transactions/"
```

**PENTING**: 
- Ganti value kunci dengan hasil generate Anda sendiri
- Jangan commit ke git / share ke publik
- Kunci PHP harus 100% identik (base64 string sama persis)

---

### 2. encryption_aes.py (Raspberry Pi)

```python
"""
ATM SAMPAH - ENCRYPTION MODULE (AES-256-CBC)
Fungsi untuk encrypt/decrypt data transaksi
"""

import json
import base64
import hmac
import hashlib
from Crypto.Cipher import AES
from Crypto.Util.Padding import pad
from Crypto.Random import get_random_bytes


def encrypt_code(code, point, date, encryption_key, hmac_key):
    """
    Encrypt kode unik transaksi + point + date.

    Args:
        code: Transaction code (string)
        point: Jumlah poin (int)
        date: Tanggal transaksi (string, format dd/mm/yyyy)
        encryption_key: 32-byte encryption key
        hmac_key: 32-byte HMAC key

    Returns:
        Token string (base64 URL-safe encoded)
        Format: base64url(IV + ciphertext + HMAC)
    """

    try:
        # ========== BUAT JSON DATA (CODE + POINT + DATE) ==========
        data = json.dumps({"code": code, "point": point, "date": date}).encode('utf-8')

        print(f"[ENCRYPT] Data: code={code}, point={point}, date={date}")
        print(f"[ENCRYPT] JSON plaintext: {data.decode()}")

        # ========== GENERATE IV RANDOM ==========
        iv = get_random_bytes(16)

        # ========== ENCRYPT MENGGUNAKAN AES-256-CBC ==========
        cipher = AES.new(encryption_key, AES.MODE_CBC, iv)
        ciphertext = cipher.encrypt(pad(data, AES.block_size))

        # ========== BUAT HMAC UNTUK VERIFIKASI INTEGRITAS ==========
        mac = hmac.new(hmac_key, iv + ciphertext, hashlib.sha256).digest()

        # ========== GABUNG: IV + CIPHERTEXT + MAC ==========
        token_bytes = iv + ciphertext + mac

        # ========== ENCODE DENGAN BASE64 URL-SAFE ==========
        token = base64.urlsafe_b64encode(token_bytes).decode('utf-8')

        print(f"[ENCRYPT] ✅ Token generated (length: {len(token)})")

        return token

    except Exception as e:
        print(f"[ENCRYPT] ❌ Error: {e}")
        raise


def test_encryption():
    """Test function untuk debugging"""
    from config import ENCRYPTION_KEY, HMAC_KEY

    code = "TRX8K7J9M2Q4P"
    point = 150
    date = "23/09/2026"
    token = encrypt_code(code, point, date, ENCRYPTION_KEY, HMAC_KEY)

    print(f"\n[TEST] Generated Token:")
    print(f"{token}\n")

    return token


if __name__ == "__main__":
    test_encryption()
```

---

### 3. app.py - Fungsi showQRPopup()

**Ganti fungsi `showQRPopup()` di app.py Anda dengan:**

```python
def showQRPopup():
    """Tampilkan QR dengan token terenkripsi"""
    from config import ENCRYPTION_KEY, HMAC_KEY
    
    date_now = datetime.now().strftime("%d/%m/%Y")
    
    try:
        # Encrypt dengan memasukkan encryption_key dan hmac_key sebagai parameter
        token = encrypt_code(trxId, saldo, date_now, ENCRYPTION_KEY, HMAC_KEY)
        
        # URL dengan token terenkripsi
        url = f"{WEBSITE_URL}?token={token}"
        
        print(f"[QR] TID: {trxId}")
        print(f"[QR] Point: {saldo}")
        print(f"[QR] Date: {date_now}")
        print(f"[QR] Token: {token}")
        print(f"[QR] Full URL: {url}")
        
    except Exception as e:
        print(f"[QR] Encryption error: {e}")
        url = (
            f"{WEBSITE_URL}"
            f"?code={trxId}"
            f"&date={date_now}"
            f"&point={saldo}"
        )
        print(f"[QR] Fallback URL (tidak terenkripsi): {url}")

    qr = qrcode.QRCode(box_size=6, border=4)
    qr.add_data(url)
    qr.make(fit=True)

    qr_img = qr.make_image(fill_color="black", back_color="white")

    overlay = Frame(
        root,
        bg="white",
        bd=5,
        highlightbackground="black",
        highlightthickness=2
    )

    overlay.place(
        relx=0.5,
        rely=0.5,
        anchor=CENTER,
        width=400,
        height=460
    )

    overlay.lift()

    Label(
        overlay,
        text="* Scan kode ini dari HP untuk klaim poin",
        font=("Helvetica", 11, "bold"),
        bg="white"
    ).pack(pady=(20, 0))

    Label(
        overlay,
        text="* Kode akan hilang dalam 20 detik",
        font=("Helvetica", 11, "bold"),
        fg="red",
        bg="white"
    ).pack(pady=(0, 0))

    tk_img = ImageTk.PhotoImage(qr_img)

    qr_label = Label(overlay, image=tk_img, bg="white")
    qr_label.image = tk_img
    qr_label.pack(pady=10)

    Button(
        overlay,
        text="Tutup",
        font=("Helvetica", 10, "bold"),
        bg="red",
        fg="white",
        width=10,
        command=overlay.destroy
    ).pack(pady=15)

    overlay.after(20000, overlay.destroy)
```

**Pastikan import di top of file:**
```python
from encryption_aes import encrypt_code
from config import WEBSITE_URL
```

---

### 4. ATM Sampah Decrypt Token (WordPress Code Snippet)

**Copy ke WordPress Admin → Code Snippets → Add New → Code field (tanpa tag `<?php`):**

```php
/**
 * ATM SAMPAH - Decrypt Encrypted QR Token (v2)
 * Pasang di: Code Snippets (Run Everywhere), ganti snippet lama.
 *
 * Perbedaan dari v1:
 * - Tidak lagi bergantung pada $_POST buatan dari request pertama.
 * - Saat submit AJAX, token diambil lagi dari URL halaman (referer),
 *   didecrypt ulang, lalu nilai field dipaksa dari hasil decrypt server.
 */

// KUNCI HARUS IDENTIK DENGAN config.py PYTHON
define('ATM_ENCRYPTION_KEY', base64_decode('DC+jHfP07+jZCi3rhVWAPDXWlZUCqSlR9keAyA4A6wI='));
define('ATM_HMAC_KEY', base64_decode('4nw6aJ3X+D9k3A6gIH3mB0yGUPqfVb9e+CV361Ltl7Q='));

define('ATM_FORM_ID', 2797);
define('ATM_FIELD_CODE', 'hidden-5');
define('ATM_FIELD_POINT', 'hidden-3');
define('ATM_FIELD_DATE', 'hidden-6');


/**
 * Decrypt token -> ['code'=>.., 'point'=>.., 'date'=>..] atau false.
 */
function atm_decrypt_data($token) {
    $bytes = base64_decode(strtr($token, '-_', '+/'));

    if ($bytes === false || strlen($bytes) < 64) {
        error_log('[ATM] Token terlalu pendek atau invalid base64');
        return false;
    }

    $iv         = substr($bytes, 0, 16);
    $mac        = substr($bytes, -32);
    $ciphertext = substr($bytes, 16, -32);

    $calc_mac = hash_hmac('sha256', $iv . $ciphertext, ATM_HMAC_KEY, true);
    if (!hash_equals($mac, $calc_mac)) {
        error_log('[ATM] HMAC verification failed - token mungkin dipalsukan');
        return false;
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', ATM_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        error_log('[ATM] Decryption failed: ' . openssl_error_string());
        return false;
    }

    $data = json_decode($plain, true);
    if (!is_array($data) || !isset($data['code'])) {
        error_log('[ATM] JSON parse failed or missing code field');
        return false;
    }

    return [
        'code'  => (string) $data['code'],
        'point' => (string) ((int) ($data['point'] ?? 0)),
        'date'  => (string) ($data['date'] ?? ''),
    ];
}


/**
 * Ambil nilai ?token= dari sebuah URL.
 */
function atm_extract_token_from_url($url) {
    if (!is_string($url) || $url === '') {
        return '';
    }
    $query = wp_parse_url($url, PHP_URL_QUERY);
    if (!$query) {
        return '';
    }
    parse_str($query, $params);
    return isset($params['token']) ? (string) $params['token'] : '';
}


/**
 * Cari token: dari $_GET (halaman dibuka), atau dari URL halaman
 * saat submit AJAX (referer).
 */
function atm_find_token() {
    if (!empty($_GET['token'])) {
        return sanitize_text_field(wp_unslash($_GET['token']));
    }

    $sources = [
        'wp_http_referer' => $_POST['_wp_http_referer'] ?? '',
        'current_url'     => $_POST['current_url'] ?? '',
        'http_referer'    => $_SERVER['HTTP_REFERER'] ?? '',
    ];

    foreach ($sources as $name => $url) {
        $token = atm_extract_token_from_url(wp_unslash($url));
        if ($token !== '') {
            error_log('[ATM] Token ditemukan dari: ' . $name);
            return sanitize_text_field($token);
        }
    }

    return '';
}


/**
 * Hasil decrypt untuk request ini (di-cache, hanya decrypt sekali).
 */
function atm_get_data() {
    static $cache = null;
    static $done  = false;

    if ($done) {
        return $cache;
    }
    $done = true;

    $token = atm_find_token();
    if ($token === '') {
        return null;
    }

    $data  = atm_decrypt_data($token);
    $cache = ($data === false) ? null : $data;
    return $cache;
}


/**
 * Saat SUBMIT Forminator (form 2797): paksa nilai field dari hasil decrypt server.
 * Nilai dari browser diabaikan, jadi tidak bisa dimanipulasi user.
 */
add_action('init', function () {

    if (empty($_POST['form_id']) || (int) $_POST['form_id'] !== ATM_FORM_ID) {
        return;
    }

    $data = atm_get_data();

    if ($data === null) {
        error_log('[ATM] Submit tanpa token valid, field dikosongkan');
        $_POST[ATM_FIELD_CODE]  = '';
        $_POST[ATM_FIELD_POINT] = '';
        $_POST[ATM_FIELD_DATE]  = '';
        return;
    }

    $_POST[ATM_FIELD_CODE]  = $data['code'];
    $_POST[ATM_FIELD_POINT] = $data['point'];
    $_POST[ATM_FIELD_DATE]  = $data['date'];

    error_log('[ATM] Submit: field dipaksa dari token, code=' . $data['code']);

}, 1);


/**
 * Saat RENDER halaman: isi hidden field dari placeholder Forminator.
 */
add_filter('forminator_field_hidden_field_value', function ($value, $saved_value, $field, $hidden_field) {

    if ($saved_value !== 'custom_value') {
        return $value;
    }

    $map = [
        '{trxcode}' => 'code',
        '{point}'   => 'point',
        '{date}'    => 'date',
    ];

    if (!isset($map[$value])) {
        return $value;
    }

    $data = atm_get_data();

    return ($data === null) ? '' : $data[$map[$value]];

}, 99, 4);



/**
 * Nilai final tiap field (dari hasil decrypt server). Kosong jika token tidak valid.
 */
function atm_forced_values() {
    $data = atm_get_data();
    return [
        ATM_FIELD_CODE  => $data === null ? '' : $data['code'],
        ATM_FIELD_POINT => $data === null ? '' : $data['point'],
        ATM_FIELD_DATE  => $data === null ? '' : $data['date'],
    ];
}


/**
 * SUBMIT (1/2): ganti data yang dikirim ke Forminator sebelum diproses.
 * Cara ini mengikuti contoh resmi dari tim support Forminator (WPMU DEV).
 */
add_filter('forminator_prepared_data', function ($prepared_data, $module_object) {

    if (!is_object($module_object) || (int) $module_object->id !== ATM_FORM_ID || !is_array($prepared_data)) {
        return $prepared_data;
    }

    foreach (atm_forced_values() as $name => $value) {
        if (array_key_exists($name, $prepared_data)) {
            $prepared_data[$name] = $value;
        }
    }

    return $prepared_data;

}, 10, 2);


/**
 * SUBMIT (2/2): ganti data entry tepat sebelum disimpan ke database.
 * Nilai lama ({trxcode}, dst) ditimpa dengan hasil decrypt.
 */
add_action('forminator_custom_form_submit_before_set_fields', function ($entry, $module_id, $field_data_array) {

    if ((int) $module_id !== ATM_FORM_ID || !is_array($field_data_array) || !class_exists('Forminator_CForm_Front_Action')) {
        return;
    }

    $values = atm_forced_values();

    foreach ($field_data_array as $key => $item) {
        if (is_array($item) && isset($item['name']) && array_key_exists($item['name'], $values)) {
            error_log('[ATM] Simpan ' . $item['name'] . ': "' . ($item['value'] ?? '') . '" -> "' . $values[$item['name']] . '"');
            Forminator_CForm_Front_Action::$info['field_data_array'][$key]['value'] = $values[$item['name']];
        }
    }

}, 10, 3);
```

**Ringkasan fungsi & hook:**

| Bagian | Fungsi |
|---|---|
| `atm_decrypt_data()` | Verifikasi HMAC lalu decrypt AES-256-CBC, return `['code','point','date']` atau `false` |
| `atm_find_token()` | Ambil token dari `?token=` atau, saat submit AJAX, dari URL halaman (referer) |
| `atm_get_data()` | Decrypt sekali per request (di-cache) |
| `init` (priority 1) | Saat submit form 2797, timpa `$_POST` hidden-5/3/6 dengan hasil decrypt |
| `forminator_field_hidden_field_value` | Isi hidden field saat halaman dibuka (render) |
| `forminator_prepared_data` | Timpa data submit dengan hasil decrypt |
| `forminator_custom_form_submit_before_set_fields` | Timpa data entry tepat sebelum disimpan ke database |

---

## Keamanan

### Apa yang bisa dan tidak bisa dilakukan user

| Aksi user | Hasil |
|---|---|
| Mengubah 1 karakter pada token di URL | **Ditolak**. HMAC gagal, field kosong |
| Membuat token palsu | **Tidak bisa**. Butuh `ENCRYPTION_KEY` dan `HMAC_KEY` |
| Membaca isi token dari URL | **Tidak bisa**. Isi terenkripsi |
| Mengubah nilai hidden field lewat DevTools sebelum Submit | **Tidak berpengaruh**. Server menimpa dengan hasil decrypt token |
| Membuka form tanpa token | Field kosong, tombol submit disembunyikan (kondisi `hidden-5` kosong) |
| Submit tanpa token valid | Field dikosongkan oleh snippet (`Submit tanpa token valid`) |
| Memakai token/QR yang sama berulang | Ditangani di snippet **Pilah Topup** (di luar snippet decrypt) |

### Catatan
- **Token yang sama tetap bisa dibuka di browser berkali-kali.** Snippet decrypt hanya memverifikasi keaslian token, bukan apakah sudah pernah dipakai. Pengecekan pemakaian berulang ada di sistem Pilah Topup.
- **Fallback URL tidak terenkripsi** di `showQRPopup()` (saat enkripsi error) tidak dikenali snippet ini. Field akan kosong dan tombol submit tersembunyi.
- **Jaga kerahasiaan key.** Dokumen ini berisi key asli. Jangan commit ke git publik atau dibagikan.

---

## Kendala & Solusi

### Kendala 1: "Incorrect padding" saat Python startup

**Penyebab**: Base64 string di config.py tidak valid

**Solusi**: Generate ulang kunci dengan script di section "Setup Raspberry Pi", lalu samakan di snippet PHP.

---

### Kendala 2: "Token tidak valid" saat scan QR

**Penyebab Umum**:
1. Kunci Python & PHP berbeda
2. Token terpotong/berubah saat QR dibuat atau di-copy
3. Padding mismatch (Python pakai PKCS7)

**Diagnosis**:
```bash
tail -50 /path/to/wordpress/wp-content/debug.log | grep ATM
```
Pesan error yang mungkin muncul: `HMAC verification failed`, `Token terlalu pendek atau invalid base64`, `Decryption failed`.

**Solusi**:
1. Verifikasi kunci identik:
   ```bash
   python3 -c "from config import ENCRYPTION_KEY, HMAC_KEY; import base64; print(base64.b64encode(ENCRYPTION_KEY).decode()); print(base64.b64encode(HMAC_KEY).decode())"
   ```
   Bandingkan dengan dua baris `define` di snippet PHP.
2. Pastikan `encryption_aes.py` memakai `pad(data, AES.block_size)` (PKCS7).

---

### Kendala 3: "Error: Your form is not valid, please fix the errors!" / value kosong saat submit

**Penyebab**: Snippet versi lama menyimpan hasil decrypt di `$_POST` pada request pertama. Forminator AJAX membuat request baru saat Submit, sehingga token dan `$_POST` buatan tidak ada. Filter mengembalikan string kosong.

**Solusi**: Gunakan snippet versi terbaru (v2). Saat submit, token diambil lagi dari URL halaman (referer) dan didecrypt ulang di server.

**Diagnosis di debug.log** (saat submit, harus muncul):
```
[ATM] Token ditemukan dari: wp_http_referer
[ATM] Submit: field dipaksa dari token, code=...
```

---

### Kendala 4: Data di database berisi teks `{trxcode}`, `{point}`, `{date}`

**Gejala**: Log decrypt sukses dan `Submit: field dipaksa dari token`, tetapi di tabel entry meta nilai `hidden-5/3/6` masih berupa placeholder. Proses lanjutan (mis. Pilah Topup) membaca `tid={trxcode}` dan `amount=0`.

**Penyebab**: Forminator menyimpan hidden field bertipe Custom Value berdasarkan pengaturan field, bukan dari `$_POST`. Mengubah `$_POST` saja tidak cukup. Filter `forminator_custom_form_submit_field_data` yang sempat dicoba hanya mengubah salinan data, bukan data yang disimpan.

**Solusi**: Timpa data yang benar-benar dipakai untuk menyimpan, yaitu:
1. `forminator_prepared_data` (filter)
2. `forminator_custom_form_submit_before_set_fields` (action) → mengubah `Forminator_CForm_Front_Action::$info['field_data_array']`

Keduanya sudah ada di snippet v2. Cara ini mengikuti contoh dari tim support Forminator (WPMU DEV) untuk mengubah hidden field saat submit.

**Diagnosis di debug.log**:
```
[ATM] Simpan hidden-5: "{trxcode}" -> "45063"
[ATM] Simpan hidden-3: "{point}" -> "50"
[ATM] Simpan hidden-6: "{date}" -> "28/09/2026"
```
Jika baris `Simpan ...` tidak muncul, pastikan Form ID (2797) dan ID field di konstanta snippet sudah benar.

---

### Kendala 5: Field di Forminator kosong saat halaman dibuka

**Penyebab**:
- Custom Value di field tidak persis `{trxcode}`, `{point}`, `{date}`
- Token tidak valid / tidak ada di URL
- Hidden field belum di-save setelah mengubah Custom Value

**Solusi**: Cek Custom Value (huruf kecil, tanpa spasi), cek debug.log untuk error decrypt, lalu save ulang form.

---

## Testing & Verification

### Test 1: Encryption di Python

```bash
cd /home/aria/Desktop/atmsampah-v1-irsensor-2026
python3 encryption_aes.py
```

**Expected**: token base64url tercetak tanpa error (`[ENCRYPT] ✅ Token generated`).

---

### Test 2: Decrypt & isi field di halaman

1. Copy token dari Test 1 (atau dari QR ATM)
2. Buka di browser: `https://pilahsampah.com/transactions/?token=<TOKEN>`
3. Cek debug.log: **tidak boleh ada** error `[ATM] HMAC verification failed` atau `Decryption failed`
4. Form tampil dengan info TID, Poin, Tanggal terisi (bagian `html-1/2/3`)

✅ **Passed**: Field terisi sesuai token

---

### Test 3: Submit menyimpan nilai asli

1. Buka URL dengan token, klik **Submit**
2. Cek `debug.log`:
   ```
   [ATM] Token ditemukan dari: wp_http_referer
   [ATM] Submit: field dipaksa dari token, code=...
   [ATM] Simpan hidden-5: "{trxcode}" -> "..."
   [ATM] Simpan hidden-3: "{point}" -> "..."
   [ATM] Simpan hidden-6: "{date}" -> "..."
   ```
3. Cek database (tabel entry meta Forminator, `frmt_form_entry_meta`): `hidden-5`, `hidden-3`, `hidden-6` berisi **nilai asli**, bukan placeholder

✅ **Passed**: Nilai asli tersimpan

---

### Test 4: Manipulasi hidden field (keamanan)

1. Buka URL dengan token valid
2. Di DevTools (Inspect), ubah value `hidden-3` menjadi angka lain
3. Klik Submit
4. Cek database: nilai `hidden-3` harus tetap sesuai token, bukan angka yang diubah

✅ **Passed**: Nilai dari browser diabaikan

---

### Test 5: Token dipalsukan

1. Ubah 1 karakter pada `?token=...` lalu buka halaman
2. Debug.log harus mencatat `HMAC verification failed`, field kosong, tombol submit tersembunyi

---

### Test 6: End-to-End (ATM → QR → Form)

1. **Di Raspberry Pi**: Jalankan app, klik "E-Struk" → QR muncul
2. **Di HP**: Scan QR
3. **Browser buka URL**: `pilahsampah.com/transactions/?token=...`
4. Klik Submit → cek database dan hasil Pilah Topup

✅ **Passed**: End-to-end berfungsi

---

## Troubleshooting

### Symptom: "ModuleNotFoundError: No module named 'Crypto'"

```bash
pip3 install pycryptodome
```

---

### Symptom: Decrypt berhasil tapi data di database masih placeholder

Lihat **Kendala 4**. Pastikan snippet v2 aktif (ada hook `forminator_prepared_data` dan `forminator_custom_form_submit_before_set_fields`) dan log `Simpan ...` muncul.

---

### Symptom: Log `Submit tanpa token valid, field dikosongkan`

**Penyebab**: Saat submit, snippet tidak menemukan token di URL halaman.
- Halaman form dibuka tanpa `?token=`
- Browser/plugin menghapus referer
- Token tidak valid

**Solusi**: Buka form lewat URL QR lengkap dengan `?token=...`, jangan dari bookmark halaman kosong.

---

### Symptom: "openssl_decrypt(): Cipher not available"

**Penyebab**: PHP OpenSSL tidak support `aes-256-cbc`

**Cek**:
```php
print_r(openssl_get_cipher_methods());
// Harus ada 'aes-256-cbc'
```

**Solusi**: Aktifkan ekstensi OpenSSL PHP (biasanya sudah default di WordPress)

---

### Symptom: HMAC verification failed, token dipalsukan

**Penyebab**:
- User mencoba ubah URL token manual
- Kunci PHP & Python tidak identik
- Token rusak saat dibuat QR / di-copy

Jika user mengubah token manual, ini adalah **behavior yang benar**: token palsu ditolak.

---

## Maintenance & Future Improvements

### Saat Ini (Working)
- ✅ Encrypt code + point + date (AES-256-CBC + HMAC-SHA256)
- ✅ Decrypt di WordPress dan isi hidden field
- ✅ Nilai asli tersimpan di database (bukan placeholder), kompatibel dengan Forminator AJAX
- ✅ Nilai dari browser tidak dipercaya (ditimpa server saat submit)
- ✅ Pengecekan token dipakai berulang (di Pilah Topup)
- ✅ 20 detik QR display
- ✅ Idle screensaver

### Saran Improvement
1. **Add expiry time** ke token (jangan bisa pakai QR yang sudah lama)
   - Tambah `"exp": unix_timestamp` ke JSON
   - Verifikasi di PHP: `if time() > exp: reject`

2. **Add machine_id** (jika ada multiple ATM)
   - Include `"machine_id": "ATM_001"` di JSON
   - Verifikasi di PHP

3. **Perhatikan panjang kode transaksi**
   - `trxId` saat ini 5 digit random (10000-99999), hanya 90.000 kemungkinan
   - Jika pengecekan token berulang hanya berdasarkan `code`, kode yang kebetulan sama pada transaksi baru bisa ditolak seiring bertambahnya jumlah transaksi
   - Pertimbangkan kode lebih panjang, atau kombinasi kode + tanggal

4. **Logging/Analytics**
   - Store decrypt events ke database
   - Track transaksi sukses/gagal

5. **QR Expiry UI**
   - Ubah dari 20 detik fixed menjadi countdown timer

6. **Ganti key secara berkala** dan simpan di tempat aman (bukan di dokumen/chat)

---

## Referensi & Resource

- **Pycryptodome**: https://www.dlitz.net/software/pycryptodome/
- **Forminator Docs**: https://wpmudev.com/docs/wpmu-dev-plugins/forminator/
- **PHP OpenSSL**: https://www.php.net/manual/en/function.openssl-decrypt.php
- **AES-256-CBC**: https://en.wikipedia.org/wiki/Advanced_Encryption_Standard

---

## Checklist Deployment

- [ ] config.py di Raspberry Pi dengan kunci unik di-generate
- [ ] encryption_aes.py dipasang di folder yang sama
- [ ] `showQRPopup()` di app sudah menggunakan `encrypt_code`
- [ ] Import statements benar di app
- [ ] Code Snippets plugin aktif di WordPress
- [ ] Snippet "ATM Sampah Decrypt Token" (v2) di-paste, Run Everywhere, aktif
- [ ] Kunci PHP identik dengan Python (base64 string sama persis)
- [ ] Forminator Form 2797: AJAX aktif, 3 Hidden Field (Required OFF)
- [ ] Custom Value: `{trxcode}` (hidden-5), `{point}` (hidden-3), `{date}` (hidden-6)
- [ ] Konstanta `ATM_FORM_ID` dan ID field di snippet sesuai form
- [ ] Form halaman: `https://pilahsampah.com/transactions/`
- [ ] Test encryption: `python3 encryption_aes.py` ✅
- [ ] Test submit: nilai asli tersimpan di database ✅
- [ ] Test manipulasi hidden field: nilai tetap dari token ✅
- [ ] Test end-to-end: ATM → scan QR → form submit → Pilah Topup ✅

---

**Status**: ✅ Berfungsi  
**Last Tested**: September 28, 2026  
**Environment**: Raspberry Pi OS + WordPress 6.x + Forminator 1.57.3 + PHP 7.4+
