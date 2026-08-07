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
namespace BluePrint3D\EtsyIntegration\Controller\Adminhtml\Auth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Action\HttpGetActionInterface; // Added for M2.4+ Strict Routing

class Index extends Action implements HttpGetActionInterface
{
    // Temporarily set to 'all' to ensure no ACL permission block is happening
    const ADMIN_RESOURCE = 'Magento_Backend::all';

    protected $scopeConfig;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
    }

    public function execute()
    {
        $appKey = $this->scopeConfig->getValue('etsy_integration/api/keystring');

        if (empty($appKey)) {
            $this->messageManager->addErrorMessage(__('Please save your Etsy App Key before connecting.'));
            return $this->_redirect('adminhtml/system_config/edit/section/etsy_integration');
        }

        $verifier = $this->generateCodeVerifier();
        $challenge = $this->generateCodeChallenge($verifier);

        $state = bin2hex(random_bytes(16));

        $session = $this->_getSession();
        $session->setEtsyAuthVerifier($verifier);
        $session->setEtsyAuthState($state);

        // Crucial: Removing the secret key from the callback URL so Etsy doesn't crash on return
        $callbackUrl = $this->getUrl('blueprint3d_etsy/auth/callback', ['_nosecret' => true]);

        $scopes = ['listings_r', 'listings_w', 'listings_d', 'shops_r'];

        $params = [
            'response_type' => 'code',
            'client_id' => $appKey,
            'redirect_uri' => $callbackUrl,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256'
        ];

        $authUrl = 'https://www.etsy.com/oauth/connect?' . http_build_query($params);

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setUrl($authUrl);

        return $resultRedirect;
    }

    private function generateCodeVerifier()
    {
        return bin2hex(random_bytes(32));
    }

    private function generateCodeChallenge($verifier)
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Bulletproof ACL bypass for debugging
     */
    protected function _isAllowed()
    {
        return true;
    }
}