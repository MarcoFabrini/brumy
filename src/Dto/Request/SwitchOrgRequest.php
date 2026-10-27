<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class SwitchOrgRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $organizationId = 0,
    ) {
    }
}
