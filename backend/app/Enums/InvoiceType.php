<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Payment = 'payment';
    case TokenPurchase = 'token_purchase';
}
