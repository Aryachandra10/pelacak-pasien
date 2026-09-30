<?php
/**
 * patient.php — Backend Bagikan Lokasi Pasien RS Pelabuhan Jakarta (IHC RSPJ)
 * ---------------------------------------------------------------------------
 * Endpoint ini HANYA mengembalikan data JSON untuk frontend.
 * Frontend HTML/CSS/JS dipisah ke file .html
 *
 * Contoh pemakaian:
 *   GET  patient.php?action=config
 *   GET  patient.php?action=schedules&name=Budi
 *   POST patient.php?action=save          (JSON body)
 *   POST patient.php?action=share-start
 *   POST patient.php?action=share-stop
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
];

// =========================================================
// 4. STORAGE PATH (ganti ke DB di production)
// =========================================================
$STORAGE_DIR  = __DIR__ . '/../storage';
$STORAGE_FILE = $STORAGE_DIR . '/jadwal_berobat.json';

if (!is_dir($STORAGE_DIR)) {
    @mkdir($STORAGE_DIR, 0775, true);
}
if (!file_exists($STORAGE_FILE)) {
    @file_put_contents($STORAGE_FILE, json_encode([]));
}

// =========================================================
// 5. ROUTING
// =========================================================
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

try {
    switch ($action) {

        // ---------------------------------------------
        // GET patient.php?action=config
        // ---------------------------------------------
        case 'config':
            if ($method !== 'GET') respondError('Method harus GET.', 405);
            respond([
                'ok'       => true,
                'hospital' => $HOSPITAL,
                'csrf'     => $csrf,
                'priority' => [
                    ['value' => 'merah',  'label' => 'Merah - gawat darurat'],
                    ['value' => 'kuning', 'label' => 'Kuning - segera'],
                    ['value' => 'hijau',  'label' => 'Hijau - tidak mendesak'],
                ],
            ]);
            break;

        // ---------------------------------------------
        // GET patient.php?action=schedules&name=Budi
        // ---------------------------------------------
        case 'schedules':
            if ($method !== 'GET') respondError('Method harus GET.', 405);

            $name = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
            $all  = loadSchedules($STORAGE_FILE);

            // Filter by name (case-insensitive, partial match)
            $filtered = $name === ''
                ? $all
                : array_values(array_filter($all, function ($item) use ($name) {
                    $itemName = isset($item['name']) ? (string)$item['name'] : '';
                    return mb_stripos($itemName, $name) !== false;
                }));

            // Filter yang dalam 7 hari ke depan
            $upcoming = array_values(array_filter($filtered, function ($item) {
                if (empty($item['date'])) return false;
                $diff = daysUntil((string)$item['date']);
                return $diff !== null && $diff >= 0 && $diff <= 7;
            }));

            // Sort by date + time
            usort($upcoming, function ($a, $b) {
                $da = ($a['date'] ?? '') . ' ' . ($a['time'] ?? '');
                $db = ($b['date'] ?? '') . ' ' . ($b['time'] ?? '');
                return strcmp($da, $db);
            });

            respond([
                'ok'        => true,
                'total'     => count($upcoming),
                'schedules' => $upcoming,
            ]);
            break;

        // ---------------------------------------------
        // POST patient.php?action=save
        // Body JSON: { name, date, time, place, note }
        // ---------------------------------------------
        case 'save':
            if ($method !== 'POST') respondError('Method harus POST.', 405);
            verifyCsrf($csrf);

            $input = readJsonBody();

            $name  = sanitize($input['name']  ?? '', 80);
            $date  = sanitize($input['date']  ?? '', 10);   // YYYY-MM-DD
            $time  = sanitize($input['time']  ?? '', 5);    // HH:MM
            $place = sanitize($input['place'] ?? '', 120);
            $note  = sanitize($input['note']  ?? '', 250);

            if ($name === '')                       respondError('Nama wajib diisi.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respondError('Format tanggal tidak valid (YYYY-MM-DD).');
            if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) respondError('Format jam tidak valid (HH:MM).');

            $all = loadSchedules($STORAGE_FILE);
            $all[] = [
                'id'        => bin2hex(random_bytes(8)),
                'name'      => $name,
                'date'      => $date,
                'time'      => $time,
                'place'     => $place,
                'note'      => $note,
                'created_at'=> date('c'),
            ];

            if (!saveSchedules($STORAGE_FILE, $all)) {
                respondError('Gagal menyimpan jadwal.', 500);
            }

            respond(['ok' => true, 'message' => 'Jadwal berhasil disimpan.']);
            break;

        // ---------------------------------------------
        // POST patient.php?action=share-start
        // Body JSON: { name, priority, lat, lng }
        // ---------------------------------------------
        case 'share-start':
            if ($method !== 'POST') respondError('Method harus POST.', 405);
            verifyCsrf($csrf);

            $input = readJsonBody();

            $name     = sanitize($input['name']     ?? '', 80);
            $priority = sanitize($input['priority'] ?? 'hijau', 20);
            $lat      = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng      = isset($input['lng']) ? (float)$input['lng'] : null;

            if ($name === '') respondError('Nama wajib diisi.');
            if (!in_array($priority, ['merah', 'kuning', 'hijau'], true)) {
                respondError('Prioritas tidak valid.');
            }

            // Simpan sesi share lokasi
            $_SESSION['share'] = [
                'name'      => $name,
                'priority'  => $priority,
                'lat'       => $lat,
                'lng'       => $lng,
                'started_at'=> time(),
                'active'    => true,
            ];

            respond([
                'ok'        => true,
                'message'   => 'Berbagi lokasi dimulai.',
                'session'   => $_SESSION['share'],
            ]);
            break;

        // ---------------------------------------------
        // POST patient.php?action=share-stop
        // ---------------------------------------------
        case 'share-stop':
            if ($method !== 'POST') respondError('Method harus POST.', 405);
            verifyCsrf($csrf);

            if (isset($_SESSION['share'])) {
                $_SESSION['share']['active'] = false;
                $_SESSION['share']['stopped_at'] = time();
            }

            respond(['ok' => true, 'message' => 'Berbagi lokasi dihentikan.']);
            break;

        // ---------------------------------------------
        // Fallback
        // ---------------------------------------------
        default:
            respondError('Action tidak dikenal.', 404);
    }

} catch (Throwable $e) {
    error_log('[patient.php] ' . $e->getMessage());
    respondError('Terjadi kesalahan pada server.', 500);
}

// =========================================================
// 6. HELPER FUNCTIONS
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
        'ok'    => false,
        'error' => $message,
        'code'  => $code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verifikasi CSRF token dari header X-CSRF-Token
 */
function verifyCsrf(string $expected): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($expected, (string)$sent)) {
        respondError('CSRF token tidak valid.', 403);
    }
}

/**
 * Baca JSON body dari php://input
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Sanitasi string input
 */
function sanitize($value, int $maxLen = 100): string
{
    $value = is_string($value) ? $value : '';
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value); // buang control chars
    if (mb_strlen($value) > $maxLen) {
        $value = mb_substr($value, 0, $maxLen);
    }
    return $value;
}

/**
 * Hitung selisih hari dari hari ini ke tanggal target (YYYY-MM-DD)
 */
function daysUntil(string $isoDate): ?int
{
    $target = DateTime::createFromFormat('Y-m-d', $isoDate);
    if (!$target) return null;
    $target->setTime(0, 0, 0);
    $today = new DateTime('today');
    return (int)$today->diff($target)->format('%r%a');
}

/**
 * Load jadwal dari file JSON
 */
function loadSchedules(string $file): array
{
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Simpan jadwal ke file JSON (atomic write)
 */
function saveSchedules(string $file, array $data): bool
{
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $file);
}