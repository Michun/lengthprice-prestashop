<?php
// modules/lengthprice/lengthprice.php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Schema.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';
require_once dirname(__FILE__) . '/src/Service/LengthPriceProductSettingsService.php'; // Dodaj require dla serwisu

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\Module\LengthPrice\Service\LengthPriceProductSettingsService;

// Dodaj use dla serwisu

class LengthPrice extends Module
{
    // Usunięto statyczną flagę processedProductsInCurrentRequest, bo nie jest już potrzebna w tym podejściu
    // private static $managedProductsInCurrentRequest = []; // Usuń tę linię

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

        // Zarejestruj nowy kontroler administracyjny
        if (!$this->installControllers()) {
            $this->logToFile('[LengthPrice] BŁĄD: installControllers() failed.');
            return false;
        }
        $this->logToFile('[LengthPrice] installControllers() result: OK');


        $hooksRegistered = $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('header')
            && $this->registerHook('actionObjectProductAddAfter') // Pozostaw, może być potrzebny do inicjalizacji (choć mniej krytyczny teraz)
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('displayAdminProductsExtra');
        $this->logToFile('[LengthPrice] registerHook() calls result: ' . ($hooksRegistered ? 'OK' : 'FAIL'));
        if (!$hooksRegistered) return false;

        $customizationFlagAdded = $this->addCustomizationFieldFlag();
        $this->logToFile('[LengthPrice] addCustomizationFieldFlag() result: ' . ($customizationFlagAdded ? 'OK' : 'FAIL'));
        if (!$customizationFlagAdded) return false;

        $schemaInstalled = $schema->installSchema();
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

        // Odrejestruj nowy kontroler administracyjny
        if (!$this->uninstallControllers()) {
            $this->logToFile('[LengthPrice] BŁĄD: uninstallControllers() failed.');
            $success = false; // Nie przerywaj, ale oznacz jako niepowodzenie
        }
        $this->logToFile('[LengthPrice] uninstallControllers() result: OK');


