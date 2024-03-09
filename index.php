<?php

declare(strict_types=1);

require __DIR__ . "/vendor/autoload.php";

use Aws\S3\S3Client;
use Intervention\Image\Constraint;
use Intervention\Image\ImageManagerStatic as Image;

return function ($event) {
    $region = $event["Records"][0]["awsRegion"] ?? null;
    $bucket = $event["Records"][0]["s3"]["bucket"]["name"] ?? null;
    $key = urldecode($event["Records"][0]["s3"]["object"]["key"] ?? null);

    $filters = [
        [
            "name" => "thumbnail",
            "h" => 120,
            "w" => 120,
        ],
        [
            "name" => "medium",
            "h" => 300,
            "w" => 300,
        ],
        [
            "name" => "large",
            "h" => 800,
            "w" => 800,
        ],
    ];

    if (!$region || !$key || !$bucket) {
        error_log("Not enough parameters");
        return;
    }

    $s3client = new S3Client([
        "region" => $region,
        "version" => "latest",
    ]);

    $obj = $s3client->getObject([
        "Bucket" => $bucket,
        "Key" => $key,
    ]);

    if (!$obj) {
        return "Image does not exist";
    }

    $imageContent = $obj["Body"]->getContents();

    $fileExtension = pathinfo($key, PATHINFO_EXTENSION);
    $newPath =
        dirname($key) . "/" . pathinfo($key, PATHINFO_FILENAME) . ".webp";

    foreach ($filters as $filter) {
        $objectKey = "cache/" . $filter["name"] . "/" . $newPath;

        if ($s3client->doesObjectExist($bucket, $objectKey, [])) {
            error_log($objectKey . " Object already exists");
            continue;
        }

        try {
            $image = Image::make($imageContent);
            $image->resize((int) $filter["h"], (int) $filter["w"], function (
                Constraint $constraint
            ) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $image->encode("webp", 100);

            $cachePath =
                "/tmp/cache/" . $filter["name"] . "/" . dirname($newPath);
            if (!file_exists($cachePath)) {
                mkdir($cachePath, 0777, true);
            }

            $fullPath = $cachePath . "/" . basename($newPath);
            error_log($fullPath);
            $image->save($fullPath, 100, "webp");

            $res = $s3client->putObject([
                "Bucket" => $bucket,
                "Key" => $objectKey,
                "Body" => file_get_contents($fullPath),
                "ContentType" => "image/webp",
                "ACL" => "public-read",
            ]);
            $objectUrl = $res["ObjectURL"];
            error_log($objectUrl);
            unset($fullPath);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
        }
    }

    return "Image resized and cached successfully.";
};
