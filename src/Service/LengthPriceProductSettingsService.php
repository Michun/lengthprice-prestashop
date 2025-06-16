<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Service;

use CustomizationField;
use Db;
use Language;
use Product;
use PrestaShop\Module\LengthPrice\Repository\LengthPriceDbRepository;
use Validate;

if (!defined('_PS_VERSION_')) {
    exit;
}

class LengthPriceProductSettingsService
{
    private $module;

    public function __construct(\LengthPrice $module)
    {
        $this->module = $module;
    }

    public function handleProductSettingsChange(int $productId, bool $isEnabled): bool
    {
        if (!$productId) {
            $this->module->logToFile('[LengthPriceProductSettingsService] handleProductSettingsChange - Invalid product ID provided.');
            return false;
        }

        if (!LengthPriceDbRepository::saveProductLengthPriceFlag($productId, $isEnabled)) {
            $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Failed to save lengthprice_enabled flag for Product ID {$productId} in module table. DB Error: " . Db::getInstance()->getMsgError());
            return false;
        }

        $languages = Language::getLanguages(false);
        $existingFieldId = null;

        foreach ($languages as $lang) {
            $fieldId = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($productId, (int)$lang['id_lang']);
            if ($fieldId !== null) {
                $existingFieldId = $fieldId;
                break;
            }
        }

        if ($isEnabled) {
            if (!$existingFieldId) {
                $cf = new CustomizationField();
                $cf->id_product = $productId;
                $cf->type = Product::CUSTOMIZE_TEXTFIELD;
                $cf->required = 1;

                foreach ($languages as $lang) {
                    $cf->name[$lang['id_lang']] = $this->module->l('Length (mm)');
                }

                if ($cf->add(true, false)) {
                    if (!LengthPriceDbRepository::setCustomizationFieldLengthFlag((int)$cf->id)) {
                        $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Nie udało się ustawić is_lengthprice=1 dla CF ID: {$cf->id}. Błąd DB: " . Db::getInstance()->getMsgError());
                        return false;
                    }
                } else {
                    $validation_messages = $cf->getValidationMessages();
                    $errors_string = is_array($validation_messages) ? implode(', ', $validation_messages) : '';
                    $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - \$cf->add() nie powiodło się dla Produktu ID: {$productId}. Błędy: {$errors_string}");
                    return false;
                }
            }
        } else {
            if ($existingFieldId) {
                $cf_to_delete = new CustomizationField((int)$existingFieldId);
                if (Validate::isLoadedObject($cf_to_delete)) {
                    if (!$cf_to_delete->delete(false)) {
                        $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Nie udało się usunąć CustomizationField ID: {$existingFieldId}.");
                        return false;
                    }
                } else {
                    $this->module->logToFile("[LengthPriceProductSettingsService] BŁĄD: handleProductSettingsChange - Nie udało się załadować CustomizationField ID: {$existingFieldId} do usunięcia.");
                    return false;
                }
            }
        }
        return true;
    }
}