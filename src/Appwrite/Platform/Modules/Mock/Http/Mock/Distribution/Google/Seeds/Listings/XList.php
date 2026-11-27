<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Listings;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class XList extends Base
{
    private const array FIELDS = ['title', 'shortDescription', 'fullDescription'];

    public static function getName(): string
    {
        return 'listDistributionGoogleSeedListings';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::SEEDS . '/listings')
            ->desc('List committed Google Play store listings')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $application = $cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL);
        $listings = [];

        foreach (\is_array($application) ? ($application['listings'] ?? []) : [] as $language => $listing) {
            if (\is_array($listing)) {
                $listings[$language] = $this->summary($listing);
            }
        }

        $response->json(['listings' => $listings]);
    }

    /**
     * @param array<string, mixed> $listing
     * @return array<string, mixed>
     */
    private function summary(array $listing): array
    {
        $summary = [];

        foreach (self::FIELDS as $field) {
            if (\is_string($listing[$field] ?? null) && $listing[$field] !== '') {
                $summary[$field] = $listing[$field];
            }
        }

        $images = [];

        foreach (\array_keys(self::IMAGE_LIMITS) as $type) {
            $uploaded = $listing['images'][$type] ?? [];

            if (\is_array($uploaded) && $uploaded !== []) {
                $images[$type] = \array_values(\array_map(
                    fn (mixed $image): string => \is_array($image) ? (string) ($image['sha256'] ?? '') : '',
                    $uploaded,
                ));
            }
        }

        $summary['images'] = $images;

        return $summary;
    }
}
