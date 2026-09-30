const tx = createTransport(function () {});
const $ = id => document.getElementById(id);
const patientId = localStorage.getItem("pid") || ("p" + Math.random().toString(36).slice(2, 8));
localStorage.setItem("pid", patientId);
$("name").value = localStorage.getItem("pname") || "";

let watchId = null;
let last = null;
let keepAlive = null;

function payload() {
  return {
    id: patientId,
    name: $("name").value.trim() || "Pasien tanpa nama",
    priority: $("priority").value,
    lat: last.latitude,
    lng: last.longitude,
    acc: Math.round(last.accuracy || 0)
  };
}

function start() {
  if (!("geolocation" in navigator)) {
    $("state").textContent = "Browser ini tidak mendukung GPS. Coba Chrome atau Safari terbaru.";
    return;
  }
  localStorage.setItem("pname", $("name").value.trim());
  $("state").textContent = "Mencari sinyal GPS…";
  $("toggle").textContent = "Berhenti bagikan lokasi";

  watchId = navigator.geolocation.watchPosition(
    pos => {
      last = pos.coords;
      tx.send(payload());
      $("state").textContent = "Lokasi dibagikan. Akurasi sekitar " + Math.round(last.accuracy) + " m.";
    },
    err => {
      const msg = err.code === 1
        ? "Izin lokasi ditolak. Aktifkan izin lokasi di pengaturan browser, lalu coba lagi."
        : "Lokasi tidak bisa dibaca. Pastikan GPS aktif dan sinyal cukup.";
      $("state").textContent = msg;
      stop(true);
    },
    { enableHighAccuracy: true, maximumAge: 2000, timeout: 15000 }
  );

  // Kirim ulang tiap 10 detik agar tetap terlihat live saat pasien diam
  keepAlive = setInterval(() => { if (last) tx.send(payload()); }, 10000);
}

function stop(keepMessage) {
  if (watchId !== null) navigator.geolocation.clearWatch(watchId);
  clearInterval(keepAlive);
  watchId = null;
  if (last) tx.send({ type: "leave", id: patientId });
  $("toggle").textContent = "Mulai bagikan lokasi";
  if (!keepMessage) $("state").textContent = "Lokasi berhenti dibagikan.";
}

$("toggle").addEventListener("click", () => (watchId === null ? start() : stop()));
window.addEventListener("pagehide", () => { if (watchId !== null) stop(); });
