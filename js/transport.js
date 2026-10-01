/* ============================================================
   transport.js — Satu antarmuka kirim/terima lokasi realtime.
   ------------------------------------------------------------
   Mode:
     • WS_URL terisi  -> WebSocket (live, auto-reconnect + ping)
     • WS_URL kosong  -> BroadcastChannel (demo antar-tab)

   API:
     const t = createTransport(onMessage, onStatus);
     t.send({...})        — kirim pesan
     t.close()            — tutup koneksi (tidak reconnect lagi)
     t.isOpen             — true kalau sedang terhubung (getter)
     t.mode               — "ws" | "demo" | "none"
   ============================================================ */
/* global CONFIG */

function createTransport(onMessage, onStatus) {
  "use strict";

  /* ---------- Guard & helper ---------- */
  var status = (typeof onStatus === "function") ? onStatus : function () {};
  var emit = function (msg) {
    if (typeof onMessage !== "function") return;
    try { onMessage(msg); }
    catch (e) { console.warn("[transport] onMessage error:", e); }
  };

  var cfg   = (typeof CONFIG !== "undefined" && CONFIG) ? CONFIG : {};
  var wsUrl = cfg.WS_URL || "";

  /* Paksa wss:// kalau halaman di-serve via HTTPS (hindari mixed content) */
  if (location.protocol === "https:" && wsUrl.indexOf("ws://") === 0) {
    wsUrl = wsUrl.replace("ws://", "wss://");
  }

  /* Status hanya di-emit kalau benar-benar berubah (hindari spam) */
  var lastStatus = "";
  function setStatus(s) {
    if (s === lastStatus) return;
    lastStatus = s;
    status(s);
  }

  /* ============================================================
     MODE 1 — WebSocket (production / live)
     ============================================================ */
  if (wsUrl) {
    var ws           = null;
    var connecting   = false;
    var retry        = 0;
    var pingTimer    = null;
    var lastPong     = Date.now();
    var closedByUser = false;
    var queue        = [];
    var MAX_QUEUE    = 30;      // cukup untuk reconnect singkat
    var PING_EVERY   = 25000;   // 25 detik
    var PONG_TIMEOUT = 60000;   // 60 detik tanpa pong -> anggap mati

    /* ---------- Helper ---------- */
    function flush() {
      while (queue.length && ws && ws.readyState === 1) {
        try { ws.send(queue.shift()); }
        catch (e) { break; }
      }
    }

    function stopPing() {
      if (pingTimer) { clearInterval(pingTimer); pingTimer = null; }
    }

    function startPing() {
      stopPing();
      lastPong = Date.now();
      pingTimer = setInterval(function () {
        if (!ws || ws.readyState !== 1) return;

        /* Deteksi koneksi zombie: sudah lewat PONG_TIMEOUT tanpa pong */
        if (Date.now() - lastPong > PONG_TIMEOUT) {
          try { ws.close(); } catch (e) {}
          return;
        }

        try { ws.send(JSON.stringify({ type: "ping", t: Date.now() })); }
        catch (e) { /* diamkan */ }
      }, PING_EVERY);
    }

    function scheduleReconnect() {
      if (closedByUser) return;
      retry = Math.min(retry + 1, 6);
      var delay = Math.min(1000 * Math.pow(2, retry), 30000); // max 30 detik
      setTimeout(connect, delay);
    }

    /* ---------- Connect ---------- */
    function connect() {
      if (connecting || closedByUser) return;
      connecting = true;

      /* Bersihkan socket lama tanpa memicu onclose */
      try {
        if (ws) {
          ws.onclose = null;
          ws.onerror = null;
          ws.onmessage = null;
          ws.onopen = null;
          ws.close();
        }
      } catch (e) {}
      ws = null;

      var socket;
      try {
        socket = new WebSocket(wsUrl);
      } catch (err) {
        connecting = false;
        setStatus("offline");
        scheduleReconnect();
        return;
      }
      ws = socket;

      socket.onopen = function () {
        connecting = false;
        retry = 0;
        setStatus("online");
        flush();
        startPing();
      };

      socket.onmessage = function (ev) {
        var data;
        try { data = JSON.parse(ev.data); }
        catch (e) { return; }
        if (!data || typeof data !== "object") return;

        /* Pong dari server — tandai masih hidup */
        if (data.type === "pong") {
          lastPong = Date.now();
          return;
        }

        /* Ping dari server — balas pong */
        if (data.type === "ping") {
          try { socket.send(JSON.stringify({ type: "pong", t: Date.now() })); }
          catch (e) {}
          return;
        }

        emit(data);
      };

      socket.onerror = function () {
        /* Biarkan onclose yang menangani status & reconnect */
      };

      socket.onclose = function () {
        connecting = false;
        stopPing();
        setStatus("offline");
        scheduleReconnect();
      };
    }

    connect();

    /* ---------- Public API ---------- */
    var api = {
      send: function (msg) {
        var s;
        try { s = JSON.stringify(msg); }
        catch (e) { return; }

        if (ws && ws.readyState === 1) {
          try { ws.send(s); return; }
          catch (e) { /* fallthrough ke queue */ }
        }

        /* Simpan untuk dikirim saat reconnect */
        queue.push(s);
        if (queue.length > MAX_QUEUE) queue.shift();
      },
      close: function () {
        closedByUser = true;
        stopPing();
        try {
          if (ws) {
            ws.onclose = null;
            ws.onerror = null;
            ws.onmessage = null;
            ws.onopen = null;
            ws.close();
          }
        } catch (e) {}
        ws = null;
        queue.length = 0;
        setStatus("closed");
      },
      get isOpen() { return !!(ws && ws.readyState === 1); },
      mode: "ws"
    };

    window.transport = api; // debug: cek di console → window.transport.mode
    return api;
  }

  /* ============================================================
     MODE 2 — BroadcastChannel (demo / offline)
     ============================================================ */
  if (typeof BroadcastChannel === "undefined") {
    setStatus("unsupported");
    var noop = {
      send: function () {},
      close: function () {},
      get isOpen() { return false; },
      mode: "none"
    };
    window.transport = noop;
    return noop;
  }

  var bc;
  try {
    bc = new BroadcastChannel("pelacak-pasien");
  } catch (e) {
    setStatus("unsupported");
    var noop2 = {
      send: function () {},
      close: function () {},
      get isOpen() { return false; },
      mode: "none"
    };
    window.transport = noop2;
    return noop2;
  }

  bc.onmessage = function (ev) {
    var data = ev && ev.data;
    if (!data || typeof data !== "object") return;
    emit(data);
  };

  if ("onmessageerror" in bc) {
    bc.onmessageerror = function () { /* diamkan */ };
  }

  setStatus("demo");

  var apiDemo = {
    send: function (msg) {
      try { bc.postMessage(msg); } catch (e) {}
    },
    close: function () {
      try { bc.close(); } catch (e) {}
      setStatus("closed");
    },
    get isOpen() { return true; },
    mode: "demo"
  };
  window.transport = apiDemo;
  return apiDemo;
}

/* Expose global (opsional, berguna untuk debugging) */
window.createTransport = createTransport;