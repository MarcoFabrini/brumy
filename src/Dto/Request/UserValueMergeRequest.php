<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class UserValueMergeRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $intoId = 0,
    ) {
    }
}
