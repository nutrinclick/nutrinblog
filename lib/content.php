<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

const NUTRIN_CATEGORIES = ['articoli', 'nutriconsigli', 'ricette', 'risposte', 'news'];
const NUTRIN_INDEX_FILENAME = 'content-index.json';

function nutrin_project_root(): string
{
    return dirname(__DIR__);
}

function nutrin_content_directory(): string
{
    return nutrin_project_root() . DIRECTORY_SEPARATOR . 'contenuti';
}

function nutrin_index_path(): string
{
    $override = getenv('NUTRIN_INDEX_PATH');
    if (is_string($override) && $override !== '') return $override;
    return nutrin_content_directory() . DIRECTORY_SEPARATOR . NUTRIN_INDEX_FILENAME;
}

function nutrin_esc($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nutrin_parse_document(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    if (!preg_match('/\A---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)/s', $raw, $match)) {
        return [[], $raw];
    }

    try {
        $metadata = Yaml::parse($match[1], Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    } catch (ParseException $error) {
        throw new RuntimeException('Frontmatter YAML non valido.', 0, $error);
    }
    if (!is_array($metadata)) {
        throw new RuntimeException('Il frontmatter YAML deve essere una mappa di campi.');
    }
    return [$metadata, substr($raw, strlen($match[0]))];
}

function nutrin_scalar(array $metadata, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $metadata) || $metadata[$key] === null) return $default;
    if (!is_scalar($metadata[$key])) throw new RuntimeException("Il campo YAML '$key' deve essere un valore semplice.");
    return trim((string) $metadata[$key]);
}

function nutrin_normalize_date_value($value): string
{
    if ($value instanceof DateTimeInterface) return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/\A\d{9,12}\z/', trim($value)))) {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
    }
    return is_scalar($value) ? trim((string) $value) : '';
}

function nutrin_date(array $metadata): string
{
    return array_key_exists('date', $metadata) ? nutrin_normalize_date_value($metadata['date']) : '';
}

function nutrin_excerpt(string $body, int $max = 0): string
{
    $text = strip_tags($body);
    $text = preg_replace('/[`*_>#\[\]()!-]+/u', ' ', $text) ?? $text;
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($max > 0 && function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
    }
    return $text;
}

function nutrin_slug_from_filename(string $filename): string
{
    $slug = preg_replace('/\.md\z/i', '', basename($filename)) ?? '';
    return preg_match('/\A[a-zA-Z0-9-]+\z/', $slug) ? $slug : '';
}

function nutrin_markdown_files(): array
{
    $files = glob(nutrin_content_directory() . DIRECTORY_SEPARATOR . '*.md');
    return is_array($files) ? $files : [];
}

function nutrin_item_from_document(string $filename, array $metadata, string $body): array
{
    $slug = nutrin_slug_from_filename($filename);
    if ($slug === '') throw new RuntimeException('Nome file Markdown non valido.');
    $category = strtolower(nutrin_scalar($metadata, 'category', nutrin_scalar($metadata, 'type')));
    $date = nutrin_date($metadata);

    return [
        'slug' => $slug,
        'title' => nutrin_scalar($metadata, 'title', $slug),
        'date' => $date,
        'category' => $category,
        'categoriesText' => nutrin_scalar($metadata, 'categoriesText', $category ?: 'contenuto'),
        'image' => nutrin_scalar($metadata, 'image'),
        'excerpt' => nutrin_scalar($metadata, 'excerpt', nutrin_excerpt($body)),
        'author' => nutrin_scalar($metadata, 'author', 'Redazione nutrinclick'),
    ];
}

function nutrin_sort_items(array &$items): void
{
    usort($items, static fn(array $a, array $b): int => (strtotime((string) ($b['date'] ?? '')) ?: 0) <=> (strtotime((string) ($a['date'] ?? '')) ?: 0));
}

