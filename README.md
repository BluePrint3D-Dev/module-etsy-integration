# BluePrint3D – Etsy Integration for Magento 2 🛍️

![Magento 2.4.x](https://img.shields.io/badge/Magento-2.4.x-orange.svg)
![PHP 8.1–8.5](https://img.shields.io/badge/PHP-8.1--8.5-blue.svg)
![Etsy API v3](https://img.shields.io/badge/Etsy%20API-v3-brightgreen.svg)
![Licence](https://img.shields.io/badge/Licence-Commercial_EULA-lightgrey.svg)

A direct Etsy API v3 connector for Magento 2. Syncs products, gallery images, tags and personalisation questions to Etsy from a background queue, without slowing down product saves or paying for monthly SaaS middleware.

---

## 🛑 The Problem
Most Magento Etsy connectors make slow, synchronous API calls during product save, so admin edit pages hang or time out. Many also treat custom options as broken variants, forcing you to build configurable products just to collect buyer text or engraving choices.

## 🛠️ The Solution
A lightweight database queue and cron worker do the Etsy work in the background. Built for **Etsy API v3**, it turns native Magento custom options and [BluePrint3D Shared Product Options](https://github.com/BluePrint3D-Dev/module-shared-product-options) into Etsy personalisation questions and dropdowns, without risking catalogue corruption or stock miscounts.

## ✨ Features
- **Background queue:** API work goes to a queue table (`blueprint3d_etsy_queue`) and a cron worker, so admin saves stay instant.
- **Etsy personalisation sync:** text fields, text areas and non-stock dropdowns go into Etsy's multi-question personalisation box.
- **Shared Product Options ready:** works with `BluePrint3D_SharedProductOptions` with no extra setup.
- **Smart tags:** built from meta keywords, cleaned of forbidden characters, capped at 20 characters, falling back to the product title.
- **Gallery sync:** up to 20 images, in Magento's gallery order.
- **Safeguards:** *Strict* mode stops the sync and alerts you; *Lenient* mode drops unsupported options and logs a warning.

## 🚀 Free vs Pro
This free edition syncs up to **20 products**. [Etsy Integration Pro](https://www.blueprint3d.dev/modules/etsy-integration-pro) adds unlimited products, bulk actions, configurable variations, price markup rules and image auto-crop.

---

## 📦 Installation

```bash
composer require blueprint3d/module-etsy-integration
bin/magento module:enable BluePrint3D_EtsyIntegration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## ⚙️ Configuration
1. Go to **Stores > Configuration > BluePrint3D > Etsy Integration**.
2. Enter your **Etsy OAuth API credentials** and **Shop ID**.
3. Choose how unsupported options are handled (*Strict* or *Lenient*).
4. Map Magento categories to **Etsy Taxonomy IDs** under **Catalog > Categories**.
5. Save an assigned product to queue it for sync.

---

## 📖 Documentation
Full setup guide: [`Etsy Integration Setup Guide.pdf`](Etsy%20Integration%20Setup%20Guide.pdf).

## 📜 Licence & Support
**Copyright © 2026 BluePrint3D Ltd. All rights reserved.**

The free edition is licensed under the BluePrint3D Commercial EULA: free to use, but resale, redistribution, sublicensing or public hosting of this source code is prohibited. Full terms in [`LICENSE.txt`](LICENSE.txt).

- **Website:** [blueprint3d.dev](https://www.blueprint3d.dev/modules/etsy-integration)
- **Support:** [support@blueprint3d.dev](mailto:support@blueprint3d.dev)
- **Company:** BluePrint3D Ltd, Company No. 13473806
