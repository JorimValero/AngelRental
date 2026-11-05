'use strict';

import html2canvas from 'html2canvas';

const downloadButton = document.querySelector('[data-receipt-download]');
const receipt = document.querySelector('#paypal-receipt-content');
const status = document.querySelector('[data-receipt-download-status]');

if (downloadButton instanceof HTMLButtonElement && receipt && status) {
    downloadButton.addEventListener('click', async () => {
        downloadButton.disabled = true;
        status.hidden = true;

        try {
            await document.fonts.ready;
            const canvas = await html2canvas(receipt, {
                backgroundColor: '#fffdfd',
                scale: 2,
                useCORS: true,
            });
            const image = await new Promise((resolve, reject) => {
                canvas.toBlob((blob) => {
                    if (blob) {
                        resolve(blob);
                    } else {
                        reject(new Error('The receipt image could not be created.'));
                    }
                }, 'image/png');
            });
            const downloadLink = document.createElement('a');
            downloadLink.href = URL.createObjectURL(image);
            downloadLink.download = downloadButton.dataset.filename || 'receipt.png';
            document.body.append(downloadLink);
            downloadLink.click();
            downloadLink.remove();
            window.setTimeout(() => URL.revokeObjectURL(downloadLink.href), 1000);
        } catch (error) {
            console.error('Could not download receipt image.', error);
            status.textContent = 'The receipt image could not be downloaded. Please try again.';
            status.hidden = false;
        } finally {
            downloadButton.disabled = false;
        }
    });
}
