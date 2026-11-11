<?php

namespace App\Services\Olt;

/** Only the explicit rates verified on C1600 firmware 109_V300R003B021C24. */
class YotcTrafficParser
{
    public function parse(string $output, string $port): array
    {
        if (! preg_match('/^[1-9]\d?\/[1-9]\d?$/D', $port)) {
            throw new OltConnectionException('Alamat PON YOTC tidak valid.', 'configuration');
        }
        preg_match_all('/^\h*Performance\h*:\h*(\w+)\h*$/mi', $output, $states);
        if (count($states[1]) === 1 && strtolower($states[1][0]) === 'disable') {
            throw new OltConnectionException('Pengukuran traffic PON '.$port.' belum aktif pada OLT.', 'traffic_unavailable');
        }
        preg_match_all('/^\h*Location\h*:\h*(\S+)\h*$/mi', $output, $locations);
        preg_match_all('/^\h*DS\h+Rate\(bps\)\h*:\h*(\S+)\h+US\h+Rate\(bps\)\h*:\h*(\S+)\h*$/mi', $output, $rates);
        // Refuse partial, duplicated, cross-port and unrecognized responses instead of recording zero.
        if (count($states[1]) !== 1 || strtolower($states[1][0]) !== 'enable'
            || count($locations[1]) !== 1 || $locations[1][0] !== $port || count($rates[1]) !== 1
            || preg_match_all('/\b(?:DS|US)\h+Rate\(bps\)\h*:/i', $output) !== 2) {
            throw new OltConnectionException('Respons traffic PON '.$port.' tidak lengkap atau tidak sesuai port.', 'traffic_unavailable');
        }
        return ['rx_bps'=>$this->rate($rates[1][0]), 'tx_bps'=>$this->rate($rates[2][0]),
            // Octets and Lapse Time have no verified counter semantics. Never derive volume or rates from them.
            'rx_bytes'=>null, 'tx_bytes'=>null];
    }

    private function rate(string $value): float
    {
        if (! preg_match('/^(?:0|[1-9]\d*|[1-9]\d{0,2}(?:,\d{3})+)$/D', $value)) {
            throw new OltConnectionException('Nilai rate traffic YOTC tidak dikenali.', 'traffic_unavailable');
        }
        $rate = (float) str_replace(',', '', $value);
        if (! is_finite($rate) || $rate > 10_000_000_000) {
            throw new OltConnectionException('Nilai rate traffic YOTC di luar batas.', 'traffic_unavailable');
        }
        return $rate;
    }
}
