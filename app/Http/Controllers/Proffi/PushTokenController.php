<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiPushToken;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);

        $token = ProffiPushToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'device_id' => $data['device_id'] ?? null, 'last_seen_at' => now()]
        );

        return response()->json(['id' => $token->id, 'registered' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        ProffiPushToken::where('user_id', $request->user()->id)->where('token', $data['token'])->delete();
        return ['ok' => true];
    }
}
