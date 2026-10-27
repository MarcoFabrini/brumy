<?php

declare(strict_types=1);

namespace App\Service\TwoFactor;

final class TwoFactorSetupException extends \DomainException
{
    public function __construct(public readonly TwoFactorSetupError $error)
    {
        parent::__construct($error->name);
    }
}
