<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Component;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * `vehicle` e `name` (già normalizzato) vanno al costruttore, che prende l'organizzazione dal veicolo; gli altri campi
 * dai setter. Per un componente eliminato: `->softDelete()` e flush dopo la creazione.
 *
 * @extends PersistentObjectFactory<Component>
 */
final class ComponentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Component::class;
    }

    protected function defaults(): array
    {
        return [
            'vehicle' => VehicleFactory::new(),
            'name' => self::faker()->unique()->words(2, true),
        ];
    }
}
