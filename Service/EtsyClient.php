<?php
namespace BluePrint3D\EtsyIntegration\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Encryption\EncryptorInterface;
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
    protected $json;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->encryptor = $encryptor;
        $this->json = $json;
        $this->logger = $logger;
    }

    public function request($endpoint, $method = 'GET', $params = [], $isRetry = false, $overrideToken = null)
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $encryptedSecret = $this->scopeConfig->getValue('etsy_integration/api/shared_secret');
        $accessToken = $overrideToken ?: $this->scopeConfig->getValue('etsy_integration/api/access_token');

        if (!$appKey || !$encryptedSecret || !$accessToken) {
            throw new \Exception(__('Etsy API credentials missing. Please authenticate in the Admin.'));
        }

        $sharedSecret = $this->encryptor->decrypt($encryptedSecret);
        $url = self::API_BASE_URL . ltrim($endpoint, '/');

        $ch = curl_init();
        $headers = [
            'x-api-key: ' . $appKey . ':' . $sharedSecret,
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];

        if ($method === 'GET') {
            if (!empty($params)) $url .= '?' . http_build_query($params);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->json->serialize($params));
        } elseif ($method === 'PATCH' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->json->serialize($params));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode === 401 && !$isRetry) {
            $this->logger->info('Etsy Access Token expired. Attempting automatic refresh...');
            $newAccessToken = $this->refreshAccessToken();
            return $this->request($endpoint, $method, $params, true, $newAccessToken);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('Etsy API Error', ['status' => $statusCode, 'endpoint' => $url, 'response' => $responseBody]);
            throw new \Exception(__('Etsy API Error (%1): %2', $statusCode, $responseBody));
        }

        return empty($responseBody) ? [] : $this->json->unserialize($responseBody);
    }

    private function refreshAccessToken()
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $refreshToken = $this->scopeConfig->getValue('etsy_integration/api/refresh_token');

        if (!$appKey || !$refreshToken) {
            throw new \Exception(__('Cannot refresh Etsy token: Missing credentials.'));
        }

        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $appKey,
            'refresh_token' => $refreshToken
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::OAUTH_TOKEN_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $responseBody = curl_exec($ch);
        curl_close($ch);
        $response = $this->json->unserialize($responseBody);

        if (isset($response['error'])) throw new \Exception(__('Token Refresh Error: %1', $response['error_description'] ?? $response['error']));

        if (isset($response['access_token'])) {
            $this->configWriter->save('etsy_integration/api/access_token', $response['access_token']);
            $this->configWriter->save('etsy_integration/api/refresh_token', $response['refresh_token']);
            $this->cacheTypeList->cleanType('config');
            return $response['access_token'];
        }

        throw new \Exception(__('Failed to parse new access token from Etsy.'));
    }

    public function uploadImage($endpoint, $filePath, $rank = null)
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $encryptedSecret = $this->scopeConfig->getValue('etsy_integration/api/shared_secret');
        $accessToken = $this->scopeConfig->getValue('etsy_integration/api/access_token');

        if (!$appKey || !$encryptedSecret || !$accessToken) throw new \Exception(__('Etsy API credentials missing.'));

        $sharedSecret = $this->encryptor->decrypt($encryptedSecret);
        $url = self::API_BASE_URL . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $appKey . ':' . $sharedSecret,
            'Authorization: Bearer ' . $accessToken
        ]);

        $postFields = [
            'image' => new \CURLFile($filePath)
        ];

        // If a rank is provided, tell Etsy exactly what position this image belongs in
        if ($rank !== null) {
            $postFields['rank'] = (int)$rank;
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('Etsy Image Upload Error', ['status' => $statusCode, 'response' => $responseBody]);
            throw new \Exception(__('Etsy Image Upload Error: %1', $responseBody));
        }

        return empty($responseBody) ? [] : $this->json->unserialize($responseBody);
    }
}