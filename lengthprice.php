<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/LengthPriceOverrideManager.php';
require_once dirname(__FILE__) . '/classes/LengthPriceDbRepository.php';

use PrestaShopBundle\Form\Admin\Type\SwitchType;


class LengthPrice extends Module
{
    // Zmieniamy strukturę, aby śledzić przetwarzanie w różnych hookach
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
            // Nowe hooki "Before" do ustawiania flagi na obiekcie przed zapisem
            && $this->registerHook('actionObjectProductUpdateBefore')
            && $this->registerHook('actionObjectProductAddBefore')
            // Hooki "After" do zarządzania polem personalizacji (na podstawie wartości z POST)
            && $this->registerHook('actionObjectProductUpdateAfter')
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
        // Wycofujemy rejestrację nowych hooków
        $success = $success && $this->unregisterHook('actionObjectProductUpdateBefore');
        $success = $success && $this->unregisterHook('actionObjectProductAddBefore');
        // Wycofujemy rejestrację hooków "After"
        $success = $success && $this->unregisterHook('actionObjectProductUpdateAfter');
        $success = $success && $this->unregisterHook('actionObjectProductAddAfter');
        $success = $success && $this->unregisterHook('displayAdminProductsExtra');
        $success = $success && $this->removeProductField();
        $success = $success && $this->removeCustomizationFieldFlag();
        $success = $success && LengthPriceOverrideManager::removeProductOverride($this);

