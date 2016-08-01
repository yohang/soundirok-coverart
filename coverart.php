<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * This is a simple php script which delivers cover images for Soundirok.
 *
 * It should work out of the box on Volumio and RuneAudio installations.
 * If your setup requires something different you can adjust this script:
 * It will be called with these HTTP GET parameters:
 *
 * type: can be one of
 *     "artist": should return the artist thumb in <directory>
 *     "album": should return the album cover in <directory>
 *     "booklet_num": should return a json encoded array with the key "booklet_num" and the
 *                    number of booklets in <directory> as value
 *     "album_booklet": should return page <no> of the booklet in this directory
 *
 * directory: the directory of the image to get.
 *
 *            This is for artist images the parent directory of the directory where the songs
 *            are stored. example: song is "/mnt/music/artist/album/01.mp3" and MPD music_directory
 *            is "/mnt/music" -> directory submitted by Soundirok is "artist"
 *
 *            For album images and booklets this is the directory where the songs are stored.
 *                example: song is "/mnt/music/artist/album/01.mp3" and MPD music_directory
 *                is "/mnt/music" -> directory submitted by Soundirok is "artist/album"
 *
 * no: only used when <type> is "album_booklet". <no> starts at 1
 *
 * @author Daniel Kabel <soundirok@kvibes.de>
 * @version 1.1
 *
 *
 * $baseDir should be set to the music_directory setting of your mpd.conf
 *
 * $artistImages is an array of file names which you use for artist thumbs
 *     Soundirok will request this file in the parent directory of the song directory
 *     example: song is "/mnt/music/artist/album/01.mp3" -> Soundirok will try to
 *     get the file "/mnt/music/artist/thumb.jpg"
 *
 * $albumImages is an array of file names which you use for album covers
 *     Soundirok will request this file in the directory of the song
 *     example: song is "/mnt/music/artist/album/01.mp3" -> Soundirok will try to
 *     get the file "/mnt/music/artist/album/cover.jpg"
 */

$config       = Yaml::parse(file_get_contents(__DIR__ . '/config.yml'));
$baseDir      = $config['base_dir'];
$artistImages = $config['images']['artist'];
$albumImages  = $config['images']['album'];
$request      = Request::createFromGlobals();

$dir  = $request->query->get('dir', '');
$type = $request->query->get('type', '');

if ($baseDir !== '' && substr($baseDir, -1) !== '/') {
    $baseDir .= '/';
}

if (is_dir('/mnt/MPD/')) {
    $baseDir = '/mnt/MPD/';
} else if (is_dir('/mnt/')) {
    $baseDir = '/mnt/';
} else if ($baseDir === '' || !is_dir($baseDir)) {
    header("HTTP/1.0 404 Not Found");
    return;
}

if ($type == 'artist') {
    $finder = \Symfony\Component\Finder\Finder::create()->in($baseDir . $dir);

    foreach ($artistImages as $fileName) {
        $finder->name($fileName);
    }

    foreach ($finder->depth(0) as $file) {
        outputFile($file->getPathname());
        return;
    }

    echo '<pre>';
    foreach ($albumImages as $fileName) {
        $finder->name($fileName);
    }

    $images = [];
    foreach ($finder->depth(1) as $file) {
        $images[] = $file->getPathname();
    }
    createArtistImage($images, $baseDir . $dir . '/thumb.jpg');
    outputFile($baseDir . $dir . '/thumb.jpg');
    return;

} else if ($type == 'album') {
    $finder = \Symfony\Component\Finder\Finder::create()->in($baseDir . $dir);

    foreach ($albumImages as $fileName) {
        $finder->name($fileName);
    }

    foreach ($finder->getIterator() as $file) {
        outputFile($file->getPathname());
        return;
    }

} else if ($type == 'booklet_num') {

    $filePath  = $baseDir . $_GET['dir'];
    $currentNo = 1;
    if (is_dir($filePath)) {
        while (is_file($filePath . '/booklet/booklet' . str_pad($currentNo, 2, '0', STR_PAD_LEFT) . '.jpg')) {
            $currentNo++;
        }
    }
    $currentNo--;
    header("Content-Type: application/json");
    echo json_encode(array('booklet_num' => $currentNo));
    return;

} else if ($type == 'album_booklet') {

    $no       = isset($_GET['no']) ? $_GET['no'] : 0;
    $no       = str_pad($no, 2, '0', STR_PAD_LEFT);
    $filePath = $baseDir . $_GET['dir'] . '/booklet/booklet' . $no . '.jpg';
    if (is_file($filePath)) {
        outputFile($filePath);
        return;
    }

}

header("HTTP/1.0 404 Not Found");

function outputFile($filePath)
{
    header('Expires: ' . gmdate("D, d M Y H:i:s", time() + 3600 * 24 * 30) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
    header('Cache-Control: max-age=' . (3600 * 24 * 30) . ', public');
    header("Content-Type: image/jpeg");
    header("Content-Length: " . filesize($filePath));

    echo file_get_contents($filePath);
}

function createArtistImage($images, $output) {
    $imagine = new \Imagine\Gd\Imagine();
    $image = $imagine->create(new \Imagine\Image\Box(800, 800));

    for ($i = 0; $i < 4 && $i < count($images); $i++) {
        $image->paste(
            $imagine->open($images[$i])->resize(new \Imagine\Image\Box(400, 400)),
            new \Imagine\Image\Point(($i % 2) * 400, intval($i / 2) * 400)
        );
    }

    $image->save($output, ['format' => 'jpg']);
}
