<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use BluePrint3D\EtsyIntegration\Model\ResourceModel\Taxonomy\CollectionFactory;

class EtsyTaxonomy extends AbstractSource
{
    protected $collectionFactory;

    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    public function getAllOptions()
    {
        if ($this->_options === null) {
            $this->_options = [['label' => __('-- Please Select an Etsy Category --'), 'value' => '']];

            $collection = $this->collectionFactory->create();
            // Sort them alphabetically by the full breadcrumb path
            $collection->setOrder('path', 'ASC');

            foreach ($collection as $item) {
                $this->_options[] = [
                    'label' => $item->getPath(),
                    'value' => $item->getTaxonomyId()
                ];
            }
        }
        return $this->_options;
    }
}