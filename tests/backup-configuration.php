<?php

declare(strict_types=1);

final class SetupWebPage
{
    public static array $modules = [];

    public static function AddModule(string $file, string $id, array $options): void
    {
        self::$modules[$id] = $options;
    }
}

final class Dict
{
    public static array $languages = [];

    public static function Add(string $code, string $name, string $label, array $entries): void
    {
        self::$languages[$code] = $entries;
    }
}

function expectBackup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$model = simplexml_load_file(__DIR__ . '/../br-backupconfiguration/datamodel.br-backupconfiguration.xml', SimpleXMLElement::class, LIBXML_NONET);
if ($model === false) {
    throw new RuntimeException('Cannot load backup configuration model.');
}

$tests = [
    'backup fields retain their storage and tag contracts' => static function () use ($model): void {
        foreach (['backupmethod' => 'AttributeTagSet', 'backupdescription' => 'AttributeString'] as $id => $type) {
            $fields = $model->xpath('/itop_design/classes/class[@id="FunctionalCI"]/fields/field[@id="' . $id . '"]');
            expectBackup(count($fields) === 1, "Expected one $id field.");
            $field = $fields[0];
            expectBackup((string) $field->attributes('http://www.w3.org/2001/XMLSchema-instance')->type === $type
                && (string) $field['_delta'] === 'define'
                && (string) $field->sql === $id && (string) $field->is_null_allowed === 'true', "Invalid $id field contract.");
        }
        $tags = $model->xpath('//class[@id="FunctionalCI"]/fields/field[@id="backupmethod"]')[0];
        expectBackup((string) $tags->max_items === '12' && (string) $tags->tag_code_max_len === '20', 'Unexpected tag limits.');
    },
    'module registration matches manifest and required iTop dependencies' => static function (): void {
        require __DIR__ . '/../br-backupconfiguration/module.br-backupconfiguration.php';
        $manifest = simplexml_load_file(__DIR__ . '/../extension.xml');
        $id = 'br-backupconfiguration/' . trim((string) $manifest->version);
        expectBackup(count(SetupWebPage::$modules) === 1 && isset(SetupWebPage::$modules[$id]), 'Module and manifest versions differ.');
        $options = SetupWebPage::$modules[$id];
        expectBackup($options['dependencies'] === ['itop-config-mgmt/3.2.0', 'itop-datacenter-mgmt/3.2.0', 'itop-virtualization-mgmt/3.2.0']
            && $options['visible'] === true && $options['mandatory'] === false, 'Unexpected module setup contract.');
    },
    'all dictionaries cover the presented backup fields' => static function (): void {
        foreach (glob(__DIR__ . '/../br-backupconfiguration/dictionaries/*.php') as $file) {
            require $file;
        }
        expectBackup(array_keys(Dict::$languages) === ['DE DE', 'EN US', 'RU RU'], 'Expected German, English, and Russian dictionaries.');
        foreach (Dict::$languages as $language => $entries) {
            foreach (['Server:backupconfiguration', 'Class:FunctionalCI/Attribute:backupmethod', 'Class:FunctionalCI/Attribute:backupmethod+', 'Class:FunctionalCI/Attribute:backupdescription', 'Class:FunctionalCI/Attribute:backupdescription+'] as $key) {
                expectBackup(isset($entries[$key]) && trim($entries[$key]) !== '', "Missing $language translation: $key");
            }
        }
    },
];

foreach (['Server' => 'must_exist', 'VirtualMachine' => 'if_exists', 'NetworkDevice' => 'if_exists', 'DBServer' => 'if_exists'] as $class => $delta) {
    $tests["$class presents backup details and supports method searches"] = static function () use ($model, $class, $delta): void {
        $nodes = $model->xpath('/itop_design/classes/class[@id="' . $class . '"]');
        expectBackup(count($nodes) === 1 && (string) $nodes[0]['_delta'] === $delta, "Unexpected $class integration.");
        $fieldsets = $nodes[0]->xpath('presentation/details/items//item[@id="fieldset:Server:backupconfiguration"]');
        expectBackup(count($fieldsets) === 1, "Missing $class backup fieldset.");
        $items = $fieldsets[0]->xpath('items/item');
        expectBackup(array_map(static fn ($item): string => (string) $item['id'], $items) === ['backupmethod', 'backupdescription'], "Unexpected $class backup fields.");
        expectBackup((int) $items[0]->rank < (int) $items[1]->rank, "Unexpected $class field order.");
        expectBackup(count($nodes[0]->xpath('presentation/search/items/item[@id="backupmethod"]')) === 1, "Missing $class backup search.");
    };
}

return $tests;
