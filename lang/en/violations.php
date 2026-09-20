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
    'headings' => [
        'skipped_level' => [
            'message' => 'Heading level skipped: :to follows :from (":content")',
            'suggestion' => 'Use heading levels in order without skipping (h1 → h2 → h3)',
        ],
        'missing_h1' => [
            'message' => 'No h1 heading found on the page',
            'suggestion' => 'Add exactly one h1 that names the main content of the page',
        ],
        'empty_heading' => [
            'message' => 'Empty :level heading',
            'suggestion' => 'Give the heading text, or remove it',
        ],
        'short_heading' => [
            'message' => 'Very short heading text ":content"',
            'suggestion' => 'Use a heading that describes the section it introduces',
        ],
        'multiple_h1' => [
            'message' => 'Additional h1 heading #:index (":content")',
            'suggestion' => 'Use one h1 per page and h2–h6 for sections',
        ],
    ],
    'contrast' => [
        'insufficient' => [
            'message' => 'Insufficient contrast :ratio:1 (required :required:1) for ":content" — :foreground on :background',
            'suggestion' => 'Increase the contrast between text and background colour to at least :required:1',
        ],
        'light_gray_inline' => [
            'message' => 'Inline style uses a light gray text colour',
            'suggestion' => 'Ensure a contrast ratio of at least 4.5:1 for body text',
        ],
    ],
];
