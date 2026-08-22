<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[OA\Tag(name: 'Media', description: 'Public file serving for uploaded images/documents (profile photos, gallery images, etc.)')]
class MediaController extends Controller
{
    /**
     * Display a publicly uploaded file with CORS headers.
     */
    #[OA\Get(
        path: '/api/media/{path}',
        tags: ['Media'],
        summary: 'Serve a publicly stored file (public, no auth required)',
        parameters: [
            new OA\Parameter(name: 'path', in: 'path', required: true, description: 'Storage path of the file, e.g. worker/gallery/abc123.jpg', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File contents'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function show(string $path): BinaryFileResponse|Response
    {
        $cleanPath = ltrim($path, '/');

        if (
            $cleanPath === ''
            || str_contains($cleanPath, '..')
            || !Storage::disk('public')->exists($cleanPath)
        ) {
            return response([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        $fullPath = Storage::disk('public')->path($cleanPath);

        return response()->file(
            $fullPath,
            [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => '*',
                'Cache-Control' => 'public, max-age=86400',
            ]
        );
    }
}