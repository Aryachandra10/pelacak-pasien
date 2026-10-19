<?php

require __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);

$d    = json_in();
$nama = trim(preg_replace('/\s+/', ' ', $d['nama'] ?? ''));
$nik  = preg_replace('/\D/', '', $d['nik'] ?? '');

// 1. Validasi input
if ($nama === '' || mb_strlen($nama) > 150) json_out(['ok' => false, 'error' => 'Nama tidak valid'], 422);
if (strlen($nik) !== 16)                    json_out(['ok' => false, 'error' => 'NIK harus 16 digit'], 422);
if (empty($d['setuju']))                    json_out(['ok' => false, 'error' => 'Persetujuan diperlukan'], 422);

$conn = db();

// 2. Cek pasien prioritas: NIK -> PASIEN -> REGISTRASI_RJ -> PELANGGAN_PRIORITAS
$sql = "SELECT TRIM(pr.KODE_PELANGGAN) AS KODE_PELANGGAN,
               pr.NAMA_PELANGGAN,
               x.NAMA,
               x.ID_WA
          FROM (
                SELECT r.KODE_PELANGGAN, ps.NAMA, ps.ID_WA
                  FROM TUGU.PASIEN ps
                  JOIN TUGU.REGISTRASI_RJ r ON r.NO_RM = ps.KODE_PASIEN
                 WHERE ps.NO_KTP = :nik
                   AND r.KODE_PELANGGAN IS NOT NULL
                 ORDER BY r.TANGGAL_REGISTRASI DESC, r.NO_REGISTRASI DESC
               ) x
          JOIN PELANGGAN_PRIORITAS pr
            ON TRIM(pr.KODE_PELANGGAN) = TRIM(x.KODE_PELANGGAN)
         WHERE ROWNUM = 1";

$e = oci_parse($conn, $sql);
oci_bind_by_name($e, ':nik', $nik);
if (!@oci_execute($e)) {
    error_log('register.php cek prioritas: ' . print_r(oci_error($e), true));
    json_out(['ok' => false, 'error' => 'Terjadi kesalahan server'], 500);
}
$prio = oci_fetch_assoc($e);

if (!$prio) {
    json_out(['ok' => false,
              'error' => 'Data Anda tidak ditemukan sebagai pasien prioritas. Hubungi petugas RS.'], 403);
}
$kodePel = $prio['KODE_PELANGGAN'];
$nama   = $prio['NAMA'];

// 3. Sudah terdaftar di VIP_PASIEN?
$s = oci_parse($conn, "SELECT ID, AKTIF FROM VIP_PASIEN WHERE NIK = :nik");
oci_bind_by_name($s, ':nik', $nik);
oci_execute($s);
$row = oci_fetch_assoc($s);

if ($row && (int)$row['AKTIF'] !== 1) {
    json_out(['ok' => false, 'error' => 'Akses dinonaktifkan. Hubungi petugas RS.'], 403);
}

$token = new_token();

if ($row) {
    $id = $row['ID'];
    $q = oci_parse($conn, "UPDATE VIP_PASIEN
                              SET TOKEN = :t, NAMA = :n, KODE_PELANGGAN = :kp
                            WHERE ID = :id");
    oci_bind_by_name($q, ':id', $id);
} else {
    $q = oci_parse($conn, "INSERT INTO VIP_PASIEN (ID, NAMA, NIK, TOKEN, KODE_PELANGGAN)
                           VALUES (SEQ_VIP_PASIEN.NEXTVAL, :n, :nik, :t, :kp)");
    oci_bind_by_name($q, ':nik', $nik);
}
oci_bind_by_name($q, ':t',  $token);
oci_bind_by_name($q, ':n',  $nama);
oci_bind_by_name($q, ':kp', $kodePel);

if (!@oci_execute($q)) {
    error_log('register.php simpan: ' . print_r(oci_error($q), true));
    json_out(['ok' => false, 'error' => 'Gagal menyimpan'], 500);
}

json_out([
    'ok'        => true,
    'token'     => $token,
    'nama'      => $nama,
    'id_wa'     => trim((string)($prio['ID_WA'] ?? '')),
    'perusahaan'=> $prio['NAMA_PELANGGAN'],
]);