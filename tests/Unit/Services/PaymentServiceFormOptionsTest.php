<?php

namespace Tests\Unit\Services;

use App\Services\PaymentService;
use ReflectionClass;
use Tests\TestCase;

final class PaymentServiceFormOptionsTest extends TestCase
{
    public function test_select_options_are_normalized_for_the_admin_form_contract(): void
    {
        $this->assertSame([
            ['value' => 'pending', 'label' => 'Not connected'],
            ['value' => 'direct', 'label' => 'Direct API'],
        ], $this->normalize([
            'pending' => 'Not connected',
            'direct' => 'Direct API',
        ]));

        $this->assertSame([
            ['value' => 'tron', 'label' => 'tron'],
            ['value' => '0', 'label' => '0'],
        ], $this->normalize(['tron', 0]));

        $this->assertSame([
            ['value' => 'tron', 'label' => 'TRON', 'disabled' => true],
            ['value' => 'zero', 'label' => '0'],
        ], $this->normalize([
            ['value' => 'tron', 'label' => 'TRON', 'disabled' => true],
            ['value' => 'zero', 'label' => '0'],
        ]));

        $this->assertSame([
            ['label' => 'Mapped option', 'value' => 'mapped'],
        ], $this->normalize([
            'mapped' => ['label' => 'Mapped option'],
        ]));
    }

    public function test_invalid_or_empty_select_options_are_discarded(): void
    {
        $this->assertSame([], $this->normalize(null));
        $this->assertSame([], $this->normalize([
            null,
            false,
            '',
            ['value' => '', 'label' => 'Empty value'],
            ['label' => 'Missing list value'],
        ]));
    }

    private function normalize(mixed $options): array
    {
        $reflection = new ReflectionClass(PaymentService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('translateFormOptions');

        return $method->invoke($service, $options);
    }
}
