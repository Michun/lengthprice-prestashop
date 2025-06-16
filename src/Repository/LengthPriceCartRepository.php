<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Repository;

use Context;
use Customization;
use Db;
use DbQuery;
use Language;
use LengthPrice;
use Product;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class LengthPriceCartRepository
{
    private Db $db;
    private string $dbPrefix;
    private LengthPrice $module;

    public function __construct(
        LengthPrice $moduleInstance,
        Db $dbInstance
    ) {
        $this->module = $moduleInstance;
        $this->db = $dbInstance;
        $this->dbPrefix = _DB_PREFIX_;
    }

    public function getCustomizationDisplayText(array $lengthData): string
    {
        if (isset($lengthData['length'])) {
            $label = $this->module->l('Length', 'lengthpricecartrepository');
            $unit = $this->module->l('mm', 'lengthpricecartrepository');
            return $label . ': ' . $lengthData['length'] . ' ' . $unit;
        }
        return '';
    }

    public function addCustomizationForLength(
        int $idCart,
        int $idProduct,
        int $idProductAttribute,
        string $lengthValue,
        int $idShop,
        float $finalCalculatedPriceExclTax
    ): int|false {
        $idCustomizationField = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct(
            $idProduct,
            (int)Context::getContext()->language->id
        );

        if (!$idCustomizationField) {
            $this->module->logToFile('[LP CartRepo] addCustomizationForLength: No customization field ID found for product ' . $idProduct);
            return false;
        }

        $customization = new Customization();
        $customization->id_cart = $idCart;
        $customization->id_product = $idProduct;
        $customization->id_product_attribute = $idProductAttribute;
        $customization->quantity = 0;
        $customization->id_address_delivery = (int)Context::getContext()->cart->id_address_delivery;
        $customization->in_cart = 1;
        $customization->quantity_refunded = 0;
        $customization->quantity_returned = 0;

        if (!$customization->add()) {
            $this->module->logToFile('[LP CartRepo] addCustomizationForLength: Failed to add Customization object. Product ID: ' . $idProduct . ' Cart ID: ' . $idCart);
            return false;
        }
        $newIdCustomization = (int)$customization->id;

        $structuredData = [
            'length' => $lengthValue,
            'calculated_price_excl_tax_at_add' => $finalCalculatedPriceExclTax
        ];

        $dataToInsert = [
            'id_customization' => $newIdCustomization,
            'type'             => Product::CUSTOMIZE_TEXTFIELD,
            'index'            => $idCustomizationField,
            'value'            => pSQL($lengthValue),
            'id_module'        => (int)$this->module->id,
            'price'            => (float)$finalCalculatedPriceExclTax,
            'lengthprice_data' => pSQL(json_encode($structuredData))
        ];

        $insertResult = $this->db->insert('customized_data', $dataToInsert);

        if (!$insertResult) {
            $this->module->logToFile('[LP CartRepo] addCustomizationForLength: Failed to insert into customized_data. ID Customization: ' . $newIdCustomization . ' DB Error: ' . $this->db->getMsgError());
            $customization->delete();
            return false;
        }

        return $newIdCustomization;
    }

    public function updateStructuredCustomizationData(int $idCustomization, int $idCustomizationField, array $lengthData): bool
    {
        if (!isset($lengthData['length'])) {
            return false;
        }

        $jsonData = json_encode($lengthData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->module->logToFile('[LP CartRepo] updateStructuredCustomizationData: JSON encode error for ID Customization: ' . $idCustomization);
            return false;
        }

        return (bool)$this->db->update(
            'customized_data',
            ['lengthprice_data' => pSQL($jsonData)],
            '`id_customization` = ' . (int)$idCustomization . ' AND `index` = ' . (int)$idCustomizationField . ' AND `type` = ' . (int)Product::CUSTOMIZE_TEXTFIELD,
            1 // Limit
        );
    }

    public function getStructuredCartData(int $idCustomization, int $idCustomizationField): ?array
    {
        $query = new DbQuery();
        $query->select('cd.lengthprice_data');
        $query->from('customized_data', 'cd');
        $query->where('cd.id_customization = ' . (int)$idCustomization);
        $query->where('cd.index = ' . (int)$idCustomizationField);
        $query->where('cd.lengthprice_data IS NOT NULL');
        $query->where('cd.type = ' . (int)Product::CUSTOMIZE_TEXTFIELD);

        $jsonData = $this->db->getValue($query);

        if ($jsonData) {
            $data = json_decode($jsonData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
            $this->module->logToFile('[LP CartRepo] getStructuredCartData: JSON decode error for ID Customization: ' . $idCustomization . '. JSON: ' . $jsonData);
        }
        return null;
    }
}