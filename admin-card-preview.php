<?php
/** Internal, admin-only thumbnail document. Same card bootstrap/renderer as the public site. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__.'/admin-guard.php';
melkinoRequireAdminJson();
header('Cache-Control: private, no-store');
require_once __DIR__.'/ad-cards-bootstrap.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet"><link rel="stylesheet" href="style.css"><link rel="stylesheet" href="design-pro.css"><link rel="stylesheet" href="ad-cards-home.css">
<style>*,*:before,*:after{box-sizing:border-box}html,body{margin:0;padding:0;background:transparent}body{padding:8px}#mkHomeAdsList{display:grid!important;width:100%;margin:0}.property-card{width:100%;margin:0}a{color:inherit;text-decoration:none}</style>
<?= melkinoAdCardsHead('home') ?>
</head><body><div id="mkHomeAdsList" class="properties-list" aria-hidden="true"></div>
<script>
window.MELKINO_HOME_ADS=[{id:'studio-sample',title:'آپارتمان ۹۰ متری نمونه',property_type:'آپارتمان',transaction_type:'فروش',area:90,rooms:2,floor:3,year:1400,location:'شاهرود · مرکز شهر',price_sell:4500000000,images:['assets/defaults/apartment-1.jpg'],amenities:['پارکینگ','آسانسور','انباری'],status:'published'}];
document.body.inert=true;
function reportHeight(){requestAnimationFrame(function(){var c=document.querySelector('.property-card');if(c)parent.postMessage({type:'melkino-card-height',height:Math.ceil(c.getBoundingClientRect().height+16),width:Math.ceil(c.getBoundingClientRect().width+16)},window.origin);});}
window.addEventListener('message',function(e){if(e.origin!==window.origin || e.source!==parent || !e.data || e.data.type!=='melkino-studio-preview')return;reportHeight();});
if(window.ResizeObserver)new ResizeObserver(reportHeight).observe(document.getElementById('mkHomeAdsList'));
</script></body></html>
