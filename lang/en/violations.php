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
    'links' => [
        'non_descriptive' => [
            'message' => 'Non-descriptive link text ":text" (:href)',
            'suggestion' => 'Use link text that describes the destination, or add an aria-label',
        ],
        'missing_name' => [
            'message' => 'Link without accessible text (:href)',
            'suggestion' => 'Add link text, an aria-label, or alt text on the image inside the link',
        ],
        'missing_href' => [
            'message' => 'Anchor element without href attribute (":text")',
            'suggestion' => 'Add an href, or use a <button> for actions',
        ],
        'adjacent_duplicate' => [
            'message' => 'Adjacent duplicate links to :href',
            'suggestion' => 'Merge adjacent links to the same destination into one link',
        ],
        'new_window_unannounced' => [
            'message' => 'Link opens in a new window without warning (:href)',
            'suggestion' => 'Add "(opens in new window)" to the link text or aria-label',
        ],
        'missing_noopener' => [
            'message' => 'Link with target="_blank" lacks rel="noopener" (:href)',
            'suggestion' => 'Add rel="noopener noreferrer" to links that open a new window',
        ],
        'url_as_text' => [
            'message' => 'URL used as link text (:text)',
            'suggestion' => 'Use descriptive text instead of the URL',
        ],
        'download_unannounced' => [
            'message' => 'Download link without file type indication (:href)',
            'suggestion' => 'Add the file type and size to the link text, e.g. "Report (:type, 2 MB)"',
        ],
    ],
    'keyboard' => [
        'missing_skip_link' => [
            'message' => 'No skip link found at the beginning of the page',
            'suggestion' => 'Add a link such as <a href="#main">Skip to main content</a> as the first focusable element',
        ],
        'dialog_missing_aria_modal' => [
            'message' => 'Dialog without aria-modal="true"',
            'suggestion' => 'Add aria-modal="true" and manage focus while the dialog is open',
        ],
        'dialog_missing_name' => [
            'message' => 'Dialog without accessible name',
            'suggestion' => 'Add aria-labelledby pointing to the dialog title, or aria-label',
        ],
        'negative_tabindex_on_interactive' => [
            'message' => 'Interactive <:tag> removed from the tab order (tabindex="-1")',
            'suggestion' => 'Remove tabindex="-1" unless focus is managed by a script',
        ],
        'anchor_not_focusable' => [
            'message' => 'Anchor without href is not keyboard accessible',
            'suggestion' => 'Add an href, or use a <button> with a click handler',
        ],
        'positive_tabindex' => [
            'message' => 'Positive tabindex (:value) overrides the natural focus order',
            'suggestion' => 'Use tabindex="0" or reorder the elements in the source',
        ],
        'click_without_keyboard' => [
            'message' => 'Non-interactive <:tag> with click handler is not keyboard accessible',
            'suggestion' => 'Use a <button> or <a>, or add tabindex="0", a role and a keyboard handler',
        ],
        'mouse_only_handler' => [
            'message' => '<:tag> has mouse event handlers but no keyboard equivalent',
            'suggestion' => 'Add onfocus/onblur/onkeydown handlers equivalent to the mouse events',
        ],
    ],
    'aria' => [
        'invalid_role' => [
            'message' => 'Invalid ARIA role ":role"',
            'suggestion' => 'Use a role defined in WAI-ARIA 1.2 or remove the attribute',
        ],
        'redundant_role' => [
            'message' => 'Redundant ARIA role ":role" on <:tag>',
            'suggestion' => 'Remove the role attribute; the element already has this role implicitly',
        ],
        'missing_required_state' => [
            'message' => 'Role ":role" requires the :attribute attribute',
            'suggestion' => 'Add :attribute and keep it in sync with the widget state',
        ],
        'hidden_focusable' => [
            'message' => 'Focusable <:tag> is hidden from assistive technology with aria-hidden="true"',
            'suggestion' => 'Remove aria-hidden, or also remove the element from the tab order (tabindex="-1")',
        ],
        'label_conflict' => [
            'message' => 'Element has both aria-label and aria-labelledby',
            'suggestion' => 'Keep one of the two; aria-labelledby takes precedence',
        ],
        'dangling_idref' => [
            'message' => ':attribute references a non-existent id ":id"',
            'suggestion' => 'Point :attribute to an existing element id',
        ],
        'unsupported_state' => [
            'message' => 'ARIA state :attribute on <:tag> without a role that supports it',
            'suggestion' => 'Add a suitable role or remove the state attribute',
        ],
    ],
];
