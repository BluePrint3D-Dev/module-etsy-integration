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