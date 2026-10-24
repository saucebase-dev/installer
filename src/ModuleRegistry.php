<?php

namespace Saucebase\Installer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Laravel\Prompts\multiselect;

/**
 * Discovers installable Saucebase modules on Packagist.
 */
class ModuleRegistry
{
    /** @var string[] */
    protected array $available = [];

    /** @return string[] Fully-qualified package names, abandoned ones excluded. */
    public function available(): array
    {
        if (! empty($this->available)) {
            return $this->available;
        }

        $response = Http::timeout(10)
            ->get('https://packagist.org/packages/list.json?type=saucebase-module&fields[]=abandoned');

        if (! $response->ok()) {
            return [];
        }

        $packages = $response->json('packages', []);

        return $this->available = array_keys(array_filter(
            $packages,
            fn (array $p) => empty($p['abandoned'])
        ));
    }

    /** `saucebase/coming-soon` reads as "Coming Soon". */
    public function label(string $package): string
    {
        return Str::headline(Str::after($package, '/'));
    }

    /**
     * @param  string[]  $available
     * @return string[]
     */
    public function promptSelection(array $available): array
    {
        $options = collect($available)
            ->mapWithKeys(fn (string $package) => [$package => $this->label($package)])
            ->all();

        return multiselect(
            label: 'Which modules would you like to install?',
            options: $options,
            default: [],
        );
    }
}
