<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    use ApiResponse;

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240', // max 10MB
            'type' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $fileName = sprintf('%s_%s.%s', date('Ymd_His'), Str::random(10), $extension);
        $folder = 'attachments/' . date('Y/m');

        $path = $file->storeAs($folder, $fileName, 'public');

        return $this->successResponse([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'attachment_type' => $request->type ?: 'photo_handover',
            'url' => Storage::disk('public')->url($path),
        ], 'File berhasil diunggah.');
    }
}
