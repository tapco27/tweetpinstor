<?php

return [
    // If true, wallet-paid orders are auto-refunded when all provider slots fail.
    'auto_refund_on_final_failure' => env('FULFILLMENT_AUTO_REFUND_ON_FINAL_FAILURE', false),
];
