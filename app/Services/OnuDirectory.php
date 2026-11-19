<?php

namespace App\Services;

use App\Models\{CustomerGroup, Onu};
use Illuminate\Http\Request;

class OnuDirectory
{
    public function listing(Request $r): array
    {
        $d = $r->validate([
            'olt_id' => 'nullable|integer',
            'port_id' => 'nullable|integer',
            'customer_group_id' => 'nullable|string|max:24',
            'vlan_id' => 'nullable|integer|min:1|max:4094',
            'q' => 'nullable|string|max:120',
            'status' => 'nullable|in:working,offline,los,dying-gasp,rebooting,sync-mib,logging,auth,poweroff,restorefail,nonworking',
            'sort' => 'nullable|in:name,sn,rx_power,last_online_at,phase_state,onu_index,position',
            'direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
            'degradation' => 'nullable|boolean',
        ]);
        $tenantId = $r->user()->tenant_id;
        $tenantGroups = CustomerGroup::where('tenant_id', $tenantId)->orderBy('name')->get();
        $q = Onu::current()->where('tenant_id', $tenantId);
        foreach (['olt_id' => 'olt_id', 'port_id' => 'olt_port_id'] as $k => $field) {
            if (! empty($d[$k])) {
                $q->where($field, $d[$k]);
            }
        }
        if (! empty($d['vlan_id'])) {
            CustomerGroup::applyVlanFilter($q, [(int) $d['vlan_id']]);
        }
        if (! empty($d['customer_group_id'])) {
            if ($d['customer_group_id'] === 'ungrouped') {
                $allVlans = $tenantGroups->flatMap(fn ($g) => CustomerGroup::normalizeVlans($g->vlan_ids ?? []))->all();
                CustomerGroup::applyUngroupedFilter($q, $allVlans);
            } elseif (ctype_digit($d['customer_group_id'])) {
                $group = $tenantGroups->firstWhere('id', (int) $d['customer_group_id']);
                CustomerGroup::applyVlanFilter($q, $group ? ($group->vlan_ids ?? []) : []);
            }
        }
        $analysis = app(OpticalAnalysis::class)->forTenant($tenantId);
        if ($d['degradation'] ?? false) {
            $q->whereIn('id', $analysis['degraded_ids']);
        }
        $total = (clone $q)->count();
        $online = (clone $q)->where('phase_state', 'working')->count();
        $summary = [
            'total' => $total,
            'online' => $online,
            'other' => $total - $online,
            'weak' => (clone $q)->where('rx_power', '<=', config('nms.bad_rx_threshold'))->count(),
            'percent' => $total ? round($online / $total * 100) : 0,
        ];
        if (! empty($d['status'])) {
            $q->where('phase_state', $d['status'] === 'nonworking' ? '!=' : '=', $d['status'] === 'nonworking' ? 'working' : $d['status']);
        }
        if (! empty($d['q'])) {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$d['q'].'%')
                ->orWhere('sn', 'like', '%'.$d['q'].'%')
                ->orWhere('description', 'like', '%'.$d['q'].'%')
                ->orWhereHas('tags', fn ($t) => $t->where('tag_name', 'like', '%'.$d['q'].'%')));
        }
        $matchingOlts = (clone $q)->distinct()->pluck('olt_id');
        $sort = $d['sort'] ?? 'name';
        $dir = $d['direction'] ?? 'asc';
        if ($sort === 'position') {
            $q->orderBy('olt_port_id', $dir)->orderBy('onu_index', $dir);
        } else {
            $q->orderBy($sort, $dir);
        }
        $page = $q->with(['tags', 'port', 'olt:id,name'])->orderBy('id')->paginate($d['per_page'] ?? 50);
        $page->getCollection()->transform(function ($o) use ($analysis, $tenantGroups) {
            $row = $o->toArray();
            $row['interface'] = preg_replace('/^(gpon|epon)-olt_/', '$1-onu_', $o->port->interface_name).':'.$o->onu_index;
            $row['previous_rx'] = $analysis['items'][$o->id]['baseline_rx'] ?? null;
            $row['all_vlans'] = CustomerGroup::onuVlans($o);
            $row['customer_groups'] = $tenantGroups
                ->filter(fn ($g) => $g->matchesOnu($o))
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'color_hex' => $g->color_hex])
                ->values()
                ->all();

            return $row + ($analysis['items'][$o->id] ?? []);
        });

        return $page->toArray() + [
            'summary' => $summary,
            'matched_olt_ids' => $matchingOlts,
            'analysis' => array_diff_key($analysis, ['items' => true, 'degraded_ids' => true]),
        ];
    }
}
