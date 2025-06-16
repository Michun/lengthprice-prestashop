<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Schema.php';
require_once dirname(__FILE__) . '/src/Service/CartService.php';

use PrestaShop\Module\LengthPrice\Service\CartService;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\Module\LengthPrice\Repository\LengthPriceDbRepository;

require_once __DIR__ . '/vendor/autoload.php';

class LengthPrice extends Module
{

    public function __construct()
    {
        $this->name = 'lengthprice';
        $this->tab = 'other';
        $this->version = '0.0.1';
        $this->author = 'Michał Nowacki';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Price per Length');
        $this->description = $this->l('Calculate price based on length.');

        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);
    }

    public function install(): bool
    {
        $schema = new Schema();

        if (!parent::install() ||
            !$this->registerHook('displayProductPriceBlock') ||
            !$this->registerHook('header') ||
            !$this->registerHook('actionProductDelete') ||
            !$this->registerHook('displayAdminProductsExtra') ||
            !$this->registerHook('actionValidateOrder') // Upewnij się, że ten hook jest rejestrowany
        ) {
            return false;
        }

        if (!$this->installControllers()) {
            return false;
        }

        if (!$this->addCustomizationFieldFlag()) {
            return false;
        }

        if (!$this->addLengthpriceDataColumnToCustomizedDataTable()) {
            return false;
        }

        if (!$schema->installSchema()) {
            return false;
        }

        return true;
    }

    public function uninstall(): bool
    {
        $schema = new Schema();
        $success = parent::uninstall();

        if (!$this->uninstallControllers()) {
            $success = false;
        }

        $success = $success && $this->unregisterHook('displayProductPriceBlock');
        $success = $success && $this->unregisterHook('header');
        $success = $success && $this->unregisterHook('actionProductDelete');
        $success = $success && $this->unregisterHook('displayAdminProductsExtra');
        $success = $success && $this->unregisterHook('actionValidateOrder'); // Upewnij się, że ten hook jest odrejestrowywany

        $success = $success && $this->removeCustomizationFieldFlag();
        $success = $success && $this->removeLengthpriceDataColumnFromCustomizedDataTable();
        $success = $success && $schema->uninstallSchema();

        return $success;
    }

    public function hookActionValidateOrder(array $params): void
    {
        /** @var \Order $order */
        $order = $params['order'];
        /** @var \Cart $cart */
        $cart = $params['cart'];

        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($cart)) {
            $this->logToFile('[LengthPrice] hookActionValidateOrder: Order or Cart object not loaded for Order ID: ' . ($order->id ?? 'N/A'));
            return;
        }

        $cartProducts = $cart->getProducts(true);
        if (empty($cartProducts)) {
            $this->logToFile('[LengthPrice] hookActionValidateOrder: No products found in cart ID ' . $cart->id . ' for Order ID ' . $order->id);
            return;
        }

        $processedOrderDetails = []; // Tablica do śledzenia przetworzonych ID OrderDetail

        foreach ($cartProducts as $cartProduct) {
            if (empty($cartProduct['id_customization']) || !LengthPriceDbRepository::isLengthPriceEnabledForProduct((int)$cartProduct['id_product'])) {
                // Jeśli brak id_customization w linii cart_product lub moduł nie jest włączony dla produktu, pomiń.
                // $cartProduct['id_customization'] pochodzi z ps_cart_product.id_customization
                continue;
            }

            $id_customization_from_cart_product_line = (int)$cartProduct['id_customization'];

            $lengthPriceFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct(
                (int)$cartProduct['id_product'],
                (int)$this->context->language->id
            );

            if ($lengthPriceFieldId === null) {
                // Nie znaleziono pola personalizacji długości dla tego produktu
                $this->logToFile('[LengthPrice] hookActionValidateOrder: LengthPrice field ID not found for Product ID ' . $cartProduct['id_product'] . ' with id_customization ' . $id_customization_from_cart_product_line);
                continue;
            }

            $customizedDataFields = LengthPriceDbRepository::getCustomizedDataFieldsByIdCustomization($id_customization_from_cart_product_line);

            if (empty($customizedDataFields)) {
                $this->logToFile('[LengthPrice] hookActionValidateOrder: No customized_data found for id_customization ' . $id_customization_from_cart_product_line . ' (linked from cart_product).');
                continue;
            }

            $foundLengthPriceCustomization = false;
            $original_length_mm_text = null;
            $price_for_original_length_excl_tax = 0; // Zmieniono z null na 0 dla spójności

            foreach ($customizedDataFields as $field) {
                // W tabeli customized_data, 'index' odpowiada 'id_customization_field'
                if ((int)$field['type'] === Product::CUSTOMIZE_TEXTFIELD && (int)$field['index'] === $lengthPriceFieldId) {
                    $original_length_mm_text = $field['value'];
                    // Pole 'price' w tabeli customized_data jest ceną jednostkową netto ustawioną przez Twój moduł
                    $price_for_original_length_excl_tax = (float)$field['price'];
                    $foundLengthPriceCustomization = true;
                    break; // Znaleziono pole długości, można przerwać pętlę po polach
                }
            }

            if (!$foundLengthPriceCustomization || $original_length_mm_text === null || !is_numeric($original_length_mm_text)) {
                $this->logToFile('[LengthPrice] hookActionValidateOrder: Invalid or missing length for id_customization ' . $id_customization_from_cart_product_line . '. Length text: "' . $original_length_mm_text . '"');
                continue;
            }

            if ($price_for_original_length_excl_tax <= 0) {
                // Ten warunek może być zbyt restrykcyjny, jeśli długość może rzeczywiście skutkować ceną zero.
                // Jednak dla produktów wycenianych, jest to dobre sprawdzenie.
                $this->logToFile('[LengthPrice] hookActionValidateOrder: Could not find valid unit price (<=0) in customized_data for id_customization ' . $id_customization_from_cart_product_line . ' for Order ID ' . $order->id . '. Price found: ' . $price_for_original_length_excl_tax);
                continue;
            }

            // Reszta logiki dopasowywania OrderDetail i aktualizacji...
            // Pamiętaj, aby używać $price_for_original_length_excl_tax do porównania z $odData['unit_price_tax_excl']

            $orderDetailList = $order->getOrderDetailList();
            foreach ($orderDetailList as $odData) {
                if (in_array((int)$odData['id_order_detail'], $processedOrderDetails)) {
                    continue;
                }

                if ((int)$odData['product_id'] === (int)$cartProduct['id_product'] &&
                    (int)$odData['product_attribute_id'] === (int)$cartProduct['id_product_attribute'] &&
                    (int)$odData['product_quantity'] === (int)$cartProduct['cart_quantity']
                ) {
                    $orderDetail = new OrderDetail((int)$odData['id_order_detail']);
                    if (!Validate::isLoadedObject($orderDetail)) {
                        continue;
                    }

                    // ... (reszta Twojej logiki aktualizacji OrderDetail, tak jak poprzednio) ...
                    // np. obliczanie $new_product_quantity, $new_unit_price_tax_excl itd.

                    $original_length_mm = (float)$original_length_mm_text;
                    $original_order_detail_quantity = (int)$orderDetail->product_quantity;

                    $rounded_length_cm_per_item = ceil($original_length_mm / 10.0);
                    $new_product_quantity = $original_order_detail_quantity * $rounded_length_cm_per_item;

                    if ($new_product_quantity <= 0) {
                        $this->logToFile('[LengthPrice] hookActionValidateOrder: Calculated new_product_quantity is zero or less for OrderDetail ID ' . $orderDetail->id . '. Skipping.');
                        continue;
                    }

                    $original_total_price_tax_excl = (float)$orderDetail->total_price_tax_excl;
                    $original_total_price_tax_incl = (float)$orderDetail->total_price_tax_incl;

                    // $new_unit_price_tax_excl = $original_total_price_tax_excl / $new_product_quantity;
                    // $new_unit_price_tax_incl = $original_total_price_tax_incl / $new_product_quantity;

                    $original_product_price = $orderDetail->original_product_price;
                    $tax_rate = $orderDetail->tax_rate;
                    $new_unit_price_tax_excl = $original_product_price;
                    $new_unit_price_tax_incl = $original_product_price * (1 + $tax_rate/100);


                    $annotation_details = sprintf(
                        $this->l('%d pcs x %.1f cm/pc', 'lengthprice'),
                        $original_order_detail_quantity,
                        ($original_length_mm / 10)
                    );
                    $annotation_suffix = sprintf(" (%s)", $annotation_details);

                    $baseProductName = $orderDetail->product_name;
                    $pattern = '/ \(' . preg_quote($this->l('%d pcs x %.1f cm/pc', 'lengthprice'), '/') . '\)$/u';
                    $pattern = str_replace(['%d', '%.1f', '%s'], ['[0-9]+', '[0-9\.]+', '.+'], $pattern);
                    $baseProductName = preg_replace($pattern, '', $baseProductName);
                    $modifiedProductName = $baseProductName . $this->l(' (unit: cm)', 'lengthprice') . $annotation_suffix;

                    $orderDetail->product_name = $modifiedProductName;
                    $orderDetail->product_quantity_in_stock = (int)$new_product_quantity;
                    $orderDetail->product_quantity = (int)$new_product_quantity;
                    $orderDetail->unit_price_tax_excl = (float)$new_unit_price_tax_excl;
                    $orderDetail->unit_price_tax_incl = (float)$new_unit_price_tax_incl;

                    if (!$orderDetail->update()) {
                        $this->logToFile('[LengthPrice] hookActionValidateOrder: Failed to update OrderDetail ID ' . $orderDetail->id . ' for Order ID ' . $order->id . '. Errors: ' . implode(", ", $orderDetail->getValidationMessages()));
                    } else {
                        $this->logToFile('[LengthPrice] hookActionValidateOrder: Successfully updated OrderDetail ID ' . $orderDetail->id . ' for Order ID ' . $order->id . '. New Qty: ' . $new_product_quantity . ', New Unit Price Excl: ' . $new_unit_price_tax_excl . ', New Name: ' . $orderDetail->product_name);
                        $processedOrderDetails[] = (int)$orderDetail->id;
                    }
                    break;
                }
            }
        }

    }


    public function logToFile(string $message): void
    {
        // This method is intentionally kept for potential error logging,
        // but informational logs have been removed from its call sites.
        // If you want to completely disable all logging, you can empty this method body.
        $logfile = _PS_MODULE_DIR_ . $this->name . '/debug.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logfile, $entry, FILE_APPEND);
    }

    private function addCustomizationFieldFlag(): bool
    {
        $result = LengthPriceDbRepository::addColumnIfNotExists(
            'customization_field',
            'is_lengthprice',
            'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
        );
        if (!$result) {
            $this->logToFile("[LengthPrice] addCustomizationFieldFlag: Adding column failed.");
        }
        return $result;
    }

    private function removeCustomizationFieldFlag(): bool
    {
        $result = LengthPriceDbRepository::markAndDeleteLengthPriceCustomizationFlag([$this, 'logToFile']);

        if (!$result) {
            $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: The process of marking fields as deleted and/or dropping the 'is_lengthprice' column encountered an issue. Check repository logs.");
        }
        return $result;
    }

    private function addLengthpriceDataColumnToCustomizedDataTable(): bool
    {
        $tableName = 'customized_data';
        $columnName = 'lengthprice_data';
        if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getAddColumnSql($tableName, $columnName, 'TEXT DEFAULT NULL');
            $result = Db::getInstance()->execute($sql);
            if (!$result) {
                $this->logToFile("[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable: ALTER TABLE FAILED - DB Error: " . Db::getInstance()->getMsgError());
            }
            return (bool)$result;
        }
        return true;
    }

    private function removeLengthpriceDataColumnFromCustomizedDataTable(): bool
    {
        $tableName = 'customized_data';
        $columnName = 'lengthprice_data';
        if (LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getDropColumnSql($tableName, $columnName);
            $result = Db::getInstance()->execute($sql);
            if (!$result) {
                $this->logToFile("[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable: ALTER TABLE DROP COLUMN FAILED - DB Error: " . Db::getInstance()->getMsgError());
            }
            return (bool)$result;
        }
        return true;
    }

    public function installControllers(): bool
    {
        $tab = new Tab();
        $tab->class_name = 'AdminLengthPriceSettings';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'LengthPrice Settings';
        }

        try {
            $result = $tab->add();
            if (!$result) {
                $this->logToFile('[LengthPrice] installControllers: Adding Tab FAILED - DB Error: ' . Db::getInstance()->getMsgError());
            }
            return $result;
        } catch (\Exception $e) {
            $this->logToFile('[LengthPrice] installControllers: Exception adding Tab: ' . $e->getMessage());
            return false;
        }
    }

    public function uninstallControllers(): bool
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminLengthPriceSettings');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            try {
                $result = $tab->delete();
                if (!$result) {
                    $this->logToFile('[LengthPrice] uninstallControllers: Deleting Tab FAILED - DB Error: ' . Db::getInstance()->getMsgError());
                }
                return $result;
            } catch (\Exception $e) {
                $this->logToFile('[LengthPrice] uninstallControllers: Exception deleting Tab: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }


    public function hookActionProductDelete(array $params): void
    {
        $productId = null;

        if (isset($params['id_product'])) {
            $productId = (int)$params['id_product'];
        } elseif (isset($params['id'])) {
            $productId = (int)$params['id'];
        } elseif (isset($params['productId']) && $params['productId'] instanceof ProductId) {
            $productId = (int)$params['productId']->getValue();
        } elseif (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $productId = (int)$params['object']->id;
        }


        if ($productId) {
            if (!LengthPriceDbRepository::deleteProductLengthPriceFlag($productId)) {
                $this->logToFile("[LengthPrice] BŁĄD: hookActionProductDelete - Failed to delete lengthprice_enabled flag for Product ID {$productId}. DB Error: " . Db::getInstance()->getMsgError());
            }
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionProductDelete - Could not determine Product ID from params.');
        }
    }


    public function hookHeader(): void
    {
        $this->context->controller->addJS($this->_path . 'views/js/lengthprice.js');

        if ($this->context->controller->php_self === 'product') {
            $idProduct = (int)Tools::getValue('id_product');
            if (!$idProduct && isset($this->context->controller->product) && $this->context->controller->product instanceof Product) {
                $idProduct = (int)$this->context->controller->product->id;
            }

            if ($idProduct > 0) {
                $isLengthPriceEnabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($idProduct);

                if ($isLengthPriceEnabled) {
                    $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($idProduct, (int)$this->context->language->id);
                    if ($customizationFieldId !== null) {
                        Media::addJsDef(['lengthpriceCustomizationFieldId' => $customizationFieldId]);
                    }
                }
            }
        }
    }


    public function hookDisplayProductPriceBlock(array $params): string
    {
        if (isset($params['type'])) {
            if ($params['type'] === 'after_price') {
                $productId = 0;
                if (isset($params['product'])) {
                    if (isset($params['product']->id)) {
                        $productId = (int)$params['product']->id;
                    } elseif (is_array($params['product']) && isset($params['product']['id_product'])) {
                        $productId = (int)$params['product']['id_product'];
                    } elseif (is_array($params['product']) && isset($params['product']['id'])) {
                        $productId = (int)$params['product']['id'];
                    }
                }

                if (!$productId && isset($this->context->controller->product) && $this->context->controller->product instanceof Product && isset($this->context->controller->product->id)) {
                    $productId = (int)$this->context->controller->product->id;
                }

                if (!$productId) {
                    return '';
                }

                $isLengthPriceEnabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($productId);
                if (!$isLengthPriceEnabled) {
                    return '';
                }

                $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId, (int)$this->context->language->id);
                if ($customizationFieldId === null) {
                    return '';
                }

                $price_per_unit = Product::getPriceStatic($productId, true, null, 6);

                $this->context->smarty->assign([
                    'price_per_unit' => $price_per_unit,
                    'customization_field_id' => $customizationFieldId,
                ]);
                return $this->fetch('module:' . $this->name . '/views/templates/hook/lengthprice.tpl');
            }
        }

        $shouldRenderCustomizationLine = false;
        $i = 0;
        foreach (debug_backtrace() as $debug) {
            if (isset($debug['file']) && (strpos($debug['file'], 'cartmodal.tpl.php') > 0
                    || strpos($debug['file'], 'cart-summary-product-line.tpl.php') > 0
                    || strpos($debug['file'], 'cart-detailed-product-line.tpl') > 0
                    || strpos($debug['file'], 'module.posshoppingcartmodal.tpl') > 0)) {
                $shouldRenderCustomizationLine = true;
            }
            if ($i > 10) {
                break;
            }
            ++$i;
        }

        if ($shouldRenderCustomizationLine) {
            if (isset($params['product'])) {
                try {
                    $cartService = new CartService(
                        $this,
                        Db::getInstance(),
                        $this->context
                    );
                    return $cartService->renderLengthPriceCustomizationForCart($params['product']);
                } catch (\Throwable $e) {
                    $this->logToFile('[LengthPrice] hookDisplayProductPriceBlock - Error manually instantiating or using CartService: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                    return '';
                }
            } else {
                return '';
            }
        }
        return '';
    }

    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $id_product = (int)$params['id_product'];

        if (!$id_product) {
            return $this->trans('Product not found.', [], 'Modules.Lengthprice.Admin');
        }

        $lengthprice_enabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($id_product);

        $router = $this->get('router');
        $ajaxUrl = $router->generate('lengthprice_admin_save_settings');

        $this->context->smarty->assign([
            'lengthprice_enabled' => $lengthprice_enabled,
            'id_product' => $id_product,
            'ajax_controller_url' => $ajaxUrl,
            'module_dir' => $this->_path,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/lengthprice_module_tab.tpl');
    }
}