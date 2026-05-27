<?php
/**
 * manifest.php – Dynamisches PWA-Manifest
 *
 * Liefert das Web-App-Manifest als JSON aus, wobei Name und Beschreibung
 * aus den APP_* Konstanten (config/bootstrap.php) stammen.
 */
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$manifest = [
    'name'             => APP_EVENT_NAME . ' – Dein Messe-Begleiter',
    'short_name'       => APP_NAME,
    'version'          => APP_VERSION,
    'description'      => 'Workshops, Q&A & Programm – ' . APP_EVENT_NAME,
    'start_url'        => '/home.html',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#372F2C',
    'theme_color'      => '#372F2C',
    'lang'             => 'de',
    'categories'       => ['events', 'lifestyle'],
    'icons'            => [
        [
            'src'     => '/img/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => '/img/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name'       => 'Programm',
            'short_name' => 'Programm',
            'url'        => '/programm.html',
            'icons'      => [['src' => '/img/icon-192.png', 'sizes' => '192x192']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
