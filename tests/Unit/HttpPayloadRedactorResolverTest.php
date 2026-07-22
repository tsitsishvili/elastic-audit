<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\IntBackedProvider;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class HttpPayloadRedactorResolverTest extends TestCase
{
    public function test_normalizes_integer_backed_provider_values(): void
    {
        config(['http_logs.payment_provider_values' => [IntBackedProvider::Delivery->value]]);

        $default  = new SensitiveDataRedactor;
        $payment  = new PaymentRedactor;
        $resolver = new HttpPayloadRedactorResolver($default, $payment);

        $this->assertSame($payment, $resolver->forProvider(IntBackedProvider::Delivery));
    }

    public function test_accepts_configured_enum_cases_and_falls_back_for_other_providers(): void
    {
        config(['http_logs.payment_provider_values' => [TestProvider::Payment]]);

        $default  = new SensitiveDataRedactor;
        $payment  = new PaymentRedactor;
        $resolver = new HttpPayloadRedactorResolver($default, $payment);

        $this->assertSame($payment, $resolver->forProvider(TestProvider::Payment));
        $this->assertSame($default, $resolver->forProvider(TestProvider::Delivery));
    }
}
