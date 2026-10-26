<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FuelStationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Distributore dell'open data MIMIT "Osservaprezzi carburanti" (anagrafica degli impianti attivi). Dato pubblico,
 * uguale per tutta l'istanza: nessuna organizzazione, si riscrive per intero a ogni import
 * ({@see \App\Service\FuelPrices\FuelPriceImporter}). L'id è quello del ministero (`idImpianto`).
 */
#[ORM\Entity(repositoryClass: FuelStationRepository::class, readOnly: true)]
#[ORM\Table(name: 'fuel_stations')]
#[ORM\Index(name: 'idx_fuel_stations_position', columns: ['latitude', 'longitude'])]
class FuelStation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        private int $id,
        #[ORM\Column(length: 200)]
        private string $operator,
        #[ORM\Column(length: 100)]
        private string $brand,
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column(length: 300)]
        private string $address,
        #[ORM\Column(length: 100)]
        private string $city,
        #[ORM\Column(length: 5)]
        private string $province,
        /** Null se il ministero non la dà o è fuori dall'Italia: il distributore si trova solo cercandolo. */
        #[ORM\Column(type: 'float', nullable: true)]
        private ?float $latitude,
        #[ORM\Column(type: 'float', nullable: true)]
        private ?float $longitude,
        /** Data dell'estrazione del ministero ("Estrazione del …"), uguale per tutte le righe di un import. */
        #[ORM\Column(name: 'extracted_on', type: 'date_immutable')]
        private \DateTimeImmutable $extractedOn,
        #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $importedAt,
    ) {
    }

    public function getId(): int { return $this->id; }
    public function getOperator(): string { return $this->operator; }
    public function getBrand(): string { return $this->brand; }
    public function getName(): string { return $this->name; }
    public function getAddress(): string { return $this->address; }
    public function getCity(): string { return $this->city; }
    public function getProvince(): string { return $this->province; }
    public function getLatitude(): ?float { return $this->latitude; }
    public function getLongitude(): ?float { return $this->longitude; }
    public function getExtractedOn(): \DateTimeImmutable { return $this->extractedOn; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
}
