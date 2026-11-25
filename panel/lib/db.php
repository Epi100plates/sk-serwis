<?php
/**
 * Warstwa bazy danych panelu (SQLite).
 * Schemat jest idempotentny - db_init() można wywoływać przy każdym żądaniu.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = cfg('data_dir');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie można utworzyć katalogu danych: ' . $dir);
    }

    $pdo = new PDO('sqlite:' . $dir . '/panel.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    db_init($pdo);
    return $pdo;
}

function db_init(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    totp_secret TEXT,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    last_login_at TEXT,
    last_login_ip TEXT,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(username, ip_hash, created_at)');

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username TEXT,
    action TEXT NOT NULL,
    target TEXT,
    details TEXT,
    ip_hash TEXT,
    created_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    name TEXT NOT NULL,
    page TEXT NOT NULL DEFAULT '',
    location TEXT NOT NULL DEFAULT '',
    target TEXT NOT NULL DEFAULT '',
    element TEXT NOT NULL DEFAULT '',
    device TEXT NOT NULL DEFAULT 'desktop',
    source TEXT NOT NULL DEFAULT 'direct',
    medium TEXT NOT NULL DEFAULT '',
    campaign TEXT NOT NULL DEFAULT '',
    referrer_host TEXT NOT NULL DEFAULT '',
    visitor_id TEXT NOT NULL DEFAULT '',
    session_id TEXT NOT NULL DEFAULT '',
    meta TEXT NOT NULL DEFAULT '{}'
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_name ON events(name, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_page ON events(page, created_at)');

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pageviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    page TEXT NOT NULL,
    device TEXT NOT NULL DEFAULT 'desktop',
    source TEXT NOT NULL DEFAULT 'direct',
    medium TEXT NOT NULL DEFAULT '',
    campaign TEXT NOT NULL DEFAULT '',
    referrer_host TEXT NOT NULL DEFAULT '',
    visitor_id TEXT NOT NULL DEFAULT '',
    session_id TEXT NOT NULL DEFAULT '',
    is_new_visitor INTEGER NOT NULL DEFAULT 0
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pageviews_created ON pageviews(created_at, page)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pageviews_page ON pageviews(page, created_at)');

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS realizacje_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 50,
    created_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS realizacje (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    category_slug TEXT NOT NULL DEFAULT 'inne',
    brand TEXT NOT NULL DEFAULT '',
    model TEXT NOT NULL DEFAULT '',
    summary TEXT NOT NULL DEFAULT '',
    problem TEXT NOT NULL DEFAULT '',
    diagnosis TEXT NOT NULL DEFAULT '',
    repair TEXT NOT NULL DEFAULT '',
    result TEXT NOT NULL DEFAULT '',
    time_label TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    gallery TEXT NOT NULL DEFAULT '[]',
    before_after TEXT NOT NULL DEFAULT '',
    realization_date TEXT NOT NULL DEFAULT '',
    seo_title TEXT NOT NULL DEFAULT '',
    seo_description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'published',
    sort INTEGER NOT NULL DEFAULT 50,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL UNIQUE,
    path TEXT NOT NULL,
    mime TEXT NOT NULL DEFAULT '',
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    bytes INTEGER NOT NULL DEFAULT 0,
    alt TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT ''
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS redirects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    old_path TEXT NOT NULL UNIQUE,
    target_url TEXT NOT NULL,
    status_code INTEGER NOT NULL DEFAULT 301,
    enabled INTEGER NOT NULL DEFAULT 1,
    note TEXT NOT NULL DEFAULT '',
    hits INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS reviews_cache (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    rating REAL,
    review_count INTEGER,
    business_name TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT '',
    payload TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    fetched_at TEXT
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    target TEXT NOT NULL,
    content TEXT NOT NULL,
    username TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_versions ON content_versions(kind, target, created_at)');

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS publish_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL DEFAULT 'publish',
    requested_by TEXT NOT NULL DEFAULT '',
    requested_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    finished_at TEXT,
    release TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT ''
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);
SQL);

    // --- Zgłoszenia serwisowe z formularza (publiczny endpoint /api/zgloszenie) ---
    // Numer zgłoszenia: SK-YYYY-000123 (sekwencja roczna, generowana w transakcji).
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    device TEXT NOT NULL DEFAULT '',
    problem TEXT NOT NULL,
    source_page TEXT NOT NULL DEFAULT '',
    ip_hash TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'new',
    consent_at TEXT NOT NULL DEFAULT '',
    consent_version TEXT NOT NULL DEFAULT '',
    client_token TEXT NOT NULL DEFAULT '',
    archived INTEGER NOT NULL DEFAULT 0,
    notes TEXT NOT NULL DEFAULT '',
    public_note TEXT NOT NULL DEFAULT '',
    status_token_version INTEGER NOT NULL DEFAULT 0
);
SQL);
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tickets_number ON tickets(number)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_created ON tickets(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_phone ON tickets(phone)');

    // Historia statusów/następstw (kto i kiedy zmienił status).
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ticket_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT 'system',
    created_at TEXT NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ticket_history_ticket ON ticket_history(ticket_id, id)');

    // Kolejka powiadomień: jeden wiersz = jedna wiadomość (SMS/e-mail) na zgłoszenie.
    // UNIQUE(ticket_id, channel, template) gwarantuje brak duplikatów przy ponowieniu formularza.
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL,
    channel TEXT NOT NULL,
    provider TEXT NOT NULL DEFAULT '',
    recipient TEXT NOT NULL DEFAULT '',
    template TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    provider_message_id TEXT NOT NULL DEFAULT '',
    delivery_status TEXT NOT NULL DEFAULT '',
    next_attempt_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(ticket_id, channel, template),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_queue ON notifications(status, next_attempt_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_ticket ON notifications(ticket_id, id)');

    // Limit tempa formularza: 3 zgłoszenia / 10 minut / IP-hash.
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS form_rate (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_form_rate ON form_rate(ip_hash, created_at)');

    // Limit tempa publicznej strony statusu (link z SMS-a bywa udostępniany dalej).
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS status_rate (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_status_rate_ip ON status_rate(ip_hash, created_at)');

    // Migracje lekkie (dodawanie kolumn w przyszłości)
    db_ensure_column($pdo, 'events', 'element', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'events', 'referrer_host', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'source_page', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'user_agent', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'consent_at', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'consent_version', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'client_token', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'archived', 'INTEGER NOT NULL DEFAULT 0');
    db_ensure_column($pdo, 'tickets', 'notes', "TEXT NOT NULL DEFAULT ''");
    // Publiczna strona statusu: notatka dla klienta i wersja tokenu w linku.
    db_ensure_column($pdo, 'tickets', 'public_note', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'tickets', 'status_token_version', 'INTEGER NOT NULL DEFAULT 0');
    db_ensure_column($pdo, 'ticket_history', 'note', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'notifications', 'provider', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'notifications', 'delivery_status', "TEXT NOT NULL DEFAULT ''");
    db_ensure_column($pdo, 'notifications', 'next_attempt_at', 'TEXT');

    // Unikalny token klienta (idempotencja formularza) dopiero po migracji kolumny.
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tickets_client_token ON tickets(client_token)');
}

function db_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $col) {
        if ($col['name'] === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function setting_get(string $key, string $default = ''): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setting_set(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
}

function audit(string $action, string $target = '', array $details = []): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $st = db()->prepare('INSERT INTO audit_log (user_id, username, action, target, details, ip_hash, created_at) VALUES (?,?,?,?,?,?,?)');
    $st->execute([
        $user['id'] ?? null,
        $user['username'] ?? '',
        $action,
        $target,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : '',
        security_ip_hash(),
        now(),
    ]);
}

function now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function security_ip_hash(): string
{
    static $salt = null;
    if ($salt === null) {
        $saltFile = cfg('data_dir') . '/ip_salt.key';
        if (!is_file($saltFile)) {
            @mkdir(dirname($saltFile), 0770, true);
            $salt = bin2hex(random_bytes(32));
            file_put_contents($saltFile, $salt);
            @chmod($saltFile, 0600);
        } else {
            $salt = (string) file_get_contents($saltFile);
        }
    }
    $ip = client_ip();
    return hash('sha256', $salt . '|' . $ip . '|' . date('Y-m-d'));
}

/**
 * Adres klienta z uwzglednieniem Cloudflare Tunnel.
 * Ruch do nginx-sk przychodzi wylacznie z cloudflared (127.0.0.1), dlatego
 * naglowek CF-Connecting-IP jest zaufany tylko dla zadan lokalnych z CF-Ray.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    $viaCf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']);
    $isLocal = in_array($remote, ['127.0.0.1', '::1', ''], true);
    if ($viaCf && $isLocal && $cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }
    return $remote;
}
