<?php
namespace BluePrint3D\EtsyIntegration\Model\ResourceModel\Taxonomy;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            \BluePrint3D\EtsyIntegration\Model\Taxonomy::class,
            \BluePrint3D\EtsyIntegration\Model\ResourceModel\Taxonomy::class
        );
    }
}