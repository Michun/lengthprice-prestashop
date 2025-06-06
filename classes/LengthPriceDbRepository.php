<?php
// modules/lengthprice/classes/LengthPriceDbRepository.php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class LengthPriceDbRepository
 * Handles database queries for the LengthPrice module.
 */
class LengthPriceDbRepository
{
    /**
     * Checks if a column exists in a given table.
     *
     * @param string $tableName Table name without prefix
     * @param string $columnName Column name
     * @return bool
     */
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

    /**
     * Gets the SQL to add a column to a table.
     *
     * @param string $tableName Table name without prefix
     * @param string $columnName Column name
     * @param string $columnDefinition Column definition (e.g., 'VARCHAR(255) NOT NULL')
     * @return string
     */
    public static function getAddColumnSql(string $tableName, string $columnName, string $columnDefinition): string
    {
        return 'ALTER TABLE `' . _DB_PREFIX_ . bqSQL($tableName) . '` ADD COLUMN `' . bqSQL($columnName) . '` ' . $columnDefinition;
    }

    /**
     * Gets the SQL to drop a column from a table.
     *
     * @param string $tableName Table name without prefix
     * @param string $columnName Column name
     * @return string
     */
    public static function getDropColumnSql(string $tableName, string $columnName): string
    {
        return 'ALTER TABLE `' . _DB_PREFIX_ . bqSQL($tableName) . '` DROP COLUMN `' . bqSQL($columnName) . '`';
    }

    /**
     * Gets the ID of the customization field marked as 'is_lengthprice' for a given product.
     * Prioritizes finding by type and name first, then checks the flag.
     *
     * @param int $idProduct
     * @param int $idLang The language ID to check the field name against.
     * @return int|null Returns the ID of the customization field or null if not found.
     */
    // In /Users/michalnowacki/Projects/prestashop-dev/modules/lengthprice/classes/LengthPriceDbRepository.php
    public static function getLengthCustomizationFieldIdForProduct(int $idProduct, int $idLang): ?int
    {
        // Logowanie dla debugowania
        $debugLogFile = _PS_MODULE_DIR_ . 'lengthprice/debug.log';
        $logPrefix = '[LengthPriceDbRepository::getLengthCustomizationFieldIdForProduct] ';
        $logMessage = $logPrefix . "Attempting to find CF for Product ID: {$idProduct}, Lang ID: {$idLang}" . PHP_EOL;
        @file_put_contents($debugLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $logMessage, FILE_APPEND);

        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            $logMessage = $logPrefix . "Column 'is_lengthprice' does NOT exist in 'customization_field'. Returning null." . PHP_EOL;
            @file_put_contents($debugLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $logMessage, FILE_APPEND);
            return null;
        }

        $sql = new \DbQuery();
        $sql->select('cf.id_customization_field');
        $sql->from('customization_field', 'cf');
        $sql->where('cf.id_product = ' . (int)$idProduct);
        $sql->where('cf.is_lengthprice = 1');
        $sql->where('cf.is_deleted = 0');
        $sql->where('cf.type = ' . (int)\Product::CUSTOMIZE_TEXTFIELD);

        $queryString = $sql->build();
        $logMessage = $logPrefix . "Query to find CF by is_lengthprice flag: " . $queryString . PHP_EOL;
        @file_put_contents($debugLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $logMessage, FILE_APPEND);

        $fieldId = (int)\Db::getInstance()->getValue($queryString);

        if ($fieldId > 0) {
            $logMessage = $logPrefix . "Found CF ID: {$fieldId} by is_lengthprice flag." . PHP_EOL;
            @file_put_contents($debugLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $logMessage, FILE_APPEND);
            return $fieldId;
        }

        $logMessage = $logPrefix . "No CF found with is_lengthprice=1 for Product ID: {$idProduct}. Returning null." . PHP_EOL;
        @file_put_contents($debugLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $logMessage, FILE_APPEND);
        return null;
    }

    /**
     * Sets the is_lengthprice flag for a given customization field ID.
     *
     * @param int $idCustomizationField
     * @return bool
     */
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

    /**
     * Checks if the is_lengthprice flag is enabled for a given customization field ID.
     *
     * @param int $idCustomizationField
     * @return bool
     */
    public static function isCustomizationFieldLengthFlagEnabled(int $idCustomizationField): bool
    {
        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            return false;
        }
        $sql = new \DbQuery();
        $sql->select('is_lengthprice');
        $sql->from('customization_field');
        $sql->where('id_customization_field = ' . (int)$idCustomizationField);
        $result = \Db::getInstance()->getValue($sql->build()); // POPRAWKA: Dodano ->build()
        return (bool)$result;
    }

    /**
     * Saves the is_enabled flag for a product in the module's settings table.
     * Inserts or updates the record.
     *
     * @param int $idProduct
     * @param bool $isEnabled
     * @return bool
     */
    public static function saveProductLengthPriceFlag(int $idProduct, bool $isEnabled): bool
    {
        $data = [
            'id_product' => $idProduct,
            'is_enabled' => (int)$isEnabled,
        ];

        $existing = \Db::getInstance()->getRow('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'lengthprice_product_settings` WHERE `id_product` = ' . (int)$idProduct);

        if ($existing) {
            return \Db::getInstance()->update(
                'lengthprice_product_settings', // Table name without prefix
                $data,
                '`id_product` = ' . (int)$idProduct // WHERE condition
            );
        } else {
            if ($isEnabled) {
                return \Db::getInstance()->insert('lengthprice_product_settings', $data);
            }
            return true;
        }
    }

    /**
     * Gets the is_enabled flag for a product from the module's settings table.
     *
     * @param int $idProduct
     * @return bool
     */
    public static function isLengthPriceEnabledForProduct(int $idProduct): bool
    {
        $sql = new \DbQuery();
        $sql->select('`is_enabled`');
        $sql->from('lengthprice_product_settings');
        $sql->where('`id_product` = ' . (int)$idProduct);

        $result = \Db::getInstance()->getValue($sql->build());
        return (bool)$result;
    }

    /**
     * Deletes the record for a product from the module's settings table.
     *
     * @param int $idProduct
     * @return bool
     */
    public static function deleteProductLengthPriceFlag(int $idProduct): bool
    {
        return \Db::getInstance()->delete('lengthprice_product_settings', '`id_product` = ' . (int)$idProduct);
    }
}