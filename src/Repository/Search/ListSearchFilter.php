<?php

declare(strict_types=1);

namespace App\Repository\Search;

use App\Service\Search\ListSearch;
use App\Service\Search\SearchTerm;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;

/**
 * Applica una {@see ListSearch} alla query di un elenco: un `andWhere` per parola, con l'`OR` delle condizioni che la
 * parola può soddisfare nei campi di {@see ListSearchFields}. Si somma alle condizioni già presenti (organizzazione,
 * veicolo, sezione…), quindi non allarga mai lo scoping. Ogni valore è un parametro (`search_<i>_…`).
 *
 * Nessuna riga moltiplicata: le associazioni a uno sono `leftJoin`, quelle a molti passano per `EXISTS`, quindi
 * `setFirstResult()`/`setMaxResults()` della query restano corretti. Nessuna query in più.
 */
final class ListSearchFilter
{
    public static function apply(QueryBuilder $qb, ListSearch $search, ListSearchFields $fields): void
    {
        $aliases = $qb->getAllAliases();
        foreach ($fields->joins as $alias => $association) {
            if (!\in_array($alias, $aliases, true)) {
                $qb->leftJoin($association, $alias);
            }
        }

        foreach ($search->terms as $i => $term) {
            $qb->andWhere(self::termCondition($qb, $term, $fields, 'search_' . $i . '_'));
        }
    }

    private static function termCondition(QueryBuilder $qb, SearchTerm $term, ListSearchFields $fields, string $p): Orx
    {
        $or = $qb->expr()->orX();
        $escape = " ESCAPE '" . SearchTerm::LIKE_ESCAPE . "'";

        $qb->setParameter($p . 'text', $term->likePattern);
        foreach ($fields->text as $field) {
            $or->add($field . ' LIKE :' . $p . 'text' . $escape);
        }

        $root = $qb->getRootAliases()[0];
        $rootEntity = $qb->getRootEntities()[0];
        foreach (array_keys($fields->collections) as $n => $association) {
            $owner = $p . 'c' . $n;
            $item = $owner . '_item';
            $likes = array_map(
                static fn (string $field): string => $item . '.' . $field . ' LIKE :' . $p . 'text' . $escape,
                $fields->collections[$association],
            );
            $or->add(sprintf(
                'EXISTS (SELECT %1$s.id FROM %2$s %1$s JOIN %1$s.%3$s %4$s WHERE %1$s.id = %5$s.id AND (%6$s))',
                $owner,
                $rootEntity,
                $association,
                $item,
                $root,
                implode(' OR ', $likes),
            ));
        }

        foreach ($term->enumHints as $family => $values) {
            foreach ($fields->families[$family] ?? [] as $field) {
                $or->add($field . ' IN (:' . $p . $family . ')');
                $qb->setParameter($p . $family, $values);
            }
        }
        foreach ($term->flagHints as $family => $values) {
            foreach ($fields->families[$family] ?? [] as $field) {
                foreach ($values as $k => $value) {
                    $or->add($field . ' = :' . $p . $family . '_' . $k);
                    $qb->setParameter($p . $family . '_' . $k, $value);
                }
            }
        }

        if ($fields->date !== null) {
            if ($term->months !== []) {
                $or->add('MONTH(' . $fields->date . ') IN (:' . $p . 'months)');
                $qb->setParameter($p . 'months', $term->months);
            }
            if ($term->dateFrom !== null && $term->dateTo !== null) {
                $or->add(sprintf('(%1$s >= :%2$sdate_from AND %1$s < :%2$sdate_to)', $fields->date, $p));
                $qb->setParameter($p . 'date_from', $term->dateFrom, Types::DATE_IMMUTABLE);
                $qb->setParameter($p . 'date_to', $term->dateTo, Types::DATE_IMMUTABLE);
            }
        }
        if ($fields->year !== null && $term->year !== null) {
            $or->add($fields->year . ' = :' . $p . 'year');
            $qb->setParameter($p . 'year', $term->year);
        }
        if ($fields->amount !== null && $term->amountFrom !== null && $term->amountTo !== null) {
            $or->add(sprintf('(%1$s >= :%2$samount_from AND %1$s < :%2$samount_to)', $fields->amount, $p));
            $qb->setParameter($p . 'amount_from', $term->amountFrom);
            $qb->setParameter($p . 'amount_to', $term->amountTo);
        }
        if ($fields->km !== [] && $term->km !== null) {
            foreach ($fields->km as $field) {
                $or->add($field . ' = :' . $p . 'km');
            }
            $qb->setParameter($p . 'km', $term->km);
        }

        return $or;
    }
}