        return $success;
    }

    // Zmieniamy nazwę metody, aby lepiej odzwierciedlała jej rolę - zarządzanie polem personalizacji
    private function _manageCustomizationField(Product $productInstance): void
    {
        if (!$productInstance || !$productInstance->id) {
            $this->logToFile('[LengthPrice] _manageCustomizationField - Invalid product instance provided.');
            return;
        }

        $productId = (int)$productInstance->id;

        // Pobierz wartość flagi bezpośrednio z danych wysłanych w formularzu
        // Ta metoda nadal polega na Tools::getValue(), ponieważ hooki "After" są wywoływane
        // po głównym zapisie, a wartość na obiekcie Product może być już zaktualizowana
        // przez hook "Before" lub główny proces zapisu.
        $isLengthPriceEnabledSubmitted = (bool)Tools::getValue('lengthprice_enabled');

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
                // Jeśli pole już istnieje, a chcemy je aktywować, upewnijmy się, że flaga is_lengthprice jest ustawiona (choć powinna być)
                if ($existingFieldId && !LengthPriceDbRepository::isCustomizationFieldLengthFlagEnabled((int)$existingFieldId)) {
                    if (LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$existingFieldId)) {
                        $this->logToFile("[LengthPrice] _manageCustomizationField - Ustawiono is_lengthprice=1 dla istniejącego CF ID: {$existingFieldId}.");
                    } else {
                        $this->logToFile("[LengthPrice] BŁĄD: _manageCustomizationField - Nie udało się ustawić is_lengthprice=1 dla istniejącego CF ID: {$existingFieldId}.");
                    }
                }
            }
        } else {
            // Logika usuwania pola, jeśli $isLengthPriceEnabledSubmitted jest false
            $this->logToFile("[LengthPrice] _manageCustomizationField - Deaktywacja LengthPrice dla produktu ID: {$productId} na podstawie wartości z formularza. Sprawdzam, czy trzeba usunąć istniejące pole.");
            $existingFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId);
            if ($existingFieldId) {
                $this->logToFile("[LengthPrice] _manageCustomizationField - Znaleziono istniejące pole (ID: {$existingFieldId}) do usunięcia dla Produktu ID: {$productId}.");
                $cf_to_delete = new CustomizationField((int)$existingFieldId);
                if (Validate::isLoadedObject($cf_to_delete)) {
                    if ($cf_to_delete->delete()) {
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
            $this->logToFile("ALTER TABLE wynik: " . ($result ? 'OK' : 'BŁAD'));
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

    // Nowy hook wywoływany PRZED zapisem obiektu Product
    public function hookActionObjectProductUpdateBefore(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductUpdateBefore triggered.');
        if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $product = $params['object'];
            $productId = (int)$product->id;

            // Używamy statycznej flagi, aby upewnić się, że przetwarzamy dany produkt tylko raz na hook
            if (isset(self::$processedProductsInRequest[$productId]['before_update'])) {
                $this->logToFile("[LengthPrice] hookActionObjectProductUpdateBefore - Product ID: {$productId} already processed (before_update) in this request. Skipping.");
                return;
            }
            self::$processedProductsInRequest[$productId]['before_update'] = true;

            // Pobierz wartość flagi bezpośrednio z danych wysłanych w formularzu
            $isLengthPriceEnabledSubmitted = (bool)Tools::getValue('lengthprice_enabled');

            $this->logToFile("[LengthPrice] hookActionObjectProductUpdateBefore - Product ID: {$productId}. Value from POST: " . ($isLengthPriceEnabledSubmitted ? 'WŁĄCZONA' : 'WYŁĄCZONA'));
            $this->logToFile("[LengthPrice] hookActionObjectProductUpdateBefore - Product ID: {$productId}. Value on object BEFORE setting: " . ($product->lengthprice_enabled ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

            // Ustaw flagę na obiekcie Product PRZED głównym zapisem
            // Sprawdzamy, czy wartość z formularza różni się od wartości na obiekcie
            if ((bool)$product->lengthprice_enabled !== $isLengthPriceEnabledSubmitted) {
                $this->logToFile("[LengthPrice] hookActionObjectProductUpdateBefore - Setting lengthprice_enabled on object to: " . ($isLengthPriceEnabledSubmitted ? '1' : '0'));
                $this->logToFile("PRZED USTAWIENIEM OBIEKTU: {$product->lengthprice_enabled}");
                $product->lengthprice_enabled = $isLengthPriceEnabledSubmitted;
                $this->logToFile("PO USTAWIENIU OBIEKTU: {$product->lengthprice_enabled}");
                // NIE wywołujemy update() tutaj. Główny proces zapisu ObjectModel zrobi to za nas.
            } else {
                $this->logToFile("[LengthPrice] hookActionObjectProductUpdateBefore - Flag on object is already consistent with POST value. Not setting.");
            }

        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductUpdateBefore - Brak obiektu Product w parametrach.');
        }
    }

    // Nowy hook wywoływany PRZED zapisem obiektu Product (dla dodawania)
    public function hookActionObjectProductAddBefore(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductAddBefore triggered.');
        if (isset($params['object']) && $params['object'] instanceof Product) { // ID może nie być jeszcze ustawione dla dodawania
            $product = $params['object'];
            // ID może być 0 lub null tutaj, obsłuż to ostrożnie, jeśli potrzebne, ale ustawienie flagi powinno być w porządku

            // Pobierz wartość flagi bezpośrednio z danych wysłanych w formularzu
            $isLengthPriceEnabledSubmitted = (bool)Tools::getValue('lengthprice_enabled');

            $this->logToFile("[LengthPrice] hookActionObjectProductAddBefore - Product (ID: " . ($product->id ?? 'null') . "). Value from POST: " . ($isLengthPriceEnabledSubmitted ? 'WŁĄCZONA' : 'WYŁĄCZONA'));
            $this->logToFile("[LengthPrice] hookActionObjectProductAddBefore - Product (ID: " . ($product->id ?? 'null') . "). Value on object BEFORE setting: " . ($product->lengthprice_enabled ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

            // Ustaw flagę na obiekcie Product PRZED głównym zapisem
            // Sprawdzamy, czy wartość z formularza różni się od wartości na obiekcie
            if ((bool)$product->lengthprice_enabled !== $isLengthPriceEnabledSubmitted) {
                $this->logToFile("[LengthPrice] hookActionObjectProductAddBefore - Setting lengthprice_enabled on object to: " . ($isLengthPriceEnabledSubmitted ? '1' : '0'));
                $product->lengthprice_enabled = $isLengthPriceEnabledSubmitted;
                // NIE wywołujemy add() tutaj. Główny proces zapisu ObjectModel zrobi to za nas.
            } else {
                $this->logToFile("[LengthPrice] hookActionObjectProductAddBefore - Flag on object is already consistent with POST value. Not setting.");
            }

        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductAddBefore - Brak obiektu Product w parametrach.');
        }
    }


    // Hook wywoływany PO zapisie produktu (przez kontroler) - używamy go do zarządzania polem personalizacji
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductUpdateAfter triggered.');
        $product = null;

        if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $product = $params['object'];
            $this->logToFile('[LengthPrice] hookActionObjectProductUpdateAfter - Product object found in params. ID: ' . $product->id);
        } elseif (isset($params['id_product'])) {
            $id_product = (int)$params['id_product'];
            $this->logToFile('[LengthPrice] hookActionObjectProductUpdateAfter - id_product found in params: ' . $id_product);
            // Załaduj produkt z pełnymi danymi dla zarządzania polem personalizacji
            $product = new Product($id_product, true, $this->context->language->id, $this->context->shop->id);
            if (!Validate::isLoadedObject($product)) {
                $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductUpdateAfter - Nie udało się załadować produktu ID z params: ' . $id_product);
                return;
            }
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductUpdateAfter - Brak obiektu Product lub id_product w parametrach.');
            return;
        }

        $this->logToFile("NA OBIEKCIE PO update(): {$product->lengthprice_enabled}");

        if ($product && $product->id) {
            // Używamy statycznej flagi, aby upewnić się, że przetwarzamy dany produkt tylko raz na hook
            if (isset(self::$processedProductsInRequest[(int)$product->id]['after_update'])) {
                $this->logToFile("[LengthPrice] hookActionObjectProductUpdateAfter - Product ID: {$product->id} already processed (after_update) in this request. Skipping.");
                return;
            }
            self::$processedProductsInRequest[(int)$product->id]['after_update'] = true;

            $this->_manageCustomizationField($product); // Wywołujemy metodę zarządzającą polem personalizacji
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductUpdateAfter - Product object is not valid after attempting to load/retrieve.');
        }
    }

    // Hook wywoływany PO dodaniu produktu (przez ObjectModel) - używamy go do zarządzania polem personalizacji
    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter triggered.');
        if (isset($params['object']) && $params['object'] instanceof Product && $params['object']->id) {
            $product = $params['object'];
            $this->logToFile('[LengthPrice] hookActionObjectProductAddAfter - Product ID: ' . $product->id);

            // Używamy statycznej flagi, aby upewnić się, że przetwarzamy dany produkt tylko raz na hook
            if (isset(self::$processedProductsInRequest[(int)$product->id]['after_add'])) {
                $this->logToFile("[LengthPrice] hookActionObjectProductAddAfter - Product ID: {$product->id} already processed (after_add) in this request. Skipping.");
                return;
            }
            self::$processedProductsInRequest[(int)$product->id]['after_add'] = true;

            $this->_manageCustomizationField($product); // Wywołujemy metodę zarządzającą polem personalizacji
        } else {
            $this->logToFile('[LengthPrice] BŁĄD: hookActionObjectProductAddAfter - Brak obiektu Product w parametrach.');
        }
    }


    // hookHeader i hookDisplayProductPriceBlock pozostają bez zmian
    public function hookHeader(): void
    {
        $this->context->controller->addJS($this->_path . 'views/js/lengthprice.js');

        if ($this->context->controller->php_self === 'product') { // Dla strony produktu
            $idProduct = (int)Tools::getValue('id_product');
            if (!$idProduct && isset($this->context->controller->product) && $this->context->controller->product instanceof Product) {
                $idProduct = (int)$this->context->controller->product->id;
            }

            if ($idProduct > 0) {
                $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($idProduct);
                if ($customizationFieldId !== null) { // Sprawdź, czy ID zostało znalezione
                    Media::addJsDef(['lengthpriceCustomizationFieldId' => $customizationFieldId]);
                } else {
                    $this->logToFile("[LengthPrice] hookHeader - Nie znaleziono pola personalizacji LengthPrice dla produktu ID: {$idProduct}.");
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

        // Sprawdzamy flagę na obiekcie Product
        if (!$product->lengthprice_enabled) {
            return '';
        }

        $this->logToFile('MODUŁ lengthprice JEST WYWOŁANY DLA: ' . $product->id);

        $customizationFieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct((int)$product->id);

        if ($customizationFieldId === null) {
            $this->logToFile("[LengthPrice] hookDisplayProductPriceBlock - Nie znaleziono pola personalizacji LengthPrice dla produktu ID: {$product->id}. Nie wyświetlam bloku.");
            return ''; // Nie wyświetlaj bloku, jeśli pole nie istnieje
        }

        // Pobierz cenę jednostkową - upewnij się, że ta logika jest poprawna dla Twoich potrzeb
        // Product::getPriceStatic może nie być najlepszym miejscem do pobierania ceny "jednostkowej"
        // jeśli cena produktu jest ceną całkowitą. Może potrzebujesz dedykowanego pola w produkcie
        // lub innej logiki do przechowywania/obliczania ceny za jednostkę długości.
        $price_per_unit = Product::getPriceStatic($product->id, true, null, 6);


        $this->context->smarty->assign([
            'price_per_unit' => $price_per_unit,
            'customization_field_id' => $customizationFieldId,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/lengthprice.tpl');
    }

    // hookDisplayAdminProductsExtra pozostaje bez zmian
    public function hookDisplayAdminProductsExtra(array $params): string // Dodano typowanie
    {
        $this->logToFile('[LengthPrice] hookDisplayAdminProductsExtra.');

        $id_product = (int)$params['id_product'];
        // Ładujemy produkt z pełnymi danymi, aby mieć dostęp do flagi z override'u
        $product = new Product($id_product, true, $this->context->language->id, $this->context->shop->id);

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