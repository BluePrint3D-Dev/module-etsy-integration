<?php
namespace BluePrint3D\EtsyIntegration\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class EtsyClient
{
    const API_BASE_URL = 'https://openapi.etsy.com/v3/application/';
    const OAUTH_TOKEN_URL = 'https://api.etsy.com/v3/public/oauth/token';

    protected $scopeConfig;
    protected $configWriter;
    protected $cacheTypeList;
    protected $encryptor;
    protected $curl;
    protected $json;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        Curl $curl,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->encryptor = $encryptor;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
    }

    public function request($endpoint, $method = 'GET', $params = [], $isRetry = false, $overrideToken = null)
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $encryptedSecret = $this->scopeConfig->getValue('etsy_integration/api/shared_secret');

        // Use the override token if we just auto-refreshed it, otherwise pull from database
        $accessToken = $overrideToken ?: $this->scopeConfig->getValue('etsy_integration/api/access_token');

        if (!$appKey || !$encryptedSecret || !$accessToken) {
            throw new \Exception(__('Etsy API credentials missing. Please authenticate in the Admin.'));
        }

        $sharedSecret = $this->encryptor->decrypt($encryptedSecret);

        $this->curl->setHeaders([
            'x-api-key' => $appKey . ':' . $sharedSecret,
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json'
        ]);

        $url = self::API_BASE_URL . ltrim($endpoint, '/');

        try {
            if ($method === 'GET') {
                if (!empty($params)) $url .= '?' . http_build_query($params);
                $this->curl->get($url);
            } elseif ($method === 'POST') {
                $this->curl->post($url, $this->json->serialize($params));
            } elseif ($method === 'PUT') {
                $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
                $this->curl->post($url, $this->json->serialize($params));
            } elseif ($method === 'DELETE') {
                $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $this->curl->get($url);
            }

            $statusCode = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();

            // 1. INTERCEPT EXPIRED TOKEN (HTTP 401)
            if ($statusCode === 401 && !$isRetry) {
                $this->logger->info('Etsy Access Token expired. Attempting automatic refresh...');

                $newAccessToken = $this->refreshAccessToken();

                // Retry the exact same request seamlessly with the new token
                return $this->request($endpoint, $method, $params, true, $newAccessToken);
            }

            // 2. Handle other API Errors
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Etsy API Error', [
                    'status' => $statusCode,
                    'endpoint' => $url,
                    'response' => $responseBody
                ]);
                throw new \Exception(__('Etsy API Error (%1): %2', $statusCode, $responseBody));
            }

            return $this->json->unserialize($responseBody);

        } catch (\Exception $e) {
            $this->logger->error('Etsy API Request Failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Trades the refresh_token for a brand new access_token and saves it
     */
    private function refreshAccessToken()
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $refreshToken = $this->scopeConfig->getValue('etsy_integration/api/refresh_token');

        if (!$appKey || !$refreshToken) {
            throw new \Exception(__('Cannot refresh Etsy token: Missing credentials.'));
        }

        $postData = [
            'grant_type' => 'refresh_token',
            'client_id' => $appKey,
            'refresh_token' => $refreshToken
        ];

        // Ensure we send form-urlencoded for this specific OAuth endpoint
        $this->curl->setHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->curl->post(self::OAUTH_TOKEN_URL, $postData);

        $response = $this->json->unserialize($this->curl->getBody());

        if (isset($response['error'])) {
            $errorMsg = $response['error_description'] ?? $response['error'];
            throw new \Exception(__('Token Refresh Error: %1', $errorMsg));
        }

        if (isset($response['access_token'])) {
            // Save the new tokens silently in the background
            $this->configWriter->save('etsy_integration/api/access_token', $response['access_token']);
            $this->configWriter->save('etsy_integration/api/refresh_token', $response['refresh_token']);

            // Clear config cache so the rest of Magento recognizes the new tokens
            $this->cacheTypeList->cleanType('config');

            return $response['access_token'];
        }

        throw new \Exception(__('Failed to parse new access token from Etsy.'));
    }
}