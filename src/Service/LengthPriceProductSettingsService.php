<?php
// modules/lengthprice/src/Service/LengthPriceProductSettingsService.php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Service;

use CustomizationField;
use Language;
use Product;
use LengthPriceDbRepository; // Upewnij się, że ta klasa jest dostępna (wymagana przez LengthPriceDbRepository)
use Validate;
use Db; // Potrzebne do logowania błędów DB

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Service to handle LengthPrice module settings for products.
 */
class LengthPriceProductSettingsService
{
    private $module; // Przechowujemy referencję do modułu, aby użyć metod takich jak l() i logToFile()

    public function __construct(\LengthPrice $module)
    {
        $this->module = $module;
    }

    /**
     * Handles saving the module's enabled flag and managing the customization field.
     *
     * @param int $productId
     * @param bool $isEnabled
     * @return bool True if the operation was successful, false otherwise.
     */
    public function handleProductSettingsChange(int $productId, bool $isEnabled): bool
    {
        if (!$productId) {
            $this->module->logToFile('[LengthPriceProductSettingsService] handleProductSettingsChange - Invalid product ID provided.');
            return false;
        }

        $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange dla Produktu ID: {$productId}. Nowy stan: " . ($isEnabled ? 'WŁĄCZONA' : 'WYŁĄCZONA'));

        // 1. Zapisz flagę w naszej dedykowanej tabeli
        if (!LengthPriceDbRepository::saveProductLengthPriceFlag($productId, $isEnabled)) {
            $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Failed to save lengthprice_enabled flag for Product ID {$productId} in module table. DB Error: " . Db::getInstance()->getMsgError());
            return false; // Zakończ, jeśli zapis flagi się nie powiódł
        }
        $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Successfully saved lengthprice_enabled flag ({$isEnabled}) for Product ID {$productId} in module table.");


        // 2. Zarządzaj polem personalizacji
        // Determine the language(s) to check for the customization field name
        $languages = Language::getLanguages(false);
        $existingFieldId = null;

        // Try to find the existing field in any language
        foreach ($languages as $lang) {
            // Pass the language ID to the repository method
            $fieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId, (int)$lang['id_lang']);
            if ($fieldId !== null) {
                $existingFieldId = $fieldId;
                $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Found existing CF ID {$existingFieldId} for product {$productId} in language {$lang['id_lang']}.");
                break; // Found it, no need to check other languages
            }
        }

        if ($isEnabled) { // Opcja włączona
            $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Aktywacja LengthPrice dla produktu ID: {$productId}.");

            if (!$existingFieldId) {
                $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - CustomizationField z flagą is_lengthprice=1 NIE istnieje dla Produktu ID: {$productId}. Tworzenie nowego.");
                $cf = new CustomizationField();
                $cf->id_product = $productId;
                $cf->type = Product::CUSTOMIZE_TEXTFIELD;
                $cf->required = 1;

                foreach ($languages as $lang) {
                    $cf->name[$lang['id_lang']] = $this->module->l('Length (mm)'); // Użyj metody l() z modułu
                }

                if ($cf->add(true, false)) {
                    $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - CustomizationField dodany, ID: {$cf->id}");
                    // After adding, set our custom flag
                    if (LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$cf->id)) { // This method sets is_lengthprice to 1
                        $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Pomyślnie ustawiono is_lengthprice=1 dla CF ID: {$cf->id}");
                        return true; // Sukces
                    } else {
                        $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Nie udało się ustawić is_lengthprice=1 dla CF ID: {$cf->id}. Błąd DB: " . Db::getInstance()->getMsgError());
                        // Mimo błędu, pole zostało dodane, ale bez flagi. Zwracamy false, bo operacja nie powiodła się w pełni.
                        return false;
                    }
                } else {
                    $validation_messages = $cf->getValidationMessages();
                    $errors_string = is_array($validation_messages) ? implode(', ', $validation_messages) : '';
                    $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - \$cf->add() nie powiodło się dla Produktu ID: {$productId}. Błędy: {$errors_string}");
                    return false; // Sukces
                }
            } else {
                $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - CustomizationField z flagą is_lengthprice=1 JUŻ istnieje dla Produktu ID: {$productId} (ID: {$existingFieldId}). Nie tworzę nowego.");
                // Jeśli pole już istnieje i zostało znalezione przez getLengthCustomizationFieldIdForProduct (co oznacza, że ma is_lengthprice=1), to operacja "włączenia" zakończyła się sukcesem.
                return true;
            }
        } else { // Opcja wyłączona
            $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Deaktywacja LengthPrice dla produktu ID: {$productId}. Sprawdzam, czy trzeba usunąć istniejące pole.");

            // We already searched for the existing field above
            if ($existingFieldId) {
                $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Znaleziono istniejące pole (ID: {$existingFieldId}) do usunięcia dla Produktu ID: {$productId}.");
                $cf_to_delete = new CustomizationField((int)$existingFieldId);
                if (Validate::isLoadedObject($cf_to_delete)) {
                    // Delete the CustomizationField object (sets is_deleted = 1)
                    if ($cf_to_delete->delete(false)) {
                        $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Pomyślnie usunięto CustomizationField ID: {$existingFieldId} (is_deleted set to 1).");
                        // Note: delete(false) sets is_deleted=1, it does NOT remove the row.
                        // It also does NOT change our custom is_lengthprice flag.
                        // The field will still exist in the DB but be marked as deleted by PrestaShop.
                        // Our getLengthCustomizationFieldIdForProduct checks for is_deleted = 0 AND is_lengthprice = 1, so it won't find it again.
                        return true; // Sukces
                    } else {
                        $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Nie udało się usunąć CustomizationField ID: {$existingFieldId}.");
                        return false; // Niepowodzenie
                    }
                } else {
                    $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Nie udało się załadować CustomizationField ID: {$existingFieldId} do usunięcia.");
                    return false; // Niepowodzenie
                }
            } else {
                $this->module->logToFile("[LengthPriceProductSettingsService] handleProductSettingsChange - Brak pola is_lengthprice do usunięcia dla Produktu ID: {$productId}.");
                // Jeśli opcja była wyłączona i nie znaleziono pola do usunięcia, to jest to oczekiwane zachowanie. Operacja zakończona sukcesem.
                return true;
            }
        }
    }
}