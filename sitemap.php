<?php
// sitemap.xml (root rewrite: /sitemap.xml -> program/sitemap.php). Lists nothing while the site lock is ON.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/seo-lib.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo melkinoSeoSitemapXml(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
