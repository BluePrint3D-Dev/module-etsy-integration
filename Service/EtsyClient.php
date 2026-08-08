<?php
/**
 * Copyright (c) 2026 BluePrint3D Ltd. All rights reserved.
 *
 * Commercial Software License (EULA)
 * This software is licensed, not sold. Unauthorized reproduction, distribution,
 * reverse engineering, or sublicensing of this source code, modified or
 * unmodified, without an active license agreement from BluePrint3D Ltd
 * is strictly prohibited.
 *
 * @author    BluePrint3D Ltd <support@blueprint3d.dev>
 * @copyright 2026 BluePrint3D Ltd (Company No. 13473806)
 * @license   Commercial Proprietary EULA (See LICENSE.txt)
 */
namespace BluePrint3D\EtsyIntegration\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Class EtsyClient
 * Handles all HTTP communication with the Etsy v3 OpenAPI
 */
class EtsyClient
{
    public const API_BASE_URL = 'https://openapi.etsy.com/v3/application/';
    public const OAUTH_TOKEN_URL = 'https://api.etsy.com/v3/public/oauth/token';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var WriterInterface
     */
    protected $configWriter;

    /**
     * @var TypeListInterface
     */
    protected $cacheTypeList;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * EtsyClient constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     * @param EncryptorInterface $encryptor
     * @param Json $json
     * @param LoggerInterface $logger
     * @param CurlFactory $curlFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        Json $json,
        LoggerInterface $logger,
        CurlFactory $curlFactory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->encryptor = $encryptor;
        $this->json = $json;
        $this->logger = $logger;
        $this->curlFactory = $curlFactory;
    }

    /**
     * Send a standard REST request to the Etsy API.
     *
     * @param string $endpoint
     * @param string $method
     * @param array $params
     * @param bool $isRetry
     * @param string|null $overrideToken
     * @return array
     * @throws LocalizedException
     */
    public function request($endpoint, $method = 'GET', $params = [], $isRetry = false, $overrideToken = null)
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $encryptedSecret = $this->scopeConfig->getValue('etsy_integration/api/shared_secret');
        $accessToken = $overrideToken ?: $this->scopeConfig->getValue('etsy_integration/api/access_token');

        if (!$appKey || !$encryptedSecret || !$accessToken) {
            throw new LocalizedException(__('Etsy API credentials missing. Please authenticate in the Admin.'));
        }

        $sharedSecret = $this->encryptor->decrypt($encryptedSecret);
        $url = self::API_BASE_URL . ltrim($endpoint, '/');

        /** @var \Magento\Framework\HTTP\Client\Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setHeaders([
            'x-api-key' => $appKey . ':' . $sharedSecret,
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json'
        ]);

        if ($method === 'GET') {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            $curl->get($url);
        } elseif ($method === 'POST') {
            $curl->post($url, $this->json->serialize($params));
        } elseif ($method === 'PATCH' || $method === 'PUT') {
            $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
            $curl->post($url, $this->json->serialize($params));
        } elseif ($method === 'DELETE') {
            $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
            $curl->get($url);
        }

        $responseBody = $curl->getBody();
        $statusCode = $curl->getStatus();

        if ($statusCode === 401 && !$isRetry) {
            $this->logger->info('Etsy Access Token expired. Attempting automatic refresh...');
            $newAccessToken = $this->refreshAccessToken();
            return $this->request($endpoint, $method, $params, true, $newAccessToken);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error(
                'Etsy API Error',
                ['status' => $statusCode, 'endpoint' => $url, 'response' => $responseBody]
            );
            throw new LocalizedException(__('Etsy API Error (%1): %2', $statusCode, $responseBody));
        }

        return empty($responseBody) ? [] : $this->json->unserialize($responseBody);
    }

    /**
     * Automatically refresh an expired Etsy OAuth access token.
     *
     * @return string
     * @throws LocalizedException
     */
    private function refreshAccessToken()
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $refreshToken = $this->scopeConfig->getValue('etsy_integration/api/refresh_token');

        if (!$appKey || !$refreshToken) {
            throw new LocalizedException(__('Cannot refresh Etsy token: Missing credentials.'));
        }

        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $appKey,
            'refresh_token' => $refreshToken
        ]);

        /** @var \Magento\Framework\HTTP\Client\Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);

        $curl->post(self::OAUTH_TOKEN_URL, $postData);

        $responseBody = $curl->getBody();
        $response = $this->json->unserialize($responseBody);

        if (isset($response['error'])) {
            throw new LocalizedException(
                __('Token Refresh Error: %1', $response['error_description'] ?? $response['error'])
            );
        }

        if (isset($response['access_token'])) {
            $this->configWriter->save('etsy_integration/api/access_token', $response['access_token']);
            $this->configWriter->save('etsy_integration/api/refresh_token', $response['refresh_token']);
            $this->cacheTypeList->cleanType('config');
            return $response['access_token'];
        }

        throw new LocalizedException(__('Failed to parse new access token from Etsy.'));
    }

    /**
     * Send an image file to the Etsy API via multipart/form-data.
     *
     * @param string $endpoint
     * @param string $filePath
     * @param int|null $rank
     * @return array
     * @throws LocalizedException
     */
    public function uploadImage($endpoint, $filePath, $rank = null)
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $encryptedSecret = $this->scopeConfig->getValue('etsy_integration/api/shared_secret');
        $accessToken = $this->scopeConfig->getValue('etsy_integration/api/access_token');

        if (!$appKey || !$encryptedSecret || !$accessToken) {
            throw new LocalizedException(__('Etsy API credentials missing.'));
        }

        $sharedSecret = $this->encryptor->decrypt($encryptedSecret);
        $url = self::API_BASE_URL . ltrim($endpoint, '/');

        /** @var \Magento\Framework\HTTP\Client\Curl $curl */
        $curl = $this->curlFactory->create();

        // Exclude Content-Type so cURL generates the multipart boundary automatically
        $curl->setHeaders([
            'x-api-key' => $appKey . ':' . $sharedSecret,
            'Authorization' => 'Bearer ' . $accessToken
        ]);

        $postFields = [
            'image' => new \CURLFile($filePath)
        ];

        if ($rank !== null) {
            $postFields['rank'] = (int)$rank;
        }

        $curl->post($url, $postFields);

        $responseBody = $curl->getBody();
        $statusCode = $curl->getStatus();

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error(
                'Etsy Image Upload Error',
                ['status' => $statusCode, 'response' => $responseBody]
            );
            throw new LocalizedException(__('Etsy Image Upload Error: %1', $responseBody));
        }

        return empty($responseBody) ? [] : $this->json->unserialize($responseBody);
    }

    /**
     * Submit listing personalization rules.
     *
     * @param int|string $shopId
     * @param int|string $listingId
     * @param array $questions
     * @return array
     * @throws LocalizedException
     */
    public function updatePersonalization($shopId, $listingId, array $questions)
    {
        $endpoint = "shops/{$shopId}/listings/{$listingId}/personalization"
            . "?supports_multiple_personalization_questions=true";

        $payload = [
            'personalization_questions' => $questions
        ];

        return $this->request($endpoint, 'POST', $payload);
    }
}
