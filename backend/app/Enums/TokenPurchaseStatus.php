<?php

namespace App\Enums;

enum TokenPurchaseStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
