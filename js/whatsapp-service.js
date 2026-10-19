/* =====================================================================
 *  whatsapp-service.js
 *  ---------------------------------------------------------------------
 *  Layanan WhatsApp terpusat — RS Pelabuhan Jakarta
 *
 *  Fitur:
 *    - sanitizeWA()       : bersihkan nomor → hanya angka, max 16 digit
 *    - normalizeWA()      : normalisasi ke format internasional (62…)
 *    - formatWADisplay()  : format tampilan (0812-3456-7890)
 *    - isValidWA()        : validasi panjang nomor (9–15 digit)
 *    - pickPatientWA()    : ambil nomor WA dari objek pasien
 *                           (prioritas: wa → phone → hp)
 *    - buildWALink()      : buat URL wa.me siap pakai
 *    - buildWAMessage*()  : template pesan ke pasien / admin
 *    - waConfig           : konfigurasi nomor admin & nama RS
 *
 *  Semua fungsi bisa diakses lewat:
 *    window.WAService.xxx(...)   ← namespace utama
 *  atau shortcut:
 *    window.sanitizeWA, window.normalizeWA, window.buildWALink, ...
 *
 *  Cara pakai (di HTML):
 *    <script src="../js/whatsapp-service.js"></script>
 *    <script>
 *      const url = window.WAService.buildWALink(patient, { toPatient: true });
 *      window.open(url, '_blank', 'noopener');
 *    </script>
 * ===================================================================== */
