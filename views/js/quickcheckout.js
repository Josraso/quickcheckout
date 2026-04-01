/**
 * QuickCheckout — JS v1.3
 * Selector de dirección por divs (sin radio buttons problemáticos)
 * + envío AJAX del pedido
 */

/* Selección de dirección — llamado desde onclick en el TPL */
function qcSelectAddress(el, addressId) {
    // Quitar activo de todas las tarjetas
    var cards = document.querySelectorAll('.qc-address-card');
    cards.forEach(function (c) { c.classList.remove('qc-address-card--active'); });

    // Activar la seleccionada
    el.classList.add('qc-address-card--active');

    // Actualizar el hidden input
    var hidden = document.getElementById('qc-address-id');
    if (hidden) { hidden.value = addressId; }
}

(function ($) {
    'use strict';

    var QC = {
        cfg: window.quickcheckout || {},

        init: function () {
            QC.bindConfirmButton();
        },

        getSelectedAddressId: function () {
            var hidden = document.getElementById('qc-address-id');
            return hidden ? parseInt(hidden.value, 10) : 0;
        },

        bindConfirmButton: function () {
            $(document).on('click', '#qc-confirm-btn', function (e) {
                e.preventDefault();
                if ($(this).prop('disabled')) { return; }
                QC.submitOrder($(this));
            });
        },

        submitOrder: function ($btn) {
            var addressId  = QC.getSelectedAddressId();
            var processUrl = $btn.data('process-url') || QC.cfg.processUrl || '';
            var token      = $btn.data('token') || '';

            $('#qc-ajax-error').hide();

            $btn.addClass('qc-btn--loading').prop('disabled', true);

            $.ajax({
                url:      processUrl,
                method:   'POST',
                dataType: 'json',
                data: {
                    ajax:                1,
                    token:               token,
                    id_address_delivery: addressId,
                },
                success: function (response) {
                    if (response && response.success) {
                        window.location.href = response.redirectUrl;
                    } else {
                        var msg = (response && response.error)
                            ? response.error
                            : (QC.cfg.labels ? QC.cfg.labels.error : 'Error al procesar el pedido.');
                        QC.showError(msg);
                        $btn.removeClass('qc-btn--loading').prop('disabled', false);
                    }
                },
                error: function () {
                    QC.showError(QC.cfg.labels ? QC.cfg.labels.error : 'Error al procesar el pedido.');
                    $btn.removeClass('qc-btn--loading').prop('disabled', false);
                },
            });
        },

        showError: function (message) {
            $('#qc-ajax-error-msg').text(message);
            $('#qc-ajax-error').show();
            $('html, body').animate({ scrollTop: $('#qc-ajax-error').offset().top - 80 }, 300);
        },
    };

    $(document).ready(function () { QC.init(); });

})(jQuery);
