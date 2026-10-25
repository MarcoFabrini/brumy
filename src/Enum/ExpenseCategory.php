<?php

declare(strict_types=1);

namespace App\Enum;

enum ExpenseCategory: string
{
    case INSURANCE = 'insurance';
    /** Bollo auto / superbollo */
    case ROAD_TAX = 'road_tax';
    /** Altre tasse non bollo (es. tassa di possesso regionale extra) */
    case TAX = 'tax';
    case PARKING = 'parking';
    case TOLL = 'toll';
    case FINE = 'fine';
    case ACCESSORY = 'accessory';
    /** Rata leasing / finanziamento */
    case FINANCING = 'financing';
    /** Abbonamento Telepass, app parcheggio, ricarica EV */
    case SUBSCRIPTION = 'subscription';
    /** Box / rimessa / posto auto affittato */
    case GARAGE = 'garage';
    /** Lavaggio (alternativa a maintenance.wash quando è spot) */
    case CAR_WASH = 'car_wash';
    /** Furto, atti vandalici, eventi non coperti assicurazione */
    case THEFT_DAMAGE = 'theft_damage';
    case OTHER = 'other';

    /**
     * Categorie di partenza delle altre spese. Assicurazione, bollo e tasse hanno ora una sezione loro:
     * restano nell'enum per la compatibilità (slug dei vecchi client, migrazioni) ma non si creano più.
     *
     * @return list<self>
     */
    public static function starters(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $c): bool => !\in_array($c, [self::INSURANCE, self::ROAD_TAX, self::TAX], true),
        ));
    }

    /** Etichetta nella lingua dell'utente ('it' o 'en'; altre lingue ricadono sull'italiano). Nome iniziale della voce di partenza nella lista dell'utente (il frontend mostra il nome della voce, non questa etichetta). */
    public function label(string $locale): string
    {
        return match ($locale) {
            'en' => match ($this) {
                self::INSURANCE => 'Insurance',
                self::ROAD_TAX => 'Road tax',
                self::TAX => 'Tax',
                self::PARKING => 'Parking',
                self::TOLL => 'Toll',
                self::FINE => 'Fine',
                self::ACCESSORY => 'Accessory',
                self::FINANCING => 'Financing',
                self::SUBSCRIPTION => 'Subscription',
                self::GARAGE => 'Garage',
                self::CAR_WASH => 'Car wash',
                self::THEFT_DAMAGE => 'Theft/Damage',
                self::OTHER => 'Other',
            },
            default => match ($this) {
                self::INSURANCE => 'Assicurazione',
                self::ROAD_TAX => 'Bollo',
                self::TAX => 'Tassa',
                self::PARKING => 'Parcheggio',
                self::TOLL => 'Pedaggio',
                self::FINE => 'Multa',
                self::ACCESSORY => 'Accessorio',
                self::FINANCING => 'Finanziamento',
                self::SUBSCRIPTION => 'Abbonamento',
                self::GARAGE => 'Garage',
                self::CAR_WASH => 'Autolavaggio',
                self::THEFT_DAMAGE => 'Furto/Danno',
                self::OTHER => 'Altro',
            },
        };
    }
}
