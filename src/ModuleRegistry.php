<?php

namespace Saucebase\Installer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Laravel\Prompts\multiselect;

/**
 * Discovers installable Saucebase modules on Packagist and the frontend
 * frameworks each one supports.
 */
class ModuleRegistry
{
    /** @var string[] */
    protected array $available = [];

    /** @var array<string, string[]> */
    protected array $frameworks = [];

    public function __construct(protected ?string $modulesPath = null) {}

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

    /** @return string[] Frameworks the module supports; defaults to ['vue']. */
    public function frameworks(string $package): array
    {
        if (isset($this->frameworks[$package])) {
            return $this->frameworks[$package];
        }

        $name = Str::after($package, '/');

        if ($this->modulesPath !== null) {
            $localManifest = $this->modulesPath."/{$name}/composer.json";

            if (file_exists($localManifest)) {
                $local = json_decode((string) file_get_contents($localManifest), true);
                $frameworks = data_get($local, 'extra.saucebase.frameworks');

                if (is_array($frameworks) && ! empty($frameworks)) {
                    return $this->frameworks[$package] = $frameworks;
                }
            }
        }

        $response = Http::timeout(5)->get("https://raw.githubusercontent.com/saucebase-dev/{$name}/main/composer.json");

        if ($response->ok()) {
            $frameworks = data_get($response->json(), 'extra.saucebase.frameworks');

            if (is_array($frameworks) && ! empty($frameworks)) {
                return $this->frameworks[$package] = $frameworks;
            }
        }

        return $this->frameworks[$package] = ['vue'];
    }

    /**
     * @param  string[]  $packages
     * @return string[]
     */
    public function filterByFramework(array $packages, string $framework): array
    {
        return array_values(array_filter(
            $packages,
            fn (string $pkg) => in_array($framework, $this->frameworks($pkg), true)
        ));
    }

    /**
     * @param  string[]  $available
     * @return string[]
     */
    public function promptSelection(array $available): array
    {
        $options = collect($available)
            ->mapWithKeys(fn (string $package) => [
                $package => Str::studly(Str::after($package, '/')),
            ])
            ->all();

        return multiselect(
            label: 'Which modules would you like to install?',
            options: $options,
            default: [],
        );
    }
}
