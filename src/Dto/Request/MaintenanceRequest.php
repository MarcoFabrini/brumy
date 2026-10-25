<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Enum\MaintenanceCategory;
use App\Enum\MaintenanceType;
use App\Validator\DecimalString;
use Symfony\Component\Validator\Constraints as Assert;

final class MaintenanceRequest
{
    /**
     * @param list<string> $components
     */
    public function __construct(
        #[Assert\Positive]
        public int $vehicleId = 0,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $performedAt = '',

        #[Assert\Range(min: 0, max: 9_999_999, notInRangeMessage: 'common.km_too_large')]
        public int $km = 0,

        public MaintenanceType $type = MaintenanceType::OTHER,
        public MaintenanceCategory $category = MaintenanceCategory::SCHEDULED,

        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $description = '',

        #[DecimalString(maxDecimals: 2, maxIntegerDigits: 8, allowZero: true, message: 'validation.amount_format')]
        public ?string $cost = null,

        #[Assert\Length(max: 200)]
        public ?string $workshop = null,

        #[Assert\Length(max: 200)]
        public ?string $shop = null,

        #[Assert\Length(max: 2000)]
        public ?string $notes = null,

        /**
         * Solo in creazione, per revisione, assicurazione e bollo/superbollo: data della prossima scadenza, che
         * aggiorna o crea il promemoria ({@see \App\Service\RecordReminders}); null = nessun promemoria.
         */
        #[Assert\Date]
        public ?string $nextDueDate = null,

        /** Nomi dei componenti del veicolo usati nel lavoro: quelli nuovi si creano. */
        #[Assert\Count(max: 30)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 200)])]
        public array $components = [],
    ) {
    }
}
