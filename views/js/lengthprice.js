document.addEventListener('DOMContentLoaded', function () {
    const lengthInput = document.querySelector('#custom_length');
    const pricePreview = document.querySelector('#calculated_price');
    const hiddenCustomizationValueInput = document.getElementById('length_customization_hidden_value');

    if (!lengthInput) {
        return;
    }
    if (!hiddenCustomizationValueInput) {
        return;
    }

    if (typeof lengthpriceCustomizationFieldId === 'undefined' || lengthpriceCustomizationFieldId === null) {
        // Error handling for undefined lengthpriceCustomizationFieldId can be kept if critical
        console.error('[LengthPrice JS] Zmienna lengthpriceCustomizationFieldId nie jest zdefiniowana...');
    } else {
        const expectedValueInputName = `product_customization[${lengthpriceCustomizationFieldId}][0][value]`;
        if (hiddenCustomizationValueInput.name !== expectedValueInputName) {
            console.error(`[LengthPrice JS] Niezgodność nazwy ukrytego pola wartości...`);
        }
    }

    const unitPrice = parseFloat(lengthInput.dataset.price || 0);
    if (isNaN(unitPrice) || unitPrice < 0) {
        console.warn('[LengthPrice JS] Nieprawidłowa lub brakująca cena jednostkowa...');
    }

    let debounceTimeout;

    function updateDisplayedPrice(length) {
        let calculatedDisplayPrice = 0;
        if (!isNaN(length) && unitPrice >= 0 && length >= 0 && pricePreview) {
            const lengthInBlocks = Math.ceil(length / 10);
            calculatedDisplayPrice = unitPrice * lengthInBlocks;
            pricePreview.textContent = calculatedDisplayPrice.toFixed(2);
        } else if (pricePreview) {
            pricePreview.textContent = '0.00';
        }
        return calculatedDisplayPrice;
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