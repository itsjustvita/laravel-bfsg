# Changelog

Alle bemerkenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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