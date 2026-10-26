<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\User;
use App\Service\OrganizationSlugs;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class OrganizationSlugsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function slugs(): OrganizationSlugs
    {
        return static::getContainer()->get(OrganizationSlugs::class);
    }

    public function testSlugFromTheName(): void
    {
        $creator = UserFactory::createOne();

        self::assertSame('famiglia-rossi', $this->slugs()->unique('Famiglia Rossi', $creator));
        self::assertSame('workspace', $this->slugs()->unique('!!!', $creator), 'un nome senza lettere né cifre');
    }

    public function testTakenSlugGetsTheCreatorIdAndACounter(): void
    {
        $creator = UserFactory::createOne();
        $id = $creator->getId();
        OrganizationFactory::createOne(['slug' => 'famiglia-rossi']);
        OrganizationFactory::createOne(['slug' => 'famiglia-rossi-'.$id.'-1']);

        self::assertSame('famiglia-rossi-'.$id.'-2', $this->slugs()->unique('Famiglia Rossi', $creator));
    }

    public function testTheCreatorMustBeSaved(): void
    {
        $this->expectException(\LogicException::class);

        $this->slugs()->unique('Famiglia Rossi', new User());
    }
}
