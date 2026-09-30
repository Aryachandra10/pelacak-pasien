<?php
/**
 * =====================================================================
 *  jadwal_berobat.php  —  BACKEND / REST API
 *  Modul Jadwal Berobat Pasien — RS Pelabuhan Jakarta
 * =====================================================================
 *
 *  DUA MODE PEMAKAIAN:
 *
 *  [1] MODE API (default) — dipanggil langsung via fetch/AJAX:
 *        GET    jadwal_berobat.php              → list semua jadwal
 *        GET    jadwal_berobat.php?id=123       → detail 1 jadwal
 *        POST   jadwal_berobat.php              → tambah jadwal
 *        PUT    jadwal_berobat.php?id=123       → update jadwal
 *        DELETE jadwal_berobat.php?id=123       → hapus jadwal
 *
 *      Body JSON (POST/PUT):
 *        {
 *          "name"  : "Budi Santoso",
 *          "date"  : "2025-01-20",
 *          "time"  : "09:00",
 *          "place" : "Poli Umum",
 *          "note"  : "Bawa hasil lab"
 *        }
 *
 *  [2] MODE LIBRARY — di-include dari file lain:
 *        require_once 'jadwal_berobat.php';
 *        $rows = jadwal_list();
 *        $id   = jadwal_create([...]);
 *        jadwal_update($id, [...]);
 *        jadwal_delete($id);
 *
 *  KONFIGURASI DB: sesuaikan konstanta di bawah, atau set lewat
 *  environment variable (JADWAL_DB_*).
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 * CEGAH AKSES LANGSUNG SAAT DI-INCLUDE
 * ------------------------------------------------------------------- */
if (!defined('JADWAL_BEROBAT_LIB')) {
    define('JADWAL_BEROBAT_LIB', true);
    $__JADWAL_AS_API = true;   // dipanggil langsung → jalankan sebagai API
} else {
    $__JADWAL_AS_API = false;  // di-include → hanya sediakan fungsi
}

/* ---------------------------------------------------------------------
 * KONFIGURASI DATABASE
 * ------------------------------------------------------------------- */
if (!defined('JADWAL_DB_HOST')) define('JADWAL_DB_HOST', getenv('JADWAL_DB_HOST') ?: 'localhost');
if (!defined('JADWAL_DB_NAME')) define('JADWAL_DB_NAME', getenv('JADWAL_DB_NAME') ?: 'rspelabuhan');
if (!defined('JADWAL_DB_USER')) define('JADWAL_DB_USER', getenv('JADWAL_DB_USER') ?: 'root');
if (!defined('JADWAL_DB_PASS')) define('JADWAL_DB_PASS', getenv('JADWAL_DB_PASS') ?: '');
if (!defined('JADWAL_DB_CHARSET')) define('JADWAL_DB_CHARSET', 'utf8mb4');

/* Nama tabel & zona waktu */
if (!defined('JADWAL_TABLE'))   define('JADWAL_TABLE', 'jadwal_berobat');
if (!defined('JADWAL_TZ'))      define('JADWAL_TZ', 'Asia/Jakarta');

date_default_timezone_set(JADWAL_TZ);

/* ---------------------------------------------------------------------
 * KONEKSI PDO (singleton)
 * ------------------------------------------------------------------- */
function jadwal_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . JADWAL_DB_HOST
         . ';dbname='    . JADWAL_DB_NAME
         . ';charset='   . JADWAL_DB_CHARSET;

    try {
        $pdo = new PDO($dsn, JADWAL_DB_USER, JADWAL_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Koneksi DB gagal: ' . $e->getMessage(), 500);
    }
    return $pdo;
}

/* ---------------------------------------------------------------------
 * AUTO-MIGRATE — buat tabel jika belum ada
 * (opsional; comment kalau pakai migration manual)
 * ------------------------------------------------------------------- */
