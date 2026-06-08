<?php
/**
 * optimize_images.php
 * Verkleinert alle Bilder in den Ordnern 'leere_wohnung' und 'ki_bilder' vor Ort.
 * Sorgt dafür, dass kein Bild größer als 250 KB ist.
 * Schwere PNGs werden zur maximalen Kompression in hocheffiziente .webp-Bilder konvertiert.
 */

// Fehleranzeige aktivieren
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Zeitlimit deaktivieren und Speicher erhöhen
set_time_limit(0); 
ini_set('memory_limit', '512M');

// Konfiguration
$foldersToScan = ['leere_wohnung', 'ki_bilder']; 
$maxWidth = 1000;                               // Maximale Breite in Pixeln
$maxFileSize = 250 * 1024;                      // 250 KB in Bytes
$defaultQuality = 75;                           // Start-Qualität (0-100)

echo "--- START ---<br>";
echo "Bild-Optimierung gestartet am: " . date('d.m.Y H:i:s') . "<br><pre>";

foreach ($foldersToScan as $folder) {
    $uploadDir = __DIR__ . '/' . $folder;
    
    if (!is_dir($uploadDir)) {
        echo "Ordner nicht gefunden: {$folder}/ (Übersprungen)\n\n";
        continue;
    }

    echo "Prüfe Ordner: {$folder}/\n";
    $images = glob($uploadDir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE | GLOB_NOSORT);

    if (empty($images)) {
        echo "  - Keine Bilder in diesem Ordner gefunden.\n\n";
        continue;
    }

    foreach ($images as $imagePath) {
        optimizeImage($imagePath, $maxWidth, $defaultQuality, $maxFileSize);
    }
    echo "\n";
}

/**
 * Funktion zur Bildverkleinerung und Dateigrößen-Kompression
 */
function optimizeImage($filePath, $maxWidth, $startQuality, $maxFileSize) {
    if (!file_exists($filePath)) return;

    $imageInfo = @getimagesize($filePath);
    if (!$imageInfo) {
        echo "  - FEHLER: Datei konnte nicht gelesen werden: " . basename($filePath) . "\n";
        return;
    }
    
    list($width, $height, $type) = $imageInfo;
    $currentFileSize = filesize($filePath);

    // Falls das Bild schon passt -> Überspringen
    if ($width <= $maxWidth && $currentFileSize <= $maxFileSize) {
        echo "  - Übersprungen: " . basename($filePath) . " (" . round($currentFileSize / 1024) . " KB & {$width}px ist okay)\n";
        return;
    }

    // Neue Dimensionen berechnen
    if ($width > $maxWidth) {
        $ratio = $height / $width;
        $newWidth = $maxWidth;
        $newHeight = round($maxWidth * $ratio);
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    // Bildquelle laden
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($filePath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($filePath);  break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($filePath); break;
        default: return; 
    }

    if (!$src) {
        echo "  - FEHLER: Bild konnte nicht geladen werden: " . basename($filePath) . "\n";
        return;
    }

    // Neues leeres Bild erstellen
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    
    // Transparenzkanäle vorbereiten (wichtig für WebP/PNG)
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
    imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);

    // Bild verkleinern
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $quality = $startQuality;
    $isConverted = false;
    $finalPath = $filePath;
    $oldPngPathToDelete = null;

    // Falls es ein schweres PNG ist, stellen wir das Ziel direkt auf .webp um
    if ($type == IMAGETYPE_PNG && $currentFileSize > $maxFileSize) {
        $oldPngPathToDelete = $filePath;
        $pathInfo = pathinfo($filePath);
        $finalPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
        $type = IMAGETYPE_WEBP; // Wechsle zu WebP-Speicher-Modus
        $isConverted = true;
    }

    // Kompressions-Schleife (Qualität reduzieren, bis das Bild unter 250KB ist)
    do {
        switch ($type) {
            case IMAGETYPE_JPEG: 
                imagejpeg($dst, $finalPath, $quality); 
                break;
            case IMAGETYPE_WEBP: 
                imagewebp($dst, $finalPath, $quality); 
                break;
            case IMAGETYPE_PNG:  
                // Kompressionsrate für verbleibende Standard-PNGs
                imagepng($dst, $finalPath, 6); 
                break;
        }

        clearstatcache();
        
        // Prüfen, ob Datei existiert und wie groß sie ist
        if (file_exists($finalPath)) {
            $compressedSize = filesize($finalPath);
        } else {
            $compressedSize = 0;
            break; // Schleife abbrechen, falls das Speichern fehlschlägt
        }

        if ($compressedSize > $maxFileSize) {
            $quality -= 10; // In 10er Schritten komprimieren
            if ($quality < 20) break; // Qualitätsgrenze einhalten
        }

    } while ($compressedSize > $maxFileSize);

    // Erst löschen, wenn die neue WebP-Datei nachweislich geschrieben wurde und existiert
    if ($isConverted && $oldPngPathToDelete && file_exists($finalPath) && filesize($finalPath) > 0) {
        unlink($oldPngPathToDelete);
    }

    if (file_exists($finalPath) && filesize($finalPath) > 0) {
        $infoZusatz = $isConverted ? " -> In .webp umgewandelt!" : "";
        echo "  - OK: " . basename($finalPath) . " (" . round($currentFileSize / 1024) . " KB -> " . round(filesize($finalPath) / 1024) . " KB) [{$width}px -> {$newWidth}px]{$infoZusatz}\n";
    } else {
        echo "  - FEHLER: Datei konnte nicht gespeichert werden: " . basename($filePath) . "\n";
    }
}

echo "</pre>";
echo "Beendet am: " . date('d.m.Y H:i:s') . "<br>";
echo "--- ENDE ---";
?>