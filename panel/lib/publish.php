<?php
/**
 * Publikacja: panel kolejkuje żądanie, build wykonuje lokalny komputer
 * (deploy/publish.ps1), a VPS tylko przełącza gotowe wersje.
 */

function publish_dir(): string
{
    $dir = (string) cfg('data_dir');
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

function publish_request_file(): string
{
    return publish_dir() . '/publish-request.json';
}

function publish_state_file(): string
{
    return publish_dir() . '/publish-state.json';
}

function publisher_heartbeat_file(): string
{
    return publish_dir() . '/publisher-heartbeat.json';
}

function request_publish(string $kind = 'publish'): array
{
    $user = current_user();
    $st = db()->prepare('INSERT INTO publish_requests (kind, requested_by, requested_at, status) VALUES (?,?,?,?)');
    $st->execute([$kind, $user['username'] ?? 'system', now(), 'pending']);
    $id = (int) db()->lastInsertId();

    $request = [
        'id' => $id,
        'kind' => $kind,
        'requested_by' => $user['username'] ?? 'system',
        'requested_at' => now_iso(),
    ];
    $tmp = publish_request_file() . '.tmp';
    file_put_contents($tmp, json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, publish_request_file());
    @chmod(publish_request_file(), 0660);

    audit('publish_request', 'kind:' . $kind, ['id' => $id]);
    return $request;
}

function publish_state(): array
{
    $state = [];
    if (is_file(publish_state_file())) {
        $decoded = json_decode((string) file_get_contents(publish_state_file()), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    // Uzgodnienie statusów: komputer publikujący zapisuje stan z request_id.
    if (!empty($state['request_id'])) {
        $status = ($state['status'] ?? '') === 'done' ? 'done' : (($state['status'] ?? '') === 'error' ? 'error' : '');
        if ($status !== '') {
            try {
                mark_requests_done((int) $state['request_id'], $status, (string) ($state['release'] ?? ''), (string) ($state['error'] ?? ''));
            } catch (Throwable $e) {
                log_panel('publish_state reconcile: ' . $e->getMessage());
            }
        }
    }

    $heartbeat = null;
    if (is_file(publisher_heartbeat_file())) {
        $hb = json_decode((string) file_get_contents(publisher_heartbeat_file()), true);
        if (is_array($hb) && !empty($hb['at'])) {
            $heartbeat = $hb;
        }
    }
    $state['heartbeat'] = $heartbeat;
    $state['pending'] = pending_publish_requests();
    $state['requests'] = db()->query('SELECT * FROM publish_requests ORDER BY id DESC LIMIT 10')->fetchAll();
    return $state;
}

function pending_publish_requests(): array
{
    return db()->query("SELECT * FROM publish_requests WHERE status = 'pending' ORDER BY id ASC")->fetchAll();
}

function mark_requests_done(int $upToId, string $status, string $release, string $error = ''): void
{
    $st = db()->prepare('UPDATE publish_requests SET status = ?, finished_at = ?, release = ?, error = ? WHERE id <= ? AND status = ?');
    $st->execute([$status, now(), $release, $error, $upToId, 'pending']);
}

function releases(): array
{
    $dir = (string) cfg('releases_dir');
    if (!is_dir($dir)) {
        return [];
    }
    $items = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (!preg_match('/^\d{8}-\d{6}$/', $name)) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (!is_dir($path)) {
            continue;
        }
        $manifest = [];
        if (is_file($path . '/manifest.json')) {
            $decoded = json_decode((string) file_get_contents($path . '/manifest.json'), true);
            $manifest = is_array($decoded) ? $decoded : [];
        }
        $items[] = [
            'name' => $name,
            'created_at' => $manifest['created_at'] ?? date('Y-m-d H:i:s', filemtime($path)),
            'files' => $manifest['files'] ?? 0,
            'bytes' => $manifest['bytes'] ?? 0,
            'commit' => $manifest['commit'] ?? '',
            'current' => is_link((string) cfg('webroot')) && basename((string) readlink((string) cfg('webroot'))) === $name,
        ];
    }
    usort($items, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $items;
}

function switch_release(string $name): array
{
    if (!preg_match('/^\d{8}-\d{6}$/', $name)) {
        throw new RuntimeException('Nieprawidłowa nazwa wersji.');
    }
    $dir = (string) cfg('releases_dir') . '/' . $name;
    if (!is_dir($dir)) {
        throw new RuntimeException('Wersja nie istnieje.');
    }
    $cmd = 'sudo -n /usr/local/bin/sk-switch-release ' . escapeshellarg($name) . ' 2>&1';
    exec($cmd, $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('Nie udało się przełączyć wersji: ' . implode(' ', $output));
    }
    audit('release_switch', $name);
    return ['ok' => true, 'release' => $name];
}

function publish_log_tail(int $lines = 80): string
{
    $file = publish_dir() . '/last-publish.log';
    if (!is_file($file)) {
        return '';
    }
    $all = preg_split('/\r?\n/', (string) file_get_contents($file));
    return implode("\n", array_slice($all, -$lines));
}

function build_info(): array
{
    return [
        'last_build_at' => setting_get('last_build_at'),
        'last_build_commit' => setting_get('last_build_commit'),
        'last_release' => setting_get('last_release'),
    ];
}
