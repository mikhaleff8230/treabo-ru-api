<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $path = $request->file('file')->store('proffi', 'public');

        return ['path' => $path];
    }

    public function show(string $path)
    {
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($path));
    }
}
