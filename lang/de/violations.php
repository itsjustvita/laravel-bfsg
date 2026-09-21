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
    'links' => [
        'non_descriptive' => [
            'message' => 'Nicht aussagekräftiger Linktext „:text“ (:href)',
            'suggestion' => 'Linktext verwenden, der das Ziel beschreibt, oder ein aria-label ergänzen',
        ],
        'missing_name' => [
            'message' => 'Link ohne zugänglichen Text (:href)',
            'suggestion' => 'Linktext, aria-label oder Alternativtext für das Bild im Link ergänzen',
        ],
        'missing_href' => [
            'message' => 'Anker-Element ohne href-Attribut („:text“)',
            'suggestion' => 'href ergänzen oder für Aktionen einen <button> verwenden',
        ],
        'adjacent_duplicate' => [
            'message' => 'Benachbarte doppelte Links zu :href',
            'suggestion' => 'Benachbarte Links zum selben Ziel zu einem Link zusammenfassen',
        ],
        'new_window_unannounced' => [
            'message' => 'Link öffnet ein neues Fenster ohne Hinweis (:href)',
            'suggestion' => '„(öffnet in neuem Fenster)“ im Linktext oder aria-label ergänzen',
        ],
        'missing_noopener' => [
            'message' => 'Link mit target="_blank" ohne rel="noopener" (:href)',
            'suggestion' => 'rel="noopener noreferrer" bei Links ergänzen, die ein neues Fenster öffnen',
        ],
        'url_as_text' => [
            'message' => 'URL als Linktext verwendet (:text)',
            'suggestion' => 'Beschreibenden Text statt der URL verwenden',
        ],
        'download_unannounced' => [
            'message' => 'Download-Link ohne Angabe des Dateityps (:href)',
            'suggestion' => 'Dateityp und Größe im Linktext ergänzen, z. B. „Bericht (:type, 2 MB)“',
        ],
    ],
    'keyboard' => [
        'missing_skip_link' => [
            'message' => 'Kein Sprunglink am Seitenanfang gefunden',
            'suggestion' => 'Einen Link wie <a href="#main">Zum Hauptinhalt springen</a> als erstes fokussierbares Element ergänzen',
        ],
        'dialog_missing_aria_modal' => [
            'message' => 'Dialog ohne aria-modal="true"',
            'suggestion' => 'aria-modal="true" ergänzen und den Fokus im geöffneten Dialog steuern',
        ],
        'dialog_missing_name' => [
            'message' => 'Dialog ohne zugänglichen Namen',
            'suggestion' => 'aria-labelledby auf den Dialogtitel setzen oder aria-label ergänzen',
        ],
        'negative_tabindex_on_interactive' => [
            'message' => 'Interaktives <:tag> aus der Tab-Reihenfolge entfernt (tabindex="-1")',
            'suggestion' => 'tabindex="-1" entfernen, sofern der Fokus nicht per Script gesteuert wird',
        ],
        'anchor_not_focusable' => [
            'message' => 'Anker ohne href ist nicht per Tastatur erreichbar',
            'suggestion' => 'href ergänzen oder einen <button> mit Click-Handler verwenden',
        ],
        'positive_tabindex' => [
            'message' => 'Positiver tabindex (:value) überschreibt die natürliche Fokusreihenfolge',
            'suggestion' => 'tabindex="0" verwenden oder die Elemente im Quelltext umsortieren',
        ],
        'click_without_keyboard' => [
            'message' => 'Nicht interaktives <:tag> mit Click-Handler ist nicht per Tastatur bedienbar',
            'suggestion' => '<button> oder <a> verwenden oder tabindex="0", eine Rolle und einen Tastatur-Handler ergänzen',
        ],
        'mouse_only_handler' => [
            'message' => '<:tag> hat Maus-Handler, aber kein Tastatur-Äquivalent',
            'suggestion' => 'onfocus/onblur/onkeydown-Handler passend zu den Maus-Events ergänzen',
        ],
    ],
    'aria' => [
        'invalid_role' => [
            'message' => 'Ungültige ARIA-Rolle „:role“',
            'suggestion' => 'Eine in WAI-ARIA 1.2 definierte Rolle verwenden oder das Attribut entfernen',
        ],
        'redundant_role' => [
            'message' => 'Redundante ARIA-Rolle „:role“ auf <:tag>',
            'suggestion' => 'role-Attribut entfernen; das Element hat diese Rolle bereits implizit',
        ],
        'missing_required_state' => [
            'message' => 'Rolle „:role“ erfordert das Attribut :attribute',
            'suggestion' => ':attribute ergänzen und mit dem Zustand des Widgets synchron halten',
        ],
        'hidden_focusable' => [
            'message' => 'Fokussierbares <:tag> ist per aria-hidden="true" vor Hilfstechnologie verborgen',
            'suggestion' => 'aria-hidden entfernen oder das Element zusätzlich aus der Tab-Reihenfolge nehmen (tabindex="-1")',
        ],
        'label_conflict' => [
            'message' => 'Element hat sowohl aria-label als auch aria-labelledby',
            'suggestion' => 'Eines der beiden Attribute behalten; aria-labelledby hat Vorrang',
        ],
        'dangling_idref' => [
            'message' => ':attribute verweist auf eine nicht vorhandene id „:id“',
            'suggestion' => ':attribute auf eine vorhandene Element-id zeigen lassen',
        ],
        'unsupported_state' => [
            'message' => 'ARIA-Zustand :attribute auf <:tag> ohne unterstützende Rolle',
            'suggestion' => 'Passende Rolle ergänzen oder das Zustandsattribut entfernen',
        ],
    ],
    'language' => [
        'missing_lang' => [
            'message' => 'Das <html>-Element hat kein lang-Attribut',
            'suggestion' => 'lang="de" (bzw. die Seitensprache) am <html>-Element ergänzen',
        ],
        'invalid_lang' => [
            'message' => 'Ungültiger Sprachcode „:lang“',
            'suggestion' => 'Einen BCP-47-Sprachcode wie „de“, „en-GB“ oder „de-AT“ verwenden',
        ],
        'no_html_element' => [
            'message' => 'Kein <html>-Element im Dokument gefunden',
            'suggestion' => 'Ein vollständiges HTML-Dokument mit <html lang="…"> als Wurzel bereitstellen',
        ],
        'possible_language_change' => [
            'message' => 'Möglicher Sprachwechsel ohne lang-Attribut („:content“)',
            'suggestion' => 'Text in anderer Sprache in ein Element mit lang-Attribut einbetten',
        ],
        'xml_lang_mismatch' => [
            'message' => 'lang=":lang" und xml:lang=":xml_lang" weichen voneinander ab',
            'suggestion' => 'Für lang und xml:lang denselben Wert verwenden oder xml:lang entfernen',
        ],
    ],
];
