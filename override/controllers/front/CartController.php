<?php
// /modules/lengthprice/override/controllers/front/CartController.php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'lengthprice/classes/LengthPriceCartRepository.php';
require_once _PS_MODULE_DIR_ . 'lengthprice/classes/LengthPriceDbRepository.php';

class CartController extends CartControllerCore
{
    protected function processChangeProductInCart(): void
    {
        /** @var LengthPrice $module */
        $module = Module::getInstanceByName('lengthprice');

        $productCustomizationData = Tools::getValue('product_customization');
        $id_product = (int)Tools::getValue('id_product');
        $id_product_attribute = (int)Tools::getValue('id_product_attribute', 0);

        if (Module::isEnabled('lengthprice') && $id_product > 0 && !empty($productCustomizationData)) {
            $lengthPriceFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($id_product, $this->context->language->id);

            if ($lengthPriceFieldId && isset($productCustomizationData[$lengthPriceFieldId][0]['value'])) {
                $lengthValue = $productCustomizationData[$lengthPriceFieldId][0]['value'];

                if ($lengthValue !== '' && (float)$lengthValue >= 0) {
                    if (!$this->context->cart->id) {
                        if (Context::getContext()->cookie->id_cart) {
                            $this->context->cart = new Cart(Context::getContext()->cookie->id_cart);
                        }
                        if (!$this->context->cart->id) {
                            $this->context->cart->add();
                            $this->context->cookie->id_cart = (int)$this->context->cart->id;
                        }
                    }

                    $cartRepo = new LengthPriceCartRepository(
                        $module,
                        Db::getInstance(),
                        _DB_PREFIX_,
                        Language::getLanguages(false)
                    );

                    $new_customization_id = $cartRepo->addCustomizationForLength(
                        (int)$this->context->cart->id,
                        $id_product,
                        $id_product_attribute,
                        (string)$lengthValue,
                        (int)$this->context->shop->id,
                        (int)$module->id
                    );

                    if ($new_customization_id) {
                        $this->customization_id = $new_customization_id;
                        $_POST['id_customization'] = $new_customization_id;
                        $_REQUEST['id_customization'] = $new_customization_id;
                    }
                }
            }
        }

        parent::processChangeProductInCart();
    }
}