<?php

use Goutte\Client;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

require 'vendor/autoload.php';

// use Goutte\Client;
// use Symfony\Component\HttpClient\HttpClient;

// $client = new Client(HttpClient::create());


// $httpHeaders = array('HTTP_USER_AGENT', 'HTTP_HOST', 'HTTP_CACHE_CONTROL', 'HTTP_CONNECTION', 'HTTP_DNT', 'HTTP_SEC_CH_UA', 'HTTP_SEC_CH_UA_MOBILE', 'HTTP_SEC_CH_UA_PLATFORM', 'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_SITE', 'HTTP_SEC_FETCH_USER', 'HTTP_UPGRADE_INSECURE_REQUESTS', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_REFERER', 'HTTP_ORIGIN', 'HTTPS');
// $headers = [];
// $_SERVER['HTTP_HOST'] = 'www.bukalapak.com';
// $_SERVER['HTTP_CLIENT_IP'] = setClientIp();
// $_SERVER['HTTP_REFERER'] = 'https://www.bukalapak.com/';
// $_SERVER['HTTP_ORIGIN'] = 'https://www.bukalapak.com';
// foreach ($httpHeaders as $httpHeader) {
//     $headers[$httpHeader] = $_SERVER[$httpHeader];
// }
// 

// $accessToken = getAccessToken($scripts);


// function getAccessToken($scripts)
// {
//     
// }


class BukaSend
{

    private $httpHeaders = array('HTTP_USER_AGENT', 'HTTP_HOST', 'HTTP_CACHE_CONTROL', 'HTTP_CONNECTION', 'HTTP_DNT', 'HTTP_SEC_CH_UA', 'HTTP_SEC_CH_UA_MOBILE', 'HTTP_SEC_CH_UA_PLATFORM', 'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_SITE', 'HTTP_SEC_FETCH_USER', 'HTTP_UPGRADE_INSECURE_REQUESTS', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_REFERER', 'HTTP_ORIGIN', 'HTTPS');

    private $headers = array();

    private $baseApi = 'https://api.bukalapak.com';

    private $client;

    private $cache;

    private $cookieJar;

    public function __construct()
    {
        $this->client = new Client(HttpClient::create());
        $this->cache = new FilesystemAdapter('', 3600, __DIR__ . '/cache');
        $this->cookieJar = new CookieJar;
    }

    public function setHeaders($serverHeaders = array())
    {
        $serverHeaders = $this->setClientIp($serverHeaders);
        $serverHeaders['HTTP_HOST'] = 'www.bukalapak.com';
        $serverHeaders['HTTP_REFERER'] = 'https://www.bukalapak.com/';
        $serverHeaders['HTTP_ORIGIN'] = 'https://www.bukalapak.com';
        foreach ($this->httpHeaders as $httpHeader) {
            $this->headers[$httpHeader] = $serverHeaders[$httpHeader];
        }
    }

    private function getHeaders()
    {
        return $this->headers;
    }

    public function getCouriers($from = array(), $to = array(),  $weight = 100)
    {
        $coordinates = $this->getCoordinates($from, $to);
        $query = array(
            'from_city' => $from['city'],
            'from_district' => $from['district'],
            'to_city' => $to['city'],
            'to_district' => $to['district'],
            'weight' => $weight,
            'ignore_geolocation' => true,
            'from_latitude' => $coordinates['from']['latitude'],
            'from_longitude' => $coordinates['from']['longitude'],
            'to_latitude' => $coordinates['to']['latitude'],
            'to_longitude' => $coordinates['to']['longitude'],
            'service_type' => 'dropoff',
            'show_all' => true
        );
        
        $cacheKey = 'couriers_from_'.$from['city'].'_'.$from['district'].'_to_'.$to['city'].'_'.$to['district'];
        $couriers = $this->getCache($cacheKey);
        if(!$couriers){
            $couriers = $this->request('/open-shipments/couriers', 'GET', $query);
            $expire = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $expire->modify('+4 hour');
            $this->setCache($cacheKey, $couriers, $expire->getTimestamp());
        }
        return $couriers;
    }

    public function getAddresess($keywords)
    {
        $uri = '/geocoders/addresses';
        $query = array(
            'countries' => 'Indonesia',
            'keywords' => $keywords,
        );

        $cacheKey = $uri . '_' . $keywords;
        $addresess = $this->getCache($cacheKey);
        if (!$addresess) {
            $addresess = $this->request($uri, 'GET', $query);
            $expire = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $expire->modify('+7 day');
            $this->setCache($cacheKey, $addresess, $expire->getTimestamp());
        }
        return $addresess;
    }

    private function getCoordinates($from = array(), $to = array())
    {
        $cacheKeys = array(
            'from' => 'districts.' . $from['province'] . '_' . $from['city'] . '_' . $from['district'],
            'to' => 'districts.' . $to['province'] . '_' . $to['city'] . '_' . $to['district']
        );
        $coordinates = [];
        $isHited = true;
        foreach ($cacheKeys as $key => $cacheKey) {
            $coordinate = $this->getCache($cacheKey);
            if ($coordinate) {
                $coordinates[$key] = $coordinate;
            } else {
                $isHited = false;
            }
        }
        if (!$isHited) {
            $uri = $this->baseApi . '/aggregate';
            $method = 'POST';
            $aggregate = array(
                'aggregate' => array(
                    'from' => array(
                        "method" => 'GET',
                        "path" => $this->buildQuery('/geocodes/coordinates', $from, true)
                    ),
                    'to' => array(
                        "method" => 'GET',
                        "path" => $this->buildQuery('/geocodes/coordinates', $to, true)
                    ),
                )
            );
            $resCoordinates = $this->request($uri, $method, [], $aggregate);
            foreach ($resCoordinates['data'] as $key => $value) {
                if ($resCoordinates['meta'][$key]['http_status'] == 200) {
                    $coordinates[$key] = $resCoordinates['data'][$key]['location'];
                    $this->setCache($cacheKeys[$key], $coordinates[$key]);
                }
            }
        }
        return $coordinates;
    }

    private function request($uri, $method, $query = array(), $data = array())
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            throw new Exception('failed to get access token');
        }
        $query['access_token'] = $accessToken;
        $uri = $this->buildQuery($uri, $query);
        $headers = $this->getHeaders();
        $headers['HTTP_HOST'] = 'api.bukalapak.com';
        $this->client = new Client(HttpClient::create(), null, $this->cookieJar);
        $client = $this->client;
        $client->getCookieJar();
        $client->request($method, $uri, [], [], $headers, json_encode($data));
        $res = $client->getInternalResponse();
        $statusCode = $res->getStatusCode();
        $content = $res->getContent();
        $contentType = $res->getHeader('content-type');
        if (($statusCode < 200 && $statusCode > 400) || !$content || strpos(strtolower($contentType), 'application/json') !== 0) {
            throw new Exception('failed request with status code: ' . $statusCode, $statusCode);
        }

