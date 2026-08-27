<?php

namespace App\Enums;

enum InventoryTransactionReason: string
{
    case Sale = 'sale';
    case Restock = 'restock';
    case Adjustment = 'adjustment';
    case Refund = 'refund';
}
