<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'vlan_ids' => 'array',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Normalize any array or comma/space-separated string of VLAN numbers into unique sorted ints (1..4094).
     *
     * @param array<int,mixed>|string|null $raw
     * @return list<int>
     */
    public static function normalizeVlans(array|string|null $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $vlans = [];
        foreach ($items ?: [] as $item) {
            if (is_numeric($item)) {
                $v = (int) $item;
                if ($v >= 1 && $v <= 4094) {
                    $vlans[] = $v;
                }
            }
        }
        $vlans = array_values(array_unique($vlans));
        sort($vlans);

        return $vlans;
    }

    /**
     * Collect all VLAN IDs belonging to an ONU (combining scalar vlan_id and json vlan_ids).
     *
     * @return list<int>
     */
    public static function onuVlans(Onu $onu): array
    {
        $list = is_array($onu->vlan_ids) ? $onu->vlan_ids : [];
        if ($onu->vlan_id !== null) {
            $list[] = (int) $onu->vlan_id;
        }

        return self::normalizeVlans($list);
    }

    public function matchesOnu(Onu $onu): bool
    {
        $groupVlans = self::normalizeVlans($this->vlan_ids ?? []);
        if (! $groupVlans) {
            return false;
        }

        return count(array_intersect($groupVlans, self::onuVlans($onu))) > 0;
    }

    /**
     * Filter an Onu query builder to rows matching any VLAN in $vlanIds.
     *
     * @param list<int> $vlanIds
     */
    public static function applyVlanFilter(Builder $query, array $vlanIds): void
    {
        $vlanIds = self::normalizeVlans($vlanIds);
        if (! $vlanIds) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $w) use ($vlanIds) {
            $w->whereIn('onus.vlan_id', $vlanIds);
            foreach ($vlanIds as $vid) {
                $w->orWhereJsonContains('onus.vlan_ids', $vid);
            }
        });
    }

    /**
     * Filter an Onu query builder to rows matching NONE of the tenant's grouped VLANs.
     *
     * @param list<int> $allGroupedVlans
     */
    public static function applyUngroupedFilter(Builder $query, array $allGroupedVlans): void
    {
        $allGroupedVlans = self::normalizeVlans($allGroupedVlans);
        if (! $allGroupedVlans) {
            return;
        }

        $query->where(function (Builder $w) use ($allGroupedVlans) {
            $w->whereNull('onus.vlan_id')->orWhereNotIn('onus.vlan_id', $allGroupedVlans);
        });

        foreach ($allGroupedVlans as $vid) {
            $query->where(function (Builder $w) use ($vid) {
                $w->whereNull('onus.vlan_ids')->orWhereJsonDoesntContain('onus.vlan_ids', $vid);
            });
        }
    }
}
