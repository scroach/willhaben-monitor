<?php

namespace App\Entity;

use App\Repository\ListingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ListingRepository::class)]
#[ORM\Table(name: 'listings')]
class Listing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $willhabenId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $firstSeen = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $lastSeen = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\OneToMany(mappedBy: 'listing', targetEntity: ListingData::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $listingData;

    public function __construct()
    {
        $this->listingData = new ArrayCollection();
        $this->firstSeen = new \DateTimeImmutable();
        $this->lastSeen = new \DateTimeImmutable();
    }

    public static function fromJson(array $json): self
    {
        $result = new self();
        $result->setTitle($json['description'] ?? '');
        $result->setWillhabenId($json['id'] ?? 0);
        $result->addListingData(new ListingData($json));

        return $result;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWillhabenId(): ?int
    {
        return $this->willhabenId;
    }

    public function setWillhabenId(int $willhabenId): self
    {
        $this->willhabenId = $willhabenId;

        return $this;
    }

    public function getFirstSeen(): ?\DateTimeInterface
    {
        return $this->firstSeen;
    }

    public function setFirstSeen(\DateTimeInterface $firstSeen): self
    {
        $this->firstSeen = $firstSeen;

        return $this;
    }

    public function getLastSeen(): ?\DateTimeInterface
    {
        return $this->lastSeen;
    }

    public function setLastSeen(\DateTimeInterface $lastSeen): self
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return Collection<int, ListingData>
     */
    public function getListingData(): Collection
    {
        return $this->listingData;
    }

    public function addListingData(ListingData $listingData): self
    {
        if (!$this->listingData->contains($listingData)) {
            $this->listingData->add($listingData);
            $listingData->setListing($this);
        }

        return $this;
    }

    public function removeListingData(ListingData $listingData): self
    {
        if ($this->listingData->removeElement($listingData)) {
            // set the owning side to null (unless already changed)
            if ($listingData->getListing() === $this) {
                $listingData->setListing(null);
            }
        }

        return $this;
    }

    public function getCurrentListingData(): ?ListingData
    {
        return $this->listingData->last() ?: null;
    }
    public function getTitleImage(): ?string
    {
        return $this->getCurrentListingData()?->getImages()[0];
    }

    public function getMaxPrice(): ?float
    {
        return max(array_map(fn(ListingData $l) => $l->getPrice(), $this->getListingData()->toArray()));
    }

    public function getMinPrice(): ?float
    {
        return min(array_map(fn(ListingData $l) => $l->getPrice(), $this->getListingData()->toArray()));
    }
}
