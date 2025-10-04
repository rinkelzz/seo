<?php
session_start();

$config = require __DIR__ . '/config.php';
$mailFromAddress = $config['mail_from'] ?? 'no-reply@example.com';

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

function generateCaptcha(): array
{
    $numberOne = random_int(1, 9);
    $numberTwo = random_int(1, 9);

    $_SESSION['captcha'] = [
        'question' => $numberOne . ' + ' . $numberTwo . ' = ?',
        'answer' => $numberOne + $numberTwo,
        'generated_at' => time(),
    ];

    return $_SESSION['captcha'];
}

function getCaptcha(): array
{
    if (!isset($_SESSION['captcha']) || !is_array($_SESSION['captcha'])) {
        return generateCaptcha();
    }

    return $_SESSION['captcha'];
}

function makeExcerpt(string $text, int $limit = 120): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $text));
    if ($normalized === '') {
        return '';
    }

    if (mb_strlen($normalized) <= $limit) {
        return $normalized;
    }

    return rtrim(mb_substr($normalized, 0, $limit - 1)) . '…';
}

function buildMailBody(string $url, array $analysis, array $tips, array $metadata, ?string $primaryRecommendation): string
{
    $lines = [];
    $lines[] = 'SEO-Analyse für: ' . $url;
    $lines[] = str_repeat('=', 40);
    $lines[] = '';
    $lines[] = 'Ergebnisse:';

    foreach ($analysis as [$label, $value, $status]) {
        $lines[] = sprintf('- %s: %s (Status: %s)', $label, $value, strtoupper($status));
    }

    if ($metadata) {
        $lines[] = '';
        $lines[] = 'Gefundene Inhalte:';
        foreach ($metadata as $item) {
            $lines[] = sprintf('• %s: %s', $item['label'], $item['value']);
        }
    }

    if ($tips) {
        $lines[] = '';
        $lines[] = 'Tipps:';
        foreach ($tips as $tip) {
            $lines[] = '- ' . $tip;
        }
    }

    if ($primaryRecommendation) {
        $lines[] = '';
        $lines[] = 'Wichtigster Verbesserungsvorschlag:';
        $lines[] = $primaryRecommendation;
    }

    $lines[] = '';
    $lines[] = 'Viele Grüße';
    $lines[] = 'Dein SEO-Checker';

    return implode("\n", $lines);
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
    $metadata = [];
    $tipPriorities = [];

    $addTip = function (string $message, string $status) use (&$tips, &$tipPriorities): void {
        if (!in_array($message, $tips, true)) {
            $tips[] = $message;
        }

        $weights = ['rot' => 3, 'orange' => 2, 'gruen' => 1];
        $weight = $weights[$status] ?? 1;

        if (!isset($tipPriorities[$message]) || $tipPriorities[$message] < $weight) {
            $tipPriorities[$message] = $weight;
        }
    };

    // Title
    $titleNodes = $dom->getElementsByTagName('title');
    $title = $titleNodes->length ? trim($titleNodes->item(0)->textContent) : '';
    $titleLength = mb_strlen($title);
    $metadata[] = [
        'label' => 'Titel',
        'value' => $title !== '' ? sprintf('%s (%d Zeichen)', $title, $titleLength) : 'Kein Titel gefunden.',
    ];
    if ($titleLength === 0) {
        $results[] = ['Titel', 'Fehlt', 'rot'];
        $addTip('Füge einen aussagekräftigen Title-Tag hinzu (50-60 Zeichen).', 'rot');
    } elseif ($titleLength < 30 || $titleLength > 65) {
        $results[] = ['Titel', sprintf('„%s“ (%d Zeichen)', makeExcerpt($title, 80), $titleLength), 'orange'];
        $addTip('Passe die Länge des Title-Tags an (ideal 50-60 Zeichen).', 'orange');
    } else {
        $results[] = ['Titel', sprintf('„%s“ (%d Zeichen)', makeExcerpt($title, 80), $titleLength), 'gruen'];
    }

    // Meta Description
    $descriptionNode = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "description"]/ @content');
    $description = $descriptionNode->length ? trim($descriptionNode->item(0)->textContent) : '';
    $descriptionLength = mb_strlen($description);
    $metadata[] = [
        'label' => 'Meta-Description',
        'value' => $description !== '' ? sprintf('%s (%d Zeichen)', $description, $descriptionLength) : 'Keine Meta-Description gefunden.',
    ];
    if ($descriptionLength === 0) {
        $results[] = ['Meta-Description', 'Fehlt', 'rot'];
        $addTip('Erstelle eine Meta-Description (50-160 Zeichen), die die Seite beschreibt.', 'rot');
    } elseif ($descriptionLength < 50 || $descriptionLength > 160) {
        $results[] = ['Meta-Description', sprintf('„%s“ (%d Zeichen)', makeExcerpt($description, 110), $descriptionLength), 'orange'];
        $addTip('Passe die Länge der Meta-Description auf 50-160 Zeichen an.', 'orange');
    } else {
        $results[] = ['Meta-Description', sprintf('„%s“ (%d Zeichen)', makeExcerpt($description, 110), $descriptionLength), 'gruen'];
    }

    // H1 Überschriften
    $h1Nodes = $dom->getElementsByTagName('h1');
    $h1Count = $h1Nodes->length;
    $h1Texts = [];
    foreach ($h1Nodes as $node) {
        $text = trim($node->textContent);
        if ($text !== '') {
            $h1Texts[] = $text;
        }
    }
    $metadata[] = [
        'label' => 'H1-Überschriften',
        'value' => $h1Texts ? implode("\n", $h1Texts) : 'Keine H1-Überschriften gefunden.',
    ];
    if ($h1Count === 0) {
        $results[] = ['H1-Überschrift', 'Fehlt', 'rot'];
        $addTip('Füge mindestens eine H1-Überschrift hinzu, die das Hauptthema beschreibt.', 'rot');
    } elseif ($h1Count > 1) {
        $results[] = ['H1-Überschriften', sprintf('%d vorhanden – erste: „%s“', $h1Count, makeExcerpt($h1Texts[0] ?? '', 90)), 'orange'];
        $addTip('Verwende idealerweise nur eine H1-Überschrift pro Seite.', 'orange');
    } else {
        $results[] = ['H1-Überschrift', sprintf('„%s“', makeExcerpt($h1Texts[0] ?? '', 90)), 'gruen'];
    }

    // Canonical
    $canonicalNode = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "canonical"]/ @href');
    $canonical = $canonicalNode->length ? trim($canonicalNode->item(0)->textContent) : '';
    $metadata[] = [
        'label' => 'Canonical',
        'value' => $canonical !== '' ? $canonical : 'Kein Canonical-Link gesetzt.',
    ];
    if ($canonical === '') {
        $results[] = ['Canonical', 'Fehlt', 'orange'];
        $addTip('Setze einen Canonical-Link, um doppelte Inhalte zu vermeiden.', 'orange');
    } else {
        $results[] = ['Canonical', $canonical, 'gruen'];
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
        $metadata[] = [
            'label' => 'Wortanzahl',
            'value' => $wordCount . ' Wörter (inkl. sichtbarem Text)',
        ];
        if ($wordCount < 300) {
            $results[] = ['Wortanzahl', $wordCount . ' Wörter', 'orange'];
            $addTip('Erhöhe den Textumfang auf mindestens 300 Wörter mit relevantem Inhalt.', 'orange');
        } else {
            $results[] = ['Wortanzahl', $wordCount . ' Wörter', 'gruen'];
        }
    }

    // Robots Meta
    $robotsNode = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "robots"]/ @content');
    if ($robotsNode->length > 0) {
        $robotsRaw = trim($robotsNode->item(0)->textContent);
        $robotsValue = strtolower($robotsRaw);
        $metadata[] = [
            'label' => 'Meta-Robots',
            'value' => $robotsRaw,
        ];
        if (strpos($robotsValue, 'noindex') !== false) {
            $results[] = ['Meta-Robots', 'noindex gesetzt', 'rot'];
            $addTip('Entferne noindex, wenn die Seite indexiert werden soll.', 'rot');
        } else {
            $results[] = ['Meta-Robots', $robotsValue, 'gruen'];
        }
    } else {
        $metadata[] = [
            'label' => 'Meta-Robots',
            'value' => 'Kein Meta-Robots-Tag gesetzt.',
        ];
    }

    return [$results, $tips, $metadata, $tipPriorities];
}

