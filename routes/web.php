<?php
/**
 * Web route definitions (future front-controller expansion).
 * Portal pages currently use /views/*.php → public/view.php.
 */
return [
    'home' => [
        'handler' => PUBLIC_PATH . '/index.php',
    ],
];
