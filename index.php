<?php
error_reporting(E_ALL);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $urls = preg_split('/\r\n|\r|\n/', trim($_POST['links']));
    $urls = array_filter($urls);

    if (!count($urls)) {
        die("No URLs supplied.");
    }

$zipFile = tempnam(sys_get_temp_dir(), 'images_');
unlink($zipFile);

$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Cannot create ZIP file.");
}

    $mh = curl_multi_init();
    $handles = [];

    foreach ($urls as $i => $url) {

$params = [];

$query = parse_url($url, PHP_URL_QUERY);

if ($query !== null) {
    parse_str($query, $params);
}

        if (!empty($params['filename'])) {
            $filename = basename($params['filename']);
        } else {
            $filename = "image_" . ($i + 1) . ".jpg";
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
        ]);

        curl_multi_add_handle($mh, $ch);

        $handles[] = [
            'handle' => $ch,
            'filename' => $filename
        ];
    }

    $running = null;

    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running);

    foreach ($handles as $item) {

	$image = curl_multi_getcontent($item['handle']);

	if (!$image) {
	    continue;
	}

	$src = @imagecreatefromstring($image);

	if (!$src) {
	    continue;
	}

	$srcW = imagesx($src);
	$srcH = imagesy($src);

	// Canvas size
	$canvasSize = 1500;

	// Keep aspect ratio
	$scale = min($canvasSize / $srcW, $canvasSize / $srcH);

	$newW = (int)($srcW * $scale);
	$newH = (int)($srcH * $scale);

	// Create white canvas
	$canvas = imagecreatetruecolor($canvasSize, $canvasSize);

	$white = imagecolorallocate($canvas, 255, 255, 255);
	imagefill($canvas, 0, 0, $white);

	// Center image
	$dstX = (int)(($canvasSize - $newW) / 2);
	$dstY = (int)(($canvasSize - $newH) / 2);

	// High quality resize
	imagecopyresampled(
	    $canvas,
	    $src,
	    $dstX,
	    $dstY,
	    0,
	    0,
	    $newW,
	    $newH,
	    $srcW,
	    $srcH
	);

	// Convert to JPG
	ob_start();
	imagejpeg($canvas, null, 95);
	$resizedImage = ob_get_clean();

	// Save to ZIP
	$zip->addFromString($item['filename'], $resizedImage);

	// Cleanup
	imagedestroy($src);
	imagedestroy($canvas);

        curl_multi_remove_handle($mh, $item['handle']);
        curl_close($item['handle']);
    }

    curl_multi_close($mh);

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=images.zip');
    header('Content-Length: '.filesize($zipFile));

    readfile($zipFile);
    unlink($zipFile);
    exit;
}
?>

<!doctype html>

<html>

<head>
<meta charset="utf-8">
<title>Bulk Image Downloader</title>

<style>

body{
    font-family:Arial;
    width:900px;
    margin:40px auto;
}

textarea{
    width:100%;
    height:400px;
    font-size:14px;
}

button{
    margin-top:20px;
    padding:15px 40px;
    font-size:18px;
    cursor:pointer;
}

</style>

</head>

<body>

<h2>Bulk Image Downloader</h2>

<form method="post">

<textarea id="links" name="links" placeholder="Paste one image URL per line"></textarea>

<br>

<button>Download ZIP</button>

</form>

<script>
const textarea = document.getElementById('links');

textarea.addEventListener('paste', function (e) {
    e.preventDefault();

    let text = (e.clipboardData || window.clipboardData).getData('text');

    // Replace any whitespace (spaces, tabs) with new lines
text = text
    .replace(/[,\s]+/g, '\n')
    .replace(/\n+/g, '\n')
    .trim();

    // Insert at cursor position
    const start = this.selectionStart;
    const end = this.selectionEnd;

    this.value =
        this.value.substring(0, start) +
        text +
        this.value.substring(end);

    this.selectionStart = this.selectionEnd = start + text.length;
});
</script>
</body>

</html>
