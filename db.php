<?php


define('DB_USER', 'tugu');
define('DB_PASS', 'logon');
define('DB_DSN', '(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=192.168.2.211)(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=orcl)))');

function db() {
    static $conn = null;
    if ($conn === null) {
        if (!function_exists('oci_connect')) {
            error_log('OCI8 belum aktif di PHP ini');
            json_out(['ok' => false, 'error' => 'Koneksi database gagal'], 500);
        }
        $conn = @oci_connect(DB_USER, DB_PASS, DB_DSN, 'AL32UTF8');
        if (!$conn) {
            error_log('oci_connect gagal: ' . print_r(oci_error(), true));
            json_out(['ok' => false, 'error' => 'Koneksi database gagal'], 500);
        }
    }
    return $conn;
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function json_in() {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function new_token() {
    return function_exists('random_bytes')
        ? bin2hex(random_bytes(32))
        : bin2hex(openssl_random_pseudo_bytes(32));
}
