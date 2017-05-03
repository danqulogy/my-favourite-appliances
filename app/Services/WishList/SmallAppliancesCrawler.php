<?php

namespace App\Services\WishList;

use App\Models\Appliance\Appliance;
use App\Models\Appliance\ApplianceCategory;
use App\Repositories\Frontend\Appliance\ApplianceRepository;
use Illuminate\Support\Facades\Storage;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\Collection;

class SmallAppliancesCrawler
{
    const SMALL_APPLIANCES_URL = 'https://www.appliancesdelivered.ie/search/small-appliances';

    /**
     * @var ApplianceRepository
     */
    private $applianceRepository;

    /**
     * SmallAppliancesCrawler constructor.
     * @param ApplianceRepository $applianceRepository
     */
    public function __construct(ApplianceRepository $applianceRepository)
    {
        $this->applianceRepository = $applianceRepository;
    }

    /**
     * @param callable $callbackAfterPageLoaded
     * @param callable $callbackAfterItemProcessed
     */
    public function importData(callable $callbackAfterPageLoaded, callable $callbackAfterItemProcessed)
    {
        $dom = new Dom;
        $pageIndex = 1;

        do {
            $pageUrl = $this->buildResultsPageUrl($pageIndex);
            $pageContent = file_get_contents($pageUrl);
            $dom->load($pageContent);
            $callbackAfterPageLoaded($pageUrl);
            $products = $this->parseProductList($callbackAfterItemProcessed, $dom);
            $pageIndex++;
        } while ($products->count() > 0);
    }

    /**
     * @param callable $callbackAfterItemProcessed
     * @param Dom $dom
     * @return array|Collection
     */
    private function parseProductList(callable $callbackAfterItemProcessed, $dom)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $products = $dom->find('div.search-results-product');

        /** @var Collection $product */
        foreach ($products as $product) {
            $data = $this->parseProduct($product);
            $this->importProductImage($data);
            $appliance = $this->createOrUpdateAppliance($data);
            $callbackAfterItemProcessed($appliance);
        }

        return $products;
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProduct($product)
    {
        $productId = $this->parseProductId($product);
        $data['external_id'] = $productId;
        $data['title'] = $this->parseProductTile($product);
        $data['description'] = $this->parseProductDescription($product);
        $data['product_url'] = $this->parseProductUrl($product);
        $data['image'] = $productId;
        $data['image_url'] = $this->parseProductImageUrl($product);
        $data['category'] = ApplianceCategory::SMALL_APPLIANCE;
        $data['price_amount'] = $this->parseProductPriceAmount($product);
        $data['price_currency'] = 'EUR'; // All product prices are in euros.
        return $data;
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProductTile($product)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return $product->find('h4')->find('a')->innerHtml();
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProductDescription($product)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $description = $product->find('ul.result-list-item-desc-list')->innerHtml();
        return empty($description) ? '' : "<ul>$description</ul>";
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProductUrl($product)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return $product->find('h4')->find('a')->getAttribute('href');
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProductId($product)
    {
        $productUrl = $this->parseProductUrl($product);
        if (preg_match("/\/(\d+)$/", $productUrl, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProductImageUrl($product)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return $product->find('div.product-image')->find('img.img-responsive')->getAttribute('src');
    }

    /**
     * @param $product
     * @return mixed
     */
    private function parseProductPriceAmount($product)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $price = $product->find('h3')->innerHtml();
        $price = str_replace('&euro;', '', $price);
        $price = str_replace(',', '', $price);
        $priceInCents = round($price * 100);
        return $priceInCents;
    }

    /**
     * @param array $data
     */
    private function importProductImage(array $data)
    {
        $imageName = $data['external_id'];
        /** @noinspection PhpUndefinedMethodInspection */
        Storage::put("public/appliances/$imageName", file_get_contents($data['image_url']), 'public');
    }

    /**
     * @param $data
     * @return static
     */
    private function createOrUpdateAppliance($data)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $appliance = Appliance::updateOrCreate(
            ['external_id' => $data['external_id']],
            $data
        );
        return $appliance;
    }

    /**
     * @param $pageIndex
     * @return string
     */
    private function buildResultsPageUrl($pageIndex)
    {
        return self::SMALL_APPLIANCES_URL . "?page=$pageIndex";
    }
}