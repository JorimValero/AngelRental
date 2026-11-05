'use strict';

import QRCode from 'qrcode';

const qrContainer = document.querySelector('[data-product-qr]');
const qrValue = qrContainer?.dataset.productQr;

if (qrContainer && qrValue) {
    QRCode.toCanvas(qrValue, {
        errorCorrectionLevel: 'M',
        margin: 2,
        width: 240,
        color: {
            dark: '#241d20',
            light: '#ffffff',
        },
    }, (error, canvas) => {
        if (error) {
            console.error('Could not generate product QR tag.', error);
            qrContainer.textContent = 'The QR code could not be generated. Refresh the page and try again.';
            return;
        }

        qrContainer.replaceChildren(canvas);
    });
}
