<?php

return [
    // Enable only after every Android device sends cash_shift_id for every new order.
    'require_shift' => env('CASH_SHIFT_REQUIRED', false),
];
