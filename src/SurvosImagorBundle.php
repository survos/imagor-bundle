<?php

declare(strict_types=1);

namespace Survos\ImagorBundle;

use Survos\ImagorBundle\Service\ImagorUrlBuilder;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Signed URLs for the Survos imagor build (survos-sites/imagor).
 *
 * Everything is configured in survos_imagor.yaml (env-var defaults below) and
 * injected into ImagorUrlBuilder by this extension — the builder never reads
 * the environment itself.
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosImagorBundle extends AbstractSurvosBundle
{
    /**
     * MUST match IMAGOR_PRESETS in survos-sites/imagor's Dockerfile. imagor has
     * no server-side presets: the client expands them, and the server persists
     * results (tiny, thumb) only when the options match its copy byte-for-byte.
     * A drifted preset still renders; it just never caches.
     */
    public const DEFAULT_PRESETS = [
        'tiny' => 'fit-in/200x200/filters:quality(70):format(webp)',
        'thumb' => 'fit-in/400x400/filters:quality(80):format(webp)',
        'observe' => 'fit-in/512x512/filters:quality(80):format(webp)',
        'display' => 'fit-in/600x400/filters:quality(80):format(webp)',
        'archive' => 'filters:quality(88):format(webp)',
        'card' => '800x600/filters:quality(82):format(webp):no_upscale()',
        'large' => 'fit-in/1600x1600/filters:quality(82):format(webp)',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('host')->defaultValue('%env(default::IMAGOR_HOST)%')
                    ->info('Base URL of the imagor server, e.g. https://imagor.survos.com')->end()
                ->scalarNode('secret')->defaultValue('%env(default::IMAGOR_SECRET)%')
                    ->info('IMAGOR_SECRET on the server; signs every URL')->end()
                ->integerNode('signer_truncate')->defaultValue(40)
                    ->info('IMAGOR_SIGNER_TRUNCATE on the server (signature is HMAC-SHA256, base64url)')->end()
                ->arrayNode('buckets')
                    ->info('Buckets the server reads in place as {bucket}/{key} (its buckets.yaml). s3:// sources elsewhere are not supported. Empty = any.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('s3_endpoint')->defaultValue('%env(default::S3_ENDPOINT)%')
                    ->info('Our object storage; https URLs into it are rewritten to {bucket}/{key} (read in place, never copied)')->end()
                ->scalarNode('source_bucket')->defaultValue('museado')
                    ->info('S3_STORAGE_BUCKET on the server: where cached external originals live')->end()
                ->scalarNode('source_prefix')->defaultValue('imagor/src')
                    ->info('S3_STORAGE_BASE_DIR on the server')->end()
                ->arrayNode('presets')
                    ->info('name => imagor options. REPLACES the defaults; keep in sync with the server.')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                    ->defaultValue(self::DEFAULT_PRESETS)
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $container->services()
            ->set(ImagorUrlBuilder::class)
            ->arg('$host', $config['host'])
            ->arg('$secret', $config['secret'])
            ->arg('$presets', $config['presets'])
            ->arg('$buckets', $config['buckets'])
            ->arg('$s3Endpoint', $config['s3_endpoint'])
            ->arg('$sourceBucket', $config['source_bucket'])
            ->arg('$sourcePrefix', $config['source_prefix'])
            ->arg('$signerTruncate', $config['signer_truncate'])
            ->arg('$httpClient', service('http_client')->nullOnInvalid())
            ->public()
            ->autoconfigure();
    }
}
