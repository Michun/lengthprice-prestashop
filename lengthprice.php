<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Schema.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';
require_once dirname(__FILE__) . '/classes/LengthPriceCartRepository.php';
require_once dirname(__FILE__) . '/src/Service/LengthPriceProductSettingsService.php';

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\Module\LengthPrice\Service\LengthPriceProductSettingsService;


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
        $this->logToFile('[LengthPrice] Install method started.');
        $schema = new Schema();

        $parentInstall = parent::install();
        $this->logToFile('[LengthPrice] parent::install() result: ' . ($parentInstall ? 'OK' : 'FAIL'));
        if (!$parentInstall) return false;

        if (!$this->installControllers()) {
            $this->logToFile('[LengthPrice] BŁĄD: installControllers() failed.');
            return false;
        }
        $this->logToFile('[LengthPrice] installControllers() result: OK');

        $hooksRegistered = $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('header')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('actionCartSave');
        $this->logToFile('[LengthPrice] registerHook() calls result: ' . ($hooksRegistered ? 'OK' : 'FAIL'));
        if (!$hooksRegistered) return false;

        $customizationFlagAdded = $this->addCustomizationFieldFlag();
        $this->logToFile('[LengthPrice] addCustomizationFieldFlag() result: ' . ($customizationFlagAdded ? 'OK' : 'FAIL'));
        if (!$customizationFlagAdded) return false;

        $customizedDataColumnAdded = $this->addLengthpriceDataColumnToCustomizedDataTable();
        $this->logToFile('[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable() result: ' . ($customizedDataColumnAdded ? 'OK' : 'FAIL'));
        if (!$customizedDataColumnAdded) return false;

        $schemaInstalled = $schema->installSchema(); // Dla ps_lengthprice_product_settings
        $this->logToFile('[LengthPrice] schema->installSchema() result: ' . ($schemaInstalled ? 'OK' : 'FAIL'));
        if (!$schemaInstalled) return false;

        $this->logToFile('[LengthPrice] Install method finished successfully.');
        return true;
    }

    public function uninstall(): bool
    {
        $this->logToFile('[LengthPrice] Uninstall method started.');
        $schema = new Schema();

        $success = parent::uninstall();
        $this->logToFile('[LengthPrice] parent::uninstall() result: ' . ($success ? 'OK' : 'FAIL'));
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at parent::uninstall().');
            return false;
        }

        if (!$this->uninstallControllers()) {
            $this->logToFile('[LengthPrice] BŁĄD: uninstallControllers() failed.');
            $success = false;
        }
        $this->logToFile('[LengthPrice] uninstallControllers() result: OK');


        $hookUnregister1 = $this->unregisterHook('displayProductPriceBlock');
        $this->logToFile('[LengthPrice] unregisterHook(displayProductPriceBlock) result: ' . ($hookUnregister1 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister1;

        $hookUnregister2 = $this->unregisterHook('header');
        $this->logToFile('[LengthPrice] unregisterHook(header) result: ' . ($hookUnregister2 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister2;

        $hookUnregister3 = $this->unregisterHook('actionProductDelete');
        $this->logToFile('[LengthPrice] unregisterHook(actionProductDelete) result: ' . ($hookUnregister3 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister3;

        $hookUnregister4 = $this->unregisterHook('displayAdminProductsExtra');
        $this->logToFile('[LengthPrice] unregisterHook(displayAdminProductsExtra) result: ' . ($hookUnregister4 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister4;


        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed during hook unregistration.');
        }

        $removedCustomizationFlag = $this->removeCustomizationFieldFlag();
        $this->logToFile('[LengthPrice] removeCustomizationFieldFlag() result: ' . ($removedCustomizationFlag ? 'OK' : 'FAIL'));
        $success = $success && $removedCustomizationFlag;
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at removeCustomizationFieldFlag().');
        }

        $customizedDataColumnRemoved = $this->removeLengthpriceDataColumnFromCustomizedDataTable();
        $this->logToFile('[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable() result: ' . ($customizedDataColumnRemoved ? 'OK' : 'FAIL'));
        $success = $success && $customizedDataColumnRemoved;


        $schemaUninstalled = $schema->uninstallSchema();
        $this->logToFile('[LengthPrice] schema->uninstallSchema() result: ' . ($schemaUninstalled ? 'OK' : 'FAIL'));
        $success = $success && $schemaUninstalled;
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at schema->uninstallSchema().');
        }

        $this->logToFile('[LengthPrice] Uninstall method finished with overall success: ' . ($success ? 'OK' : 'FAIL'));
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
        $this->logToFile("[LengthPrice] addCustomizationFieldFlag: Checking if column {$columnName} exists in {$tableName}.");
        if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $this->logToFile("[LengthPrice] addCustomizationFieldFlag: Column {$columnName} does not exist in {$tableName}. Attempting to add.");
            $sql = LengthPriceDbRepository::getAddColumnSql($tableName, $columnName, 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
            $this->logToFile("[LengthPrice] addCustomizationFieldFlag: SQL to add column: " . $sql);
            $result = Db::getInstance()->execute($sql);
            $this->logToFile("[LengthPrice] addCustomizationFieldFlag: ALTER TABLE result: " . ($result ? 'OK' : 'FAIL - DB Error: ' . Db::getInstance()->getMsgError()));
            return (bool)$result;
        } else {
            $this->logToFile("[LengthPrice] addCustomizationFieldFlag: Column {$columnName} already exists in {$tableName}.");
        }
        return true;
    }


    private function removeCustomizationFieldFlag(): bool
    {
        $tableName = 'customization_field';
        $columnName = 'is_lengthprice';
        $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: Checking if column {$columnName} exists in {$tableName}.");
        if (LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: Column {$columnName} exists in {$tableName}. Attempting to drop.");
            $sql = LengthPriceDbRepository::getDropColumnSql($tableName, $columnName);
            $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: SQL to drop column: " . $sql);
            $result = Db::getInstance()->execute($sql);
            $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: ALTER TABLE DROP COLUMN result: " . ($result ? 'OK' : 'FAIL - DB Error: ' . Db::getInstance()->getMsgError()));
            return (bool)$result;
        } else {
            $this->logToFile("[LengthPrice] removeCustomizationFieldFlag: Column {$columnName} does not exist in {$tableName}. Skipping drop.");
        }
        return true;
    }

    private function addLengthpriceDataColumnToCustomizedDataTable(): bool
    {
        $tableName = 'customized_data';
        $columnName = 'lengthprice_data';
        $this->logToFile("[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable: Checking if column {$columnName} exists in {$tableName}.");
        if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $this->logToFile("[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable: Column {$columnName} does not exist in {$tableName}. Attempting to add.");
            // TEXT może przechowywać dłuższe JSONy niż VARCHAR
            $sql = LengthPriceDbRepository::getAddColumnSql($tableName, $columnName, 'TEXT DEFAULT NULL');
            $this->logToFile("[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable: SQL to add column: " . $sql);
            $result = Db::getInstance()->execute($sql);
            $this->logToFile("[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable: ALTER TABLE result: " . ($result ? 'OK' : 'FAIL - DB Error: ' . Db::getInstance()->getMsgError()));
            return (bool)$result;
        } else {
            $this->logToFile("[LengthPrice] addLengthpriceDataColumnToCustomizedDataTable: Column {$columnName} already exists in {$tableName}.");
        }
        return true;
    }

    private function removeLengthpriceDataColumnFromCustomizedDataTable(): bool
    {
        $tableName = 'customized_data';
        $columnName = 'lengthprice_data';
        $this->logToFile("[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable: Checking if column {$columnName} exists in {$tableName}.");
        if (LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $this->logToFile("[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable: Column {$columnName} exists in {$tableName}. Attempting to drop.");
            $sql = LengthPriceDbRepository::getDropColumnSql($tableName, $columnName);
            $this->logToFile("[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable: SQL to drop column: " . $sql);
            $result = Db::getInstance()->execute($sql);
            $this->logToFile("[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable: ALTER TABLE DROP COLUMN result: " . ($result ? 'OK' : 'FAIL - DB Error: ' . Db::getInstance()->getMsgError()));
            return (bool)$result;
        } else {
            $this->logToFile("[LengthPrice] removeLengthpriceDataColumnFromCustomizedDataTable: Column {$columnName} does not exist in {$tableName}. Skipping drop.");
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
            $this->logToFile('[LengthPrice] installControllers: Adding Tab result: ' . ($result ? 'OK' : 'FAIL - DB Error: ' . Db::getInstance()->getMsgError()));
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
                $this->logToFile('[LengthPrice] uninstallControllers: Deleting Tab result: ' . ($result ? 'OK' : 'FAIL - DB Error: ' . Db::getInstance()->getMsgError()));
                return $result;
            } catch (\Exception $e) {
                $this->logToFile('[LengthPrice] uninstallControllers: Exception deleting Tab: ' . $e->getMessage());
                return false;
            }
        }
        $this->logToFile('[LengthPrice] uninstallControllers: Tab not found for class AdminLengthPriceSettings.');
        return true;
    }


    public function hookActionProductDelete(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionProductDelete triggered.');
        $productId = null;

        if (isset($params['id_product'])) {
            $productId = (int)$params['id_product'];
            $this->logToFile('[LengthPrice] hookActionProductDelete - id_product found in params: ' . $productId);
        } elseif (isset($params['id'])) {
            $productId = (int)$params['id'];
            $this->logToFile('[LengthPrice] hookActionProductDelete - id found in params: ' . $productId);
        } elseif (isset($params['productId']) && $params['productId'] instanceof ProductId) {
            $productId = (int)$params['productId']->getValue();
            $this->logToFile('[LengthPrice] hookActionProductDelete - ProductId Value Object found in params. ID: ' . $productId);
        } elseif (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $productId = (int)$params['object']->id;
            $this->logToFile('[LengthPrice] hookActionProductDelete - Product object found in params. ID: ' . $productId);
        }


        if ($productId) {
            $this->logToFile("[LengthPrice] hookActionProductDelete - Deleting module settings for Product ID: {$productId}.");
            if (LengthPriceDbRepository::deleteProductLengthPriceFlag($productId)) {
                $this->logToFile("[LengthPrice] hookActionProductDelete - Successfully deleted lengthprice_enabled flag for Product ID {$productId} from module table.");
            } else {
                $this->logToFile("[LengthPrice] BŁĄD: hookActionProductDelete - Failed to delete lengthprice_enabled flag for Product ID {$productId} from module table. DB Error: " . Db::getInstance()->getMsgError());
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
                        $this->logToFile("[LengthPrice] hookHeader - LengthPrice enabled and CF found for product ID: {$idProduct}. CF ID: {$customizationFieldId}");
                    } else {
                        $this->logToFile("[LengthPrice] hookHeader - LengthPrice enabled for product ID: {$idProduct}, but customization field not found.");
                    }
                } else {
                    $this->logToFile("[LengthPrice] hookHeader - LengthPrice is NOT enabled for product ID: {$idProduct}.");
                }
            }
        }
    }


    public function hookDisplayProductPriceBlock(array $params): string
    {
        // 1. Sprawdź typ hooka - interesuje nas tylko 'after_price'
        if (!isset($params['type']) || $params['type'] !== 'after_price') {
            // Celowo nie dodajemy tutaj logu, aby nie zaśmiecać, jeśli hook jest wołany z innymi typami
            return '';
        }
        $this->logToFile('[LengthPrice] hookDisplayProductPriceBlock - Hook type is "after_price".');

        $productId = 0;

        if (isset($params['product'])) {
            if ($params['product'] instanceof Product && isset($params['product']->id)) {
                $productId = (int)$params['product']->id;
                $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Product ID {$productId} obtained from Product object in params.");
            }
            elseif (isset($params['product']['id_product'])) {
                $productId = (int)$params['product']['id_product'];
                $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Product ID {$productId} obtained from params['product']['id_product'] (likely ProductLazyArray or array).");
            } else {
                $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - params['product'] is set but Product ID could not be extracted. Value type: " . gettype($params['product']));
            }
        }

        if (!$productId && isset($this->context->controller->product) && $this->context->controller->product instanceof Product && isset($this->context->controller->product->id)) {
            $productId = (int)$this->context->controller->product->id;
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Product ID {$productId} obtained from context->controller->product.");
        }

        if (!$productId) {
            $this->logToFile('[LengthPrice] hookDisplayProductPriceBlock - Exiting: Product ID could not be definitively obtained.');
            return '';
        }
        $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Proceeding with Product ID: {$productId}.");

        // 4. Kontynuuj z logiką modułu, używając $productId
        $isLengthPriceEnabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($productId);
        if (!$isLengthPriceEnabled) {
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Exiting: LengthPrice is NOT enabled for product ID: {$productId}.");
            return '';
        }
        $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - LengthPrice IS enabled for product ID: {$productId}.");

        $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId, (int)$this->context->language->id);
        if ($customizationFieldId === null) {
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Exiting: Nie znaleziono pola personalizacji LengthPrice dla produktu ID: {$productId}.");
            return '';
        }
        $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Found CF ID: {$customizationFieldId} for product ID: {$productId}.");

        // Używamy $productId do pobrania ceny
        $price_per_unit = Product::getPriceStatic($productId, true, null, 6);
        $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Price per unit for product ID {$productId}: {$price_per_unit}.");

        $this->context->smarty->assign([
            'price_per_unit' => $price_per_unit,
            'customization_field_id' => $customizationFieldId,
        ]);
        $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Smarty variables assigned. Fetching template 'module:{$this->name}/views/templates/hook/lengthprice.tpl'...");

        return $this->fetch('module:' . $this->name . '/views/templates/hook/lengthprice.tpl');
    }

    public function hookActionCartSave(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionCartSave triggered.');
        if (!isset($params['cart']) || !$params['cart'] instanceof Cart) {
            $this->logToFile('[LengthPrice] hookActionCartSave - Cart object not found in params.');
            return;
        }

        /** @var Cart $cart */
        $cart = $params['cart'];
        $productsInCart = $cart->getProducts(true); // true to get customizations

        if (empty($productsInCart)) {
            $this->logToFile('[LengthPrice] hookActionCartSave - Cart is empty.');
            return;
        }

        // Inicjalizacja repozytorium
        $translator = SymfonyContainer::getInstance()->get('translator');
        $languages = Language::getLanguages(false);
        $cartRepository = new LengthPriceCartRepository(
            $this,
            Db::getInstance(),
            _DB_PREFIX_,
            $translator,
            $languages
        );

        foreach ($productsInCart as $product) {
            if (isset($product['id_customization']) && (int)$product['id_customization'] > 0) {
                $idCustomization = (int)$product['id_customization'];
                $idProduct = (int)$product['id_product'];
                $idShop = (int)$this->context->shop->id;

                $lengthPriceCustomizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($idProduct, (int)$this->context->language->id);

                if ($lengthPriceCustomizationFieldId === null) {
                    $this->logToFile("[LengthPrice] hookActionCartSave - No lengthprice customization field found for product ID {$idProduct}. Skipping.");
                    continue;
                }

                $query = new DbQuery();
                $query->select('`value`');
                $query->from('customized_data');
                $query->where('`id_customization` = ' . $idCustomization);
                $query->where('`index` = ' . (int)$lengthPriceCustomizationFieldId);
                $query->where('`type` = 0');

                $lengthValue = Db::getInstance()->getValue($query);

                if ($lengthValue !== false && $lengthValue !== null) {
                    $this->logToFile("[LengthPrice] hookActionCartSave - Found length value '{$lengthValue}' for product ID {$idProduct}, customization ID {$idCustomization}, field ID {$lengthPriceCustomizationFieldId}.");

                    $structuredData = ['length' => $lengthValue];
                    if ($cartRepository->updateStructuredCustomizationData($idCustomization, $lengthPriceCustomizationFieldId, $structuredData)) {
                        $this->logToFile("[LengthPrice] hookActionCartSave - Successfully updated structured data for customization ID {$idCustomization}.");
                    } else {
                        $this->logToFile("[LengthPrice] hookActionCartSave - Failed to update structured data for customization ID {$idCustomization}.");
                    }
                } else {
                    $this->logToFile("[LengthPrice] hookActionCartSave - No length value found in customized_data for product ID {$idProduct}, customization ID {$idCustomization}, field ID {$lengthPriceCustomizationFieldId}. Query: " . $query);
                }
            }
        }
    }


    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $this->logToFile('[LengthPrice] hookDisplayAdminProductsExtra.');

        $id_product = (int)$params['id_product'];

        if (!$id_product) {
            $this->logToFile('[LengthPrice] hookDisplayAdminProductsExtra - Invalid product ID.');
            return $this->trans('Product not found.', [], 'Modules.Lengthprice.Admin');
        }

        $lengthprice_enabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($id_product);

        $this->logToFile("[LengthPrice] hookDisplayAdminProductsExtra - Reading lengthprice_enabled flag for Product ID {$id_product} from module table: " . ($lengthprice_enabled ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

        $router = $this->get('router');
        $ajaxUrl = $router->generate('lengthprice_admin_save_settings');

        $this->logToFile('[LengthPrice] Generated AJAX URL: ' . $ajaxUrl);

        $this->context->smarty->assign([
            'lengthprice_enabled' => $lengthprice_enabled,
            'id_product' => $id_product,
            'ajax_controller_url' => $ajaxUrl,
            'module_dir' => $this->_path,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/lengthprice_module_tab.tpl');
    }
}