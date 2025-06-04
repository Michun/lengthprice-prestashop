<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/LengthPriceOverrideManager.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';

use PrestaShopBundle\Form\Admin\Type\SwitchType; // Jeśli używasz w formularzu konfiguracyjnym

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

        if (!parent::install()
            || !$this->registerHook('displayProductPriceBlock')
            || !$this->registerHook('header') // 'actionFrontControllerSetMedia' dla PS 1.7+ do dodawania JS/CSS
            || !$this->registerHook('actionAdminProductsControllerSaveAfter')
            //|| !$this->registerHook('displayAdminProductsOptionsStepTop')
            || !$this->registerHook('displayAdminProductsExtra')
            || !$this->addProductField()
            || !$this->addCustomizationFieldFlag()
            || !LengthPriceOverrideManager::createProductOverride($this)) {
            return false;
        }
        return true;
    }

    public function uninstall(): bool
    {

        $success = parent::uninstall();
        $success = $success && $this->unregisterHook('displayProductPriceBlock');
        $success = $success && $this->unregisterHook('header');
        $success = $success && $this->unregisterHook('actionAdminProductsControllerSaveAfter');
        $success = $success && $this->unregisterHook('displayAdminProductsExtra');
//        $success = $success && $this->unregisterHook('displayAdminProductsOptionsStepTop');
        $success = $success && $this->removeProductField();
        $success = $success && $this->removeCustomizationFieldFlag();
        $success = $success && LengthPriceOverrideManager::removeProductOverride($this);

        return $success;
    }

    private function logToFile(string $message): void
    {
        // Rozważ użycie PrestaShopLogger::addLog($message, 1, null, 'LengthPrice', null, true, $this->id);
        // Poziom 1 = informacyjny, 2 = ostrzeżenie, 3 = błąd
        $logfile = _PS_MODULE_DIR_ . $this->name . '/debug.log'; // Użyj $this->name dla spójności
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logfile, $entry, FILE_APPEND);
        // Usunięto sprawdzanie $res, file_put_contents zwróci false i PHP wygeneruje warning, jeśli zapis się nie uda
    }

    private function addProductField(): bool
    {
        $tableName = 'product';
        $columnName = 'lengthprice_enabled';
        if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getAddColumnSql($tableName, $columnName, 'TINYINT(1) NOT NULL DEFAULT 0');
            return Db::getInstance()->execute($sql);
        }
        return true;
    }

    private function removeProductField(): bool
    {
        $tableName = 'product';
        $columnName = 'lengthprice_enabled';
        if (LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getDropColumnSql($tableName, $columnName);
            return Db::getInstance()->execute($sql);
        }
        return true;
    }


    private function addCustomizationFieldFlag(): bool
    {
        $tableName = 'customization_field';
        $columnName = 'is_lengthprice';
        if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $this->logToFile("Dodaję kolumnę {$columnName} do {$tableName}");
            $sql = LengthPriceDbRepository::getAddColumnSql($tableName, $columnName, 'TINYINT(1) NOT NULL DEFAULT 0');
            $result = Db::getInstance()->execute($sql);
            $this->logToFile("ALTER TABLE wynik: " . ($result ? 'OK' : 'BŁĄD'));
            return (bool)$result;
        } else {
            $this->logToFile("Kolumna {$columnName} już istnieje w {$tableName}");
        }
        return true;
    }


    private function removeCustomizationFieldFlag(): bool
    {
        $tableName = 'customization_field';
        $columnName = 'is_lengthprice';
        if (LengthPriceDbRepository::columnExists($tableName, $columnName)) {
            $sql = LengthPriceDbRepository::getDropColumnSql($tableName, $columnName);
            return Db::getInstance()->execute($sql);
        }
        return true;
    }

    // __call jest OK dla debugowania

    public function hookActionAdminProductsControllerSaveAfter(array $params): void
    {
        $this->logToFile('hookActionAdminProductsControllerSaveAfter triggered');
        if (!isset($params['return']->id)) {
            $this->logToFile('hookActionAdminProductsControllerSaveAfter: Brak ID produktu w parametrach.');
            return;
        }
        $idProduct = (int)$params['return']->id;
        $product = new Product($idProduct);

        if (Validate::isLoadedObject($product)) {
            $this->logToFile('hookActionAdminProductsControllerSaveAfter: Product ID ' . $product->id);
            if ((bool)$product->lengthprice_enabled) {
                $existingFields = LengthPriceDbRepository::getExistingLengthCustomizationFields($idProduct, (int)$this->context->language->id);
                $alreadyExists = false;
                if (is_array($existingFields)) { // Sprawdzenie czy $existingFields jest tablicą
                    foreach ($existingFields as $field) {
                        // Użyj Tools::strtolower dla porównania case-insensitive, jeśli nazwa może mieć różną wielkość liter
                        if (isset($field['name']) && Tools::strtolower($field['name']) === 'length (mm)') { // 'length (mm)' zamiast 'Length'
                            $alreadyExists = true;
                            break;
                        }
                    }
                }


                $this->logToFile('$alreadyExists ' . ($alreadyExists ? '1' : '0'));

                if (!$alreadyExists) {
                    $cf = new CustomizationField();
                    $cf->id_product = $idProduct;
                    $cf->type = 1; // Typ pola tekstowego
                    $cf->required = 1;

                    foreach (Language::getLanguages(false) as $lang) {
                        $cf->name[$lang['id_lang']] = 'Length (mm)';
                    }

                    if ($cf->add(true, false)) {
                        $this->logToFile('CustomizationField (rekord główny i _lang) dodany przez ObjectModel, ID: ' . $cf->id);

                        if (LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$cf->id)) {
                            $this->logToFile('Pomyślnie ustawiono is_lengthprice=1 dla CF ID: ' . $cf->id);
                            $verification_data = LengthPriceDbRepository::getCustomizationFieldForVerification((int)$cf->id);
                            if ($verification_data) {
                                $this->logToFile('WERYFIKACJA: Rekord CF ID ' . $cf->id . ' istnieje. is_lengthprice = ' . ($verification_data['is_lengthprice'] ?? 'N/A'));
                            } else {
                                $this->logToFile('WERYFIKACJA: Rekord CF ID ' . $cf->id . ' NIE istnieje.');
                            }
                        } else {
                            $db_error = Db::getInstance()->getMsgError();
                            $this->logToFile('BŁĄD: Nie udało się ustawić is_lengthprice=1 dla CF ID: ' . $cf->id . '. Błąd bazy danych: ' . $db_error);
                        }

//                        $product->customizable = 1;
//                         $product->text_fields  = 1;
//                        if (!$product->update()) {
//                            $this->logToFile('BŁĄD: Nie udało się zaktualizować flag personalizacji dla Produktu ID: ' . $product->id);
//                        } else {
//                            $this->logToFile('Produkt zaktualizowany jako konfigurowalny dla ID: ' . $product->id);
//                        }


                    } else {
                        $validation_messages = $cf->getValidationMessages(); // Dla PrestaShop 1.7+
                        $errors_string = '';
                        if (is_array($validation_messages) && count($validation_messages) > 0) {
                            $errors_string = implode(', ', $validation_messages);
                        }
                        $this->logToFile('BŁĄD: Metoda $cf->add() nie powiodła się dla Produktu ID: ' . $product->id . '. Błędy walidacji: ' . $errors_string . '. Ostatni błąd DB (jeśli jest): ' . Db::getInstance()->getMsgError());
                    }
                }
            }
        } else {
            $this->logToFile('hookActionAdminProductsControllerSaveAfter: Nie udało się załadować produktu ID: ' . $idProduct);
        }
    }

    public function hookHeader(): void
    {
        $this->context->controller->addJS($this->_path . 'views/js/lengthprice.js');

        if ($this->context->controller->php_self === 'product') { // Dla strony produktu
            $idProduct = (int)Tools::getValue('id_product');
            if (!$idProduct && isset($this->context->controller->product) && $this->context->controller->product instanceof Product) {
                $idProduct = (int)$this->context->controller->product->id;
            }

            if ($idProduct > 0) {
                $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldId($idProduct);
                Media::addJsDef(['lengthpriceCustomizationFieldId' => $customizationFieldId]);
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

        if (!$product->lengthprice_enabled) {
            return '';
        }

        $this->logToFile('MODUŁ lengthprice JEST WYWOŁANY DLA: ' . $product->id);

        $price_per_unit = Product::getPriceStatic($product->id, true, null, 6);
        $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldId((int)$product->id);

        $this->context->smarty->assign([
            'price_per_unit' => $price_per_unit,
            'customization_field_id' => $customizationFieldId,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/lengthprice.tpl');
    }

    public function hookDisplayAdminProductsExtra(array $params): string // Dodano typowanie
    {
        $this->logToFile('[LengthPrice] hookDisplayAdminProductsExtra.');

        $id_product = (int) $params['id_product'];
        $product = new Product($id_product); // Można by pobrać z $params['product'] jeśli jest dostępne

        if (!Validate::isLoadedObject($product)) {
            return $this->trans('Product not found.', [], 'Modules.Lengthprice.Admin');
        }

        $this->context->smarty->assign([
            'lengthprice_enabled' => (bool) $product->lengthprice_enabled, // Dostęp dzięki override
            'id_product' => $product->id,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/lengthprice_module_tab.tpl');
    }
}