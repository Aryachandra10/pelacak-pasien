/* global CONFIG */

function createTransport(onMessage, onStatus) {
  "use strict";

  var status = typeof onStatus === "function" ? onStatus : function () {};
  var emit = function (msg) {
    if (typeof onMessage !== "function") return;
    try { onMessage(msg); } catch (e) { console.warn("[transport] onMessage error:", e); }
  };
  var cfg = typeof CONFIG !== "undefined" && CONFIG ? CONFIG : {};
  var eventsUrl = cfg.SSE_URL || "";
  var publishUrl = cfg.SSE_PUBLISH_URL || "";

  if (eventsUrl) {
    var source = null;
    var closedByUser = false;
    var retryTimer = null;

    function connect() {
      if (closedByUser) return;
      source = new EventSource(eventsUrl);
      source.onopen = function () { status("online"); };
      source.onmessage = function (event) {
        try { emit(JSON.parse(event.data)); } catch (_) {}
      };
      source.onerror = function () {
        status("offline");
        if (source) source.close();
        if (!closedByUser && !retryTimer) {
          retryTimer = setTimeout(function () {
            retryTimer = null;
            connect();
          }, 3000);
        }
      };
    }
    connect();

    var apiSse = {
      send: function (msg) {
        if (!publishUrl) {
          console.warn("[transport] SSE_PUBLISH_URL belum dikonfigurasi");
          return;
        }
        fetch(publishUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(msg),
          keepalive: true
        }).catch(function () { status("offline"); });
      },
      close: function () {
        closedByUser = true;
        if (retryTimer) clearTimeout(retryTimer);
        if (source) source.close();
        status("closed");
      },
      get isOpen() { return !!source && source.readyState === EventSource.OPEN; },
      mode: "sse"
    };
    window.transport = apiSse;
    return apiSse;
  }

  if (typeof BroadcastChannel === "undefined") {
    status("unsupported");
    return { send: function () {}, close: function () {}, get isOpen() { return false; }, mode: "none" };
  }

  var bc = new BroadcastChannel("pelacak-pasien");
  bc.onmessage = function (event) { if (event.data) emit(event.data); };
  status("demo");
  var apiDemo = {
    send: function (msg) { try { bc.postMessage(msg); } catch (_) {} },
    close: function () { bc.close(); status("closed"); },
    get isOpen() { return true; },
    mode: "demo"
  };
  window.transport = apiDemo;
  return apiDemo;
}

window.createTransport = createTransport;
