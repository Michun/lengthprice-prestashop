<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Schema
{
    private function getInstallSql(): string
    {
        return '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'lengthprice_product_settings` (
              `id_product` int(10) UNSIGNED NOT NULL,
              `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
    }

    private function getUninstallSql(): string
    {
        return 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'lengthprice_product_settings`;';
    }

    public function installSchema(): bool
    {
        $sql = $this->getInstallSql();
        try {
            $result = \Db::getInstance()->execute($sql);
            if (!$result) {
                $this->logToFile('[Schema] Schema installation failed. DB Error: ' . \Db::getInstance()->getMsgError());
            }
            return $result;
        } catch (\Exception $e) {
            $this->logToFile('[Schema] Schema Install Exception: ' . $e->getMessage());
            return false;
        }
    }

    public function uninstallSchema(): bool
    {
        $sql = $this->getUninstallSql();
        try {
            $result = \Db::getInstance()->execute($sql);
            if (!$result) {
                $this->logToFile('[Schema] Schema uninstallation failed. DB Error: ' . \Db::getInstance()->getMsgError());
            }
            return $result;
        } catch (\Exception $e) {
            $this->logToFile('[Schema] Schema Uninstall Exception: ' . $e->getMessage());
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