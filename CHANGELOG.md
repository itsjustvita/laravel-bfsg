# Changelog

Alle bemerkenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.1] - 2025-10-28

### Hinzugefügt
- **Ignored Selectors Configuration** - Filter third-party widgets from analysis
  - New `ignored_selectors` config option for CSS selectors
  - BrowserAnalyzer removes ignored elements before accessibility analysis
  - Pre-configured for Chatbase and other common widgets
  - Useful for analytics scripts, chat widgets, and third-party iframes

### Verbessert
- BrowserAnalyzer now respects `ignored_selectors` config
- Cleaner analysis results by excluding non-content elements

## [1.5.0] - 2025-10-28

### Hinzugefügt
- **TableAnalyzer** - Umfassende Tabellenbarrierefreiheit
  - Prüfung von `<caption>` Elementen für Tabellenbeschreibungen
  - Validierung von `<th>` mit `scope`-Attributen (`row`/`col`)
  - Erkennung von Tabellen ohne Header-Zellen
  - Unterscheidung zwischen Layout- und Datentabellen
  - Validierung komplexer Tabellenbeziehungen mit `headers` Attribut

- **MediaAnalyzer** - Audio/Video Barrierefreiheit
  - Prüfung von Video-Untertiteln (`<track kind="captions">`)
  - Validierung von Audio-Transkript-Referenzen
  - Erkennung problematischer `autoplay`-Attribute
  - Überprüfung auf `controls`-Attribute
  - YouTube iframe Untertitel-Parameter (`cc_load_policy`)

- **SemanticHTMLAnalyzer** - Semantische HTML-Struktur
  - Validierung von Landmark-Elementen (`<main>`, `<nav>`, `<header>`, `<footer>`)
  - Erkennung von "div-itis" (übermäßige `<div>` Nutzung >40%)
  - Prüfung korrekte Verwendung von `<button>` vs `<a>`
  - Validierung von Überschriften in `<section>` Elementen
  - Überprüfung von Listen-Strukturen

- **Report-System** - Professionelle Accessibility-Reports
  - `ReportGenerator` Klasse für mehrere Ausgabeformate
  - **HTML-Reports** mit Compliance-Score (0-100%) und Grades (A+ bis F)
  - **JSON-Reports** für CI/CD Integration
  - **Markdown-Reports** für Dokumentation
  - Detaillierte Statistiken (Critical, Errors, Warnings, Notices)
  - Report-Speicherung in `storage/app/bfsg-reports/`

- **SPA-Testing Guide** - Umfassende Dokumentation
  - Neue `SPA-TESTING.md` Dokumentation
  - Playwright Setup und Installation Anleitung
  - Browser-Konfiguration (Chromium, Firefox, WebKit)
  - Timeout und Wait-Selector Beispiele
  - CI/CD Integration Guides

### Verbessert
- README.md komplett überarbeitet
  - Klare Trennung zwischen `bfsg:check` (Full-Featured) und `bfsg:analyze` (SPA Support)
  - Alle 11 Analyzer dokumentiert (4 neue mit ⭐ NEW Badge)
  - Report-Generation Sektion mit Beispielen
  - SPA-Testing Sektion mit Playwright-Anleitung
  - Config-Beispiel mit allen 11 Analyzern
- Test-Suite auf 60 Tests erweitert (103 Assertions)
- Alle Tests angepasst für semantisches HTML mit `<header>`, `<main>`, `<footer>`

### Technische Details
- 3 neue Analyzer-Klassen (Table, Media, SemanticHTML)
- Report-System mit HTML/JSON/Markdown Templates
- Blade-Template für HTML-Reports (`resources/views/reports/html.blade.php`)
- Integration in `BfsgCheckCommand` für `--format=html` und `--format=json`
- Compliance-Score Algorithmus mit gewichteten Issue-Typen

## [1.4.0] - 2025-10-06

### Hinzugefügt
- **LanguageAnalyzer** für WCAG 3.1.1 und BFSG §3 Compliance
  - Prüfung des `lang`-Attributs auf dem `<html>`-Element
  - Validierung von ISO 639-1 Sprachcodes (inkl. Region-Codes wie "en-US")
  - Erkennung von Sprachwechseln im Content
  - Überprüfung von `xml:lang` Attributen
