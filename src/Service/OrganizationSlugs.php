<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\OrganizationRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Slug di una nuova organizzazione quando chi la crea non lo sceglie: dal nome, e se è già preso con l'id del
 * creatore e un contatore (`famiglia-rossi-12-1`). Non è atomico: una corsa la ferma l'indice unico al flush.
 */
final class OrganizationSlugs
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly OrganizationRepository $orgRepo,
    ) {
    }

    public function unique(string $name, User $creator): string
    {
        $creatorId = $creator->getId() ?? throw new \LogicException('Il creatore va salvato prima: il suo id entra nello slug.');
        $base = strtolower((string) $this->slugger->slug($name));
        if ($base === '') {
            $base = 'workspace';
        }
        $candidate = $base;
        $suffix = 1;
        while ($this->orgRepo->findOneBySlug($candidate) !== null) {
            $candidate = $base.'-'.$creatorId.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
