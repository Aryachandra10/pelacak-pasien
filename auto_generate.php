<?php
/**
 * auto_generate.php — Auto-generator jadwal berobat
 * -----------------------------------------------------------------------
 * Baca master pasien dari patients.json, generate jadwal ke jadwal_berobat.json.
 * Nama pasien TIDAK ditulis di sini — otomatis diambil dari patients.json.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');

$STORAGE_DIR    = __DIR__ . '/../storage';
$PATIENTS_FILE  = $STORAGE_DIR . '/patients.json';
$SCHEDULES_FILE = $STORAGE_DIR . '/jadwal_berobat.json';
$HORIZON_DAYS   = 60;

if (!is_dir($STORAGE_DIR)) @mkdir($STORAGE_DIR, 0775, true);

try {
    $result = autoGenerate($PATIENTS_FILE, $SCHEDULES_FILE, $HORIZON_DAYS);
    if (PHP_SAPI === 'cli') {
        echo sprintf(
            "[OK] %d pasien → %d jadwal (%d auto + %d manual) horizon %d hari\n",
            $result['patients'], $result['auto'], $result['auto'],
            $result['manual'], $HORIZON_DAYS
        );
    }
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    throw $e;
}

// =========================================================
function autoGenerate(string $patientsFile, string $outputFile, int $horizonDays): array
{
    $patients = loadJson($patientsFile);
    if (empty($patients)) {
        throw new RuntimeException("Tidak ada pasien di {$patientsFile}");
    }

    $today = new DateTime('today');
    $limit = (clone $today)->modify("+{$horizonDays} days");

    // Pertahankan entri manual (source != 'auto')
    $existing = array_values(array_filter(loadJson($outputFile), function ($row) {
        return ($row['source'] ?? '') !== 'auto';
    }));

    $auto = [];
    foreach ($patients as $p) {
        $name  = trim((string)($p['name']  ?? ''));
        $poli  = trim((string)($p['poli']  ?? ''));
        $time  = trim((string)($p['time']  ?? ''));
        $note  = trim((string)($p['note']  ?? ''));
        $ctrl  = is_array($p['control'] ?? null) ? $p['control'] : ['type' => 'once'];

        if ($name === '') continue; // skip pasien tanpa nama

        $dates = expandControl($ctrl, $today, $limit);
        foreach ($dates as $isoDate) {
            $auto[] = [
                'id'         => substr(md5("{$name}|{$isoDate}|{$time}|{$poli}"), 0, 16),
                'name'       => $name,
                'date'       => $isoDate,
                'time'       => $time,
                'place'      => $poli,
                'note'       => $note,
                'created_at' => date('c'),
                'source'     => 'auto',
            ];
        }
    }

    $all = array_merge($existing, $auto);
    usort($all, fn($a, $b) =>
        strcmp(($a['date'] ?? '') . ' ' . ($a['time'] ?? ''),
               ($b['date'] ?? '') . ' ' . ($b['time'] ?? ''))
    );

    // Atomic write
    $tmp  = $outputFile . '.tmp';
    $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('Gagal encode JSON.');
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Gagal tulis tmp.');
    if (!rename($tmp, $outputFile)) throw new RuntimeException('Gagal rename.');

    return [
        'patients' => count($patients),
        'auto'     => count($auto),
        'manual'   => count($existing),
    ];
}

/**
 * Terjemahkan "control" pasien menjadi daftar tanggal.
 */
function expandControl(array $c, DateTime $start, DateTime $end): array
{
    $type = $c['type'] ?? 'once';
    $dates = [];

    switch ($type) {
        case 'daily':
            $stepDays = max(1, (int)($c['interval'] ?? 1));
            for ($d = clone $start; $d <= $end; $d->modify("+{$stepDays} day")) {
                $dates[] = $d->format('Y-m-d');
            }
            break;

        case 'weekly':
            $days = array_map('intval', (array)($c['days_of_week'] ?? [1]));
            for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
                if (in_array((int)$d->format('N'), $days, true)) {
                    $dates[] = $d->format('Y-m-d');
                }
            }
            break;

        case 'monthly':
            $dom = max(1, (int)($c['day_of_month'] ?? 1));
            $m   = (clone $start)->modify('first day of this month');
            while ($m <= $end) {
                $max = (int)$m->format('t');
                $day = min($dom, $max);
                $cand = new DateTime(sprintf('%04d-%02d-%02d',
                    (int)$m->format('Y'), (int)$m->format('n'), $day));
                if ($cand >= $start && $cand <= $end) $dates[] = $cand->format('Y-m-d');
                $m->modify('+1 month');
            }
            break;

        case 'once':
        default:
            $iso = $c['date'] ?? null;
            if ($iso) {
                $d = DateTime::createFromFormat('Y-m-d', $iso);
                if ($d && $d >= $start && $d <= $end) $dates[] = $d->format('Y-m-d');
            }
            break;
    }

    return $dates;
}

function loadJson(string $file): array
{
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}