- **CONTRIBUTING.md** - Umfassende Dokumentation für Contributors
  - Code Standards und Guidelines
  - Testing Best Practices
  - Pull Request Prozess
- **GitHub Actions CI/CD Pipeline** (`.github/workflows/tests.yml`)
  - Automatische Tests auf PHP 8.2, 8.3, 8.4
  - Laravel 12 Kompatibilitätstests
  - Code-Style-Checks mit Pint
- **Development Tools**
  - `.gitignore` für sauberes Repository
  - `.gitattributes` für Export-Optimierung
  - Laravel Pint als dev-dependency

### Verbessert
- LanguageAnalyzer zu `AnalyzeUrlCommand` hinzugefügt
- Test-Suite erweitert: `lang`-Attribute in allen Test-HTML-Beispielen ergänzt
- Alle 60 Tests laufen erfolgreich durch (103 Assertions)
- Konsistente Fehlerbehandlung in allen Analyzern
- Vollständige Testabdeckung für alle 8 Analyzer

### Behoben
- Fehlgeschlagene Tests durch fehlende lang-Attribute (BfsgTest.php)
- CONTRIBUTING.md Referenz in README.md jetzt valide

## [1.3.0] - 2025-09-21

### Hinzugefügt
- **Automatische Herd-Domain-Erkennung**
  - Automatische Erkennung von `.test` Domains (Laravel Herd)
  - Temporärer PHP-Server auf verfügbarem Port (8100-8199)
  - Sauberes Server-Shutdown nach Check
  - Nahtlose BFSG-Prüfung von lokalen Herd-Sites

## [1.2.0] - 2025-09-20

### Hinzugefügt
- **Browser-Engine-Integration für SPA-Unterstützung**
  - Neue `BrowserAnalyzer`-Klasse mit Playwright-Integration
  - Unterstützung für JavaScript-gerenderte Inhalte (React, Vue, Inertia)
  - Neues Artisan-Command: `php artisan bfsg:analyze {url}`
  - `--browser` Flag für Browser-basierte Analyse
  - `--headless=false` Option für sichtbaren Browser beim Debugging
- **Umfassende Test-Suite**
  - Tests für alle Analyzer-Komponenten
  - Tests für BrowserAnalyzer
  - Tests für Artisan-Commands
  - 58 erfolgreiche Tests mit 103 Assertions

### Verbessert
- Konsistente Rückgabestruktur aller Analyzer mit `['issues' => [...]]` Format
- Fallback-Mechanismen für fehlende Keys in Analyzer-Ergebnissen
- Verbesserte Fehlerbehandlung in Commands

### Behoben
- "Undefined array key 'rule'" Fehler in BfsgCheckCommand
- Inkonsistente Datenstrukturen zwischen Analyzern und Hauptklasse
- Fehlende 'issues' Key-Behandlung in Bfsg-Klasse

## [1.1.0] - 2025-09-20

### Hinzugefügt
- KeyboardNavigationAnalyzer für umfassende Tastaturzugänglichkeitstests
  - Erkennung von fokussierbaren Elementen
  - Analyse der Tab-Reihenfolge
  - Erkennung von Tastatur-Fallen
  - Überprüfung von visuellen Fokusindikatoren
  - Analyse von Skip-Links
  - Validierung von ARIA-Attributen
  - Kompatibilität mit dynamischen Inhalten

### Geändert
- Verbesserte Testabdeckung für Analyzer-Komponenten
- Erweiterte Dokumentation mit Beispielen für KeyboardNavigationAnalyzer

## [1.0.0] - 2025-09-20

### Hinzugefügt
- Initiale Veröffentlichung des Laravel BFSG Accessibility Package
- Grundlegende BFSG/WCAG-Konformitätsprüfungen
- Automatisierte Accessibility-Analysen
- Laravel-Integration mit Service Provider und Facade
- Umfassende Testsuite
- MIT-Lizenz