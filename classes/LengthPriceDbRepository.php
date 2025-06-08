<?php
// modules/lengthprice/classes/LengthPriceDbRepository.php

if (!defined('_PS_VERSION_')) {
    exit;
}

class LengthPriceDbRepository
{
    public static function columnExists(string $tableName, string $columnName): bool
    {
        $db = \Db::getInstance();
        $prefix = _DB_PREFIX_;
        $databaseName = _DB_NAME_;

        $sql = 'SELECT COUNT(*)
                FROM `INFORMATION_SCHEMA`.`COLUMNS`
                WHERE `TABLE_SCHEMA` = \'' . pSQL($databaseName) . '\'
                  AND `TABLE_NAME` = \'' . pSQL($prefix . $tableName) . '\'
                  AND `COLUMN_NAME` = \'' . pSQL($columnName) . '\'';

        return (bool)$db->getValue($sql);
    }

    public static function getAddColumnSql(string $tableName, string $columnName, string $columnDefinition): string
    {
        return 'ALTER TABLE `' . _DB_PREFIX_ . bqSQL($tableName) . '` ADD COLUMN `' . bqSQL($columnName) . '` ' . $columnDefinition;
    }

    public static function getDropColumnSql(string $tableName, string $columnName): string
    {
        return 'ALTER TABLE `' . _DB_PREFIX_ . bqSQL($tableName) . '` DROP COLUMN `' . bqSQL($columnName) . '`';
    }

    public static function getLengthCustomizationFieldIdForProduct(int $idProduct, int $idLang): ?int
    {
        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            return null;
        }

        $sql = new \DbQuery();
        $sql->select('cf.id_customization_field');
        $sql->from('customization_field', 'cf');
        $sql->where('cf.id_product = ' . (int)$idProduct);
        $sql->where('cf.is_lengthprice = 1');
        $sql->where('cf.is_deleted = 0');
        $sql->where('cf.type = ' . (int)\Product::CUSTOMIZE_TEXTFIELD);

        $fieldId = (int)\Db::getInstance()->getValue($sql->build());

        if ($fieldId > 0) {
            return $fieldId;
        }
        return null;
    }

    public static function setCustomizationFieldLengthFlag(int $idCustomizationField): bool
    {
        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            return false;
        }
        return \Db::getInstance()->update(
            'customization_field',
            ['is_lengthprice' => 1],
            'id_customization_field = ' . (int)$idCustomizationField
        );
    }

    public static function isCustomizationFieldLengthFlagEnabled(int $idCustomizationField): bool
    {
        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            return false;
        }
        $sql = new \DbQuery();
        $sql->select('is_lengthprice');
        $sql->from('customization_field');
        $sql->where('id_customization_field = ' . (int)$idCustomizationField);
        $result = \Db::getInstance()->getValue($sql->build());
        return (bool)$result;
    }

    public static function saveProductLengthPriceFlag(int $idProduct, bool $isEnabled): bool
    {
        $data = [
            'id_product' => $idProduct,
            'is_enabled' => (int)$isEnabled,
        ];

        $existing = \Db::getInstance()->getRow('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'lengthprice_product_settings` WHERE `id_product` = ' . (int)$idProduct);

        if ($existing) {
            return \Db::getInstance()->update(
                'lengthprice_product_settings',
                $data,
                '`id_product` = ' . (int)$idProduct
            );
        } else {
            if ($isEnabled) {
                return \Db::getInstance()->insert('lengthprice_product_settings', $data);
            }
            return true;
        }
    }

    public static function isLengthPriceEnabledForProduct(int $idProduct): bool
    {
        $sql = new \DbQuery();
        $sql->select('`is_enabled`');
        $sql->from('lengthprice_product_settings');
        $sql->where('`id_product` = ' . (int)$idProduct);

        $result = \Db::getInstance()->getValue($sql->build());
        return (bool)$result;
    }

    public static function deleteProductLengthPriceFlag(int $idProduct): bool
    {
        return \Db::getInstance()->delete('lengthprice_product_settings', '`id_product` = ' . (int)$idProduct);
    }
}