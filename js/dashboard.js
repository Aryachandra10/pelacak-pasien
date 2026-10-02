const H = CONFIG.HOSPITAL;
const RANK = { merah: 0, kuning: 1, hijau: 2 };
const LABEL = { merah: "Merah", kuning: "Kuning", hijau: "Hijau" };
const COLOR = { merah: "#c8352b", kuning: "#d9962b", hijau: "#3e8e52" };

// ============================================================
//  KONFIGURASI TAMBAHAN (opsional — bisa diisi di CONFIG.VEHICLE)
// ============================================================
const VCFG = Object.assign({
  CAR_SPEED_KMH  : 45,    // >= kecepatan ini dianggap MOBIL
  CAR_CONFIRM    : 3,     // butuh 3 sampel berturut-turut untuk naik jadi mobil
  MOTOR_CONFIRM  : 8,     // butuh 8 sampel turun untuk balik jadi motor (anti-flip saat macet)
  AUTO_FOCUS_NEW : true,  // begitu pasien setuju bagikan lokasi -> langsung difokuskan
  DETECT_FLASH_MS: 8000,  // lama label "Terdeteksi" di daftar
  ACC_GOOD_M     : 120,   // akurasi GPS dianggap bagus (meter)
  SMOOTH_MARKER  : true   // marker meluncur halus, tidak "lompat"
}, CONFIG.VEHICLE || {});

// ============================================================
//  IKON KENDARAAN (tampak atas) — dipakai di marker & daftar
// ============================================================
const VEHICLE_SVG = {
  car:
    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<rect x="6.2" y="2.6" width="11.6" height="18.8" rx="3.6"/>' +
      '<rect x="4.1" y="6.2" width="2.5" height="4.2" rx="1.1"/>' +
      '<rect x="17.4" y="6.2" width="2.5" height="4.2" rx="1.1"/>' +
      '<rect x="4.1" y="13.6" width="2.5" height="4.2" rx="1.1"/>' +
      '<rect x="17.4" y="13.6" width="2.5" height="4.2" rx="1.1"/>' +
      '<rect x="8.4" y="5.4" width="7.2" height="3.8" rx="1.7" fill="#000" opacity=".22"/>' +
      '<rect x="8.4" y="15" width="7.2" height="3.2" rx="1.5" fill="#000" opacity=".22"/>' +
    '</svg>',
  motor:
    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<rect x="4.6" y="5.05" width="14.8" height="1.9" rx="0.95"/>' +
      '<circle cx="12" cy="6.6" r="2.6"/>' +
      '<rect x="10.5" y="8.4" width="3" height="7.6" rx="1.5"/>' +
      '<circle cx="12" cy="17.6" r="2.6"/>' +
    '</svg>'
};
const VEHICLE_LABEL = { car: 'Mobil', motor: 'Motor' };

// Marker pasien (ikon kendaraan + cincin prioritas)
function pinIcon(priority, vehicle, heading) {
  const v = vehicle === 'car' ? 'car' : 'motor';
  return L.divIcon({
    className: '',
    html:
      '<div class="pin ' + priority + '">' +
        '<span class="pulse"></span>' +
        '<span class="veh" style="transform:rotate(' + Math.round(heading || 0) + 'deg)">' +
          VEHICLE_SVG[v] +
        '</span>' +
      '</div>',
    iconSize: [36, 36],
    iconAnchor: [18, 18],
    tooltipAnchor: [0, -16]
  });
}

// ============================================================
//  PETA — sudah dibuat di index.html (window.map)
// ============================================================
const map = window.map;
if (!map) {
  console.error('[dashboard.js] window.map tidak ditemukan. Pastikan index.html sudah membuat peta sebelum dashboard.js dimuat.');
}

const patients = new Map();
let selectedId = null;
let queued = false;
let lastFocusAt = 0;

// Matikan transisi marker saat zoom biar tidak terlihat "ngambang"
if (map) {
  map.on('zoomstart', () => document.body.classList.add('zooming'));
  map.on('zoomend', () => document.body.classList.remove('zooming'));
}

// ---------- Hitung jarak, arah & estimasi ----------
function distKm(aLat, aLng, bLat, bLng) {
  const r = Math.PI / 180;
  const dLat = (bLat - aLat) * r, dLng = (bLng - aLng) * r;
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLng / 2) ** 2;
  return 12742 * Math.asin(Math.sqrt(x));
}

