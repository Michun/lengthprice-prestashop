<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Repository;

use Db;
use DbQuery;
use PrestaShopDatabaseException;

class LengthPriceDbRepository
{
    /**
     * Checks if a column exists in a given table.
     *
     * @param string $tableName The table name (without prefix).
     * @param string $columnName The column name.
     * @return bool True if the column exists, false otherwise.
     */
    public static function columnExists(string $tableName, string $columnName): bool
    {
        $db = Db::getInstance();
        $query = 'SHOW COLUMNS FROM `' . _DB_PREFIX_ . pSQL($tableName) . '` LIKE \'' . pSQL($columnName) . '\'';
        try {
            $result = $db->executeS($query);
            return is_array($result) && !empty($result);
        } catch (PrestaShopDatabaseException $e) {
            error_log('[LengthPriceDbRepository] Error checking column existence for table ' . $tableName . ', column ' . $columnName . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensures a column exists in a table, adding it if it doesn't.
     *
     * @param string $tableName The table name (without prefix).
     * @param string $columnName The column name.
     * @param string $columnDefinition The SQL definition of the column.
     * @param callable|null $logger Optional logger callback for errors.
     * @return bool True on success (column exists or was added), false on failure.
     */
    public static function ensureColumnExists(string $tableName, string $columnName, string $columnDefinition, ?callable $logger = null): bool
    {
        $db = Db::getInstance();
        $logPrefix = '[LengthPriceDbRepository] ensureColumnExists: ';

        if (self::columnExists($tableName, $columnName)) {
            // Success log for already existing column removed for brevity
            return true;
        }

        $query = "ALTER TABLE `" . _DB_PREFIX_ . pSQL($tableName) . "` ADD COLUMN `" . pSQL($columnName) . "` " . $columnDefinition;

        try {
            $result = $db->execute($query);
            if (!$result && $logger) {
                call_user_func($logger, $logPrefix . "Failed to add column '{$columnName}' to '{$tableName}'. DB Error: " . $db->getMsgError() . " Query: " . $query);
            }
            return (bool)$result;
        } catch (PrestaShopDatabaseException $e) {
            if ($logger) {
                call_user_func($logger, $logPrefix . "Exception while adding column '{$columnName}' to '{$tableName}': " . $e->getMessage() . " Query: " . $query);
            }
            return false;
        }
    }


    /**
     * Marks length price customization fields as deleted in the customization_field table.
     *
     * @param callable|null $logger Optional logger callback for errors.
     * @return bool True on success, false on failure.
     */
    public static function markLengthPriceCustomizationFieldsAsDeleted(?callable $logger = null): bool
    {
        $db = Db::getInstance();
        $logPrefix = '[LengthPriceDbRepository] markLengthPriceCustomizationFieldsAsDeleted: ';

        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            if ($logger) {
                call_user_func($logger, $logPrefix . "'is_lengthprice' column does not exist in 'customization_field'. Skipping marking fields as deleted.");
            }
            return true;
        }

        $table = 'customization_field';
        $data = ['is_deleted' => 1];
        $where = '`is_lengthprice` = 1 AND `is_deleted` = 0';
        $limit = 0;

        try {
            $result = $db->update($table, $data, $where, $limit);

            if ($result === false && $logger) {
                call_user_func($logger, $logPrefix . "Failed to mark fields as deleted using Db::update. DB Error: " . $db->getMsgError());
            }
            // Success log removed for brevity
            return $result !== false;
        } catch (PrestaShopDatabaseException $e) {
            if ($logger) {
                call_user_func($logger, $logPrefix . 'Exception while marking fields as deleted using Db::update: ' . $e->getMessage());
            }
            return false;
        }
    }


    /**
     * Deletes product settings from the module's specific table.
     *
     * @param int $productId The ID of the product.
     * @param callable|null $logger Optional logger callback for errors.
     * @return bool True on success, false on failure.
     */
    public static function deleteProductSettings(int $productId, ?callable $logger = null): bool
    {
        $db = Db::getInstance();
        $logPrefix = '[LengthPriceDbRepository] deleteProductSettings: ';
        $tableName = 'lengthprice_product_settings';
        $where = '`id_product` = ' . (int)$productId;

        try {
            $result = $db->delete($tableName, $where);
            if (!$result && $logger) {
                call_user_func($logger, $logPrefix . "Failed to delete settings for product {$productId}. DB Error: " . $db->getMsgError());
            }
            // Success log removed for brevity
            return (bool)$result;
        } catch (PrestaShopDatabaseException $e) {
            if ($logger) {
                call_user_func($logger, $logPrefix . "Exception while deleting settings for product {$productId}: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Checks if length price is enabled for a specific product.
     *
     * @param int $productId The ID of the product.
     * @return bool True if enabled, false otherwise.
     */
    public static function isLengthPriceEnabledForProduct(int $productId): bool
    {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('`is_enabled`');
        $query->from('lengthprice_product_settings');
        $query->where('`id_product` = ' . (int)$productId);

        try {
            $isEnabled = $db->getValue($query);
            return (bool)$isEnabled;
        } catch (PrestaShopDatabaseException $e) {
            error_log('[LengthPriceDbRepository] Error checking if length price is enabled for product ' . $productId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets the customization field ID for length price for a product.
     * Note: idLang parameter was removed as it wasn't used in the query logic for selecting the field itself,
     * only for potentially displaying its name, which is not this method's responsibility.
     *
     * @param int $productId The ID of the product.
     * @return int|null The customization field ID, or null if not found.
     */
    public static function getLengthCustomizationFieldIdForProduct(int $productId): ?int
    {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('ps.id_customization_field');
        $query->from('lengthprice_product_settings', 'ps');
        $query->innerJoin('customization_field', 'cf', 'cf.id_customization_field = ps.id_customization_field');
        $query->where('ps.id_product = ' . (int)$productId);
        $query->where('cf.is_lengthprice = 1');
        $query->where('cf.is_deleted = 0');

        try {
            $fieldId = $db->getValue($query);
            return $fieldId ? (int)$fieldId : null;
        } catch (PrestaShopDatabaseException $e) {
            error_log('[LengthPriceDbRepository] Error getting customization field ID for product ' . $productId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Gets customized data fields for a given customization ID.
     *
     * @param int $idCustomization The customization ID.
     * @return array An array of customized data fields, or empty array if none found.
     */
    public static function getCustomizedDataFieldsByIdCustomization(int $idCustomization): array
    {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('*');
        $query->from('customized_data');
        $query->where('id_customization = ' . (int)$idCustomization);

        try {
            $results = $db->executeS($query);
            return is_array($results) ? $results : [];
        } catch (PrestaShopDatabaseException $e) {
            error_log('[LengthPriceDbRepository] Error getting customized data for customization ID ' . $idCustomization . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Gets product settings from the module's specific table.
     *
     * @param int $productId The ID of the product.
     * @return array|null An array of settings, or null if not found.
     */
    public static function getProductSettings(int $productId): ?array
    {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('*');
        $query->from('lengthprice_product_settings');
        $query->where('`id_product` = ' . (int)$productId);

        try {
            $settings = $db->getRow($query);
            return is_array($settings) ? $settings : null;
        } catch (PrestaShopDatabaseException $e) {
            error_log('[LengthPriceDbRepository] Error getting product settings for product ' . $productId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Saves product settings to the module's specific table.
     *
     * @param int $productId The ID of the product.
     * @param bool $isEnabled Whether length price is enabled.
     * @param int|null $idCustomizationField The customization field ID.
     * @param string $basePriceType The base price type.
     * @param float $baseLengthMm The base length in mm.
     * @param callable|null $logger Optional logger callback for errors.
     * @return bool True on success, false on failure.
     */
    public static function saveProductSettings(int $productId, bool $isEnabled, ?int $idCustomizationField, string $basePriceType, float $baseLengthMm, ?callable $logger = null): bool
    {
        $db = Db::getInstance();
        $logPrefix = '[LengthPriceDbRepository] saveProductSettings: ';

        $data = [
            'is_enabled' => (int)$isEnabled,
            'id_customization_field' => ($idCustomizationField === null) ? null : (int)$idCustomizationField,
            'base_price_type' => pSQL($basePriceType),
            'base_length_mm' => (float)$baseLengthMm,
        ];

        $existingSettings = self::getProductSettings($productId);

        try {
            if ($existingSettings !== null) {
                $result = $db->update('lengthprice_product_settings', $data, '`id_product` = ' . (int)$productId, 0, true);
                if (!$result && $logger) { // Log only on failure
                    call_user_func($logger, $logPrefix . "Failed to update settings for product {$productId}. DB Error: " . $db->getMsgError());
                }
                return (bool)$result;
            } else {
                $data['id_product'] = $productId;
                $result = $db->insert('lengthprice_product_settings', $data, true);
                if (!$result && $logger) { // Log only on failure
                    call_user_func($logger, $logPrefix . "Failed to insert settings for product {$productId}. DB Error: " . $db->getMsgError());
                }
                return (bool)$result;
            }
        } catch (PrestaShopDatabaseException $e) {
            if ($logger) {
                call_user_func($logger, $logPrefix . "Exception while saving settings for product {$productId}: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Marks a given customization field as being managed by the lengthprice module.
     *
     * @param int $customizationFieldId The ID of the customization field.
     * @param callable|null $logger Optional logger callback for errors.
     * @return bool True on success, false on failure.
     */
    public static function setCustomizationFieldAsLengthPrice(int $customizationFieldId, ?callable $logger = null): bool
    {
        $db = Db::getInstance();
        $logPrefix = '[LengthPriceDbRepository] setCustomizationFieldAsLengthPrice: ';

        // Assuming Installer guarantees the column exists.
        // If not, the update query will fail, which is acceptable as it indicates a setup issue.
        // The defensive check and attempt to add column is removed.
        // if (!self::columnExists('customization_field', 'is_lengthprice')) { ... }

        $table = 'customization_field';
        $data = ['is_lengthprice' => 1];
        $where = 'id_customization_field = ' . (int)$customizationFieldId;

        try {
            $result = $db->update($table, $data, $where);
            if ($result === false && $logger) {
                call_user_func($logger, $logPrefix . "Failed to set is_lengthprice=1 for CF ID: {$customizationFieldId}. DB Error: " . $db->getMsgError());
            }
            // Success log removed for brevity
            return $result !== false;
        } catch (PrestaShopDatabaseException $e) {
            if ($logger) {
                call_user_func($logger, $logPrefix . "Exception while setting is_lengthprice=1 for CF ID: {$customizationFieldId}: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Checks if a given customization field is marked as a lengthprice field.
     *
     * @param int $customizationFieldId
     * @return bool
     */
    public static function isCustomizationFieldMarkedAsLengthPrice(int $customizationFieldId): bool
    {
        $db = Db::getInstance();
        if (!self::columnExists('customization_field', 'is_lengthprice')) {
            // If the column for marking doesn't even exist, then the field cannot be marked.
            error_log('[LengthPriceDbRepository] isCustomizationFieldMarkedAsLengthPrice: "is_lengthprice" column does not exist in customization_field table.');
            return false;
        }
        $query = new DbQuery();
        $query->select('`is_lengthprice`');
        $query->from('customization_field');
        $query->where('`id_customization_field` = ' . (int)$customizationFieldId);

        try {
            return (bool)$db->getValue($query);
        } catch (PrestaShopDatabaseException $e) {
            error_log('[LengthPriceDbRepository] Error checking if CF ID ' . $customizationFieldId . ' is marked as length price: ' . $e->getMessage());
            return false;
        }
    }
}
