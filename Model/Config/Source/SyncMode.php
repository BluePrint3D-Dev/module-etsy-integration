<?php
namespace BluePrint3D\EtsyIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SyncMode implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'realtime', 'label' => __('Real-Time (Immediate on Save)')],
            ['value' => 'cron', 'label' => __('Background Queue (Cron - Recommended)')]
        ];
    }
}