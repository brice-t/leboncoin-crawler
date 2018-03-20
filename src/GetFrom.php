<?php

namespace Lbc;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Lbc\Crawler\AdCrawler;
use Lbc\Crawler\SearchResultCrawler;
use Lbc\Parser\SearchResultUrlParser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class GetFrom
 * @package Lbc
 */
class GetFrom
{
    /**
     * @var Client
     */
    protected $client;

    protected $authenticated = false;

    protected $token = null;
    protected $personaldata = null;

    /**
     * GetFrom constructor.
     * @param Client|null $client
     */
    public function __construct(Client $client = null)
    {
        $this->client = $client ?: new Client();
    }

    /**
     * Return the http client
     * (useful to mock the response for unit testing)
     *
     * @return Client
     */
    public function getHttpClient()
    {
        return $this->client;
    }

    /**
     * retrieve the search result data from the given url
     *
     * @param $url
     * @param bool $detailedAd
     *
     * @return array
     */
    public function search($url, $detailedAd = false)
    {
        $searchData = new SearchResultCrawler(
            new Crawler((string) $this->client->get($url)->getBody()),
            $url
        );

        $url = new SearchResultUrlParser($url, $searchData->getNbPages());

        $ads = ($detailedAd) ? $searchData->getAds() : $searchData->getAdsId();

        $sumarize = [
            'total_ads'    => $searchData->getNbAds(),
            'total_page'   => $searchData->getNbPages(),
            'ads_per_page' => $searchData->getNbAdsPerPage(),
            'category'     => $searchData->getUrlParser()->getCategory(),
            'location'     => $searchData->getUrlParser()->getLocation(),
            'search_area'  => $searchData->getUrlParser()->getSearchArea(),
            'sort_by'      => $searchData->getUrlParser()->getSortType(),
            'type'         => $searchData->getUrlParser()->getType(),
            'ads'          => $ads,
        ];

        return array_merge($url->getNav(), $sumarize);
    }

    /**
     * Retrieve the ad's data from an ad's ID and its category
     *
     * @param $id
     * @param $category
     *
     * @return array
     */
    private function adById($id, $category)
    {
        return $this->ad("https://www.leboncoin.fr/{$category}/{$id}.htm");
    }

    /**
     * Retrieve the ad's data from the given url
     *
     * @param $url
     * @return array
     */
    private function adByUrl($url)
    {
        $content = $this->client->get($url)->getBody()->getContents();

        $adData = new AdCrawler(new Crawler(utf8_encode($content)), $url);

        return $adData->getAll();
    }

    /**
     * Dynamique method to retrive the data by url OR id and category
     *
     * @return bool|mixed
     */
    public function ad()
    {
        if (func_num_args() === 1) {
            return call_user_func_array([$this, 'adByUrl'], func_get_args());
        }

        if (func_num_args() === 2) {
            return call_user_func_array([$this, 'adById'], func_get_args());
        }

        throw new \InvalidArgumentException('Bad number of argument');
    }



    /**
     * Authenticate to leboncoin (set a connection cookie)
     *
     * @return bool|mixed
     */
    public function connect( $login, $password )
    {
        $response = $this->client->request('POST', 'https://compteperso.leboncoin.fr/store/verify_login/0', [
            'allow_redirects' => false,
            'form_params' => [
                'st_username' => $login,
                'st_passwd' => $password,
            ]
        ]);

        $locationExpectedStart = '/store/main/0';
        if ($response->hasHeader('Location') &&
            substr( $response->getHeader('Location')[0], 0, strlen($locationExpectedStart) ) == $locationExpectedStart) {
            $this->authenticated = true;
            return true;
        }


        $this->authenticated = false;
        return false;
    }


    public function API_fetchToken( $login, $password )
    {
        $response = $this->client->request('POST', 'https://api.leboncoin.fr/api/oauth/v1/token', [
            'allow_redirects' => false,
            'form_params' => [
                'client_id' => 'frontweb',
                'grant_type' => 'password',
                'username' => $login,
                'password' => $password,
            ]
        ]);

        $contentJSON = $response->getBody()->getContents();

        $this->token = json_decode( $contentJSON );

        return $this->token;
    }




    public function API_fetchPersonalData()
    {
        $url = 'https://api.leboncoin.fr/api/accounts/v1/accounts/me/personaldata';

        $contentJSON = $this->client->get($url,
            array(
                'headers' => array(
                    'authorization' => 'Bearer ' . $this->token->access_token,
                )
            ))->getBody()->getContents();

        $this->personaldata = json_decode( $contentJSON );

        return $this->personaldata;
    }



    public function API_listFavoritesAdIds()
    {
        $url = 'https://api.leboncoin.fr/api/accounts/v1/accounts/' . $this->personaldata->storeId . '/bookmarks/classified';

        $contentJSON = $this->client->get($url,
            array(
                'headers' => array(
                    'authorization' => 'Bearer ' . $this->token->access_token,
                )
            ))->getBody()->getContents();

        $adIds = json_decode( $contentJSON );

        return $adIds;
    }



    public function API_search( $limit, $filters )
    {
        $url = 'https://api.leboncoin.fr/finder/search';

        $response = $this->client->request('POST', $url, [
            'headers' => [
                'api_key' => 'ba0c2dad52b3ec', //TODO
                'content-type' => 'text/plain;charset=UTF-8',
            ],
            RequestOptions::JSON => [
                'limit' => $limit,
                'filters' => $filters,
                'user_id' => $this->personaldata->userId,
                'store_id' => strval($this->personaldata->storeId),
            ]
        ]);

        $contentJSON = $response->getBody()->getContents();

        $ads = json_decode( $contentJSON );

        return $ads;
    }

}
