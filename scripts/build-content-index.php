<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/content.php';

try {
    $items = nutrin_write_index();
    fwrite(STDOUT, 'Indice generato: ' . count($items) . ' contenuti.' . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Generazione indice fallita: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
