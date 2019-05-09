<?php
$list = [
    'AT', // Austria
    'BE', // Belgium
    'HR', // Croatia
    'BG', // Bulgaria
    'CY', // Cyprus
    'CZ', // Czech Republic
    'DK', // Denmark
    'EE', // Estonia
    'FI', // Finland
    'FR', // France
    'DE', // Germany
    'GR', // Greece
    'HU', // Hungary
    'IE', // Ireland
    'IT', // Italy
    'LV', // Latvia
    'LT', // Lithuania
    'LU', // Luxembourg
    'MT', // Malta
    'NL', // Netherlands
    'PL', // Poland
    'PT', // Portugal
    'RO', // Romania
    'SK', // Slovakia
    'SI', // Slovenia
    'ES', // Spain
    'SE', // Sweden
];

if(time() < strtotime('2019-03-19')) { // @see https://en.wikipedia.org/wiki/Brexit
    $list[] = 'GB'; // // United Kingdom
}

return $list;