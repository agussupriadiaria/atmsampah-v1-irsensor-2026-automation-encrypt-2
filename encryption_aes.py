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