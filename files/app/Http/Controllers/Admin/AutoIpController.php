<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\AutoIp\RecordStore;
use Pterodactyl\Services\AutoIp\CloudflareDns;
use Pterodactyl\Providers\AutoIpServiceProvider;

/** Admin -> Cloudflare DNS (auto-ip-ptero) */
class AutoIpController extends Controller
{
    public function __construct(private AlertsMessageBag $alert, private CloudflareDns $dns)
    {
    }

    public function index(): View
    {
        $zones = [];
        $zoneError = null;
        if ($this->dns->connected()) {
            try {
                $zones = $this->dns->zones();
            } catch (DisplayException $e) {
                $zoneError = $e->getMessage();
            }
        }
        $records = (new RecordStore())->all();
        $missing = Server::query()->whereNotIn('id', array_keys($records))->count();

        return view('admin.auto-ip.index', [
            'config' => $this->dns->config(),
            'connected' => $this->dns->connected(),
            'active' => $this->dns->active(),
            'tokenHint' => $this->dns->tokenHint(),
            'zones' => $zones,
            'zoneError' => $zoneError,
            'records' => $records,
            'servers' => Server::query()->whereIn('id', array_keys($records))->pluck('name', 'id')->all(),
            'missing' => $missing,
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $zones = $this->dns->connect((string) $request->input('token'));
        if (count($zones) === 1) {
            $c = $this->dns->config();
            $this->dns->save($zones[0]['id'], $zones[0]['name'], true, $c['target_ip']);
            $this->alert->success("Connected. Domain {$zones[0]['name']} selected and automatic DNS is on.")->flash();
        } else {
            $this->alert->success('Connected - ' . count($zones) . ' domains found. Pick one and save.')->flash();
        }

        return redirect()->route('admin.autoip.index');
    }

    public function save(Request $request): RedirectResponse
    {
        $zoneId = (string) $request->input('zone_id');
        $zoneName = '';
        foreach ($this->dns->zones() as $z) {
            if ($z['id'] === $zoneId) {
                $zoneName = $z['name'];
            }
        }
        if ($zoneId !== '' && $zoneName === '') {
            throw new DisplayException('That domain is not visible to this token.');
        }
        $enabled = $request->boolean('enabled');
        $this->dns->save($zoneId, $zoneName, $enabled, (string) $request->input('target_ip'));
        $this->alert->success($enabled ? "Saved - new servers get <name>.{$zoneName}" : 'Saved - automatic DNS is off.')->flash();

        return redirect()->route('admin.autoip.index');
    }

    public function disconnect(): RedirectResponse
    {
        $this->dns->disconnect();
        $this->alert->success('Cloudflare disconnected. Existing DNS records were not touched.')->flash();

        return redirect()->route('admin.autoip.index');
    }

    /** existing servers: create missing addresses + show the address in the panel instead of the IP */
    public function sync(): RedirectResponse
    {
        if (!$this->dns->active()) {
            throw new DisplayException('Connect Cloudflare, pick a domain and enable it first.');
        }
        $records = (new RecordStore())->all();
        $provider = app()->getProvider(AutoIpServiceProvider::class);
        $created = 0;
        $updated = 0;
        $failed = [];
        foreach (Server::query()->get() as $server) {
            if (isset($records[$server->id])) {
                if ($provider->applyAlias((int) $server->id)) {
                    $updated++;
                } else {
                    $failed[] = "{$server->name}: no allocation";
                }
                continue;
            }
            try {
                $this->dns->assertAvailable((string) $server->name, (int) $server->id);
            } catch (DisplayException $e) {
                $failed[] = "{$server->name}: name already used";
                continue;
            }
            $r = $provider->registerFor((int) $server->id);
            if ($r['ok']) {
                $created++;
            } else {
                $failed[] = "{$server->name}: {$r['error']}";
            }
        }
        $this->alert->success("Done: {$created} new address(es) created, {$updated} existing server(s) now show their address in the panel.")->flash();
        if ($failed) {
            $this->alert->warning('Skipped: ' . implode(' | ', array_slice($failed, 0, 8)))->flash();
        }

        return redirect()->route('admin.autoip.index');
    }
}
