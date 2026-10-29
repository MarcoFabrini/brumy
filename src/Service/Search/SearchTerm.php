<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Enum\ExpenseSection;
use App\Enum\FuelType;
use App\Enum\MaintenanceCategory;
use App\Enum\MaintenanceType;
use App\Enum\RecurringPeriod;
use App\Enum\ReminderType;
use App\Enum\VehicleType;

/**
 * Una parola della ricerca negli elenchi, già interpretata: il pattern `LIKE` per i testi, i valori tradotti mandati
 * dal frontend (`hint`) e ciò che la parola vale come numero (importo, anno, mese/anno, giorno, km). Il record la
 * soddisfa se corrisponde ad almeno una di queste cose ({@see \App\Repository\Search\ListSearchFilter}).
 *
 * Le regole numeriche non dipendono dalla lingua; le etichette (categoria "Tagliando", "marzo") le conosce solo il
 * frontend, che le traduce in valori (`maintenance_type:inspection`, `month:3`).
 */
final readonly class SearchTerm
{
    /** Carattere di escape del `LIKE`: né `\` (dipende da `sql_mode`) né un carattere che si scrive spesso. */
    public const LIKE_ESCAPE = '!';

    /** Famiglie di hint che sono un enum: famiglia → classe. */
    public const ENUM_FAMILIES = [
        'maintenance_type' => MaintenanceType::class,
        'maintenance_category' => MaintenanceCategory::class,
        'expense_section' => ExpenseSection::class,
        'recurring_period' => RecurringPeriod::class,
        'fuel_type' => FuelType::class,
        'vehicle_type' => VehicleType::class,
        'reminder_type' => ReminderType::class,
    ];

    /** Famiglie di hint che sono un sì/no: famiglia → valore → bool. */
    public const FLAG_FAMILIES = [
        'refueling_tank' => ['full' => true, 'partial' => false],
    ];

    public const MONTH_FAMILY = 'month';

    /** Km massimi riconosciuti (colonne `INT`): oltre, la parola resta testo/importo. */
    private const MAX_KM = 99_999_999;

    public function __construct(
        /** La parola come scritta. */
        public string $text,
        /** `%parola%` con `%`, `_` e `!` preceduti da `!`: si usa con `ESCAPE '!'`. */
        public string $likePattern,
        /** @var array<string, non-empty-list<\BackedEnum>> famiglia enum → valori che l'etichetta tradotta contiene */
        public array $enumHints = [],
        /** @var array<string, non-empty-list<bool>> famiglia sì/no → valori */
        public array $flagHints = [],
        /** @var list<int<1, 12>> mesi il cui nome inizia con la parola */
        public array $months = [],
        /** Importo minimo (incluso), `"120.00"`. */
        public ?string $amountFrom = null,
        /** Importo massimo (escluso), `"121.00"`. */
        public ?string $amountTo = null,
        /** Primo giorno (incluso) dell'anno, del mese o del giorno scritto. */
        public ?\DateTimeImmutable $dateFrom = null,
        /** Giorno successivo all'ultimo (escluso). */
        public ?\DateTimeImmutable $dateTo = null,
        /** Anno scritto da solo (`2025`): per i campi anno interi, come l'anno del veicolo. */
        public ?int $year = null,
        /** Km esatti (`125000` o `125.000`). */
        public ?int $km = null,
    ) {
    }

    /**
     * @param list<string> $hints valori `famiglia:valore` per questa parola; quelli non validi si ignorano
     */
    public static function parse(string $word, array $hints = []): self
    {
        [$enumHints, $flagHints, $months] = self::parseHints($hints);
        [$amountFrom, $amountTo] = self::parseAmount($word);
        [$dateFrom, $dateTo, $year] = self::parseDate($word);

        return new self(
            text: $word,
            likePattern: '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%',
            enumHints: $enumHints,
            flagHints: $flagHints,
            months: $months,
            amountFrom: $amountFrom,
            amountTo: $amountTo,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            year: $year,
            km: self::parseKm($word),
        );
    }

    /**
     * @param list<string> $hints
     *
     * @return array{array<string, non-empty-list<\BackedEnum>>, array<string, non-empty-list<bool>>, list<int<1, 12>>}
     */
    private static function parseHints(array $hints): array
    {
        $enums = [];
        $flags = [];
        $months = [];
        foreach ($hints as $hint) {
            [$family, $value] = array_pad(explode(':', $hint, 2), 2, '');
            if (isset(self::ENUM_FAMILIES[$family])) {
                $case = self::ENUM_FAMILIES[$family]::tryFrom($value);
                if ($case !== null && !\in_array($case, $enums[$family] ?? [], true)) {
                    $enums[$family][] = $case;
                }
            } elseif (isset(self::FLAG_FAMILIES[$family][$value])) {
                $flag = self::FLAG_FAMILIES[$family][$value];
                if (!\in_array($flag, $flags[$family] ?? [], true)) {
                    $flags[$family][] = $flag;
                }
            } elseif ($family === self::MONTH_FAMILY && preg_match('/^\d{1,2}$/', $value) === 1) {
                $month = (int) $value;
                if ($month >= 1 && $month <= 12 && !\in_array($month, $months, true)) {
                    $months[] = $month;
                }
            }
        }

        return [$enums, $flags, $months];
    }

    /**
     * `120` → [120.00, 121.00); `120,50` o `120.5` → [120.50, 120.51). Le colonne sono `DECIMAL(…, 2)`: l'intervallo
     * di un centesimo è l'uguaglianza. `1.200` (punto delle migliaia) non è un importo.
     *
     * @return array{?string, ?string}
     */
    private static function parseAmount(string $word): array
    {
        $number = preg_replace('/^€|€$/u', '', $word) ?? $word;
        if (preg_match('/^(\d{1,8})(?:[.,](\d{1,2}))?$/', $number, $m) !== 1) {
            return [null, null];
        }
        $isDecimal = isset($m[2]); // un gruppo finale non catturato manca dall'array
        $cents = (int) $m[1] * 100 + ($isDecimal ? (int) str_pad($m[2], 2, '0') : 0);
        $to = $cents + ($isDecimal ? 1 : 100);

        return [self::money($cents), self::money($to)];
    }

    /**
     * Anno (`2025`, 1900–2100), mese/anno (`03/2025`), giorno all'italiana (`12/03/2025`, `1.3.25`) o ISO (`2025-03-12`).
     * Un giorno inesistente (`31/02/2025`) non è una data.
     *
     * @return array{?\DateTimeImmutable, ?\DateTimeImmutable, ?int}
     */
    private static function parseDate(string $word): array
    {
        if (preg_match('/^\d{4}$/', $word) === 1) {
            $year = (int) $word;
            if ($year < 1900 || $year > 2100) {
                return [null, null, null];
            }
            $from = self::day($year, 1, 1);

            return [$from, $from->modify('+1 year'), $year];
        }
        if (preg_match('/^(\d{1,2})[\/.-](\d{4})$/', $word, $m) === 1) {
            $month = (int) $m[1];
            if ($month < 1 || $month > 12) {
                return [null, null, null];
            }
            $from = self::day((int) $m[2], $month, 1);

            return [$from, $from->modify('+1 month'), null];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $word, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4}|\d{2})$/', $word, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (\strlen($m[3]) === 2) {
                $year += 2000;
            }
        } else {
            return [null, null, null];
        }
        if (!checkdate($month, $day, $year)) {
            return [null, null, null];
        }
        $from = self::day($year, $month, $day);

        return [$from, $from->modify('+1 day'), null];
    }

    /** `125000` o `125.000` (punto delle migliaia): km esatti. Con `€` è un importo, non km. */
    private static function parseKm(string $word): ?int
    {
        if (preg_match('/^(?:\d{1,8}|\d{1,3}(?:\.\d{3}){1,2})$/', $word) !== 1) {
            return null;
        }
        $km = (int) str_replace('.', '', $word);

        return $km <= self::MAX_KM ? $km : null;
    }

    private static function day(int $year, int $month, int $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private static function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
