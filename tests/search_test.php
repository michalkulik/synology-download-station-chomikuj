<?php
/**
 * Offline test of the search module parser.
 *
 *   php tests/search_test.php [plik.html]
 *
 * Without an argument the bundled fixture (tests/fixtures/search.html) is used.
 */

require __DIR__ . '/../src/search/search.php';

class ChomikujSearchTestPlugin
{
    public $results = array();

    public function addResult($title, $download, $size, $datetime, $page, $hash, $seeds, $leechs, $category)
    {
        $this->results[] = array(
            'title' => $title,
            'download' => $download,
            'size' => $size,
            'datetime' => $datetime,
            'page' => $page,
            'seeds' => $seeds,
            'leechs' => $leechs,
            'category' => $category,
        );
    }
}

$fixture = isset($argv[1]) ? $argv[1] : __DIR__ . '/fixtures/search.html';
$html = file_get_contents($fixture);
if (false === $html) {
    fwrite(STDERR, "Nie można odczytać pliku: $fixture\n");
    exit(1);
}

$module = new SynoDLMSearchChomikuj();
$plugin = new ChomikujSearchTestPlugin();
$count = $module->parse($plugin, $html);

$failures = array();

if ($count < 1) {
    $failures[] = 'parser nie zwrócił żadnego wyniku';
}
if ($count !== count($plugin->results)) {
    $failures[] = 'liczba wyników nie zgadza się z licznikiem';
}

foreach ($plugin->results as $index => $result) {
    $label = 'wynik #' . ($index + 1);
    if ('' === trim($result['title'])) {
        $failures[] = $label . ': pusty tytuł';
    }
    if (0 !== strpos($result['download'], 'https://chomikuj.pl/')) {
        $failures[] = $label . ': nieprawidłowy adres pobierania';
    }
    if (0 === $result['size']) {
        $failures[] = $label . ': nie odczytano rozmiaru';
    }
    if (!in_array($result['category'], array('video', 'audio', 'image', 'other'), true)) {
        $failures[] = $label . ': nieprawidłowa kategoria';
    }
}

printf("Sprawdzono %d wyników z %s\n", $count, basename($fixture));
foreach ($plugin->results as $result) {
    printf("  %-60s %10d  %-6s %s\n", substr($result['title'], 0, 60), $result['size'], $result['category'], $result['download']);
}

if ($failures) {
    fwrite(STDERR, "\nBŁĘDY:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "\nOK\n";