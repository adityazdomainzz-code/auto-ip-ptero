<?php

namespace Pterodactyl\Providers;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Services\AutoIp\RecordStore;
use Pterodactyl\Services\AutoIp\CloudflareDns;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Http\Controllers\Admin\AutoIpController;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

/**
 * auto-ip-ptero: Cloudflare DNS for every new server (Admin -> Cloudflare DNS).
 * https://github.com/adityazdomainzz-code/auto-ip-ptero
 */
class AutoIpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudflareDns::class, function ($app) {
            return new CloudflareDns($app->make(SettingsRepositoryInterface::class), new RecordStore());
        });
    }

    public function boot(): void
    {
        if (!$this->app->routesAreCached()) {
            Route::middleware(['web', 'auth.session', RequireTwoFactorAuthentication::class, AdminAuthenticate::class])
                ->prefix('/admin/cloudflare-dns')
                ->name('admin.autoip.')
                ->group(function () {
                    Route::get('/', [AutoIpController::class, 'index'])->name('index');
                    Route::post('/connect', [AutoIpController::class, 'connect'])->name('connect');
                    Route::post('/save', [AutoIpController::class, 'save'])->name('save');
                    Route::post('/disconnect', [AutoIpController::class, 'disconnect'])->name('disconnect');
                    Route::post('/sync', [AutoIpController::class, 'sync'])->name('sync');
                });
        }

        // name already used -> stop the server from being created (shown as a red alert in the panel)
        Server::creating(function (Server $server) {
            $this->dns()->assertAvailable((string) $server->name);
        });

        Server::created(function (Server $server) {
            $this->afterCommit($server, fn () => $this->registerFor($server->id));
        });

        // renamed -> new address
        Server::updating(function (Server $server) {
            if ($server->isDirty('name')) {
                $this->dns()->assertAvailable((string) $server->name, (int) $server->id);
            }
        });

        Server::updated(function (Server $server) {
            if ($server->wasChanged('name') && $this->dns()->hostnameFor((string) $server->name) !== $this->dns()->hostnameOf((int) $server->id)) {
                $this->afterCommit($server, function () use ($server) {
                    $this->dns()->remove((int) $server->id);
                    $this->registerFor($server->id);
                });
            }
        });

        Server::deleted(function (Server $server) {
            $this->dns()->remove((int) $server->id);
        });
    }

    private function dns(): CloudflareDns
    {
        return $this->app->make(CloudflareDns::class);
    }

    /** run after the database transaction is committed (or right away if there is none) */
    private function afterCommit(Server $server, callable $fn): void
    {
        $run = function () use ($fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::warning('[auto-ip-ptero] ' . $e->getMessage());
            }
        };
        try {
            $server->getConnection()->afterCommit($run);
        } catch (\Throwable $e) {
            $run();
        }
    }

    public function registerFor(int $serverId): array
    {
        $server = Server::query()->with(['allocation', 'egg.nest'])->find($serverId);
        if (!$server) {
            return ['ok' => false, 'error' => 'server not found'];
        }
        $result = $this->dns()->register(self::serverData($server));
        if (!$result['ok']) {
            Log::warning("[auto-ip-ptero] DNS for server {$server->id} ({$server->name}) not created: " . $result['error']);
        }

        return $result;
    }

    public static function serverData(Server $server): array
    {
        $alloc = $server->allocation;
        $egg = $server->egg;
        $text = strtolower(($egg->name ?? '') . ' ' . ($egg->nest->name ?? '') . ' ' . ($egg->docker_image ?? ''));
        $minecraft = (bool) preg_match('/minecraft|paper|spigot|bukkit|forge|fabric|purpur|velocity|bungee|waterfall|sponge|vanilla|folia/', $text)
            && !str_contains($text, 'bedrock') && !str_contains($text, 'pocketmine') && !str_contains($text, 'nukkit');

        return [
            'id' => $server->id,
            'name' => $server->name,
            'ip' => $alloc->ip ?? '',
            'ip_alias' => $alloc->ip_alias ?? '',
            'port' => $alloc->port ?? null,
            'minecraft' => $minecraft,
        ];
    }
}
