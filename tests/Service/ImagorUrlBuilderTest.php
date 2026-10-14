<?php

declare(strict_types=1);

namespace Survos\ImagorBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\ImagorBundle\Service\ImagorUrlBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Expected signatures are computed independently of this code:
 *   printf '%s' "$path" | openssl dgst -sha256 -hmac localsecret -binary | base64 | tr '+/' '-_' | cut -c1-40
 */
final class ImagorUrlBuilderTest extends TestCase
{
    private function builder(?MockHttpClient $http = null): ImagorUrlBuilder
    {
        return new ImagorUrlBuilder(
            host: 'http://localhost:8000/',
            secret: 'localsecret',
            buckets: ['museado', 'ink-rappnews'],
            s3Endpoint: 'https://fsn1.your-objectstorage.com',
            httpClient: $http,
        );
    }

    public function testS3SourceIsBucketSlashKey(): void
    {
        self::assertSame(
            'http://localhost:8000/2lmwRkMeL8mI3CeMkft3yEMvHvuf-XvOfezK6qBm/fit-in/400x400/filters:quality(80):format(webp)/museado/orig/te/st/photo.jpg',
            $this->builder()->preset('s3://museado/orig/te/st/photo.jpg', 'thumb'),
        );
    }

    public function testHttpSourceIsFullyEncodedSoItCannotBeParsedAsOptions(): void
    {
        self::assertSame(
            'http://localhost:8000/zAOVEjik_zhL1NUThp4dC9yUyXosSf1G2Apm5AjI/fit-in/600x400/filters:quality(80):format(webp)/https%3A%2F%2Fexample.com%2Fa%20b.jpg%3Fv%3D1%26w%3D2',
            $this->builder()->preset('https://example.com/a b.jpg?v=1&w=2', 'display'),
        );
    }

    public function testCropOfOurOwnMaster(): void
    {
        self::assertSame(
            'http://localhost:8000/UtBxU-cmFSXtoE_ZKmpe3FjS9gzHfHR0YO1_nOiC/1000x800:3000x2800/fit-in/1000x1000/filters:quality(85):format(webp)/ink-rappnews/R/1/0001.jp2',
            $this->builder()->crop('s3://ink-rappnews/R/1/0001.jp2', 1000, 800, 2000, 2000, 1000, 1000),
        );
    }

    public function testOwnObjectStorageUrlsAreReadInPlace(): void
    {
        $b = $this->builder();
        $expected = $b->preset('s3://ink-rappnews/R/1/0001.jp2');
        self::assertSame($expected, $b->preset('https://fsn1.your-objectstorage.com/ink-rappnews/R/1/0001.jp2'));
        self::assertSame($expected, $b->preset('https://ink-rappnews.fsn1.your-objectstorage.com/R/1/0001.jp2'));
        self::assertNull($b->cachedSourceUrl('https://ink-rappnews.fsn1.your-objectstorage.com/R/1/0001.jp2'), 'our masters are never copied');
        self::assertFalse($b->supports('https://nabolom.fsn1.your-objectstorage.com/a.jpg'), 'bucket not allowed');
        self::assertTrue($b->supports('https://nabolom.fsn1.your-objectstorage.com/a.jpg?X-Amz-Signature=1'), 'presigned stays an http source');
    }

    public function testCachedSourceUrlMatchesTheServerKey(): void
    {
        $url = 'https://iiif.example.org/iiif/2/a%3Ab/full/max/0/default.jpg';
        $d = sha1($url);
        self::assertSame('s3://museado/imagor/src/'.substr($d, 0, 2).'/'.substr($d, 2, 2).'/'.$d, $this->builder()->cachedSourceUrl($url));
    }

    public function testUnsupportedSourcesAndPresets(): void
    {
        $b = $this->builder();
        self::assertFalse($b->supports('s3://survos-platform/vault/a.jpg'));
        self::assertFalse($b->supports('s3://museado/'));
        self::assertFalse($b->supports('file:///etc/passwd'));
        $this->expectException(\InvalidArgumentException::class);
        $b->preset('https://example.com/a.jpg', 'huge');
    }

    public function testUnconfiguredBuilderRefusesToBuild(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImagorUrlBuilder())->preset('https://example.com/a.jpg');
    }

    public function testMeta(): void
    {
        $requested = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested = $url;

            return new MockResponse('{"format":"jpeg","content_type":"image/jpeg","width":8551,"height":10680,"orientation":0,"pages":1,"bands":3,"exif":{},"blurhash":"L9MQ*JfQ-;t7~qt7xuxu9Foft7j[","thumbhash":"LwgGBgCFNuOJaQqIiGuIg7hXAAAAAAA=","average_color":{"r":192,"g":192,"b":192},"filters":[]}');
        });
        $meta = $this->builder($http)->meta('s3://museado/orig/a.jpg');

        self::assertSame('http://localhost:8000/imQP-NDbeS66XubppP_wljVF1MNqTAAfsQuFOMF_/meta/filters:thumbhash():blurhash(4,3):avgcolor()/museado/orig/a.jpg', $requested);
        self::assertSame(8551, $meta->width);
        self::assertSame('LwgGBgCFNuOJaQqIiGuIg7hXAAAAAAA=', $meta->thumbhash);
        self::assertSame('#c0c0c0', $meta->averageHex());

        $common = $this->builder($http)->imageMeta('s3://museado/orig/a.jpg');
        self::assertSame(['width' => 8551, 'height' => 10680, 'format' => 'jpeg', 'thumbhash' => 'LwgGBgCFNuOJaQqIiGuIg7hXAAAAAAA=', 'blurhash' => 'L9MQ*JfQ-;t7~qt7xuxu9Foft7j[', 'averageColor' => '#c0c0c0'], $common->toArray());
    }

    public function testFormatOverrideSwapsThePresetsFormatFilter(): void
    {
        $b = $this->builder();
        $png = $b->preset('s3://museado/orig/a.jpg', 'thumb', 'png');

        self::assertStringEndsWith('/fit-in/400x400/filters:quality(80):format(png)/museado/orig/a.jpg', $png);
        self::assertSame(
            'http://localhost:8000/'.$b->sign('fit-in/400x400/filters:quality(80):format(png)/museado/orig/a.jpg').'/fit-in/400x400/filters:quality(80):format(png)/museado/orig/a.jpg',
            $png,
            'the override is signed, not appended after signing',
        );

        $this->expectException(\InvalidArgumentException::class);
        $b->preset('s3://museado/orig/a.jpg', 'thumb', 'png):evil(');
    }

    public function testFlickrIsServedByFlickr(): void
    {
        $b = $this->builder();
        $base = 'https://live.staticflickr.com/65535/55578917726_234ae89748';
        self::assertSame($base.'_m.jpg', $b->preset($base.'_b.jpg', 'tiny'), '200px → 240 (_m)');
        self::assertSame($base.'.jpg', $b->preset($base.'_b.jpg', 'thumb'), '400px → 500');
        self::assertSame($base.'_z.jpg', $b->preset($base.'_b.jpg', 'display'), '600px → 640 (_z)');
        self::assertSame($base.'_b.jpg', $b->preset($base.'_b.jpg', 'archive'), 'no size → largest');
        self::assertSame($base.'_z.jpg', $b->preset($base.'_z.jpg', 'large'), 'never above the source size');
    }
}
