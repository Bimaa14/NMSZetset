<?php

return [
    'demo' => env('NMS_DEMO', false),
    'subscription' => env('NMS_SUBSCRIPTION', true),
    'bad_rx_threshold' => -27.0, // Bad RX is inclusive (<=) per the real-driver requirements.
    'equipment_updated_at' => '15.48 WIB',
    'optical_calculated_at' => '15.00 WIB',
];
