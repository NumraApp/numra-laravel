<?php

declare(strict_types=1);

namespace Numra\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Numra\PhoneCheck check(string $phone, array $options = [])
 * @method static \Numra\OutcomeResult reportOutcome(array $input)
 * @method static \Numra\LicenseStatus verifyLicense()
 *
 * @see \Numra\Numra
 */
final class Numra extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Numra\Numra::class;
    }
}
