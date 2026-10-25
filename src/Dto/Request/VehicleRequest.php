<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Enum\FuelType;
use App\Enum\VehicleType;
use App\Validator\DecimalString;
use Symfony\Component\Validator\Constraints as Assert;

final class VehicleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $brand = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $model = '',

        #[Assert\Range(min: 1900, max: 2100, notInRangeMessage: 'vehicle.year.out_of_range')]
        public int $year = 2024,

        public VehicleType $type = VehicleType::CAR,
        public FuelType $fuelType = FuelType::GASOLINE,

        /** Seconda fonte per veicoli bi-fuel (es. benzina+GPL, ibrido benzina+elettrico). Null se mono. */
        public ?FuelType $secondaryFuelType = null,

        #[Assert\Length(max: 20)]
        public ?string $licensePlate = null,

        #[Assert\Length(max: 17)]
        public ?string $vin = null,

        // Negativo → common.too_small (PositiveOrZero); oltre il massimo → common.km_too_large. Un Range con
        // entrambi i limiti darebbe lo stesso messaggio "troppo grande" anche ai negativi.
        #[Assert\PositiveOrZero]
        #[Assert\LessThanOrEqual(value: 9_999_999, message: 'common.km_too_large')]
        public int $initialKm = 0,

        #[DecimalString(maxDecimals: 2, maxIntegerDigits: 8, allowZero: true, message: 'validation.amount_format')]
        public ?string $purchasePrice = null,

        #[Assert\Length(max: 5000)]
        public ?string $notes = null,
    ) {
    }
}
