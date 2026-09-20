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
];
