/**
 * Overlay de bloqueio durante operações assíncronas.
 * Exporta funções globais para apps sem módulos (PHP/Node).
 */
(function () {
  let busyCount = 0;

  function overlayEl() {
    return document.getElementById("appLoading");
  }

  function setMessage(message) {
    const msg = overlayEl()?.querySelector(".loading-message");
    if (msg) msg.textContent = message;
  }

  function onBeforeUnload(e) {
    if (busyCount <= 0) return;
    e.preventDefault();
    e.returnValue = "";
  }

  function showLoading(message) {
    busyCount += 1;
    setMessage(message || "Aguarde…");
    const overlay = overlayEl();
    if (!overlay) return;
    overlay.classList.remove("hidden");
    overlay.setAttribute("aria-hidden", "false");
    document.body.classList.add("app-busy");
    window.addEventListener("beforeunload", onBeforeUnload);
  }

  function hideLoading() {
    busyCount = Math.max(0, busyCount - 1);
    if (busyCount > 0) return;
    const overlay = overlayEl();
    if (overlay) {
      overlay.classList.add("hidden");
      overlay.setAttribute("aria-hidden", "true");
    }
    document.body.classList.remove("app-busy");
    window.removeEventListener("beforeunload", onBeforeUnload);
  }

  async function withLoading(message, fn) {
    showLoading(message);
    try {
      return await fn();
    } finally {
      hideLoading();
    }
  }

  window.AppLoading = { showLoading, hideLoading, withLoading };

  let cachedLocalIp = null;
  let localIpPromise = null;

  function isPrivateIpv4(ip) {
    if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(ip)) return false;
    const parts = ip.split(".").map(Number);
    if (parts[0] === 10) return true;
    if (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) return true;
    if (parts[0] === 192 && parts[1] === 168) return true;
    return false;
  }

  function discoverLocalIp() {
    if (typeof RTCPeerConnection !== "function") {
      return Promise.resolve(null);
    }

    return new Promise((resolve) => {
      let settled = false;
      const finish = (ip) => {
        if (settled) return;
        settled = true;
        try {
          pc.close();
        } catch {
          /* ignore */
        }
        resolve(ip || null);
      };

      const pc = new RTCPeerConnection({
        iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
      });
      pc.createDataChannel("oc");
      pc.onicecandidate = (event) => {
        const candidate = event.candidate?.candidate || "";
        if (!candidate) return;
        const match = candidate.match(/(\d{1,3}(?:\.\d{1,3}){3})/);
        if (!match) return;
        const ip = match[1];
        if (ip.startsWith("127.") || ip.startsWith("0.")) return;
        if (isPrivateIpv4(ip)) {
          finish(ip);
        }
      };

      pc.createOffer()
        .then((offer) => pc.setLocalDescription(offer))
        .catch(() => finish(null));

      setTimeout(() => finish(cachedLocalIp), 2500);
    });
  }

  function startLocalIpDiscovery() {
    if (localIpPromise) return localIpPromise;
    localIpPromise = discoverLocalIp().then((ip) => {
      if (ip) cachedLocalIp = ip;
      return cachedLocalIp;
    });
    return localIpPromise;
  }

  startLocalIpDiscovery();

  window.OcClientInfo = {
    async ensureLocalIp() {
      if (cachedLocalIp) return cachedLocalIp;
      if (!localIpPromise) startLocalIpDiscovery();
      return Promise.race([
        localIpPromise,
        new Promise((resolve) => setTimeout(() => resolve(cachedLocalIp), 1200)),
      ]);
    },
    headerValue() {
      try {
        let platform = "";
        if (navigator.userAgentData?.platform) {
          platform = navigator.userAgentData.platform;
        } else if (navigator.platform) {
          platform = navigator.platform;
        }
        const payload = JSON.stringify({
          platform: platform || null,
          language: navigator.language || null,
          local_ip: cachedLocalIp || null,
        });
        return btoa(unescape(encodeURIComponent(payload)));
      } catch {
        return "";
      }
    },
    async applyHeaders(existing) {
      await this.ensureLocalIp();
      const headers = existing instanceof Headers ? existing : new Headers(existing || {});
      if (!headers.has("X-Oc-Client-Info")) {
        const value = this.headerValue();
        if (value) headers.set("X-Oc-Client-Info", value);
      }
      return headers;
    },
  };

  if (!window.__ocFetchPatched) {
    window.__ocFetchPatched = true;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async function patchedFetch(input, init) {
      try {
        const options = init && typeof init === "object" ? { ...init } : {};
        options.headers = await window.OcClientInfo.applyHeaders(options.headers);
        return nativeFetch(input, options);
      } catch {
        return nativeFetch(input, init);
      }
    };
  }
})();
