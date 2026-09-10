<?php

namespace App\Entity\Etudiant;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Structure\StructureAnnee;
use App\Entity\Structure\StructureAnneeUniversitaire;
use App\Entity\Structure\StructureDepartement;
use App\Entity\Traits\OldIdTrait;
use App\Entity\Traits\UuidTrait;
use App\Entity\Users\Etudiant;
use App\Filter\EtudiantScolariteFilter;
use App\Repository\EtudiantScolariteRepository;
use App\State\Provider\Etudiant\EtudiantCountProvider;
use App\State\Provider\Etudiant\EtudiantListeProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: EtudiantScolariteRepository::class)]
#[ORM\HasLifecycleCallbacks]
class EtudiantScolarite
{
    use UuidTrait;
    use OldIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'scolarites')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etudiant $etudiant = null;

    #[ORM\Column]
    private int $ordre = 1;

    #[ORM\Column(nullable: true)]
    private ?float $moyenne = null;

    #[ORM\Column]
    private int $nbAbsences = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private bool $public = false;

    #[ORM\Column(nullable: true)]
    private ?array $moyennesMatiere = null;

    #[ORM\Column(nullable: true)]
    private ?array $moyennesUe = null;

    #[ORM\ManyToOne(inversedBy: 'scolarites')]
    #[ORM\JoinColumn(nullable: false)]
    private ?StructureAnneeUniversitaire $anneeUniversitaire = null;

    #[ORM\ManyToOne(inversedBy: 'scolarites')]
    private ?StructureDepartement $departement = null;

    #[ORM\OneToMany(targetEntity: EtudiantScolariteSemestre::class, mappedBy: 'scolarite', orphanRemoval: true, cascade: ['remove'])]
    private Collection $scolariteSemestre;

    #[ORM\Column]
    private bool $actif = false;

    #[ORM\Column(nullable: true)]
    private ?bool $decision = null;

    #[ORM\ManyToOne(inversedBy: 'etudiantScolaritesPropositions')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?StructureAnnee $proposition = null;

    public function __construct()
    {
        $this->scolariteSemestre = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEtudiant(): ?Etudiant { return $this->etudiant; }
    public function setEtudiant(?Etudiant $etudiant): static { $this->etudiant = $etudiant; return $this; }
    public function getOrdre(): ?int { return $this->ordre; }
    public function setOrdre(int $ordre = 1): static { $this->ordre = $ordre; return $this; }
    public function getMoyenne(): ?float { return $this->moyenne; }
    public function setMoyenne(?float $moyenne): static { $this->moyenne = $moyenne; return $this; }
    public function getNbAbsences(): ?int { return $this->nbAbsences; }
    public function setNbAbsences(int $nbAbsences = 0): static { $this->nbAbsences = $nbAbsences; return $this; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $commentaire): static { $this->commentaire = $commentaire; return $this; }
    public function isPublic(): ?bool { return $this->public; }
    public function setPublic(bool $public): static { $this->public = $public; return $this; }
    public function getMoyennesMatiere(): ?array { return $this->moyennesMatiere; }
    public function setMoyennesMatiere(?array $moyennesMatiere): static { $this->moyennesMatiere = $moyennesMatiere; return $this; }
    public function getMoyennesUe(): ?array { return $this->moyennesUe; }
    public function setMoyennesUe(?array $moyennesUe): static { $this->moyennesUe = $moyennesUe; return $this; }
    public function getAnneeUniversitaire(): ?StructureAnneeUniversitaire { return $this->anneeUniversitaire; }
    public function setAnneeUniversitaire(?StructureAnneeUniversitaire $anneeUniversitaire): static { $this->anneeUniversitaire = $anneeUniversitaire; return $this; }
    public function getDepartement(): ?StructureDepartement { return $this->departement; }
    public function setDepartement(?StructureDepartement $departement): static { $this->departement = $departement; return $this; }
    public function getScolariteSemestre(): Collection { return $this->scolariteSemestre; }
    public function addScolariteSemestre(EtudiantScolariteSemestre $scolariteSemestre): static { if (!$this->scolariteSemestre->contains($scolariteSemestre)) { $this->scolariteSemestre->add($scolariteSemestre); $scolariteSemestre->setScolarite($this); } return $this; }
    public function removeScolariteSemestre(EtudiantScolariteSemestre $scolariteSemestre): static { if ($this->scolariteSemestre->removeElement($scolariteSemestre) && $scolariteSemestre->getScolarite() === $this) { $scolariteSemestre->setScolarite(null); } return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): void { $this->actif = $actif; }
    public function getDecision(): ?bool { return $this->decision; }
    public function setDecision(?bool $decision): void { $this->decision = $decision; }
    public function getProposition(): ?StructureAnnee { return $this->proposition; }
    public function setProposition(?StructureAnnee $proposition): static { $this->proposition = $proposition; return $this; }
}
