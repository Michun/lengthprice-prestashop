<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Database;

use Db;
use LengthPrice;
use PrestaShopDatabaseException;
use PrestaShop\Module\LengthPrice\Repository\LengthPriceDbRepository;

class Schema
{
    private Db $db;
    private string $dbPrefix;
    private string $engine;
    /** @var callable|null */
    private $logger;

    public function __construct(Db $dbInstance, ?callable $logger = null)
    {
        $this->db = $dbInstance;
        $this->dbPrefix = _DB_PREFIX_;
        $this->engine = _MYSQL_ENGINE_;
        $this->logger = $logger;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            call_user_func($this->logger, '[LengthPrice Schema] ' . $message);
        }
    }

    public function installSchema(): bool
    {
        $this->log('Starting schema installation.');
        $queries = $this->getInstallationQueries();
        $success = true;

        foreach ($queries as $description => $query) {
            $this->log("Executing query: $description");
            try {
                if (!$this->db->execute($query)) {
                    $this->log("Failed to execute query ($description): " . $this->db->getMsgError());
                    $success = false;
                } else {
                    $this->log("Successfully executed query: $description");
                }
            } catch (PrestaShopDatabaseException $e) {
                $this->log("Database exception during query ($description): " . $e->getMessage());
                $success = false;
            }
        }

        if ($success) {
            $this->log('Schema installation completed successfully.');
        } else {
            $this->log('Schema installation failed or completed with errors.');
        }
        return $success;
    }

    public function uninstallSchema(): bool
    {
        $this->log('Starting schema uninstallation.');
        $queries = $this->getUninstallationQueries();
        $success = true;

        foreach ($queries as $description => $query) {
            $this->log("Executing query: $description");
            try {
                if (!$this->db->execute($query)) {
                    $this->log("Failed to execute query ($description): " . $this->db->getMsgError());
                    $success = false;
                } else {
                    $this->log("Successfully executed query: $description");
                }
            } catch (PrestaShopDatabaseException $e) {
                $this->log("Database exception during query ($description): " . $e->getMessage());
                $success = false;
            }
        }

        if ($success) {
            $this->log('Schema uninstallation completed successfully.');
        } else {
            $this->log('Schema uninstallation failed or completed with errors.');
        }
        return $success;
    }

    private function getInstallationQueries(): array
    {
        return [
            'Create lengthprice_product_settings table' => "CREATE TABLE IF NOT EXISTS `{$this->dbPrefix}lengthprice_product_settings` (
                `id_product` INT(10) UNSIGNED NOT NULL,
                `is_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `id_customization_field` INT(10) UNSIGNED DEFAULT NULL,
                `base_price_type` VARCHAR(20) NOT NULL DEFAULT 'per_milimeter',
                `base_length_mm` DECIMAL(10, 2) NOT NULL DEFAULT 10.00,
                PRIMARY KEY (`id_product`)
            ) ENGINE={$this->engine} DEFAULT CHARSET=utf8;",

            'Add is_lengthprice column to customization_field' => $this->getAddColumnQuery(
                'customization_field',
                'is_lengthprice',
                'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
            ),
            'Add lengthprice_data column to customized_data' => $this->getAddColumnQuery(
                'customized_data',
                'lengthprice_data',
                'TEXT DEFAULT NULL'
            ),
        ];
    }

    private function getUninstallationQueries(): array
    {
        $queries = [
            'Drop lengthprice_product_settings table' => "DROP TABLE IF EXISTS `{$this->dbPrefix}lengthprice_product_settings`",
        ];

        return $queries;
    }

    private function getAddColumnQuery(string $tableName, string $columnName, string $columnDefinition): string
    {
         if (!LengthPriceDbRepository::columnExists($tableName, $columnName)) {
             return "ALTER TABLE `{$this->dbPrefix}{$tableName}` ADD COLUMN `{$columnName}` {$columnDefinition}";
         }
         $this->log("Column {$columnName} already exists in {$this->dbPrefix}{$tableName}. Skipping ADD COLUMN.");
         return "-- Column {$columnName} already exists in {$tableName} or check skipped";
    }
}