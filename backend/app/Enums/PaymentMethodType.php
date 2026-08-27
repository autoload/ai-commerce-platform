<?php

namespace App\Enums;

/**
 * Single value for MVP; modeled as an enum since Database Design 2.5
 * anticipates additional Stripe payment method types later.
 */
enum PaymentMethodType: string
{
    case Card = 'card';
}
