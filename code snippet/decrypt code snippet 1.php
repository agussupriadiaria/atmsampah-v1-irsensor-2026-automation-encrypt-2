<?php
define('ATM_ENCRYPTION_KEY', base64_decode('eceiBSuVret8nOgzxcqhS/iFeiyMZ0zTC266ONR+Byg='));
define('ATM_HMAC_KEY', base64_decode('7eei2G7D148OsMLRhKt/1+BmqlFmVaVoWBaXSOS+lBM='));

function atm_decrypt_data($token) {
    $bytes = base64_decode(strtr($token, '-_', '+/'));

    if ($bytes === false || strlen($bytes) < 64) {
        return false;
    }

    $iv         = substr($bytes, 0, 16);
    $mac        = substr($bytes, -32);
    $ciphertext = substr($bytes, 16, -32);

    $calc_mac = hash_hmac('sha256', $iv . $ciphertext, ATM_HMAC_KEY, true);
    if (!hash_equals($mac, $calc_mac)) {
        return false;
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', ATM_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        return false;
    }

    $data = json_decode($plain, true);
    if (!isset($data['code'])) {
        return false;
    }

    return [
        'code'  => $data['code'],
        'point' => $data['point'] ?? '',
        'date'  => $data['date'] ?? '',
    ];
}

add_action('wp', function () {
    if (!isset($_GET['token'])) {
        return;
    }

    $data = atm_decrypt_data(sanitize_text_field($_GET['token']));

    if ($data !== false) {
        $_POST['atm_unique_code'] = $data['code'];
        $_POST['atm_point']       = $data['point'];
        $_POST['atm_date']        = $data['date'];
    } else {
        $_POST['atm_unique_code'] = 'TOKEN_TIDAK_VALID';
        $_POST['atm_point']       = '';
        $_POST['atm_date']        = '';
    }
});

add_filter('forminator_field_hidden_field_value', function ($value, $saved_value, $field, $hidden_field) {
    if ($saved_value !== 'custom_value') {
        return $value;
    }

    if ($value === '{unique_code}') {
        return isset($_POST['atm_unique_code']) ? $_POST['atm_unique_code'] : '';
    }

    if ($value === '{point}') {
        return isset($_POST['atm_point']) ? $_POST['atm_point'] : '';
    }

    if ($value === '{date}') {
        return isset($_POST['atm_date']) ? $_POST['atm_date'] : '';
    }

    return $value;
}, 99, 4);