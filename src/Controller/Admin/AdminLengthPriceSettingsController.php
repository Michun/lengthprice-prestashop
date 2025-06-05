<?php
// modules/lengthprice/controllers/admin/AdminLengthPriceSettingsController.php

namespace PrestaShop\Module\LengthPrice\Controller\Admin;

use PrestaShop\Module\LengthPrice\Service\LengthPriceProductSettingsService;
use Tools;
use Validate; // Dodaj Validate, jeśli używasz go w kontrolerze (choć w serwisie jest lepsze miejsce)
use Db; // Dodaj Db, jeśli używasz go w kontrolerze (choć w serwisie jest lepsze miejsce)


if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminLengthPriceSettingsController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    /**
     * Handle AJAX request to save product settings.
     */
    public function ajaxProcessSaveProductSettings(): void
    {
        $productId = (int)Tools::getValue('id_product');
        $isEnabled = (bool)Tools::getValue('lengthprice_enabled');

        // Użyj $this->module do logowania, bo $this->module jest dostępne w ModuleAdminController
        $this->module->logToFile('[AdminLengthPriceSettingsController] ajaxProcessSaveProductSettings triggered.');
        $this->module->logToFile("[AdminLengthPriceSettingsController] Received Product ID: {$productId}, isEnabled: " . ($isEnabled ? 'true' : 'false'));

        if (!$productId) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid product ID.'),
            ]));
        }

        // Pobierz serwis z kontenera
        // W ModuleAdminController możesz uzyskać dostęp do kontenera przez $this->getContainer()
        /** @var LengthPriceProductSettingsService $settingsService */
        $settingsService = $this->getContainer()->get('prestashop.module.lengthprice.service.product_settings');

        $success = $settingsService->handleProductSettingsChange($productId, $isEnabled);

        if ($success) {
            $this->ajaxDie(json_encode([
                'success' => true,
                'message' => $this->module->l('Settings updated successfully.'),
            ]));
        } else {
            // Komunikat błędu jest już logowany w serwisie
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $this->module->l('Failed to update settings.'),
            ]));
        }
    }
}