<?php

namespace App\Http\Controllers\Proffi\Concerns;

use App\Models\ProffiIdentityVerification;
use App\Models\ProffiReview;
use App\Models\ProffiUserPresence;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

trait MapsProffiUsers
{
    protected function proffiRole(User $user): string
    {
        $permissions = $user->getPermissionNames();
        if ($permissions->contains(Permission::SUPER_ADMIN)) {
            return 'admin';
        }
        if ($permissions->contains(Permission::STORE_OWNER)) {
            return 'specialist';
        }
        return 'customer';
    }

    protected function avatarUrl($avatar): ?string
    {
        if (!$avatar) {
            return null;
        }
        if (is_array($avatar)) {
            return $avatar['original'] ?? $avatar['thumbnail'] ?? null;
        }
        if (is_string($avatar)) {
            $decoded = json_decode($avatar, true);
            if (is_array($decoded)) {
                return $decoded['original'] ?? $decoded['thumbnail'] ?? null;
            }
            return $avatar;
        }
        return null;
    }

    protected function mediaUrls($value): array
    {
        if (!$value) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                return [$value];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $urls = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $urls[] = $item;
                continue;
            }
            if (is_array($item)) {
                $url = $item['original'] ?? $item['url'] ?? $item['thumbnail'] ?? null;
                if ($url) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    protected function specialistRatingSummary(User $user): array
    {
        $query = ProffiReview::where('specialist_id', $user->id);
        $count = (int) $query->count();

        return [
            'rating' => $count > 0 ? round((float) $query->avg('rating'), 1) : 0.0,
            'reviews_count' => $count,
        ];
    }

    protected function publicUser(User $user): array
    {
        $profile = $user->profile;
        $services = $profile?->proffi_services ?? [];
        if (is_string($services)) {
            $services = json_decode($services, true) ?: [];
        }
        $socials = $profile?->socials ?? [];
        if (is_string($socials)) {
            $socials = json_decode($socials, true) ?: [];
        }

        $rating = $this->proffiRole($user) === 'specialist'
            ? $this->specialistRatingSummary($user)
            : ['rating' => 0.0, 'reviews_count' => 0];

        $identityStatus = $this->proffiRole($user) === 'specialist'
            ? $this->identityStatus($user)
            : ProffiIdentityVerification::STATUS_NOT_SUBMITTED;

        return [
            'id' => (string) $user->id,
            'phone' => $profile?->contact ?? '',
            'name' => $user->name ?? '',
            'role' => $this->proffiRole($user),
            'city' => $profile?->proffi_city,
            'rating' => $rating['rating'],
            'reviews_count' => $rating['reviews_count'],
            'bio' => $profile?->bio,
            'services' => is_array($services) ? $services : [],
            'avatar' => $this->avatarUrl($profile?->avatar),
            'portfolio' => $this->mediaUrls(is_array($socials) ? ($socials['treabo_portfolio'] ?? []) : []),
            'lat' => $profile?->proffi_lat !== null ? (float) $profile->proffi_lat : null,
            'lng' => $profile?->proffi_lng !== null ? (float) $profile->proffi_lng : null,
            'last_seen' => optional($user->updated_at)->toIso8601String(),
            'last_seen_label' => $this->formatLastSeenLabel((int) $user->id),
            'is_online' => ProffiUserPresence::isUserOnline((int) $user->id),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'email' => $user->email,
            'is_verified' => (bool) $user->email_verified,
            'identity_status' => $identityStatus,
            'passport_verified' => $identityStatus === ProffiIdentityVerification::STATUS_APPROVED,
            'min_price' => is_array($socials) && isset($socials['treabo_min_price'])
                ? (int) $socials['treabo_min_price']
                : null,
        ];
    }

    protected function identityStatus(User $user): string
    {
        $verification = ProffiIdentityVerification::where('user_id', $user->id)->first();

        return $verification?->status ?? ProffiIdentityVerification::STATUS_NOT_SUBMITTED;
    }

    protected function formatLastSeenLabel(int $userId): string
    {
        $presence = ProffiUserPresence::where('user_id', $userId)->first();

        if ($presence && $presence->is_online && $presence->last_seen_at
            && $presence->last_seen_at->greaterThan(now()->subMinutes(2))) {
            return 'Сейчас в сети';
        }

        $lastSeen = $presence?->last_seen_at ?? null;
        if (!$lastSeen) {
            return 'Был в сети давно';
        }

        $minutes = (int) $lastSeen->diffInMinutes(now());
        if ($minutes < 60) {
            return "Был в сети {$minutes} мин. назад";
        }

        $hours = (int) $lastSeen->diffInHours(now());
        if ($hours < 24) {
            return "Был в сети {$hours} ч. назад";
        }

        $days = (int) $lastSeen->diffInDays(now());

        return "Был в сети {$days} дн. назад";
    }
}
