<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Schema.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';
require_once dirname(__FILE__) . '/classes/LengthPriceCartRepository.php';
require_once dirname(__FILE__) . '/src/Service/CartService.php';

use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\Module\LengthPrice\Service\CartService;

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

        if (!parent::install()) {
            return false;
        }

        if (!$this->installControllers()) {
            $this->logToFile('[LengthPrice] BŁĄD: installControllers() failed.');
            return false;
        }

        if (!($this->registerHook('displayProductPriceBlock')
            && $this->registerHook('header')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('displayAdminProductsExtra'))) {
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

        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at parent::uninstall().');
            return false;
        }

        if (!$this->uninstallControllers()) {
            $this->logToFile('[LengthPrice] BŁĄD: uninstallControllers() failed.');
            $success = false;
        }

        $success = $success && $this->unregisterHook('displayProductPriceBlock');
        $success = $success && $this->unregisterHook('header');
        $success = $success && $this->unregisterHook('actionProductDelete');
        $success = $success && $this->unregisterHook('displayAdminProductsExtra');

        $success = $success && $this->removeCustomizationFieldFlag();
        if (!$success && !$this->removeCustomizationFieldFlag()) { // Check again to log if this specific step failed
            $this->logToFile('[LengthPrice] Uninstall failed at removeCustomizationFieldFlag().');
        }


        $success = $success && $this->removeLengthpriceDataColumnFromCustomizedDataTable();
        $success = $success && $schema->uninstallSchema();
        if (!$success && !$schema->uninstallSchema()) { // Check again to log if this specific step failed
            $this->logToFile('[LengthPrice] Uninstall failed at schema->uninstallSchema().');
        }

        return $success;
    }

    public function logToFile(string $message): void
    {
        $logfile = _PS_MODULE_DIR_ . $this->name . '/debug.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logfile, $entry, FILE_APPEND);
    }

    private function addCustomizationFieldFlag(): bool
    {
        $tableName = 'customization_field';
        $columnName = 'is_lengthprice';
        if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getAddColumnSql($tableName, $columnName, 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
            $result = Db::getInstance()->execute($sql);
            if (!$result) {
                $this->logToFile("[LengthPrice] addCustomizationFieldFlag: ALTER TABLE FAILED - DB Error: " . Db::getInstance()->getMsgError());
            }
            return (bool)$result;
        }
        return true;
    }

    private function removeCustomizationFieldFlag(): bool
    {
        $tableName = 'customization_field';
        $columnName = 'is_lengthprice';
        if (LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getDropColumnSql($tableName, $columnName);
            $result = Db::getInstance()->execute($sql);
            if (!$result) {
                $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: ALTER TABLE DROP COLUMN FAILED - DB Error: " . Db::getInstance()->getMsgError());
            }
            return (bool)$result;
        }
        return true;
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
        $tab->id_parent = -1; // No parent, top level
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'LengthPrice Settings';
        }

        try {
            return $tab->add();
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
                return $tab->delete();
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
                if (LengthPriceDbRepository::isLengthPriceEnabledForProduct($idProduct)) {
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
        if (!isset($params['type'])) {
            return '';
        }

        if ($params['type'] === 'after_price') {
            $productId = 0;
            if (isset($params['product'])) {
                // Standard Product object or array from product list
                if (isset($params['product']->id)) { // Product object
                    $productId = (int)$params['product']->id;
                } elseif (is_array($params['product']) && isset($params['product']['id_product'])) { // Array from product list
                    $productId = (int)$params['product']['id_product'];
                } elseif (is_array($params['product']) && isset($params['product']['id'])) { // Fallback for 'id' key
                    $productId = (int)$params['product']['id'];
                }
            }

            // Fallback for controller context if not found in params
            if (!$productId && isset($this->context->controller->product) && $this->context->controller->product instanceof Product && isset($this->context->controller->product->id)) {
                $productId = (int)$this->context->controller->product->id;
            }

            if (!$productId) {
                return '';
            }

            if (!LengthPriceDbRepository::isLengthPriceEnabledForProduct($productId)) {
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

        } elseif ($params['type'] === 'customization') {
            if (isset($params['product'])) {
                try {
                    $cartService = new CartService(
                        $this,
                        Db::getInstance(),
                        $this->context
                    );
                    return $cartService->renderLengthPriceCustomizationForCart($params['product']);
                } catch (\Throwable $e) {
                    $this->logToFile('[LengthPrice] hookDisplayProductPriceBlock (customization) - Error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                    return '';
                }
            }
            return '';
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