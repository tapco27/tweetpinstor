<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case Processing = 'processing';
    case WaitingProvider = 'waiting_provider';
    case WaitingAdmin = 'waiting_admin';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
