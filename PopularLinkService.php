<?php

namespace App\Service\PopularLink;

use App\Entity\Form\ProfileSearch;
use App\Entity\Region;
use App\Enum\Site\Domain;
use App\Enum\User\Gender;
use App\Service\Ads\AdsService;
use App\Service\City\CityService;
use App\Service\Data\ApiExchanger;
use App\Service\Dating\LangGenderService;
use App\Service\Region\RegionService;
use App\Twig\AppExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PopularLinkService
{
    /**
     * @var RouterInterface
     */
    private $router;
    /**
     * @var TranslatorInterface
     */
    private $translator;
    /**
     * @var AppExtension
     */
    private $appExtension;
    /**
     * @var RequestStack
     */
    private $requestStack;
    /**
     * @var CityService
     */
    private $cityService;
    /**
     * @var AdsService
     */
    private $adsService;
    /**
     * @var ApiExchanger
     */
    private $apiExchanger;

    public function __construct(RouterInterface $router, TranslatorInterface $translator, AppExtension $appExtension, RequestStack $requestStack, CityService $cityService, AdsService $adsService, ApiExchanger $apiExchanger)
    {
        $this->router = $router;
        $this->translator = $translator;
        $this->appExtension = $appExtension;
        $this->requestStack = $requestStack;
        $this->cityService = $cityService;
        $this->adsService = $adsService;
        $this->apiExchanger = $apiExchanger;
    }

    /**
     * Женщины ➞ Онлайн
     * Женщины ➞ Новые
     * Женщины ➞ Онлайн новые
     * Мужчины ➞ Онлайн
     * Мужчины ➞ Новые
     * Мужчины ➞ Онлайн новые
     * Пары ➞ Онлайн
     * Пары ➞ Новые
     * Пары ➞ Онлайн новые
     *
     * @return array
     */
    public function getDatingLinks(): array
    {
        $links = [];
        foreach ($this->getGender() as $gender) {
            $links = array_merge($links, $this->getLinksByGender($gender));
        }

        return $links;
    }


    public function getCurrentDatingCity(): array
    {
        $currentCity = [];
        $cities = $this->apiExchanger->getCities();
        foreach ($cities as $city) {
           if (in_array($city['id'], CityService::CITY_ID_ARRAY)) {
               $currentCity[] = $city;
           }
       }
        return $currentCity;
    }

    public function getCurrentDatingRegion(): array
    {
        $currentRegion = [];
        $regions = $this->apiExchanger->getRegions();
        foreach ($regions as $region) {
            if (in_array($region['id'], RegionService::REGION_ID_ARRAY)) {
                $currentRegion[] = $region;
            }
        }
        return $currentRegion;
    }

    /**
     * @return array
     */
    public function getGender(): array
    {
        $genders = [Gender::GENDER_FEMALE, Gender::GENDER_MALE, Gender::GENDER_PAIR];
        if (Domain::isGribuWL() || Domain::isXdateWL()) {
            $genders[] = Gender::GENDER_TRANS;
        }
        if (Domain::isBbs() || Domain::isGayBoard()) {
            $genders[] = Gender::GENDER_TRANS;
            $genders[] = Gender::GENDER_PAIR_GAY;
        }
        return $genders;
    }

    /**
     * @param string $gender
     * @return array[]
     */
    public function getLinksByGender(string $gender): array
    {
        $genderUpper = ucfirst($gender);
        $onlineUrlPart = $this->getOnlineUrlPart();
        $newUrlPart = $this->getNewUrlPart();
        $newOnlineUrlPart = $this->getNewOnlineUrlPart();
        $photoUrlPart = $this->getPhotoUrlPart();
        $genderUrlPart = $this->getGenderUrlPart($gender);
        $datingRawLink = $this->router->generate('dating_dyn');
        $firstPartLink = "{$datingRawLink}/{$genderUrlPart}";

        return [
            [
                'parts' => [$genderUpper, $this->translator->trans('Online')],
                'link' => "{$firstPartLink}?{$onlineUrlPart}"
            ],
            [
                'parts' => [$genderUpper, $this->translator->trans('newest')],
                'link' => "{$firstPartLink}?{$newUrlPart}"
            ],
            [
                'parts' => [$genderUpper, "{$this->translator->trans('newest')} {$this->translator->trans('Online')}"],
                'link' => "{$firstPartLink}?{$newOnlineUrlPart}"
            ],
            [
                'parts' => [$genderUpper, "{$this->translator->trans('With photo')}"],
                'link' => "{$firstPartLink}?{$photoUrlPart}"
            ]
        ];
    }

    public function getOnlineUrlPart()
    {
        return ProfileSearch::URL_PREFIX['online'] . "=y";
    }

    public function getNewUrlPart()
    {
        return ProfileSearch::URL_PREFIX['newest'] . "=n";
    }

    public function getNewOnlineUrlPart()
    {
        return ProfileSearch::URL_PREFIX['online'] . "=y&t=n";
    }

    public function getCityUrlPart($cityCode)
    {
        $city = $this->cityService->getCityById($cityCode);
        if($city && isset($city['uri']) && $city['uri']) {
            return $city['uri'];
        }
        return ProfileSearch::URL_PREFIX['city'] . "={$cityCode}";
    }

    public function getPhotoUrlPart()
    {
        return ProfileSearch::URL_PREFIX['photos'] . "=photo";
    }

    public function getGenderUrlPart(string $gender)
    {
        return LangGenderService::getLangGenderByGender($gender, $this->requestStack->getCurrentRequest()->getLocale());
    }

    public function getCategoryLinks(int $categoryId)
    {
        $qty = $this->getMainCitiesQty();
        $cities = $this->appExtension->getCities($qty);
        $links = [];
        $categoryName = $this->getCategoryName($categoryId);

        foreach ($cities as $city) {
            $links[] = [
                'parts' => [$categoryName, $city['name']],
                'link' => $this->router->generate($this->adsService->getAdsCategoryRoute($categoryId, true), [
                    'city' => strtolower($city['name'])
                ]),
            ];
        }

        $links[] = [
            'parts' => [$categoryName, $this->translator->trans('All city')],
            'link' => $this->router->generate($this->adsService->getAdsCategoryRoute($categoryId), [
                'city' => 'all'
            ])
        ];

        return $links;
    }

    private function getMainCitiesQty()
    {
        switch (getenv('SITE_DOMAIN')) {
            case Domain::XKUMPPANI_FI:
            case Domain::X_KUMPPANI_FI:
                return 6;
            case Domain::XDATE_LT:
            case Domain::GRIBU_LV:
            case Domain::ESCORT_LV:
                return 5;
            default:
                return 4;
        }
    }

    private function getCategoryName(int $categoryId)
    {
        $categories = $this->appExtension->getCategories();
        $currentCategory = null;

        foreach ($categories as $category) {
            if($category['id'] === $categoryId) {
                $currentCategory = $category;
            }
        }

        if(!$currentCategory) {
            return null;
        }

        return $currentCategory['lang'][$this->getLocale()]['name_mobile'];
    }

    private function getLocale()
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request->getLocale() ?: $request->getDefaultLocale();
    }

    public function getCountryUrlPart($cityCode)
    {
        return ProfileSearch::URL_PREFIX['country'] . "_{$cityCode}";
    }

    public function getBbsCategoriesHotLinks(int $currentCategoryId)
    {
        $cityCategories = $this->getCityCategories($currentCategoryId);
        $cityUri = $this->cityService->getSelectCityUri();

        $links = [];

        foreach ($cityCategories as $category) {
            $links[] = [
                'parts' => $this->getBbsCategoryHotLinkParts($category['lang'][$this->getLocale()]['name_mobile']),
                'link' => $this->router->generate($this->adsService->getAdsCategoryRoute($category['id']), ['city' => $cityUri])
            ];
        }

        return $links;
    }

    public function getCityCategories(int $currentCategoryId)
    {
        $categories = $this->appExtension->getCategories();
        $selectCityId = $this->cityService->getSelectCityId();
        $resultCategories = [];

        foreach ($categories as $category) {
            if(isset($category['city']) && $category['city'] == $selectCityId && $currentCategoryId !== $category['id']) {
                $resultCategories[] = $category;
            }
        }

        return $resultCategories;
    }

    private function getBbsCategoryHotLinkParts(string $categoryName)
    {
        $currentCityName = $this->cityService->getSelectCityName();

        return ["{$this->translator->trans('Гей-объявления')}:", $categoryName, $this->translator->trans($currentCityName)];
    }

    public function getBbsHotLinkByAds($adsId)
    {
        $ads = $this->adsService->getOneAds($adsId);
        $hotLink = [];

        if(!$ads) {
            return $hotLink;
        }

        $cityId = $ads->getCity();
        $firstLinkTextPart = $this->translator->trans('Гей-объявления');
        $cityName = $this->translator->trans($ads->getCityName());

        if($cityId === CityService::MOSCOW_ID || $cityId === CityService::SPB_ID) {
            $categories = $this->apiExchanger->getCategories();
            $category = $categories[$ads->getCategory()] ?? null;

            if(!$category) {
                return $hotLink;
            }
            $categoryName = $category['lang'][$this->getLocale()]['name_mobile'];

            $hotLink = [
                'link' => $this->generateBbsCategoryUrl($this->adsService->getAdsCategoryRoute($category['id']), $ads->getCityUri()),
                'parts' => ["{$firstLinkTextPart}:", $categoryName, $cityName]
            ];
        } else {
            return [
                'link' => $this->generateBbsCategoryUrl($this->adsService->getAdsCategoryRoute($ads->getCategory()), $ads->getCityUri()),
                'parts' => [$cityName, $firstLinkTextPart]
            ];
        }

        return $hotLink;
    }

    public function generateBbsCategoryUrl(string $categoryRoute, string $cityUri)
    {
        return $this->router->generate($categoryRoute, ['city' => $cityUri]);
    }

    public static function getProfileSearchRoute(): string
    {
        return 'dating_dyn';
        if (Domain::isGribuWL() || Domain::isXdateWL() || Domain::isXkumppaniWL()) {
            return 'profile_search_start_dyn';
        }

        return 'profile_search_dyn';
    }
}
