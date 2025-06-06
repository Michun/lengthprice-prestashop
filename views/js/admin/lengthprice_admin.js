/**
 * 2007-2024 PrestaShop
 *
 * LengthPrice module
 *
 * NOTICE OF LICENSE
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 * @author    Michał Nowacki
 * @copyright 2025 Michał Nowacki
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

$(document).ready(function() {
    // Znajdź panel modułu
    var $lengthPricePanel = $('#lengthprice_settings_panel');
    if ($lengthPricePanel.length === 0) {
        console.error('LengthPrice: Panel element #lengthprice_settings_panel not found.');
        return; // Zakończ, jeśli panel nie istnieje
    }

    var productId = $lengthPricePanel.data('product-id');
    var ajaxUrl = $lengthPricePanel.data('ajax-url');
    var $saveButton = $('#lengthprice_save_settings_button');
    var $checkbox = $('#lengthprice_enabled_checkbox');
    var $statusSpan = $('#lengthprice_save_status');

    if (!productId || !ajaxUrl) {
        console.error('LengthPrice: Missing product ID or AJAX URL.');
        $saveButton.prop('disabled', true).text('Error loading settings');
        return;
    }

    // Obsługa kliknięcia przycisku Zapisz
    $saveButton.on('click', function(e) {
        e.preventDefault(); // Zapobiegaj domyślnej akcji formularza

        // Pobierz aktualny stan checkboxa
        var isEnabled = $checkbox.is(':checked') ? 1 : 0;

        // Pokaż status ładowania
        $statusSpan.text('Saving...').css('color', 'gray');
        $saveButton.prop('disabled', true); // Wyłącz przycisk podczas zapisu

        // Wyślij żądanie AJAX
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                // ajax: 1, // Wymagane przez kontrolery AJAX w PrestaShop
                // action: 'saveProductSettingsAction', // Nazwa metody ajaxProcess... bez prefiksu 'ajaxProcess'
                id_product: productId,
                lengthprice_enabled: isEnabled,
                // Dodaj token bezpieczeństwa, jeśli jest wymagany przez PrestaShop (zalecane)
                // security_token: prestashop.security.activity_token, // Przykład dla PS 1.7/8, może wymagać dostosowania
            },
            success: function(response) {
                if (response.success) {
                    $statusSpan.text('Saved!').css('color', 'green');
                } else {
                    $statusSpan.text('Error: ' + response.message).css('color', 'red');
                }
            },
            error: function(xhr, status, error) {
                $statusSpan.text('AJAX Error: ' + error).css('color', 'red');
                console.error('LengthPrice AJAX Error:', status, error, xhr);
            },
            complete: function() {
                // Przywróć przycisk po zakończeniu żądania
                $saveButton.prop('disabled', false);
                // Ukryj status po kilku sekundach
                setTimeout(function() {
                    $statusSpan.text('');
                }, 5000); // Ukryj po 5 sekundach
            }
        });
    });

    // Opcjonalnie: Możesz dodać obsługę zmiany stanu checkboxa,
    // aby automatycznie zapisywać lub aktywować przycisk zapisu.
    // $checkbox.on('change', function() {
    //     // Możesz tutaj np. automatycznie wywołać $saveButton.trigger('click');
    //     // lub po prostu upewnić się, że przycisk zapisu jest widoczny/aktywny.
    // });
});