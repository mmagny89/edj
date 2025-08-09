<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Member
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 10, unique: true)]
    private string $memberNumber; // ex. 2025-001

    public function getMemberNumber(): string
    {
        return $this->memberNumber;
    }

    public function setMemberNumber(string $memberNumber): self
    {
        $this->memberNumber = $memberNumber;
        return $this;
    }
}
