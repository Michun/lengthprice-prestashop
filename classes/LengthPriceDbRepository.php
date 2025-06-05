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

        // Użyj bezpośredniego zapytania SQL dla INFORMATION_SCHEMA
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
     *
     * @param int $idProduct
     * @return int|null Returns the ID of the customization field or null if not found.
     */
    public static function getLengthCustomizationFieldIdForProduct(int $idProduct): ?int
    {
        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            return null;
        }

        $sql = new \DbQuery();
        $sql->select('cf.id_customization_field');
        $sql->from('customization_field', 'cf');
        $sql->where('cf.id_product = ' . (int)$idProduct);
        $sql->where('cf.is_lengthprice = 1');
        $sql->where('cf.is_deleted = 0'); // Upewnij się, że pole nie jest "miękko" usunięte

        $result = \Db::getInstance()->getValue($sql->build()); // POPRAWKA: Dodano ->build()
        return $result ? (int)$result : null;
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

        // Sprawdź, czy rekord dla tego produktu już istnieje
        $existing = \Db::getInstance()->getRow('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'lengthprice_product_settings` WHERE `id_product` = ' . (int)$idProduct);

        if ($existing) {
            // Rekord istnieje, zaktualizuj
            return \Db::getInstance()->update(
                'lengthprice_product_settings', // Table name without prefix
                $data,
                '`id_product` = ' . (int)$idProduct // WHERE condition
            );
        } else {
            // Rekord nie istnieje, wstaw nowy
            // Wstaw tylko jeśli is_enabled jest true, aby uniknąć pustych wpisów dla produktów z wyłączoną opcją
            if ($isEnabled) {
                return \Db::getInstance()->insert('lengthprice_product_settings', $data);
            }
            // Jeśli is_enabled jest false i rekord nie istnieje, nic nie robimy
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

        $result = \Db::getInstance()->getValue($sql->build()); // POPRAWKA: Dodano ->build()
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