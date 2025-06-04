<div class="panel" style="border: 1px solid #ccc; padding: 20px;">
  <div class="text-left mb-3">
    <span style="font-size: 2em; color: #1e4f7a;">
      <span id="calculated_price">0,00</span> zł
    </span>
  </div>
  <div class="d-flex justify-content-left align-items-center">
    <label for="custom_length" class="me-2 mb-0">podaj długość</label>
    <input type="number" step="1" min="0" max="1200" id="custom_length" name="custom_length"
           class="form-control" style="max-width: 100px;" value="0" data-price="{$price_per_unit}">
    <span class="ms-2">mm</span>
  </div>
</div>
<input type="hidden"
       name="product_customization[{$customization_field_id}]"
       id="length_customization_hidden"
       value="" />