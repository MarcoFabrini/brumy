<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/** Seguire o no il prezzo del distributore collegato a una voce distributore. */
final class UserValueFollowPriceRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?bool $follow = null,
    ) {
    }
}
