<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FuelType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prezzo di un giorno dei distributori seguiti da qualcuno ({@see UserValue::followsPrice()}), per i grafici
 * dell'andamento: una riga per distributore, carburante base, self/servito e giorno dell'estrazione MIMIT. Dato pubblico
 * e condiviso da tutta l'istanza. Niente chiave esterna verso i distributori: quelli si riscrivono a ogni import, lo
 * storico resta anche se un distributore chiude.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'fuel_price_history')]
#[ORM\UniqueConstraint(name: 'uniq_fuel_price_history_station_fuel_self_day', columns: ['station_id', 'fuel', 'is_self', 'day'])]
class FuelPriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'station_id', type: 'integer')]
        private int $stationId,
        #[ORM\Column(length: 20, enumType: FuelType::class)]
        private FuelType $fuel,
        #[ORM\Column(name: 'is_self', type: 'boolean')]
        private bool $self,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $day,
        /** €/l con tre decimali, come il ministero. */
        #[ORM\Column(type: 'decimal', precision: 5, scale: 3)]
        private string $price,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getStationId(): int { return $this->stationId; }
    public function getFuel(): FuelType { return $this->fuel; }
    public function isSelf(): bool { return $this->self; }
    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function getPrice(): string { return $this->price; }
}
