<?php
define('ATM_ENCRYPTION_KEY', base64_decode('eceiBSuVret8nOgzxcqhS/iFeiyMZ0zTC266ONR+Byg='));
define('ATM_HMAC_KEY', base64_decode('7eei2G7D148OsMLRhKt/1+BmqlFmVaVoWBaXSOS+lBM='));

function atm_decrypt_data($token) {
    $bytes = base64_decode(strtr($token, '-_', '+/'));

    if ($bytes === false || strlen($bytes) < 64) {
        error_log('[ATM] Token terlalu pendek');
        return false;
    }

    $iv         = substr($bytes, 0, 16);
    $mac        = substr($bytes, -32);
    $ciphertext = substr($bytes, 16, -32);

    $calc_mac = hash_hmac('sha256', $iv . $ciphertext, ATM_HMAC_KEY, true);
    if (!hash_equals($mac, $calc_mac)) {
        error_log('[ATM] HMAC gagal');
        return false;
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', ATM_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        error_log('[ATM] Decrypt gagal');
        return false;
    }

    $data = json_decode($plain, true);
    if (!isset($data['code'])) {
        error_log('[ATM] JSON parse gagal');
        return false;
    }

    error_log('[ATM] Decrypt success: ' . json_encode($data));
    
    return [
        'code'  => $data['code'],
        'point' => $data['point'] ?? 0,
        'date'  => $data['date'] ?? '',
    ];
}

// UBAH: Jalan di 'init' bukan 'wp' - lebih awal
add_action('init', function () {
    if (!isset($_GET['token'])) {
        return;
    }

    error_log('[ATM] Processing token...');
    
    $data = atm_decrypt_data(sanitize_text_field($_GET['token']));

    if ($data !== false) {
        $_POST['atm_unique_code'] = $data['code'];
        $_POST['atm_point']       = (int)$data['point'];
        $_POST['atm_date']        = $data['date'];
        error_log('[ATM] $_POST set: code=' . $data['code'] . ', point=' . $data['point']);
    } else {
        error_log('[ATM] Decrypt failed, setting invalid marker');
        $_POST['atm_unique_code'] = 'TOKEN_TIDAK_VALID';
        $_POST['atm_point']       = 0;
        $_POST['atm_date']        = '';
    }
}, -100); // Priority -100 (jalan sangat awal)

add_filter('forminator_field_hidden_field_value', function ($value, $saved_value, $field, $hidden_field) {
    if ($saved_value !== 'custom_value') {
        return $value;
    }

    if ($value === '{unique_code}') {
        $result = isset($_POST['atm_unique_code']) ? $_POST['atm_unique_code'] : '';
        error_log('[ATM] Filter unique_code: ' . $result);
        return $result;
    }

    if ($value === '{point}') {
        $result = isset($_POST['atm_point']) ? (string)$_POST['atm_point'] : '';
        error_log('[ATM] Filter point: ' . $result);
        return $result;
    }

    if ($value === '{date}') {
        $result = isset($_POST['atm_date']) ? $_POST['atm_date'] : '';
        error_log('[ATM] Filter date: ' . $result);
        return $result;
    }

    return $value;
}, 99, 4);