<?php

declare(strict_types=1);

namespace Survos\ImagorBundle\Tests\Contract;

use Survos\ImageContracts\ImageUrlBuilderInterface;
use Survos\ImageContracts\Test\ImageUrlBuilderTestCase;
use Survos\ImagorBundle\Service\ImagorUrlBuilder;

final class ImagorImageUrlBuilderTest extends ImageUrlBuilderTestCase
{
    protected function createBuilder(): ImageUrlBuilderInterface
    {
        return new ImagorUrlBuilder(host: 'http://localhost:8000/', secret: 'localsecret', buckets: ['museado']);
    }

    protected function createUnconfiguredBuilder(): ImageUrlBuilderInterface
    {
        return new ImagorUrlBuilder(host: 'http://localhost:8000/');
    }

    protected function unsupportedSource(): string
    {
        return 's3://not-our-bucket/a.jpg';
    }

    public function testOwnBucketSourceUrlRoundTripsForPresets(): void
    {
        $builder = $this->createBuilder();
        $source = 's3://museado/orig/a b/c.jpg';

        foreach (array_keys($builder->getPresets()) as $preset) {
            self::assertSame($source, $builder->sourceUrl($builder->preset($source, $preset)), $preset);
        }
    }
}
