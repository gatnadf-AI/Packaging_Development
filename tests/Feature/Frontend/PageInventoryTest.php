<?php

declare(strict_types=1);

namespace Tests\Frontend;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * TSD-701 — one Inertia page component per screen id, and a test that walks the
 * server's own offers rather than a list someone maintains beside the code.
 *
 * The reviewed prototype's screens were specified in one place and built in
 * another, and the two drifted: a controller answered with a component name no
 * build had, and the browser showed a blank page under a working URL. A server
 * that names a screen is claiming the screen exists, so the claim is checked
 * twice — once against the file, once against the built manifest that the browser
 * is actually handed.
 *
 * This is the shape the Phase 1.6 inventory test will grow into (every FSD screen
 * id against its component); today it covers the screens the application already
 * offers, which is the part that can be asserted without waiting for Phase 2.
 */
final class PageInventoryTest extends TestCase
{
    use SeedsReferenceData;

    public function testEveryScreenTheServerOffersHasAPageComponent(): void
    {
        $offered = $this->screensTheServerOffers();

        $this->assertNotEmpty($offered, 'no Inertia::render() call was found — the scanner is wrong');

        $missing = array_values(array_filter(
            $offered,
            fn (string $screen): bool => ! is_file($this->pagePath($screen)),
        ));

        $this->assertSame(
            [],
            $missing,
            'These screens are offered by a controller but have no page component: '
                .implode(', ', array_map(fn (string $s): string => "resources/js/Pages/{$s}.jsx", $missing))
        );
    }

    public function testEveryPageComponentIsBuiltIntoTheManifestTheBrowserReads(): void
    {
        $manifest = $this->builtManifest();
        $notBuilt = array_values(array_filter(
            $this->screensTheServerOffers(),
            fn (string $screen): bool => ! array_key_exists("resources/js/Pages/{$screen}.jsx", $manifest),
        ));

        $this->assertSame(
            [],
            $notBuilt,
            'These screens exist as source but are not in public/build/manifest.json — run `npm run build`: '
                .implode(', ', $notBuilt)
        );
    }

    /**
     * The screen names every controller and route file offers, read off the code.
     *
     * @return list<string>
     */
    private function screensTheServerOffers(): array
    {
        $screens = [];

        foreach ($this->phpFiles() as $file) {
            preg_match_all('/Inertia::render\(\'([A-Za-z0-9_\/]+)\'/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $name) {
                $screens[$name] = true;
            }
        }

        return array_keys($screens);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFiles(): array
    {
        $found = [];

        foreach (['app/Http/Controllers', 'routes'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    private function pagePath(string $screen): string
    {
        return resource_path("js/Pages/{$screen}.jsx");
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function builtManifest(): array
    {
        $path = public_path('build/manifest.json');

        $this->assertFileExists(
            $path,
            'public/build/manifest.json is missing — run `npm run build` before the feature suite'
        );

        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $manifest;
    }
}
