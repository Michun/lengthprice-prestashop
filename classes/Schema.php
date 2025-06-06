<?php
// modules/lengthprice/classes/Schema.php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class Schema
 * Handles database schema installation and uninstallation for the LengthPrice module.
 */
class Schema
{
    /**
     * Returns the SQL for creating the module's settings table.
     *
     * @return string
     */
    private function getInstallSql(): string
    {
        // Tabela do przechowywania flagi lengthprice_enabled dla każdego produktu
        // id_product jako klucz główny zapewnia unikalność wpisu dla każdego produktu
        return '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'lengthprice_product_settings` (
              `id_product` int(10) UNSIGNED NOT NULL,
              `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
    }

    /**
     * Returns the SQL for dropping the module's settings table.
     *
     * @return string
     */
    private function getUninstallSql(): string
    {
        return 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'lengthprice_product_settings`;';
    }

    /**
     * Installs the module's database schema.
     *
     * @return bool
     */
    public function installSchema(): bool
    {
        $this->logToFile('[Schema] Attempting to install schema.'); // Dodane logowanie
        $sql = $this->getInstallSql();
        $this->logToFile('[Schema] SQL for install: ' . $sql); // Dodane logowanie
        try {
            $result = \Db::getInstance()->execute($sql);
            if ($result) {
                $this->logToFile('[Schema] Schema installed successfully.'); // Dodane logowanie
            } else {
                $this->logToFile('[Schema] Schema installation failed. DB Error: ' . \Db::getInstance()->getMsgError()); // Dodane logowanie
            }
            return $result;
        } catch (\Exception $e) {
            $this->logToFile('[Schema] Schema Install Exception: ' . $e->getMessage()); // Dodane logowanie
            // PrestaShopLogger::addLog('LengthPrice Schema Install Error: ' . $e->getMessage(), 3, null, null, null, true);
            return false;
        }
    }

    /**
     * Uninstalls the module's database schema.
     *
     * @return bool
     */
    public function uninstallSchema(): bool
    {
        $this->logToFile('[Schema] Attempting to uninstall schema.'); // Dodane logowanie
        $sql = $this->getUninstallSql();
        $this->logToFile('[Schema] SQL for uninstall: ' . $sql); // Dodane logowanie
        try {
            $result = \Db::getInstance()->execute($sql);
            if ($result) {
                $this->logToFile('[Schema] Schema uninstalled successfully.'); // Dodane logowanie
            } else {
                $this->logToFile('[Schema] Schema uninstallation failed. DB Error: ' . \Db::getInstance()->getMsgError()); // Dodane logowanie
            }
            return $result;
        } catch (\Exception $e) {
            $this->logToFile('[Schema] Schema Uninstall Exception: ' . $e->getMessage()); // Dodane logowanie
            return false;
        }
    }

    private function logToFile(string $message): void
    {
        $logfile = _PS_MODULE_DIR_ . 'lengthprice/debug-schema.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logfile, $entry, FILE_APPEND);
    }
}