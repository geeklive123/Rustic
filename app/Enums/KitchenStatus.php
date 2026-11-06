<?php

namespace App\Enums;

enum KitchenStatus: string
{
    case New = 'NEW';
    case Preparing = 'PREPARING';
    case Ready = 'READY';
    case Delivered = 'DELIVERED';

    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Delivered,
            self::Delivered => null,
        };
    }
}
