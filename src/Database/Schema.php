<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Database;

use Db;
use PrestaShopDatabaseException;

class Schema
{
    private Db $db;
    private string $dbPrefix;
    private string $engine;
    /** @var callable|null */
    private $logger;

    /**
     * Constructor.
     *
     * @param Db $dbInstance PrestaShop Db instance.
     * @param callable|null $logger Optional logger callback (e.g., $this->module->logToFile).
     */
    public function __construct(Db $dbInstance, ?callable $logger = null)
    {
        $this->db = $dbInstance;
        $this->dbPrefix = _DB_PREFIX_;
        $this->engine = _MYSQL_ENGINE_;
        $this->logger = $logger;
    }

    /**
     * Logs a message using the provided logger.
     *
     * @param string $message The message to log.
     */
    private function log(string $message): void
    {
        if ($this->logger) {
            call_user_func($this->logger, '[LengthPrice Schema] ' . $message);
        }
    }

    /**
     * Installs the module-specific database schema.
     * Called from Installer::install().
     *
     * @return bool True on success, false on failure.
     */
    public function installSchema(): bool
    {
        $this->log('Starting schema installation for module-specific tables.');
        $queries = $this->getInstallationQueries();
        $success = true;

        foreach ($queries as $description => $query) {
            if (empty(trim($query)) || strpos(trim($query), '--') === 0) {
                $this->log("Skipping query: $description (empty or comment)");
                continue;
            }
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
            $this->log('Module-specific schema installation completed successfully.');
        } else {
            $this->log('Module-specific schema installation failed or completed with errors.');
        }
        return $success;
    }

    /**
     * Uninstalls the module-specific database schema.
     * Called from Installer::uninstall().
     *
     * @return bool True on success, false on failure.
     */
    public function uninstallSchema(): bool
    {
        $this->log('Starting schema uninstallation for module-specific tables.');
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
            $this->log('Module-specific schema uninstallation completed successfully.');
        } else {
            $this->log('Module-specific schema uninstallation failed or completed with errors.');
        }
        return $success;
    }

    /**
     * Gets the SQL queries for installing module-specific tables.
     * Called from installSchema().
     *
     * @return array An array of SQL queries.
     */
    private function getInstallationQueries(): array
    {
        return [
            'Create lengthprice_product_settings table' => "CREATE TABLE IF NOT EXISTS `{$this->dbPrefix}lengthprice_product_settings` (
                `id_product` INT(10) UNSIGNED NOT NULL,
                `is_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `id_customization_field` INT(10) UNSIGNED DEFAULT NULL,
                `base_price_type` VARCHAR(20) NOT NULL DEFAULT 'per_milimeter', /* Default value was per_meter, changed to per_milimeter as per previous context */
                `base_length_mm` DECIMAL(10, 2) NOT NULL DEFAULT 10.00, /* Default value was 1000.00, changed to 10.00 as per previous context */
                PRIMARY KEY (`id_product`)
            ) ENGINE={$this->engine} DEFAULT CHARSET=utf8;",
        ];
    }

    /**
     * Gets the SQL queries for uninstalling module-specific tables.
     * Called from uninstallSchema().
     *
     * @return array An array of SQL queries.
     */
    private function getUninstallationQueries(): array
    {
        return [
            'Drop lengthprice_product_settings table' => "DROP TABLE IF EXISTS `{$this->dbPrefix}lengthprice_product_settings`",
        ];
    }
}