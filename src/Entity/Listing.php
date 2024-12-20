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

    #[ORM\OneToMany(mappedBy: 'listing', targetEntity: ListingData::class, orphanRemoval: true, cascade: ['persist'], fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['created_at' => 'DESC'])]
    private Collection $listingData;

    #[ORM\Column(length: 255)]
    private ?string $city = null;
    #[ORM\Column(length: 50)]
    private ?string $zip = null;
    #[ORM\Column(length: 255)]
    private ?string $titleImage = null;
    #[ORM\Column]
    private ?float $priceMin = null;
    #[ORM\Column]
    private ?float $priceMax = null;
    #[ORM\Column]
    private ?float $priceCurrent = null;
    #[ORM\Column]
    private ?float $priceCurrentPerSqm = null;
    #[ORM\Column]
    private ?float $area = null;
    #[ORM\Column]
    private bool $isStarred = false;

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
        $listingData = new ListingData($json);
        $result->listingData->add($listingData);
        $result->addListingData($listingData);

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
        // do not fetch listing data collection here because that may be a lot of memory..
        $listingData->setListing($this);

        $this->updateAggregatedDataDaily($listingData);

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
        $data = $this->listingData->toArray();
        usort($data, fn (ListingData $a, ListingData $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        return end($data) ?: null;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(?string $zip): void
    {
        $this->zip = $zip;
    }

    public function getPriceMin(): ?float
    {
        return $this->priceMin;
    }

    public function setPriceMin(?float $priceMin): void
    {
        $this->priceMin = $priceMin;
    }

    public function getPriceMax(): ?float
    {
        return $this->priceMax;
    }

    public function setPriceMax(?float $priceMax): void
    {
        $this->priceMax = $priceMax;
    }

    public function getArea(): ?float
    {
        return $this->area;
    }

    public function setArea(?float $area): void
    {
        $this->area = $area;
    }

    public function getPriceCurrent(): ?float
    {
        return $this->priceCurrent;
    }

    public function setPriceCurrent(?float $priceCurrent): void
    {
        $this->priceCurrent = $priceCurrent;
    }

    public function getTitleImage(): ?string
    {
        return $this->titleImage;
    }

    public function setTitleImage(?string $titleImage): void
    {
        $this->titleImage = $titleImage;
    }

    public function getPriceCurrentPerSqm(): ?float
    {
        return $this->priceCurrentPerSqm;
    }

    public function setPriceCurrentPerSqm(?float $priceCurrentPerSqm): void
    {
        $this->priceCurrentPerSqm = $priceCurrentPerSqm;
    }

    public function updateAggregatedDataDaily(ListingData $newData): void
    {
        $priceCurrent = $newData->getPrice();
        $prices = [$this->getPriceCurrent(), $priceCurrent];
        // filter zero values
        $prices = array_filter($prices, fn(?float $price) => $price > 10);
        $this->setPriceMin(count($prices) ? min($prices) : null);
        $this->setPriceMax(count($prices) ? max($prices) : null);
        $this->setPriceCurrent($priceCurrent > 10 ? $priceCurrent : null);
        $this->setArea($newData->getLivingSize());

        $this->setPriceCurrentPerSqm(null);
        if ($priceCurrent > 10 && $this->getArea() > 10) {
            $this->setPriceCurrentPerSqm($priceCurrent / $this->getArea());
        }

        $this->setCity($newData->getCity());
        $this->setZip($newData->getZip());
        $this->setTitleImage($newData->getImages()[0] ?? null);
        $this->setTitle($newData->getTitle() ?? $this->getTitle());
    }

    public function updateAggregatedDataFull(): void
    {
        $prices = array_map(fn(ListingData $l) => $l->getPrice(), $this->getListingData()->toArray());
        // filter zero values
        $prices = array_filter($prices, fn(?float $price) => $price > 10);
        $this->setPriceMin(count($prices) ? min($prices) : null);
        $this->setPriceMax(count($prices) ? max($prices) : null);
        $priceCurrent = $this->getCurrentListingData()?->getPrice();
        $this->setPriceCurrent($priceCurrent > 10 ? $priceCurrent : null);
        $this->setArea($this->getCurrentListingData()->getLivingSize());

        $this->setPriceCurrentPerSqm(null);
        if ($priceCurrent > 10 && $this->getArea() > 10) {
            $this->setPriceCurrentPerSqm($priceCurrent / $this->getArea());
        }

        $this->setCity($this->getCurrentListingData()->getCity());
        $this->setZip($this->getCurrentListingData()->getZip());
        $this->setTitleImage($this->getCurrentListingData()?->getImages()[0] ?? null);
        $this->setTitle($this->getCurrentListingData()?->getTitle() ?? $this->getTitle());
    }

    public function getSaleReduction(): float
    {
        if($this->getPriceCurrent() > 1000 && $this->getPriceMax() > 1000) {
            return 100 - ($this->getPriceCurrent() / $this->getPriceMax() * 100);
        } else {
            return 0;
        }
    }

    public function getAgeInWeeks(): ?int
    {
        return $this->firstSeen?->diff($this->lastSeen)->days/7;
    }

    public function isStarred(): bool
    {
        return $this->isStarred;
    }

    public function setIsStarred(bool $isStarred): void
    {
        $this->isStarred = $isStarred;
    }

}
