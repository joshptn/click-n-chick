<?php

namespace App\Utils; 

use Illuminate\Support\Facades\Log;


class Image
{
    public static function uploadImage($file, $folder)
    {
        if (!$file->isValid()) {
            return response()->json(['message' => 'Invalid file upload.'], 400);
        }

        try {
                    // Create Cloudinary instance with SSL disabled for Windows
            $client = new \GuzzleHttp\Client(['verify' => false]);
                    
            $cloudinary = new \Cloudinary\Cloudinary([
                'cloud' => [
                    'cloud_name' => config('filesystems.disks.cloudinary.cloud'),
                    'api_key' => config('filesystems.disks.cloudinary.key'),
                    'api_secret' => config('filesystems.disks.cloudinary.secret'),
                ],
                'url' => ['secure' => true],
            ]);
                    
            $cloudinary->configuration->cloud->api_http_client = $client;
                    
            $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                'folder' => $folder,
            ]);

            Log::info('Cloudinary upload successful proof_of_payment', [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id']
            ]);

            return $result['secure_url'];
                    
            
        } catch (\Throwable $e) {
            Log::error('Cloudinary upload failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to upload image.',
                'error' => $e->getMessage(),
            ], 500);
        }       
    }

    public static function deleteImage($image_url,$type){
        $client = new \GuzzleHttp\Client(['verify' => false]);
                    
        $cloudinary = new \Cloudinary\Cloudinary([
            'cloud' => [
                'cloud_name' => config('filesystems.disks.cloudinary.cloud'),
                'api_key' => config('filesystems.disks.cloudinary.key'),
                'api_secret' => config('filesystems.disks.cloudinary.secret'),
            ],
            'url' => ['secure' => true],
        ]);
                    
        $cloudinary->configuration->cloud->api_http_client = $client;

                    // Delete old image if exists
        if ($image_url && str_contains($image_url, 'res.cloudinary.com')) {
            try {
                $urlPath = parse_url($image_url, PHP_URL_PATH);
                $pathParts = explode('/', $urlPath);
                $publicIdWithExt = end($pathParts);
                $publicId = pathinfo($publicIdWithExt, PATHINFO_FILENAME);
                            
                            // Find the folder (usually "foods")
                $folderIndex = array_search($type, $pathParts);
                if ($folderIndex !== false) {
                    $folder = $pathParts[$folderIndex];
                    $cloudinary->uploadApi()->destroy($folder . '/' . $publicId);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to delete old Cloudinary image: ' . $e->getMessage());
            }
        }
    }
}