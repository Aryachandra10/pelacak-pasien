const H = CONFIG.HOSPITAL;
const RANK = { merah: 0, kuning: 1, hijau: 2 };
const LABEL = { merah: "Merah", kuning: "Kuning", hijau: "Hijau" };
const COLOR = { merah: "#c8352b", kuning: "#d9962b", hijau: "#3e8e52" };
const patients = new Map();
let selectedId = null;
let queued = false;

// ---------- Peta ----------
const map = L.map("map", { zoomControl: false }).setView([H.lat, H.lng], 13);
L.control.zoom({ position: "bottomright" }).addTo(map);
L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
  maxZoom: 19,
  attribution: "&copy; OpenStreetMap"
}).addTo(map);

L.marker([H.lat, H.lng], {
  icon: L.divIcon({ className: "", html: '<div class="pin hosp">+</div>', iconSize: [34, 34], iconAnchor: [17, 17] })
}).addTo(map).bindTooltip(H.name);

function pinIcon(priority) {
  return L.divIcon({
    className: "",
    html: '<div class="pin ' + priority + '"><span class="pulse"></span></div>',
    iconSize: [26, 26],
    iconAnchor: [13, 13]
  });
}

// ---------- Hitung jarak & estimasi ----------
function distKm(aLat, aLng, bLat, bLng) {
  const r = Math.PI / 180;
  const dLat = (bLat - aLat) * r, dLng = (bLng - aLng) * r;
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLng / 2) ** 2;
  return 12742 * Math.asin(Math.sqrt(x));
}
const fmtDist = km => (km < 1 ? Math.round(km * 1000) + " m" : km.toFixed(1) + " km");
const fmtEta = km => {
  const min = Math.max(1, Math.round((km / CONFIG.SPEED_KMH) * 60));
  return "±" + min + " mnt";
};

// ---------- Data masuk ----------
function upsert(m) {
  if (m.type === "leave") { remove(m.id); return; }
  if (typeof m.lat !== "number" || typeof m.lng !== "number" || !RANK.hasOwnProperty(m.priority)) return;

  let p = patients.get(m.id);
  if (!p) {
    p = { id: m.id, trail: [], iconPriority: m.priority };
    p.marker = L.marker([m.lat, m.lng], { icon: pinIcon(m.priority) }).addTo(map);
    p.line = L.polyline([], { color: COLOR[m.priority], weight: 4, opacity: 0.6 }).addTo(map);
    p.marker.on("click", () => select(m.id));
    patients.set(m.id, p);
    if (patients.size === 1) map.setView([m.lat, m.lng], 14);
  }
  p.name = String(m.name || "Pasien").slice(0, 60);
  p.priority = m.priority;
  p.lat = m.lat; p.lng = m.lng; p.acc = m.acc || 0;
  p.t = Date.now();

  if (p.iconPriority !== p.priority) {
    p.marker.setIcon(pinIcon(p.priority));
    p.line.setStyle({ color: COLOR[p.priority] });
    p.iconPriority = p.priority;
  }
  p.trail.push([m.lat, m.lng]);
  if (p.trail.length > 80) p.trail.shift();
  p.line.setLatLngs(p.trail);
  p.marker.setLatLng([m.lat, m.lng]);
  p.marker.bindTooltip(p.name);

  if (selectedId === m.id) map.panTo([m.lat, m.lng], { animate: true });
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

// ---------- Daftar ----------
function queueRender() {
  if (queued) return;
  queued = true;
  requestAnimationFrame(() => { queued = false; render(); });
}

function render() {
  const list = document.getElementById("list");
  const now = Date.now();
  const rows = Array.from(patients.values()).map(p => ({
    p,
    km: distKm(p.lat, p.lng, H.lat, H.lng),
    live: now - p.t < CONFIG.STALE_MS
  }));
  rows.sort((a, b) => (RANK[a.p.priority] - RANK[b.p.priority]) || (a.km - b.km));

  const liveCount = rows.filter(r => r.live).length;
  document.getElementById("summary").textContent = rows.length
    ? liveCount + " dari " + rows.length + " pasien live, diurutkan dari prioritas tertinggi."
    : "Belum ada pasien yang membagikan lokasi.";

  list.replaceChildren();
  rows.forEach(({ p, km, live }) => {
    const li = document.createElement("li");
    li.className = "item " + p.priority + (live ? "" : " stale") + (p.id === selectedId ? " active" : "");

    const btn = document.createElement("button");
    btn.className = "item-btn";
    btn.addEventListener("click", () => select(p.id));

    const top = document.createElement("div");
    top.className = "item-top";
    const name = document.createElement("strong");
    name.textContent = p.name;
    const tag = document.createElement("span");
    tag.className = "tag " + p.priority;
    tag.textContent = LABEL[p.priority];
    top.append(name, tag);

    const meta = document.createElement("div");
    meta.className = "item-meta";
    const secs = Math.round((now - p.t) / 1000);
    meta.textContent = live
      ? fmtDist(km) + " dari " + H.name + " · tiba " + fmtEta(km) + " · live"
      : "Terakhir terlihat " + (secs > 90 ? Math.round(secs / 60) + " menit" : secs + " detik") + " lalu";

    btn.append(top, meta);
    li.append(btn);
    list.append(li);
  });
}
setInterval(render, 3000);

// ---------- Koneksi ----------
const connEl = document.getElementById("conn");
const CONN_TEXT = { online: "Terhubung ke server", offline: "Terputus, mencoba lagi…", demo: "Mode demo (antar-tab)" };
createTransport(upsert, s => {
  connEl.textContent = CONN_TEXT[s];
  connEl.className = "conn " + s;
});

// ---------- Simulasi ----------
let demoTimer = null;
const demoBtn = document.getElementById("demo");
demoBtn.addEventListener("click", () => {
  if (demoTimer) {
    clearInterval(demoTimer);
    demoTimer = null;
    ["demo1", "demo2", "demo3"].forEach(remove);
    demoBtn.textContent = "Jalankan simulasi";
    return;
  }
  const sims = [
    { id: "demo1", name: "Budi Santoso", priority: "merah", lat: H.lat + 0.045, lng: H.lng - 0.03 },
    { id: "demo2", name: "Siti Rahma", priority: "kuning", lat: H.lat - 0.04, lng: H.lng + 0.04 },
    { id: "demo3", name: "Agus Wijaya", priority: "hijau", lat: H.lat + 0.03, lng: H.lng + 0.05 }
  ];
  const step = () => sims.forEach(s => {
    const dLat = H.lat - s.lat, dLng = H.lng - s.lng;
    if (distKm(s.lat, s.lng, H.lat, H.lng) > 0.05) {
      s.lat += dLat * 0.03 + (Math.random() - 0.5) * 0.0004;
      s.lng += dLng * 0.03 + (Math.random() - 0.5) * 0.0004;
    }
    upsert({ id: s.id, name: s.name, priority: s.priority, lat: s.lat, lng: s.lng, acc: 8 });
  });
  step();
  demoTimer = setInterval(step, 1000);
  demoBtn.textContent = "Hentikan simulasi";
});
