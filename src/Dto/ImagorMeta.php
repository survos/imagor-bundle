<?php

declare(strict_types=1);

namespace Survos\ImagorBundle\Dto;

use Survos\ImageContracts\ImageMeta;

/**
 * imagor's meta/ response. Unlike imgproxy Pro's /info there are no palettes,
 * perceptual hashes, checksums or classification — but thumbhash, blurhash and
 * average color are computed server-side (Go/libvips) when requested.
 */
final readonly class ImagorMeta
{
    /**
     * @param array<string, string>              $exif
     * @param array{r: int, g: int, b: int}|null $averageColor
     * @param array<int, array<string, mixed>>   $detectedRegions
     */
    public function __construct(
        public string $format,
        public string $contentType,
        public int $width,
        public int $height,
        public int $orientation = 0,
        public int $pages = 1,
        public int $bands = 0,
        public array $exif = [],
        public ?string $thumbhash = null,
        public ?string $blurhash = null,
        public ?array $averageColor = null,
        public array $detectedRegions = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            format: (string) ($data['format'] ?? ''),
            contentType: (string) ($data['content_type'] ?? ''),
            width: (int) ($data['width'] ?? 0),
            height: (int) ($data['height'] ?? 0),
            orientation: (int) ($data['orientation'] ?? 0),
            pages: (int) ($data['pages'] ?? 1),
            bands: (int) ($data['bands'] ?? 0),
            exif: is_array($data['exif'] ?? null) ? $data['exif'] : [],
            thumbhash: $data['thumbhash'] ?? null,
            blurhash: $data['blurhash'] ?? null,
            averageColor: $data['average_color'] ?? null,
            detectedRegions: $data['detected_regions'] ?? [],
        );
    }

    /** The backend-neutral subset ({@see \Survos\ImageContracts\ImageMetaProviderInterface}). */
    public function toImageMeta(): ImageMeta
    {
        return new ImageMeta(
            width: $this->width ?: null,
            height: $this->height ?: null,
            format: '' === $this->format ? null : strtolower($this->format),
            thumbhash: $this->thumbhash,
            blurhash: $this->blurhash,
            averageColor: $this->averageHex(),
        );
    }

    /** "#c0c0c0", or null when avgcolor was not requested. */
    public function averageHex(): ?string
    {
        return null === $this->averageColor ? null : sprintf('#%02x%02x%02x', $this->averageColor['r'], $this->averageColor['g'], $this->averageColor['b']);
    }
}
