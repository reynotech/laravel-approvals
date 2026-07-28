<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Http\UploadedFile;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Optional: apply to a Modification subclass (which must also implement
 * Spatie\MediaLibrary\HasMedia and `use InteractsWithMedia`) to allow
 * attaching images to a pending change. Requires spatie/laravel-medialibrary,
 * which this package does not depend on directly.
 */
trait HasModificationMedia
{
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Contain, 300, 200);
        $this->addMediaConversion('normal')->fit(Fit::Contain, 800, 600);
    }

    public function addImage(UploadedFile $image, array $meta): Media
    {
        $name = $image->hashName();
        $nameNoExt = explode('.', $name)[0];

        return $this->addMedia($image)
            ->withCustomProperties([
                'n' => $meta['n'] ?? $meta['name'],
                'l' => $meta['l'] ?? $meta['label'],
                'd' => $meta['d'] ?? ($meta['description'] ?? null),
                'h' => $meta['h'] ?? ($meta['helpText'] ?? null),
            ])
            ->setOrder($meta['i'] ?? ($meta['imageIndex'] ?? $meta['index'] ?? 0))
            ->usingName($nameNoExt)
            ->usingFileName($name)
            ->toMediaCollection('main');
    }

    public static function imageLoad(?Media $media, array $loads = ['thumb', 'normal', 'original']): ?array
    {
        if (!$media) {
            return null;
        }

        $profiles = [
            'n' => $media->custom_properties['n'],
            'l' => $media->custom_properties['l'],
        ];

        foreach ($loads as $load) {
            $profiles[$load] = $load === 'original'
                ? $media->getUrl()
                : ($media->hasGeneratedConversion($load) ? $media->getUrl($load) : $media->getUrl());
        }

        return $profiles;
    }
}
