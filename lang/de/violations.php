<?php

// Translation keys for violations: [analyzer => [check => ['message' => ..., 'suggestion' => ...]]]
// Placeholders use Laravel's :name syntax and must be identical in every locale.
return [
    'images' => [
        'missing_alt' => [
            'message' => 'Bild ohne alt-Attribut (:src)',
            'suggestion' => 'alt-Attribut ergänzen, das den Bildinhalt beschreibt, oder alt="" für rein dekorative Bilder',
        ],
        'possibly_decorative' => [
            'message' => 'Bild mit leerem Alternativtext ist möglicherweise nicht dekorativ (:src)',
            'suggestion' => 'Prüfen, ob das Bild dekorativ ist; andernfalls den Inhalt im alt-Attribut beschreiben',
        ],
    ],
    'forms' => [
        'control_missing_label' => [
            'message' => 'Formularfeld ":name" (:type) hat keine zugeordnete Beschriftung',
            'suggestion' => '<label for="…"> ergänzen, das Feld in ein <label> einbetten oder aria-label/aria-labelledby verwenden',
        ],
        'form_missing_name' => [
            'message' => 'Formular ohne beschreibende Bezeichnung oder Überschrift',
            'suggestion' => 'aria-label/aria-labelledby am Formular oder eine benennende Überschrift ergänzen',
        ],
        'required_missing_aria_required' => [
            'message' => 'Pflichtfeld ":name" ohne aria-required-Attribut',
            'suggestion' => 'aria-required="true" ergänzen oder den Pflichtstatus in der Beschriftung sichtbar machen',
        ],
    ],
    'headings' => [
        'skipped_level' => [
            'message' => 'Überschriftenebene übersprungen: :to folgt auf :from („:content“)',
            'suggestion' => 'Überschriftenebenen der Reihe nach verwenden (h1 → h2 → h3)',
        ],
        'missing_h1' => [
            'message' => 'Keine h1-Überschrift auf der Seite',
            'suggestion' => 'Genau eine h1 ergänzen, die den Hauptinhalt der Seite benennt',
        ],
        'empty_heading' => [
            'message' => 'Leere :level-Überschrift',
            'suggestion' => 'Überschrift mit Text füllen oder entfernen',
        ],
        'short_heading' => [
            'message' => 'Sehr kurzer Überschriftentext „:content“',
            'suggestion' => 'Überschrift verwenden, die den folgenden Abschnitt beschreibt',
        ],
        'multiple_h1' => [
            'message' => 'Weitere h1-Überschrift Nr. :index („:content“)',
            'suggestion' => 'Eine h1 pro Seite verwenden und h2–h6 für Abschnitte',
        ],
    ],
    'contrast' => [
        'insufficient' => [
            'message' => 'Unzureichender Kontrast :ratio:1 (erforderlich :required:1) für „:content“ — :foreground auf :background',
            'suggestion' => 'Kontrast zwischen Text- und Hintergrundfarbe auf mindestens :required:1 erhöhen',
        ],
        'light_gray_inline' => [
            'message' => 'Inline-Style verwendet eine hellgraue Schriftfarbe',
            'suggestion' => 'Kontrastverhältnis von mindestens 4.5:1 für Fließtext sicherstellen',
        ],
    ],
];
