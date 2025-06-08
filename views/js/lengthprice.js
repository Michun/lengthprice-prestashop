document.addEventListener('DOMContentLoaded', function () {
    console.log('[LengthPrice JS] DOMContentLoaded fired.');

    const lengthInput = document.querySelector('#custom_length');
    const pricePreview = document.querySelector('#calculated_price');
    // Ukryte pole dla wartości personalizacji, które będziemy aktualizować
    const hiddenCustomizationValueInput = document.getElementById('length_customization_hidden_value');

    if (!lengthInput) {
        console.error('[LengthPrice JS] Element input #custom_length nie został znaleziony.');
        return;
    }
    if (!pricePreview) {
        console.warn('[LengthPrice JS] Element #calculated_price do podglądu ceny nie został znaleziony.');
    }
    // Sprawdzamy, czy ukryte pole dla wartości istnieje
    if (!hiddenCustomizationValueInput) {
        console.error('[LengthPrice JS] Ukryte pole #length_customization_hidden_value nie zostało znalezione. Personalizacja nie zostanie zapisana.');
        return;
    }

    // lengthpriceCustomizationFieldId jest ustawiane przez Media::addJsDef w hookHeader
    // i używane w TPL do nazwania ukrytych pól. Sprawdźmy dla pewności.
    if (typeof lengthpriceCustomizationFieldId === 'undefined' || lengthpriceCustomizationFieldId === null) {
        console.error('[LengthPrice JS] Zmienna lengthpriceCustomizationFieldId nie jest zdefiniowana. Upewnij się, że jest ustawiana przez Media::addJsDef i używana w szablonie TPL dla ukrytych pól.');
    } else {
        console.log('[LengthPrice JS] ID pola personalizacji (lengthpriceCustomizationFieldId):', lengthpriceCustomizationFieldId);
        // Opcjonalna walidacja nazwy dla pola wartości
        const expectedValueInputName = `product_customization[${lengthpriceCustomizationFieldId}][0][value]`;
        if (hiddenCustomizationValueInput.name !== expectedValueInputName) {
            console.error(`[LengthPrice JS] Niezgodność nazwy ukrytego pola wartości. Oczekiwano: ${expectedValueInputName}, Znaleziono: ${hiddenCustomizationValueInput.name}`);
        }
    }

    const unitPrice = parseFloat(lengthInput.dataset.price || 0);
    if (isNaN(unitPrice) || unitPrice < 0) { // Cena jednostkowa może być 0
        console.warn('[LengthPrice JS] Nieprawidłowa lub brakująca cena jednostkowa (data-price) w inpucie #custom_length.');
    }
    console.log('[LengthPrice JS] Cena jednostkowa (unitPrice):', unitPrice);

    let debounceTimeout;

    /**
     * Aktualizuje wyświetlaną cenę na stronie produktu.
     * Ta funkcja służy tylko do wizualizacji; rzeczywista cena w koszyku
     * będzie obliczana przez PrestaShop po stronie serwera.
     * @param {number} length - Wprowadzona długość.
     */
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
                    length = 0; // Lub minLength, w zależności od pożądanego zachowania
                } else if (length < minLength) {
                    length = minLength;
                } else if (length > maxLength) {
                    length = maxLength;
                }

                lengthInput.value = length;
                updateDisplayedPrice(length);

                if (hiddenCustomizationValueInput) {
                    hiddenCustomizationValueInput.value = length.toString();
                    console.log(`[LengthPrice JS] Zaktualizowano ukryte pole '${hiddenCustomizationValueInput.name}' na wartość: ${length.toString()}`);
                }
            }, 300); // Debounce 300ms
        });

        const initialLength = parseFloat(lengthInput.value);
        if (!isNaN(initialLength) && initialLength >= 0) {
            updateDisplayedPrice(initialLength);
            if (hiddenCustomizationValueInput) {
                hiddenCustomizationValueInput.value = initialLength.toString();
                console.log(`[LengthPrice JS] Zainicjowano ukryte pole '${hiddenCustomizationValueInput.name}' wartością: ${initialLength.toString()}`);
            }
        } else {
            const defaultInitialLength = parseFloat(lengthInput.min) || 0;
            lengthInput.value = defaultInitialLength;
            updateDisplayedPrice(defaultInitialLength);
            if (hiddenCustomizationValueInput) {
                hiddenCustomizationValueInput.value = defaultInitialLength.toString();
                console.log(`[LengthPrice JS] Zainicjowano ukryte pole '${hiddenCustomizationValueInput.name}' wartością domyślną: ${defaultInitialLength.toString()}`);
            }
        }
    }

    console.log('[LengthPrice JS] Inicjalizacja zakończona. Oczekiwanie na wprowadzenie długości.');
});