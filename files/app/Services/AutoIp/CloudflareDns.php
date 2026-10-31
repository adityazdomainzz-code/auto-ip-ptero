<?php

namespace Pterodactyl\Services\AutoIp;

use Pterodactyl\Exceptions\DisplayException;

/**
 * Auto IP (Cloudflare DNS) for Pterodactyl.
 *
 * Every new server gets  <server-name>.<domain>  (A / AAAA / CNAME record, DNS only)
 * pointing at the server's primary allocation (or the "Public IP" set by the admin).
 * Minecraft servers also get an SRV record so players can join without a port.
 * A name that is already used stops the server from being created.
 */
class CloudflareDns
{
    public const PREFIX = 'autoip::';

    /** @var object settings repository: get(key, default), set(key, value), forget(key) */
    private $settings;

    /** @var RecordStore */
    private $store;

    private string $api;

    public function __construct($settings, RecordStore $store, ?string $apiBase = null)
    {
        $this->settings = $settings;
        $this->store = $store;
        $this->api = rtrim($apiBase ?: (getenv('AUTOIP_CLOUDFLARE_API') ?: 'https://api.cloudflare.com/client/v4'), '/');
    }

    // ------------------------------------------------------------------ settings

    public function config(): array
    {
        $token = (string) $this->settings->get(self::PREFIX . 'token', '');

        return [
            'token' => $token !== '' ? $this->decrypt($token) : '',
            'enabled' => (string) $this->settings->get(self::PREFIX . 'enabled', '0') === '1',
            'zone_id' => (string) $this->settings->get(self::PREFIX . 'zone_id', ''),
            'zone_name' => (string) $this->settings->get(self::PREFIX . 'zone_name', ''),
            'target_ip' => (string) $this->settings->get(self::PREFIX . 'target_ip', ''),
        ];
    }

    public function connected(): bool
    {
        return $this->config()['token'] !== '';
    }

    public function active(): bool
    {
        $c = $this->config();

        return $c['token'] !== '' && $c['enabled'] && $c['zone_id'] !== '' && $c['zone_name'] !== '';
    }

    public function tokenHint(): string
    {
        $t = $this->config()['token'];

        return $t === '' ? '' : '••••' . substr($t, -4);
    }

    /** verify a token, store it and return the domains it can see */
    public function connect(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new DisplayException('Paste your Cloudflare API token.');
        }
        $this->call($token, 'GET', '/user/tokens/verify');
        $zones = $this->listZones($token);
        $this->settings->set(self::PREFIX . 'token', $this->encrypt($token));

