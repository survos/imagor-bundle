<?php

declare(strict_types=1);

namespace Survos\ImagorBundle\Service;

use Survos\ImagorBundle\Dto\ImagorMeta;
use Survos\ImagorBundle\SurvosImagorBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * Builds signed imagor URLs:  {host}/{signature}/{options}/{image}.
 *
 *  - options: a preset (client-expanded, see SurvosImagorBundle::DEFAULT_PRESETS),
 *    a crop, or anything imagor understands.
 *  - image: "{bucket}/{key}" for our own S3 (read in place, never copied), or a
 *    fully percent-encoded URL for anything else, so '?', '(' and ',' can never
 *    be parsed as options. Our own object storage's https URLs are rewritten to
 *    {bucket}/{key} first.
 *  - signature: base64url(HMAC-SHA256(secret, "{options}/{image}")), truncated.
 *
 * All configuration is injected (survos_imagor.yaml); nothing reads the env here.
 */
final class ImagorUrlBuilder
{
    /**
     * @param array<string, string> $presets name => imagor options
     * @param list<string>          $buckets buckets readable in place; [] = any
     */
    public function __construct(
        public readonly ?string $host = null,
        private readonly ?string $secret = null,
        private readonly array $presets = SurvosImagorBundle::DEFAULT_PRESETS,
        private readonly array $buckets = [],
        private readonly ?string $s3Endpoint = null,
        private readonly string $sourceBucket = 'museado',
        private readonly string $sourcePrefix = 'imagor/src',
        private readonly int $signerTruncate = 40,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->host && '' !== $this->host && null !== $this->secret && '' !== $this->secret;
    }

    public function hasPreset(string $preset): bool
    {
        return isset($this->presets[$preset]);
    }

    /** @return array<string, string> */
    public function getPresets(): array
    {
        return $this->presets;
    }

    /** Whether imagor can read this source: http(s), or s3:// in an allowed bucket. */
    public function supports(string $source): bool
    {
        return null !== $this->imagePath($source);
    }

    /** {{ asset.url|imagor('thumb') }} */
    #[AsTwigFilter('imagor')]
    public function preset(string $source, string $preset = 'thumb'): string
    {
        if (!isset($this->presets[$preset])) {
            throw new \InvalidArgumentException(sprintf('Unknown imagor preset "%s". Available: %s', $preset, implode(', ', array_keys($this->presets))));
        }

        return $this->build($source, $this->presets[$preset]);
    }

    /**
     * A region of the source, in source pixels, then fitted into $width x $height.
     * Cached server-side when IMAGOR_CACHE_CROPS=true. {{ url|imagor_crop(x, y, w, h, 1000) }}.
     */
    #[AsTwigFilter('imagor_crop')]
    public function crop(string $source, int $x, int $y, int $w, int $h, int $width = 0, int $height = 0, string $format = 'webp', int $quality = 85): string
    {
        $options = sprintf('%dx%d:%dx%d', $x, $y, $x + $w, $y + $h);
        if ($width > 0 || $height > 0) {
            $options .= sprintf('/fit-in/%dx%d', $width, $height);
        }

        return $this->build($source, sprintf('%s/filters:quality(%d):format(%s)', $options, $quality, $format));
    }

    /** Arbitrary imagor options, e.g. "fit-in/1200x0/filters:format(webp)". */
    public function build(string $source, string $options): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('imagor is not configured. Set survos_imagor.host and survos_imagor.secret (IMAGOR_HOST, IMAGOR_SECRET).');
        }
        $image = $this->imagePath($source) ?? throw new \InvalidArgumentException(sprintf('imagor cannot read source "%s".', $source));

        $path = trim($options, '/').'/'.$image;

        return rtrim((string) $this->host, '/').'/'.$this->sign($path).'/'.$path;
    }

    /**
     * Signed meta/ URL. Extras are computed in Go on the server:
     * thumbhash, blurhash (4x3), avgcolor.
     *
     * @param list<string> $extras
     */
    public function metaUrl(string $source, array $extras = ['thumbhash', 'blurhash', 'avgcolor']): string
    {
        $filters = array_map(static fn (string $e): string => match ($e) {
            'blurhash' => 'blurhash(4,3)',
            default => $e.'()',
        }, $extras);

        return $this->build($source, 'meta'.([] === $filters ? '' : '/filters:'.implode(':', $filters)));
    }

    /** @param list<string> $extras */
    public function meta(string $source, array $extras = ['thumbhash', 'blurhash', 'avgcolor']): ImagorMeta
    {
        if (!$this->httpClient instanceof HttpClientInterface) {
            throw new \RuntimeException('imagor meta() requires symfony/http-client.');
        }

        return ImagorMeta::fromArray($this->httpClient->request('GET', $this->metaUrl($source, $extras))->toArray());
    }

    /**
     * Where the server keeps its byte-identical copy of an EXTERNAL original —
     * s3://{source_bucket}/{source_prefix}/{aa}/{bb}/{sha1(url)} — computable from
     * the URL alone and readable without imagor. Null for our own buckets: those
     * are never copied. The object exists once imagor has served the source.
     */
    public function cachedSourceUrl(string $source): ?string
    {
        $source = $this->ownStorageS3Url($source) ?? $source;
        if (!preg_match('~^https?://~i', $source)) {
            return null;
        }
        $d = sha1($source);

        return sprintf('s3://%s/%s/%s/%s/%s', $this->sourceBucket, trim($this->sourcePrefix, '/'), substr($d, 0, 2), substr($d, 2, 2), $d);
    }

    public function sign(string $path): string
    {
        $sig = strtr(base64_encode(hash_hmac('sha256', $path, (string) $this->secret, true)), '+/', '-_');

        return $this->signerTruncate > 0 ? substr($sig, 0, $this->signerTruncate) : $sig;
    }

    private function imagePath(string $source): ?string
    {
        $source = $this->ownStorageS3Url($source) ?? $source;

        if (str_starts_with($source, 's3://')) {
            [$bucket, $key] = array_pad(explode('/', substr($source, 5), 2), 2, '');
            if ('' === $bucket || '' === $key || ([] !== $this->buckets && !in_array($bucket, $this->buckets, true))) {
                return null;
            }

            return implode('/', array_map(rawurlencode(...), [$bucket, ...explode('/', $key)]));
        }

        if (preg_match('~^https?://~i', $source)) {
            return rawurlencode($source);
        }

        return null;
    }

    /**
     * https://{endpoint}/{bucket}/{key} and https://{bucket}.{endpoint}/{key}
     * → s3://{bucket}/{key}. Presigned URLs (with a query) are left alone.
     */
    private function ownStorageS3Url(string $source): ?string
    {
        $endpoint = parse_url((string) $this->s3Endpoint, PHP_URL_HOST);
        $parts = parse_url($source);
        if (!$endpoint || !isset($parts['host'], $parts['path']) || isset($parts['query'])) {
            return null;
        }
        $path = ltrim(rawurldecode($parts['path']), '/');
        if (0 === strcasecmp($parts['host'], $endpoint)) {
            return str_contains($path, '/') ? 's3://'.$path : null;
        }
        $suffix = '.'.strtolower($endpoint);
        if (str_ends_with(strtolower($parts['host']), $suffix) && '' !== $path) {
            return 's3://'.substr($parts['host'], 0, -strlen($suffix)).'/'.$path;
        }

        return null;
    }
}
