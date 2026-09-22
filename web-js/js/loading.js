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

export function showLoading(message) {
  busyCount += 1;
  setMessage(message || "Aguarde…");
  const overlay = overlayEl();
  if (!overlay) return;
  overlay.classList.remove("hidden");
  overlay.setAttribute("aria-hidden", "false");
  document.body.classList.add("app-busy");
  window.addEventListener("beforeunload", onBeforeUnload);
}

export function hideLoading() {
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

export async function withLoading(message, fn) {
  showLoading(message);
  try {
    return await fn();
  } finally {
    hideLoading();
  }
}

export function runWithLoading(message, fn) {
  showLoading(message);
  try {
    return fn();
  } finally {
    hideLoading();
  }
}