function nutrin_scan_local_items(): array
{
    $items = [];
    foreach (nutrin_markdown_files() as $filename) {
        try {
            $raw = file_get_contents($filename);
            if (!is_string($raw)) throw new RuntimeException('File Markdown non leggibile.');
            [$metadata, $body] = nutrin_parse_document($raw);
            $items[] = nutrin_item_from_document($filename, $metadata, $body);
        } catch (Throwable $error) {
            error_log('Contenuto locale ' . basename($filename) . ' ignorato: ' . $error->getMessage());
        }
    }
    nutrin_sort_items($items);
    return $items;
}

function nutrin_index_is_fresh(string $indexPath, array $items): bool
{
    $files = nutrin_markdown_files();
    if (count($files) !== count($items)) return false;
    $indexTime = filemtime($indexPath) ?: 0;
    foreach ($files as $file) {
        if ((filemtime($file) ?: 0) > $indexTime) return false;
    }
    return true;
}

function nutrin_load_index(): ?array
{
    $path = nutrin_index_path();
    if (!is_file($path) || !is_readable($path)) return null;
    try {
        $raw = file_get_contents($path);
        if (!is_string($raw)) throw new RuntimeException('Indice non leggibile.');
        $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($items)) throw new RuntimeException('Formato indice non valido.');
        foreach ($items as &$item) {
            if (!is_array($item) || !isset($item['slug'], $item['title'], $item['date'], $item['category'])) {
                throw new RuntimeException('Voce indice non valida.');
            }
            $item['date'] = nutrin_normalize_date_value($item['date']);
        }
        unset($item);
        if (!nutrin_index_is_fresh($path, $items)) return null;
        nutrin_sort_items($items);
        return $items;
    } catch (Throwable $error) {
        error_log('Indice contenuti non utilizzabile: ' . $error->getMessage());
        return null;
    }
}

function nutrin_load_items(): array
{
    return nutrin_load_index() ?? nutrin_scan_local_items();
}

function nutrin_write_index(?string $destination = null): array
{
    $destination ??= nutrin_index_path();
    $items = nutrin_scan_local_items();
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $destination . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Impossibile scrivere atomicamente l’indice contenuti.');
    }
    return $items;
}

function nutrin_load_post(string $slug): array
{
    if (!preg_match('/\A[a-zA-Z0-9-]+\z/', $slug)) throw new InvalidArgumentException('Slug non valido.');
    $path = nutrin_content_directory() . DIRECTORY_SEPARATOR . $slug . '.md';
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Contenuto locale non trovato.');
    $raw = file_get_contents($path);
    if (!is_string($raw)) throw new RuntimeException('Contenuto locale non leggibile.');
    return nutrin_parse_document($raw);
}

function nutrin_image_url(string $image): string
{
    $original = trim($image);
    if ($original === '') return '';
    $decoded = rawurldecode(str_replace('\\', '/', $original));
    $path = ltrim($decoded, '/');
    $segments = explode('/', $path);
    if (count($segments) < 3 || $segments[0] !== 'assets' || $segments[1] !== 'uploads' || in_array('..', $segments, true) || in_array('.', $segments, true)) {
        error_log("Percorso cover locale non valido: $original");
        return '';
    }
    $relative = implode(DIRECTORY_SEPARATOR, array_slice($segments, 2));
    $localPath = nutrin_project_root() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($localPath)) {
        error_log("Cover locale mancante: $original");
        return '';
    }
    return '/assets/uploads/' . implode('/', array_map('rawurlencode', array_slice($segments, 2)));
}

function nutrin_filter_items(array $items, string $category): array
{
    $category = strtolower(trim($category));
    if ($category === '') return array_values($items);
    if (!in_array($category, NUTRIN_CATEGORIES, true)) throw new InvalidArgumentException('Categoria non valida.');
    return array_values(array_filter($items, static fn(array $item): bool => ($item['category'] ?? '') === $category));
}
