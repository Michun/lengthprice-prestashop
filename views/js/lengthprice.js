(function() {
    let lengthpriceContainer,
        lengthInput,
        pricePreview,
        hiddenCustomizationValueInput,
        addToCartButton,
        minLengthMessageElement,
        maxLengthMessageElement,
        MIN_LENGTH_MM,
        MAX_LENGTH_MM,
        lengthpriceCurrencySign,
        unitPrice,
        debounceTimeout,
        minLengthMessageTemplate,
        maxLengthMessageTemplate;

    const lengthpriceCurrencyDecimals = 2;

    /**
     * Formats the price to two decimal places and appends the currency symbol.
     * @param {number} price - Raw price value.
     * @returns {string} Formatted price with currency symbol.
     */
    function formatPrice(price) {
        const validPrice = isNaN(price) ? 0 : price;
        const sign = typeof lengthpriceCurrencySign !== 'undefined' ? lengthpriceCurrencySign : 'zł';
        const fixedPriceWithComma = validPrice.toFixed(lengthpriceCurrencyDecimals).replace('.', ',');
        return `${fixedPriceWithComma} ${sign}`;
    }

    /**
     * Validates the entered length against defined MIN and MAX.
     * @param {number} length - Entered length in mm.
     * @returns {boolean} True if the length is valid, false otherwise.
     */
    function isValidLength(length) {
        const min = typeof MIN_LENGTH_MM !== 'undefined' ? MIN_LENGTH_MM : 80;
        const max = typeof MAX_LENGTH_MM !== 'undefined' ? MAX_LENGTH_MM : 1200;
        return typeof length === 'number' && !isNaN(length) && length >= min && length <= max;
    }

    /**
     * Determines the type of validation error, if any.
     * @param {number} length - Entered length in mm.
     * @returns {string|null} Error type ('MIN', 'MAX') or null if length is valid.
     */
    function getValidationErrorType(length) {
        const min = typeof MIN_LENGTH_MM !== 'undefined' ? MIN_LENGTH_MM : 80;
        const max = typeof MAX_LENGTH_MM !== 'undefined' ? MAX_LENGTH_MM : 1200;

        if (typeof length !== 'number' || isNaN(length)) {
            return null;
        }
        if (length > 0 && length < min) {
            return 'MIN';
        }
        if (length > max) {
            return 'MAX';
        }
        return null;
    }

    /**
     * Displays or hides the appropriate validation message.
     * @param {string|null} errorType - Type of error ('MIN', 'MAX') or null to hide messages.
     */
    function displayValidationMessage(errorType) {
        const minElement = minLengthMessageElement;
        const maxElement = maxLengthMessageElement;

        if (minElement) {
            minElement.style.display = 'none';
        }
        if (maxElement) {
            maxElement.style.display = 'none';
        }

        const minTpl = typeof minLengthMessageTemplate !== 'undefined' ? minLengthMessageTemplate : '';
        const maxTpl = typeof maxLengthMessageTemplate !== 'undefined' ? maxLengthMessageTemplate : '';
        const minVal = typeof MIN_LENGTH_MM !== 'undefined' ? MIN_LENGTH_MM : 80;
        const maxVal = typeof MAX_LENGTH_MM !== 'undefined' ? MAX_LENGTH_MM : 1200;

        if (errorType === 'MIN' && minElement && minTpl) {
            minElement.textContent = minTpl.replace('%%MIN%%', minVal);
            minElement.style.display = 'block';
        } else if (errorType === 'MAX' && maxElement && maxTpl) {
            maxElement.textContent = maxTpl.replace('%%MAX%%', maxVal);
            maxElement.style.display = 'block';
        }
    }

    /**
     * Updates the "Add to cart" button state based on validation.
     * @param {boolean} isValid - True if the length is valid, false otherwise.
     */
    function updateAddToCartButtonState(isValid) {
        const button = addToCartButton;
        if (button) {
            button.disabled = !isValid;
        }
    }

    /**
     * Calculates and displays the price, and updates the button state and validation message.
     * @param {number} length - Entered length in mm.
     */
    function updateDisplayedState(length) {
        let calculatedRawPrice = 0;
        const isLengthFieldValid = isValidLength(length);
        const validationErrorType = getValidationErrorType(length);
        const currentUnitPrice = typeof unitPrice !== 'undefined' ? unitPrice : 0;

        if (!isLengthFieldValid) {
            displayValidationMessage(validationErrorType);
            updateAddToCartButtonState(isLengthFieldValid);
            if (pricePreview) {
                pricePreview.textContent = formatPrice(0);
            }
            return;
        }

        if (!isNaN(length) && currentUnitPrice >= 0 && length >= 0) {
            const lengthInBlocks = Math.ceil(length / 10);
            calculatedRawPrice = currentUnitPrice * lengthInBlocks;
        }

        if (pricePreview) {
            pricePreview.textContent = formatPrice(calculatedRawPrice);
        }
        displayValidationMessage(validationErrorType);
        updateAddToCartButtonState(isLengthFieldValid);
    }

    /**
     * Initializes or re-initializes the module's JavaScript logic.
     * Queries DOM elements, reads configuration, and sets initial state.
     */
    function initializeLengthPrice() {
        lengthpriceContainer = document.getElementById('lengthprice_container');
        if (!lengthpriceContainer) {
            console.error('[LengthPrice JS] Main container #lengthprice_container not found. Initialization aborted.');
            return;
        }

        lengthInput = document.querySelector('#custom_length');
        pricePreview = document.querySelector('#calculated_price');
        hiddenCustomizationValueInput = document.getElementById('length_customization_hidden_value');
        addToCartButton = document.querySelector('#add_to_cart button[data-button-action="add-to-cart"], button.add-to-cart');
        minLengthMessageElement = document.getElementById('lengthprice_min_length_message');
        maxLengthMessageElement = document.getElementById('lengthprice_max_length_message');

        if (!lengthInput) {
            console.error('[LengthPrice JS] #custom_length input not found. Initialization aborted.');
            if (addToCartButton) updateAddToCartButtonState(true);
            return;
        }

        if (!pricePreview) console.warn('[LengthPrice JS] #calculated_price element not found.');
        if (!hiddenCustomizationValueInput) console.warn('[LengthPrice JS] #length_customization_hidden_value element not found.');
        if (!addToCartButton) console.warn('[LengthPrice JS] Add to cart button not found.');
        if (!minLengthMessageElement) console.warn('[LengthPrice JS] Minimum length message element not found.');
        if (!maxLengthMessageElement) console.warn('[LengthPrice JS] Maximum length message element not found.');

        MIN_LENGTH_MM = parseInt(lengthpriceContainer.dataset.minLengthValue) || 80;
        MAX_LENGTH_MM = parseInt(lengthpriceContainer.dataset.maxLengthValue) || 1200;
        lengthpriceCurrencySign = lengthpriceContainer.dataset.currencySign || 'zł';

        unitPrice = parseFloat(lengthInput.dataset.price || 0);
        if (isNaN(unitPrice) || unitPrice < 0) {
            console.warn('[LengthPrice JS] Invalid or missing unit price from data-price attribute. Defaulting to 0.');
            unitPrice = 0;
        }

        minLengthMessageTemplate = minLengthMessageElement ? minLengthMessageElement.textContent : '';
        maxLengthMessageTemplate = maxLengthMessageElement ? maxLengthMessageElement.textContent : '';

        const initialLengthRaw = lengthInput.value;
        let initialLength = parseFloat(initialLengthRaw);

        if (isNaN(initialLength) || initialLengthRaw.trim() === '') {
            initialLength = 0;
            if (lengthInput.value.trim() === '') lengthInput.value = '0';
        }

        updateDisplayedState(initialLength);

        if (hiddenCustomizationValueInput) {
            hiddenCustomizationValueInput.value = initialLength.toString();
        }
        console.log('[LengthPrice JS] Initialized.');
    }

    document.addEventListener('DOMContentLoaded', initializeLengthPrice);

    document.addEventListener('input', function(event) {
        if (event.target.id === 'custom_length') {
            const currentLengthInput = event.target;

            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(() => {
                let length = parseFloat(currentLengthInput.value);
                if (isNaN(length)) {
                    length = 0;
                }
                updateDisplayedState(length);

                const currentHiddenInput = document.getElementById('length_customization_hidden_value');
                if (currentHiddenInput) {
                    currentHiddenInput.value = isNaN(parseFloat(currentLengthInput.value)) ? '' : parseFloat(currentLengthInput.value).toString();
                }
            }, 300);
        }
    });

    if (typeof prestashop !== 'undefined' && prestashop.on) {
        prestashop.on('updatedProduct', function() {
            initializeLengthPrice();
        });
    } else {
        console.warn('[LengthPrice JS] PrestaShop event system not detected. AJAX re-initialization might not work.');
    }

})();