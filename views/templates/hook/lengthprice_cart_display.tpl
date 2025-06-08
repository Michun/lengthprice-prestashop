
{if isset($lengthprice_customization_display) && $lengthprice_customization_display != ''}
    <div class="lengthprice-cart-customization">
        {$lengthprice_customization_display nofilter} {* Używamy nofilter, ponieważ <br /> jest celowo dodawane *}
    </div>
{/if}