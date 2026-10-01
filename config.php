<?php
// config.php — pengganti js/config.js
// Menghasilkan JavaScript `window.CONFIG`, dipanggil lewat <script src="config.php"></script>

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

// ============================================================
//  🔑 KONFIGURASI API KEY
// ============================================================
// PENTING: file ini diakses publik lewat browser.
// Jangan taruh API key rahasia (server-only) di sini!
//
//   • Google Maps  → batasi di Google Cloud Console:
//         - HTTP referrer: https://domain-anda.com/*
//         - API restrictions: Maps JavaScript API saja
//
//   • Geoapify     → batasi di dashboard Geoapify:
//         - Allowed origins: https://domain-anda.com
// ============================================================

// --- Google Maps API Key (dari Google Cloud Console) ---
// Biarkan kosong ('') jika TIDAK memakai Google Maps.
$GOOGLE_MAPS_API_KEY = ''; // contoh: 'AIzaSyD-xxxxxxxxxxxxxxxxxxxx'

// --- Geoapify API Key (dari https://myprojects.geoapify.com) ---
// Biarkan kosong ('') jika TIDAK memakai Geoapify / Leaflet.
$GEOAPIFY_API_KEY = 'fc249c8458504d8790e29e110336616b';

// ============================================================
//  Pilih peta yang dipakai front-end: 'google' atau 'geoapify'
// ============================================================
$MAP_PROVIDER = 'geoapify'; // ganti ke 'google' kalau pakai Google Maps

// ============================================================
//  Susun URL tile Geoapify (hanya kalau provider = geoapify)
//  Pakai concat (titik), bukan interpolasi, agar aman.
// ============================================================
$tileUrl = '';
if ($MAP_PROVIDER === 'geoapify' && $GEOAPIFY_API_KEY !== '') {
    // Style "carto" (mirip Google Maps). Bisa diganti ke:
    //   osm-bright, osm-carto, toner, positron, dark-matter, klokantech-basic
    $tileUrl = 'https://maps.geoapify.com/v1/tile/carto/'
             . '{z}/{x}/{y}.png?apiKey=' . $GEOAPIFY_API_KEY;
}

// ============================================================
//  Konfigurasi utama
// ============================================================
$config = [
    // Provider peta yang aktif: 'google' | 'geoapify'
    'MAP_PROVIDER' => $MAP_PROVIDER,

    // 🔑 Google Maps JS API Key (dipakai kalau MAP_PROVIDER = 'google')
    'GOOGLE_MAPS_API_KEY' => $GOOGLE_MAPS_API_KEY,

    // 🗺️ Tile URL (dipakai kalau MAP_PROVIDER = 'geoapify', mis. Leaflet)
    'TILE_URL' => $tileUrl,

    // Atribusi tile (wajib untuk Geoapify/OSM)
    'TILE_ATTRIBUTION' => '&copy; OpenStreetMap contributors &copy; Geoapify',

    // Zoom default peta
    'MAP_CENTER' => [
        'lat' => -6.125022,
        'lng' => 106.917765,
    ],
    'MAP_ZOOM' => 13,

    // 🏥 Tujuan pasien (rumah sakit / faskes)
    'HOSPITAL' => [
        'name' => 'RS Pelabuhan Jakarta',
        'lat'  => -6.125022,
        'lng'  => 106.917765,
    ],

    // WebSocket:
    //   '' = mode demo (antar-tab di satu browser, tanpa server)
    //   isi = mode live, mis. "ws://localhost:8080" atau "wss://domain-anda.com/ws"
    'WS_URL' => '',

    'STALE_MS'  => 30000, // lokasi dianggap tidak live jika tak ada update selama ini
    'SPEED_KMH' => 30,    // asumsi kecepatan untuk estimasi tiba (garis lurus)
];

echo 'window.CONFIG = ' . json_encode(
    $config,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
) . ';';