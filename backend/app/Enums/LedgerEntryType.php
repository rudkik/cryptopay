<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Deposit = 'deposit';
    case Adjustment = 'adjustment';
}
