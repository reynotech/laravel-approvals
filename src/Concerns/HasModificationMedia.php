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
    /**
     * Register the `thumb` (300x200) and `normal` (800x600) image conversions.
     *
     * @param  \Spatie\MediaLibrary\MediaCollections\Models\Media|null  $media
     * @return void
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Contain, 300, 200);
        $this->addMediaConversion('normal')->fit(Fit::Contain, 800, 600);
    }

    /**
     * Attach an image to the `main` media collection.
     *
     * @param  \Illuminate\Http\UploadedFile  $image
     * @param  array<string, mixed>  $meta  `n` or `name` and `l` or `label` are required. `d` or `description`, `h` or `helpText`, and `i`, `imageIndex` or `index` are optional.
     * @return \Spatie\MediaLibrary\MediaCollections\Models\Media
     */
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

    /**
     * The name, the label and the URL of each requested size of an image.
     *
     * @param  \Spatie\MediaLibrary\MediaCollections\Models\Media|null  $media
     * @param  array<int, string>  $loads  Any of `thumb`, `normal` and `original`.
     * @return array<string, mixed>|null  Null when there's no image.
     */
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
