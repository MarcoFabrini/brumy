<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Reminder;
use App\Entity\User;
use App\Enum\ReminderUrgency;
use App\Repository\ReminderRepository;
use App\Service\Search\ListSearch;

/**
 * Le scadenze di tutti i veicoli propri, con l'urgenza già calcolata (data e km insieme, come le notifiche): la tab
 * "Scadenze" le mostra divise in scadute, in scadenza e più avanti senza dover chiedere i km di ogni veicolo.
 */
final class ReminderOverview
{
    public function __construct(
        private readonly ReminderRepository $repo,
        private readonly VehicleStatsService $stats,
        private readonly AppClock $clock,
    ) {
    }

    /**
     * Ordine: scadute, poi in scadenza, poi le altre; a parità la data più vicina, poi i km mancanti, poi l'id.
     * `$search` (ricerca dell'elenco) restringe i promemoria; null = tutti.
     *
     * @return list<array{reminder: Reminder, urgency: ReminderUrgency, currentKm: int}>
     */
    public function forOwner(Organization $org, User $owner, ?ListSearch $search = null): array
    {
        $today = $this->clock->today();
        /** @var array<int, int> $kmByVehicle */
        $kmByVehicle = [];
        $items = [];
        foreach ($this->repo->findActiveForOwner($org, $owner, $search) as $reminder) {
            $vehicle = $reminder->getVehicle();
            $vehicleId = (int) $vehicle->getId();
            $km = $kmByVehicle[$vehicleId] ??= $this->stats->currentKm($vehicle);
            $items[] = ['reminder' => $reminder, 'urgency' => $reminder->urgency($km, $today), 'currentKm' => $km];
        }

        usort($items, static function (array $a, array $b): int {
            $byUrgency = $b['urgency']->rank() <=> $a['urgency']->rank();
            if ($byUrgency !== 0) {
                return $byUrgency;
            }
            $dateA = $a['reminder']->getDueDate()?->format('Y-m-d') ?? '9999-12-31';
            $dateB = $b['reminder']->getDueDate()?->format('Y-m-d') ?? '9999-12-31';
            $kmLeftA = $a['reminder']->getDueKm() !== null ? $a['reminder']->getDueKm() - $a['currentKm'] : PHP_INT_MAX;
            $kmLeftB = $b['reminder']->getDueKm() !== null ? $b['reminder']->getDueKm() - $b['currentKm'] : PHP_INT_MAX;

            return [$dateA, $kmLeftA, $a['reminder']->getId()] <=> [$dateB, $kmLeftB, $b['reminder']->getId()];
        });

        return $items;
    }
}
