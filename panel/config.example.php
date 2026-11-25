<?php
/**
 * Przykładowa konfiguracja panelu Studio-Komp.
 * Skopiuj do /srv/studio-komp-cms/config.php i uzupełnij sekrety.
 * Ten plik NIE zawiera prawdziwych sekretów i może leżeć w repozytorium.
 */
return [
    // Ścieżki po stronie serwera
    'repo_dir'    => '/srv/studio-komp',
    'data_dir'    => '/srv/studio-komp-cms/data',
    'uploads_dir' => '/srv/studio-komp/public/images/uploads',
    'releases_dir' => '/srv/studio-komp-releases',
    'webroot'     => '/var/www/sk.mati-it.pl',
    'preview_dir' => '/var/www/sk-preview',

    // Adresy
    'site_url'    => 'https://sk.mati-it.pl',
    'panel_path'  => '/panel',

    // Bezpieczeństwo
    'session_name'        => 'sk_panel',
    'idle_timeout'        => 1800,  // sekundy bezczynności
    'session_rotate'      => 900,   // rotacja ID sesji
    'login_max_attempts'  => 5,
    'login_window'        => 900,   // sekundy okna prób logowania
    'login_lock'          => 900,   // blokada po przekroczeniu limitu
    'totp_enabled'        => true,

    // Publikacja: build wykonuje się lokalnie (Windows), panel tylko kolejkuje.
    'publish_mode'        => 'local_queue',
    'publisher_name'      => 'Komputer Studio-Komp',

    // Google (opcjonalne integracje; puste = funkcja nieaktywna)
    'google_places_api_key' => '',
    'google_place_id'       => 'ChIJFSWHiHXCD0cRi3pxOkFdqFE',

    // Automatyczne odświeżanie opinii (cron na VPS)
    'reviews_refresh_hours' => 12,

    // Limity analityki
    'analytics_retention_days' => 400,
    'analytics_max_events_per_minute' => 60,

    // Media
    'media_max_bytes' => 12 * 1024 * 1024,
    'media_allowed_mime' => [
        'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml',
        'video/mp4', 'video/webm',
    ],
];
