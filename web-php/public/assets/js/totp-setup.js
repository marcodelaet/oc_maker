(function () {
  function renderQr(container, uri) {
    if (typeof qrcode !== 'function') {
      throw new Error('Biblioteca QR Code não carregada.');
    }
    if (qrcode.stringToBytesFuncs?.['UTF-8']) {
      qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
    }
    const qr = qrcode(0, 'M');
    qr.addData(uri);
    qr.make();
    container.innerHTML = qr.createSvgTag(5, 2);
    const svg = container.querySelector('svg');
    if (svg) {
      svg.setAttribute('role', 'img');
      svg.setAttribute('aria-label', 'QR Code para Google ou Microsoft Authenticator');
      svg.style.display = 'block';
      svg.style.margin = '0 auto';
      svg.style.maxWidth = '220px';
      svg.style.height = 'auto';
    }
  }

  async function copyText(text) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  window.OcTotpSetup = { renderQr, copyText };
})();