function selectPrimaryRecommendation(array $tips, array $tipPriorities): ?string
{
    if (!$tips) {
        return null;
    }

    $bestTip = null;
    $bestWeight = -1;

    foreach ($tips as $tip) {
        $weight = $tipPriorities[$tip] ?? 0;
        if ($weight > $bestWeight) {
            $bestWeight = $weight;
            $bestTip = $tip;
        }
    }

    return $bestTip;
}

$analysis = [];
$tips = [];
$metadataDetails = [];
$errors = [];
$mailStatus = null;
$url = '';
$email = '';
$captchaQuestion = '';
$primaryRecommendation = null;

$captchaData = getCaptcha();
$captchaQuestion = $captchaData['question'];
$correctCaptchaAnswer = $captchaData['answer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $captchaAnswer = trim($_POST['captcha_answer'] ?? '');

    if ($url === '') {
        $errors[] = 'Bitte gib eine URL an.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte gib eine gültige E-Mail-Adresse an.';
    }

    if ($email !== '') {
        if ($captchaAnswer === '') {
            $errors[] = 'Bitte beantworte das Captcha, um Spam zu verhindern.';
        } elseif ((int)$captchaAnswer !== (int)$correctCaptchaAnswer) {
            $errors[] = 'Die Captcha-Antwort ist leider falsch.';
        }
    }

    if (!$errors) {
        [$html, $fetchError] = fetchHtml($url);
        if ($html === false) {
            $errors[] = $fetchError;
        } else {
            $dom = createDom($html);
            if ($dom) {
                [$analysis, $tips, $metadataDetails, $tipPriorities] = analyzeSeo($dom);
                $primaryRecommendation = selectPrimaryRecommendation($tips, $tipPriorities);

                if ($email !== '') {
                    $body = buildMailBody($url, $analysis, $tips, $metadataDetails, $primaryRecommendation);
                    $subject = 'SEO-Analyse für ' . $url;
                    $headers = sprintf(
                        "From: %s\r\nReply-To: %s\r\nContent-Type: text/plain; charset=UTF-8",
                        $mailFromAddress,
                        $mailFromAddress
                    );

                    $mailSent = mail($email, $subject, $body, $headers);
                    $mailStatus = $mailSent
                        ? 'Die Analyse wurde erfolgreich per E-Mail verschickt.'
                        : 'Die E-Mail konnte nicht versendet werden. Bitte versuche es später erneut.';
                }
            } else {
                $errors[] = 'Der HTML-Inhalt konnte nicht verarbeitet werden.';
            }
        }
    }

    $captchaData = generateCaptcha();
    $captchaQuestion = $captchaData['question'];
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
        input[type="url"],
        input[type="email"],
        input[type="number"] {
            flex: 1 1 250px;
            padding: 12px 16px;
            border: 2px solid #e67e22;
            border-radius: 12px;
            font-size: 16px;
        }
        input[type="number"] {
            flex: 1 1 120px;
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
        .success {
            background: #2ecc71;
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .recommendation {
            background: rgba(39, 174, 96, 0.15);
            border: 2px dashed #27ae60;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .recommendation h2 {
            margin-top: 0;
            color: #1e8449;
        }
        .captcha-row {
            flex: 1 1 100%;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .captcha-row label {
            font-weight: 600;
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
        .details {
            margin-top: 30px;
            background: rgba(155, 89, 182, 0.12);
            border-radius: 12px;
            padding: 20px;
        }
        .details h2 {
            margin-top: 0;
            color: #8e44ad;
        }
        .details dl {
            margin: 0;
            display: grid;
            gap: 10px;
        }
        .details dt {
            font-weight: 700;
        }
        .details dd {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
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
        <input type="email" name="email" placeholder="E-Mail für Ergebnisse (optional)" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="captcha-row">
            <label for="captcha_answer">Captcha: <?= htmlspecialchars($captchaQuestion, ENT_QUOTES, 'UTF-8'); ?></label>
            <input id="captcha_answer" type="number" name="captcha_answer" min="0" placeholder="Antwort">
        </div>
        <button type="submit">Analyse starten</button>
    </form>

    <?php if ($primaryRecommendation): ?>
        <div class="recommendation">
            <h2>Wichtigster Verbesserungsvorschlag</h2>
            <p><?= htmlspecialchars($primaryRecommendation, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="error">
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($errors as $errorMessage): ?>
                    <li><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($mailStatus): ?>
        <div class="success"><?= htmlspecialchars($mailStatus, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($metadataDetails): ?>
        <div class="details">
            <h2>Gefundene Inhalte</h2>
            <dl>
                <?php foreach ($metadataDetails as $item): ?>
                    <dt><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></dt>
                    <dd><?= nl2br(htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8')); ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
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
