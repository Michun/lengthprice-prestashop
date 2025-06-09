<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Service;

use Db;
use Language;
use LengthPrice;
use LengthPriceCartRepository;
use LengthPriceDbRepository;
use Context;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CartService
{
    private LengthPrice $module;
    private Db $db;
    private Context $context;

    public function __construct(LengthPrice $module, Db $db, Context $context)
    {
        $this->module = $module;
        $this->db = $db;
        $this->context = $context;
    }

    /**
     * Generates the HTML display for LengthPrice customization in the cart.
     *
     * @param mixed $productData Product data array or ArrayAccess object, typically $params['product'] from the hook.
     * @return string HTML content to display.
     */
    public function renderLengthPriceCustomizationForCart($productData): string // Zmieniono typ z array na mixed
    {
        if (!is_array($productData) && !($productData instanceof \ArrayAccess)) {
            $this->module->logToFile('[CartService] renderLengthPriceCustomizationForCart - productData is not an array or ArrayAccess object.');
            return '';
        }

        // Dostęp do 'customizations' - zadziała dla array i ArrayAccess
        if (empty($productData['customizations'])) {
            $this->module->logToFile('[CartService] renderLengthPriceCustomizationForCart - no customizations found in productData.');
            return '';
        }

        $customizations = $productData['customizations'];
        $lengthDisplayText = '';

        $id_product = 0;
        // Dostęp do 'id_product' lub 'id'
        if (isset($productData['id_product'])) {
            $id_product = (int)$productData['id_product'];
        } elseif (isset($productData['id'])) { // Fallback
            $id_product = (int)$productData['id'];
        }

        if (!$id_product) {
            $this->module->logToFile('[CartService] renderLengthPriceCustomizationForCart - product ID not found in productData.');
            return '';
        }

        $lengthPriceFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct(
            $id_product,
            (int)$this->context->language->id
        );

        if ($lengthPriceFieldId === null) {
            $this->module->logToFile("[CartService] renderLengthPriceCustomizationForCart - LengthPrice field ID not found for product {$id_product}.");
            return '';
        }

        $cartRepository = new LengthPriceCartRepository(
            $this->module,
            $this->db,
            _DB_PREFIX_,
            Language::getLanguages(false)
        );

        foreach ($customizations as $customization) {
            if (isset($customization['id_customization'])) {
                $structuredData = $cartRepository->getStructuredCartData((int)$customization['id_customization'], $lengthPriceFieldId);
                if ($structuredData && isset($structuredData['length'])) {
                    $lengthDisplayText .= $cartRepository->getCustomizationDisplayText($structuredData) . '<br />';
                } else {
                    // Fallback
                    if (isset($customization['fields']) && is_array($customization['fields'])) {
                        foreach ($customization['fields'] as $field) {
                            if (isset($field['id_customization_field']) && (int)$field['id_customization_field'] === $lengthPriceFieldId && !empty($field['text'])) {
                                $lengthDisplayText .= $cartRepository->getCustomizationDisplayText(['length' => $field['text']]) . '<br />';
                            }
                        }
                    }
                }
            }
        }

        if (empty($lengthDisplayText)) {
            $this->module->logToFile("[CartService] renderLengthPriceCustomizationForCart - lengthDisplayText is empty for product {$id_product}.");
            return '';
        }

        $this->context->smarty->assign([
            'lengthprice_customization_display' => $lengthDisplayText,
        ]);

        return $this->module->fetch('module:' . $this->module->name . '/views/templates/hook/lengthprice_cart_display.tpl');
    }
}