<?php

namespace App\Entity;

use App\Enum\StampColor;
use App\Enum\StampSymbol;
use App\Repository\SeasonStampRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tampon d'une saison : le symbole et la couleur apposes sur la carte
 * d'adherent en face de l'annee reglee.
 *
 * Une saison porte le millesime de l'annee qu'elle couvre, et non celui du
 * depot : l'annee associative court du 1er octobre au 30 septembre, donc une
 * adhesion prise le 1er octobre 2026 vaut pour la saison 2027.
 *
 * Le couple symbole + couleur est une decision de l'association, pas une
 * regle de calcul : il se saisit dans l'administration. Un couple deduit par
 * une rotation en dur empecherait d'en changer l'annee ou une erreur a ete
 * imprimee.
 */
#[ORM\Entity(repositoryClass: SeasonStampRepository::class)]
#[ORM\Table(name: 'season_stamp')]
#[ORM\UniqueConstraint(name: 'uniq_season_stamp_year', columns: ['year'])]
class SeasonStamp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'year')]
    #[Assert\NotNull(message: 'Indiquez la saison.')]
    #[Assert\Range(
        min: 2000,
        max: 2100,
        notInRangeMessage: 'Une saison s\'écrit en quatre chiffres, entre {{ min }} et {{ max }}.',
    )]
    private ?int $year = null;

    #[ORM\Column(length: 20, enumType: StampSymbol::class)]
    #[Assert\NotNull(message: 'Choisissez un symbole.')]
    private ?StampSymbol $symbol = null;

    #[ORM\Column(length: 20, enumType: StampColor::class)]
    #[Assert\NotNull(message: 'Choisissez une couleur.')]
    private ?StampColor $color = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getSymbol(): ?StampSymbol
    {
        return $this->symbol;
    }

    public function setSymbol(?StampSymbol $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getColor(): ?StampColor
    {
        return $this->color;
    }

    public function setColor(?StampColor $color): static
    {
        $this->color = $color;

        return $this;
    }
}
