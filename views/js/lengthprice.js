document.addEventListener('DOMContentLoaded', function () {
    console.log('[LengthPrice JS] DOMContentLoaded fired.');

    const lengthInput = document.querySelector('#custom_length');
    const pricePreview = document.querySelector('#calculated_price');
    // Ukryte pole, którego PrestaShop używa do zapisywania personalizacji
    // Jego atrybut 'name' jest ustawiany w szablonie .tpl i zawiera ID pola personalizacji
    const hiddenCustomizationInput = document.getElementById('length_customization_hidden');

    if (!lengthInput) {
        console.error('[LengthPrice JS] Element input #custom_length nie został znaleziony.');
        return;
    }
    if (!pricePreview) {
        console.warn('[LengthPrice JS] Element #calculated_price do podglądu ceny nie został znaleziony.');
    }
    if (!hiddenCustomizationInput) {
        console.error('[LengthPrice JS] Ukryte pole #length_customization_hidden nie zostało znalezione. Personalizacja nie zostanie zapisana.');
        // Można rozważyć zablokowanie przycisku dodania do koszyka lub wyświetlenie błędu użytkownikowi
        return;
    }

    // lengthpriceCustomizationFieldId jest ustawiane przez Media::addJsDef w hookHeader
    // i używane w TPL do nazwania ukrytego pola. Sprawdźmy dla pewności.
    if (typeof lengthpriceCustomizationFieldId === 'undefined' || lengthpriceCustomizationFieldId === null) {
        console.error('[LengthPrice JS] Zmienna lengthpriceCustomizationFieldId nie jest zdefiniowana. Upewnij się, że jest ustawiana przez Media::addJsDef i używana w szablonie TPL dla ukrytego pola.');
    } else {
        console.log('[LengthPrice JS] ID pola personalizacji (lengthpriceCustomizationFieldId):', lengthpriceCustomizationFieldId);
        const expectedName = `product_customization[${lengthpriceCustomizationFieldId}]`;
        if (hiddenCustomizationInput.name !== expectedName) {
            console.error(`[LengthPrice JS] Niezgodność nazwy ukrytego pola. Oczekiwano: ${expectedName}, Znaleziono: ${hiddenCustomizationInput.name}`);
        }
    }

    const unitPrice = parseFloat(lengthInput.dataset.price || 0);
    if (isNaN(unitPrice) || unitPrice < 0) { // Cena jednostkowa może być 0
        console.warn('[LengthPrice JS] Nieprawidłowa lub brakująca cena jednostkowa (data-price) w inpucie #custom_length.');
        // Można obsłużyć błąd, np. wyłączyć funkcjonalność lub pokazać cenę domyślną
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
            // Przykład: unitPrice to cena za 10mm (1cm), a length jest w mm
            const lengthInBlocks = Math.ceil(length / 10);
            calculatedDisplayPrice = unitPrice * lengthInBlocks;
            // Dostosuj tę logikę do swojego modelu cenowego, np.:
            // calculatedDisplayPrice = unitPrice * length; // Jeśli unitPrice jest za 1mm
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

                // Aktualizuj wartość widocznego pola input po walidacji/korekcie
                // Upewnij się, że nie formatujesz tutaj wartości w sposób, który uniemożliwi dalsze parsowanie (np. przecinek zamiast kropki)
                lengthInput.value = length; // parseFloat samo obsłuży kropkę jako separator

                updateDisplayedPrice(length);

                // Aktualizuj wartość ukrytego pola personalizacji PrestaShop
                if (hiddenCustomizationInput) {
                    hiddenCustomizationInput.value = length.toString(); // PrestaShop oczekuje stringa
                    console.log(`[LengthPrice JS] Zaktualizowano ukryte pole '${hiddenCustomizationInput.name}' na wartość: ${length.toString()}`);
                }
            }, 300); // Debounce 300ms
        });

        // Inicjalizacja wyświetlanej ceny i ukrytego pola przy ładowaniu strony, jeśli jest wartość domyślna
        const initialLength = parseFloat(lengthInput.value);
        if (!isNaN(initialLength) && initialLength >= 0) {
            updateDisplayedPrice(initialLength);
            if (hiddenCustomizationInput) {
                hiddenCustomizationInput.value = initialLength.toString();
                console.log(`[LengthPrice JS] Zainicjowano ukryte pole '${hiddenCustomizationInput.name}' wartością: ${initialLength.toString()}`);
            }
        } else {
            // Ustawienie domyślne, jeśli początkowa wartość jest nieprawidłowa lub jej nie ma
            const defaultInitialLength = parseFloat(lengthInput.min) || 0;
            lengthInput.value = defaultInitialLength;
            updateDisplayedPrice(defaultInitialLength);
            if (hiddenCustomizationInput) {
                hiddenCustomizationInput.value = defaultInitialLength.toString();
                console.log(`[LengthPrice JS] Zainicjowano ukryte pole '${hiddenCustomizationInput.name}' wartością domyślną: ${defaultInitialLength.toString()}`);
            }
        }
    }

    // Nie ma już potrzeby obsługi kliknięcia przycisku "Dodaj do koszyka" tutaj.
    // Standardowy formularz produktu PrestaShop zajmie się wysłaniem danych,
    // w tym wartości z ukrytego pola 'product_customization'.

    console.log('[LengthPrice JS] Inicjalizacja zakończona. Oczekiwanie na wprowadzenie długości.');
});