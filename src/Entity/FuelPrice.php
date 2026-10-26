<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FuelType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ultimo prezzo comunicato da un distributore per un carburante base, self o servito (open data MIMIT
 * `prezzo_alle_8`). Ogni import sostituisce tutto; il prezzo dei distributori seguiti resta in {@see FuelPriceHistory}.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'fuel_prices')]
#[ORM\UniqueConstraint(name: 'uniq_fuel_prices_station_fuel_self', columns: ['station_id', 'fuel', 'is_self'])]
class FuelPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: FuelStation::class)]
        #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
        private FuelStation $station,
        #[ORM\Column(length: 20, enumType: FuelType::class)]
        private FuelType $fuel,
        #[ORM\Column(name: 'is_self', type: 'boolean')]
        private bool $self,
        /** €/l con tre decimali, come il ministero. */
        #[ORM\Column(type: 'decimal', precision: 5, scale: 3)]
        private string $price,
        #[ORM\Column(name: 'communicated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $communicatedAt,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getStation(): FuelStation { return $this->station; }
    public function getFuel(): FuelType { return $this->fuel; }
    public function isSelf(): bool { return $this->self; }
    public function getPrice(): string { return $this->price; }
    public function getCommunicatedAt(): \DateTimeImmutable { return $this->communicatedAt; }
}