        return $zones;
    }

    public function zones(): array
    {
        $c = $this->config();

        return $c['token'] === '' ? [] : $this->listZones($c['token']);
    }

    public function save(string $zoneId, string $zoneName, bool $enabled, string $targetIp): void
    {
        $targetIp = trim($targetIp);
        if ($targetIp !== '' && !filter_var($targetIp, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $targetIp)) {
            throw new DisplayException('Public IP must be an IPv4 / IPv6 address or a hostname.');
        }
        if ($enabled && $zoneId === '') {
            throw new DisplayException('Select a domain first.');
        }
        $this->settings->set(self::PREFIX . 'zone_id', $zoneId);
        $this->settings->set(self::PREFIX . 'zone_name', $zoneName);
        $this->settings->set(self::PREFIX . 'enabled', $enabled ? '1' : '0');
        $this->settings->set(self::PREFIX . 'target_ip', $targetIp);
    }

    public function disconnect(): void
    {
        foreach (['token', 'enabled', 'zone_id', 'zone_name', 'target_ip'] as $k) {
            $this->settings->forget(self::PREFIX . $k);
        }
    }

    // ------------------------------------------------------------------ names

    /** "Survival SMP!" -> "survival-smp" */
    public static function label(string $name): string
    {
        $s = strtolower($name);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $s = strtolower($t);
            }
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');

        return rtrim(substr($s, 0, 50), '-');
    }

    public function hostnameFor(string $name): ?string
    {
        $label = self::label($name);
        $zone = $this->config()['zone_name'];

        return $label === '' || $zone === '' ? null : "{$label}.{$zone}";
    }

    /**
     * @throws DisplayException "Server name already exists" when the address is taken
     */
    public function assertAvailable(string $name, ?int $exceptServerId = null): ?string
    {
        if (!$this->active()) {
            return null;
        }
        $host = $this->hostnameFor($name);
        if ($host === null) {
            throw new DisplayException('Server name must contain letters or numbers (it becomes the server address).');
        }
        if ($exceptServerId !== null && $this->hostnameOf($exceptServerId) === $host) {
            return $host; // renamed but the address stays the same - it is already this server's
        }
        $owner = $this->store->serverWithHostname($host, $exceptServerId);
        if ($owner !== null) {
            throw new DisplayException("Server name already exists ({$host} is already used by another server). Choose another name.");
        }
        $c = $this->config();
        try {
            $r = $this->call($c['token'], 'GET', '/zones/' . rawurlencode($c['zone_id']) . '/dns_records?name=' . rawurlencode($host));
        } catch (DisplayException $e) {
            return $host; // Cloudflare unreachable - never block server creation because of that
        }
        if (!empty($r['result'])) {
            throw new DisplayException("Server name already exists ({$host} is already in your Cloudflare DNS). Choose another name.");
        }

        return $host;
    }

    // ------------------------------------------------------------------ records

    /**
     * Create the records for a server. Never throws - server creation must not fail on DNS.
     *
     * @param array $server [id, name, ip, ip_alias, port, minecraft(bool)]
     */
    public function register(array $server): array
    {
        try {
            if (!$this->active()) {
                return ['ok' => false, 'error' => 'Cloudflare DNS is not enabled'];
            }
            $c = $this->config();
            $host = $this->hostnameFor((string) $server['name']);
            if ($host === null) {
                return ['ok' => false, 'error' => 'invalid server name'];
            }
            $target = $c['target_ip'] !== '' ? $c['target_ip'] : (string) ($server['ip_alias'] ?: $server['ip']);
            if ($target === '' || self::isPrivate($target)) {
                return ['ok' => false, 'error' => "the server IP ({$target}) is private / local - set \"Public IP\" in Cloudflare DNS settings"];
            }

            $type = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'A' : (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'CNAME');
            $zone = '/zones/' . rawurlencode($c['zone_id']) . '/dns_records';
            $comment = 'Pterodactyl auto-ip server ' . $server['id'];
            $ids = [];

            $main = $this->call($c['token'], 'POST', $zone, [
                'type' => $type, 'name' => $host, 'content' => $target, 'ttl' => 1, 'proxied' => false, 'comment' => $comment,
            ]);
            $ids[] = $main['result']['id'];

            if (!empty($server['minecraft']) && !empty($server['port'])) {
                try {
                    $srv = $this->call($c['token'], 'POST', $zone, [
                        'type' => 'SRV', 'name' => "_minecraft._tcp.{$host}", 'ttl' => 1, 'comment' => $comment,
                        'data' => ['priority' => 0, 'weight' => 5, 'port' => (int) $server['port'], 'target' => $host],
                    ]);
                    $ids[] = $srv['result']['id'];
                } catch (\Throwable $e) {
                    // the A record alone still works (players then need the port)
                }
            }

            $this->store->put((int) $server['id'], $host, $c['zone_id'], $ids);

            return ['ok' => true, 'hostname' => $host, 'ids' => $ids];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** delete a server's records. Never throws. */
    public function remove(int $serverId): void
    {
        try {
            $row = $this->store->find($serverId);
            if ($row === null) {
                return;
            }
            $c = $this->config();
            if ($c['token'] !== '') {
                foreach ($row['record_ids'] as $id) {
                    try {
                        $this->call($c['token'], 'DELETE', '/zones/' . rawurlencode($row['zone_id'] ?: $c['zone_id']) . '/dns_records/' . rawurlencode($id));
                    } catch (\Throwable $e) {
                    }
                }
            }
            $this->store->delete($serverId);
        } catch (\Throwable $e) {
        }
    }

    public function hostnameOf(int $serverId): ?string
    {
        $row = $this->store->find($serverId);

        return $row['hostname'] ?? null;
    }

    // ------------------------------------------------------------------ helpers

    public static function isPrivate(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false; // hostname -> CNAME
        }
        if ($ip === '0.0.0.0' || $ip === '::') {
            return true;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // CGNAT 100.64.0.0/10
        return (bool) preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip);
    }

    private function listZones(string $token): array
    {
        $r = $this->call($token, 'GET', '/zones?per_page=50&status=active');
        $zones = [];
        foreach ($r['result'] ?? [] as $z) {
            $zones[] = ['id' => $z['id'], 'name' => $z['name']];
        }
        if (!$zones) {
            throw new DisplayException('This token cannot see any domain. Create it with the "Edit zone DNS" template (Zone - DNS - Edit, Zone - Zone - Read).');
        }

        return $zones;
    }

    /** @throws DisplayException */
    private function call(string $token, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->api . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new DisplayException("Cloudflare is unreachable from the panel ({$err}).");
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || $code >= 400 || ($json['success'] ?? true) === false) {
            $msg = $json['errors'][0]['message'] ?? "HTTP {$code}";
            throw new DisplayException("Cloudflare: {$msg}");
        }

        return $json;
    }

    private function encrypt(string $v): string
    {
        return function_exists('encrypt') ? encrypt($v) : $v;
    }

    private function decrypt(string $v): string
    {
        if (!function_exists('decrypt')) {
            return $v;
        }
        try {
            return (string) decrypt($v);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
