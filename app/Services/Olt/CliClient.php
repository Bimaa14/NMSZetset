<?php

namespace App\Services\Olt;

use App\Models\Olt;

interface CliClient
{
    public function connect(Olt $olt): void;
    public function addSecrets(array $secrets): void;

    public function command(string $command, bool $allowConfirmation = false, ?float $idleTimeout = null, ?float $maxDuration = null, bool $raw = false): string;

    public function close(): void;
}
