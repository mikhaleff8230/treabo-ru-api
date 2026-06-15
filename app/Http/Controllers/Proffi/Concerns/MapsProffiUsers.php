<?php

namespace App\Http\Controllers\Proffi\Concerns;

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

        return [
            'id' => (string) $user->id,
            'phone' => $profile?->contact ?? '',
            'name' => $user->name ?? '',
            'role' => $this->proffiRole($user),
            'city' => $profile?->proffi_city,
            'rating' => 0.0,
            'reviews_count' => 0,
            'bio' => $profile?->bio,
            'services' => is_array($services) ? $services : [],
            'avatar' => $this->avatarUrl($profile?->avatar),
            'portfolio' => $this->mediaUrls(is_array($socials) ? ($socials['treabo_portfolio'] ?? []) : []),
            'lat' => $profile?->proffi_lat !== null ? (float) $profile->proffi_lat : null,
            'lng' => $profile?->proffi_lng !== null ? (float) $profile->proffi_lng : null,
            'last_seen' => optional($user->updated_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'email' => $user->email,
            'is_verified' => (bool) $user->email_verified,
        ];
    }
}
