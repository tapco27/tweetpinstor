<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case RequiresAction = 'requires_action';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
