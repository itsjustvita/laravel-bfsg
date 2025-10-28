# SPA Testing Guide

Laravel BFSG unterstützt das Testen von **Single Page Applications (SPAs)** mit echtem Browser-Rendering durch Playwright.

## 🎯 Warum Browser-Testing?

SPAs (React, Vue, Inertia, etc.) rendern Content dynamisch mit JavaScript. Server-seitiges Parsing sieht nur das initiale HTML - nicht den finalen, gerenderten Content. Browser-Testing löst das!

## 📦 Installation

### 1. Playwright installieren

```bash
# Playwright NPM Package
npm install playwright

# Browser-Binaries herunterladen
npx playwright install
```

### 2. Verfügbare Browser

Playwright unterstützt:
- **Chromium** (empfohlen, Standard)
- **Firefox**
- **WebKit** (Safari)

```bash
# Nur Chromium (kleiner Download)
npx playwright install chromium

# Oder alle Browser
npx playwright install
```

## 🚀 Verwendung

### Command Line

```bash
# SPA mit Browser-Rendering analysieren
php artisan bfsg:analyze https://example.com --browser

# Mit sichtbarem Browser (zum Debugging)
php artisan bfsg:analyze https://example.com --browser --headless=false

# Timeout anpassen (Standard: 30s)
php artisan bfsg:analyze https://example.com --browser --timeout=60000

# Anderen Browser verwenden
php artisan bfsg:analyze https://example.com --browser --browser-type=firefox
```

### Programmatisch

```php
use ItsJustVita\LaravelBfsg\BrowserAnalyzer;

$analyzer = new BrowserAnalyzer([
    'browser' => 'chromium',  // chromium, firefox, webkit
    'headless' => true,        // false = sichtbarer Browser
    'timeout' => 30000,        // Millisekunden
    'waitForSelector' => 'body', // Auf Element warten
]);

$results = $analyzer->analyzeUrl('https://spa-app.com');

if ($results['success']) {
    foreach ($results['results'] as $analyzerName => $issues) {
        echo "{$analyzerName}: " . count($issues['issues']) . " issues\n";
    }
} else {
    echo "Error: " . $results['error'];
}
```

## 🔧 Erweiterte Optionen

### Custom Wait Selector

Für SPAs, die lange laden:

```php
$analyzer = new BrowserAnalyzer([
    'waitForSelector' => '#app-loaded', // Warte auf spezifisches Element
    'timeout' => 60000, // 60 Sekunden
]);
```

### Verschiedene Browser

```php
// Chromium (empfohlen, schnell, Chrome-ähnlich)
new BrowserAnalyzer(['browser' => 'chromium']);

// Firefox
new BrowserAnalyzer(['browser' => 'firefox']);

// WebKit (Safari)
new BrowserAnalyzer(['browser' => 'webkit']);
```

### Debug-Modus

```php
$analyzer = new BrowserAnalyzer([
    'headless' => false, // Browser ist sichtbar
]);

// Oder via Command
php artisan bfsg:analyze URL --browser --headless=false
```

## 📝 Anwendungsfälle

### 1. React/Vue/Angular Apps

```bash
# Normale React App
php artisan bfsg:analyze https://react-app.test --browser

# Mit Authentifizierung
php artisan bfsg:analyze https://react-app.test/dashboard \
    --browser \
    --auth \
    --email=user@test.com \
    --password=secret
```

### 2. Inertia.js Apps

```bash
# Inertia rendert serverseitig UND clientseitig
php artisan bfsg:analyze https://inertia-app.test/users --browser
```

### 3. Livewire Apps

```bash
# Livewire ist meistens server-rendered, aber mit --browser siehst du den finalen State
php artisan bfsg:analyze https://livewire-app.test --browser
```

### 4. Lazy-Loaded Content

```bash
# Warte länger für lazy-loaded Images/Content
php artisan bfsg:analyze https://app.test/gallery --browser --timeout=60000
```

## 🐛 Troubleshooting

### "Playwright is not installed"

```bash
npm install playwright
npx playwright install
```

### "Failed to launch browser"

```bash
# Browser-Binaries neu installieren
npx playwright install --force
```

### Timeout Errors

```bash
# Timeout erhöhen
php artisan bfsg:analyze URL --browser --timeout=60000
```

### Port already in use

Playwright startet Browser auf zufälligen Ports - normalerweise kein Problem. Falls doch:

```bash
# Andere Playwright-Prozesse killen
pkill -f playwright
```

## ⚡ Performance

### Server-Side vs Browser

| Methode | Geschwindigkeit | Use Case |
|---------|----------------|----------|
| **Server-Side** | ~1-2s | Statisches HTML, Server-Rendering |
| **Browser** | ~5-10s | SPAs, JavaScript-Heavy Apps |

### Tipps

1. **Server-Side First**: Teste statische Seiten ohne `--browser`
2. **Browser für SPAs**: Nur für React/Vue/etc.
3. **Headless Mode**: Immer `--headless=true` in CI/CD
4. **Caching**: Playwright cached Browser-Binaries

## 🔄 CI/CD Integration

### GitHub Actions

```yaml
- name: Install Playwright
  run: |
    npm install playwright
    npx playwright install chromium

- name: Run BFSG Browser Tests
  run: php artisan bfsg:analyze ${{ secrets.APP_URL }} --browser
```

### GitLab CI

```yaml
test:
  before_script:
    - npm install playwright
    - npx playwright install chromium
  script:
    - php artisan bfsg:analyze https://staging.example.com --browser
```

## 📊 Vergleich

### Ohne Browser (Server-Side)

```bash
php artisan bfsg:analyze https://react-app.test
# Sieht nur: <div id="root"></div>
# ❌ Findet keine React-Component Issues
```

### Mit Browser

```bash
php artisan bfsg:analyze https://react-app.test --browser
# Sieht: Vollständig gerendertes React HTML
# ✅ Findet alle Accessibility Issues
```

## 🎓 Best Practices

1. **Entwicklung**: `--headless=false` zum Debugging
2. **CI/CD**: Immer `--headless=true`
3. **Timeout**: Mindestens 30s für SPAs
4. **Selector**: `waitForSelector` auf App-Mount-Element setzen
5. **Browser**: Chromium für beste Performance

## 📚 Weitere Ressourcen

- [Playwright Docs](https://playwright.dev/)
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [BFSG Requirements](https://www.bmas.de/)

---

**Fragen?** Öffne ein Issue auf GitHub!