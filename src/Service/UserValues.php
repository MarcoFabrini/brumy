<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\UserValueKind;
use App\Repository\UserValueRepository;
use App\Repository\VehicleShareRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste ricorrenti private dell'utente (distributori, officine, categorie di spesa).
 */
final class UserValues
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserValueRepository $repository,
        private readonly VehicleShareRepository $shares,
    ) {
    }

    /**
     * Crea le voci di partenza del tipo (categorie delle altre spese, tipi di tassa: {@see UserValueKind::starterNames()}),
     * nella lingua dell'utente, solo se la sua lista di quel tipo in questa organizzazione non ha nessuna riga,
     * eliminate comprese (chi le elimina non le rivede). Per gli altri tipi non fa nulla.
     */
    public function ensureStarters(User $user, Organization $org, UserValueKind $kind): void
    {
        $names = $kind->starterNames($user->getLocale());
        if ($names === []) {
            return;
        }
        $existing = (int) $this->repository->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.user = :user AND v.organization = :org AND v.kind = :kind')
            ->setParameter('user', $user)
            ->setParameter('org', $org)
            ->setParameter('kind', $kind)
            ->getQuery()
            ->getSingleScalarResult();
        if ($existing > 0) {
            return;
        }

        // Inserimento atomico (come findOrCreate), senza ORM né flush: due richieste simultanee alla prima
        // visita non si pestano i piedi e il flush resta al chiamante. Una voce omonima già presente non viene toccata.
        $connection = $this->em->getConnection();
        foreach ($names as $starterKey => $name) {
            $connection->executeStatement(
                'INSERT INTO user_values (user_id, organization_id, kind, name, name_key, starter_key, created_at)
                 VALUES (:user, :org, :kind, :name, :key, :starter, NOW())
                 ON DUPLICATE KEY UPDATE id = id',
                [
                    'user' => $user->getId(),
                    'org' => $org->getId(),
                    'kind' => $kind->value,
                    'name' => $name,
                    'key' => UserValue::keyOf($name),
                    'starter' => $starterKey,
                ],
            );
        }
    }

    /**
     * Voce con quel nome (a meno di maiuscole e spazi) nella lista; se manca la crea.
     * L'inserimento è atomico rispetto all'indice unico: un doppio salvataggio concorrente non fallisce.
     * Una lista ancora vuota di un tipo con voci di partenza le riceve prima: ogni lista parte da quelle,
     * anche se la sua prima voce è fatta a mano (endpoint di creazione, rehome(), resolve()).
     */
    public function findOrCreate(User $owner, Organization $org, UserValueKind $kind, string $name): UserValue
    {
        $name = UserValue::normalizeName($name);
        $key = UserValue::keyOf($name);

        $this->ensureStarters($owner, $org, $kind);

        $found = $this->repository->findOneInList($owner, $org, $kind, $key);
        if ($found !== null) {
            return $found;
        }

        $this->em->getConnection()->executeStatement(
            'INSERT INTO user_values (user_id, organization_id, kind, name, name_key, created_at)
             VALUES (:user, :org, :kind, :name, :key, NOW())
             ON DUPLICATE KEY UPDATE id = id',
            ['user' => $owner->getId(), 'org' => $org->getId(), 'kind' => $kind->value, 'name' => $name, 'key' => $key],
        );

        $created = $this->repository->findOneInList($owner, $org, $kind, $key);
        if ($created === null) {
            throw new \LogicException('Voce appena inserita non trovata.');
        }

        return $created;
    }

    /** Proprietario attuale del veicolo; per un veicolo orfano, l'utente che sta salvando. */
    public function listOwner(Vehicle $vehicle, User $actor): User
    {
        return $this->shares->findOwnerShare($vehicle)?->getUser() ?? $actor;
    }

    /**
     * Voce da collegare a un record del veicolo, nella lista del proprietario.
     * Testo vuoto: null. Se il testo è ancora il nome di $current (la voce che il record ha già) resta
     * quella, anche se eliminata: modificare altri campi non la sostituisce con una nuova omonima.
     * Lo slug di una voce di partenza mandato da un vecchio client ('toll') punta a quella voce.
     */
    public function resolve(Vehicle $vehicle, User $actor, UserValueKind $kind, ?string $text, ?UserValue $current = null): ?UserValue
    {
        $text = UserValue::normalizeName($text ?? '');
        if ($text === '') {
            return null;
        }
        if ($current?->getKind() === $kind && UserValue::keyOf($current->getName()) === UserValue::keyOf($text)) {
            return $current;
        }

        $owner = $this->listOwner($vehicle, $actor);
        $org = $vehicle->getOrganization();

        // Lo slug di una voce di partenza mandato da un vecchio client ('toll') punta a quella voce;
        // se è stata eliminata se ne crea una nuova con il suo nome, non con lo slug
        $starterName = $kind->starterNames($owner->getLocale())[$text] ?? null;
        if ($starterName !== null) {
            $this->ensureStarters($owner, $org, $kind);

            return $this->repository->findStarter($owner, $org, $kind, $text)
                ?? $this->findOrCreate($owner, $org, $kind, $starterName);
        }

        return $this->findOrCreate($owner, $org, $kind, $text);
    }

    /**
     * Il veicolo cambia proprietario: i suoi record che puntano a voci di altre liste passano alla voce
     * con lo stesso nome nella lista di $newOwner (creata se manca; una categoria di partenza va su quella
     * con la stessa chiave). Le voci della lista precedente restano. I record si ricollegano via ORM e il
     * flush è del chiamante (non merge(), che rilegge i record e perderebbe le modifiche non salvate);
     * le categorie di partenza mancanti a $newOwner si inseriscono via SQL (ensureStarters()).
     */
    public function rehome(Vehicle $vehicle, User $newOwner): void
    {
        if ($vehicle->getId() === null) {
            return; // veicolo appena creato: ancora nessun record
        }

        $org = $vehicle->getOrganization();
        /** @var array<int, UserValue> $targets voce di origine (id) => voce nella lista di $newOwner */
        $targets = [];
        foreach (UserValueKind::cases() as $kind) {
            // Per campo, non per classe del record: una manutenzione ha due voci (officina e negozio), il negozio
            // sta anche nei componenti, la colonna categoria delle spese porta categorie o tipi di tassa
            foreach (UserValueRepository::usagesOf($kind) as [$entity, $field]) {
                $meta = $this->em->getClassMetadata($entity);
                foreach ($this->repository->findVehicleRecordsOutsideList($entity, $field, $kind, $vehicle, $newOwner) as $record) {
                    $source = $meta->getFieldValue($record, $field);
                    if (!$source instanceof UserValue) {
                        continue;
                    }
                    $meta->setFieldValue($record, $field, $targets[(int) $source->getId()] ??= $this->counterpart($source, $newOwner, $org));
                }
            }
        }
    }

    /**
     * Voce corrispondente a $source nella lista di $owner: stessa voce di partenza se c'è, altrimenti stesso nome.
     * Una voce eliminata resta eliminata: $owner ne riceve una copia già eliminata, che non entra nella sua lista.
     */
    private function counterpart(UserValue $source, User $owner, Organization $org): UserValue
    {
        if ($source->isDeleted()) {
            $copy = new UserValue($owner, $org, $source->getKind(), $source->getName(), $source->getStarterKey());
            $copy->softDelete();
            $this->em->persist($copy);

            return $copy;
        }

        $starterKey = $source->getStarterKey();
        if ($starterKey !== null) {
            $this->ensureStarters($owner, $org, $source->getKind());
            $starter = $this->repository->findStarter($owner, $org, $source->getKind(), $starterKey);
            if ($starter !== null) {
                return $starter;
            }
        }

        return $this->findOrCreate($owner, $org, $source->getKind(), $source->getName());
    }

    public function rename(UserValue $value, string $name): void
    {
        $name = UserValue::normalizeName($name);
        $clash = $this->repository->findOneInList($value->getUser(), $value->getOrganization(), $value->getKind(), UserValue::keyOf($name));
        if ($clash !== null && $clash->getId() !== $value->getId()) {
            throw new UserValueNameTakenException();
        }

        $value->rename($name);
        $this->em->flush();
    }

    /** Ripunta i record di $from su $into e rimuove $from. */
    public function merge(UserValue $from, UserValue $into): void
    {
        if ($from->getId() === $into->getId()
            || $from->getUser()->getId() !== $into->getUser()->getId()
            || $from->getOrganization()->getId() !== $into->getOrganization()->getId()
            || $from->getKind() !== $into->getKind()
            || $from->isDeleted()
            || $into->isDeleted()
        ) {
            throw new \InvalidArgumentException('Le due voci non sono unibili.');
        }

        $this->em->wrapInTransaction(function () use ($from, $into): void {
            foreach (UserValueRepository::usagesOf($from->getKind()) as [$entity, $field]) {
                $this->em->createQueryBuilder()
                    ->update($entity, 'r')
                    ->set("r.$field", ':into')
                    ->where("r.$field = :from")
                    ->setParameter('into', $into)
                    ->setParameter('from', $from)
                    ->getQuery()
                    ->execute();

                // L'UPDATE DQL non tocca i record già caricati: li rileggo, altrimenti il flush li troverebbe ancora su $from
                $meta = $this->em->getClassMetadata($entity);
                foreach ($this->em->getUnitOfWork()->getIdentityMap()[$entity] ?? [] as $record) {
                    if ($meta->getFieldValue($record, $field) === $from) {
                        $this->em->refresh($record);
                    }
                }
            }

            $this->em->remove($from);
            $this->em->flush();
        });
    }

    /**
     * Eliminazione morbida, anche di una voce usata: sparisce da lista e suggerimenti, i record la mostrano ancora.
     * Resta anche se nessun record la usa: così chi elimina tutte le categorie non si vede tornare quelle di partenza.
     */
    public function delete(UserValue $value): void
    {
        $value->softDelete();
        $this->em->flush();
    }
}
