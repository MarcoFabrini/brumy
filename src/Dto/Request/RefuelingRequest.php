<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Enum\FuelType;
use App\Validator\DecimalString;
use Symfony\Component\Validator\Constraints as Assert;

final class RefuelingRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $vehicleId = 0,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $refueledAt = '',

        #[Assert\Range(min: 0, max: 9_999_999, notInRangeMessage: 'common.km_too_large')]
        public int $km = 0,

        #[DecimalString(maxDecimals: 3, maxIntegerDigits: 5, message: 'validation.liters_format')]
        public string $liters = '0',

        #[DecimalString(maxDecimals: 4, maxIntegerDigits: 2, message: 'validation.price_format')]
        public string $pricePerLiter = '0',

        public FuelType $fuelType = FuelType::GASOLINE,
        public bool $fullTank = true,

        #[Assert\Length(max: 200)]
        public ?string $station = null,

        #[Assert\Length(max: 500)]
        public ?string $description = null,

        #[Assert\Length(max: 2000)]
        public ?string $notes = null,
    ) {
    }
}
