<?php

namespace App\Services\Olt;

trait PreparesZteSession
{
    public function addSecrets(array $secrets): void
    {
        $this->secrets = array_values(array_unique(array_merge($this->secrets, array_filter($secrets, fn($value)=>is_string($value)&&$value!==''))));
    }
    private function prepareSession(string $password): void
    {
        // A fresh empty command consumes login banners and pending Telnet negotiation.
        $prompt = $this->exchange('');
        if (preg_match('/>\h*$/', $prompt)) {
            $response = $this->exchange('enable', true);
            if (preg_match('/password\h*:\h*$/i', $response)) {
                // Only one attempt; never loop passwords or retry operational writes.
                $this->exchange($password);
            }
            // A rejected enable with a valid user prompt still leaves a usable session.
        }
        try {
            $this->command('terminal length 0');
        } catch (OltConnectionException $e) {
            if ($e->reason !== 'command_rejected') throw $e;
        }
    }
}
