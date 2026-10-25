<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Component;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Repository\ComponentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Componenti di un veicolo scritti per nome in un lavoro: si trova quello con lo stesso nome (a meno di
 * maiuscole e spazi), altrimenti si crea con quel nome.
 */
final class Components
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComponentRepository $repository,
    ) {
    }

    /**
     * Componenti del veicolo con quei nomi, nell'ordine dato, senza doppioni né nomi vuoti. Un nome di uno dei
     * $current (quelli che il lavoro ha già) tiene quel componente anche se eliminato: modificare il lavoro non lo
     * sostituisce con uno nuovo omonimo.
     * L'inserimento è atomico rispetto all'indice unico (come {@see UserValues::findOrCreate()}): un doppio
     * salvataggio concorrente trova la riga dell'altro invece di fallire.
     *
     * @param list<string>    $names
     * @param list<Component> $current
     *
     * @return list<Component>
     */
    public function resolve(Vehicle $vehicle, array $names, array $current = []): array
    {
        $kept = [];
        foreach ($current as $component) {
            $kept[UserValue::keyOf($component->getName())] = $component;
        }
        $components = [];
        foreach ($names as $name) {
            $name = UserValue::normalizeName($name);
            $key = UserValue::keyOf($name);
            if ($name === '' || isset($components[$key])) {
                continue;
            }

            $found = $kept[$key] ?? $this->repository->findOneByKey($vehicle, $key);
            if ($found === null) {
                $this->em->getConnection()->executeStatement(
                    'INSERT INTO components (organization_id, vehicle_id, name, name_key, created_at, updated_at)
                     VALUES (:org, :vehicle, :name, :key, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE id = id',
                    ['org' => $vehicle->getOrganization()->getId(), 'vehicle' => $vehicle->getId(), 'name' => $name, 'key' => $key],
                );
                $found = $this->repository->findOneByKey($vehicle, $key)
                    ?? throw new \LogicException('Componente appena inserito non trovato.');
            }
            $components[$key] = $found;
        }

        return array_values($components);
    }
}
