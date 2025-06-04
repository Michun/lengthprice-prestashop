<div class="panel panel-default" style="border: 1px solid #ccc; padding: 15px; margin-top: 20px;">
    <div class="panel-heading" style="font-weight: bold; font-size: 16px; color: #1e4f7a;">
        🔧 LengthPrice – ustawienia modułu
    </div>
    <div class="panel-body" style="margin-top: 10px;">
        <div class="form-group">
            <div class="checkbox">
                <label>
                    <input type="hidden" name="lengthprice_enabled" value="0" />
                    <input type="checkbox" name="lengthprice_enabled" value="1" {if $lengthprice_enabled == 1}checked="checked"{/if} />
                    <strong>Włącz przeliczanie ceny na podstawie długości</strong>
                </label>
            </div>
            <p class="help-block" style="margin-left: 22px;">
                Po włączeniu tej opcji, klient na stronie produktu będzie mógł podać długość, a cena zostanie automatycznie przeliczona.
            </p>
        </div>
    </div>
</div>