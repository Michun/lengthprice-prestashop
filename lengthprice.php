<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/LengthPriceOverrideManager.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';

use PrestaShopBundle\Form\Admin\Type\SwitchType;


class LengthPrice extends Module
{
    private static $processedProductsInRequest = [];

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
        return parent::install()
            && $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('header')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('displayAdminProductsExtra')
            && $this->addProductField()
            && $this->addCustomizationFieldFlag()
            && LengthPriceOverrideManager::createProductOverride($this);
    }

    public function uninstall(): bool
    {

        $success = parent::uninstall();
        $success = $success && $this->unregisterHook('displayProductPriceBlock');
        $success = $success && $this->unregisterHook('header');
        $success = $success && $this->unregisterHook('actionProductUpdate');                  // DODAJ
        $success = $success && $this->unregisterHook('actionObjectProductAddAfter');          // DODAJ
        $success = $success && $this->unregisterHook('displayAdminProductsExtra');
//        $success = $success && $this->unregisterHook('displayAdminProductsOptionsStepTop');
        $success = $success && $this->removeProductField();
        $success = $success && $this->removeCustomizationFieldFlag();
        $success = $success && LengthPriceOverrideManager::removeProductOverride($this);

        return $success;
    }

    private function _handleProductSave(Product $productInstance): void
    {
        if (!$productInstance || !$productInstance->id) {
            $this->logToFile('[LengthPrice] _handleProductSave - Invalid product instance provided.');
            return;
        }

        $productId = (int)$productInstance->id;

        if (isset(self::$processedProductsInRequest[$productId])) {
            $this->logToFile("[LengthPrice] _handleProductSave - Product ID: {$productId} already processed in this request. Skipping further processing.");
            return;
        }

        $this->logToFile("[LengthPrice] _handleProductSave - Processing Product ID: {$productId}.");
        self::$processedProductsInRequest[$productId] = true;

        $this->manageLengthPriceCustomization($productInstance);
    }

    private function logToFile(string $message): void
    {
        // PrestaShopLogger::addLog($message, 1, null, 'LengthPrice', null, true, $this->id);
        // Poziom 1 = informacyjny, 2 = ostrzeżenie, 3 = błąd
        $logfile = _PS_MODULE_DIR_ . $this->name . '/debug.log'; // Użyj $this->name dla spójności
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logfile, $entry, FILE_APPEND);
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

    public function hookActionProductUpdate(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionProductUpdate triggered.');
        $product = null;

        if (isset($params['product']) && $params['product'] instanceof Product && $params['product']->id) {
            $product = $params['product'];
            $this->logToFile('[LengthPrice] hookActionProductUpdate - Product object found in params. ID: ' . $product->id);
        } elseif (isset($params['id_product'])) {
            $id_product = (int)$params['id_product'];
            $this->logToFile('[LengthPrice] hookActionProductUpdate - id_product found in params: ' . $id_product);
            $product = new Product($id_product); // Załaduj produkt, jeśli przekazano tylko ID
            if (!Validate::isLoadedObject($product)) {
                $this->logToFile('[LengthPrice] BŁĄD: hookActionProductUpdate - Nie udało się załadować produktu ID z params: ' . $id_product);
                return;
            }
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionProductUpdate - Brak obiektu Product lub id_product w parametrach.');
            return;
        }

        if ($product && $product->id) {
            $this->_handleProductSave($product);
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionProductUpdate - Product object is not valid after attempting to load/retrieve.');
        }
    }

    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter triggered.');
        if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $product = $params['object'];
            $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter - Product ID: ' . $product->id);
            $this->_handleProductSave($product);
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductAddAfter - Brak obiektu Product w parametrach.');
        }
    }

    private function manageLengthPriceCustomization(Product $productFromHook): void
    {
        // Upewniamy się, że Product::$definition jest rozszerzone o nasze pole.
        // To jest już obsługiwane przez LengthPriceOverrideManager.
        if (!isset(Product::$definition['fields']['lengthprice_enabled'])) {
            Product::$definition['fields']['lengthprice_enabled'] = ['type' => Product::TYPE_BOOL, 'validate' => 'isBool'];
        }

        $productId = (int)$productFromHook->id;

        $isLengthPriceEnabledSubmitted = (bool)Tools::getValue('lengthprice_enabled');

        $this->logToFile("[LengthPrice] manageLengthPriceCustomization dla Produktu ID: {$productId}. Flaga lengthprice_enabled (z Tools::getValue): " . ($isLengthPriceEnabledSubmitted ? 'WŁĄCZONA' : 'WYŁĄCZONA'));



        if ($isLengthPriceEnabledSubmitted) {
            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Aktywacja LengthPrice dla produktu ID: {$productId} na podstawie wartości z formularza.");
            $existingFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId);

            if (!$existingFieldId) {
                $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Tworzenie nowego CustomizationField dla Produktu ID: {$productId}");
                $cf = new CustomizationField();
                $cf->id_product = $productId; // Użyj $productId
                $cf->type = Product::CUSTOMIZE_TEXTFIELD;
                $cf->required = 1;

                foreach (Language::getLanguages(false) as $lang) {
                    $cf->name[$lang['id_lang']] = $this->l('Length (mm)');
                }

                if ($cf->add(true, false)) {
                    $this->logToFile("[LengthPrice] manageLengthPriceCustomization - CustomizationField dodany, ID: {$cf->id}");
                    if (LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$cf->id)) {
                        $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Pomyślnie ustawiono is_lengthprice=1 dla CF ID: {$cf->id}");

                        $productToUpdate = new Product($productId, true, $this->context->language->id, $this->context->shop->id);
                        if (Validate::isLoadedObject($productToUpdate)) {
                            $productToUpdate->customizable = 1;

                            $text_fields_count = 0;
                            $uploadable_files_count = 0;
                            $customization_fields_data = $productToUpdate->getCustomizationFields((int)$this->context->language->id, (int)$this->context->shop->id);

                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Zwrócone pola personalizacji (PO DODANIU CF, płaska struktura): " . print_r($customization_fields_data, true)); // Dodatkowe logowanie

                            if (is_array($customization_fields_data)) {
                                // Iteruj bezpośrednio po płaskiej liście tablic asocjacyjnych
                                foreach ($customization_fields_data as $field_data) {
                                    // $field_data jest teraz pojedynczą tablicą asocjacyjną pola
                                    if (isset($field_data['type'])) {
                                        $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Przetwarzam pole ID: " . ($field_data['id_customization_field'] ?? 'N/A') . ", Typ: " . $field_data['type']);
                                        if ((int)$field_data['type'] === Product::CUSTOMIZE_TEXTFIELD) {
                                            $text_fields_count++;
                                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Zwiększono text_fields_count do: " . $text_fields_count);
                                        } elseif ((int)$field_data['type'] === Product::CUSTOMIZE_FILE) {
                                            $uploadable_files_count++;
                                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Zwiększono uploadable_files_count do: " . $uploadable_files_count);
                                        }
                                    } else {
                                        $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Pole bez klucza 'type': " . print_r($field_data, true));
                                    }
                                }
                            }
                            $productToUpdate->text_fields = $text_fields_count;
                            $productToUpdate->uploadable_files = $uploadable_files_count;

                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Aktualizacja Produktu ID {$productToUpdate->id} z customizable=1, text_fields={$productToUpdate->text_fields}, uploadable_files={$productToUpdate->uploadable_files}");
                            if (!$productToUpdate->update()) {
                                $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - NIE udało się zaktualizować Produktu ID {$productToUpdate->id} (flagi personalizacji).");
                            } else {
                                $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Produkt ID {$productToUpdate->id} zaktualizowany pomyślnie (flagi personalizacji).");
                            }
                        } else {
                            $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - Nie udało się załadować produktu ID: {$productId} do aktualizacji flag personalizacji.");
                        }
                    } else {
                        $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - Nie udało się ustawić is_lengthprice=1 dla CF ID: {$cf->id}. Błąd DB: " . Db::getInstance()->getMsgError());
                    }
                } else {
                    $validation_messages = $cf->getValidationMessages();
                    $errors_string = is_array($validation_messages) ? implode(', ', $validation_messages) : '';
                    $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - \$cf->add() nie powiodło się dla Produktu ID: {$productId}. Błędy: {$errors_string}");
                }
            } else {
                $this->logToFile("[LengthPrice] manageLengthPriceCustomization - CustomizationField z flagą is_lengthprice=1 już istnieje dla Produktu ID: {$productId} (ID: {$existingFieldId}). Nie tworzę nowego.");
            }
        } else {
            // Logika usuwania pola, jeśli $isLengthPriceEnabledSubmitted jest false
            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Deaktywacja LengthPrice dla produktu ID: {$productId} na podstawie wartości z formularza. Sprawdzam, czy trzeba usunąć istniejące pole.");
            $existingFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId);
            if ($existingFieldId) {
                $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Znaleziono istniejące pole (ID: {$existingFieldId}) do usunięcia dla Produktu ID: {$productId}.");
                $cf_to_delete = new CustomizationField((int)$existingFieldId);
                if (Validate::isLoadedObject($cf_to_delete)) {
                    if ($cf_to_delete->delete()) {
                        $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Pomyślnie usunięto CustomizationField ID: {$existingFieldId}");
                        $productToUpdate = new Product($productId, true, $this->context->language->id, $this->context->shop->id);
                        if (Validate::isLoadedObject($productToUpdate)) {
                            $customization_fields_data_after_delete = $productToUpdate->getCustomizationFields((int)$this->context->language->id, (int)$this->context->shop->id);

                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Zwrócone pola personalizacji (PO USUNIĘCIU CF, płaska struktura): " . print_r($customization_fields_data_after_delete, true)); // Dodatkowe logowanie

                            $text_fields_count_after_delete = 0;
                            $uploadable_files_count_after_delete = 0;
                            if (is_array($customization_fields_data_after_delete)) {
                                foreach ($customization_fields_data_after_delete as $field_data) {
                                    if (isset($field_data['type'])) {
                                        $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Przetwarzam pole ID: " . ($field_data['id_customization_field'] ?? 'N/A') . ", Typ: " . $field_data['type']);
                                        if ((int)$field_data['type'] === Product::CUSTOMIZE_TEXTFIELD) {
                                            $text_fields_count_after_delete++;
                                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Zwiększono text_fields_count_after_delete do: " . $text_fields_count_after_delete);
                                        } elseif ((int)$field_data['type'] === Product::CUSTOMIZE_FILE) {
                                            $uploadable_files_count_after_delete++;
                                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Zwiększono uploadable_files_count_after_delete do: " . $uploadable_files_count_after_delete);
                                        }
                                    } else {
                                        $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Pole bez klucza 'type': " . print_r($field_data, true));
                                    }
                                }
                            }
                            $productToUpdate->text_fields = $text_fields_count_after_delete;
                            $productToUpdate->uploadable_files = $uploadable_files_count_after_delete;
                            $productToUpdate->customizable = ($text_fields_count_after_delete > 0 || $uploadable_files_count_after_delete > 0) ? 1 : 0;

                            $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Aktualizacja Produktu ID {$productToUpdate->id} po usunięciu CF: customizable={$productToUpdate->customizable}, text_fields={$productToUpdate->text_fields}");
                            if (!$productToUpdate->update()) {
                                $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - NIE udało się zaktualizować Produktu ID {$productToUpdate->id} po usunięciu CF.");
                            } else {
                                $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Produkt ID {$productToUpdate->id} zaktualizowany pomyślnie po usunięciu CF.");
                            }
                        } else {
                            $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - Nie udało się załadować produktu ID: {$productId} do aktualizacji flag personalizacji po usunięciu CF.");
                        }
                    } else {
                        $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - Nie udało się usunąć CustomizationField ID: {$existingFieldId}");
                    }
                } else {
                    $this->logToFile("[LengthPrice] BŁĄD: manageLengthPriceCustomization - Nie udało się załadować CustomizationField ID: {$existingFieldId} do usunięcia.");
                }
            } else {
                $this->logToFile("[LengthPrice] manageLengthPriceCustomization - Brak pola is_lengthprice do usunięcia dla Produktu ID: {$productId}.");
            }
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

        $id_product = (int)$params['id_product'];
        $product = new Product($id_product); // Można by pobrać z $params['product'] jeśli jest dostępne

        if (!Validate::isLoadedObject($product)) {
            return $this->trans('Product not found.', [], 'Modules.Lengthprice.Admin');
        }

        $this->context->smarty->assign([
            'lengthprice_enabled' => (bool)$product->lengthprice_enabled, // Dostęp dzięki override
            'id_product' => $product->id,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/admin/lengthprice_module_tab.tpl');
    }
}