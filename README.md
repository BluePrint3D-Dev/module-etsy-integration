<h1>BluePrint3D - Etsy Integration for Magento 2 🛍️</h1>

<p>
    <a href="#"><img src="https://img.shields.io/badge/Magento-2.4.x-orange.svg" alt="Magento Version" /></a>
    <a href="#"><img src="https://img.shields.io/badge/PHP-8.1%20|%208.2%20|%208.3-blue.svg" alt="PHP Version" /></a>
    <a href="#"><img src="https://img.shields.io/badge/Etsy%20API-v3%20Ready-brightgreen.svg" alt="Etsy API v3" /></a>
    <a href="#"><img src="https://img.shields.io/badge/License-Commercial_EULA-lightgrey.svg" alt="License" /></a>
</p>

<p>An enterprise-grade, direct-database Etsy v3 connector for Magento 2. Sync products, gallery images, smart tags, and dynamic personalizations to Etsy asynchronously in the background—without breaking cron schedules, causing Admin timeouts, or paying expensive monthly SaaS middleware fees.</p>

<hr />

<h2>🛑 The Problem</h2>
<p>Most Magento 2 Etsy connectors rely on slow, synchronous HTTP requests during product save, causing Admin edit pages to crash or lock up. Worse still, existing tools treat custom options as broken variants, forcing merchants to build complex configurable products just to collect simple buyer text inputs or engraving choices.</p>

<h2>🛠️ The Solution</h2>
<p>The <strong>BluePrint3D Etsy Integration</strong> handles your catalogue sync using a lightweight, direct-database background queue worker. Built natively for the latest <strong>Etsy API v3</strong>, it automatically converts both native Magento Custom Options and <a href="https://github.com/BluePrint3D-Dev/module-shared-product-options">BluePrint3D Shared Options</a> into native Etsy Personalizations and custom dropdowns without risking catalogue corruption or inventory miscounts.</p>

<h2>✨ Features</h2>
<ul>
    <li><strong>Asynchronous Background Queue:</strong> Offloads heavy API lifting to a dedicated DB queue table (<code>blueprint3d_etsy_queue</code>) and cron worker. Product saves in Admin remain instant.</li>
    <li><strong>Native Etsy Personalization Sync:</strong> Leverages Etsy's multi-question personalization API to automatically push text fields, text areas, and non-inventory dropdowns straight into Etsy's custom options box.</li>
    <li><strong>BluePrint3D Shared Options Ready:</strong> Zero-config compatibility with <code>BluePrint3D_SharedProductOptions</code>, bypassing Admin/CLI area restrictions to read shared groups seamlessly.</li>
    <li><strong>Smart Tag Generator:</strong> Extracts meta keywords, cleans forbidden characters, caps length at 20 characters, and automatically falls back to product titles if meta tags are missing.</li>
    <li><strong>Automated Gallery Sync:</strong> Sorts and uploads up to 10 high-resolution product gallery images according to native Magento position rankings.</li>
    <li><strong>Configurable Safeguards:</strong> Built-in <em>Strict Mode</em> (abort sync and alert admin) and <em>Lenient Mode</em> (strip unsupported options and log warnings) to protect listings from API rejection.</li>
</ul>

<hr />

<h2>📦 Installation</h2>

<p><strong>1. Register the Private Packagist Repository</strong></p>
<pre><code>composer config repositories.blueprint3d composer https://repo.blueprint3d.dev</code></pre>

<p><strong>2. Install via Composer</strong></p>
<pre><code>composer require blueprint3d/module-etsy-integration</code></pre>

<p><strong>3. Enable the module</strong></p>
<pre><code>php bin/magento module:enable BluePrint3D_EtsyIntegration</code></pre>

<p><strong>4. Run the database upgrade</strong></p>
<pre><code>php bin/magento setup:upgrade</code></pre>

<p><strong>5. Compile and flush cache</strong></p>
<pre><code>php bin/magento setup:di:compile
php bin/magento cache:flush</code></pre>

<hr />

<h2>⚙️ Quick Configuration</h2>
<ol>
    <li>Navigate to <strong>Stores &gt; Configuration &gt; BluePrint3D &gt; Etsy Settings</strong> in your Magento Admin.</li>
    <li>Enter your <strong>Etsy OAuth API Credentials</strong> and <strong>Shop ID</strong>.</li>
    <li>Configure your <strong>Unsupported Options Handling</strong> (<em>Strict Mode</em> or <em>Lenient Mode</em>).</li>
    <li>Map your Magento Categories to <strong>Etsy Taxonomy IDs</strong> under <strong>Catalog &gt; Categories</strong>.</li>
    <li>Save any assigned product to queue it for immediate background sync!</li>
</ol>

<hr />

<h2>📜 License &amp; Support</h2>
<p><strong>Copyright &copy; 2026 BluePrint3D Ltd. All rights reserved.</strong></p>

<p>This software is licensed under a Commercial Proprietary End User License Agreement (EULA). Resale, redistribution, sublicensing, or public hosting of this source code is strictly prohibited.</p>

<p>For licensing updates, technical documentation, or dedicated integration support:</p>

<p>
    <strong>Website:</strong> <a href="https://www.blueprint3d.dev">www.blueprint3d.dev</a><br />
    <strong>Company:</strong> BluePrint3D Ltd (Company Registration Number: 13473806)<br />
    <strong>Support Email:</strong> <a href="mailto:support@blueprint3d.dev">support@blueprint3d.dev</a>
</p>