<?php
require __DIR__ . '/../db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);

$d     = json_in();
$token = (string)($d['token'] ?? '');
$tipe  = strtoupper((string)($d['tipe'] ?? 'LOC'));
if (!in_array($tipe, ['LOC', 'ARRIVED', 'STOP'], true))
    json_out(['ok' => false, 'error' => 'Tipe tidak valid'], 422);

$conn = db();

$s = oci_parse($conn, "SELECT ID FROM VIP_PASIEN WHERE TOKEN = :t AND AKTIF = 1");
oci_bind_by_name($s, ':t', $token);
if (!@oci_execute($s)) {
    error_log('share_location token: ' . print_r(oci_error($s), true));
    json_out(['ok' => false, 'error' => 'Terjadi kesalahan server'], 500);
}
$p = oci_fetch_assoc($s);
if (!$p) json_out(['ok' => false, 'error' => 'Token tidak dikenal'], 401);
$pid = $p['ID'];

// Baris terakhir pasien ini
$q = oci_parse($conn, "SELECT LAT, LNG, AKURASI_M, TIPE,
                              ROUND((SYSDATE - CREATED_AT) * 86400) AS DETIK
                         FROM VIP_LOKASI
                        WHERE ID = (SELECT MAX(ID) FROM VIP_LOKASI WHERE PASIEN_ID = :p)");
oci_bind_by_name($q, ':p', $pid);
oci_execute($q);
$last = oci_fetch_assoc($q);

$lat = filter_var($d['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($d['lng'] ?? null, FILTER_VALIDATE_FLOAT);
$akr = isset($d['akurasi']) && is_numeric($d['akurasi']) ? round((float)$d['akurasi'], 1) : null;
$koordOk = $lat !== false && $lng !== false && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;

if ($tipe === 'STOP') {
    if (!$last) json_out(['ok' => true]);                       
    if ($last['TIPE'] === 'ARRIVED') json_out(['ok' => true]);  
    if (!$koordOk) { $lat = (float)$last['LAT']; $lng = (float)$last['LNG']; $akr = $last['AKURASI_M']; }
} else {
    if (!$koordOk) json_out(['ok' => false, 'error' => 'Koordinat tidak valid'], 422);
    // throttle: abaikan titik biasa kalau titik biasa sebelumnya < 10 detik
    if ($tipe === 'LOC' && $last && $last['TIPE'] === 'LOC' && (int)$last['DETIK'] < 10)
        json_out(['ok' => true, 'throttled' => true]);
}

$i = oci_parse($conn, "INSERT INTO VIP_LOKASI (ID, PASIEN_ID, LAT, LNG, AKURASI_M, TIPE)
                       VALUES (SEQ_VIP_LOKASI.NEXTVAL, :p, :lat, :lng, :ak, :tp)");
oci_bind_by_name($i, ':p',   $pid);
oci_bind_by_name($i, ':lat', $lat);
oci_bind_by_name($i, ':lng', $lng);
oci_bind_by_name($i, ':ak',  $akr);
oci_bind_by_name($i, ':tp',  $tipe);
if (!@oci_execute($i)) {
    error_log('share_location simpan: ' . print_r(oci_error($i), true));
    json_out(['ok' => false, 'error' => 'Gagal menyimpan lokasi'], 500);
}
json_out(['ok' => true]);