<div class="panel" style="border: 1px solid #ccc; padding: 20px;"
     id="lengthprice_container"
     data-currency-sign="{$lengthprice_currency_sign|escape:'htmlall':'UTF-8'}"
>
  <div class="text-left mb-3">
    <span style="font-size: 2em; color: #1e4f7a;">
      <span id="calculated_price">{$initial_calculated_price}</span>
    </span>
  </div>
  <div class="d-flex justify-content-left align-items-center">
    <label for="custom_length" class="me-2 mb-0">{l s='enter length' mod='lengthprice'}</label>
    <input type="number" step="1" min="0" max="1200" id="custom_length" name="custom_length"
           class="form-control" style="max-width: 100px;" value="0" data-price="{$price_per_unit|floatval}">
    <span class="ms-2">{l s='mm' mod='lengthprice'}</span>
  </div>
</div>
{* Ukryte pole dla typu personalizacji - PrestaShop używa tego do identyfikacji typu pola *}
<input type="hidden"
       name="product_customization[{$customization_field_id}][0][type]"
       id="length_customization_hidden"
       value="1" />

{* Ukryte pole dla wartości personalizacji - to będzie odczytywane przez Twoje nadpisanie *}
<input type="hidden"
       name="product_customization[{$customization_field_id}][0][value]"
       id="length_customization_hidden_value" {* Zmienione ID dla jasności *}
       value="" />