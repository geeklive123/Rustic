<?php

namespace Tests\Unit;

use App\Enums\KitchenStatus;
use PHPUnit\Framework\TestCase;

class KitchenStatusTest extends TestCase
{
    public function test_only_the_next_transition_is_available_and_delivery_is_final(): void
    {
        $this->assertSame(KitchenStatus::Preparing, KitchenStatus::New->next());
        $this->assertSame(KitchenStatus::Ready, KitchenStatus::Preparing->next());
        $this->assertSame(KitchenStatus::Delivered, KitchenStatus::Ready->next());
        $this->assertNull(KitchenStatus::Delivered->next());
    }

    public function test_ready_can_be_reopened_and_delivered_cannot(): void
    {
        $this->assertTrue(KitchenStatus::Ready->canTransitionTo(KitchenStatus::Preparing));
        $this->assertTrue(KitchenStatus::Ready->canTransitionTo(KitchenStatus::Delivered));
        $this->assertFalse(KitchenStatus::Delivered->canTransitionTo(KitchenStatus::Preparing));
    }
}
