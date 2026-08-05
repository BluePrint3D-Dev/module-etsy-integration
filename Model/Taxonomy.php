<?php
namespace BluePrint3D\EtsyIntegration\Model;

use Magento\Framework\Model\AbstractModel;

class Taxonomy extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\BluePrint3D\EtsyIntegration\Model\ResourceModel\Taxonomy::class);
    }
}