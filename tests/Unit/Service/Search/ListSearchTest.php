<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Enum\FuelType;
use App\Enum\MaintenanceCategory;
use App\Enum\MaintenanceType;
use App\Service\Search\ListSearch;
use App\Service\Search\SearchTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListSearchTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function emptyQueries(): iterable
    {
        yield 'vuota' => [''];
        yield 'spazi' => ['   '];
        yield 'tab e a capo' => ["\t\n "];
    }

    #[DataProvider('emptyQueries')]
    public function testEmptyQueryIsNoSearch(string $q): void
    {
        self::assertNull(ListSearch::fromQuery($q, [0 => ['month:3']]));
    }

    public function testWordsAreSplitOnAnySpaceAndCappedAtSix(): void
    {
        $search = ListSearch::fromQuery("  uno   due\tTRE quattro cinque sei sette otto ");

        self::assertNotNull($search);
        self::assertSame(['uno', 'due', 'TRE', 'quattro', 'cinque', 'sei'], array_map(static fn (SearchTerm $t): string => $t->text, $search->terms));
    }

    public function testQueryIsCutAtOneHundredCharacters(): void
    {
        $search = ListSearch::fromQuery(str_repeat('è', 150));

        self::assertNotNull($search);
        self::assertCount(1, $search->terms);
        self::assertSame(100, mb_strlen($search->terms[0]->text));
    }

    public function testCutHappensBeforeSplitting(): void
    {
        $search = ListSearch::fromQuery(str_repeat('a', 99) . ' bcd');

        self::assertNotNull($search);
        self::assertSame([str_repeat('a', 99)], array_map(static fn (SearchTerm $t): string => $t->text, $search->terms));
    }

    /** @return iterable<string, array{string, string}> */
    public static function likePatterns(): iterable
    {
        yield 'testo semplice' => ['tagl', '%tagl%'];
        yield 'percento' => ['50%', '%50!%%'];
        yield 'trattino basso' => ['a_b', '%a!_b%'];
        yield 'punto esclamativo' => ['ciao!', '%ciao!!%'];
        yield 'accenti invariati' => ['perché', '%perché%'];
    }

    #[DataProvider('likePatterns')]
    public function testLikeWildcardsAreEscaped(string $word, string $pattern): void
    {
        self::assertSame($pattern, SearchTerm::parse($word)->likePattern);
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function amounts(): iterable
    {
        yield 'intero' => ['120', '120.00', '121.00'];
        yield 'decimale con virgola' => ['120,50', '120.50', '120.51'];
        yield 'decimale con punto, una cifra' => ['120.5', '120.50', '120.51'];
        yield 'euro davanti' => ['€120', '120.00', '121.00'];
        yield 'euro dietro' => ['120,50€', '120.50', '120.51'];
        yield 'zero' => ['0', '0.00', '1.00'];
        yield 'punto delle migliaia' => ['1.200', null, null];
        yield 'tre decimali' => ['1,234', null, null];
        yield 'troppe cifre' => ['123456789', null, null];
        yield 'testo' => ['tagliando', null, null];
        yield 'negativo' => ['-5', null, null];
    }

    #[DataProvider('amounts')]
    public function testAmounts(string $word, ?string $from, ?string $to): void
    {
        $term = SearchTerm::parse($word);

        self::assertSame([$from, $to], [$term->amountFrom, $term->amountTo]);
    }

    /** @return iterable<string, array{string, ?string, ?string, ?int}> */
    public static function dates(): iterable
    {
        yield 'anno' => ['2025', '2025-01-01', '2026-01-01', 2025];
        yield 'anno minimo' => ['1900', '1900-01-01', '1901-01-01', 1900];
        yield 'anno massimo' => ['2100', '2100-01-01', '2101-01-01', 2100];
        yield 'anno sotto il minimo' => ['1899', null, null, null];
        yield 'anno sopra il massimo' => ['2101', null, null, null];
        yield 'mese/anno' => ['03/2025', '2025-03-01', '2025-04-01', null];
        yield 'mese/anno a una cifra, trattino' => ['3-2025', '2025-03-01', '2025-04-01', null];
        yield 'dicembre' => ['12/2025', '2025-12-01', '2026-01-01', null];
        yield 'mese 13' => ['13/2025', null, null, null];
        yield 'giorno' => ['12/03/2025', '2025-03-12', '2025-03-13', null];
        yield 'giorno con punti e anno corto' => ['1.3.25', '2025-03-01', '2025-03-02', null];
        yield 'giorno ISO' => ['2025-03-12', '2025-03-12', '2025-03-13', null];
        yield 'fine anno' => ['31/12/2024', '2024-12-31', '2025-01-01', null];
        yield '29 febbraio bisestile' => ['29/02/2024', '2024-02-29', '2024-03-01', null];
        yield '31 febbraio' => ['31/02/2025', null, null, null];
        yield 'ISO non valida' => ['2025-02-30', null, null, null];
        yield 'testo' => ['marzo', null, null, null];
    }

    #[DataProvider('dates')]
    public function testDates(string $word, ?string $from, ?string $to, ?int $year): void
    {
        $term = SearchTerm::parse($word);

        self::assertSame(
            [$from, $to, $year],
            [$term->dateFrom?->format('Y-m-d'), $term->dateTo?->format('Y-m-d'), $term->year],
        );
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function kms(): iterable
    {
        yield 'intero' => ['125000', 125000];
        yield 'punto delle migliaia' => ['125.000', 125000];
        yield 'milioni' => ['1.250.000', 1250000];
        yield 'piccolo' => ['120', 120];
        yield 'anno vale anche come km' => ['2025', 2025];
        yield 'decimale' => ['120,50', null];
        yield 'gruppo di migliaia sbagliato' => ['12.34', null];
        yield 'euro' => ['€125000', null];
        yield 'troppe cifre' => ['123456789', null];
        yield 'data' => ['12/03/2025', null];
    }

    #[DataProvider('kms')]
    public function testKm(string $word, ?int $km): void
    {
        self::assertSame($km, SearchTerm::parse($word)->km);
    }

    public function testOneWordCanBeYearAmountAndKmAtOnce(): void
    {
        $term = SearchTerm::parse('2025');

        self::assertSame(['2025.00', '2026.00', 2025, 2025], [$term->amountFrom, $term->amountTo, $term->year, $term->km]);
        self::assertSame('%2025%', $term->likePattern);
    }

    public function testValidHintsBecomeTypedValuesOfTheirWord(): void
    {
        $search = ListSearch::fromQuery('tagliando marzo', [
            0 => ['maintenance_type:inspection', 'maintenance_category:inspection', 'maintenance_type:inspection'],
            1 => ['month:3', 'month:03', 'fuel_type:diesel', 'refueling_tank:full', 'refueling_tank:partial'],
        ]);

        self::assertNotNull($search);
        [$first, $second] = $search->terms;
        self::assertSame(['maintenance_type' => [MaintenanceType::INSPECTION], 'maintenance_category' => [MaintenanceCategory::INSPECTION]], $first->enumHints);
        self::assertSame([], $first->months);
        self::assertSame([3], $second->months, 'mese senza doppioni');
        self::assertSame(['fuel_type' => [FuelType::DIESEL]], $second->enumHints);
        self::assertSame(['refueling_tank' => [true, false]], $second->flagHints);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidHints(): iterable
    {
        yield 'valore enum sconosciuto' => [[0 => ['maintenance_type:spaceship']]];
        yield 'famiglia sconosciuta' => [[0 => ['color:red']]];
        yield 'senza due punti' => [[0 => ['inspection']]];
        yield 'mese zero' => [[0 => ['month:0']]];
        yield 'mese tredici' => [[0 => ['month:13']]];
        yield 'mese non numerico' => [[0 => ['month:mar']]];
        yield 'serbatoio sconosciuto' => [[0 => ['refueling_tank:half']]];
        yield 'indice oltre le parole' => [[1 => ['month:3'], 7 => ['month:4']]];
        yield 'indice non numerico' => [['x' => ['month:3']]];
        yield 'parola non lista' => [[0 => 'month:3']];
        yield 'valori non stringa' => [[0 => [['month:3'], 3, null, true]]];
    }

    /** @param array<mixed> $hints */
    #[DataProvider('invalidHints')]
    public function testInvalidHintsAreIgnored(array $hints): void
    {
        $search = ListSearch::fromQuery('parola', $hints);

        self::assertNotNull($search);
        $term = $search->terms[0];
        self::assertSame([[], [], []], [$term->enumHints, $term->flagHints, $term->months]);
    }

    public function testOnlyTheFirstThirtyHintsOfAWordCount(): void
    {
        $hints = array_fill(0, 30, 'month:1');
        $hints[] = 'month:2';

        $search = ListSearch::fromQuery('gen', [0 => $hints]);

        self::assertNotNull($search);
        self::assertSame([1], $search->terms[0]->months);
    }
}
