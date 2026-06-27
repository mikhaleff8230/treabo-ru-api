<?php

namespace App\Http\Controllers\Proffi\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Otp\Gateways\OtpGateway;

trait UsesTreaboPhoneOtp
{
    protected function treaboPhoneOtpEnabled(): bool
    {
        return (bool) config('services.treabo.phone_otp_enabled', false);
    }

    protected function getTreaboOtpGateway(): OtpGateway
    {
        $gateway = config('auth.active_otp_gateway', 'redsms');
        $gateWayClass = 'Marvel\\Otp\\Gateways\\' . ucfirst($gateway) . 'Gateway';

        return new OtpGateway(new $gateWayClass());
    }

    protected function treaboOtpContextKey(string $otpId): string
    {
        return "treabo_otp_ctx:{$otpId}";
    }

    protected function cacheTreaboOtpContext(string $otpId, array $context): void
    {
        Cache::put($this->treaboOtpContextKey($otpId), $context, now()->addMinutes(10));
    }

    protected function getTreaboOtpContext(string $otpId): ?array
    {
        $context = Cache::get($this->treaboOtpContextKey($otpId));

        return is_array($context) ? $context : null;
    }

    protected function forgetTreaboOtpContext(string $otpId): void
    {
        Cache::forget($this->treaboOtpContextKey($otpId));
    }

    protected function dispatchTreaboPhoneOtp(string $phone): array
    {
        try {
            $otpGateway = $this->getTreaboOtpGateway();
            $result = $otpGateway->startVerification($phone);

            if (!$result->isValid()) {
                $errors = $result->getErrors();
                $message = is_array($errors) && count($errors) > 0
                    ? (string) ($errors[0] ?? 'SMS send failed')
                    : 'SMS send failed';

                Log::error('Treabo OTP send failed', [
                    'phone' => $phone,
                    'errors' => $errors,
                ]);

                return ['ok' => false, 'detail' => $message];
            }

            $otpId = $result->getId();
            if (!$otpId) {
                return ['ok' => false, 'detail' => 'SMS send failed'];
            }

            return ['ok' => true, 'otp_id' => $otpId];
        } catch (\Throwable $e) {
            Log::error('Treabo OTP gateway error', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'detail' => 'SMS send failed'];
        }
    }

    protected function verifyTreaboPhoneOtpCode(string $otpId, string $code, string $phone): bool
    {
        try {
            $otpGateway = $this->getTreaboOtpGateway();
            $result = $otpGateway->checkVerification($otpId, trim($code), $phone);

            return $result->isValid();
        } catch (\Throwable $e) {
            Log::warning('Treabo OTP verify error', [
                'otp_id' => $otpId,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function treaboOtpSentPayload(string $phone, string $otpId): array
    {
        return [
            'status' => 'otp_sent',
            'phone' => $phone,
            'otp_id' => $otpId,
        ];
    }
}
