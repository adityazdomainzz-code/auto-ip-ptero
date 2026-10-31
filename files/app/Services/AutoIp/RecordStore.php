<?php

namespace Pterodactyl\Services\AutoIp;

use Illuminate\Support\Facades\DB;

/** remembers which DNS records belong to which server (table: autoip_dns_records) */
class RecordStore
{
    public const TABLE = 'autoip_dns_records';

    public function find(int $serverId): ?array
    {
        $row = DB::table(self::TABLE)->where('server_id', $serverId)->first();
        if (!$row) {
            return null;
        }

        return [
            'server_id' => (int) $row->server_id,
            'hostname' => $row->hostname,
            'zone_id' => $row->zone_id,
            'record_ids' => json_decode($row->record_ids, true) ?: [],
        ];
    }

    public function serverWithHostname(string $hostname, ?int $exceptServerId = null): ?int
    {
        $q = DB::table(self::TABLE)->where('hostname', $hostname);
        if ($exceptServerId !== null) {
            $q->where('server_id', '!=', $exceptServerId);
        }
        $row = $q->first();

        return $row ? (int) $row->server_id : null;
    }

    public function put(int $serverId, string $hostname, string $zoneId, array $ids): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['server_id' => $serverId],
            ['hostname' => $hostname, 'zone_id' => $zoneId, 'record_ids' => json_encode(array_values($ids)), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function delete(int $serverId): void
    {
        DB::table(self::TABLE)->where('server_id', $serverId)->delete();
    }

    public function all(): array
    {
        return DB::table(self::TABLE)->pluck('hostname', 'server_id')->all();
    }
}