        $content = json_decode($content, true);
        $jsonError = json_last_error_msg();
        if (!$content || ($jsonError && 'no error' !== strtolower($jsonError))) {
            throw new Exception('failed decode response: ' . $client);
        }
        return $content;
    }


    private function getAccessToken()
    {
        $headers = $this->getHeaders();
        $cacheKey = urlencode(strtolower($headers['HTTP_SEC_CH_UA_PLATFORM']) . '_' . urlencode($headers['HTTP_USER_AGENT']) . '_' . $headers['REMOTE_ADDR']);
        $tokenCached = $this->getCache($cacheKey);
        if ($tokenCached) {
            $this->cookieJar = $this->setCookies($tokenCached['cookie']);;
            return $tokenCached['access_token'];
        }
        $crawler = $this->client->request('GET', 'https://www.bukalapak.com/bukasend/delivery-cost-check', [], [], $headers);
        $cookiesJar = $this->client->getCookieJar()->all(); //->getHeader('cookie');
        $cookies = [];
        foreach ($cookiesJar as $cookie) {
            $cookies[] = $cookie->__toString();
        }
        $scripts = $crawler->filter('script')->extract(['_text']);
        $accessToken = array();
        foreach ($scripts as $key => $script) {
            if (preg_match("/localStorage.setItem\('bl_token'.*\);/", $script)) {
                $script = trim(preg_replace("/(localStorage.setItem\('bl_token', |\);)/", "", $script), "'");
                $json = json_decode($script, true);
                $error = json_last_error_msg();
                if (!$json || ($error && 'no error' !== strtolower($error))) {
                    return false;
                }
                $accessToken = $json;
            }
        }
        if (!$accessToken) {
            return false;
        }
        if (!$accessToken['access_token']) {
            return false;
        }

        $cached = array(
            'access_token' => $accessToken['access_token'],
            'cookie' => $cookies
        );
        $this->setCache($cacheKey, $cached, ($accessToken['expires_at'] / 1000) - 3600 * 2);
        return $accessToken['access_token'];
    }

    private function setClientIp($server = array())
    {
        // Get real visitor IP behind CloudFlare network
        if (isset($server["HTTP_CF_CONNECTING_IP"])) {
            $server['REMOTE_ADDR'] = $server["HTTP_CF_CONNECTING_IP"];
            $server['HTTP_CLIENT_IP'] = $server["HTTP_CF_CONNECTING_IP"];
        } else {
            $server['REMOTE_ADDR'] = $server['HTTP_X_FORWARDED_FOR'];
            $server['HTTP_CLIENT_IP'] = $server['HTTP_X_FORWARDED_FOR'];
        }

        return $server;
    }

    private function buildQuery($uri, $data = array(), $encode = false)
    {
        if ($encode) {
            foreach ($data as $key => $value) {
                $data[$key] = urlencode($value);
            }
        }
        return $uri . '?' . http_build_query($data);
    }

    private function setCookies($cookies)
    {
        foreach ($cookies as $cookie) {
            $cookie = Cookie::fromString($cookie);
            $this->cookieJar->set($cookie);
        }
        return $this->cookieJar;
    }

    private function getCache($cacheKey)
    {
        $tokenCached = $this->cache->getItem($cacheKey);
        if ($tokenCached->isHit()) {
            return $tokenCached->get();
        }
        return false;
    }

    private function setCache($cacheKey, $value, $expired = 0)
    {
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($value, $expired) {
            if ($expired) {
                $expire = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
                $expire->setTimestamp($expired);
                $item->expiresAt($expire);
            }
            return $value;
        });
    }
}
