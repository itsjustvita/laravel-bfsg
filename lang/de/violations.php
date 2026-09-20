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
];
