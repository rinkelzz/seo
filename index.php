<?php
function fetchHtml(string $url): array {
    $parsed = parse_url(trim($url));
    if (!$parsed || empty($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
        return [false, 'Bitte gib eine gültige URL mit http oder https an.'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SEO-Checker/1.0 (+https://example.com)',
    ]);

    $html = curl_exec($ch);
    if ($html === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [false, 'Fehler beim Abrufen der Seite: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8')];
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode >= 400) {
        return [false, 'Die URL antwortete mit dem Statuscode ' . $statusCode . '.'];
    }

    return [$html, null];
}

function createDom(string $html): ?DOMDocument {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    libxml_clear_errors();
    return $loaded ? $dom : null;
}

function analyzeSeo(DOMDocument $dom): array {
    $xpath = new DOMXPath($dom);

    $results = [];
    $tips = [];


    $addResult = static function (array &$results, string $label, string $value, string $status, string $hint): void {
        $results[] = [
            'label' => $label,
            'value' => $value,
            'status' => $status,
            'hint' => $hint,
        ];
    };

    // Title
    $titleNodes = $dom->getElementsByTagName('title');
    $title = $titleNodes->length ? trim($titleNodes->item(0)->textContent) : '';
    $titleLength = mb_strlen($title);
    if ($titleLength === 0) {

        $addResult($results, 'Titel', 'Fehlt', 'rot', 'Der Title-Tag wird in den Suchergebnissen angezeigt und sollte das Hauptthema klar benennen.');
        $tips[] = 'Füge einen aussagekräftigen Title-Tag hinzu (50-60 Zeichen).';
    } elseif ($titleLength < 30 || $titleLength > 65) {
        $addResult($results, 'Titel', 'Länge ' . $titleLength . ' Zeichen', 'orange', 'Der Title-Tag sollte zwischen 50 und 60 Zeichen liegen, um vollständig angezeigt zu werden.');
        $tips[] = 'Passe die Länge des Title-Tags an (ideal 50-60 Zeichen).';
    } else {
        $addResult($results, 'Titel', 'Optimale Länge (' . $titleLength . ' Zeichen)', 'gruen', 'Gute Länge – prüfe, ob das Hauptkeyword weit vorne steht.');
    }

    // Meta Description
    $descriptionNode = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "description"]/ @content');
    $description = $descriptionNode->length ? trim($descriptionNode->item(0)->textContent) : '';
    $descriptionLength = mb_strlen($description);
    if ($descriptionLength === 0) {
        $addResult($results, 'Meta-Description', 'Fehlt', 'rot', 'Die Meta-Description erscheint als Snippet in Google und sollte neugierig auf den Inhalt machen.');
        $tips[] = 'Erstelle eine Meta-Description (50-160 Zeichen), die die Seite beschreibt.';
    } elseif ($descriptionLength < 50 || $descriptionLength > 160) {
        $addResult($results, 'Meta-Description', 'Länge ' . $descriptionLength . ' Zeichen', 'orange', 'Beschreibung zu kurz oder zu lang – passe sie auf 50 bis 160 Zeichen an.');
        $tips[] = 'Passe die Länge der Meta-Description auf 50-160 Zeichen an.';
    } else {
        $addResult($results, 'Meta-Description', 'Optimale Länge (' . $descriptionLength . ' Zeichen)', 'gruen', 'Passt – achte weiterhin auf eine klare Handlungsaufforderung (CTA).');
    }

    // H1 Überschriften
    $h1Nodes = $dom->getElementsByTagName('h1');
    $h1Count = $h1Nodes->length;
    if ($h1Count === 0) {

        $addResult($results, 'H1-Überschrift', 'Fehlt', 'rot', 'Die H1 ist die wichtigste Überschrift und sollte das Hauptthema der Seite wiedergeben.');
        $tips[] = 'Füge mindestens eine H1-Überschrift hinzu, die das Hauptthema beschreibt.';
    } elseif ($h1Count > 1) {
        $addResult($results, 'H1-Überschriften', $h1Count . ' vorhanden', 'orange', 'Mehrere H1s können Suchmaschinen verwirren – reduziere auf eine Hauptüberschrift.');
        $tips[] = 'Verwende idealerweise nur eine H1-Überschrift pro Seite.';
    } else {
        $addResult($results, 'H1-Überschrift', 'Genau eine vorhanden', 'gruen', 'Sehr gut – nutze passende Keywords und fasse den Inhalt kurz zusammen.');
    }

    // Canonical
    $canonicalNode = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "canonical"]/ @href');
    if ($canonicalNode->length === 0) {

        $addResult($results, 'Canonical', 'Fehlt', 'orange', 'Ein Canonical-Tag hilft dabei, doppelte Inhalte zusammenzuführen.');
        $tips[] = 'Setze einen Canonical-Link, um doppelte Inhalte zu vermeiden.';
    } else {
        $addResult($results, 'Canonical', 'Gefunden', 'gruen', 'Prima – der Canonical zeigt Suchmaschinen die Hauptversion der Seite.');
    }

    // Bilder ohne Alt
    $imageNodes = $dom->getElementsByTagName('img');
    $missingAlt = 0;
    foreach ($imageNodes as $image) {
        $alt = $image->getAttribute('alt');
        if (trim($alt) === '') {
            $missingAlt++;
        }
    }
    if ($imageNodes->length > 0) {
        if ($missingAlt > 0) {

            $addResult($results, 'Bilder', $missingAlt . ' ohne Alt-Text', 'orange', 'Alt-Texte beschreiben Bilder für Screenreader und liefern Kontext für Suchmaschinen.');
            $tips[] = 'Vergib Alt-Texte für alle Bilder zur besseren Barrierefreiheit und SEO.';
        } else {
            $addResult($results, 'Bilder', 'Alle Bilder mit Alt-Text', 'gruen', 'Super – alle Bilder sind für Nutzer:innen mit Screenreader zugänglich.');
        }
    }

    // Wortanzahl
    $bodyNodes = $dom->getElementsByTagName('body');
    if ($bodyNodes->length > 0) {
        $bodyText = preg_replace('/\s+/', ' ', strip_tags($dom->saveHTML($bodyNodes->item(0))));
        $words = array_filter(explode(' ', $bodyText));
        $wordCount = count($words);
        if ($wordCount < 300) {

            $addResult($results, 'Wortanzahl', $wordCount . ' Wörter', 'orange', 'Etwas mehr Text hilft, ein Thema umfassend abzudecken und relevante Keywords einzubauen.');
            $tips[] = 'Erhöhe den Textumfang auf mindestens 300 Wörter mit relevantem Inhalt.';
        } else {
            $addResult($results, 'Wortanzahl', $wordCount . ' Wörter', 'gruen', 'Der Umfang ist solide – achte zusätzlich auf Strukturierung mit Zwischenüberschriften.');
        }
    }

    // Robots Meta
    $robotsNode = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "robots"]/ @content');
    if ($robotsNode->length > 0) {
        $robotsValue = strtolower($robotsNode->item(0)->textContent);
        if (strpos($robotsValue, 'noindex') !== false) {

            $addResult($results, 'Meta-Robots', 'noindex gesetzt', 'rot', 'Mit "noindex" wird die Seite aktiv von der Google-Suche ausgeschlossen.');
            $tips[] = 'Entferne noindex, wenn die Seite indexiert werden soll.';
        } else {
            $addResult($results, 'Meta-Robots', $robotsValue, 'gruen', 'Die Seite darf indexiert werden – überprüfe bei Bedarf weitere Direktiven wie follow/nofollow.');
        }
    } else {
        $addResult($results, 'Meta-Robots', 'Kein Tag vorhanden', 'orange', 'Mit einem Meta-Robots-Tag kannst du das Crawling genauer steuern (z. B. index, follow).');
    }

    return [$results, array_values(array_unique($tips))];
}

