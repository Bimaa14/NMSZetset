<?php

namespace App\Services\Olt;

use App\Models\{Olt, Onu};
use Illuminate\Support\Facades\Cache;

/** Independent CLI sessions with reserved capacity for interactive control. */
class OltSessionCoordinator
{
    private function key(Olt $olt, string $lane): string
    {
        return 'nms:'.$lane.':'.$olt->tenant_id.':'.$olt->id;
    }

    private function slots(): int
    {
        return max(1, min(4, (int) config('olt.control_sessions', 2)));
    }

    private function lease(string $key)
    {
        return Cache::lock($key, (int) config('olt.operation_timeout', 300) + 30);
    }

    public function monitor(Olt $olt, callable $action): mixed
    {
        return $this->run($olt, [$this->key($olt, 'olt')], [], false, false, $action);
    }

    public function read(Olt $olt, callable $action): mixed
    {
        return $this->run($olt, [$this->key($olt, 'olt-read')], [], false, true, $action);
    }

    public function control(Onu $onu, callable $action): mixed
    {
        abort_unless($onu->port && $onu->port->olt_id === $onu->olt_id, 422, 'Posisi ONU tidak valid.');
        // Lock the physical address, including duplicate inventory records.
        $address = hash('sha256', $onu->port->interface_name.':'.$onu->onu_index);
        $slots = [];
        for ($i=0; $i<$this->slots(); $i++) $slots[] = $this->key($onu->olt, 'olt-control-'.$i);
        return $this->run($onu->olt, [$this->key($onu->olt, 'onu-control-'.$address)], $slots, false, false, $action);
    }

    public function exclusive(Olt $olt, callable $action): mixed
    {
        $keys = [$this->key($olt, 'olt'), $this->key($olt, 'olt-read')];
        for ($i=0; $i<$this->slots(); $i++) $keys[] = $this->key($olt, 'olt-control-'.$i);
        return $this->run($olt, $keys, [], true, false, $action);
    }

    private function run(Olt $olt, array $required, array $slots, bool $exclusive, bool $read, callable $action): mixed
    {
        @set_time_limit((int) config('olt.operation_timeout', 300) + 30);
        $gate = $this->lease($this->key($olt, 'olt-exclusive'));
        $held = [];
        $busy = function () use ($olt, $read): never {
            if ($read) throw new OltConnectionException('Sesi baca sedang dipakai.', 'read_busy');
            throw new OltBusyException($olt);
        };
        // Let simultaneous admissions serialize for milliseconds, without
        // waiting for another session's network IO or an exclusive operation.
        try { $gate->betweenBlockedAttemptsSleepFor(25)->block(1); }
        catch (\Illuminate\Contracts\Cache\LockTimeoutException) { $busy(); }
        $gateHeld = true;
        try {
            // Admission is atomic with respect to OLT-wide changes. Shared lanes
            // release the gate before IO; an exclusive action retains it.
            foreach ($required as $key) {
                $lock = $this->lease($key);
                if (!$lock->get()) $busy();
                $held[] = $lock;
            }
            if ($slots) {
                $available = false;
                foreach ($slots as $key) {
                    $slot = $this->lease($key);
                    if ($slot->get()) { $held[] = $slot; $available = true; break; }
                }
                if (!$available) $busy();
            }
            if (!$exclusive) { $gate->release(); $gateHeld = false; }
            return $action();
        } finally {
            foreach (array_reverse($held) as $lock) $lock->release();
            // Owner-aware release never clears a lease taken by another request.
            if ($gateHeld) $gate->release();
        }
    }
}
