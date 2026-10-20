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
$MAP_PROVIDER = 'geoapify'; 

$WHATSAPP_RS_RAW = '0812-8835-7422'; 

// Sanitasi → hanya angka
$WA_DIGITS = preg_replace('/[^0-9]/', '', $WHATSAPP_RS_RAW);

// Normalisasi ke format internasional (62…)
$WA_E164 = $WA_DIGITS;
if ($WA_E164 !== '') {
    if ($WA_E164[0] === '0') {
        $WA_E164 = '62' . substr($WA_E164, 1);
    } elseif ($WA_E164[0] === '8') {
        $WA_E164 = '62' . $WA_E164;
    } elseif (substr($WA_E164, 0, 2) !== '62') {
        $WA_E164 = '62' . $WA_E164;
    }
}

// Format tampilan lokal (0xxx-xxxx-xxxx)
$WA_DISPLAY = $WA_DIGITS;
if (substr($WA_DISPLAY, 0, 2) === '62') {
    $WA_DISPLAY = '0' . substr($WA_DISPLAY, 2);
}
$WA_DISPLAY = preg_replace('/(\d{4})(?=\d)/', '$1-', $WA_DISPLAY);

$tileUrl = '';
if ($MAP_PROVIDER === 'geoapify' && $GEOAPIFY_API_KEY !== '') {
    $tileUrl = 'https://maps.geoapify.com/v1/tile/carto/'
             . '{z}/{x}/{y}.png?apiKey=' . $GEOAPIFY_API_KEY;
}

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

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

    // ========================================================
    // ☎️  NOMOR WHATSAPP ADMIN / LAYANAN RS
    // ========================================================
    // Dipakai oleh:
    //   - index.html          → WA_CONFIG.adminNumber
    //   - jadwal_berobat.html → default nomor admin
    //   - whatsapp-service.js → WAService.config.adminNumber
    //
    // Tiga bentuk sekaligus agar kompatibel dengan semua pemanggil:
    //   WHATSAPP_ADMIN    → "6281288357422" (paling sering dipakai)
    //   WHATSAPP_ADMIN_E164 → sama, format internasional
    //   WHATSAPP_ADMIN_DISPLAY → "0812-8835-7422" (untuk tampilan)
    // ========================================================
    'WHATSAPP_ADMIN'         => $WA_E164,
    'WHATSAPP_ADMIN_E164'    => $WA_E164,
    'WHATSAPP_ADMIN_DISPLAY' => $WA_DISPLAY,

    // 🏥 Tujuan pasien (rumah sakit / faskes)
    // Field `whatsapp` & `whatsappE164` di sini juga penting — banyak
    // modul membaca dari CONFIG.HOSPITAL.whatsapp untuk fallback.
    'HOSPITAL' => [
        'name'         => 'RS Pelabuhan Jakarta',
        'lat'          => -6.12504,
        'lng'          => 106.91778,
        'whatsapp'     => $WA_E164,        // dipakai index.html & jadwal_berobat.html
        'whatsappE164' => $WA_E164,        // alias
        'whatsappDisplay' => $WA_DISPLAY,  // untuk tampilan
        'address'      => 'Jl. Pelabuhan No. 1, Jakarta Utara',
    ],

    // SSE live: pasien mengirim POST, dashboard menerima EventSource.
    // Kosongkan keduanya hanya untuk mode demo antar-tab.
    'SSE_PUBLISH_URL' => $base . '/live/publish',
    'SSE_URL'         => $base . '/live/events',
    'STALE_MS'  => 60000, // lokasi dianggap tidak live jika tak ada update selama ini
    'SPEED_KMH' => 30,    // asumsi kecepatan untuk estimasi tiba (garis lurus)
];

echo 'window.CONFIG = ' . json_encode(
    $config,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
) . ';';