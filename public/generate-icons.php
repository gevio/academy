<?php
/**
 * generate-icons.php – PWA-Icons aus dem Southside-Logo generieren
 * Erstellt icon-192.png und icon-512.png mit braunem Hintergrund (#372F2C)
 */

$logoPath = __DIR__ . '/img/logo-southside.png';
$outDir   = __DIR__ . '/img';
$bgColor  = [0x37, 0x2F, 0x2C]; // --as-braun-dark

$sizes = [192, 512];

$logo = imagecreatefrompng($logoPath);
if (!$logo) die("Logo konnte nicht geladen werden.\n");

// Logo hat Transparenz → wir brauchen Alpha-Blending
imagealphablending($logo, true);
$logoW = imagesx($logo);
$logoH = imagesy($logo);

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);

    // Hintergrund füllen
    $bg = imagecolorallocate($img, $bgColor[0], $bgColor[1], $bgColor[2]);
    imagefill($img, 0, 0, $bg);

    // Logo weiß einfärben: Wir laden das Logo neu, machen es weiß
    // Strategie: Logo mit Padding (20%) zentriert auf Hintergrund,
    // aber erst die Alpha-Maske nutzen um weiß zu zeichnen
    $padding = (int)($size * 0.15);
    $targetSize = $size - (2 * $padding);

    // Temporäres Logo skaliert
    $scaled = imagecreatetruecolor($targetSize, $targetSize);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefill($scaled, 0, 0, $transparent);
    imagealphablending($scaled, true);
    imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $targetSize, $targetSize, $logoW, $logoH);

    // Pixel für Pixel: Original-Alpha übernehmen, Farbe auf Weiß setzen
    $white = imagecreatetruecolor($targetSize, $targetSize);
    imagealphablending($white, false);
    imagesavealpha($white, true);
    $transparentW = imagecolorallocatealpha($white, 0, 0, 0, 127);
    imagefill($white, 0, 0, $transparentW);

    for ($x = 0; $x < $targetSize; $x++) {
        for ($y = 0; $y < $targetSize; $y++) {
            $rgba = imagecolorat($scaled, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha < 127) { // nicht komplett transparent
                $whitePixel = imagecolorallocatealpha($white, 255, 255, 255, $alpha);
                imagesetpixel($white, $x, $y, $whitePixel);
            }
        }
    }

    // Weißes Logo auf braunen Hintergrund compositen
    imagealphablending($img, true);
    imagecopy($img, $white, $padding, $padding, 0, 0, $targetSize, $targetSize);

    $outPath = "$outDir/icon-$size.png";
    imagepng($img, $outPath, 9);
    echo "✓ icon-$size.png ({$size}x{$size})\n";

    imagedestroy($scaled);
    imagedestroy($white);
    imagedestroy($img);
}

imagedestroy($logo);
echo "Fertig!\n";
