// Relay WebSocket sederhana: terima lokasi dari pasien, siarkan ke semua dasbor.
// Jalankan: npm install && npm start
const { WebSocketServer } = require("ws");

const PORT = process.env.PORT || 8080;
const wss = new WebSocketServer({ port: PORT });
const lastKnown = new Map(); // id -> pesan lokasi terakhir

wss.on("connection", ws => {
  // Klien baru langsung menerima posisi terakhir semua pasien
  lastKnown.forEach(msg => ws.send(JSON.stringify(msg)));

  ws.on("message", raw => {
    let msg;
    try { msg = JSON.parse(raw); } catch (_) { return; }
    if (!msg || typeof msg.id !== "string" || msg.id.length > 40) return;

    if (msg.type === "leave") {
      lastKnown.delete(msg.id);
    } else if (typeof msg.lat === "number" && typeof msg.lng === "number") {
      lastKnown.set(msg.id, msg);
    } else {
      return;
    }

    const out = JSON.stringify(msg);
    wss.clients.forEach(c => { if (c !== ws && c.readyState === 1) c.send(out); });
  });
});

console.log("Server live di ws://localhost:" + PORT);
