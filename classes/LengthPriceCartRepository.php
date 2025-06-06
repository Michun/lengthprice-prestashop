<?php
// /modules/lengthprice/classes/LengthPriceCartRepository.php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer; // Dla translatora
use Symfony\Contracts\Translation\TranslatorInterface;


class LengthPriceCartRepository
{
    private Db $db;
    private string $dbPrefix;
    private TranslatorInterface $translator;
    private array $languages;
    private \LengthPrice $module; // Instancja modułu do logowania

    public function __construct(\LengthPrice $moduleInstance, Db $dbInstance, string $databasePrefix, TranslatorInterface $translator, array $languages)
    {
        $this->module = $moduleInstance;
        $this->db = $dbInstance;
        $this->dbPrefix = $databasePrefix;
        $this->translator = $translator;
        $this->languages = $languages;
    }

    /**
     * Generuje tekst wyświetlany dla personalizacji długości.
     *
     * @param array $lengthData Tablica z kluczem 'length', np. ['length' => 123]
     * @return string
     */
    public function getCustomizationDisplayText(array $lengthData): string
    {
        if (isset($lengthData['length'])) {
            // Użyj translatora, jeśli chcesz, aby "Length" i "mm" były tłumaczone
            // $label = $this->translator->trans('Length', [], 'Modules.Lengthprice.Shop');
            // $unit = $this->translator->trans('mm', [], 'Modules.Lengthprice.Shop');
            // return $label . ': ' . $lengthData['length'] . ' ' . $unit;
            return 'Length: ' . $lengthData['length'] . ' mm';
        }
        return '';
    }

    /**
     * Zapewnia istnienie pola personalizacji dla modułu lengthprice i zwraca jego ID.
     *
     * @param int $idProduct
     * @param int $idShop
     * @return int ID pola personalizacji
     * @throws PrestaShopDatabaseException
     */
    public function getCustomizationFieldId(int $idProduct, int $idShop): int
    {
        $query = new DbQuery();
        $query->select('cf.id_customization_field');
        $query->from('customization_field', 'cf');
        $query->where('cf.id_product = ' . (int)$idProduct);
        $query->where('cf.is_lengthprice = 1'); // Używamy flagi z Twojego modułu
        $query->where('cf.is_deleted = 0'); // Upewnij się, że pole nie jest usunięte

        $fieldId = (int)$this->db->getValue($query);

        if (!$fieldId) {
            $customizationField = new CustomizationField();
            $customizationField->id_product = $idProduct;
            $customizationField->type = Product::CUSTOMIZE_TEXTFIELD; // Pole tekstowe
            $customizationField->required = 1; // Czy wymagane? Dostosuj wg potrzeb
            $customizationField->is_module = 1; // Oznacz jako pole modułu

            foreach ($this->languages as $language) {
                // Użyj metody l() z modułu lub translatora
                $customizationField->name[$language['id_lang']] = $this->module->l('Length (mm)', 'lengthpricecartrepository');
            }

            if (!$customizationField->add()) {
                $this->module->logToFile('[LengthPriceCartRepository] Error adding customization field for product ID ' . $idProduct);
                // Możesz rzucić wyjątek lub zwrócić 0/false
                return 0;
            }
            $fieldId = (int)$customizationField->id;

            // Ustaw flagę is_lengthprice dla nowo utworzonego pola
            LengthPriceDbRepository::setCustomizationFieldLengthFlag($fieldId);
            $this->module->logToFile("[LengthPriceCartRepository] Created new customization field ID {$fieldId} for product ID {$idProduct} and set is_lengthprice=1.");
        } else {
            $this->module->logToFile("[LengthPriceCartRepository] Found existing customization field ID {$fieldId} for product ID {$idProduct}.");
        }
        return $fieldId;
    }

    /**
     * Zapisuje lub aktualizuje strukturalne dane JSON dla personalizacji.
     *
     * @param int $idCustomization ID wpisu w ps_customization
     * @param int $idCustomizationField ID pola personalizacji (z ps_customization_field)
     * @param array $lengthData Tablica z danymi, np. ['length' => 123]
     * @return bool
     */
    public function updateStructuredCustomizationData(int $idCustomization, int $idCustomizationField, array $lengthData): bool
    {
        if (!isset($lengthData['length'])) {
            $this->module->logToFile("[LengthPriceCartRepository] updateStructuredCustomizationData - Missing 'length' in data for id_customization {$idCustomization}.");
            return false;
        }

        $jsonData = json_encode($lengthData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->module->logToFile("[LengthPriceCartRepository] updateStructuredCustomizationData - JSON encode error: " . json_last_error_msg());
            return false;
        }

        // Znajdź wpis w ps_customized_data stworzony przez PrestaShop
        // PrestaShop używa `index` jako ID pola personalizacji i `type` = 0 dla pól tekstowych z formularza
        // lub `type` = 1 dla plików. Dla pól tekstowych `type` = Product::CUSTOMIZE_TEXTFIELD (czyli 1)
        // jest używane przy tworzeniu pola, ale w `customized_data` `type` = 0 dla wartości tekstowych.
        // Sprawdźmy, jak PrestaShop zapisuje to dla Twojego pola.
        // Najbezpieczniej jest polegać na id_customization i id_customization_field (index).

        $result = $this->db->update(
            'customized_data',
            ['lengthprice_data' => pSQL($jsonData)],
            '`id_customization` = ' . (int)$idCustomization . ' AND `index` = ' . (int)$idCustomizationField . ' AND `type` = ' . (int)Product::CUSTOMIZE_TEXTFIELD,
            1 // limit
        );

        if (!$result) {
            // Spróbuj z type = 0, jeśli type = 1 (Product::CUSTOMIZE_TEXTFIELD) nie zadziałało
            $result = $this->db->update(
                'customized_data',
                ['lengthprice_data' => pSQL($jsonData)],
                '`id_customization` = ' . (int)$idCustomization . ' AND `index` = ' . (int)$idCustomizationField . ' AND `type` = 0',
                1 // limit
            );
        }


        if ($result) {
            $this->module->logToFile("[LengthPriceCartRepository] Successfully updated lengthprice_data for id_customization {$idCustomization}, field ID {$idCustomizationField}.");
        } else {
            $this->module->logToFile("[LengthPriceCartRepository] Failed to update lengthprice_data for id_customization {$idCustomization}, field ID {$idCustomizationField}. DB Error: " . $this->db->getMsgError());
        }
        return (bool)$result;
    }

    /**
     * Pobiera strukturalne dane personalizacji z koszyka.
     *
     * @param int $idCustomization
     * @param int $idCustomizationField
     * @return array|null
     */
    public function getStructuredCartData(int $idCustomization, int $idCustomizationField): ?array
    {
        $query = new DbQuery();
        $query->select('cd.lengthprice_data');
        $query->from('customized_data', 'cd');
        $query->where('cd.id_customization = ' . (int)$idCustomization);
        $query->where('cd.index = ' . (int)$idCustomizationField); // `index` w ps_customized_data to id_customization_field
        $query->where('cd.lengthprice_data IS NOT NULL');

        $jsonData = $this->db->getValue($query);

        if ($jsonData) {
            $data = json_decode($jsonData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            } else {
                $this->module->logToFile("[LengthPriceCartRepository] getStructuredCartData - JSON decode error for id_customization {$idCustomization}.");
            }
        }
        return null;
    }
}