// /Users/michalnowacki/Projects/prestashop-dev/modules/lengthprice/views/js/lengthprice.js
document.addEventListener('DOMContentLoaded', function () {
    const lengthpriceContainer = document.getElementById('lengthprice_container'); // Pobierz kontener

    if (!lengthpriceContainer) {
        console.error('[LengthPrice JS] Main container #lengthprice_container not found.');
        return;
    }

    const lengthInput = document.querySelector('#custom_length');
    const pricePreview = document.querySelector('#calculated_price');
    const hiddenCustomizationValueInput = document.getElementById('length_customization_hidden_value');

    if (!lengthInput || !pricePreview || !hiddenCustomizationValueInput) {
        console.error('[LengthPrice JS] Missing one or more required elements.');
        return;
    }

    // Odczytaj dane waluty z atrybutów data-*
    const lengthpriceCurrencySign = lengthpriceContainer.dataset.currencySign || 'zł';
    const lengthpriceCurrencyDecimals = 2;

    const unitPrice = parseFloat(lengthInput.dataset.price || 0);
    if (isNaN(unitPrice) || unitPrice < 0) {
        console.warn('[LengthPrice JS] Invalid or missing unit price.');
    }

    let debounceTimeout;

    function formatPrice(price) {
        const fixedPrice = price.toFixed(lengthpriceCurrencyDecimals);
        let formattedPrice = fixedPrice + ' ' + lengthpriceCurrencySign;
        return formattedPrice;
    }


    function updateDisplayedPrice(length) {
        let calculatedRawPrice = 0;
        if (!isNaN(length) && unitPrice >= 0 && length >= 0) {
            const lengthInBlocks = Math.ceil(length / 10);
            calculatedRawPrice = unitPrice * lengthInBlocks;
        }
        pricePreview.textContent = formatPrice(calculatedRawPrice);
    }

    if (lengthInput) {
        lengthInput.addEventListener('input', function () {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(() => {
                let length = parseFloat(lengthInput.value);
                const minLength = parseFloat(lengthInput.min) || 0;
                const maxLength = parseFloat(lengthInput.max) || Infinity;

                if (isNaN(length)) {
                    length = 0;
                } else if (length < minLength) {
                    length = minLength;
                } else if (length > maxLength) {
                    length = maxLength;
                }

                lengthInput.value = length;
                updateDisplayedPrice(length);

                if (hiddenCustomizationValueInput) {
                    hiddenCustomizationValueInput.value = length.toString();
                }
            }, 300);
        });

        const initialLength = parseFloat(lengthInput.value);
        if (!isNaN(initialLength) && initialLength >= 0) {
            updateDisplayedPrice(initialLength);
            if (hiddenCustomizationValueInput) {
                hiddenCustomizationValueInput.value = initialLength.toString();
            }
        } else {
            const defaultInitialLength = parseFloat(lengthInput.min) || 0;
            lengthInput.value = defaultInitialLength;
            updateDisplayedPrice(defaultInitialLength);
            if (hiddenCustomizationValueInput) {
                hiddenCustomizationValueInput.value = defaultInitialLength.toString();
            }
        }
    }
});