<?php

namespace PrestaShop\Module\LengthPrice\Controller\Admin;

use PrestaShop\Module\LengthPrice\Service\LengthPriceProductSettingsService;
use Tools;
use Validate;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminLengthPriceSettingsController extends FrameworkBundleAdminController
{
    private \LengthPrice $moduleInstance;
    private LengthPriceProductSettingsService $settingsService;
    private TranslatorInterface $translator;

    public function __construct(
        \LengthPrice $moduleInstance,
        LengthPriceProductSettingsService $settingsService,
        TranslatorInterface $translator
    ) {
        $this->moduleInstance = $moduleInstance;
        $this->settingsService = $settingsService;
        $this->translator = $translator;

        $this->moduleInstance->logToFile('[AdminLengthPriceSettingsController] CONSTRUCTOR CALLED.');
    }


    public function saveProductSettingsAction(Request $request): JsonResponse
    {
        $this->moduleInstance->logToFile('[AdminLengthPriceSettingsController] saveProductSettingsAction CALLED.');

        $productId = (int)$request->request->get('id_product');
        $isEnabled = (bool)$request->request->get('lengthprice_enabled');

        $this->moduleInstance->logToFile("[AdminLengthPriceSettingsController] Received Product ID: {$productId}, isEnabled: " . ($isEnabled ? 'true' : 'false'));

        if (!$productId) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('Invalid product ID.', [], 'Modules.Lengthprice.Admin'),
            ]);
        }

        $success = $this->settingsService->handleProductSettingsChange($productId, $isEnabled);

        if ($success) {
            return new JsonResponse([
                'success' => true,
                'message' => $this->translator->trans('Settings updated successfully.', [], 'Modules.Lengthprice.Admin'),
            ]);
        } else {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('Failed to update settings.', [], 'Modules.Lengthprice.Admin'),
            ]);
        }
    }

}
    