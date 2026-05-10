<?php

require_once __DIR__ . '/vendor/autoload.php';

use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Html2Pdf;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function getRequestData(): array
{
    $data = $_REQUEST;
    $rawBody = file_get_contents('php://input');
    if (!$rawBody) {
        return $data;
    }

    $decoded = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return array_merge($data, $decoded);
    }

    return $data;
}

function normalizeDimension($value, int $default): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    $dimension = (int) $value;
    return $dimension > 0 ? $dimension : $default;
}

function createPdfFromHtml(string $html): string
{
    $html2pdf = new Html2Pdf('P', 'A4', 'en');
    $html2pdf->setDefaultFont('courier');
    $html2pdf->writeHTML($html);
    return $html2pdf->Output('', 'S');
}

function convertPdfToImage(string $pdfBinary, string $format, int $width, int $height): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'html2img_');
    if ($tempFile === false) {
        throw new RuntimeException('Unable to allocate a temporary file.');
    }

    $pdfPath = $tempFile . '.pdf';
    rename($tempFile, $pdfPath);
    file_put_contents($pdfPath, $pdfBinary);

    try {
        $imagick = new Imagick();
        $imagick->setResolution(144, 144);
        $imagick->readImage($pdfPath . '[0]');
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $imagick->setImageFormat($format);
        $imagick->thumbnailImage($width, $height, true, true);
        $blob = $imagick->getImagesBlob();
        $imagick->clear();
        $imagick->destroy();
        return $blob;
    } finally {
        if (file_exists($pdfPath)) {
            unlink($pdfPath);
        }
    }
}

$request = getRequestData();
$requestTimestamp = time();

$mediaTypes = array(
    'application/pdf' => array('ext' => 'pdf', 'format' => 'pdf'),
    'image/gif' => array('ext' => 'gif', 'format' => 'gif'),
    'image/jpeg' => array('ext' => 'jpg', 'format' => 'jpeg'),
    'image/pjpeg' => array('ext' => 'jpeg', 'format' => 'jpeg'),
    'image/png' => array('ext' => 'png', 'format' => 'png'),
    'image/tiff' => array('ext' => 'tiff', 'format' => 'tiff'),
);

if (empty($request['api'])) {
    respondJson(200, array(
        'action' => 'ready',
        'message' => 'Send api=1, html, and imgType.',
        'requestTimestamp' => $requestTimestamp,
        'supportedTypes' => array_keys($mediaTypes),
        'imagickAvailable' => extension_loaded('imagick'),
    ));
}

if (empty($request['html']) || empty($request['imgType'])) {
    respondJson(400, array(
        'action' => 'fail',
        'error' => 'Both html and imgType are required.',
        'requestTimestamp' => $requestTimestamp,
    ));
}

$imgType = strtolower((string) $request['imgType']);
if (!isset($mediaTypes[$imgType])) {
    respondJson(415, array(
        'action' => 'fail',
        'error' => 'Unsupported imgType.',
        'requestTimestamp' => $requestTimestamp,
        'supportedTypes' => array_keys($mediaTypes),
    ));
}

$html = (string) $request['html'];
$width = normalizeDimension($request['w'] ?? null, 1200);
$height = normalizeDimension($request['h'] ?? null, 1200);
$namePrefix = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) ($request['imgNamePref'] ?? time()));
$namePrefix = trim($namePrefix, '-');
if ($namePrefix === '') {
    $namePrefix = (string) time();
}

try {
    $pdfBinary = createPdfFromHtml($html);
    $fileName = $namePrefix . '.' . $mediaTypes[$imgType]['ext'];

    if ($imgType === 'application/pdf') {
        respondJson(200, array(
            'action' => 'success',
            'statusCode' => 200,
            'contentType' => 'application/pdf',
            'fileName' => $fileName,
            'base64' => base64_encode($pdfBinary),
            'dataUri' => 'data:application/pdf;base64,' . base64_encode($pdfBinary),
            'requestTimestamp' => $requestTimestamp,
        ));
    }

    if (!extension_loaded('imagick')) {
        respondJson(501, array(
            'action' => 'fail',
            'statusCode' => 501,
            'error' => 'Imagick is not available in this PHP runtime, so raster image conversion cannot run on this deployment.',
            'requestTimestamp' => $requestTimestamp,
            'fallback' => array(
                'contentType' => 'application/pdf',
                'fileName' => $namePrefix . '.pdf',
                'base64' => base64_encode($pdfBinary),
            ),
        ));
    }

    $imageBinary = convertPdfToImage($pdfBinary, $mediaTypes[$imgType]['format'], $width, $height);
    respondJson(200, array(
        'action' => 'success',
        'statusCode' => 200,
        'contentType' => $imgType,
        'fileName' => $fileName,
        'width' => $width,
        'height' => $height,
        'base64' => base64_encode($imageBinary),
        'dataUri' => 'data:' . $imgType . ';base64,' . base64_encode($imageBinary),
        'requestTimestamp' => $requestTimestamp,
    ));
} catch (Html2PdfException $exception) {
    respondJson(500, array(
        'action' => 'fail',
        'error' => $exception->getMessage(),
        'requestTimestamp' => $requestTimestamp,
    ));
} catch (Throwable $throwable) {
    respondJson(500, array(
        'action' => 'fail',
        'error' => $throwable->getMessage(),
        'requestTimestamp' => $requestTimestamp,
    ));
}
