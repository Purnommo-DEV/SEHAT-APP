<?php

namespace App\Enums;

enum QueueTicketStatus: string
{
    case Waiting = 'waiting';
    case Calling = 'calling';
    case Serving = 'serving';
    case Finished = 'finished';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Menunggu',
            self::Calling => 'Dipanggil',
            self::Serving => 'Dilayani',
            self::Finished => 'Selesai',
            self::Skipped => 'Dilewati',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
