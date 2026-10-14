<?php
// Vorlage. Als config.php neben diese Datei kopieren und ausfuellen.
// config.php gehoert NICHT ins Repository.
//
// Zufaellige Schluessel/Tokens erzeugen, z. B. per SSH:
//   php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'

return [
    // Ablage der Bilder und Videos, ausserhalb des Web-Ordners.
    'daten_pfad' => '/data/web/e71813/zeitraffer-daten',

    // Schluessel fuer den Abholer (muss in dessen config.ini gleich sein).
    'api_schluessel' => 'HIER-LANGEN-ZUFAELLIGEN-SCHLUESSEL-EINTRAGEN',

    'zeitzone' => 'Europe/Vienna',

    // Tagzeit (fuer den Schalter "Nachtbilder" in der Galerie), gleich wie im Abholer.
    'tag_von' => 6,
    'tag_bis' => 20,

    // Fusszeile in der Galerie (leer = keine).
    'fusszeile' => 'e.viSio elektrovisionen gmbh',

    'kameras' => [
        // Schluessel = Kurzname, gleich wie [kamera:...] im Abholer.
        'esther-reier-weg-8' => [
            'titel' => 'Baustelle Esther-Reier-Weg 8',

            // Freigabelinks: Token => Bezeichnung (nur fuer dich zur Uebersicht).
            // Link: https://DEINE-DOMAIN/zeitraffer/?k=TOKEN
            // Einen Token entfernen sperrt diesen Link sofort.
            'freigaben' => [
                'HIER-TOKEN-FUER-BAUHERR' => 'Bauherr',
                'HIER-TOKEN-FUER-HOMEPAGE' => 'Homepage',
            ],

            // Wo darf die Galerie per <iframe> eingebettet werden?
            // Leer = ueberall. Sonst Liste von Ursprüngen, z. B. ['https://www.evisio.at'].
            'einbetten_erlaubt' => [],
        ],
    ],
];
