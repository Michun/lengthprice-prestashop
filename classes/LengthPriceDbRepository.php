<?php
// modules/lengthprice/classes/LengthPriceDbRepository.php

// Dodaj przestrzeń nazw, jeśli jej używasz w module
// namespace TwojaNazwaVendor\LengthPrice\Classes;

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
     * Checks if a column exists in a table.
     *
     * @param string $tableName Table name without prefix.
     * @param string $columnName Column name.
     * @return bool
     */
    public static function columnExists(string $tableName, string $columnName): bool
    {
        // Użyj bezpośredniego zapytania SQL dla INFORMATION_SCHEMA, aby uniknąć problemów z DbQuery i prefixami
        $sql = "SELECT COUNT(*) 
                FROM `INFORMATION_SCHEMA`.`COLUMNS` 
                WHERE `TABLE_SCHEMA` = '" . pSQL(_DB_NAME_) . "' 
                  AND `TABLE_NAME` = '" . _DB_PREFIX_ . pSQL($tableName) . "' 
                  AND `COLUMN_NAME` = '" . pSQL($columnName) . "'";

        return (bool)Db::getInstance()->getValue($sql);
    }

    /**
     * Gets the SQL for adding a column to a table.
     *
     * @param string $tableName Table name without prefix.
     * @param string $columnName Column name.
     * @param string $columnDefinition SQL definition of the column (e.g., "TINYINT(1) NOT NULL DEFAULT 0").
     * @return string
     */
    public static function getAddColumnSql(string $tableName, string $columnName, string $columnDefinition): string
    {
        return "ALTER TABLE `" . _DB_PREFIX_ . bqSQL($tableName) . "` ADD `" . bqSQL($columnName) . "` " . $columnDefinition;
    }

    /**
     * Gets the SQL for dropping a column from a table.
     *
     * @param string $tableName Table name without prefix.
     * @param string $columnName Column name.
     * @return string
     */
    public static function getDropColumnSql(string $tableName, string $columnName): string
    {
        return "ALTER TABLE `" . _DB_PREFIX_ . bqSQL($tableName) . "` DROP COLUMN `" . bqSQL($columnName) . "`";
    }

    /**
     * Retrieves existing customization fields related to length price for a product.
     *
     * @param int $idProduct
     * @param int $idLang
     * @return array|false|mysqli_result|PDOStatement|resource|null
     */
    public static function getExistingLengthCustomizationFields(int $idProduct, int $idLang)
    {
        $sql = new DbQuery();
        $sql->select('cf.id_customization_field, cfl.name');
        $sql->from('customization_field', 'cf');
        $sql->leftJoin('customization_field_lang', 'cfl', 'cf.id_customization_field = cfl.id_customization_field AND cfl.id_lang = ' . $idLang);
        $sql->where('cf.id_product = ' . $idProduct);
        $sql->where('cf.is_lengthprice = 1');
        // Nie ma potrzeby `cfl.id_lang` w `WHERE`, jeśli jest już w `JOIN`

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Gets the ID of the customization field used for length price for a specific product.
     * It assumes the one with MAX ID is the relevant one if multiple exist.
     *
     * @param int $idProduct
     * @return int
     */
    public static function getLengthCustomizationFieldId(int $idProduct): int
    {
        $sql = new DbQuery();
        $sql->select('MAX(cf.id_customization_field)');
        $sql->from('customization_field', 'cf');
        $sql->where('cf.id_product = ' . $idProduct);
        $sql->where('cf.is_lengthprice = 1');
        return (int)Db::getInstance()->getValue($sql);
    }

    /**
     * Sets the is_lengthprice flag for a given customization field.
     *
     * @param int $idCustomizationField
     * @return bool
     */
    public static function setCustomizationFieldLengthFlag(int $idCustomizationField): bool
    {
        return Db::getInstance()->update(
            'customization_field', // Table name without prefix
            ['is_lengthprice' => 1], // Data to update
            'id_customization_field = ' . $idCustomizationField // WHERE condition
        );
    }

    /**
     * Retrieves a customization field record for verification.
     *
     * @param int $idCustomizationField
     * @return array|object|null False if no result, null on error (Db::getRow specific)
     */
    public static function getCustomizationFieldForVerification(int $idCustomizationField)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('customization_field');
        $sql->where('id_customization_field = ' . $idCustomizationField);
        return Db::getInstance()->getRow($sql);
    }
}