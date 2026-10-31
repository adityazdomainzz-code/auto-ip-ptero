<?php

namespace Pterodactyl\BlueprintFramework\Extensions\mcmanager;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;

/**
 * MC Manager - plugin manager (Modrinth / Hangar / Spigot) + player manager for Minecraft servers.
 * Download URLs are never taken from the browser: the panel looks them up itself from the project id,
 * and only files from the known plugin CDNs are written into the server.
 */
class ManagerController extends Controller
{
    private const UA = 'auto-ip-ptero/mcmanager (Pterodactyl extension)';
    private const MAX_BYTES = 104857600; // 100 MB
    private const DOWNLOAD_HOSTS = ['cdn.modrinth.com', 'hangarcdn.papermc.io', 'api.spiget.org', 'cdn.spiget.org'];
    private const PROXY_LOADERS = ['velocity', 'bungeecord', 'waterfall'];
    private const MOD_LOADERS = ['fabric', 'forge', 'neoforge', 'quilt'];

    public function __construct(private DaemonFileRepository $files, private DaemonCommandRepository $commands)
    {
    }

    // =====================================================================  helpers

    private function server(Request $request, string $permission): Server
    {
        abort_unless($request->user(), 401, 'Not logged in.');
        $uuid = (string) $request->input('server', '');
        $server = Server::query()->where('uuid', $uuid)->orWhere('uuidShort', $uuid)->firstOrFail();
        $this->authorize($permission, $server);

        return $server;
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }

    private function getJson(string $url, array $query = []): array
    {
        $r = Http::withUserAgent(self::UA)->acceptJson()->timeout(15)->get($url, $query);
        if (!$r->successful()) {
            throw new \RuntimeException('Plugin site answered HTTP ' . $r->status());
        }

        return $r->json() ?? [];
    }

