<?php
namespace BluePrint3D\EtsyIntegration\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class EtsyClient
{
    const API_BASE_URL = 'https://openapi.etsy.com/v3/application/';

    protected $scopeConfig;
    protected $encryptor;
    protected $curl;
    protected $json;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        Curl $curl,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
    }

    public function request($endpoint, $method = 'GET', $params = [])
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $encryptedSecret = $this->scopeConfig->getValue('etsy_integration/api/shared_secret');
        $accessToken = $this->scopeConfig->getValue('etsy_integration/api/access_token');

        if (!$appKey || !$encryptedSecret || !$accessToken) {
            throw new \Exception(__('Etsy API credentials missing. Please authenticate in the Magento Admin.'));
        }

        // 1. Decrypt the shared secret safely
        $sharedSecret = $this->encryptor->decrypt($encryptedSecret);

        // 2. Etsy Quirk: The header must contain BOTH the keystring and the shared secret
        $apiKeyHeader = $appKey . ':' . $sharedSecret;

        $this->curl->setHeaders([
            'x-api-key' => $apiKeyHeader,
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json'
        ]);

        $url = self::API_BASE_URL . ltrim($endpoint, '/');

        try {
            if ($method === 'GET') {
                if (!empty($params)) {
                    $url .= '?' . http_build_query($params);
                }
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

            $responseBody = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

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
}