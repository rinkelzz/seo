# SEO Checker

Dieses Projekt stellt ein farbenfrohes PHP-Dashboard bereit, um beliebige Webseiten auf wichtige SEO-Aspekte zu untersuchen und konkrete Optimierungsvorschläge zu erhalten.

## Funktionsumfang
- Abruf externer Seiteninhalte mit DOM-Auswertung.
- Aufbereitung gefundener Meta-Daten inklusive Beispielinhalte.
- Priorisierte Handlungsempfehlungen mit Gewichtung nach Dringlichkeit.
- Optionaler Versand der Analyse per E-Mail mit konfigurierbarem Absender.
- Captcha-geschütztes Formular, um automatisierten Spam zu vermeiden.

## Konfiguration
1. Kopiere `config.php` und passe die Variable `$config['mail_from']` an, damit deine Absenderadresse korrekt gesetzt wird.
2. Stelle sicher, dass `mbstring` und `curl` in deiner PHP-Umgebung aktiviert sind.

## Nutzung
1. Starte einen lokalen PHP-Server, z. B. mit `php -S localhost:8000` im Projektverzeichnis.
2. Öffne `http://localhost:8000/index.php` in deinem Browser.
3. Gib die zu analysierende URL ein, löse das Captcha und fordere optional einen E-Mail-Report an.

## Branch-Hinweis
Die neuesten Änderungen wurden in den `main`-Branch übernommen, sodass dort immer die aktuelle Version verfügbar ist.
