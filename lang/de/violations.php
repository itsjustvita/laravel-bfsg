<?php

// Translation keys for violations: [analyzer => [check => ['message' => ..., 'suggestion' => ...]]]
// Placeholders use Laravel's :name syntax and must be identical in every locale.
return [
    'images' => [
        'missing_alt' => [
            'message' => 'Bild ohne Textalternative (:src)',
            'suggestion' => 'alt-Attribut ergänzen, das den Bildinhalt beschreibt, oder alt="" für rein dekorative Bilder',
        ],
        'area_missing_alt' => [
            'message' => 'Imagemap-Bereich ohne Textalternative (:href)',
            'suggestion' => 'alt-Attribut ergänzen, das das Linkziel des Bereichs beschreibt',
        ],
        'svg_missing_name' => [
            'message' => 'Inline-SVG ohne zugänglichen Namen',
            'suggestion' => '<title> als erstes Kindelement oder aria-label ergänzen; rein dekorative SVGs mit aria-hidden="true" auszeichnen',
        ],
        'possibly_decorative' => [
            'message' => 'Bild mit leerem Alternativtext wird als dekorativ behandelt (:src)',
            'suggestion' => 'Prüfen, ob das Bild dekorativ ist; andernfalls den Inhalt im alt-Attribut beschreiben',
        ],
        'suspicious_alt' => [
            'message' => 'Alternativtext „:alt“ beschreibt das Bild nicht (:src)',
            'suggestion' => 'Inhalt oder Funktion des Bildes beschreiben statt Dateiname oder Allgemeinbegriff',
        ],
    ],
    'forms' => [
        'control_missing_label' => [
            'message' => 'Formularfeld ":name" (:type) hat keinen zugänglichen Namen',
            'suggestion' => '<label for="…"> ergänzen, das Feld in ein <label> einbetten oder aria-label/aria-labelledby verwenden',
        ],
        'button_missing_name' => [
            'message' => 'Schaltfläche <:tag> ohne zugänglichen Namen',
            'suggestion' => 'Der Schaltfläche sichtbaren Text, einen value oder ein aria-label geben, das die Aktion benennt',
        ],
        'radio_group_missing_legend' => [
            'message' => 'Optionsfeld-Gruppe ":name" ohne Gruppenbeschriftung',
            'suggestion' => 'Optionsfelder in ein <fieldset> mit <legend> einbetten oder role="radiogroup" mit aria-labelledby verwenden',
        ],
        'required_not_indicated' => [
            'message' => 'Pflichtfeld ":name" ist in der Beschriftung nicht als Pflichtfeld gekennzeichnet',
            'suggestion' => 'Pflichtfeld in der sichtbaren Beschriftung kennzeichnen (z. B. „Pflichtfeld“ oder ein erklärtes Sternchen)',
        ],
    ],
    'headings' => [
        'skipped_level' => [
            'message' => 'Überschriftenebene übersprungen: :to folgt auf :from („:content“)',
            'suggestion' => 'Überschriftenebenen der Reihe nach verwenden (h1 → h2 → h3)',
        ],
        'missing_h1' => [
            'message' => 'Keine Überschrift der ersten Ebene auf der Seite',
            'suggestion' => 'Eine h1 ergänzen, die den Hauptinhalt der Seite benennt',
        ],
        'multiple_h1' => [
            'message' => 'Weitere Überschrift der ersten Ebene („:content“)',
            'suggestion' => 'Eine h1 pro Seite und h2–h6 für Abschnitte in Betracht ziehen',
        ],
        'empty_heading' => [
            'message' => 'Leere :level-Überschrift',
            'suggestion' => 'Der Überschrift Text (oder ein Bild mit Alternativtext) geben oder sie entfernen',
        ],
        'short_heading' => [
            'message' => 'Sehr kurzer Überschriftentext „:content“',
            'suggestion' => 'Eine Überschrift verwenden, die den folgenden Abschnitt beschreibt',
        ],
    ],
    'contrast' => [
        'insufficient' => [
            'message' => 'Unzureichender Textkontrast :ratio:1 (erforderlich :required:1): :foreground auf :background',
            'suggestion' => 'Kontrast zwischen Text- und Hintergrundfarbe auf mindestens :required:1 erhöhen',
        ],
        'analysis_truncated' => [
            'message' => 'Kontrastprüfung nach :limit Textelementen abgebrochen',
            'suggestion' => 'Übrige Texte mit einem browserbasierten Kontrastwerkzeug prüfen oder kleinere Seiten analysieren',
        ],
    ],
    'links' => [
        'missing_name' => [
            'message' => 'Link ohne zugänglichen Namen (:href)',
            'suggestion' => 'Linktext, ein aria-label oder einen Alternativtext am Bild im Link ergänzen',
        ],
        'non_descriptive' => [
            'message' => 'Linktext „:text“ beschreibt das Ziel nicht (:href)',
            'suggestion' => 'Linktext verwenden, der das Ziel benennt, oder ein entsprechendes aria-label ergänzen',
        ],
        'non_descriptive_in_context' => [
            'message' => 'Linktext „:text“ ist nur im Kontext verständlich (:href)',
            'suggestion' => 'Linktext bevorzugen, der auch für sich allein verständlich ist, z. B. in Linklisten',
        ],
        'url_as_text' => [
            'message' => 'URL als Linktext verwendet (:text)',
            'suggestion' => 'Beschreibenden Text statt der URL verwenden',
        ],
        'new_window_unannounced' => [
            'message' => 'Link öffnet ohne Hinweis ein neues Fenster (:href)',
            'suggestion' => '„(öffnet in neuem Fenster)“ im Linktext oder aria-label ergänzen',
        ],
        'missing_noopener' => [
            'message' => 'Externer Link mit target="_blank" ohne rel="noopener" (:href)',
            'suggestion' => 'rel="noopener" an externen Links ergänzen, die ein neues Fenster öffnen',
        ],
        'download_unannounced' => [
            'message' => 'Link auf eine :type-Datei nennt den Dateityp nicht (:href)',
            'suggestion' => 'Dateityp und -größe im Linktext angeben, z. B. „Jahresbericht (PDF, 2 MB)“',
        ],
        'adjacent_duplicate' => [
            'message' => 'Benachbarte Links mit gleichem Text verweisen auf :href',
            'suggestion' => 'Benachbarte Links zum selben Ziel zu einem Link zusammenfassen',
        ],
        'pseudo_link' => [
            'message' => 'Link als Schaltfläche verwendet (:href)',
            'suggestion' => '<button> für Aktionen verwenden und <a href> für Navigation beibehalten',
        ],
    ],
    'keyboard' => [
        'missing_skip_link' => [
            'message' => 'Kein main-Landmark und kein Sprunglink am Seitenanfang',
            'suggestion' => 'Ein <main>-Element oder einen Link wie <a href="#content">Zum Inhalt springen</a> als einen der ersten Links ergänzen',
        ],
        'skip_link_target_missing' => [
            'message' => 'Ziel :href des Sprunglinks existiert nicht',
            'suggestion' => 'Sprunglink auf die id des Hauptinhalts verweisen lassen',
        ],
        'positive_tabindex' => [
            'message' => 'Positiver tabindex (:value) überschreibt die natürliche Fokusreihenfolge',
            'suggestion' => 'tabindex="0" verwenden oder die Elemente im Quelltext umsortieren',
        ],
        'negative_tabindex_on_interactive' => [
            'message' => 'Interaktives <:tag> aus der Tab-Reihenfolge entfernt (tabindex="-1")',
            'suggestion' => 'tabindex="-1" entfernen, sofern der Fokus nicht per Skript verwaltet wird',
        ],
        'dialog_missing_name' => [
            'message' => 'Dialog ohne zugänglichen Namen',
            'suggestion' => 'aria-labelledby mit Verweis auf den Dialogtitel oder aria-label ergänzen',
        ],
        'dialog_missing_aria_modal' => [
            'message' => 'Dialog-Rolle ohne aria-modal="true"',
            'suggestion' => 'aria-modal="true" ergänzen und den Fokus im geöffneten Dialog halten oder <dialog> verwenden',
        ],
        'click_without_keyboard' => [
            'message' => '<:tag> mit Klick-Handler ist nicht per Tastatur bedienbar',
            'suggestion' => '<button> oder <a href> verwenden oder tabindex="0", eine Rolle und einen Tastatur-Handler ergänzen',
        ],
        'role_without_tabindex' => [
            'message' => '<:tag> mit Rolle „:role“ ist nicht fokussierbar',
            'suggestion' => 'tabindex="0" ergänzen (oder in zusammengesetzten Widgets den Fokus mit tabindex="-1" verwalten) oder das native Element verwenden',
        ],
        'anchor_not_focusable' => [
            'message' => 'Anker ohne href wird als Bedienelement genutzt, ist aber nicht fokussierbar',
            'suggestion' => 'href ergänzen oder einen <button> mit Klick-Handler verwenden',
        ],
        'mouse_only_handler' => [
            'message' => '<:tag> hat Maus-Ereignisse, aber keine Tastatur-Entsprechung',
            'suggestion' => 'onfocus-/onblur-/onkeydown-Handler ergänzen, die den Maus-Ereignissen entsprechen',
        ],
    ],
    'aria' => [
        'invalid_role' => [
            'message' => 'Ungültige ARIA-Rolle „:role“',
            'suggestion' => 'Eine in WAI-ARIA 1.2 definierte Rolle verwenden oder das Attribut entfernen',
        ],
        'abstract_role' => [
            'message' => 'Abstrakte ARIA-Rolle „:role“ darf nicht im Inhalt verwendet werden',
            'suggestion' => 'Abstrakte Rolle durch eine konkrete Rolle wie button, link oder region ersetzen',
        ],
        'missing_required_state' => [
            'message' => 'Rolle „:role“ erfordert das Attribut :attribute',
            'suggestion' => ':attribute ergänzen und mit dem Zustand des Bedienelements synchron halten',
        ],
        'unsupported_state' => [
            'message' => 'ARIA-Zustand :attribute an <:tag> wird von dessen Rolle nicht unterstützt',
            'suggestion' => 'Dem Element eine Rolle geben, die den Zustand unterstützt, oder das Attribut entfernen',
        ],
        'hidden_focusable' => [
            'message' => 'Fokussierbares <:tag> ist mit aria-hidden="true" vor Hilfstechnologien verborgen',
            'suggestion' => 'aria-hidden entfernen oder das Element zusätzlich aus der Tab-Reihenfolge nehmen (tabindex="-1" oder disabled)',
        ],
        'redundant_role' => [
            'message' => 'Redundante ARIA-Rolle „:role“ an <:tag>',
            'suggestion' => 'role-Attribut entfernen; das Element hat diese Rolle bereits implizit',
        ],
        'dangling_idref' => [
            'message' => ':attribute verweist auf eine nicht vorhandene id „:id“',
            'suggestion' => ':attribute auf eine vorhandene Element-id verweisen lassen',
        ],
        'duplicate_id' => [
            'message' => 'Referenzierte id „:id“ wird mehrfach verwendet',
            'suggestion' => 'id eindeutig machen, damit Verweise genau auf ein Element zeigen',
        ],
    ],
    'language' => [
        'missing_lang' => [
            'message' => 'Das <html>-Element hat kein lang-Attribut',
            'suggestion' => 'lang="de" (bzw. die Sprache der Seite) am <html>-Element ergänzen',
        ],
        'empty_lang' => [
            'message' => 'Das <html>-Element hat ein leeres lang-Attribut',
            'suggestion' => 'lang auf die Sprache der Seite setzen, z. B. lang="de"',
        ],
        'invalid_lang' => [
            'message' => 'Ungültiges Sprach-Tag „:lang“',
            'suggestion' => 'Ein BCP-47-Sprach-Tag wie „de“, „en-GB“ oder „de-AT“ verwenden (Bindestrich statt Unterstrich)',
        ],
        'unknown_lang' => [
            'message' => 'Unbekannter Sprachcode „:lang“',
            'suggestion' => 'Einen ISO-639-1-Sprachcode wie „de“, „en“ oder „fr“ verwenden',
        ],
        'possible_language_change' => [
            'message' => 'Text wirkt anderssprachig (:lang), hat aber kein passendes lang-Attribut („:content“)',
            'suggestion' => 'Anderssprachigen Text in ein Element mit lang-Attribut einschließen',
        ],
        'xml_lang_mismatch' => [
            'message' => 'lang=":lang" und xml:lang=":xml_lang" unterscheiden sich',
            'suggestion' => 'Für lang und xml:lang denselben Wert verwenden oder xml:lang entfernen',
        ],
    ],
    'tables' => [
        'layout_table_with_semantics' => [
            'message' => 'Layouttabelle (role="presentation") enthält :found',
            'suggestion' => 'Tabellensemantik aus Layouttabellen entfernen oder role="presentation" weglassen, falls es eine Datentabelle ist',
        ],
        'missing_headers' => [
            'message' => 'Datentabelle ohne Kopfzellen',
            'suggestion' => 'Kopfzellen mit <th> (und scope) auszeichnen oder id/headers-Zuordnungen verwenden',
        ],
        'missing_caption' => [
            'message' => 'Tabelle ohne Beschriftung oder zugänglichen Namen',
            'suggestion' => '<caption> ergänzen, die die Tabelle benennt, oder aria-label/aria-labelledby verwenden',
        ],
        'th_missing_scope' => [
            'message' => 'Kopfzelle „:content“ in komplexer Tabelle ohne scope',
            'suggestion' => 'scope="col" oder scope="row" ergänzen oder Zellen über id/headers zuordnen',
        ],
        'invalid_scope' => [
            'message' => 'Ungültiger scope-Wert „:value“',
            'suggestion' => 'scope="col", "row", "colgroup" oder "rowgroup" verwenden',
        ],
        'dangling_headers_ref' => [
            'message' => 'Zelle verweist auf eine nicht vorhandene Kopfzellen-id „:id“',
            'suggestion' => 'headers-Attribut auf vorhandene <th>-ids verweisen lassen',
        ],
        'nested_table' => [
            'message' => 'Verschachtelte Tabelle',
            'suggestion' => 'Datentabellen nicht verschachteln; Struktur vereinfachen',
        ],
    ],
    'media' => [
        'video_missing_captions' => [
            'message' => 'Video ohne Untertitel (:src)',
            'suggestion' => '<track kind="captions"> (oder subtitles) in der Sprache des Videos ergänzen',
        ],
        'video_missing_audio_description' => [
            'message' => 'Video ohne Audiodeskriptionsspur (:src)',
            'suggestion' => 'Audiodeskription bereitstellen (track kind="descriptions" oder beschriebene Fassung), wenn das Video visuelle Informationen vermittelt',
        ],
        'video_missing_controls' => [
            'message' => 'Video ohne Bedienelemente (:src)',
            'suggestion' => 'controls-Attribut oder barrierefreie eigene Bedienelemente ergänzen',
        ],
        'autoplay_with_audio' => [
            'message' => '<:tag> spielt Ton automatisch ab',
            'suggestion' => 'Medien mit Ton nicht automatisch abspielen; falls nötig, stumm schalten und eine Pause-Funktion anbieten',
        ],
        'autoplay_without_pause' => [
            'message' => 'Stummes Video startet automatisch ohne Möglichkeit zum Anhalten (:src)',
            'suggestion' => 'Bedienelemente oder eine Pause-Schaltfläche ergänzen oder die Bewegung nach fünf Sekunden beenden',
        ],
        'audio_missing_transcript' => [
            'message' => 'Audio ohne Transkript (:src)',
            'suggestion' => 'Transkript neben dem Player bereitstellen und per aria-describedby verknüpfen',
        ],
        'iframe_missing_title' => [
            'message' => 'Frame ohne zugänglichen Namen (:src)',
            'suggestion' => 'title-Attribut ergänzen, das den eingebetteten Inhalt beschreibt',
        ],
        'embedded_video_captions_unknown' => [
            'message' => 'Eingebetteter Videoplayer: Untertitel nicht prüfbar (:src)',
            'suggestion' => 'Prüfen, ob das eingebettete Video korrekte Untertitel hat',
        ],
    ],
    'semantic' => [
        'missing_main' => [
            'message' => 'Kein <main>-Landmark gefunden',
            'suggestion' => 'Hauptinhalt in ein <main>-Element einschließen, damit Nutzende direkt dorthin springen können',
        ],
        'multiple_main' => [
            'message' => 'Weiteres sichtbares <main>-Landmark #:index',
            'suggestion' => 'Nur ein sichtbares <main>-Element pro Seite verwenden',
        ],
        'section_without_heading' => [
            'message' => '<section> ohne Überschrift oder zugänglichen Namen',
            'suggestion' => 'Abschnitt mit einer Überschrift beginnen oder ein aria-label vergeben',
        ],
        'empty_list' => [
            'message' => '<:tag> ohne <li>-Einträge',
            'suggestion' => 'Leere Listen entfernen oder mit Listeneinträgen füllen',
        ],
        'button_with_href' => [
            'message' => '<button> mit href-Attribut',
            'suggestion' => '<a href> für Navigation und <button> für Aktionen verwenden',
        ],
    ],
    'page_title' => [
        'missing_title' => [
            'message' => 'Seite hat kein <title>-Element',
            'suggestion' => '<title> im <head> ergänzen, der die Seite beschreibt',
        ],
        'empty_title' => [
            'message' => 'Seitentitel <title> ist leer',
            'suggestion' => 'Dem <title> einen beschreibenden Text geben',
        ],
        'short_title' => [
            'message' => 'Seitentitel ist zu kurz (:length Zeichen)',
            'suggestion' => 'Einen Titel verwenden, der den Seiteninhalt benennt',
        ],
        'generic_title' => [
            'message' => 'Seitentitel „:title“ ist zu allgemein',
            'suggestion' => 'Einen Titel verwenden, der Seite und Website benennt, z. B. „Kontakt | Firma“',
        ],
        'long_title' => [
            'message' => 'Seitentitel ist sehr lang (:length Zeichen)',
            'suggestion' => 'Titel knapp halten; die wichtigsten Wörter an den Anfang stellen',
        ],
        'multiple_titles' => [
            'message' => 'Weiteres <title>-Element im <head>',
            'suggestion' => 'Genau einen <title> pro Seite verwenden',
        ],
    ],
    'input_purpose' => [
        'missing_autocomplete' => [
            'message' => 'Feld für persönliche Daten ":name" ohne autocomplete-Attribut',
            'suggestion' => 'Passenden autocomplete-Wert ergänzen, z. B. autocomplete="email" oder "given-name"',
        ],
        'invalid_autocomplete' => [
            'message' => 'Ungültiger autocomplete-Wert ":value" am Feld ":name"',
            'suggestion' => 'Einen Wert aus der HTML-Autofill-Liste verwenden, z. B. "name", "email", "tel", "street-address", "postal-code"',
        ],
        'autocomplete_off_on_personal_field' => [
            'message' => 'Automatisches Ausfüllen ist am Feld für persönliche Daten ":name" abgeschaltet',
            'suggestion' => 'Passenden autocomplete-Wert statt autocomplete="off" verwenden, damit das Feld automatisch ausgefüllt werden kann',
        ],
    ],
    'focus' => [
        'outline_removed_inline' => [
            'message' => 'Fokusrahmen per Inline-Style an <:tag> entfernt',
            'suggestion' => 'outline:none nicht inline setzen; stattdessen einen sichtbaren Fokusstil (outline oder box-shadow) vorsehen',
        ],
        'outline_removed_global' => [
            'message' => 'Globales Zurücksetzen des Fokusrahmens („:selector“) ohne Ersatzindikator',
            'suggestion' => 'Das Zurücksetzen durch einen sichtbaren Fokusstil für focus-visible ersetzen, z. B. outline: 2px solid',
        ],
        'outline_removed' => [
            'message' => 'Fokusrahmen für „:selector“ ohne alternativen Indikator entfernt',
            'suggestion' => 'Sichtbaren Fokusstil (outline, box-shadow, border oder background) für diesen Selektor ergänzen',
        ],
    ],
    'error_handling' => [
        'no_error_strategy' => [
            'message' => 'Formular „:name“ mit Pflichtfeldern ohne erkennbare Fehlerbehandlung',
            'suggestion' => 'Ungültige Felder mit aria-invalid kennzeichnen und den Fehlertext per aria-describedby oder aria-errormessage verknüpfen; Fehler mit role="alert" ankündigen',
        ],
        'css_only_error_indicators' => [
            'message' => 'Formular „:name“ zeigt Fehler nur über CSS-Klassen an',
            'suggestion' => 'aria-invalid="true" und aria-describedby an fehlerhaften Feldern ergänzen',
        ],
    ],
    'status_messages' => [
        'invalid_aria_live' => [
            'message' => 'Ungültiger aria-live-Wert „:value“',
            'suggestion' => 'aria-live="polite", "assertive" oder "off" verwenden',
        ],
        'no_live_region' => [
            'message' => 'Seite mit interaktiven Elementen ohne Live-Region für Statusmeldungen',
            'suggestion' => 'Container mit role="status" oder aria-live="polite" für Statusmeldungen ergänzen',
        ],
    ],
];
