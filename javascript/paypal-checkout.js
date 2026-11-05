document.querySelectorAll('[data-paypal-checkout]').forEach((container) => {
    const message = container.querySelector('[data-payment-message]');
    const reservationId = container.dataset.reservationId;
    const purpose = container.dataset.paymentPurpose;
    const csrfToken = container.dataset.csrfToken;

    if (!window.paypal || !reservationId || !purpose || !csrfToken) {
        if (message) {
            message.textContent = 'PayPal checkout is not available. Please contact the shop.';
        }
        return;
    }

    const postPaymentAction = async (action, values) => {
        const response = await fetch('../billing/payment.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({ action, reservation_id: reservationId, purpose, ...values }),
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'The payment could not be processed.');
        }
        return result;
    };

    window.paypal.Buttons({
        createOrder: async () => {
            if (message) {
                message.textContent = '';
            }
            const result = await postPaymentAction('create_order', {});
            return result.order_id;
        },
        onApprove: async (data) => {
            try {
                if (message) {
                    message.textContent = 'Confirming your payment…';
                }
                await postPaymentAction('capture_order', { order_id: data.orderID });
                if (message) {
                    message.textContent = 'Payment received.';
                }
                window.location.reload();
            } catch (error) {
                if (message) {
                    message.textContent = error.message;
                }
            }
        },
        onCancel: () => {
            if (message) {
                message.textContent = 'Payment was cancelled. You can try again when ready.';
            }
        },
        onError: (error) => {
            if (message) {
                message.textContent = error instanceof Error ? error.message : 'PayPal checkout failed. Please try again.';
            }
        },
    }).render(container.querySelector('[data-paypal-buttons]'));
});
