<?php 

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Intervention\Image\ImageManagerStatic as Image;

return function($event) {
    $region = $event['Records'][0]['awsRegion'] ?? null;
    $bucket = $event['Records'][0]['s3']['bucket']['name'] ?? null;
    $key = urldecode($event['Records'][0]['s3']['object']['key'] ?? null);

    $filters = [
        [
            "name" => "thumbnail",
            "h" => 300,
            "w" => 300
        ],
        [
            "name" => "medium",
            "h" => 650,
            "w" => 650
        ],
        [
            "name" => "large",
            "h" => 1200,
            "w" => 1200
        ]
    ];

    if (!$region || !$key || !$bucket) {
        error_log('Not enough parameter');
        return;
    }

    $s3client = new S3Client([
        'region' => $region,
        'version' => 'latest',
    ]);

    $path = $key;
    $obj = $s3client->getObject([
        'Bucket' => $bucket,
        'Key' => $path
    ]);

    if (!$obj) return 'Image does not exist';

    $imageContent = $obj['Body']->getContents();

    $fileExtension = pathinfo($path, PATHINFO_EXTENSION);
    $newPath = str_replace('.' . $fileExtension, '.webp', $path);

    foreach ($filters as $filter) {
        $objectKey = 'cache/'. $filter['name']. "/". basename($newPath);

        if ($s3client->doesObjectExist($bucket, $objectKey, [])) {
            error_log($objectKey . ' Object already exists');
            continue;
        }

        try {
            $image = Image::make($imageContent);
            $image->fit((int) $filter['h'], (int) $filter['w']);
            $image->encode('webp', 75);

            $cachePath = '/tmp/cache/' . $filter['name'] . "/";
            if (!file_exists($cachePath)) {
              mkdir($cachePath, 0777, true);
            }

            $fullPath = $cachePath . basename($newPath);
            error_log($fullPath);
            $image->save($fullPath, 75, 'webp');

            $res = $s3client->putObject([
                'Bucket' => $bucket, 
                'Key' => $objectKey, 
                'Body' => file_get_contents($fullPath),
                'ContentType' => 'image/webp',
                'ACL' => 'public-read'
            ]);
            $objectUrl = $res['ObjectURL'];
            error_log($objectUrl);
            unset($fullPath);
        } catch (Exception $e) {
            error_log('Error: ' . $e->getMessage());
        }
    }

    return 'Image resized and cached successfully.';
};

