<?php
// config.php — pengganti js/config.js
// Menghasilkan JavaScript `window.CONFIG`, dipanggil lewat <script src="config.php"></script>

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

// Ubah sesuai kebutuhan.
$config = [
    // Tujuan pasien (rumah sakit / faskes)
    'HOSPITAL' => [
        'name' => 'RS Pelabuhan Jakarta',
        'lat'  => -6.125022,
        'lng'  => 106.917765,
    ],

    // Kosongkan = mode demo (antar-tab di satu browser, tanpa server).
    // Isi untuk mode live sungguhan, mis. "ws://localhost:8080" atau "wss://domain-anda.com/ws"
    'WS_URL' => '',

    'STALE_MS'  => 30000, // lokasi dianggap tidak live jika tak ada update selama ini
    'SPEED_KMH' => 30,    // asumsi kecepatan untuk estimasi tiba (garis lurus)
];

echo 'window.CONFIG = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . ';';