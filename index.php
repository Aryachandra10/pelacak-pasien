<?php
/**
 * index.php — Backend Beranda RS Pelabuhan Jakarta (IHC RSPJ)
 * ------------------------------------------------------------
 * Endpoint ini HANYA mengembalikan data JSON untuk frontend.
 * Frontend HTML/CSS/JS dipisah ke file .html
 *
 * Contoh pemakaian:
 *   GET  index.php              → info hospital + menu
 *   GET  index.php?action=stats → statistik pasien (opsional)
 */
declare(strict_types=1);

// =========================================================
// 1. HEADER & ERROR HANDLING
// =========================================================
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

ini_set('display_errors', '0');
error_reporting(E_ALL);

// =========================================================
// 2. SESSION & CSRF
// =========================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// =========================================================
// 3. KONFIGURASI RUMAH SAKIT
// =========================================================
$HOSPITAL = [
    'name'           => 'RS Pelabuhan Jakarta',
    'tagline'        => 'IHC RSPJ — Layanan Kesehatan Terpadu',
    'hotlineDisplay' => '021-4300000',
    'hotlineE164'    => '+62214300000',
    'whatsappE164'   => '6281234567890',
    'address'        => 'Jl. Pelabuhan No. 1, Jakarta',
    'open24h'        => true,
];

// =========================================================
// 4. ROUTING SEDERHANA
// =========================================================
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'info';

try {
    switch ($action) {

        // ---------------------------------------------
        // GET index.php?action=info  (default)
        // ---------------------------------------------
        case 'info':
            respond([
                'ok'       => true,
                'hospital' => $HOSPITAL,
                'menu'     => [
                    [
                        'id'    => 'patient',
                        'label' => 'Bagikan Lokasi Pasien',
                        'desc'  => 'Kirim posisi Anda ke petugas untuk penanganan cepat',
                        'icon'  => '📍',
                        'url'   => 'patient.php',
                    ],
                    [
                        'id'    => 'schedule',
                        'label' => 'Jadwal Berobat',
                        'desc'  => 'Lihat & kelola jadwal kunjungan Anda',
                        'icon'  => '📅',
                        'url'   => 'jadwal.php',
                    ],
                    [
                        'id'    => 'hotline',
                        'label' => 'Hotline Darurat',
                        'desc'  => $HOSPITAL['hotlineDisplay'] . ' · 24 jam',
                        'icon'  => '📞',
                        'url'   => 'tel:' . $HOSPITAL['hotlineE164'],
                    ],
                ],
                'csrf'     => $csrf,
            ]);
            break;

        // ---------------------------------------------
        // GET index.php?action=stats  (opsional)
        // ---------------------------------------------
        case 'stats':
            // Placeholder — ganti dengan query DB nyata
            respond([
                'ok'    => true,
                'stats' => [
                    'total_pasien_hari_ini' => 0,
                    'sedang_dibagikan'      => 0,
                    'jadwal_minggu_ini'     => 0,
                ],
            ]);
            break;

        // ---------------------------------------------
        // Fallback
        // ---------------------------------------------
        default:
            respondError('Action tidak dikenal.', 404);
    }

} catch (Throwable $e) {
    error_log('[index.php] ' . $e->getMessage());
    respondError('Terjadi kesalahan pada server.', 500);
}

// =========================================================
// 5. HELPER RESPONSE
// =========================================================
function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode([
        'ok'      => false,
        'error'   => $message,
        'code'    => $code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}