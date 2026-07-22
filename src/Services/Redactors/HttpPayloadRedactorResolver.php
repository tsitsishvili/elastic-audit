<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Redactors;

use BackedEnum;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;

final class HttpPayloadRedactorResolver
{
    public function __construct(
        private readonly SensitiveDataRedactor $defaultRedactor,
        private readonly PaymentRedactor $paymentRedactor,
    ) {}

    public function forProvider(ProviderContract $provider): SensitiveDataRedactor
    {
        $providerValue = (string) $provider->value;

        foreach ((array) config('http_logs.payment_provider_values', []) as $configuredValue) {
            if ($configuredValue instanceof BackedEnum) {
                $configuredValue = $configuredValue->value;
            }

            if (! is_string($configuredValue) && ! is_int($configuredValue)) {
                continue;
            }

            if ((string) $configuredValue === $providerValue) {
                return $this->paymentRedactor;
            }
        }

        return $this->defaultRedactor;
    }
}
