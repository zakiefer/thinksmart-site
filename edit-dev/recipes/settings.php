<?php
// Settings: each value is stored once and shown wherever it appears (as locked chips inside text).
declare(strict_types=1);

$set = function (string $key, $value, string $label, string $type = 'text', array $extra = []) use ($cv) {
    $cv->put($key, $value, 'settings');
    $cv->label($key, $label, $type, $extra);
};
$set('site.phone', '812-774-5049', 'Main phone number', 'phone');
$set('site.phone2', '812-850-1050', 'Second phone number (free claim review line)', 'phone');
$set('site.email', 'thinksmartpublicadjusting@gmail.com', 'Email shown on the site', 'email');
$set('site.address', ['street' => '376 S Ornament W', 'city' => 'Santa Claus', 'region' => 'IN', 'zip' => '47579'], 'Office address', 'address');
$set('site.adjuster', ['name' => 'Jonathan Nelson', 'title' => 'President & CEO'], 'Adjuster name and title', 'person');
$set('site.callback', '24h', 'Callback promise', 'choice');
$set('site.price', 299, 'Stand-alone estimate price', 'money');
$set('site.guarantee', [
    'short' => "if your claim isn't paid, our service is free.",
    'long' => "If your claim isn't paid, our service fee is \$0. Incurred third-party costs (satellite imagery reports, Xactimate estimates) are not included.",
], 'Guarantee wording', 'guarantee');
$set('site.social', [['platform' => 'Facebook', 'url' => 'https://www.facebook.com/SmartAdjusting/']], 'Social links', 'list');
$set('site.sharePicture', 'media/og-cover.jpg', 'Share picture (shown when the site is shared by text or on Facebook)', 'photo');
$set('site.businessName', 'Think Smart Public Adjusting LLC', 'Business name for Google', 'text');
$set('site.seal', true, '"Paid or Free" seal', 'switch');
$set('site.brightness', 'standard', 'Easier to read: body text brightness', 'choice');