(function (global) {
  'use strict';

  if (global.WAService && global.WAService.__init__) return;
  var DEFAULT_COUNTRY = '62';

  /* -------------------------------------------------------------------
   * 0. KONFIG — bisa dioverride dari window.CONFIG
   * ------------------------------------------------------------------- */
  var CFG = (function () {
    var c = global.CONFIG || {};
    var hosp = c.HOSPITAL || {};

    var admin = c.WHATSAPP_ADMIN
             || hosp.whatsapp
             || hosp.whatsappE164
             || '6281234567890'; /* ← GANTI dengan nomor petugas RSPJ */

    var adminE164 = normalizeWA(admin) || '6281234567890';
    var hospitalName = hosp.name || 'RS Pelabuhan Jakarta';

    return {
      adminNumber: adminE164,       // format 62…
      adminDisplay: formatWADisplay(adminE164),
      hospitalName: hospitalName,
      defaultCountry: DEFAULT_COUNTRY
    };
  })();

  /* -------------------------------------------------------------------
   * 1. SANITASI & NORMALISASI
   * ------------------------------------------------------------------- */

  /**
   * Bersihkan input WA → hanya angka, max 16 digit.
   * Sinkron dengan sanitizeWA() di jadwal_berobat.html & index.html.
   */
  function sanitizeWA(v) {
    return String(v == null ? '' : v)
      .replace(/[^0-9]/g, '')
      .slice(0, 16);
  }

  /**
   * Normalisasi nomor WA ke format internasional (62…).
   *   "0812-3456-7890" → "6281234567890"
   *   "81234567890"    → "6281234567890"
   *   "6281234567890"  → "6281234567890"
   */
  function normalizeWA(v) {
    var s = sanitizeWA(v);
    if (!s) return '';
    if (s.charAt(0) === '0') {
      s = DEFAULT_COUNTRY + s.slice(1);
    } else if (s.charAt(0) === '8') {
      s = DEFAULT_COUNTRY + s;
    } else if (s.indexOf(DEFAULT_COUNTRY) !== 0) {
      s = DEFAULT_COUNTRY + s;
    }
    return s;
  }

  /**
   * Format tampilan nomor WA (hanya untuk UI).
   *   "6281234567890" → "0812-3456-7890"
   *   "081234567890"  → "0812-3456-7890"
   */
  function formatWADisplay(v) {
    var s = sanitizeWA(v);
    if (!s) return '-';

    // Kalau diawali 62, ubah dulu ke 0 untuk tampilan lokal
    if (s.indexOf('62') === 0) s = '0' + s.slice(2);

    // Kelompokkan 4-4-4-…
    return s.replace(/(\d{4})(?=\d)/g, '$1-');
  }

  /**
   * Validasi panjang nomor WA (longgar, tidak memaksa 16 digit).
   * Nomor Indonesia umumnya 10–13 digit. Range 9–15 aman.
   */
  function isValidWA(v) {
    var s = sanitizeWA(v);
    return s.length >= 9 && s.length <= 15;
  }

  /* -------------------------------------------------------------------
   * 2. EKSTRAKSI NOMOR DARI OBJEK PASIEN
   * ------------------------------------------------------------------- */

  /**
   * Ambil nomor WA dari objek pasien.
   * Prioritas: `wa` → `phone` → `hp` → kosong.
   * Selalu dikembalikan dalam bentuk angka murni (belum dinormalisasi).
   */
  function pickPatientWA(patient) {
    if (!patient) return '';
    return sanitizeWA(
      patient.wa != null ? patient.wa
      : patient.phone != null ? patient.phone
      : patient.hp != null ? patient.hp
      : ''
    );
  }

  /* -------------------------------------------------------------------
   * 3. BUILDER URL wa.me
   * ------------------------------------------------------------------- */

  /**
   * Bangun URL wa.me siap pakai.
   *
   * @param {Object} patient — objek pasien:
   *        { name, nik, wa|phone|hp, priority, lat, lng, speed, ts }
   * @param {Object} [opts]
   *        {
   *          toPatient : boolean — kirim ke pasien? (default: ke admin jika nomor kosong)
   *          target    : string  — override nomor tujuan (opsional)
   *          message   : string  — override pesan (opsional)
   *          hospitalCoord : { lat, lng } — untuk menghitung jarak/ETA
   *        }
   * @returns {string} URL wa.me lengkap dengan query pesan
   */
  function buildWALink(patient, opts) {
    opts = opts || {};

    var raw = pickPatientWA(patient);
    var phone = raw ? normalizeWA(raw) : '';
    var target = opts.target
              || (opts.toPatient && phone ? phone : '')
              || phone
              || CFG.adminNumber;

    var msg = opts.message;
    if (!msg) {
      msg = (opts.toPatient && phone)
        ? buildWAMessageToPatient(patient, opts)
        : buildWAMessageToAdmin(patient, opts);
    }

    return 'https://wa.me/' + target + '?text=' + encodeURIComponent(msg);
  }

  /* -------------------------------------------------------------------
   * 4. TEMPLATE PESAN
   * ------------------------------------------------------------------- */

  function buildWAMessageToPatient(p, opts) {
    opts = opts || {};
    p = p || {};

    var name = p.name || 'Bapak/Ibu';
    var nik  = sanitizeNIK(p.nik);
    var lat  = p.lat != null ? Number(p.lat) : null;
    var lng  = p.lng != null ? Number(p.lng) : null;

    var jarakStr = '';
    var etaStr = '';
    var coord = opts.hospitalCoord || (global.CONFIG && global.CONFIG.HOSPITAL) || null;

    if (lat != null && lng != null && coord && coord.lat != null && coord.lng != null) {
      var d = haversineMeters(lat, lng, Number(coord.lat), Number(coord.lng));
      jarakStr = fmtDistance(d);
      var spd = (p.speed != null && p.speed > 0.5) ? p.speed : 1.4;
      etaStr = fmtEta(d / spd);
    }

    var lines = [];
    lines.push('Halo *' + name + '*,');
    lines.push('');
    lines.push('Kami dari *' + CFG.hospitalName + '*.');
    lines.push('Kami melihat Anda sedang dalam perjalanan menuju rumah sakit.');
    lines.push('');
    if (jarakStr) lines.push('🎯 Jarak ke RS: *' + jarakStr + '*');
    if (etaStr)   lines.push('⏱️ Perkiraan tiba: *' + etaStr + '*');
    if (lat != null && lng != null) {
      lines.push('');
      lines.push('📍 Posisi Anda saat ini:');
      lines.push('https://maps.google.com/?q=' + lat + ',' + lng);
    }
    if (nik) {
      lines.push('');
      lines.push('_(Ref: NIK ' + nik + ')_');
    }
    lines.push('');
    lines.push('Mohon berkendara dengan aman. Petugas kami akan menyambut Anda di lobi penerimaan.');
    lines.push('');
    lines.push('Terima kasih 🙏');
    return lines.join('\n');
  }

  function buildWAMessageToAdmin(p, opts) {
    opts = opts || {};
    p = p || {};

    var name = p.name || 'Pasien';
    var nik  = sanitizeNIK(p.nik) || '-';
    var waNum = pickPatientWA(p);
    var prioLabel = getPriorityLabel(p.priority);
    var lat  = p.lat != null ? Number(p.lat) : null;
    var lng  = p.lng != null ? Number(p.lng) : null;

    var jarakStr = '—';
    var etaStr = '—';
    var coord = opts.hospitalCoord || (global.CONFIG && global.CONFIG.HOSPITAL) || null;

    if (lat != null && lng != null && coord && coord.lat != null && coord.lng != null) {
      var d = haversineMeters(lat, lng, Number(coord.lat), Number(coord.lng));
      jarakStr = fmtDistance(d);
      var spd = (p.speed != null && p.speed > 0.5) ? p.speed : 1.4;
      etaStr = fmtEta(d / spd);
    }

    var waktu = '';
    if (p.ts) {
      try { waktu = new Date(p.ts).toLocaleString('id-ID'); } catch (e) {}
    }

    var lines = [];
    lines.push('🚨 *Notifikasi Pasien Prioritas*');
    lines.push('');
    lines.push('*Nama*   : ' + name);
    lines.push('*NIK*    : ' + nik);
    if (waNum) lines.push('*WA*     : ' + waNum);
    lines.push('*Kelas*  : ' + prioLabel);

    if (lat != null && lng != null) {
      lines.push('');
      lines.push('📍 *Posisi saat ini*:');
      lines.push('https://maps.google.com/?q=' + lat + ',' + lng);
      lines.push('_(Lat ' + lat.toFixed(6) + ', Lng ' + lng.toFixed(6) + ')_');
    }

    lines.push('');
    lines.push('🎯 *Jarak ke RS* : ' + jarakStr);
    lines.push('⏱️ *ETA*          : ' + etaStr);
    if (waktu) lines.push('🕐 *Update*       : ' + waktu);
    lines.push('');
    lines.push('Mohon dipersiapkan penerimaannya di lobi / area terkait.');
    return lines.join('\n');
  }

  /* -------------------------------------------------------------------
   * 5. HELPER INTERNAL
   * ------------------------------------------------------------------- */

  function sanitizeNIK(v) {
    return String(v == null ? '' : v).replace(/[^0-9]/g, '').slice(0, 16);
  }

  function getPriorityLabel(p) {
    var map = {
      'president':  'President Suite',
      'vvip-suite': 'VVIP Suite',
      'vvip':       'VVIP',
      'merah':      'President Suite',
      'kuning':     'VVIP Suite',
      'biru':       'VVIP',
      'hijau':      'VVIP'
    };
    return map[p] || 'VVIP';
  }

  function haversineMeters(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var toRad = function (d) { return d * Math.PI / 180; };
    var dLat = toRad(lat2 - lat1);
    var dLng = toRad(lng2 - lng1);
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function fmtDistance(m) {
    if (!isFinite(m)) return '—';
    if (m < 1000) return Math.round(m) + ' m';
    return (m / 1000).toFixed(2) + ' km';
  }

  function fmtEta(seconds) {
    if (!isFinite(seconds) || seconds <= 0) return '—';
    var m = Math.round(seconds / 60);
    if (m < 1) return '< 1 menit';
    if (m < 60) return m + ' menit';
    var h = Math.floor(m / 60), r = m % 60;
    return h + ' jam' + (r ? ' ' + r + ' menit' : '');
  }

  /* -------------------------------------------------------------------
   * 6. API PUBLIK
   * ------------------------------------------------------------------- */
  var API = {
    __init__: true,

    /* Konfigurasi */
    config: CFG,

    /* Sanitasi & normalisasi */
    sanitizeWA: sanitizeWA,
    normalizeWA: normalizeWA,
    formatWADisplay: formatWADisplay,
    isValidWA: isValidWA,

    /* Ekstraksi nomor dari objek pasien */
    pickPatientWA: pickPatientWA,

    /* Builder URL & pesan */
    buildWALink: buildWALink,
    buildWAMessageToPatient: buildWAMessageToPatient,
    buildWAMessageToAdmin: buildWAMessageToAdmin,

    /* Aksi siap pakai */
    openChat: function (patient, opts) {
      var url = buildWALink(patient, opts);
      if (typeof global.open === 'function') {
        global.open(url, '_blank', 'noopener');
      }
      return url;
    }
  };

  /* Ekspos namespace utama */
  global.WAService = API;

  /* Shortcut global untuk backward-compat dengan kode lama */
  global.sanitizeWA         = global.sanitizeWA         || API.sanitizeWA;
  global.normalizeWA        = global.normalizeWA        || API.normalizeWA;
  global.formatWADisplay    = global.formatWADisplay    || API.formatWADisplay;
  global.buildWALink        = global.buildWALink        || API.buildWALink;
  global.pickPatientWA      = global.pickPatientWA      || API.pickPatientWA;

})(typeof window !== 'undefined' ? window : this);