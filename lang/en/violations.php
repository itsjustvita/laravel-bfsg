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
    'forms' => [
        'control_missing_label' => [
            'message' => 'Form control ":name" (:type) has no associated label',
            'suggestion' => 'Add a <label for="…">, wrap the control in a <label>, or use aria-label/aria-labelledby',
        ],
        'form_missing_name' => [
            'message' => 'Form without descriptive label or heading',
            'suggestion' => 'Add aria-label/aria-labelledby to the form or a heading that names it',
        ],
        'required_missing_aria_required' => [
            'message' => 'Required field ":name" without aria-required attribute',
            'suggestion' => 'Add aria-required="true" or make the required state visible in the label',
        ],
    ],
];
