<?php

/**
 * Scopes always granted to the auto-generated ephemeral API key of each
 * compute resource type, in addition to the scopes configured on the resource
 * itself. Downstream platforms may extend these with platform-specific
 * scopes.
 */

return [
    'functions' => [
        'health.read',
    ],
    'sites' => [
        'health.read',
    ],
];
