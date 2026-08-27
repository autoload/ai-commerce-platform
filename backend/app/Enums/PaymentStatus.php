<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case RequiresPayment = 'requires_payment';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
