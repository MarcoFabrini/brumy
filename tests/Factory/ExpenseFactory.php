<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Expense;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Enum\UserValueKind;
use App\Repository\OrganizationMemberRepository;
use App\Repository\VehicleShareRepository;
use App\Service\UserValues;
use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * `category` accetta una voce oppure un ExpenseCategory (default OTHER), che diventa la voce di partenza
 * corrispondente nella lista del proprietario del veicolo (veicolo orfano: di un utente dell'org).
 * Per una tassa o un'assicurazione passare `section` e la voce giusta (`category` tipo di tassa / null, `insurer`).
 *
 * @extends PersistentObjectFactory<Expense>
 */
final class ExpenseFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly UserValues $values,
        private readonly VehicleShareRepository $shares,
        private readonly OrganizationMemberRepository $members,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return Expense::class;
    }

    /**
     * Voce di partenza `$category` nella lista in cui finirebbero le spese del veicolo. Assicurazione, bollo e tasse
     * non sono più categorie di partenza (hanno una sezione loro): per quelle una categoria qualunque con quel nome.
     */
    public function starterCategory(Vehicle $vehicle, ExpenseCategory $category): UserValue
    {
        $user = $this->listUser($vehicle);
        $text = \in_array($category, ExpenseCategory::starters(), true) ? $category->value : $category->label($user->getLocale());
        $value = $this->values->resolve($vehicle, $user, UserValueKind::EXPENSE_CATEGORY, $text);
        if ($value === null) {
            throw new \LogicException('resolve() non restituisce null per uno slug.');
        }

        return $value;
    }

    protected function defaults(): array
    {
        $vehicle = VehicleFactory::createOne();
        return [
            'organization' => $vehicle->getOrganization(),
            'vehicle' => $vehicle,
            'occurredAt' => new \DateTimeImmutable(self::faker()->dateTimeBetween('-1 year')->format('Y-m-d')),
            'category' => ExpenseCategory::OTHER,
            'description' => self::faker()->sentence(),
            'amount' => (string) self::faker()->randomFloat(2, 5, 500),
        ];
    }

    protected function initialize(): static
    {
        // Sezione e voci si impostano insieme con Expense::classify(): la factory le scrive direttamente
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce('category', 'section', 'insurer'))->beforeInstantiate(function (array $attributes): array {
            if ($attributes['category'] instanceof ExpenseCategory) {
                $vehicle = $attributes['vehicle'] instanceof Factory ? $attributes['vehicle']->create() : $attributes['vehicle'];
                $attributes['vehicle'] = $vehicle;
                $attributes['category'] = $this->starterCategory($vehicle, $attributes['category']);
            }

            return $attributes;
        });
    }

    /** Proprietario del veicolo; se orfano, il primo membro dell'org (creato se l'org non ne ha). */
    private function listUser(Vehicle $vehicle): User
    {
        $owner = $this->shares->findOwnerShare($vehicle)?->getUser();
        if ($owner !== null) {
            return $owner;
        }
        $member = $this->members->findOneBy(['organization' => $vehicle->getOrganization()], ['id' => 'ASC']);

        return $member?->getUser()
            ?? OrganizationMemberFactory::createOne(['organization' => $vehicle->getOrganization()])->getUser();
    }
}
