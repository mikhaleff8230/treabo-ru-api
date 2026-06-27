<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf', 'max:20480'],
            'folder' => ['nullable', 'string', 'max:64'],
        ]);

        $disk = $this->uploadDisk();
        $directory = $this->uploadDirectory($request);
        $file = $request->file('file');
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $filename = (string) Str::uuid() . '.' . strtolower($extension);
        $path = $file->storeAs($directory, $filename, [
            'disk' => $disk,
            'visibility' => 'public',
        ]);

        if (!$path) {
            return response()->json(['message' => 'Не удалось загрузить файл'], 500);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => $this->publicUrl($disk, $path),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    public function show(string $path)
    {
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        $disk = $this->uploadDisk();

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        if ($disk !== 'public') {
            return redirect()->away($this->publicUrl($disk, $path));
        }

        return response()->file(Storage::disk('public')->path($path));
    }

    private function uploadDisk(): string
    {
        $disk = (string) config('filesystems.proffi_upload_disk', 'public');

        return config('filesystems.disks.' . $disk)
            ? $disk
            : 'public';
    }

    private function uploadDirectory(Request $request): string
    {
        $prefix = trim((string) config('filesystems.proffi_upload_prefix', 'proffi'), '/');
        $folder = Str::slug((string) $request->input('folder', 'tasks')) ?: 'tasks';

        return trim($prefix . '/' . $folder . '/' . now()->format('Y/m'), '/');
    }

    private function publicUrl(string $disk, string $path): string
    {
        if ($disk === 'public') {
            return url('/api/files/' . ltrim($path, '/'));
        }

        return Storage::disk($disk)->url($path);
    }
}
