<?php

declare(strict_types=1);

namespace Survos\ImagorBundle\Command;

use Survos\ImagorBundle\Service\ImagorUrlBuilder;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('imagor:url', 'Signed imagor URL for a source image (preset or meta), and where its cached original lives')]
final class ImagorUrlCommand
{
    public function __construct(private readonly ImagorUrlBuilder $imagor)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Source: https URL, or s3://bucket/key')] string $source,
        #[Argument('Preset name')] string $preset = 'thumb',
        #[Option('Fetch meta/ (thumbhash, blurhash, average color) instead')] bool $meta = false,
    ): int {
        if (!$this->imagor->supports($source)) {
            $io->error("imagor cannot read $source (bucket not in survos_imagor.buckets?)");

            return Command::FAILURE;
        }

        if ($meta) {
            $m = $this->imagor->meta($source);
            $io->definitionList(
                ['size' => "{$m->width}x{$m->height} {$m->contentType}"],
                ['thumbhash' => (string) $m->thumbhash],
                ['blurhash' => (string) $m->blurhash],
                ['average' => (string) $m->averageHex()],
            );

            return Command::SUCCESS;
        }

        if (!$this->imagor->hasPreset($preset)) {
            $io->error(sprintf('Unknown preset "%s". Available: %s', $preset, implode(', ', array_keys($this->imagor->getPresets()))));

            return Command::FAILURE;
        }

        $url = $this->imagor->preset($source, $preset);
        $io->writeln(sprintf('<href=%s>%s</>', $url, $url));
        if (null !== $cached = $this->imagor->cachedSourceUrl($source)) {
            $io->writeln("cached original: $cached");
        }

        return Command::SUCCESS;
    }
}
