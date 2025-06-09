<?php

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

                if ($lengthValue !== '' && is_numeric($lengthValue) && (float)$lengthValue >= 0) { // Poprawiona walidacja
                    if (!$this->context->cart->id) {
                        if (Context::getContext()->cookie->id_cart) {
                            $this->context->cart = new Cart(Context::getContext()->cookie->id_cart);
                        }
                        if (!$this->context->cart->id) {
                            $this->context->cart->add();
                            if ($this->context->cart->id) { // Sprawdź czy dodanie koszyka się powiodło
                                $this->context->cookie->id_cart = (int)$this->context->cart->id;
                            } else {
                                $module?->logToFile("[LengthPrice] CartController Error: Failed to create or load cart.");
                                parent::processChangeProductInCart(); // Przerwij dalsze przetwarzanie modułu
                                return;
                            }
                        }
                    }

                    // Obliczanie ceny docelowej (bez podatku)
                    $baseProductPriceExclTax = Product::getPriceStatic(
                        (int)$id_product, false, null, 6, null, false, true, 1, false,
                        null, null, null, $specificPriceOutput_dummy, false, true, $this->context, true
                    );

                    if ($baseProductPriceExclTax === null || $baseProductPriceExclTax < 0) {
                        $module?->logToFile("[LengthPrice] CartController Error: Could not retrieve base product price (tax_excl) for Product ID {$id_product}. Price: " . var_export($baseProductPriceExclTax, true));
                    } else {
                        $lengthInBlocks = ceil((float)$lengthValue / 10.0);
                        $finalCalculatedPriceExclTax = $baseProductPriceExclTax * $lengthInBlocks;

                        $zeroingSpecificPriceConditions =
                            '`id_product` = ' . (int)$id_product .
                            ' AND `id_cart` = ' . (int)$this->context->cart->id .
                            ' AND `price` = 0.00' .
                            ' AND `reduction` = 0' .
                            ' AND `from_quantity` = 1' .
                            ' AND `id_specific_price_rule` = 0' .
                            ' AND `id_product_attribute` = 0'; // Dla produktu bazowego

                        $existingZeroingSpId = Db::getInstance()->getValue(
                            'SELECT `id_specific_price` FROM `' . _DB_PREFIX_ . 'specific_price` WHERE ' . $zeroingSpecificPriceConditions
                        );

                        if (!$existingZeroingSpId) {
                            Db::getInstance()->delete(
                                'specific_price',
                                '`id_product` = ' . (int)$id_product .
                                ' AND `id_cart` = ' . (int)$this->context->cart->id .
                                ' AND `price` >= 0 AND `reduction` = 0 AND `id_specific_price_rule` = 0 AND `id_product_attribute` = 0'
                            );

                            $zeroSpecificPrice = new SpecificPrice();
                            $zeroSpecificPrice->id_product = (int)$id_product;
                            $zeroSpecificPrice->id_product_attribute = 0; // Dla produktu bazowego
                            $zeroSpecificPrice->id_shop = (int)$this->context->shop->id;
                            $zeroSpecificPrice->id_currency = 0;
                            $zeroSpecificPrice->id_country = 0;
                            $zeroSpecificPrice->id_group = 0;
                            $zeroSpecificPrice->id_customer = 0;
                            $zeroSpecificPrice->id_cart = (int)$this->context->cart->id;
                            $zeroSpecificPrice->price = 0.00; // Ustaw cenę bazową na 0
                            $zeroSpecificPrice->from_quantity = 1;
                            $zeroSpecificPrice->reduction = 0;
                            $zeroSpecificPrice->reduction_tax = 0;
                            $zeroSpecificPrice->reduction_type = 'amount';
                            $zeroSpecificPrice->from = '0000-00-00 00:00:00';
                            $zeroSpecificPrice->to = '0000-00-00 00:00:00';

                            if (!$zeroSpecificPrice->add()) {
                                $module?->logToFile('[LengthPrice] CartController Error: Failed to add zeroing SpecificPrice for Product ID: ' . $id_product . '. Validation errors: ' . implode(", ", $zeroSpecificPrice->getValidationMessages()));
                            }
                        } else {
                            $module?->logToFile("[LengthPrice] CartController: Zeroing SpecificPrice (ID: {$existingZeroingSpId}) already exists for Product ID {$id_product}, Cart ID {$this->context->cart->id}.");
                        }

                        // Krok 2: Dodaj personalizację z obliczoną ceną do customized_data
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
                            (int)$module->id,
                            $finalCalculatedPriceExclTax // Przekaż obliczoną cenę
                        );

                        if ($new_customization_id) {
                            // Ustaw $this->customization_id, aby PrestaShop poprawnie przetworzył dodanie do koszyka
                            // produktu z personalizacją.
                            $this->customization_id = $new_customization_id;
                            // Uaktualnij również $_POST i $_REQUEST, ponieważ niektóre części PrestaShop mogą z nich korzystać
                            $_POST['id_customization'] = $new_customization_id;
                            $_REQUEST['id_customization'] = $new_customization_id;
                            $module?->logToFile("[LengthPrice] CartController: Set customization_id to {$new_customization_id} for processing.");
                        } else {
                            $module?->logToFile("[LengthPrice] CartController Error: Failed to add customization data for Product ID {$id_product}.");
                        }
                    }
                } else {
                    $module?->logToFile("[LengthPrice] CartController: Invalid or empty lengthValue ('{$lengthValue}') for Product ID {$id_product}.");
                }
            }
        }

        parent::processChangeProductInCart();
    }
}