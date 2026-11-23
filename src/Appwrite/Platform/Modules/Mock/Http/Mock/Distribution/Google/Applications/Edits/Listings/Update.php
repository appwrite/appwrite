<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Listings;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Base
{
    private const array LENGTHS = [
        'title' => 30,
        'shortDescription' => 80,
        'fullDescription' => 4000,
    ];

    public static function getName(): string
    {
        return 'updateDistributionGoogleListing';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/edits/:editId/listings/:language')
            ->desc('Update Google Play edit listing')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID.')
            ->param('language', '', new Text(0), 'BCP-47 language tag of the listing.')
            ->param('title', null, new Nullable(new Text(0)), 'Localized title of the app.', true)
            ->param('shortDescription', null, new Nullable(new Text(0)), 'Short description of the app.', true)
            ->param('fullDescription', null, new Nullable(new Text(0)), 'Full description of the app.', true)
            ->param('video', null, new Nullable(new Text(0)), 'URL of a promotional YouTube video for the app.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $editId, string $language, ?string $title, ?string $shortDescription, ?string $fullDescription, ?string $video, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $edit = $this->edit($request, $response, $cache, $packageName, $editId);

        if ($edit === null) {
            return;
        }

        $current = $edit['listings'][$language] ?? null;

        if (!\is_array($current)) {
            $this->notFound($response, 'notFound', 'Listing not found: ' . $language . '.');
            return;
        }

        $fields = \array_filter([
            'title' => $title,
            'shortDescription' => $shortDescription,
            'fullDescription' => $fullDescription,
            'video' => $video,
        ], \is_string(...));

        foreach (self::LENGTHS as $field => $length) {
            if (\mb_strlen($fields[$field] ?? '') > $length) {
                $this->badRequest($response, 'Listing ' . $field . ' must be at most ' . $length . ' characters.');
                return;
            }
        }

        $listing = [...$current, ...$fields];

        $edit['listings'][$language] = $listing;
        $this->saveEdit($cache, $edit);

        $response->json($this->listing($language, $listing));
    }
}
