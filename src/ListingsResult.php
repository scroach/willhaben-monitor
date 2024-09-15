<?php

namespace App;

use App\Entity\Listing;

class ListingsResult
{
    private array $data = [];

    /**
     * @return Listing[]
     */
    public function getListings(): array
    {
        $listings = [];
        foreach ($this->getSearchResult()['advertSummaryList']['advertSummary'] as $listingData) {
            $listings[] = Listing::fromJson($listingData);
        }

        return $listings;
    }

    public static function fromJson(array $json): self
    {
        $result = new ListingsResult();
        $result->data = $json;


        //        "verticalId": 2,
        //        "searchId": 102,
        //        "rowsRequested": 10,
        //        "rowsFound": 1352,
        //        "rowsReturned": 10,
        //        "pageRequested": 1, props.pageProps.searchResult.rowsReturned

        return $result;
    }

    public function getCurrentPage(): int
    {
        return $this->getSearchResult()['pageRequested'] ?? -1;
    }

    public function getMaxPage(): int
    {
        if ($this->getSearchResult()['rowsReturned']) {
            return ceil($this->getSearchResult()['rowsFound'] / $this->getSearchResult()['rowsReturned']);
        } else {
            return 0;
        }
    }

    private function getSearchResult(): mixed
    {
        return $this->data['props']['pageProps']['searchResult'];
    }

}