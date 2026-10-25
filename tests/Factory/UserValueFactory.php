<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\UserValue;
use App\Enum\UserValueKind;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * `user` e `organization` vanno passati dal chiamante.
 *
 * @extends PersistentObjectFactory<UserValue>
 */
final class UserValueFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return UserValue::class;
    }

    protected function defaults(): array
    {
        return [
            'kind' => UserValueKind::STATION,
            'name' => self::faker()->unique()->company(),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(static function (array $attributes): UserValue {
            return new UserValue($attributes['user'], $attributes['organization'], $attributes['kind'], $attributes['name'], $attributes['starterKey'] ?? null);
        });
    }
}
