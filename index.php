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

    // Title
    $titleNodes = $dom->getElementsByTagName('title');
    $title = $titleNodes->length ? trim($titleNodes->item(0)->textContent) : '';
    $titleLength = mb_strlen($title);
    if ($titleLength === 0) {
        $results[] = ['Titel', 'Fehlt', 'rot'];
        $tips[] = 'Füge einen aussagekräftigen Title-Tag hinzu (50-60 Zeichen).';
    } elseif ($titleLength < 30 || $titleLength > 65) {
        $results[] = ['Titel', 'Länge ' . $titleLength . ' Zeichen', 'orange'];
        $tips[] = 'Passe die Länge des Title-Tags an (ideal 50-60 Zeichen).';
    } else {
        $results[] = ['Titel', 'Optimale Länge (' . $titleLength . ' Zeichen)', 'gruen'];
    }

    // Meta Description
    $descriptionNode = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "description"]/ @content');
    $description = $descriptionNode->length ? trim($descriptionNode->item(0)->textContent) : '';
    $descriptionLength = mb_strlen($description);
    if ($descriptionLength === 0) {
        $results[] = ['Meta-Description', 'Fehlt', 'rot'];
        $tips[] = 'Erstelle eine Meta-Description (50-160 Zeichen), die die Seite beschreibt.';
    } elseif ($descriptionLength < 50 || $descriptionLength > 160) {
        $results[] = ['Meta-Description', 'Länge ' . $descriptionLength . ' Zeichen', 'orange'];
        $tips[] = 'Passe die Länge der Meta-Description auf 50-160 Zeichen an.';
    } else {
        $results[] = ['Meta-Description', 'Optimale Länge (' . $descriptionLength . ' Zeichen)', 'gruen'];
    }

    // H1 Überschriften
    $h1Nodes = $dom->getElementsByTagName('h1');
    $h1Count = $h1Nodes->length;
    if ($h1Count === 0) {
        $results[] = ['H1-Überschrift', 'Fehlt', 'rot'];
        $tips[] = 'Füge mindestens eine H1-Überschrift hinzu, die das Hauptthema beschreibt.';
    } elseif ($h1Count > 1) {
        $results[] = ['H1-Überschriften', $h1Count . ' vorhanden', 'orange'];
        $tips[] = 'Verwende idealerweise nur eine H1-Überschrift pro Seite.';
    } else {
        $results[] = ['H1-Überschrift', 'Genau eine vorhanden', 'gruen'];
    }

    // Canonical
    $canonicalNode = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "canonical"]/ @href');
    if ($canonicalNode->length === 0) {
        $results[] = ['Canonical', 'Fehlt', 'orange'];
        $tips[] = 'Setze einen Canonical-Link, um doppelte Inhalte zu vermeiden.';
    } else {
        $results[] = ['Canonical', 'Gefunden', 'gruen'];
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
            $results[] = ['Bilder', $missingAlt . ' ohne Alt-Text', 'orange'];
            $tips[] = 'Vergib Alt-Texte für alle Bilder zur besseren Barrierefreiheit und SEO.';
        } else {
            $results[] = ['Bilder', 'Alle Bilder mit Alt-Text', 'gruen'];
        }
    }

    // Wortanzahl
    $bodyNodes = $dom->getElementsByTagName('body');
    if ($bodyNodes->length > 0) {
        $bodyText = preg_replace('/\s+/', ' ', strip_tags($dom->saveHTML($bodyNodes->item(0))));
        $words = array_filter(explode(' ', $bodyText));
        $wordCount = count($words);
        if ($wordCount < 300) {
            $results[] = ['Wortanzahl', $wordCount . ' Wörter', 'orange'];
            $tips[] = 'Erhöhe den Textumfang auf mindestens 300 Wörter mit relevantem Inhalt.';
        } else {
            $results[] = ['Wortanzahl', $wordCount . ' Wörter', 'gruen'];
        }
    }

    // Robots Meta
    $robotsNode = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "robots"]/ @content');
    if ($robotsNode->length > 0) {
        $robotsValue = strtolower($robotsNode->item(0)->textContent);
        if (strpos($robotsValue, 'noindex') !== false) {
            $results[] = ['Meta-Robots', 'noindex gesetzt', 'rot'];
            $tips[] = 'Entferne noindex, wenn die Seite indexiert werden soll.';
        } else {
            $results[] = ['Meta-Robots', $robotsValue, 'gruen'];
        }
    }

    return [$results, array_unique($tips)];
}

$url = $_POST['url'] ?? '';
$html = null;
$error = null;
$analysis = [];
$tips = [];

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
    </style>
</head>
<body>
<div class="container">
    <h1>🧠 Bunter SEO-Checker</h1>
    <form method="post">
        <input type="url" name="url" placeholder="https://beispiel.de" value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" required>
        <button type="submit">Analyse starten</button>
    </form>

    <?php if ($error): ?>
        <div class="error"><?= $error; ?></div>
    <?php endif; ?>

    <?php if ($analysis): ?>
        <table>
            <thead>
            <tr>
                <th>Element</th>
                <th>Bewertung</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($analysis as [$label, $value, $status]): ?>
                <tr>
                    <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge <?= $status; ?>"><?= strtoupper($status); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($tips): ?>
        <div class="tips">
            <h2>Verbesserungstipps</h2>
            <ul>
                <?php foreach ($tips as $tip): ?>
                    <li><?= htmlspecialchars($tip, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
