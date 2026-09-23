<?php

// Translation keys for violations: [analyzer => [check => ['message' => ..., 'suggestion' => ...]]]
// Placeholders use Laravel's :name syntax and must be identical in every locale.
return [
    'images' => [
        'missing_alt' => [
            'message' => 'Image without text alternative (:src)',
            'suggestion' => 'Add an alt attribute that describes the image, or alt="" if it is purely decorative',
        ],
        'area_missing_alt' => [
            'message' => 'Image map area without text alternative (:href)',
            'suggestion' => 'Add an alt attribute that describes the link target of the area',
        ],
        'svg_missing_name' => [
            'message' => 'Inline SVG without accessible name',
            'suggestion' => 'Add a <title> as first child or aria-label; mark purely decorative SVGs with aria-hidden="true"',
        ],
        'possibly_decorative' => [
            'message' => 'Image with empty alt text is treated as decorative (:src)',
            'suggestion' => 'Verify the image is decorative; otherwise describe it in the alt attribute',
        ],
        'suspicious_alt' => [
            'message' => 'Alt text ":alt" does not describe the image (:src)',
            'suggestion' => 'Describe the content or function of the image instead of a file name or a generic word',
        ],
    ],
    'forms' => [
        'control_missing_label' => [
            'message' => 'Form control ":name" (:type) has no accessible name',
            'suggestion' => 'Add a <label for="…">, wrap the control in a <label>, or use aria-label/aria-labelledby',
        ],
        'button_missing_name' => [
            'message' => 'Button <:tag> without accessible name',
            'suggestion' => 'Give the button visible text, a value, or an aria-label that states its action',
        ],
        'radio_group_missing_legend' => [
            'message' => 'Radio group ":name" without group label',
            'suggestion' => 'Wrap the radio buttons in a <fieldset> with a <legend>, or use role="radiogroup" with aria-labelledby',
        ],
        'required_not_indicated' => [
            'message' => 'Required field ":name" is not marked as required in its label',
            'suggestion' => 'Mark the field as required in the visible label (e.g. "required" or an explained asterisk)',
        ],
    ],
    'headings' => [
        'skipped_level' => [
            'message' => 'Heading level skipped: :to follows :from (":content")',
            'suggestion' => 'Use heading levels in order without skipping (h1 → h2 → h3)',
        ],
        'missing_h1' => [
            'message' => 'No level-one heading on the page',
            'suggestion' => 'Add an h1 that names the main content of the page',
        ],
        'multiple_h1' => [
            'message' => 'Additional level-one heading (":content")',
            'suggestion' => 'Consider one h1 per page and h2–h6 for sections',
        ],
        'empty_heading' => [
            'message' => 'Empty :level heading',
            'suggestion' => 'Give the heading text (or an image with alt text), or remove it',
        ],
        'short_heading' => [
            'message' => 'Very short heading text ":content"',
            'suggestion' => 'Use a heading that describes the section it introduces',
        ],
    ],
    'contrast' => [
        'insufficient' => [
            'message' => 'Insufficient text contrast :ratio:1 (required :required:1): :foreground on :background',
            'suggestion' => 'Increase the contrast between text and background colour to at least :required:1',
        ],
        'analysis_truncated' => [
            'message' => 'Contrast analysis stopped after :limit text elements',
            'suggestion' => 'Check the remaining text with a browser-based contrast tool, or analyze smaller pages',
        ],
    ],
    'links' => [
        'missing_name' => [
            'message' => 'Link without accessible name (:href)',
            'suggestion' => 'Add link text, an aria-label, or alt text on the image inside the link',
        ],
        'non_descriptive' => [
            'message' => 'Link text ":text" does not describe the destination (:href)',
            'suggestion' => 'Use link text that names the destination, or add an aria-label that does',
        ],
        'non_descriptive_in_context' => [
            'message' => 'Link text ":text" relies on the surrounding context (:href)',
            'suggestion' => 'Prefer link text that is meaningful on its own, e.g. in a list of links',
        ],
        'url_as_text' => [
            'message' => 'URL used as link text (:text)',
            'suggestion' => 'Use descriptive text instead of the URL',
        ],
        'new_window_unannounced' => [
            'message' => 'Link opens a new window without notice (:href)',
            'suggestion' => 'Add "(opens in new window)" to the link text or aria-label',
        ],
        'missing_noopener' => [
            'message' => 'External link with target="_blank" lacks rel="noopener" (:href)',
            'suggestion' => 'Add rel="noopener" to external links that open a new window',
        ],
        'download_unannounced' => [
            'message' => 'Link to a :type file does not mention the file type (:href)',
            'suggestion' => 'Add the file type and size to the link text, e.g. "Annual report (PDF, 2 MB)"',
        ],
        'adjacent_duplicate' => [
            'message' => 'Adjacent links with the same text point to :href',
            'suggestion' => 'Merge adjacent links to the same destination into one link',
        ],
        'pseudo_link' => [
            'message' => 'Link used as a button (:href)',
            'suggestion' => 'Use a <button> for actions and keep <a href> for navigation',
        ],
    ],
    'keyboard' => [
        'missing_skip_link' => [
            'message' => 'No main landmark and no skip link at the beginning of the page',
            'suggestion' => 'Add a <main> element, or a link such as <a href="#content">Skip to main content</a> as one of the first links',
        ],
        'skip_link_target_missing' => [
            'message' => 'Skip link target :href does not exist',
            'suggestion' => 'Point the skip link to the id of the main content container',
        ],
        'positive_tabindex' => [
            'message' => 'Positive tabindex (:value) overrides the natural focus order',
            'suggestion' => 'Use tabindex="0" or reorder the elements in the source',
        ],
        'negative_tabindex_on_interactive' => [
            'message' => 'Interactive <:tag> removed from the tab order (tabindex="-1")',
            'suggestion' => 'Remove tabindex="-1" unless focus is managed by a script',
        ],
        'dialog_missing_name' => [
            'message' => 'Dialog without accessible name',
            'suggestion' => 'Add aria-labelledby pointing to the dialog title, or aria-label',
        ],
        'dialog_missing_aria_modal' => [
            'message' => 'Dialog role without aria-modal="true"',
            'suggestion' => 'Add aria-modal="true" and keep focus inside the dialog while it is open, or use <dialog>',
        ],
        'click_without_keyboard' => [
            'message' => '<:tag> with click handler is not operable by keyboard',
            'suggestion' => 'Use a <button> or <a href>, or add tabindex="0", a role and a keyboard handler',
        ],
        'role_without_tabindex' => [
            'message' => '<:tag> with role ":role" is not focusable',
            'suggestion' => 'Add tabindex="0" (or manage focus with tabindex="-1" in composite widgets), or use the native element',
        ],
        'anchor_not_focusable' => [
            'message' => 'Anchor without href is used as a control but cannot receive focus',
            'suggestion' => 'Add an href, or use a <button> with a click handler',
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
        'abstract_role' => [
            'message' => 'Abstract ARIA role ":role" must not be used in content',
            'suggestion' => 'Replace the abstract role with a concrete role such as button, link or region',
        ],
        'missing_required_state' => [
            'message' => 'Role ":role" requires the :attribute attribute',
            'suggestion' => 'Add :attribute and keep it in sync with the widget state',
        ],
        'unsupported_state' => [
            'message' => 'ARIA state :attribute on <:tag> is not supported by its role',
            'suggestion' => 'Give the element a role that supports the state, or remove the attribute',
        ],
        'hidden_focusable' => [
            'message' => 'Focusable <:tag> is hidden from assistive technology with aria-hidden="true"',
            'suggestion' => 'Remove aria-hidden, or also remove the element from the tab order (tabindex="-1" or disabled)',
        ],
        'redundant_role' => [
            'message' => 'Redundant ARIA role ":role" on <:tag>',
            'suggestion' => 'Remove the role attribute; the element already has this role implicitly',
        ],
        'dangling_idref' => [
            'message' => ':attribute references a non-existent id ":id"',
            'suggestion' => 'Point :attribute to an existing element id',
        ],
        'duplicate_id' => [
            'message' => 'Referenced id ":id" is used more than once',
            'suggestion' => 'Make the id unique so references point to exactly one element',
        ],
    ],
    'language' => [
        'missing_lang' => [
            'message' => 'The <html> element has no lang attribute',
            'suggestion' => 'Add lang="de" (or the page language) to the <html> element',
        ],
        'empty_lang' => [
            'message' => 'The <html> element has an empty lang attribute',
            'suggestion' => 'Set lang to the language of the page, e.g. lang="de"',
        ],
        'invalid_lang' => [
            'message' => 'Invalid language tag ":lang"',
            'suggestion' => 'Use a BCP 47 language tag such as "de", "en-GB" or "de-AT" (hyphen, not underscore)',
        ],
        'unknown_lang' => [
            'message' => 'Unknown language code ":lang"',
            'suggestion' => 'Use an ISO 639-1 language code such as "de", "en" or "fr"',
        ],
        'possible_language_change' => [
            'message' => 'Text looks like another language (:lang) but has no matching lang attribute (":content")',
            'suggestion' => 'Wrap text in another language in an element with a lang attribute',
        ],
        'xml_lang_mismatch' => [
            'message' => 'lang=":lang" and xml:lang=":xml_lang" differ',
            'suggestion' => 'Use the same value for lang and xml:lang, or drop xml:lang',
        ],
    ],
    'tables' => [
        'layout_table_with_semantics' => [
            'message' => 'Layout table (role="presentation") contains :found',
            'suggestion' => 'Remove table semantics from layout tables, or drop role="presentation" if it is a data table',
        ],
        'missing_headers' => [
            'message' => 'Data table without header cells',
            'suggestion' => 'Mark header cells with <th> (and scope) or use id/headers associations',
        ],
        'missing_caption' => [
            'message' => 'Table without caption or accessible name',
            'suggestion' => 'Add a <caption> that names the table, or aria-label/aria-labelledby',
        ],
        'th_missing_scope' => [
            'message' => 'Header cell ":content" in a complex table without scope',
            'suggestion' => 'Add scope="col" or scope="row", or associate cells via id/headers',
        ],
        'invalid_scope' => [
            'message' => 'Invalid scope value ":value"',
            'suggestion' => 'Use scope="col", "row", "colgroup" or "rowgroup"',
        ],
        'dangling_headers_ref' => [
            'message' => 'Cell references a non-existent header id ":id"',
            'suggestion' => 'Point the headers attribute to existing <th> ids',
        ],
        'nested_table' => [
            'message' => 'Nested table',
            'suggestion' => 'Avoid nesting data tables; flatten the structure',
        ],
    ],
    'media' => [
        'video_missing_captions' => [
            'message' => 'Video without captions or subtitles (:src)',
            'suggestion' => 'Add a <track kind="captions"> (or subtitles) in the language of the video',
        ],
        'video_missing_audio_description' => [
            'message' => 'Video without audio description track (:src)',
            'suggestion' => 'Provide an audio description (track kind="descriptions" or a described version) if the video conveys visual information',
        ],
        'video_missing_controls' => [
            'message' => 'Video without controls (:src)',
            'suggestion' => 'Add the controls attribute or accessible custom controls',
        ],
        'autoplay_with_audio' => [
            'message' => '<:tag> plays sound automatically',
            'suggestion' => 'Do not autoplay media with sound; if autoplay is needed, mute it and offer a pause control',
        ],
        'autoplay_without_pause' => [
            'message' => 'Muted video plays automatically without a way to pause it (:src)',
            'suggestion' => 'Add controls or a pause button, or stop the animation after five seconds',
        ],
        'audio_missing_transcript' => [
            'message' => 'Audio without transcript (:src)',
            'suggestion' => 'Provide a transcript next to the player and link it via aria-describedby',
        ],
        'iframe_missing_title' => [
            'message' => 'Frame without accessible name (:src)',
            'suggestion' => 'Add a title attribute that describes the embedded content',
        ],
        'embedded_video_captions_unknown' => [
            'message' => 'Embedded video player: captions cannot be verified (:src)',
            'suggestion' => 'Verify that the embedded video has accurate captions',
        ],
    ],
    'semantic' => [
        'missing_main' => [
            'message' => 'No <main> landmark found',
            'suggestion' => 'Wrap the primary content in a <main> element so users can jump to it',
        ],
        'multiple_main' => [
            'message' => 'Additional visible <main> landmark #:index',
            'suggestion' => 'Use a single visible <main> element per page',
        ],
        'section_without_heading' => [
            'message' => '<section> without heading or accessible name',
            'suggestion' => 'Start the section with a heading or give it an aria-label',
        ],
        'empty_list' => [
            'message' => '<:tag> without <li> children',
            'suggestion' => 'Remove empty lists or fill them with list items',
        ],
        'button_with_href' => [
            'message' => '<button> with href attribute',
            'suggestion' => 'Use <a href> for navigation and <button> for actions',
        ],
    ],
    'page_title' => [
        'missing_title' => [
            'message' => 'Page has no <title> element',
            'suggestion' => 'Add a <title> in <head> that describes the page',
        ],
        'empty_title' => [
            'message' => 'Page <title> is empty',
            'suggestion' => 'Give the <title> descriptive text',
        ],
        'short_title' => [
            'message' => 'Page title is too short (:length characters)',
            'suggestion' => 'Use a title that identifies the page content',
        ],
        'generic_title' => [
            'message' => 'Page title ":title" is too generic',
            'suggestion' => 'Use a title that identifies the page and the site, e.g. "Contact | Acme"',
        ],
        'long_title' => [
            'message' => 'Page title is very long (:length characters)',
            'suggestion' => 'Keep the title concise; put the most important words first',
        ],
        'multiple_titles' => [
            'message' => 'Additional <title> element in <head>',
            'suggestion' => 'Keep exactly one <title> per page',
        ],
    ],
    'input_purpose' => [
        'missing_autocomplete' => [
            'message' => 'Personal-data field ":name" has no autocomplete attribute',
            'suggestion' => 'Add the matching autocomplete token, e.g. autocomplete="email" or "given-name"',
        ],
        'invalid_autocomplete' => [
            'message' => 'Invalid autocomplete value ":value" on field ":name"',
            'suggestion' => 'Use a token from the HTML autofill list, e.g. "name", "email", "tel", "street-address", "postal-code"',
        ],
        'autocomplete_off_on_personal_field' => [
            'message' => 'Autofill is switched off on personal-data field ":name"',
            'suggestion' => 'Use the matching autocomplete token instead of autocomplete="off" so users can fill the field automatically',
        ],
    ],
    'focus' => [
        'outline_removed_inline' => [
            'message' => 'Focus outline removed via inline style on <:tag>',
            'suggestion' => 'Do not set outline: none inline; provide a visible focus style (outline or box-shadow) instead',
        ],
        'outline_removed_global' => [
            'message' => 'Global focus outline reset (":selector") without a replacement indicator',
            'suggestion' => 'Replace the reset with a visible focus style for focus-visible, e.g. outline: 2px solid',
        ],
        'outline_removed' => [
            'message' => 'Focus outline removed for ":selector" without an alternative indicator',
            'suggestion' => 'Add a visible focus style (outline, box-shadow, border or background) for this selector',
        ],
    ],
    'error_handling' => [
        'no_error_strategy' => [
            'message' => 'Form ":name" disables browser validation; its error identification cannot be verified statically',
            'suggestion' => 'Mark invalid fields with aria-invalid, link the error text via aria-describedby or aria-errormessage, and announce errors with role="alert"',
        ],
    ],
    'status_messages' => [
        'invalid_aria_live' => [
            'message' => 'Invalid aria-live value ":value"',
            'suggestion' => 'Use aria-live="polite", "assertive" or "off"',
        ],
        'empty_aria_live' => [
            'message' => 'Empty aria-live attribute',
            'suggestion' => 'Set aria-live="polite" (or "assertive" for urgent messages), or remove the attribute',
        ],
        'alert_without_live_region' => [
            'message' => 'Status message container is not a live region',
            'suggestion' => 'Add role="status" (or role="alert" for errors) so screen readers announce the message',
        ],
    ],
];
