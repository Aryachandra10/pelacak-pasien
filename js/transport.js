/* ============================================================
   transport.js — Satu antarmuka kirim/terima lokasi realtime.
   - WS_URL terisi -> WebSocket (live sungguhan, auto-reconnect)
   - WS_URL kosong -> BroadcastChannel (demo / offline)
   ============================================================ */

/* eslint-disable no-unused-vars */
function createTransport(onMessage, onStatus) {
  "use strict";

  var status = (typeof onStatus === "function") ? onStatus : function () {};
  var cfg    = (typeof CONFIG !== "undefined" && CONFIG) ? CONFIG : {};
  var wsUrl  = cfg.WS_URL || "";

  /* Paksa wss:// kalau halaman di-serve via HTTPS (hindari mixed content) */
  if (location.protocol === "https:" && wsUrl.indexOf("ws://") === 0) {
    wsUrl = wsUrl.replace("ws://", "wss://");
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
    var MAX_QUEUE    = 50;
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
      if (pingTimer) {
        clearInterval(pingTimer);
        pingTimer = null;
      }
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

        try {
          ws.send(JSON.stringify({ type: "ping", t: Date.now() }));
        } catch (e) { /* diamkan */ }
      }, PING_EVERY);
    }

    function scheduleReconnect() {
      if (closedByUser) return;
      retry = Math.min(retry + 1, 6);
      var delay = Math.min(1000 * Math.pow(2, retry), 30000);
      setTimeout(connect, delay);
    }

    /* ---------- Connect ---------- */
    function connect() {
      if (connecting || closedByUser) return;
      connecting = true;

      /* Tutup WS lama tanpa memicu onclose */
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
        status("error");
        scheduleReconnect();
        return;
      }
      ws = socket;

      socket.onopen = function () {
        connecting = false;
        retry = 0;
        status("online");
        flush();
        startPing();
      };

      socket.onmessage = function (ev) {
        var data;
        try { data = JSON.parse(ev.data); }
        catch (e) { return; }

        if (!data || typeof data !== "object") return;

        /* Pong — tandai server masih hidup */
        if (data.type === "pong") {
          lastPong = Date.now();
          return;
        }

        /* Ping dari server — balas pong */
        if (data.type === "ping") {
          try {
            socket.send(JSON.stringify({ type: "pong", t: Date.now() }));
          } catch (e) {}
          return;
        }

        onMessage(data);
      };

      socket.onerror = function () {
        status("error");
      };

      socket.onclose = function () {
        connecting = false;
        stopPing();
        status("offline");
        scheduleReconnect();
      };
    }

    connect();

    /* ---------- Public API ---------- */
    return {
      send: function (msg) {
        var s;
        try { s = JSON.stringify(msg); }
        catch (e) { return; }

        if (ws && ws.readyState === 1) {
          try { ws.send(s); }
          catch (e) {
            queue.push(s);
            if (queue.length > MAX_QUEUE) queue.shift();
          }
        } else {
          queue.push(s);
          if (queue.length > MAX_QUEUE) queue.shift();
        }
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
      },
      get isOpen() {
        return !!(ws && ws.readyState === 1);
      }
    };
  }

  /* ============================================================
     MODE 2 — BroadcastChannel (demo / offline / fallback)
     ============================================================ */
  if (typeof BroadcastChannel === "undefined") {
    status("unsupported");
    return {
      send: function () {},
      close: function () {},
      isOpen: false
    };
  }

  var bc;
  try {
    bc = new BroadcastChannel("pelacak-pasien");
  } catch (e) {
    status("unsupported");
    return {
      send: function () {},
      close: function () {},
      isOpen: false
    };
  }

  bc.onmessage = function (ev) {
    var data = ev && ev.data;
    if (!data || typeof data !== "object") return;
    onMessage(data);
  };

  if ("onmessageerror" in bc) {
    bc.onmessageerror = function () { /* diamkan */ };
  }

  status("demo");

  return {
    send: function (msg) {
      try { bc.postMessage(msg); } catch (e) {}
    },
    close: function () {
      try { bc.close(); } catch (e) {}
    },
    isOpen: true
  };
}