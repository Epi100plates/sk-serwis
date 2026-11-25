<?php
/**
 * Samokontrola warstwy treści: round-trip frontmattera dla wszystkich plików
 * Markdown + testy syntetyczne. Uruchomienie (CLI):
 *   php /srv/studio-komp/panel/bin/self-check.php
 * Kod wyjścia 0 = OK.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

$failures = [];
$checked = 0;

$synthetic = [
    "title: \"Test: wartość\"\nincludes:\n  - \"pierwsza\"\n  - \"druga\"\n  - trzecia\nfaq:\n  - question: \"Pytanie?\"\n    answer: \"Odpowiedź z # i : oraz \\\"cudzysłowem\\\"\"\nproblems: []\nupdated: 2026-11-25\norder: 12\nfeatured: true",
];

foreach ($synthetic as $index => $yaml) {
    $parsed = yaml_parse_simple($yaml);
    $roundtrip = serialize_frontmatter($parsed, '');
    $again = parse_frontmatter($roundtrip)['data'];
    if ($again !== $parsed) {
        $failures[] = "Syntetyczny test #{$index}: round-trip nie jest stabilny.\n" . json_encode($parsed, JSON_UNESCAPED_UNICODE) . "\n" . json_encode($again, JSON_UNESCAPED_UNICODE);
    }
    if (count($parsed['includes'] ?? []) !== 3) {
        $failures[] = "Syntetyczny test #{$index}: includes powinno mieć 3 pozycje, jest " . count($parsed['includes'] ?? []);
    }
    if (($parsed['faq'][0]['answer'] ?? '') === '') {
        $failures[] = "Syntetyczny test #{$index}: FAQ zgubiło odpowiedź.";
    }
    if ((int) ($parsed['order'] ?? 0) !== 12 || ($parsed['featured'] ?? null) !== true) {
        $failures[] = "Syntetyczny test #{$index}: typy liczb/bool nie zachowane.";
    }
    $checked++;
}

foreach (content_types() as $type => $definition) {
    $dir = content_dir($type);
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob($dir . '/*.md') ?: [] as $file) {
        $checked++;
        $raw = (string) file_get_contents($file);
        $parsed = parse_frontmatter($raw);
        $again = parse_frontmatter(serialize_frontmatter($parsed['data'], $parsed['body']));
        if ($again['data'] !== $parsed['data']) {
            $diffKeys = [];
            foreach (array_unique(array_merge(array_keys($parsed['data']), array_keys($again['data']))) as $key) {
                if (($parsed['data'][$key] ?? null) !== ($again['data'][$key] ?? null)) {
                    $diffKeys[] = $key;
                }
            }
            $failures[] = basename($file) . ': pola różnią się po round-trip: ' . implode(', ', $diffKeys);
        }
        if (trim($again['body']) !== trim($parsed['body'])) {
            $failures[] = basename($file) . ': treść (body) zmieniła się po round-trip.';
        }
    }
}

echo "Sprawdzone pliki/testy: {$checked}\n";
if ($failures) {
    echo "BŁĘDY (" . count($failures) . "):\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo "OK — parser i serializator YAML są stabilne.\n";
