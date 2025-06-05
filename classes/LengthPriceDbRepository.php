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
     * Gets the ID of the customization field used for length price for a specific product.
     *
     * @param int $idProduct
     * @return int|null
     */
    public static function getLengthCustomizationFieldIdForProduct(int $idProduct): ?int
    {
        $sql = new DbQuery();
        $sql->select('cf.`id_customization_field`');
        $sql->from('customization_field', 'cf');
        $sql->where('cf.`id_product` = ' . $idProduct);
        $sql->where('cf.`is_lengthprice` = 1');
        // Można dodać limit 1, jeśli zakładasz tylko jedno takie pole na produkt
        $sql->limit(0, 1);
        $result = Db::getInstance()->getValue($sql);
        return $result ? (int)$result : null;
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
            'id_customization_field = ' . (int)$idCustomizationField // WHERE condition
        );
    }

    /**
     * Checks if the is_lengthprice flag is enabled for a given customization field.
     *
     * @param int $idCustomizationField
     * @return bool
     */
    public static function isCustomizationFieldLengthFlagEnabled(int $idCustomizationField): bool
    {
        $sql = new DbQuery();
        $sql->select('cf.`is_lengthprice`');
        $sql->from('customization_field', 'cf');
        $sql->where('cf.`id_customization_field` = ' . (int)$idCustomizationField);
        $result = Db::getInstance()->getValue($sql);
        return (bool)$result;
    }

}