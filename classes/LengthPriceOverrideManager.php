<?php
// modules/lengthprice/classes/LengthPriceOverrideManager.php

if (!defined('_PS_VERSION_')) {
    exit;
}

class LengthPriceOverrideManager
{
    /**
     * Zwraca oczekiwaną zawartość pliku override dla Product.php.
     * @return string
     */
    private static function getProductOverrideContent()
    {
        return <<<PHP
<?php
class Product extends ProductCore
{
    /**
     * @var bool Whether price by length is enabled for this product
     */
    public \$lengthprice_enabled;

    public function __construct(\$id_product = null, \$full = false, \$id_lang = null, \$id_shop = null, \Context \$context = null)
    {
        // Add new field to the object model definition
        if (class_exists('ProductCore')) {
            self::\$definition['fields']['lengthprice_enabled'] = [
                'type' => self::TYPE_BOOL,
                'validate' => 'isBool',
                'db_type' => 'TINYINT(1)',
                'db_default' => '0'
            ];
        }
        parent::__construct(\$id_product, \$full, \$id_lang, \$id_shop, \$context);
    }
}
PHP;
    }

    /**
     * Tworzy plik override dla klasy Product.
     * @param Module $moduleInstance Instancja modułu wywołującego.
     * @return bool True jeśli sukces lub plik już poprawnie istnieje, false w przypadku błędu.
     */
    public static function createProductOverride(Module $moduleInstance)
    {
        $overrideDir = _PS_OVERRIDE_DIR_ . 'classes/';
        $overrideFile = $overrideDir . 'Product.php';
        $expectedContent = self::getProductOverrideContent();

        if (!is_dir($overrideDir)) {
            // Używamy @ aby stłumić błąd mkdir, jeśli katalog już istnieje (rasa warunków)
            // Sprawdzamy wynik mkdir zaraz po wywołaniu
            if (!@mkdir($overrideDir, 0775, true) && !is_dir($overrideDir)) { // Sprawdź ponownie is_dir na wypadek wyścigu
                $moduleInstance->_errors[] = $moduleInstance->l('Failed to create override directory: ') . $overrideDir;
                return false;
            }
        }

        if (!file_exists($overrideFile)) {
            if (file_put_contents($overrideFile, $expectedContent) === false) {
                $moduleInstance->_errors[] = $moduleInstance->l('Failed to create Product.php override file.');
                return false;
            }
            // Próba regeneracji indeksu klas
            if (class_exists('PrestaShopAutoload')) {
                PrestaShopAutoload::getInstance()->generateIndex();
            }
            return true;
        } else {
            $currentContent = Tools::file_get_contents($overrideFile);
            if (strpos($currentContent, 'public $lengthprice_enabled;') !== false &&
                strpos($currentContent, "self::\$definition['fields']['lengthprice_enabled']") !== false) {
                return true; // Modyfikacje już istnieją
            } else {
                $moduleInstance->_errors[] = $moduleInstance->l('Product.php override file exists but does not contain the required modifications for LengthPrice module. Please merge manually or remove the existing override if it is not needed by other modules. Installation aborted.');
                return false; // Konflikt, przerwij instalację
            }
        }
    }

    /**
     * Usuwa plik override dla Product, jeśli został stworzony przez ten moduł i niezmodyfikowany.
     * @param Module $moduleInstance Instancja modułu wywołującego.
     * @return bool True jeśli sukces lub nie było potrzeby działania.
     */
    public static function removeProductOverride(Module $moduleInstance)
    {
        $overrideFile = _PS_OVERRIDE_DIR_ . 'classes/Product.php';
        $expectedContent = self::getProductOverrideContent();

        if (file_exists($overrideFile)) {
            $currentContent = Tools::file_get_contents($overrideFile);
            if (trim($currentContent) === trim($expectedContent)) {
                if (!@unlink($overrideFile)) {
                    // Logowanie błędu, nazwa modułu pobierana z instancji
                    PrestaShopLogger::addLog(
                        $moduleInstance->name . ' Module: Failed to delete Product.php override file during uninstall. Please remove it manually: ' . $overrideFile,
                        2, null, null, null, true, $moduleInstance->id
                    );
                } else {
                    if (class_exists('PrestaShopAutoload')) {
                        PrestaShopAutoload::getInstance()->generateIndex();
                    }
                }
            } else {
                PrestaShopLogger::addLog(
                    $moduleInstance->name . ' Module: Product.php override file was modified. Module did not delete it during uninstall. Please manually remove any ' . $moduleInstance->name . ' specific code from: ' . $overrideFile,
                    2, null, null, null, true, $moduleInstance->id
                );
            }
        }
        return true; // Zawsze true, chyba że krytyczny błąd w tej funkcji
    }
}