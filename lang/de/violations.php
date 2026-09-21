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
    'tables' => [
        'missing_caption' => [
            'message' => 'Datentabelle ohne Beschriftung',
            'suggestion' => '<caption> ergänzen, die die Tabelle benennt, oder aria-label/aria-labelledby verwenden',
        ],
        'th_missing_scope' => [
            'message' => 'Kopfzelle „:content“ ohne scope-Attribut',
            'suggestion' => 'scope="col" oder scope="row" an Kopfzellen ergänzen',
        ],
        'invalid_scope' => [
            'message' => 'Ungültiger scope-Wert „:value“',
            'suggestion' => 'scope="col", "row", "colgroup" oder "rowgroup" verwenden',
        ],
        'missing_headers' => [
            'message' => 'Datentabelle ohne Kopfzellen',
            'suggestion' => 'Kopfzellen mit <th> (und scope) auszeichnen oder id/headers-Zuordnungen verwenden',
        ],
        'dangling_headers_ref' => [
            'message' => 'Zelle verweist auf eine nicht vorhandene Kopfzellen-id „:id“',
            'suggestion' => 'headers-Attribut auf vorhandene <th>-ids zeigen lassen',
        ],
        'layout_table_with_semantics' => [
            'message' => 'Layout-Tabelle (role="presentation") enthält <:found>',
            'suggestion' => 'Tabellensemantik aus Layout-Tabellen entfernen oder role="presentation" bei Datentabellen weglassen',
        ],
        'nested_table' => [
            'message' => 'Verschachtelte Tabelle',
            'suggestion' => 'Datentabellen nicht verschachteln; Struktur abflachen',
        ],
    ],
    'media' => [
        'video_missing_captions' => [
            'message' => 'Video ohne Untertitel (:src)',
            'suggestion' => '<track kind="captions"> (oder subtitles) in der Sprache des Videos ergänzen',
        ],
        'video_missing_audio_description' => [
            'message' => 'Video ohne Audiodeskription (:src)',
            'suggestion' => 'Audiodeskriptionsspur oder eine beschriebene Alternativversion bereitstellen',
        ],
        'autoplay_with_audio' => [
            'message' => '<:tag> startet automatisch',
            'suggestion' => 'Medien mit Ton nicht automatisch abspielen; bei nötigem Autoplay stummschalten und eine Pause-Funktion anbieten',
        ],
        'video_missing_controls' => [
            'message' => 'Video ohne Bedienelemente (:src)',
            'suggestion' => 'controls-Attribut oder zugängliche eigene Bedienelemente ergänzen',
        ],
        'audio_missing_transcript' => [
            'message' => 'Audio ohne Transkript-Verweis (:src)',
            'suggestion' => 'Transkript bereitstellen und per aria-describedby oder neben dem Player verlinken',
        ],
        'audio_missing_controls' => [
            'message' => 'Audio ohne Bedienelemente (:src)',
            'suggestion' => 'controls-Attribut oder zugängliche eigene Bedienelemente ergänzen',
        ],
        'iframe_missing_title' => [
            'message' => 'Medien-iframe ohne title (:src)',
            'suggestion' => 'title-Attribut ergänzen, das den eingebetteten Inhalt beschreibt',
        ],
        'embedded_video_captions_unknown' => [
            'message' => 'Eingebetteter Videoplayer ohne standardmäßig aktivierte Untertitel (:src)',
            'suggestion' => 'Untertitel in der Embed-URL aktivieren (z. B. cc_load_policy=1) oder Verfügbarkeit prüfen',
        ],
    ],
    'semantic' => [
        'missing_main' => [
            'message' => 'Kein <main>-Landmark gefunden',
            'suggestion' => 'Hauptinhalt in ein <main>-Element einbetten',
        ],
        'multiple_main' => [
            'message' => 'Weiteres <main>-Element Nr. :index',
            'suggestion' => 'Nur ein <main>-Element pro Seite verwenden',
        ],
        'missing_nav' => [
            'message' => 'Kein <nav>-Landmark gefunden',
            'suggestion' => 'Hauptnavigation in ein <nav>-Element einbetten',
        ],
        'section_without_heading' => [
            'message' => '<section> ohne Überschrift oder zugänglichen Namen',
            'suggestion' => 'Abschnitt mit einer Überschrift beginnen oder ein aria-label vergeben',
        ],
        'div_ratio' => [
            'message' => 'Übermäßige Verwendung von <div>-Elementen (:ratio % aller Elemente)',
            'suggestion' => 'Semantische Elemente (header, nav, main, section, article, footer) statt generischer <div>s bevorzugen',
        ],
        'button_with_href' => [
            'message' => '<button> mit href-Attribut',
            'suggestion' => '<a href> für Navigation und <button> für Aktionen verwenden',
        ],
        'anchor_as_button' => [
            'message' => '<a> als Button verwendet (role="button")',
            'suggestion' => 'Für Aktionen ein echtes <button>-Element verwenden',
        ],
        'missing_header' => [
            'message' => 'Kein <header>-Landmark gefunden',
            'suggestion' => 'Seitenweites <header>-Element ergänzen',
        ],
        'missing_footer' => [
            'message' => 'Kein <footer>-Landmark gefunden',
            'suggestion' => 'Seitenweites <footer>-Element ergänzen',
        ],
        'empty_list' => [
            'message' => '<:tag> ohne <li>-Kindelemente',
            'suggestion' => 'Leere Listen entfernen oder mit Listeneinträgen füllen',
        ],
    ],
    'page_title' => [
        'missing_title' => [
            'message' => 'Seite hat kein <title>-Element',
            'suggestion' => '<title> im <head> ergänzen, das die Seite beschreibt',
        ],
        'empty_title' => [
            'message' => '<title> der Seite ist leer',
            'suggestion' => '<title> mit beschreibendem Text füllen',
        ],
        'generic_title' => [
            'message' => 'Seitentitel „:title“ ist zu allgemein',
            'suggestion' => 'Titel verwenden, der Seite und Website benennt, z. B. „Kontakt | Firma“',
        ],
        'short_title' => [
            'message' => 'Seitentitel ist zu kurz (:length Zeichen)',
            'suggestion' => 'Titel verwenden, der den Seiteninhalt benennt',
        ],
        'long_title' => [
            'message' => 'Seitentitel ist zu lang (:length Zeichen)',
            'suggestion' => 'Titel knapp halten; die wichtigsten Wörter zuerst',
        ],
    ],
    'input_purpose' => [
        'missing_autocomplete' => [
            'message' => 'Feld für personenbezogene Daten „:name“ ohne autocomplete-Attribut',
            'suggestion' => 'Passendes autocomplete-Token ergänzen, z. B. autocomplete="email" oder "given-name"',
        ],
        'invalid_autocomplete' => [
            'message' => 'Ungültiger autocomplete-Wert „:value“ am Feld „:name“',
            'suggestion' => 'Ein Token aus der HTML-Autofill-Liste verwenden, z. B. "name", "email", "tel", "street-address", "postal-code"',
        ],
    ],
];
