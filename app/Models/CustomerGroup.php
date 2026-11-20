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
            'name_keywords' => 'array',
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
     * Normalize comma/semicolon/newline-separated ONU name/description keywords.
     *
     * @param array<int,mixed>|string|null $raw
     * @return list<string>
     */
    public static function normalizeKeywords(array|string|null $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/[,;\r\n]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $seen = [];
        $keywords = [];
        foreach ($items ?: [] as $item) {
            $kw = trim((string) $item);
            if ($kw === '') {
                continue;
            }
            $kw = mb_substr($kw, 0, 60);
            $key = mb_strtolower($kw);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keywords[] = $kw;
            if (count($keywords) >= 30) {
                break;
            }
        }

        return $keywords;
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
        if ($groupVlans && count(array_intersect($groupVlans, self::onuVlans($onu))) > 0) {
            return true;
        }

        $keywords = self::normalizeKeywords($this->name_keywords ?? []);
        if ($keywords) {
            $haystackName = (string) ($onu->name ?? '');
            $haystackDesc = (string) ($onu->description ?? '');
            foreach ($keywords as $kw) {
                if (($haystackName !== '' && mb_stripos($haystackName, $kw) !== false)
                    || ($haystackDesc !== '' && mb_stripos($haystackDesc, $kw) !== false)) {
                    return true;
                }
            }
        }

        return false;
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
     * Filter an Onu query builder to rows matching a CustomerGroup's VLANs and/or ONU name keywords.
     */
    public static function applyGroupFilter(Builder $query, ?self $group): void
    {
        if (! $group) {
            $query->whereRaw('1 = 0');

            return;
        }

        $vlanIds = self::normalizeVlans($group->vlan_ids ?? []);
        $keywords = self::normalizeKeywords($group->name_keywords ?? []);

        if (! $vlanIds && ! $keywords) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $w) use ($vlanIds, $keywords) {
            $first = true;

            if ($vlanIds) {
                $w->whereIn('onus.vlan_id', $vlanIds);
                foreach ($vlanIds as $vid) {
                    $w->orWhereJsonContains('onus.vlan_ids', $vid);
                }
                $first = false;
            }

            foreach ($keywords as $kw) {
                $pattern = '%'.self::escapeLike(mb_strtolower($kw)).'%';
                if ($first) {
                    $w->whereRaw("LOWER(COALESCE(onus.name, '')) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(COALESCE(onus.description, '')) LIKE ? ESCAPE '!'", [$pattern]);
                    $first = false;
                } else {
                    $w->orWhereRaw("LOWER(COALESCE(onus.name, '')) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(COALESCE(onus.description, '')) LIKE ? ESCAPE '!'", [$pattern]);
                }
            }
        });
    }

    /**
     * Filter an Onu query builder to rows matching NONE of the tenant's groups (neither VLAN nor name keywords).
     *
     * @param iterable<self> $groups
     */
    public static function applyUngroupedFilter(Builder $query, iterable $groups): void
    {
        $allVlans = [];
        $allKeywords = [];

        foreach ($groups as $g) {
            if ($g instanceof self) {
                $allVlans = array_merge($allVlans, self::normalizeVlans($g->vlan_ids ?? []));
                $allKeywords = array_merge($allKeywords, self::normalizeKeywords($g->name_keywords ?? []));
            } elseif (is_numeric($g)) {
                $allVlans[] = (int) $g;
            }
        }

        $allVlans = self::normalizeVlans($allVlans);
        $allKeywords = self::normalizeKeywords($allKeywords);

        if ($allVlans) {
            $query->where(function (Builder $w) use ($allVlans) {
                $w->whereNull('onus.vlan_id')->orWhereNotIn('onus.vlan_id', $allVlans);
            });

            foreach ($allVlans as $vid) {
                $query->where(function (Builder $w) use ($vid) {
                    $w->whereNull('onus.vlan_ids')->orWhereJsonDoesntContain('onus.vlan_ids', $vid);
                });
            }
        }

        foreach ($allKeywords as $kw) {
            $pattern = '%'.self::escapeLike(mb_strtolower($kw)).'%';
            $query->whereRaw("LOWER(COALESCE(onus.name, '')) NOT LIKE ? ESCAPE '!'", [$pattern])
                ->whereRaw("LOWER(COALESCE(onus.description, '')) NOT LIKE ? ESCAPE '!'", [$pattern]);
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
