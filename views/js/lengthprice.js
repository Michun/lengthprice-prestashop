document.addEventListener('DOMContentLoaded', function () {
    const lengthInput = document.querySelector('#custom_length');
    const pricePreview = document.querySelector('#calculated_price');
    const customizationField = document.getElementById('length_customization_hidden');
    const unitPrice = parseFloat(document.querySelector('[name="custom_length"]').dataset.price || 0);
    let debounceTimeout;

    function updatePrice(length) {
        let price = 0;
        if (!isNaN(length)) {
            const length_cm_ceil = Math.ceil(length / 10);
            price = (unitPrice * length_cm_ceil).toFixed(2);
            pricePreview.textContent = `${price}`;
        } else {
            pricePreview.textContent = `0.00`;
        }
        return price;
    }

    const addToCartButton = document.querySelector('button[data-button-action="add-to-cart"]');
    if (addToCartButton) {
        addToCartButton.addEventListener('click', function (e) {
            e.preventDefault();
            const length = parseFloat(lengthInput.value);
            if (length < 80 || length > 1200) {
                alert('Podaj długość od 80 do 1200 mm.');
                return;
            }
            const customizationText = length;
            const formData = new FormData();
            formData.append('textField' + lengthpriceCustomizationFieldId, customizationText);
            formData.append('submitCustomizedData', '');
            formData.append('ajax', '1');
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'ok' && data.id_customization) {
                    const cartForm = new FormData();
                    cartForm.append('id_product', document.querySelector('[name="id_product"]').value);
                    cartForm.append('qty', document.querySelector('[name="qty"]').value);
                    cartForm.set('id_customization', data.id_customization);
                    cartForm.append('add', '1');
                    fetch('/cart', {
                        method: 'POST',
                        body: cartForm
                    })
                    .then(() => window.location.href = '/cart?action=show')
                    .catch(err => {
                        console.error(err);
                        alert('Błąd podczas dodawania do koszyka.');
                    });
                } else {
                    alert('Nie udało się zapisać personalizacji.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Błąd podczas wysyłania personalizacji.');
            });
        });
    }

    lengthInput.addEventListener('input', function () {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => {
            let length = parseFloat(lengthInput.value);
            if (length < 0) length = 0;
            if (length > 1200) length = 1200;
            lengthInput.value = length;

            updatePrice(length);

            customizationField.value = length;
        }, 200);
    });
});
