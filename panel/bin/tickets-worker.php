<?php
/**
 * Worker kolejki powiadomień formularza (CLI, cron co minutę).
 *
 * Przetwarza powiadomienia SMS/e-mail dla nowych zgłoszeń, zapisuje wynik,
 * stosuje backoff (1, 5, 15, 60 minut, maks. 5 prób). Blokada flock chroni
 * przed nakładaniem się przebiegów. Log: data/logs/tickets.log (bez danych osobowych).
 *
 * Cron (deploy/setup-cms.sh):
 *   * * * * * skcms /usr/bin/php /srv/studio-komp/panel/bin/tickets-worker.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

$lockFile = rtrim((string) cfg('data_dir'), '/') . '/tickets-worker.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock) {
    fwrite(STDERR, "Nie mozna otworzyc pliku blokady: $lockFile\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    tickets_log('worker: poprzedni przebieg nadal trwa - pomijam');
    exit(0);
}

try {
    $summary = tickets_process_queue(30);
    $rateRemoved = tickets_cleanup_rate();
    tickets_log(sprintf(
        'worker: przetworzono %d, wyslano %d, test %d, pominieto %d, bledy %d, form_rate usunieto %d',
        $summary['processed'],
        $summary['sent'],
        $summary['test'],
        $summary['skipped'],
        $summary['failed'],
        $rateRemoved
    ));
    echo 'Kolejka: przetworzono ' . $summary['processed']
        . ', wyslano ' . $summary['sent']
        . ', test ' . $summary['test']
        . ', pominieto ' . $summary['skipped']
        . ', bledy ' . $summary['failed'] . "\n";
    echo "OK\n";
} catch (Throwable $e) {
    tickets_log('worker: blad ' . get_class($e));
    fwrite(STDERR, "Blad workera powiadomien. Szczegoly w data/logs/tickets.log\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit(0);
