document.addEventListener('DOMContentLoaded', function () {
    const lengthpriceContainer = document.getElementById('lengthprice_container');

    if (!lengthpriceContainer) {
        console.error('[LengthPrice JS] Main container #lengthprice_container not found.');

    }

    const lengthInput = document.querySelector('#custom_length');
    const pricePreview = document.querySelector('#calculated_price');
    const hiddenCustomizationValueInput = document.getElementById('length_customization_hidden_value');
    const addToCartButton = document.querySelector('#add_to_cart button[data-button-action="add-to-cart"], button.add-to-cart');

    const minLengthMessageElement = document.getElementById('lengthprice_min_length_message');
    const maxLengthMessageElement = document.getElementById('lengthprice_max_length_message');

    if (!lengthInput || !pricePreview || !hiddenCustomizationValueInput || !addToCartButton) {
        console.error('[LengthPrice JS] Missing one or more required elements (input, preview, hidden, or add to cart button).');
        if (!addToCartButton) {
            console.warn('[LengthPrice JS] Add to cart button not found. Cannot disable/enable.');
        }
        return;
    }
    if (!minLengthMessageElement) {
        console.warn('[LengthPrice JS] Minimum length message element not found.');
    }
    if (!maxLengthMessageElement) {
        console.warn('[LengthPrice JS] Maximum length message element not found.');
    }


    const MIN_LENGTH_MM = parseInt(lengthpriceContainer.dataset.minLengthValue) || 80;
    const MAX_LENGTH_MM = parseInt(lengthpriceContainer.dataset.maxLengthValue) || 1200;

    const lengthpriceCurrencySign = lengthpriceContainer.dataset.currencySign || 'zł';
    const lengthpriceCurrencyDecimals = 2;

    const unitPrice = parseFloat(lengthInput.dataset.price || 0);
    if (isNaN(unitPrice) || unitPrice < 0) {
        console.warn('[LengthPrice JS] Invalid or missing unit price.');
    }

    let debounceTimeout;

    const minLengthMessageTemplate = minLengthMessageElement ? minLengthMessageElement.textContent : '';
    const maxLengthMessageTemplate = maxLengthMessageElement ? maxLengthMessageElement.textContent : '';

    /**
     * Formats the price to two decimal places and appends the currency symbol.
     * @param {number} price - Raw price value.
     * @returns {string} Formatted price with currency symbol.
     */
    function formatPrice(price) {
        const validPrice = isNaN(price) ? 0 : price;
        const fixedPriceWithComma = validPrice.toFixed(lengthpriceCurrencyDecimals).replace('.', ',');
        return `${fixedPriceWithComma} ${lengthpriceCurrencySign}`;
    }

    /**
     * Validates the entered length against defined MIN and MAX.
     * @param {number} length - Entered length in mm.
     * @returns {boolean} True if the length is valid, false otherwise.
     */
    function isValidLength(length) {
        return typeof length === 'number' && !isNaN(length) && length >= MIN_LENGTH_MM && length <= MAX_LENGTH_MM;
    }

    /**
     * Determines the type of validation error, if any.
     * @param {number} length - Entered length in mm.
     * @returns {string|null} Error type ('MIN', 'MAX') or null if length is valid.
     */
    function getValidationErrorType(length) {
        if (typeof length !== 'number' || isNaN(length)) {
            return null; // No message for non-numeric or empty on initial load/typing
        }
        if (length > 0 && length < MIN_LENGTH_MM) { // Show message only if a positive value is entered but too small
            return 'MIN';
        }
        if (length > MAX_LENGTH_MM) {
            return 'MAX';
        }
        return null; // Length is valid or 0
    }

    /**
     * Displays or hides the appropriate validation message.
     * @param {string|null} errorType - Type of error ('MIN', 'MAX') or null to hide messages.
     */
    function displayValidationMessage(errorType) {
        if (minLengthMessageElement) {
            minLengthMessageElement.style.display = 'none';
        }
        if (maxLengthMessageElement) {
            maxLengthMessageElement.style.display = 'none';
        }

        if (errorType === 'MIN' && minLengthMessageElement) {
            minLengthMessageElement.textContent = minLengthMessageTemplate.replace('%%MIN%%', MIN_LENGTH_MM);
            minLengthMessageElement.style.display = 'block';
        } else if (errorType === 'MAX' && maxLengthMessageElement) {
            maxLengthMessageElement.textContent = maxLengthMessageTemplate.replace('%%MAX%%', MAX_LENGTH_MM);
            maxLengthMessageElement.style.display = 'block';
        }
    }

    /**
     * Updates the "Add to cart" button state based on validation.
     * @param {boolean} isValid - True if the length is valid, false otherwise.
     */
    function updateAddToCartButtonState(isValid) {
        if (addToCartButton) {
            addToCartButton.disabled = !isValid;
        }
    }

    /**
     * Calculates and displays the price, and updates the button state and validation message.
     * @param {number} length - Entered length in mm.
     */
    function updateDisplayedState(length) {
        let calculatedRawPrice = 0;
        const isLengthFieldValid = isValidLength(length); // Validation for enabling/disabling cart button
        const validationErrorType = getValidationErrorType(length); // Validation for displaying messages

        if (!isLengthFieldValid) {
            displayValidationMessage(validationErrorType);
            updateAddToCartButtonState(isLengthFieldValid);
            pricePreview.textContent = formatPrice(0);
            return;
        }

        if (!isNaN(length) && unitPrice >= 0 && length >= 0) {
            const lengthInBlocks = Math.ceil(length / 10);
            calculatedRawPrice = unitPrice * lengthInBlocks;
        }

        pricePreview.textContent = formatPrice(calculatedRawPrice);
        displayValidationMessage(validationErrorType);
        updateAddToCartButtonState(isLengthFieldValid);
    }

    if (lengthInput) {
        lengthInput.addEventListener('input', function () {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(() => {
                let length = parseFloat(lengthInput.value);

                if (isNaN(length)) {
                    length = 0;
                }

                updateDisplayedState(length);

                if (hiddenCustomizationValueInput) {
                    hiddenCustomizationValueInput.value = isNaN(parseFloat(lengthInput.value)) ? '' : parseFloat(lengthInput.value).toString();
                }
            }, 300);
        });

        const initialLengthRaw = lengthInput.value;
        let initialLength = parseFloat(initialLengthRaw);

        if (isNaN(initialLength) || initialLengthRaw.trim() === '') {
            initialLength = 0; // Default to 0 if empty or not a number
            if (lengthInput.value.trim() === '') lengthInput.value = '0'; // Set input to '0' if it was empty
        }

        updateDisplayedState(initialLength);

        if (hiddenCustomizationValueInput) {
            hiddenCustomizationValueInput.value = initialLength.toString();
        }
    } else {
        // Fallback if lengthInput is not found
        if(addToCartButton) updateAddToCartButtonState(true); // Enable button if no length input
    }
});