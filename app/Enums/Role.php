<?php

namespace App\Enums;

enum Role: string
{
    case Administrator = 'administrador';
    case Seller = 'vendedor';
    case Kitchen = 'cocina';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => ['users.manage', 'menu.manage', 'settings.manage', 'reports.view', 'cash.manage', 'catalog.view', 'orders.view', 'preparation.view', 'kitchen.view', 'orders.prepare', 'orders.ready', 'orders.deliver'],
            self::Seller => ['orders.create', 'payments.create', 'cash.manage', 'preparation.view', 'orders.deliver', 'catalog.view', 'orders.view'],
            self::Kitchen => ['orders.receive', 'tickets.view', 'orders.prepare', 'orders.ready', 'preparation.view', 'kitchen.view', 'catalog.view', 'orders.view'],
        };
    }
}
