<?php

namespace App\Enums;

enum BookingSagaState: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Completed = 'completed';
    case Compensated = 'compensated';
    case CompensationFailed = 'compensation_failed';
}
