// Relay SSE sederhana: pasien mengirim lokasi melalui POST, dashboard menerima SSE.
// Jalankan: npm install && npm start
const http = require("http");

const PORT = process.env.PORT || 1972;
const lastKnown = new Map(); // id -> pesan lokasi terakhir
const clients = new Set(); // response stream SSE

function writeEvent(res, msg) {
  res.write(`data: ${JSON.stringify(msg)}\n\n`);
}

function broadcast(msg) {
  clients.forEach(client => {
    try { writeEvent(client, msg); }
    catch (_) { clients.delete(client); }
  });
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = "";
    req.on("data", chunk => {
      body += chunk;
      if (body.length > 100000) req.destroy(new Error("Payload terlalu besar"));
    });
    req.on("end", () => {
      try { resolve(JSON.parse(body || "{}")); }
      catch (error) { reject(error); }
    });
    req.on("error", reject);
  });
}

const server = http.createServer(async (req, res) => {
  if (req.method === "OPTIONS") {
    res.writeHead(204, {
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
      "Access-Control-Allow-Headers": "Content-Type",
    });
    res.end();
    return;
  }

  if (req.method === "GET" && req.url === "/events") {
    res.writeHead(200, {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache, no-transform",
      "Connection": "keep-alive",
      "X-Accel-Buffering": "no",
      "Access-Control-Allow-Origin": "*",
    });
    res.write(": connected\n\n");
    clients.add(res);
    lastKnown.forEach(msg => writeEvent(res, msg));
    req.on("close", () => clients.delete(res));
    return;
  }

  if (req.method === "POST" && req.url === "/publish") {
    try {
      const msg = await readJson(req);
      if (!msg || typeof msg.id !== "string" || msg.id.length > 40) {
        res.writeHead(422, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ ok: false, error: "id tidak valid" }));
        return;
      }

      if (msg.type === "leave" || msg.type === "location-stop") {
        lastKnown.delete(msg.id);
      } else if (
        typeof msg.lat === "number" &&
        typeof msg.lng === "number" &&
        msg.lat >= -90 && msg.lat <= 90 &&
        msg.lng >= -180 && msg.lng <= 180
      ) {
        lastKnown.set(msg.id, msg);
      } else if (msg.type !== "patient-info") {
        res.writeHead(422, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ ok: false, error: "data lokasi tidak valid" }));
        return;
      }

      broadcast(msg);
      res.writeHead(204);
      res.end();
    } catch (error) {
      res.writeHead(400, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ ok: false, error: "JSON tidak valid" }));
    }
    return;
  }

  res.writeHead(404, { "Content-Type": "application/json" });
  res.end(JSON.stringify({ ok: false, error: "Not found" }));
});

server.listen(PORT, () => {
  console.log(`Server SSE live di http://localhost:${PORT}`);
});
