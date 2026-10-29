<?php

namespace App\Entity;

enum ListingType: string
{
    case HOUSE = 'house';
    case LAND = 'land';

    public function label(): string
    {
        return match ($this) {
            self::HOUSE => 'Haus',
            self::LAND => 'Grundstück',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::HOUSE => 'Häuser',
            self::LAND => 'Grundstücke',
        };
    }
}
