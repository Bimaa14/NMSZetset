<?php

namespace App\Services\Olt;

use App\Models\{Olt, OltOperation};
use Symfony\Component\HttpKernel\Exception\HttpException;

class OltBusyException extends HttpException
{
    public function __construct(private Olt $olt)
    {
        parent::__construct(409, 'Sesi OLT sedang dipakai operasi lain. Perintah belum dikirim.');
    }

    public function render()
    {
        $operation = OltOperation::where('tenant_id', $this->olt->tenant_id)
            ->where('olt_id', $this->olt->id)->where('status', 'running')->first();

        return response()->json([
            'message' => $this->getMessage(),
            'reason' => 'olt_busy',
            'retry_after' => 2,
            'operation' => $operation ? ['kind' => $operation->kind, 'step' => $operation->current_step] : null,
        ], 409);
    }
}
