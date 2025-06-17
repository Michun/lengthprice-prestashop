<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\LengthPrice\Repository\LengthPriceDbRepository;
use PrestaShop\Module\LengthPrice\Service\CartService;
use PrestaShop\Module\LengthPrice\Setup\Installer;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;

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
        if (!parent::install() ||
            !$this->registerHook('displayProductPriceBlock') ||
            !$this->registerHook('header') ||
            !$this->registerHook('actionProductDelete') ||
            !$this->registerHook('displayAdminProductsExtra') ||
            !$this->registerHook('actionValidateOrder')
        ) {
            $this->logToFile('Moduł: Nie udało się zarejestrować podstawowych hooków.');
            return false;
        }

        $installer = new Installer($this, Db::getInstance());
        if (!$installer->install()) {
            $this->logToFile('Moduł: Instalator zwrócił błąd.');
            return false;
        }

        $this->logToFile('Moduł: Instalacja zakończona pomyślnie.');
        return true;
    }

    public function uninstall(): bool
    {
        $this->logToFile("Rozpoczynanie procesu deinstalacji modułu LengthPrice.");
        $success = true;

        $hooksToUnregister = [
            'displayProductPriceBlock',
            'header',
            'actionProductDelete',
            'displayAdminProductsExtra',
            'actionValidateOrder',
        ];
        foreach ($hooksToUnregister as $hookName) {
            if (!$this->unregisterHook($hookName)) {
                $this->logToFile("Moduł: Nie udało się wyrejestrować hooka: {$hookName}");
            }
        }
        $this->logToFile("Moduł: Próba wyrejestrowania hooków zakończona.");

        $installer = new Installer($this, Db::getInstance());
        if (!$installer->uninstall()) {
            $this->logToFile('Moduł: Deinstalator zwrócił błąd.');
            $success = false;
        }

        if (!parent::uninstall()) {
            $this->logToFile("Moduł: Wystąpił błąd podczas parent::uninstall().");
            $success = false;
        }

        $this->logToFile("Moduł: Proces deinstalacji zakończony. Ogólny sukces: " . ($success ? 'Tak' : 'Nie'));
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

        $processedOrderDetails = [];

        foreach ($cartProducts as $cartProduct) {
            if (empty($cartProduct['id_customization']) || !LengthPriceDbRepository::isLengthPriceEnabledForProduct((int)$cartProduct['id_product'])) {
                continue;
            }

            $id_customization_from_cart_product_line = (int)$cartProduct['id_customization'];

            $lengthPriceFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct(
                (int)$cartProduct['id_product'],
                (int)$this->context->language->id
            );

            if ($lengthPriceFieldId === null) {
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
            $price_for_original_length_excl_tax = 0;

            foreach ($customizedDataFields as $field) {
                if ((int)$field['type'] === Product::CUSTOMIZE_TEXTFIELD && (int)$field['index'] === $lengthPriceFieldId) {
                    $original_length_mm_text = $field['value'];
                    $price_for_original_length_excl_tax = (float)$field['price'];
                    $foundLengthPriceCustomization = true;
                    break;
                }
            }

            if (!$foundLengthPriceCustomization || $original_length_mm_text === null || !is_numeric($original_length_mm_text)) {
                $this->logToFile('[LengthPrice] hookActionValidateOrder: Invalid or missing length for id_customization ' . $id_customization_from_cart_product_line . '. Length text: "' . $original_length_mm_text . '"');
                continue;
            }

            if ($price_for_original_length_excl_tax <= 0) {
                $this->logToFile('[LengthPrice] hookActionValidateOrder: Could not find valid unit price (<=0) in customized_data for id_customization ' . $id_customization_from_cart_product_line . ' for Order ID ' . $order->id . '. Price found: ' . $price_for_original_length_excl_tax);
                continue;
            }

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

                    $original_product_price = $orderDetail->original_product_price;
                    $tax_rate = $orderDetail->tax_rate;
                    $new_unit_price_tax_excl = $original_product_price;
                    $new_unit_price_tax_incl = $original_product_price * (1 + $tax_rate/100);


                    $annotation_details = sprintf(
                        $this->l('%d pcs x %.0f mm/pc', 'lengthprice'),
                        $original_order_detail_quantity,
                        $original_length_mm
                    );
                    $annotation_suffix = sprintf(" (%s)", $annotation_details);

                    $baseProductName = $orderDetail->product_name;
                    $pattern = '/ \(' . preg_quote($this->l('%d pcs x %f cm/pc', 'lengthprice'), '/') . '\)$/u';
                    $pattern = str_replace(['%d', '%.0f', '%s'], ['[0-9]+', '[0-9]+', '.+'], $pattern);
                    $baseProductName = preg_replace($pattern, '', $baseProductName);
                    $modifiedProductName = $baseProductName . $this->l(' (unit: mm)', 'lengthprice') . $annotation_suffix;

                    $orderDetail->product_name = $modifiedProductName;
                    $orderDetail->product_quantity_in_stock = (int)$new_product_quantity;
                    $orderDetail->product_quantity = (int)$new_product_quantity;
                    $orderDetail->unit_price_tax_excl = (float)$new_unit_price_tax_excl;
                    $orderDetail->unit_price_tax_incl = (float)$new_unit_price_tax_incl;
                    $orderDetail->id_customization = 0;


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
        $logfile = _PS_MODULE_DIR_ . $this->name . '/debug.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logfile, $entry, FILE_APPEND);
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
            if (!LengthPriceDbRepository::deleteProductSettings($productId, [$this, 'logToFile'])) {
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