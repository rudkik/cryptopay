<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Detected = 'detected';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Orphaned = 'orphaned';
}
