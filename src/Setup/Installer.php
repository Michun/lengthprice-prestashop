<?php

declare(strict_types=1);

namespace PrestaShop\Module\LengthPrice\Setup;

use Db;
use Language;
use LengthPrice;
use PrestaShop\Module\LengthPrice\Database\Schema;
use PrestaShop\Module\LengthPrice\Repository\LengthPriceDbRepository;
use Tab;
use Configuration;

class Installer
{
    private LengthPrice $module;
    private Db $db;

    public function __construct(LengthPrice $module, Db $db)
    {
        $this->module = $module;
        $this->db = $db;
    }

    public function install(): bool
    {
        $schemaManager = new Schema($this->db, [$this->module, 'logToFile']);
        if (!$schemaManager->installSchema()) {
            $this->module->logToFile('Installer: Nie udało się zainstalować schematu bazy danych.');
            return false;
        }
        $this->module->logToFile('Installer: Schemat bazy danych zainstalowany.');

        if (!$this->installControllers()) {
            $this->module->logToFile('Installer: Nie udało się zainstalować kontrolerów administracyjnych.');
            return false;
        }
        $this->module->logToFile('Installer: Kontrolery administracyjne zainstalowane.');

        if (!$this->addCustomColumnsToCoreTables()) {
            $this->module->logToFile('Installer: Nie udało się dodać niestandardowych kolumn do tabel PrestaShop.');
            return false;
        }
        $this->module->logToFile('Installer: Niestandardowe kolumny dodane.');

        $this->module->logToFile('Installer: Instalacja zakończona pomyślnie.');
        return true;
    }

    public function uninstall(): bool
    {
        $success = true;

        if (!$this->uninstallControllers()) {
            $this->module->logToFile('Installer: Nie udało się odinstalować kontrolerów administracyjnych.');
            $success = false;
        } else {
            $this->module->logToFile('Installer: Kontrolery administracyjne odinstalowane.');
        }

        if (!$this->handleCustomColumnsOnUninstall()) {
            $this->module->logToFile('Installer: Wystąpił błąd podczas obsługi niestandardowych kolumn przy deinstalacji.');
            $success = false;
        } else {
            $this->module->logToFile('Installer: Niestandardowe kolumny obsłużone przy deinstalacji.');
        }

        $schemaManager = new Schema($this->db, [$this->module, 'logToFile']);
        if (!$schemaManager->uninstallSchema()) {
            $this->module->logToFile("Installer: Nie udało się odinstalować schematu bazy danych (własne tabele modułu).");
            $success = false;
        } else {
            $this->module->logToFile("Installer: Schemat bazy danych (własne tabele modułu) odinstalowany.");
        }

        $this->module->logToFile('Installer: Deinstalacja zakończona. Ogólny sukces: ' . ($success ? 'Tak' : 'Nie'));
        return $success;
    }

    private function installControllers(): bool
    {
        $tab = new Tab();
        $tab->class_name = 'AdminLengthPriceSettings';
        $tab->module = $this->module->name;
        $tab->id_parent = (int)Tab::getIdFromClassName('IMPROVE'); // Lepsze miejsce dla ustawień
        if (!$tab->id_parent) {
            $this->module->logToFile('Installer: Nie znaleziono nadrzędnej zakładki "IMPROVE", używam -1.');
            $tab->id_parent = -1; // Fallback
        }
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = $this->module->l('LengthPrice Product Settings', 'Installer');
        }

        try {
            $result = $tab->add();
            if (!$result) {
                $this->module->logToFile('Installer: Dodawanie zakładki Admin FAILED - DB Error: ' . $this->db->getMsgError());
            }
            return $result;
        } catch (\Exception $e) {
            $this->module->logToFile('Installer: Wyjątek podczas dodawania zakładki Admin: ' . $e->getMessage());
            return false;
        }
    }

    private function uninstallControllers(): bool
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminLengthPriceSettings');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            try {
                $result = $tab->delete();
                if (!$result) {
                    $this->module->logToFile('Installer: Usuwanie zakładki Admin FAILED - DB Error: ' . $this->db->getMsgError());
                }
                return $result;
            } catch (\Exception $e) {
                $this->module->logToFile('Installer: Wyjątek podczas usuwania zakładki Admin: ' . $e->getMessage());
                return false;
            }
        }
        $this->module->logToFile('Installer: Zakładka AdminLengthPriceSettings nie znaleziona, pomijam usuwanie.');
        return true;
    }

    private function addCustomColumnsToCoreTables(): bool
    {
        $success = true;

        if (!LengthPriceDbRepository::ensureColumnExists(
            'customization_field',
            'is_lengthprice',
            'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            [$this->module, 'logToFile']
        )) {
            $this->module->logToFile("Installer: Nie udało się dodać kolumny 'is_lengthprice' do tabeli 'customization_field'.");
            $success = false;
        }

        if (!LengthPriceDbRepository::ensureColumnExists(
            'customized_data',
            'lengthprice_data',
            'TEXT DEFAULT NULL',
            [$this->module, 'logToFile']
        )) {
            $this->module->logToFile("Installer: Nie udało się dodać kolumny 'lengthprice_data' do tabeli 'customized_data'.");
            $success = false;
        }
        return $success;
    }

    private function handleCustomColumnsOnUninstall(): bool
    {
        $result = LengthPriceDbRepository::markLengthPriceCustomizationFieldsAsDeleted([$this->module, 'logToFile']);
        if (!$result) {
            $this->module->logToFile("Installer: Nie udało się oznaczyć pól personalizacji długości jako usuniętych.");
        }
        return $result;
    }
}
