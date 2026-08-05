<?php
namespace BluePrint3D\EtsyIntegration\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Taxonomy extends AbstractDb
{
    protected function _construct()
    {
        // Tells Magento the table name and the primary key column
        $this->_init('blueprint3d_etsy_taxonomy', 'entity_id');
    }
}