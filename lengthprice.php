<?php
// modules/lengthprice/lengthprice.php

if (!defined('_PS_VERSION_')) {
    exit;
}

// Usunięto LengthPriceOverrideManager, dodano Schema
require_once dirname(__FILE__) . '/classes/Schema.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId; // Dodano use dla ProductId w hooku delete

class LengthPrice extends Module
{
    // Usunięto statyczną flagę processedProductsInRequest, bo nie jest już potrzebna w tym podejściu

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
        $schema = new Schema(); // Utworzenie instancji Schema

        $parentInstall = parent::install();
        $this->logToFile('[LengthPrice] parent::install() result: ' . ($parentInstall ? 'OK' : 'FAIL'));
        if (!$parentInstall) return false;

        $hooksRegistered = $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('header')
            && $this->registerHook('actionObjectProductUpdateAfter')
            && $this->registerHook('actionObjectProductAddAfter')
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

        $hookUnregister1 = $this->unregisterHook('displayProductPriceBlock');
        $this->logToFile('[LengthPrice] unregisterHook(displayProductPriceBlock) result: ' . ($hookUnregister1 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister1;

        $hookUnregister2 = $this->unregisterHook('header');
        $this->logToFile('[LengthPrice] unregisterHook(header) result: ' . ($hookUnregister2 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister2;

        $hookUnregister3 = $this->unregisterHook('actionObjectProductUpdateAfter');
        $this->logToFile('[LengthPrice] unregisterHook(actionObjectProductUpdateAfter) result: ' . ($hookUnregister3 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister3;

        $hookUnregister4 = $this->unregisterHook('actionObjectProductAddAfter');
        $this->logToFile('[LengthPrice] unregisterHook(actionObjectProductAddAfter) result: ' . ($hookUnregister4 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister4;

        $hookUnregister5 = $this->unregisterHook('actionProductDelete');
        $this->logToFile('[LengthPrice] unregisterHook(actionProductDelete) result: ' . ($hookUnregister5 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister5;

        $hookUnregister6 = $this->unregisterHook('displayAdminProductsExtra');
        $this->logToFile('[LengthPrice] unregisterHook(displayAdminProductsExtra) result: ' . ($hookUnregister6 ? 'OK' : 'FAIL'));
        $success = $success && $hookUnregister6;

        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed during hook unregistration.');
            return false;
        }

        $removedCustomizationFlag = $this->removeCustomizationFieldFlag();
        $this->logToFile('[LengthPrice] removeCustomizationFieldFlag() result: ' . ($removedCustomizationFlag ? 'OK' : 'FAIL'));
        $success = $success && $removedCustomizationFlag;
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at removeCustomizationFieldFlag().');
            return false;
        }

        $schemaUninstalled = $schema->uninstallSchema();
        $this->logToFile('[LengthPrice] schema->uninstallSchema() result: ' . ($schemaUninstalled ? 'OK' : 'FAIL'));
        $success = $success && $schemaUninstalled;
        if (!$success) {
            $this->logToFile('[LengthPrice] Uninstall failed at schema->uninstallSchema().');
            return false;
        }

        $this->logToFile('[LengthPrice] Uninstall method finished with overall success: ' . ($success ? 'OK' : 'FAIL'));
        return $success;
    }

    private function _manageCustomizationField(int $productId, bool $isLengthPriceEnabledSubmitted): void
    {
        if (!$productId) {
            $this->logToFile('[LengthPrice] _manageCustomizationField - Invalid product ID provided.');
            return;
        }

        $this->logToFile("[LengthPrice] _manageCustomizationField dla Produktu ID: {$productId}. Flaga lengthprice_enabled (z Tools::getValue): " . ($isLengthPriceEnabledSubmitted ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

        if ($isLengthPriceEnabledSubmitted) {
            $this->logToFile("[LengthPrice] _manageCustomizationField - Aktywacja LengthPrice dla produktu ID: {$productId} na podstawie wartości z formularza.");
            $existingFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId);

            if (!$existingFieldId) {
                $this->logToFile("[LengthPrice] _manageCustomizationField - Tworzenie nowego CustomizationField dla Produktu ID: {$productId}");
                $cf = new CustomizationField();
                $cf->id_product = $productId;
                $cf->type = Product::CUSTOMIZE_TEXTFIELD;
                $cf->required = 1;

                foreach (Language::getLanguages(false) as $lang) {
                    $cf->name[$lang['id_lang']] = $this->l('Length (mm)');
                }

                if ($cf->add(true, false)) {
                    $this->logToFile("[LengthPrice] _manageCustomizationField - CustomizationField dodany, ID: {$cf->id}");
                    if (LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$cf->id)) {
                        $this->logToFile("[LengthPrice] _manageCustomizationField - Pomyślnie ustawiono is_lengthprice=1 dla CF ID: {$cf->id}");
                    } else {
                        $this->logToFile("[LengthPrice] BŁĄD: _manageCustomizationField - Nie udało się ustawić is_lengthprice=1 dla CF ID: {$cf->id}. Błąd DB: " . Db::getInstance()->getMsgError());
                    }
                } else {
                    $validation_messages = $cf->getValidationMessages();
                    $errors_string = is_array($validation_messages) ? implode(', ', $validation_messages) : '';
                    $this->logToFile("[LengthPrice] BŁĄD: _manageCustomizationField - \$cf->add() nie powiodło się dla Produktu ID: {$productId}. Błędy: {$errors_string}");
                }
            } else {
                $this->logToFile("[LengthPrice] _manageCustomizationField - CustomizationField z flagą is_lengthprice=1 już istnieje dla Produktu ID: {$productId} (ID: {$existingFieldId}). Nie tworzę nowego.");
                if ($existingFieldId && !LengthPriceDbRepository::isCustomizationFieldLengthFlagEnabled((int)$existingFieldId)) {
                    if (LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$existingFieldId)) {
                        $this->logToFile("[LengthPrice] _manageCustomizationField - Ustawiono is_lengthprice=1 dla istniejącego CF ID: {$existingFieldId}.");
                    } else {
                        $this->logToFile("[LengthPrice] BŁĄD: _manageCustomizationField - Nie udało się ustawić is_lengthprice=1 dla istniejącego CF ID: {$existingFieldId}.");
                    }
                }
            }
        } else {
            $this->logToFile("[LengthPrice] _manageCustomizationField - Deaktywacja LengthPrice dla produktu ID: {$productId} na podstawie wartości z formularza. Sprawdzam, czy trzeba usunąć istniejące pole.");
            $existingFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId);
            if ($existingFieldId) {
                $this->logToFile("[LengthPrice] _manageCustomizationField - Znaleziono istniejące pole (ID: {$existingFieldId}) do usunięcia dla Produktu ID: {$productId}.");
                $cf_to_delete = new CustomizationField((int)$existingFieldId);
                if (Validate::isLoadedObject($cf_to_delete)) {
                    if ($cf_to_delete->delete(false)) {
                        $this->logToFile("[LengthPrice] _manageCustomizationField - Pomyślnie usunięto CustomizationField ID: {$existingFieldId}");
                    } else {
                        $this->logToFile("[LengthPrice] BŁĄD: _manageCustomizationField - Nie udało się usunąć CustomizationField ID: {$existingFieldId}");
                    }
                } else {
                    $this->logToFile("[LengthPrice] BŁĄD: _manageCustomizationField - Nie udało się załadować CustomizationField ID: {$existingFieldId} do usunięcia.");
                }
            } else {
                $this->logToFile("[LengthPrice] _manageCustomizationField - Brak pola is_lengthprice do usunięcia dla Produktu ID: {$productId}.");
            }
        }
    }


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


    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductUpdateAfter triggered.');
        if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $product = $params['object'];
            $productId = (int)$product->id;
            $this->logToFile('[LengthPrice] hookActionObjectProductUpdateAfter - Product object found in params. ID: ' . $productId);

            $isLengthPriceEnabledSubmitted = (bool)Tools::getValue('lengthprice_enabled');

            $this->logToFile("[LengthPrice] hookActionObjectProductUpdateAfter - Product ID: {$productId}. Value from POST: " . ($isLengthPriceEnabledSubmitted ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

            if (LengthPriceDbRepository::saveProductLengthPriceFlag($productId, $isLengthPriceEnabledSubmitted)) {
                $this->logToFile("[LengthPrice] hookActionObjectProductUpdateAfter - Successfully saved lengthprice_enabled flag ({$isLengthPriceEnabledSubmitted}) for Product ID {$productId} in module table.");
            } else {
                $this->logToFile("[LengthPrice] BŁĄD: hookActionObjectProductUpdateAfter - Failed to save lengthprice_enabled flag for Product ID {$productId} in module table. DB Error: " . Db::getInstance()->getMsgError());
            }

            $this->_manageCustomizationField($productId, $isLengthPriceEnabledSubmitted);

        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductUpdateAfter - Brak obiektu Product w parametrach.');
        }
    }

    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter triggered.');
        if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $product = $params['object'];
            $productId = (int)$product->id;
            $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter - Product ID: ' . $productId);

            $isLengthPriceEnabledSubmitted = (bool)Tools::getValue('lengthprice_enabled');

            $this->logToFile("[LengthPrice] hookActionObjectProductAddAfter - Product ID: {$productId}. Value from POST: " . ($isLengthPriceEnabledSubmitted ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

            if (LengthPriceDbRepository::saveProductLengthPriceFlag($productId, $isLengthPriceEnabledSubmitted)) {
                $this->logToFile("[LengthPrice] hookActionObjectProductAddAfter - Successfully saved lengthprice_enabled flag ({$isLengthPriceEnabledSubmitted}) for Product ID {$productId} in module table.");
            } else {
                $this->logToFile("[LengthPrice] BŁĄD: hookActionObjectProductAddAfter - Failed to save lengthprice_enabled flag for Product ID {$productId} in module table. DB Error: " . Db::getInstance()->getMsgError());
            }

            $this->_manageCustomizationField($productId, $isLengthPriceEnabledSubmitted);

        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductAddAfter - Brak obiektu Product w parametrach.');
        }
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
            // Usuń wpis z naszej dedykowanej tabeli
            if (LengthPriceDbRepository::deleteProductLengthPriceFlag($productId)) {
                $this->logToFile("[LengthPrice] hookActionProductDelete - Successfully deleted lengthprice_enabled flag for Product ID {$productId} from module table.");
            } else {
                $this->logToFile("[LengthPrice] BŁĄD: hookActionProductDelete - Failed to delete lengthprice_enabled flag for Product ID {$productId} from module table. DB Error: " . Db::getInstance()->getMsgError());
            }
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionProductDelete - Could not determine Product ID from params.');
        }
    }


    // hookHeader - modyfikacja do odczytu flagi z nowej tabeli
    public function hookHeader(): void
    {
        $this->context->controller->addJS($this->_path . 'views/js/lengthprice.js');

        if ($this->context->controller->php_self === 'product') { // Dla strony produktu
            $idProduct = (int)Tools::getValue('id_product');
            if (!$idProduct && isset($this->context->controller->product) && $this->context->controller->product instanceof Product) {
                $idProduct = (int)$this->context->controller->product->id;
            }

            if ($idProduct > 0) {
                // Sprawdź flagę w naszej dedykowanej tabeli
                $isLengthPriceEnabled = LengthPriceDbRepository::isLengthPriceEnabledForProduct($idProduct);

                if ($isLengthPriceEnabled) {
                    $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($idProduct);
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

        $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId);

        if ($customizationFieldId === null) {
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Nie znaleziono pola personalizacji LengthPrice dla produktu ID: {$productId}. Nie wyświetlam bloku.");
            return ''; // Nie wyświetlaj bloku, jeśli pole nie istnieje
        }

        $price_per_unit = Product::getPriceStatic($productId, true, null, 6);


        $this->context->smarty->assign([
            'price_per_unit' => $price_per_unit,
            'customization_field_id' => $customizationFieldId,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/lengthprice.tpl');
    }

    public function hookDisplayAdminProductsExtra(array $params): string // Dodano typowanie
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
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/lengthprice_module_tab.tpl');
    }
}