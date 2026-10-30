(function () { const el = document.getElementById('qr'); if (el && typeof QRCode !== 'undefined') new QRCode(el, { text: el.dataset.uri, width: 180, height: 180 }); })();
