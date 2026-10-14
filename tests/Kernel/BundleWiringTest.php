<?php

declare(strict_types=1);

namespace Survos\ImagorBundle\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Survos\ImageContracts\ImageMetaProviderInterface;
use Survos\ImageContracts\ImageUrlBuilderInterface;
use Survos\ImagorBundle\Service\ImagorUrlBuilder;
use Survos\ImagorBundle\SurvosImagorBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/** survos_imagor config → injected ImagorUrlBuilder, and the Twig filters. */
final class BundleWiringTest extends TestCase
{
    public function testConfigIsInjectedAndTwigFiltersWork(): void
    {
        $_SERVER['IMAGOR_HOST'] = 'http://env-host';
        $kernel = new ImagorTestKernel('test', true);
        $kernel->boot();
        $c = $kernel->getContainer();

        $b = $c->get(ImagorUrlBuilder::class);
        self::assertSame('http://env-host', $b->host, 'host defaults to %env(IMAGOR_HOST)%');
        self::assertTrue($b->supports('s3://ink-rappnews/a.jp2'));
        self::assertFalse($b->supports('s3://other/a.jpg'), 'buckets from config');
        self::assertTrue($b->hasPreset('page'), 'presets from config replace the defaults');
        self::assertFalse($b->hasPreset('thumb'));
        self::assertSame($b, $c->get(ImageUrlBuilderInterface::class), 'consumers inject the contract, not the class');
        self::assertSame($b, $c->get(ImageMetaProviderInterface::class));

        $twig = $c->get('test.service_container')->get('twig');
        $html = $twig->createTemplate("{{ 's3://ink-rappnews/a.jp2'|imagor('page') }} {{ 's3://ink-rappnews/a.jp2'|imagor_crop(0, 0, 10, 10) }}")->render();
        self::assertStringContainsString('/fit-in/1000x1000/ink-rappnews/a.jp2', $html);
        self::assertStringContainsString('/0x0:10x10/filters:quality(85):format(webp)/ink-rappnews/a.jp2', $html);

        $kernel->shutdown();
        unset($_SERVER['IMAGOR_HOST']);
    }
}

final class ImagorTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new SurvosKitBundle(), new SurvosImagorBundle()];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'x', 'http_method_override' => false]);
        $container->extension('survos_imagor', [
            'secret' => 'localsecret',
            'buckets' => ['ink-rappnews'],
            'presets' => ['page' => 'fit-in/1000x1000'],
        ]);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/imagor-bundle-test/'.spl_object_id($this);
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
