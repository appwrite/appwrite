<?php

namespace Appwrite\Docker\Compose;

use Exception;

class Generator
{
    private const array SELECTABLE_SERVICE_GROUPS = [
        'database' => [
            'default' => 'mongodb',
            'services' => [
                'mariadb',
                'mongodb',
                'postgresql',
            ],
        ],
    ];

    private const array SELECTABLE_VOLUME_GROUPS = [
        'database' => [
            'mariadb' => ['appwrite-mariadb'],
            'mongodb' => ['appwrite-mongodb', 'appwrite-mongodb-keyfile'],
            'postgresql' => ['appwrite-postgresql'],
        ],
    ];

    private const array OPTIONAL_SERVICES = [
        'enableAssistant' => 'appwrite-assistant',
    ];

    private const array LOCAL_VOLUME_PREPENDS = [
        'appwrite' => [
            'param' => 'hostPath',
            'volume' => '{hostPath}:/usr/src/code:rw',
        ],
    ];

    private const array PARAM_DEFAULTS = [
        'version' => 'latest',
        'database' => 'mongodb',
        'hostPath' => '',
        'enableAssistant' => false,
    ];

    private const array DEPENDENCY_SELECTORS = [
        'database' => [
            'param' => 'database',
            'condition' => [
                'condition' => 'service_healthy',
            ],
            'placeholders' => [
                '${_APP_DB_HOST:-mongodb}',
                '${_APP_DB_HOST:-mariadb}',
            ],
        ],
    ];

    private const array YAML_DOCUMENT_MARKER_PATTERNS = [
        '/^---\R/',
        '/\R\\.\\.\\.\R?$/',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $compose;

    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    private array $selectableServices = [
        'mariadb',
        'mongodb',
        'postgresql',
    ];

    public function __construct(string $compose)
    {
        $parsed = \yaml_parse($compose);

        if (!\is_array($parsed)) {
            throw new Exception('Failed to parse Docker Compose file');
        }

        $this->compose = $parsed;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(array $params): string
    {
        $compose = $this->compose;
        $this->params = $this->normalizeParams($params);
        $this->selectableServices = $this->getSelectableServices();

        $compose['services'] = $this->filterServices($compose['services'] ?? []);
        $compose['volumes'] = $this->filterVolumes($compose['volumes'] ?? []);

        foreach ($compose['services'] as $name => &$service) {
            if (!\is_array($service)) {
                continue;
            }

            $this->rewriteDependencies($service);
            $this->rewriteRelativeBindMounts($service);
            $this->rewriteLocalVolumes($name, $service);
        }
        unset($service);

        return $this->emit($compose);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeParams(array $params): array
    {
        $params = \array_merge(self::PARAM_DEFAULTS, $params);

        foreach (self::SELECTABLE_SERVICE_GROUPS as $param => $config) {
            if (!\in_array($params[$param], $config['services'], true)) {
                $params[$param] = $config['default'];
            }
        }

        $params['hostPath'] = \rtrim((string)$params['hostPath'], '/');

        return $params;
    }

    /**
     * @return string[]
     */
    private function getSelectableServices(): array
    {
        $services = [];

        foreach (self::SELECTABLE_SERVICE_GROUPS as $config) {
            $services = \array_merge($services, $config['services']);
        }

        return \array_values(\array_unique($services));
    }

    /**
     * @param array<string, mixed> $services
     * @return array<string, mixed>
     */
    private function filterServices(array $services): array
    {
        foreach (self::SELECTABLE_SERVICE_GROUPS as $param => $config) {
            foreach ($config['services'] as $service) {
                if ($service !== $this->params[$param]) {
                    unset($services[$service]);
                }
            }
        }

        foreach (self::OPTIONAL_SERVICES as $param => $service) {
            if (empty($this->params[$param])) {
                unset($services[$service]);
            }
        }

        return $services;
    }

    /**
     * @param array<string, mixed> $volumes
     * @return array<string, mixed>
     */
    private function filterVolumes(array $volumes): array
    {
        foreach (self::SELECTABLE_VOLUME_GROUPS as $param => $groups) {
            foreach ($groups as $service => $names) {
                if ($service === $this->params[$param]) {
                    continue;
                }

                foreach ($names as $name) {
                    unset($volumes[$name]);
                }
            }
        }

        return $volumes;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function rewriteDependencies(array &$service): void
    {
        if (!isset($service['depends_on'])) {
            return;
        }

        foreach (self::DEPENDENCY_SELECTORS as $selector) {
            $selected = $this->params[$selector['param']];
            $removable = [
                ...$this->selectableServices,
                ...$selector['placeholders'],
            ];

            if (\array_is_list($service['depends_on'])) {
                $hasSelectedDependency = false;
                $dependsOn = \array_values(\array_filter(
                    $service['depends_on'],
                    function (mixed $dependency) use ($removable, &$hasSelectedDependency): bool {
                        if (!\in_array($dependency, $removable, true)) {
                            return true;
                        }

                        $hasSelectedDependency = true;

                        return false;
                    }
                ));

                if ($hasSelectedDependency) {
                    $dependsOn = \array_fill_keys($dependsOn, ['condition' => 'service_started']);
                    $dependsOn[$selected] = $selector['condition'];
                }

                $service['depends_on'] = $dependsOn;
                continue;
            }

            $hasSelectedDependency = false;
            foreach ($removable as $dependency) {
                if (\array_key_exists($dependency, $service['depends_on'])) {
                    $hasSelectedDependency = true;
                }

                unset($service['depends_on'][$dependency]);
            }

            if ($hasSelectedDependency) {
                $service['depends_on'][$selected] = $selector['condition'];
            }
        }
    }

    /**
     * @param array<string, mixed> $service
     */
    private function rewriteRelativeBindMounts(array &$service): void
    {
        if ($this->params['version'] !== 'local' || empty($this->params['hostPath']) || empty($service['volumes'])) {
            return;
        }

        foreach ($service['volumes'] as &$volume) {
            if (!\is_string($volume) || !\str_starts_with($volume, './')) {
                continue;
            }

            $volume = $this->params['hostPath'] . '/' . \substr($volume, 2);
        }
        unset($volume);
    }

    /**
     * @param array<string, mixed> $service
     */
    private function rewriteLocalVolumes(string $name, array &$service): void
    {
        if ($this->params['version'] !== 'local' || !isset(self::LOCAL_VOLUME_PREPENDS[$name])) {
            return;
        }

        $config = self::LOCAL_VOLUME_PREPENDS[$name];
        if (empty($this->params[$config['param']])) {
            return;
        }

        $service['volumes'] ??= [];
        \array_unshift($service['volumes'], $this->replacePlaceholders($config['volume']));
    }

    /**
     * @param array<string, mixed> $compose
     */
    private function emit(array $compose): string
    {
        $yaml = \yaml_emit($compose, YAML_UTF8_ENCODING, YAML_LN_BREAK);

        foreach (self::YAML_DOCUMENT_MARKER_PATTERNS as $pattern) {
            $yaml = \preg_replace($pattern, '', $yaml) ?? $yaml;
        }

        return $yaml;
    }

    private function replacePlaceholders(string $value): string
    {
        foreach ($this->params as $param => $replacement) {
            if (\is_scalar($replacement)) {
                $value = \str_replace('{' . $param . '}', (string)$replacement, $value);
            }
        }

        return $value;
    }

}
