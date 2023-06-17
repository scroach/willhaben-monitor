<?php

namespace App\Entity;

use App\Repository\ListingDataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ListingDataRepository::class)]
#[ORM\Table(name: 'listings_data')]
class ListingData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'listingData')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Listing $listing = null;

    #[ORM\Column]
    private array $data = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $created_at = null;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->created_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListing(): ?Listing
    {
        return $this->listing;
    }

    public function setListing(?Listing $listing): self
    {
        $this->listing = $listing;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeImmutable $created_at): self
    {
        $this->created_at = $created_at;

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        foreach ($this->data['attributes']['attribute'] as $attribute) {
            if($key === $attribute['name']) {
                return array_values($attribute['values'])[0];
            }
        }
        return null;
    }

    public function getAttributeAsString(string $key): string
    {
        foreach ($this->data['attributes']['attribute'] as $attribute) {
            if($key === $attribute['name']) {
                return implode(', ', $attribute['values']);
            }
        }
        return '';
    }

    public function getPrice(): ?float
    {
        return $this->getAttribute('PRICE');
    }

    public function getRooms(): ?int
    {
        return $this->getAttribute('NUMBER_OF_ROOMS');
    }

    public function getImages(): array
    {
        return explode(';', $this->getAttribute('ALL_IMAGE_URLS'));
    }

    public function getLivingSize(): ?float
    {
        return $this->getAttribute('ESTATE_SIZE');
    }

    public function getFreeArea(): ?float
    {
        return $this->getAttribute('FREE_AREA/FREE_AREA_AREA_TOTAL');
    }

    public function getFreeAreaType(): ?string
    {
        return $this->getAttributeAsString('FREE_AREA_TYPE_NAME');
    }
}
