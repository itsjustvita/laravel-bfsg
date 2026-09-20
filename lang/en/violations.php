<?php

// Translation keys for violations: [analyzer => [check => ['message' => ..., 'suggestion' => ...]]]
// Placeholders use Laravel's :name syntax and must be identical in every locale.
return [
    'images' => [
        'missing_alt' => [
            'message' => 'Image without alt attribute (:src)',
            'suggestion' => 'Add an alt attribute that describes the image, or alt="" if it is purely decorative',
        ],
        'possibly_decorative' => [
            'message' => 'Image with empty alt text may not be decorative (:src)',
            'suggestion' => 'Verify the image is decorative; otherwise describe it in the alt attribute',
        ],
    ],
];