        // Odrejestruj hooki
        $hookUnregister1 = $this->unregisterHook('displayProductPriceBlock');
        $this->logToFile('[LengthPrice] unregisterHook(displayProductPriceBlock) result: ' . ($hookUnregister1 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister1;

        $hookUnregister2 = $this->unregisterHook('header');
        $this->logToFile('[LengthPrice] unregisterHook(header) result: ' . ($hookUnregister2 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister2;

        // Odrejestruj hooki produktu, których już nie używamy do zapisu
        $hookUnregister3 = $this->unregisterHook('actionObjectProductUpdateAfter'); // Odrejestruj
        $this->logToFile('[LengthPrice] unregisterHook(actionObjectProductUpdateAfter) result: ' . ($hookUnregister3 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister3;

        $hookUnregister4 = $this->unregisterHook('actionObjectProductAddAfter'); // Odrejestruj (jeśli nie używasz do inicjalizacji)
        $this->logToFile('[LengthPrice] unregisterHook(actionObjectProductAddAfter) result: ' . ($hookUnregister4 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister4;

        $hookUnregister5 = $this->unregisterHook('actionProductDelete'); // Pozostaw zarejestrowany, jest potrzebny
        $this->logToFile('[LengthPrice] unregisterHook(actionProductDelete) result: ' . ($hookUnregister5 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister5;

        $hookUnregister6 = $this->unregisterHook('displayAdminProductsExtra'); // Pozostaw zarejestrowany, jest potrzebny
        $this->logToFile('[LengthPrice] unregisterHook(displayAdminProductsExtra) result: ' . ($hookUnregister6 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister6;

        // Odrejestruj actionProductUpdate jeśli był zarejestrowany
        // $hookUnregister7 = $this->unregisterHook('actionProductUpdate'); // Odrejestruj
        // $this->logToFile('[LengthPrice] unregisterHook(actionProductUpdate) result: ' . ($hookUnregister7 ? 'OK' : 'FAIL'));
        // $success = $success && $hookUnregister7;


        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed during hook unregistration.');
            // Nie przerywaj, pozwól na próbę usunięcia flagi i schematu
        }

        $removedCustomizationFlag = $this->removeCustomizationFieldFlag();
        $this->logToFile('[LengthPrice] removeCustomizationFieldFlag() result: ' . ($removedCustomizationFlag ? 'OK' : 'FAIL'));
        $success = $success && $removedCustomizationFlag;
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at removeCustomizationFieldFlag().');
            // Nie przerywaj, pozwól na próbę usunięcia schematu
        }

        $schemaUninstalled = $schema->uninstallSchema();
        $this->logToFile('[LengthPrice] schema->uninstallSchema() result: ' . ($schemaUninstalled ? 'OK' : 'FAIL'));
        $success = $success && $schemaUninstalled;
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at schema->uninstallSchema().');
            // Nie przerywaj, zwróć ogólny wynik
        }

        $this->logToFile('[LengthPrice] Uninstall method finished with overall success: ' . ($success ? 'OK' : 'FAIL'));
        return $success;
    }

    // Usuń metodę _manageCustomizationField, jej logika jest teraz w serwisie
    // private function _manageCustomizationField(int $productId, bool $isLengthPriceEnabledSubmitted): void { ... } // Usuń całą metodę


    private function logToFile(string $message): void
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

    // Metoda do instalacji kontrolerów administracyjnych
    public function installControllers(): bool
    {
        // Dodaj wpis do tabeli ps_tab
        $tab = new Tab();
        $tab->class_name = 'AdminLengthPriceSettings'; // Nazwa klasy kontrolera bez sufiksu 'Controller'
        $tab->module = $this->name;
        $tab->id_parent = -1; // Ukryty kontroler
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'LengthPrice Settings'; // Nazwa widoczna w menu (choć ten jest ukryty)
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

    // Metoda do deinstalacji kontrolerów administracyjnych
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
        return true; // Zwróć true, jeśli tab nie istnieje (już usunięty)
    }


    // Usuń logikę z hooków zapisu produktu
    public function hookActionProductUpdate(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionProductUpdate triggered (logic removed).');
        // Logika przeniesiona do kontrolera AJAX
    }

    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter triggered (logic removed).');
        // Logika przeniesiona do kontrolera AJAX
        // Możesz tutaj dodać logikę inicjalizacji, np. ustawienie flagi na 0 dla nowego produktu, jeśli domyślnie ma być wyłączona
        // if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
        //     LengthPriceDbRepository::saveProductLengthPriceFlag((int)$params['object']->id, false);
        // }
    }

    // hookActionProductDelete pozostaje bez zmian
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


    // hookHeader pozostaje bez zmian
    public function hookHeader(): void
    {
        $this->context->controller->addJS($this->_path . 'views/js/lengthprice.js');

        if ($this->context->controller->php_self === 'product') { // Dla strony produktu
            $idProduct = (int)Tools::getValue('id_product');
            if (!$idProduct && isset($this->context->controller->product) && $this->context->controller->product instanceof Product) {
                $idProduct = (int)$this->context->controller->product->id;
            }

            if ($idProduct > 0) {
                $isLengthPriceEnabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($idProduct);

                if ($isLengthPriceEnabled) {
                    // Przekaż ID języka do metody repozytorium
                    $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($idProduct, (int)$this->context->language->id);
                    if ($customizationFieldId !== null) { // Sprawdź, czy ID zostało znalezione
                        Media::addJsDef(['lengthpriceCustomizationFieldId' => $customizationFieldId]);
                        $this->logToFile("[LengthPrice] hookHeader - LengthPrice enabled and CF found for product ID: {$idProduct}. CF ID: {$customizationFieldId}");
                    } else {
                        // To może się zdarzyć, jeśli flaga w naszej tabeli jest true, ale pole personalizacji nie istnieje
                        $this->logToFile("[LengthPrice] hookHeader - LengthPrice enabled for product ID: {$idProduct}, but customization field not found.");
                    }
                } else {
                    $this->logToFile("[LengthPrice] hookHeader - LengthPrice is NOT enabled for product ID: {$idProduct}.");
                }
            }
        }
    }

    // hookDisplayProductPriceBlock pozostaje bez zmian
    public function hookDisplayProductPriceBlock(array $params): string
    {
        if (!isset($params['product']) || !($params['product'] instanceof Product)) {
            return '';
        }
        if ($params['type'] !== 'after_price') {
            return '';
        }

        /** @var Product $product */
        $product = $params['product'];
        $productId = (int)$product->id;

        if (!$productId) {
            $this->logToFile('[LengthPrice] hookDisplayProductPriceBlock - Invalid product ID.');
            return '';
        }

        $isLengthPriceEnabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($productId);

        if (!$isLengthPriceEnabled) {
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - LengthPrice is NOT enabled for product ID: {$productId}. Not displaying block.");
            return '';
        }

        $this->logToFile('MODUŁ lengthprice JEST WYWOŁANY DLA: ' . $productId);

        // Przekaż ID języka do metody repozytorium
        $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId, (int)$this->context->language->id);

        if ($customizationFieldId === null) {
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Nie znaleziono pola personalizacji LengthPrice dla produktu ID: {$productId}. Nie wyświetlam bloku.");
            return '';
        }

        $price_per_unit = Product::getPriceStatic($productId, true, null, 6);


        $this->context->smarty->assign([
            'price_per_unit' => $price_per_unit,
            'customization_field_id' => $customizationFieldId,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/lengthprice.tpl');
    }

    // hookDisplayAdminProductsExtra pozostaje bez zmian (tylko wyświetla stan)
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


        $this->context->smarty->assign([
            'lengthprice_enabled' => $lengthprice_enabled, // Odczytane z naszej tabeli
            'id_product' => $id_product,
            'ajax_controller_url' => $this->context->link->getAdminLink('AdminLengthPriceSettings'), // Przekaż URL kontrolera AJAX do szablonu
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/lengthprice_module_tab.tpl');
    }
}