function bearing(a, b) {
  const r = Math.PI / 180;
  const y = Math.sin((b.lng - a.lng) * r) * Math.cos(b.lat * r);
  const x = Math.cos(a.lat * r) * Math.sin(b.lat * r) -
            Math.sin(a.lat * r) * Math.cos(b.lat * r) * Math.cos((b.lng - a.lng) * r);
  return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

const fmtDist = km => (km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(1) + ' km');

// Kecepatan asumsi per jenis kendaraan (fallback: CONFIG.SPEED_KMH)
function speedFor(vehicle) {
  const s = CONFIG.SPEED_KMH;
  if (typeof s === 'number') return s;
  if (s && typeof s === 'object') return s[vehicle] || 30;
  return 30;
}
const fmtEta = (km, vehicle) => {
  const min = Math.max(1, Math.round((km / speedFor(vehicle)) * 60));
  return '±' + min + ' mnt';
};

// ============================================================
//  AUTO-DETEKSI JENIS KENDARAAN
//  1) Kalau pengirim sudah menyertakan m.vehicle / m.mode -> pakai itu
//  2) Kalau tidak -> ditebak dari kecepatan GPS (dengan hysteresis)
// ============================================================
function detectVehicle(m, p) {
  const raw = String(m.vehicle || m.mode || m.transport || '').toLowerCase();
  if (raw === 'car' || raw === 'mobil') return 'car';
  if (raw === 'motor' || raw === 'motorcycle' || raw === 'bike' || raw === 'sepeda motor') return 'motor';
  if (m.isCar === true)  return 'car';
  if (m.isCar === false) return 'motor';

  const guess = p.speed >= VCFG.CAR_SPEED_KMH ? 'car' : 'motor';

  if (p.vehicleCand === guess) p.vehicleVotes++;
  else { p.vehicleCand = guess; p.vehicleVotes = 1; }

  if (!p.vehicle) return guess;                     // deteksi pertama: langsung pakai
  if (guess !== p.vehicle) {
    const need = guess === 'car' ? VCFG.CAR_CONFIRM : VCFG.MOTOR_CONFIRM;
    if (p.vehicleVotes >= need) return guess;
  }
  return p.vehicle;
}

// Update ikon marker (jenis kendaraan / prioritas / rotasi arah)
function refreshIcon(p) {
  const key = p.priority + '|' + p.vehicle;
  if (p.iconKey !== key) {
    p.marker.setIcon(pinIcon(p.priority, p.vehicle, p.heading));
    p.iconKey = key;
    return;
  }
  const el = p.marker.getElement();
  const veh = el && el.querySelector('.veh');
  if (veh) veh.style.transform = 'rotate(' + Math.round(p.heading) + 'deg)';
}

// ============================================================
//  DATA MASUK
// ============================================================
function upsert(m) {
  if (!m) return;
  if (m.type === 'leave') { remove(m.id); return; }
  if (typeof m.lat !== 'number' || typeof m.lng !== 'number' || !RANK.hasOwnProperty(m.priority)) return;

  const now = Date.now();
  let p = patients.get(m.id);
  const isNew = !p;

  if (isNew) {
    p = {
      id: m.id,
      trail: [],
      iconKey: null,
      vehicle: null,
      vehicleCand: null,
      vehicleVotes: 0,
      heading: 0,
      speed: 0,
      detectedAt: now,
      priority: m.priority,
      lat: m.lat, lng: m.lng,
      name: String(m.name || 'Pasien').slice(0, 60),
      acc: m.acc || 0,
      t: now
    };

    p.marker = L.marker([m.lat, m.lng], {
      icon: pinIcon(m.priority, 'motor', 0),
      zIndexOffset: 500,
      riseOnHover: true
    }).addTo(map);

    p.marker.on('click', () => select(m.id));

    p.line = L.polyline([], { color: COLOR[m.priority], weight: 4, opacity: 0.6 }).addTo(map);
    p.lineColor = m.priority;
    p.iconKey = m.priority + '|motor';

    patients.set(m.id, p);

    if (VCFG.SMOOTH_MARKER) {
      const el = p.marker.getElement();
      if (el) el.classList.add('smooth-move');
    }

    // ---- DETEKSI OTOMATIS: pasien baru saja setuju bagikan lokasi ----
    if (VCFG.AUTO_FOCUS_NEW) autoFocusNew(p, patients.size === 1);
  }

  const prev = p.trail.length ? p.trail[p.trail.length - 1] : null;

  p.name = String(m.name || p.name || 'Pasien').slice(0, 60);
  p.priority = m.priority;
  p.acc = m.acc || 0;
  p.t = now;

  // ---- kecepatan (EMA) & arah gerak ----
  if (prev) {
    const km = distKm(prev.lat, prev.lng, m.lat, m.lng);
    const dt = Math.max(0.5, (now - prev.t) / 1000);
    const inst = (km / dt) * 3600;                 // km/jam
    p.speed = p.speed ? (p.speed * 0.6 + inst * 0.4) : inst;
    if (km > 0.003) p.heading = bearing(prev, { lat: m.lat, lng: m.lng }); // >3 m baru update arah
  } else {
    p.speed = 0;
  }

  p.lat = m.lat;
  p.lng = m.lng;

  // ---- auto deteksi mobil / motor ----
  p.vehicle = detectVehicle(m, p);

  // ---- jejak & marker ----
  p.trail.push({ lat: m.lat, lng: m.lng, t: now });
  if (p.trail.length > 80) p.trail.shift();
  p.line.setLatLngs(p.trail.map(q => [q.lat, q.lng]));
  if (p.lineColor !== p.priority) {
    p.line.setStyle({ color: COLOR[p.priority] });
    p.lineColor = p.priority;
  }

  p.marker.setLatLng([m.lat, m.lng]);
  p.marker.bindTooltip(p.name + ' · ' + VEHICLE_LABEL[p.vehicle]);
  refreshIcon(p);

  if (selectedId === m.id) map.panTo([m.lat, m.lng], { animate: true });
  queueRender();
}

// Fokus otomatis untuk pasien yang baru terdeteksi
function autoFocusNew(p, first) {
  selectedId = p.id;
  const now = Date.now();
  if (first) {
    map.setView([p.lat, p.lng], 15);
    lastFocusAt = now;
  } else if (now - lastFocusAt > 1500) {   // hindari rebutan fokus kalau datang bersamaan
    lastFocusAt = now;
    map.flyTo([p.lat, p.lng], Math.max(map.getZoom(), 15), { duration: 0.9 });
  }
  queueRender();
}

function remove(id) {
  const p = patients.get(id);
  if (!p) return;
  map.removeLayer(p.marker);
  map.removeLayer(p.line);
  patients.delete(id);
  if (selectedId === id) selectedId = null;
  queueRender();
}

function select(id) {
  selectedId = id;
  const p = patients.get(id);
  if (p) map.flyTo([p.lat, p.lng], Math.max(map.getZoom(), 16), { duration: 0.8 });
  queueRender();
}

// ============================================================
//  DAFTAR PASIEN
// ============================================================
function queueRender() {
  if (queued) return;
  queued = true;
  requestAnimationFrame(() => { queued = false; render(); });
}

function render() {
  const list = document.getElementById('list');
  if (!list) return;
  const now = Date.now();

  const rows = Array.from(patients.values()).map(p => ({
    p,
    km: distKm(p.lat, p.lng, H.lat, H.lng),
    live: now - p.t < CONFIG.STALE_MS
  }));
  rows.sort((a, b) => (RANK[a.p.priority] - RANK[b.p.priority]) || (a.km - b.km));

  const liveCount = rows.filter(r => r.live).length;
  const mobil = rows.filter(r => r.p.vehicle === 'car').length;
  document.getElementById('summary').textContent = rows.length
    ? liveCount + ' dari ' + rows.length + ' pasien live (' + mobil + ' mobil, ' +
      (rows.length - mobil) + ' motor), diurutkan dari prioritas tertinggi.'
    : 'Belum ada pasien yang membagikan lokasi.';

  list.replaceChildren();
  rows.forEach(({ p, km, live }) => {
    const li = document.createElement('li');
    li.className = 'item ' + p.priority + (live ? '' : ' stale') + (p.id === selectedId ? ' active' : '');

    const btn = document.createElement('button');
    btn.className = 'item-btn';
    btn.addEventListener('click', () => select(p.id));

    const top = document.createElement('div');
    top.className = 'item-top';
    const name = document.createElement('strong');
    name.textContent = p.name;
    const tag = document.createElement('span');
    tag.className = 'tag ' + p.priority;
    tag.textContent = LABEL[p.priority];
    top.append(name, tag);

    // chip "Terdeteksi" sebentar setelah pasien baru masuk
    if (now - p.detectedAt < VCFG.DETECT_FLASH_MS) {
      const chip = document.createElement('span');
      chip.className = 'chip-detect';
      chip.textContent = 'Terdeteksi';
      top.append(chip);
    }

    const meta = document.createElement('div');
    meta.className = 'item-meta';

    const vIcon = document.createElement('span');
    vIcon.className = 'veh-inline ' + p.vehicle;
    vIcon.innerHTML = VEHICLE_SVG[p.vehicle];

    const secs = Math.round((now - p.t) / 1000);
    const txt = live
      ? fmtDist(km) + ' dari ' + H.name + ' · ' + VEHICLE_LABEL[p.vehicle] +
        ' · tiba ' + fmtEta(km, p.vehicle) + ' · live'
      : 'Terakhir terlihat ' + (secs > 90 ? Math.round(secs / 60) + ' menit' : secs + ' detik') + ' lalu';

    meta.append(vIcon, document.createTextNode(txt));

    btn.append(top, meta);
    li.append(btn);
    list.append(li);
  });
}
setInterval(render, 3000);

// ============================================================
//  KONEKSI
// ============================================================
const connEl = document.getElementById('conn');
const CONN_TEXT = { online: 'Terhubung ke server', offline: 'Terputus, mencoba lagi…', demo: 'Mode demo (antar-tab)' };
createTransport(upsert, s => {
  if (connEl) {
    connEl.textContent = CONN_TEXT[s] || s;
    connEl.className = 'conn ' + s;
  }
});