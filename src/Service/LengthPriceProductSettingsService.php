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
    private \LengthPrice $module;

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

        $currentSettings = LengthPriceDbRepository::getProductSettings($productId);
        $idCustomizationFieldForDb = $currentSettings['id_customization_field'] ?? null;

        $languages = Language::getLanguages(false);
        $existingCustomizationFieldId = null;

        if ($idCustomizationFieldForDb) {
            $cfTest = new CustomizationField($idCustomizationFieldForDb);
            if (Validate::isLoadedObject($cfTest) && $cfTest->id_product == $productId) {
                if (LengthPriceDbRepository::isCustomizationFieldMarkedAsLengthPrice($idCustomizationFieldForDb)) {
                    $existingCustomizationFieldId = $idCustomizationFieldForDb;
                }
            }
        }


        if ($isEnabled) {
            if (!$existingCustomizationFieldId) {
                $cf = new CustomizationField();
                $cf->id_product = $productId;
                $cf->type = Product::CUSTOMIZE_TEXTFIELD;
                $cf->required = 1;
                $cf->is_module = 1;

                foreach ($languages as $lang) {
                    $cf->name[$lang['id_lang']] = $this->module->l('Length (mm)');
                }

                if ($cf->add(true, false)) {
                    $idCustomizationFieldForDb = (int)$cf->id;
                    if (!LengthPriceDbRepository::setCustomizationFieldAsLengthPrice((int)$cf->id, [$this->module, 'logToFile'])) {
                        $this->module->logToFile("[LengthPriceProductSettingsService] ERROR: handleProductSettingsChange - Failed to mark CF ID: {$cf->id} as is_lengthprice.");
                        $cf->delete();
                        return false;
                    }
                } else {
                    $validation_messages = $cf->getValidationMessages();
                    $errors_string = is_array($validation_messages) ? implode(', ', $validation_messages) : '';
                    $this->module->logToFile("[LengthPriceProductSettingsService] ERROR: handleProductSettingsChange - \$cf->add() failed for Product ID: {$productId}. Errors: {$errors_string}");
                    return false;
                }
            }
        } else {
            if ($existingCustomizationFieldId) {
                $cf_to_delete = new CustomizationField((int)$existingCustomizationFieldId);
                if (Validate::isLoadedObject($cf_to_delete)) {
                    if (!$cf_to_delete->delete(false)) {
                        $this->module->logToFile("[LengthPriceProductSettingsService] ERROR: handleProductSettingsChange - Failed to delete CustomizationField ID: {$existingCustomizationFieldId}.");
                    }
                }
                $idCustomizationFieldForDb = null; // Disassociate
            }
        }

        $basePriceType = $currentSettings['base_price_type'] ?? 'per_milimeter';
        $baseLengthMm = $currentSettings['base_length_mm'] ?? 10.00;

        if (!LengthPriceDbRepository::saveProductSettings(
            $productId,
            $isEnabled,
            $idCustomizationFieldForDb,
            $basePriceType,
            $baseLengthMm,
            [$this->module, 'logToFile']
        )) {
            $this->module->logToFile("[LengthPriceProductSettingsService] ERROR: handleProductSettingsChange - Failed to save product settings for Product ID {$productId}. DB Error: " . Db::getInstance()->getMsgError());
            return false;
        }

        return true;
    }
}