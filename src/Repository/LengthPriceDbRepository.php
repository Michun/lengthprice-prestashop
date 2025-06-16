<?php
// modules/lengthprice/classes/LengthPriceDbRepository.php

namespace PrestaShop\Module\LengthPrice\Repository;
use Db;
use DbQuery;

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

    public static function addColumnIfNotExists(string $tableName, string $columnName, string $columnDefinition): bool
    {
        if (!self::columnExists($tableName, $columnName)) {
            $sql = self::getAddColumnSql($tableName, $columnName, $columnDefinition);
            $result = Db::getInstance()->execute($sql);
            if (!$result) {
                // Można tutaj dodać logowanie błędu, jeśli repozytorium ma dostęp do mechanizmu logowania
                // lub rzucić wyjątek
            }
            return (bool)$result;
        }
        return true;
    }

    /**
     * Drops a column from a table if it exists.
     *
     * @param string $tableName The name of the table (without prefix).
     * @param string $columnName The name of the column to drop.
     * @param callable|null $logger Optional callable for logging messages.
     * @param string $logPrefix Optional prefix for log messages.
     * @return bool True on success (or if column didn't exist), false on failure.
     */
    public static function dropColumnIfExists(string $tableName, string $columnName, callable $logger = null, string $logPrefix = ''): bool
    {
        if (self::columnExists($tableName, $columnName)) {
            $sql = self::getDropColumnSql($tableName, $columnName); // Assumes getDropColumnSql adds _DB_PREFIX_
            try {
                $result = Db::getInstance()->execute($sql);
                if (!$result && $logger) {
                    $logger($logPrefix . "ALTER TABLE DROP COLUMN FAILED - DB Error: " . Db::getInstance()->getMsgError());
                }
                return (bool)$result;
            } catch (\PrestaShopDatabaseException $e) {
                if ($logger) {
                    $logger($logPrefix . 'Exception while dropping column: ' . $e->getMessage());
                }
                return false;
            }
        }
        return true; // Column doesn't exist, so it's "successfully" absent
    }

    /**
     * Marks all customization fields previously identified as 'lengthprice' as deleted
     * and then drops the 'is_lengthprice' column from the customization_field table.
     *
     * @param callable|null $logger Optional callable for logging messages, e.g., [$moduleInstance, 'logToFile']
     * @return bool True if both operations (marking as deleted and dropping column) were successful or not needed, false otherwise.
     */
    public static function markAndDeleteLengthPriceCustomizationFlag(callable $logger = null): bool
    {
        $logPrefix = '[LengthPriceDbRepository] markAndDeleteLengthPriceCustomizationFlag: ';
        $columnExists = self::columnExists('customization_field', 'is_lengthprice');
        $markedOk = true;

        if ($columnExists) {
            $sqlMarkAsDeleted = 'UPDATE `' . _DB_PREFIX_ . 'customization_field`
                             SET `is_deleted` = 1
                             WHERE `is_lengthprice` = 1';
            try {
                if (!Db::getInstance()->execute($sqlMarkAsDeleted)) {
                    if ($logger) {
                        $logger($logPrefix . "Failed to mark lengthprice customization fields as deleted. DB Error: " . Db::getInstance()->getMsgError());
                    }
                    $markedOk = false;
                }
            } catch (\PrestaShopDatabaseException $e) {
                if ($logger) {
                    $logger($logPrefix . 'Exception while marking lengthprice customization fields as deleted: ' . $e->getMessage());
                }
                return false; // Critical failure
            }
        } elseif ($logger) {
            $logger($logPrefix . "'is_lengthprice' column does not exist, skipping marking fields as deleted.");
        }
        return $markedOk;
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

    public static function getCustomizedDataFieldsByIdCustomization(int $id_customization): array
    {
        if ($id_customization <= 0) {
            return [];
        }
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('customized_data');
        $sql->where('id_customization = ' . $id_customization);
        $result = Db::getInstance()->executeS($sql);
        return $result ?: []; // Zwróć pustą tablicę, jeśli nie ma wyników lub wystąpił błąd
    }
}