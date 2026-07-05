<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiIdentityVerification;
use Illuminate\Http\Request;

class IdentityVerificationController extends Controller
{
    public function show(Request $request)
    {
        $verification = ProffiIdentityVerification::forUser((int) $request->user()->id);

        return $this->mapVerification($verification);
    }

    public function submit(Request $request)
    {
        $data = $request->validate([
            'passport_main_photo' => ['required', 'string', 'max:2048'],
            'passport_registration_photo' => ['required', 'string', 'max:2048'],
            'passport_selfie_photo' => ['required', 'string', 'max:2048'],
        ]);

        $verification = ProffiIdentityVerification::forUser((int) $request->user()->id);

        if ($verification->status === ProffiIdentityVerification::STATUS_PENDING) {
            return response()->json(['detail' => 'Заявка уже на проверке'], 400);
        }

        $verification->update([
            'passport_main_photo' => $data['passport_main_photo'],
            'passport_registration_photo' => $data['passport_registration_photo'],
            'passport_selfie_photo' => $data['passport_selfie_photo'],
            'status' => ProffiIdentityVerification::STATUS_PENDING,
            'moderator_comment' => null,
            'reviewed_by' => null,
        ]);

        return $this->mapVerification($verification->fresh());
    }

    private function mapVerification(ProffiIdentityVerification $verification): array
    {
        return [
            'status' => $verification->status,
            'passport_main_photo' => $verification->passport_main_photo,
            'passport_registration_photo' => $verification->passport_registration_photo,
            'passport_selfie_photo' => $verification->passport_selfie_photo,
            'moderator_comment' => $verification->moderator_comment,
            'updated_at' => optional($verification->updated_at)->toIso8601String(),
        ];
    }
}
