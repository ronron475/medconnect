<?php
/**
 * API route map (documentation / future router).
 * Live endpoints remain at app/api/* for backward compatibility.
 */
return [
    'prefix' => '/app/api',
    'path'   => APP_API_PATH ?? (dirname(__DIR__) . '/app/api'),
];