    /** loader + Minecraft version of a server, guessed from its egg and variables */
    private function detect(Server $server): array
    {
        $egg = strtolower(($server->egg->name ?? '') . ' ' . ($server->nest->name ?? ''));
        $loader = 'paper';
        foreach (['purpur', 'folia', 'paper', 'spigot', 'bukkit', 'velocity', 'bungeecord', 'waterfall', 'neoforge', 'fabric', 'quilt', 'forge'] as $l) {
            if (str_contains($egg, $l)) {
                $loader = $l;
                break;
            }
        }
        if (str_contains($egg, 'bungee')) {
            $loader = 'bungeecord';
        }

        $version = '';
        foreach ($server->variables as $v) {
            if (in_array(strtoupper($v->env_variable), ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION', 'VERSION'], true)) {
                $val = trim((string) ($v->server_value ?? $v->default_value));
                if (preg_match('/^\d+\.\d+(\.\d+)?$/', $val)) {
                    $version = $val;
                    break;
                }
            }
        }
        $folder = in_array($loader, self::MOD_LOADERS, true) ? '/mods' : '/plugins';

        return ['loader' => $loader, 'version' => $version, 'folder' => $folder, 'proxy' => in_array($loader, self::PROXY_LOADERS, true)];
    }

    private function safeFile(string $name): ?string
    {
        $name = basename(str_replace('\\', '/', $name));

        return preg_match('/^[\w.\-+ ()\[\]]{1,120}\.jar(\.disabled)?$/i', $name) ? $name : null;
    }

    private function hostAllowed(string $url): bool
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? '') === 'https' && in_array(strtolower($p['host'] ?? ''), self::DOWNLOAD_HOSTS, true);
    }

    // =====================================================================  plugins

    public function info(Request $request): JsonResponse
    {
        $server = $this->server($request, 'file.read');

        return response()->json(['success' => true, 'data' => $this->detect($server)]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->server($request, 'file.read');
        $source = (string) $request->input('source', 'modrinth');
        $q = mb_substr(trim((string) $request->input('q', '')), 0, 80);
        $page = max(0, (int) $request->input('page', 0));
        $loader = preg_replace('/[^a-z]/', '', strtolower((string) $request->input('loader', '')));
        $version = preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $request->input('version')) ? (string) $request->input('version') : '';
        $limit = 20;

        try {
            $items = [];
            if ($source === 'modrinth') {
                $type = in_array($loader, self::MOD_LOADERS, true) ? 'mod' : 'plugin';
                $facets = [["project_type:{$type}"]];
                if ($loader !== '') {
                    $facets[] = ["categories:{$loader}"];
                }
                if ($version !== '') {
                    $facets[] = ["versions:{$version}"];
                }
                $j = $this->getJson('https://api.modrinth.com/v2/search', [
                    'query' => $q, 'limit' => $limit, 'offset' => $page * $limit, 'index' => $q === '' ? 'downloads' : 'relevance', 'facets' => json_encode($facets),
                ]);
                foreach ($j['hits'] ?? [] as $h) {
                    $items[] = ['id' => $h['project_id'], 'name' => $h['title'], 'description' => $h['description'] ?? '', 'author' => $h['author'] ?? '', 'downloads' => $h['downloads'] ?? 0, 'icon' => $h['icon_url'] ?? '', 'url' => 'https://modrinth.com/' . ($h['project_type'] ?? 'plugin') . '/' . ($h['slug'] ?? $h['project_id'])];
                }
            } elseif ($source === 'hangar') {
                $platform = $loader === 'velocity' ? 'VELOCITY' : (in_array($loader, ['bungeecord', 'waterfall'], true) ? 'WATERFALL' : 'PAPER');
                $query = ['q' => $q, 'limit' => $limit, 'offset' => $page * $limit, 'platform' => $platform, 'sort' => $q === '' ? '-downloads' : ''];
                if ($version !== '') {
                    $query['version'] = $version;
                }
                $j = $this->getJson('https://hangar.papermc.io/api/v1/projects', array_filter($query, fn ($v) => $v !== ''));
                foreach ($j['result'] ?? [] as $p) {
                    $slug = $p['namespace']['slug'] ?? $p['name'];
                    $owner = $p['namespace']['owner'] ?? '';
                    $items[] = ['id' => "{$owner}/{$slug}", 'name' => $p['name'], 'description' => $p['description'] ?? '', 'author' => $owner, 'downloads' => $p['stats']['downloads'] ?? 0, 'icon' => $p['avatarUrl'] ?? '', 'url' => "https://hangar.papermc.io/{$owner}/{$slug}"];
                }
            } elseif ($source === 'spigot') {
                $url = $q === ''
                    ? 'https://api.spiget.org/v2/resources/free'
                    : 'https://api.spiget.org/v2/search/resources/' . rawurlencode($q);
                $query = ['size' => $limit, 'page' => $page + 1, 'sort' => '-downloads', 'fields' => 'id,name,tag,downloads,icon,premium,external,file'];
                if ($q !== '') {
                    $query['field'] = 'name';
                }
                $j = $this->getJson($url, $query);
                foreach ($j as $p) {
                    if (!empty($p['premium'])) {
                        continue;
                    }
                    $items[] = ['id' => (string) $p['id'], 'name' => $p['name'], 'description' => $p['tag'] ?? '', 'author' => '', 'downloads' => $p['downloads'] ?? 0, 'icon' => !empty($p['icon']['url']) ? 'https://www.spigotmc.org/' . $p['icon']['url'] : '', 'url' => 'https://www.spigotmc.org/resources/' . $p['id'], 'external' => !empty($p['external'])];
                }
            } else {
                return $this->fail('Unknown source.');
            }

            return response()->json(['success' => true, 'data' => $items]);
        } catch (\Throwable $e) {
            return $this->fail('Search failed: ' . $e->getMessage(), 502);
        }
    }

    /** list downloadable versions; ids only - the URL is resolved again on install */
    private function listVersions(string $source, string $project, string $loader, string $version): array
    {
        $out = [];
        if ($source === 'modrinth') {
            abort_unless((bool) preg_match('/^[A-Za-z0-9]{1,64}$/', $project), 422, 'Bad project id.');
            $query = [];
            if ($loader !== '') {
                $query['loaders'] = json_encode(in_array($loader, ['purpur', 'folia'], true) ? [$loader, 'paper', 'spigot', 'bukkit'] : ($loader === 'paper' ? ['paper', 'spigot', 'bukkit'] : [$loader]));
            }
            if ($version !== '') {
                $query['game_versions'] = json_encode([$version]);
            }
            foreach (array_slice($this->getJson("https://api.modrinth.com/v2/project/{$project}/version", $query), 0, 15) as $v) {
                $file = collect($v['files'] ?? [])->firstWhere('primary', true) ?? ($v['files'][0] ?? null);
                if (!$file) {
                    continue;
                }
                $out[] = ['id' => $v['id'], 'name' => $v['version_number'] ?? $v['name'], 'game' => implode(', ', array_slice($v['game_versions'] ?? [], -4)), 'file' => $file['filename'], 'url' => $file['url'], 'size' => $file['size'] ?? 0];
            }
        } elseif ($source === 'hangar') {
            abort_unless((bool) preg_match('#^[\w.-]{1,64}/[\w.-]{1,64}$#', $project), 422, 'Bad project id.');
            [$owner, $slug] = explode('/', $project);
            $platform = $loader === 'velocity' ? 'VELOCITY' : (in_array($loader, ['bungeecord', 'waterfall'], true) ? 'WATERFALL' : 'PAPER');
            $query = ['limit' => 15, 'offset' => 0, 'platform' => $platform];
            try {
                $j = $this->getJson("https://hangar.papermc.io/api/v1/projects/{$slug}/versions", $query);
            } catch (\Throwable $e) {
                $j = $this->getJson("https://hangar.papermc.io/api/v1/projects/{$owner}/{$slug}/versions", $query);
            }
            foreach ($j['result'] ?? [] as $v) {
                $d = $v['downloads'][$platform] ?? null;
                if (!$d || empty($d['downloadUrl'])) {
                    continue; // external downloads cannot be installed safely
                }
                $games = $v['platformDependencies'][$platform] ?? [];
                if ($version !== '' && $games && !in_array($version, $games, true) && !in_array(implode('.', array_slice(explode('.', $version), 0, 2)), $games, true)) {
                    continue;
                }
                $out[] = ['id' => $v['name'], 'name' => $v['name'], 'game' => implode(', ', array_slice($games, -4)), 'file' => $d['fileInfo']['name'] ?? ($slug . '-' . $v['name'] . '.jar'), 'url' => $d['downloadUrl'], 'size' => $d['fileInfo']['sizeBytes'] ?? 0];
            }
        } elseif ($source === 'spigot') {
            abort_unless((bool) preg_match('/^\d{1,9}$/', $project), 422, 'Bad project id.');
            $res = $this->getJson("https://api.spiget.org/v2/resources/{$project}");
            if (!empty($res['premium'])) {
                abort(422, 'Premium plugins cannot be installed here.');
            }
            if (!empty($res['external']) || ($res['file']['type'] ?? '.jar') !== '.jar') {
                abort(422, 'This plugin is hosted outside SpigotMC - download it from its own page.');
            }
            $name = preg_replace('/[^\w.\-]+/', '', str_replace(' ', '-', $res['name'] ?? "spigot-{$project}"));
            $out[] = ['id' => 'latest', 'name' => 'Latest', 'game' => implode(', ', array_slice($res['testedVersions'] ?? [], -4)), 'file' => "{$name}.jar", 'url' => "https://api.spiget.org/v2/resources/{$project}/download", 'size' => (int) (($res['file']['size'] ?? 0) * (($res['file']['sizeUnit'] ?? '') === 'MB' ? 1048576 : 1024))];
        }

        return $out;
    }

    public function versions(Request $request): JsonResponse
    {
        $server = $this->server($request, 'file.read');
        $d = $this->detect($server);
        try {
            $list = $this->listVersions((string) $request->input('source'), (string) $request->input('project'), (string) $request->input('loader', $d['loader']), (string) $request->input('version', ''));

            return response()->json(['success' => true, 'data' => array_map(fn ($v) => array_diff_key($v, ['url' => 1]), $list)]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('Could not load versions: ' . $e->getMessage(), 502);
        }
    }

    public function install(Request $request): JsonResponse
    {
        $server = $this->server($request, 'file.create');
        $d = $this->detect($server);
        $wanted = (string) $request->input('versionId', '');
        try {
            $list = $this->listVersions((string) $request->input('source'), (string) $request->input('project'), (string) $request->input('loader', $d['loader']), (string) $request->input('version', ''));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('Could not load versions: ' . $e->getMessage(), 502);
        }
        $pick = collect($list)->firstWhere('id', $wanted) ?? ($wanted === '' ? ($list[0] ?? null) : null);
        if (!$pick) {
            return $this->fail('That version was not found.');
        }
        $file = $this->safeFile((string) $pick['file']);
        if (!$file || !$this->hostAllowed($pick['url'])) {
            return $this->fail('This download is not allowed.');
        }

        try {
            $r = Http::withUserAgent(self::UA)->timeout(90)
                ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https'], 'on_redirect' => function ($req, $res, $uri) {
                    if (!in_array(strtolower($uri->getHost()), self::DOWNLOAD_HOSTS, true)) {
                        throw new \RuntimeException('download redirected to an unknown site');
                    }
                }]])
                ->get($pick['url']);
            if (!$r->successful()) {
                return $this->fail('Download failed (HTTP ' . $r->status() . ').', 502);
            }
            $body = $r->body();
            if (strlen($body) > self::MAX_BYTES) {
                return $this->fail('File is larger than 100 MB.');
            }
            if (substr($body, 0, 2) !== 'PK') {
                return $this->fail('The download is not a .jar file (the plugin may need to be downloaded from its website).');
            }
            $this->files->setServer($server)->putContent($d['folder'] . '/' . $file, $body);
        } catch (\Throwable $e) {
            return $this->fail('Install failed: ' . $e->getMessage(), 502);
        }

        return response()->json(['success' => true, 'message' => "Installed {$file} into {$d['folder']}. Restart the server to load it.", 'file' => $file]);
    }

    public function installed(Request $request): JsonResponse
    {
        $server = $this->server($request, 'file.read');
        $d = $this->detect($server);
        try {
            $list = $this->files->setServer($server)->getDirectory($d['folder']);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => [], 'folder' => $d['folder'], 'note' => 'Folder not found yet - start the server once.']);
        }
        $out = [];
        foreach ($list as $f) {
            $name = $f['name'] ?? '';
            if (!($f['file'] ?? false) || !preg_match('/\.jar(\.disabled)?$/i', $name)) {
                continue;
            }
            $out[] = ['file' => $name, 'size' => $f['size'] ?? 0, 'enabled' => !str_ends_with(strtolower($name), '.disabled'), 'modified' => $f['modified'] ?? null];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['file'], $b['file']));

        return response()->json(['success' => true, 'data' => $out, 'folder' => $d['folder']]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $server = $this->server($request, 'file.update');
        $d = $this->detect($server);
        $file = $this->safeFile((string) $request->input('file'));
        if (!$file) {
            return $this->fail('Invalid file name.');
        }
        $to = str_ends_with(strtolower($file), '.disabled') ? substr($file, 0, -9) : $file . '.disabled';
        try {
            $this->files->setServer($server)->renameFiles($d['folder'], [['from' => $file, 'to' => $to]]);
        } catch (\Throwable $e) {
            return $this->fail('Rename failed: ' . $e->getMessage(), 502);
        }

        return response()->json(['success' => true, 'file' => $to]);
    }

    public function remove(Request $request): JsonResponse
    {
        $server = $this->server($request, 'file.delete');
        $d = $this->detect($server);
        $file = $this->safeFile((string) $request->input('file'));
        if (!$file) {
            return $this->fail('Invalid file name.');
        }
        try {
            $this->files->setServer($server)->deleteFiles($d['folder'], [$file]);
        } catch (\Throwable $e) {
            return $this->fail('Delete failed: ' . $e->getMessage(), 502);
        }

        return response()->json(['success' => true]);
    }

    // =====================================================================  players

    /** Minecraft "server list ping" - online count + up to 12 player names */
    private function ping(string $host, int $port): ?array
    {
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $err, 3);
        if (!$fp) {
            return null;
        }
        stream_set_timeout($fp, 3);
        $varint = function (int $v): string {
            $o = '';
            do {
                $b = $v & 0x7F;
                $v >>= 7;
                $o .= chr($v ? $b | 0x80 : $b);
            } while ($v);

            return $o;
        };
        $data = "\x00" . $varint(760) . $varint(strlen($host)) . $host . pack('n', $port) . "\x01";
        fwrite($fp, $varint(strlen($data)) . $data . "\x01\x00");
        $readVar = function () use ($fp): int {
            $v = 0;
            for ($i = 0; $i < 5; $i++) {
                $c = fgetc($fp);
                if ($c === false) {
                    return -1;
                }
                $c = ord($c);
                $v |= ($c & 0x7F) << (7 * $i);
                if (!($c & 0x80)) {
                    return $v;
                }
            }

            return -1;
        };
        $len = $readVar();
        $readVar(); // packet id
        $strLen = $readVar();
        if ($len < 0 || $strLen <= 0 || $strLen > 1048576) {
            fclose($fp);

            return null;
        }
        $json = '';
        while (strlen($json) < $strLen && !feof($fp)) {
            $chunk = fread($fp, $strLen - strlen($json));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $json .= $chunk;
        }
        fclose($fp);

        return json_decode($json, true) ?: null;
    }

    private function readJsonFile(Server $server, string $path): array
    {
        try {
            $c = $this->files->setServer($server)->getContent($path, 5 * 1048576);

            return json_decode($c, true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function players(Request $request): JsonResponse
    {
        $server = $this->server($request, 'control.console');
        $alloc = $server->allocation;
        $host = (!$alloc || in_array($alloc->ip, ['0.0.0.0', '::', ''], true)) ? '127.0.0.1' : $alloc->ip;
        $status = $alloc ? $this->ping($host, (int) $alloc->port) : null;
        if (!$status && $host !== '127.0.0.1' && $alloc) {
            $status = $this->ping('127.0.0.1', (int) $alloc->port);
        }

        $props = '';
        try {
            $props = $this->files->setServer($server)->getContent('/server.properties', 1048576);
        } catch (\Throwable $e) {
        }

        return response()->json(['success' => true, 'data' => [
            'online' => $status !== null,
            'count' => $status['players']['online'] ?? 0,
            'max' => $status['players']['max'] ?? 0,
            'players' => array_values(array_map(fn ($p) => ['name' => $p['name'] ?? '', 'uuid' => $p['id'] ?? ''], array_filter($status['players']['sample'] ?? [], fn ($p) => !empty($p['name']) && !str_contains($p['name'], '§')))),
            'ops' => array_values(array_map(fn ($p) => ['name' => $p['name'] ?? '', 'uuid' => $p['uuid'] ?? ''], $this->readJsonFile($server, '/ops.json'))),
            'banned' => array_values(array_map(fn ($p) => ['name' => $p['name'] ?? '', 'uuid' => $p['uuid'] ?? '', 'reason' => $p['reason'] ?? ''], $this->readJsonFile($server, '/banned-players.json'))),
            'bannedIps' => array_values(array_map(fn ($p) => ['ip' => $p['ip'] ?? '', 'reason' => $p['reason'] ?? ''], $this->readJsonFile($server, '/banned-ips.json'))),
            'whitelist' => array_values(array_map(fn ($p) => ['name' => $p['name'] ?? '', 'uuid' => $p['uuid'] ?? ''], $this->readJsonFile($server, '/whitelist.json'))),
            'whitelistOn' => (bool) preg_match('/^\s*(white-list|enforce-whitelist)\s*=\s*true/m', $props),
        ]]);
    }

    public function action(Request $request): JsonResponse
    {
        $server = $this->server($request, 'control.console');
        $action = (string) $request->input('action');
        $name = trim((string) $request->input('name', ''));
        $reason = trim(preg_replace('/[\r\n\x00-\x1F]+/', ' ', mb_substr((string) $request->input('reason', ''), 0, 100)));

        $needsName = !in_array($action, ['whitelist-on', 'whitelist-off', 'whitelist-reload', 'save'], true);
        if ($needsName && !preg_match('/^\.?[A-Za-z0-9_]{1,16}$/', $name)) {
            return $this->fail('Invalid player name.');
        }
        $map = [
            'kick' => "kick {$name}" . ($reason !== '' ? " {$reason}" : ''),
            'ban' => "ban {$name}" . ($reason !== '' ? " {$reason}" : ''),
            'pardon' => "pardon {$name}",
            'op' => "op {$name}",
            'deop' => "deop {$name}",
            'whitelist-add' => "whitelist add {$name}",
            'whitelist-remove' => "whitelist remove {$name}",
            'whitelist-on' => 'whitelist on',
            'whitelist-off' => 'whitelist off',
            'whitelist-reload' => 'whitelist reload',
            'save' => 'save-all',
        ];
        if (!isset($map[$action])) {
            return $this->fail('Unknown action.');
        }
        try {
            $this->commands->setServer($server)->send($map[$action]);
        } catch (\Throwable $e) {
            return $this->fail('The server must be running for this (' . $e->getMessage() . ').', 409);
        }

        return response()->json(['success' => true, 'message' => "Sent: /{$map[$action]}"]);
    }
}
