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
namespace BluePrint3D\EtsyIntegration\Model\Sync;

use Magento\Catalog\Model\Product;

/**
 * Class GalleryImageProcessor
 * Extension point for adjusting a gallery image on its way to Etsy. The free tier passes
 * every image through untouched; BluePrint3D_EtsyIntegrationPro plugs in here to auto-crop
 * the first image. The Magento gallery file is never modified - a processor returns the
 * path of a derived temp copy, which ProductSync deletes after upload.
 */
class GalleryImageProcessor
{
    /**
     * Return the file that should be uploaded to Etsy for this gallery image.
     *
     * @param Product $product
     * @param string $absolutePath Original gallery image on disk.
     * @param int $rank Etsy image rank (1 = the listing's first/thumbnail image).
     * @return string Path to upload; equal to $absolutePath when nothing was changed.
     */
    public function prepare(Product $product, string $absolutePath, int $rank): string
    {
        return $absolutePath;
    }

    /**
     * Fingerprint of any processing settings that affect what is uploaded, folded into the
     * gallery hash so a settings change triggers one re-upload. Empty when nothing is applied.
     *
     * @param Product $product
     * @return string
     */
    public function getSignature(Product $product): string
    {
        return '';
    }
}
