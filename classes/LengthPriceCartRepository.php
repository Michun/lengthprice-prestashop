<?php
// /modules/lengthprice/classes/LengthPriceCartRepository.php

if (!defined('_PS_VERSION_')) {
    exit;
}

class LengthPriceCartRepository
{
    private Db $db;
    private string $dbPrefix;
    private array $languages;
    private \LengthPrice $module;

    public function __construct(\LengthPrice $moduleInstance, Db $dbInstance, string $databasePrefix, array $languages)
    {
        $this->module = $moduleInstance;
        $this->db = $dbInstance;
        $this->dbPrefix = $databasePrefix;
        $this->languages = $languages;
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

    public function addCustomizationForLength(int $idCart, int $idProduct, int $idProductAttribute, string $lengthValue, int $idShop, int $idModule): int|false
    {
        $idCustomizationField = LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct($idProduct, (int)Context::getContext()->language->id);

        if (!$idCustomizationField) {
            return false;
        }

        $customization = new Customization();
        $customization->id_cart = $idCart;
        $customization->id_product = $idProduct;
        $customization->id_product_attribute = $idProductAttribute;
        $customization->quantity = 1;
        $customization->id_address_delivery = (int)Context::getContext()->cart->id_address_delivery;
        $customization->in_cart = 1;
        $customization->quantity_refunded = 0;
        $customization->quantity_returned = 0;

        if (!$customization->add()) {
            return false;
        }
        $newIdCustomization = (int)$customization->id;

        $structuredData = ['length' => $lengthValue];

        $dataToInsert = [
            'id_customization' => $newIdCustomization,
            'type'             => 1,
            'index'            => $idCustomizationField,
            'value'            => pSQL($lengthValue),
            'id_module'        => 0,
            'lengthprice_data' => pSQL(json_encode($structuredData))
        ];

        $insertResult = $this->db->insert('customized_data', $dataToInsert);

        if (!$insertResult) {
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
            return false;
        }

        return (bool)$this->db->update(
            'customized_data',
            ['lengthprice_data' => pSQL($jsonData)],
            '`id_customization` = ' . (int)$idCustomization . ' AND `index` = ' . (int)$idCustomizationField . ' AND `type` = 1',
            1
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
        $query->where('cd.type = 1');

        $jsonData = $this->db->getValue($query);

        if ($jsonData) {
            $data = json_decode($jsonData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        return null;
    }
}