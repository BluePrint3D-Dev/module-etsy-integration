<?php
namespace BluePrint3D\EtsyIntegration\Controller\Adminhtml\Auth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use BluePrint3D\EtsyIntegration\Service\EtsyClient;

class Callback extends Action
{
    const ADMIN_RESOURCE = 'Magento_Backend::all';
    const ETSY_TOKEN_URL = 'https://api.etsy.com/v3/public/oauth/token';

    protected $scopeConfig;
    protected $configWriter;
    protected $cacheTypeList;
    protected $curl;
    protected $logger;
    protected $etsyClient;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        Curl $curl,
        LoggerInterface $logger,
        EtsyClient $etsyClient
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->etsyClient = $etsyClient;
    }

    public function _processUrlKeys()
    {
        return true;
    }

    public function execute()
    {
        $request = $this->getRequest();
        $session = $this->_getSession();

        $code = $request->getParam('code');
        $state = $request->getParam('state');
        $error = $request->getParam('error');

        $savedState = $session->getEtsyAuthState();
        $verifier = $session->getEtsyAuthVerifier();

        if ($error) {
            $this->messageManager->addErrorMessage(__('Etsy Authorization Failed: %1', $error));
            return $this->redirectBack();
        }

        if (!$state || $state !== $savedState) {
            $this->messageManager->addErrorMessage(__('Security Error: Etsy State mismatch. Please try connecting again.'));
            return $this->redirectBack();
        }

        if (!$code || !$verifier) {
            $this->messageManager->addErrorMessage(__('Missing authorization code or verifier.'));
            return $this->redirectBack();
        }

        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');
        $callbackUrl = $this->getUrl('blueprint3d_etsy/auth/callback', ['_nosecret' => true]);

        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $appKey,
            'redirect_uri' => $callbackUrl,
            'code' => $code,
            'code_verifier' => $verifier
        ];

        try {
            $this->curl->post(self::ETSY_TOKEN_URL, $postData);
            $responseBody = $this->curl->getBody();
            $response = json_decode($responseBody, true);

            if (isset($response['error'])) {
                $errorMsg = isset($response['error_description']) ? $response['error_description'] : $response['error'];
                $this->messageManager->addErrorMessage(__('Etsy Token Error: %1', $errorMsg));
                $this->logger->error('Etsy Token Exchange Error', ['response' => $responseBody]);
            } elseif (isset($response['access_token'])) {

                // 1. Save tokens to database
                $this->configWriter->save('etsy_integration/api/access_token', $response['access_token']);
                $this->configWriter->save('etsy_integration/api/refresh_token', $response['refresh_token']);

                // 2. Clear config cache so our EtsyClient can immediately read the new tokens
                $this->cacheTypeList->cleanType('config');

                // 3. Fetch and save the Shop ID automatically!
                $this->fetchAndSaveShopId();

                // 4. Clean up session
                $session->unsEtsyAuthVerifier();
                $session->unsEtsyAuthState();

                $this->messageManager->addSuccessMessage(__('Successfully authenticated with Etsy! Your shop is now connected.'));
            } else {
                $this->messageManager->addErrorMessage(__('Unexpected response from Etsy. Check logs for details.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('An error occurred while connecting to Etsy: %1', $e->getMessage()));
        }

        return $this->redirectBack();
    }

    /**
     * Automatically fetches the Shop ID using the new token and saves it.
     */
    private function fetchAndSaveShopId()
    {
        try {
            $userData = $this->etsyClient->request('users/me');
            if (isset($userData['user_id'])) {
                $userId = $userData['user_id'];
                $shopData = $this->etsyClient->request("users/{$userId}/shops");

                $shopId = null;
                if (!empty($shopData['shop_id'])) {
                    $shopId = $shopData['shop_id'];
                } elseif (!empty($shopData['results'][0]['shop_id'])) {
                    $shopId = $shopData['results'][0]['shop_id'];
                }

                if ($shopId) {
                    $this->configWriter->save('etsy_integration/api/shop_id', $shopId);
                    $this->cacheTypeList->cleanType('config');
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to automatically fetch Shop ID during OAuth Callback: ' . $e->getMessage());
            // We don't throw an error to the user here, we just log it so it doesn't ruin their success message.
        }
    }

    private function redirectBack()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'etsy_integration']);
    }
}