function jadwal_ensure_table(): void
{
    static $done = false;
    if ($done) return;

    $sql = 'CREATE TABLE IF NOT EXISTS `' . JADWAL_TABLE . '` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(120)  NOT NULL,
        `date`       DATE          NOT NULL,
        `time`       TIME          NULL,
        `place`      VARCHAR(180)  NOT NULL,
        `note`       TEXT          NULL,
        `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

    jadwal_db()->exec($sql);
    $done = true;
}

/* ---------------------------------------------------------------------
 * VALIDASI INPUT
 * ------------------------------------------------------------------- */
function jadwal_validate(array $in, bool $isUpdate = false): array
{
    $errors = [];

    $name  = trim((string)($in['name']  ?? ''));
    $date  = trim((string)($in['date']  ?? ''));
    $time  = trim((string)($in['time']  ?? ''));
    $place = trim((string)($in['place'] ?? ''));
    $note  = trim((string)($in['note']  ?? ''));

    if ($name === '')                          $errors['name']  = 'Nama pasien wajib diisi.';
    elseif (mb_strlen($name) > 120)            $errors['name']  = 'Nama maksimal 120 karakter.';

    if ($date === '')                          $errors['date']  = 'Tanggal berobat wajib diisi.';
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors['date'] = 'Format tanggal harus YYYY-MM-DD.';
    else {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) $errors['date'] = 'Tanggal tidak valid.';
    }

    if ($time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        $errors['time'] = 'Format jam harus HH:MM.';
    }

    if ($place === '')                         $errors['place'] = 'Tempat / poli wajib diisi.';
    elseif (mb_strlen($place) > 180)           $errors['place'] = 'Tempat maksimal 180 karakter.';

    if (mb_strlen($note) > 1000)               $errors['note']  = 'Catatan maksimal 1000 karakter.';

    if ($errors) {
        throw new InvalidArgumentException(json_encode($errors));
    }

    return [
        'name'  => $name,
        'date'  => $date,
        'time'  => $time !== '' ? $time : null,
        'place' => $place,
        'note'  => $note !== '' ? $note : null,
    ];
}

/* ---------------------------------------------------------------------
 * CRUD — dipakai baik di mode library maupun API
 * ------------------------------------------------------------------- */
function jadwal_list(?string $from = null, ?string $to = null, int $limit = 200, int $offset = 0): array
{
    jadwal_ensure_table();

    $sql    = 'SELECT * FROM `' . JADWAL_TABLE . '` WHERE 1=1';
    $params = [];

    if ($from !== null) { $sql .= ' AND `date` >= :from'; $params[':from'] = $from; }
    if ($to   !== null) { $sql .= ' AND `date` <= :to';   $params[':to']   = $to; }

    $sql .= ' ORDER BY `date` ASC, `time` ASC, `id` ASC LIMIT :lim OFFSET :off';

    $stmt = jadwal_db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);

    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);

    $stmt->execute();
    return $stmt->fetchAll();
}

function jadwal_get(int $id): ?array
{
    jadwal_ensure_table();

    $stmt = jadwal_db()->prepare('SELECT * FROM `' . JADWAL_TABLE . '` WHERE `id` = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function jadwal_create(array $in): int
{
    jadwal_ensure_table();
    $data = jadwal_validate($in);

    $sql = 'INSERT INTO `' . JADWAL_TABLE . '`
              (`name`, `date`, `time`, `place`, `note`)
            VALUES (:name, :date, :time, :place, :note)';

    $stmt = jadwal_db()->prepare($sql);
    $stmt->execute([
        ':name'  => $data['name'],
        ':date'  => $data['date'],
        ':time'  => $data['time'],
        ':place' => $data['place'],
        ':note'  => $data['note'],
    ]);
    return (int)jadwal_db()->lastInsertId();
}

function jadwal_update(int $id, array $in): bool
{
    jadwal_ensure_table();
    if (!jadwal_get($id)) {
        throw new RuntimeException('Jadwal tidak ditemukan.', 404);
    }
    $data = jadwal_validate($in, true);

    $sql = 'UPDATE `' . JADWAL_TABLE . '` SET
              `name`  = :name,
              `date`  = :date,
              `time`  = :time,
              `place` = :place,
              `note`  = :note
            WHERE `id` = :id';

    $stmt = jadwal_db()->prepare($sql);
    return $stmt->execute([
        ':name'  => $data['name'],
        ':date'  => $data['date'],
        ':time'  => $data['time'],
        ':place' => $data['place'],
        ':note'  => $data['note'],
        ':id'    => $id,
    ]);
}

function jadwal_delete(int $id): bool
{
    jadwal_ensure_table();
    $stmt = jadwal_db()->prepare('DELETE FROM `' . JADWAL_TABLE . '` WHERE `id` = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------------
 * API RUNNER — hanya dijalankan kalau file dipanggil langsung
 * ------------------------------------------------------------------- */
function jadwal_api_run(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    /* CORS sederhana — sesuaikan origin jika perlu */
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    /* Helper response */
    $respond = function (int $code, array $payload): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    /* Baca body JSON */
    $readJson = function (): array {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Body bukan JSON yang valid.');
        }
        return is_array($decoded) ? $decoded : [];
    };

    try {
        switch ($method) {

            /* ---------------- GET ---------------- */
            case 'GET':
                if ($id > 0) {
                    $row = jadwal_get($id);
                    if (!$row) $respond(404, ['ok' => false, 'error' => 'Jadwal tidak ditemukan.']);
                    $respond(200, ['ok' => true, 'data' => $row]);
                }
                $from  = $_GET['from']  ?? null;
                $to    = $_GET['to']    ?? null;
                $limit = isset($_GET['limit'])  ? max(1, min(500, (int)$_GET['limit']))  : 200;
                $off   = isset($_GET['offset']) ? max(0, (int)$_GET['offset'])           : 0;

                $rows = jadwal_list($from, $to, $limit, $off);
                $respond(200, ['ok' => true, 'count' => count($rows), 'data' => $rows]);
                break;

            /* ---------------- POST ---------------- */
            case 'POST':
                $body = $readJson();
                $newId = jadwal_create($body);
                $row = jadwal_get($newId);
                $respond(201, ['ok' => true, 'message' => 'Jadwal berhasil ditambahkan.', 'data' => $row]);
                break;

            /* ---------------- PUT ---------------- */
            case 'PUT':
                if ($id <= 0) $respond(400, ['ok' => false, 'error' => 'Parameter id wajib diisi.']);
                $body = $readJson();
                jadwal_update($id, $body);
                $row = jadwal_get($id);
                $respond(200, ['ok' => true, 'message' => 'Jadwal berhasil diperbarui.', 'data' => $row]);
                break;

            /* ---------------- DELETE ---------------- */
            case 'DELETE':
                if ($id <= 0) $respond(400, ['ok' => false, 'error' => 'Parameter id wajib diisi.']);
                $deleted = jadwal_delete($id);
                if (!$deleted) $respond(404, ['ok' => false, 'error' => 'Jadwal tidak ditemukan.']);
                $respond(200, ['ok' => true, 'message' => 'Jadwal berhasil dihapus.']);
                break;

            default:
                $respond(405, ['ok' => false, 'error' => 'Method tidak didukung.']);
        }
    } catch (InvalidArgumentException $e) {
        /* errors validasi disimpan sebagai JSON string */
        $msg = $e->getMessage();
        $decoded = json_decode($msg, true);
        $respond(422, [
            'ok'     => false,
            'error'  => 'Validasi gagal.',
            'fields' => is_array($decoded) ? $decoded : $msg,
        ]);
    } catch (RuntimeException $e) {
        $code = $e->getCode() ?: 500;
        $respond((int)$code, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[jadwal_berobat] ' . $e->getMessage());
        $respond(500, ['ok' => false, 'error' => 'Kesalahan server internal.']);
    }
}

/* ---------------------------------------------------------------------
 * AUTO-RUN SAAT DIPANGGIL LANGSUNG
 * ------------------------------------------------------------------- */
if ($__JADWAL_AS_API) {
    jadwal_api_run();
}