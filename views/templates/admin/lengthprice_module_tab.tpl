{* modules/lengthprice/views/templates/admin/lengthprice_module_tab.tpl *}

<div class="panel panel-default" id="lengthprice_settings_panel" data-product-id="{$id_product|intval}" data-ajax-url="{$ajax_controller_url|escape:'htmlall':'UTF-8'}">
    <div class="panel-heading">
        🔧 {l s='LengthPrice – module settings' mod='lengthprice'}
    </div>
    <div class="panel-body">
        <div class="form-group">
            <div class="checkbox">
                <label>
                    {* Ukryte pole dla wartości 0, jeśli checkbox jest odznaczony *}
                    <input type="hidden" name="lengthprice_enabled" value="0" />
                    {* Checkbox dla wartości 1 *}
                    <input type="checkbox" name="lengthprice_enabled" id="lengthprice_enabled_checkbox" value="1" {if $lengthprice_enabled == 1}checked="checked"{/if} />
                    <strong>{l s='Enable price calculation based on length' mod='lengthprice'}</strong>
                </label>
            </div>
            <p class="help-block">
                {l s='When this option is enabled, the customer on the product page will be able to enter the length, and the price will be automatically recalculated.' mod='lengthprice'}
            </p>
        </div>

        {* Dodaj przycisk Zapisz *}
        <div class="form-group">
            <button type="button" class="btn btn-primary" id="lengthprice_save_settings_button">
                <i class="process-icon-save"></i> {l s='Save LengthPrice Settings' mod='lengthprice'}
            </button>
            {* Tutaj można dodać miejsce na komunikaty sukcesu/błędu *}
            <span id="lengthprice_save_status" style="margin-left: 15px;"></span>
        </div>

    </div>
</div>

{* Dołącz plik JavaScript *}
<script type="text/javascript" src="{$module_dir|escape:'htmlall':'UTF-8'}views/js/admin/lengthprice_admin.js"></script>