$url = $_POST['url'] ?? '';
$email = trim($_POST['email'] ?? '');
$sendMail = isset($_POST['send_mail']);
$html = null;
$error = null;
$analysis = [];
$tips = [];
$mailFeedback = null;

if ($url) {
    [$html, $error] = fetchHtml($url);
    if ($html !== false) {
        $dom = createDom($html);
        if ($dom) {
            [$analysis, $tips] = analyzeSeo($dom);
        } else {
            $error = 'Der HTML-Inhalt konnte nicht verarbeitet werden.';
        }
    }
}


if ($sendMail && !$error) {
    if (!$analysis) {
        $mailFeedback = ['type' => 'error', 'message' => 'Es liegen keine Analyseergebnisse vor, die versendet werden können.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mailFeedback = ['type' => 'error', 'message' => 'Bitte gib eine gültige E-Mail-Adresse an.'];
    } else {
        $subject = 'SEO-Analyse für ' . $url;
        $lines = [];
        foreach ($analysis as $item) {
            $lines[] = sprintf('- %s: %s [%s]', $item['label'], $item['value'], strtoupper($item['status']));
            $lines[] = '  Hinweis: ' . $item['hint'];
        }
        if ($tips) {
            $lines[] = "\nVerbesserungstipps:";
            foreach ($tips as $tip) {
                $lines[] = '- ' . $tip;
            }
        }

        $message = "Hallo,\n\n" .
            "Hier ist die Auswertung deines SEO-Checks für {$url}:\n\n" .
            implode("\n", $lines) . "\n\n" .
            "Viele Grüße\nDein bunter SEO-Checker";

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: SEO Checker <no-reply@example.com>',
        ];

        if (mail($email, $subject, $message, implode("\r\n", $headers))) {
            $mailFeedback = ['type' => 'success', 'message' => 'Die Analyse wurde erfolgreich per E-Mail versendet.'];
        } else {
            $mailFeedback = ['type' => 'error', 'message' => 'Die E-Mail konnte nicht versendet werden. Bitte versuche es später erneut.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>SEO Checker</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2c3e50;
        }
        .container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 30px 40px;
            max-width: 900px;
            width: 100%;
        }
        h1 {
            text-align: center;
            color: #e74c3c;
            margin-bottom: 20px;
        }
        form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        input[type="url"] {
            flex: 1 1 250px;
            padding: 12px 16px;
            border: 2px solid #e67e22;
            border-radius: 12px;
            font-size: 16px;
        }
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            background: #27ae60;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(39, 174, 96, 0.4);
        }
        .error {
            background: #ff6b6b;
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .feedback {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            color: white;
        }
        .feedback.success {
            background: #2ecc71;
        }
        .feedback.error {
            background: #e74c3c;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
        }
        th {
            background: #f1c40f;
            color: #2c3e50;
        }
        tr:nth-child(even) {
            background: rgba(230, 126, 34, 0.1);
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            color: white;
        }
        .badge.gruen {
            background: #2ecc71;
        }
        .badge.orange {
            background: #f39c12;
        }
        .badge.rot {
            background: #e74c3c;
        }
        .tips {
            margin-top: 30px;
            background: rgba(52, 152, 219, 0.15);
            border-radius: 12px;
            padding: 20px;
        }
        .tips h2 {
            margin-top: 0;
            color: #2980b9;
        }
        .tips ul {
            margin: 0;
            padding-left: 20px;
        }

        .tips p {
            margin-top: 0;
            color: #1f4c6b;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }
        .info-card {
            background: rgba(255, 255, 255, 0.7);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
        }
        .info-card h3 {
            margin-top: 0;
            color: #d35400;
        }
        .email-form {
            margin-top: 24px;
            background: rgba(39, 174, 96, 0.12);
            border-radius: 12px;
            padding: 20px;
        }
        .email-form h2 {
            margin-top: 0;
            color: #1e8449;
        }
        .email-form .field-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .email-form input[type="email"] {
            flex: 1 1 260px;
            padding: 10px 14px;
            border-radius: 12px;
            border: 2px solid #1e8449;
            font-size: 16px;
        }
        .note {
            font-size: 14px;
            color: #34495e;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🧠 Bunter SEO-Checker</h1>

    <p class="note">Gib eine URL ein und erhalte verständliche Hinweise dazu, wie Suchmaschinen deine Seite wahrnehmen – inklusive optionaler E-Mail-Zusammenfassung.</p>
    <form method="post">
        <input type="url" name="url" placeholder="https://beispiel.de" value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" required>
        <button type="submit">Analyse starten</button>
    </form>

    <?php if ($error): ?>
        <div class="error"><?= $error; ?></div>
    <?php endif; ?>


    <?php if ($mailFeedback): ?>
        <div class="feedback <?= $mailFeedback['type']; ?>"><?= htmlspecialchars($mailFeedback['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($analysis): ?>
        <div class="info-grid">
            <div class="info-card">
                <h3>Meta-Angaben</h3>
                <p>Titel und Meta-Description prägen das Snippet in der Google-Suche. Starke Keywords und passende Länge sorgen für mehr Klicks.</p>
            </div>
            <div class="info-card">
                <h3>Struktur</h3>
                <p>Überschriften und Wortanzahl zeigen, wie klar und ausführlich das Thema dargestellt wird.</p>
            </div>
            <div class="info-card">
                <h3>Technik &amp; Medien</h3>
                <p>Canonical-Tag, Meta-Robots und Bild-Alternativtexte helfen Suchmaschinen, Inhalte korrekt zu verstehen.</p>
            </div>
        </div>
        <table>
            <thead>
            <tr>
                <th>Element</th>
                <th>Bewertung</th>
                <th>Status</th>

                <th>Hinweis</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($analysis as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge <?= $item['status']; ?>"><?= strtoupper($item['status']); ?></span></td>
                    <td><?= htmlspecialchars($item['hint'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($tips): ?>
        <div class="tips">
            <h2>Verbesserungstipps</h2>

            <p>Starte mit den roten Feldern und arbeite dich dann zu den orange markierten Empfehlungen vor.</p>
            <ul>
                <?php foreach ($tips as $tip): ?>
                    <li><?= htmlspecialchars($tip, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <?php if ($analysis && !$error): ?>
        <form method="post" class="email-form">
            <h2>Ergebnis per E-Mail versenden</h2>
            <p class="note">Praktisch für Kund:innen oder Kolleg:innen – wir schicken die obigen Werte samt Tipps direkt in dein Postfach.</p>
            <div class="field-group">
                <input type="hidden" name="url" value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="email" name="email" placeholder="name@example.com" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                <button type="submit" name="send_mail" value="1">Analyse zusenden</button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
