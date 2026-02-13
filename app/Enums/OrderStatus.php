<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case AwaitingManualApproval = 'awaiting_manual_approval';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
