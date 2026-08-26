<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\AvatarPhotos\Photo;
use Appwrite\AvatarPhotos\Providers\Fallback;
use Appwrite\AvatarPhotos\Providers\Gravatar;
use Appwrite\AvatarPhotos\Providers\Initials;
use Appwrite\AvatarPhotos\Providers\Libavatar;
use Appwrite\AvatarPhotos\Providers\OAuth2;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/photo')
            ->desc('Get user photo')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getPhoto',
                description: <<<'EOT'
                Returns the best available profile photo for a user. The endpoint tries each source in priority order and returns the first successful result: OAuth2 identity photo, Gravatar, Libravatar, Appwrite Initials, built-in static fallback.

                The photo resolves for the currently authenticated user unless `userId` points at another user. An explicit `emailHash` or `name` parameter takes priority over the user's own attributes: the hash is looked up on Gravatar and Libravatar, and the name is rendered as initials. Emails are only ever accepted pre-hashed, so no address ends up in a URL.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::IMAGE
            ))
            ->param('width', 256, new Range(0, 2000), 'Output image width in pixels. Pass an integer between 0 and 2000. Defaults to 256.', true)
            ->param('height', 256, new Range(0, 2000), 'Output image height in pixels. Pass an integer between 0 and 2000. Defaults to 256.', true)
            ->param('quality', 100, new Range(0, 100), 'Output image quality between 0 and 100. Defaults to 100.', true)
            ->param('output', 'png', new WhiteList(['png', 'jpg', 'webp'], true), 'Output image format. Defaults to \'png\'.', true)
            ->param('rating', 'g', new WhiteList(['g', 'pg', 'r', 'x'], true), 'Maximum image rating to fetch from Gravatar/Libravatar. Defaults to \'g\'.', true)
            ->param('userId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID to resolve the photo for. Defaults to \'current\' for the currently authenticated user.', true, ['dbForProject'])
            ->param('emailHash', '', new Text(64, 64, [...Text::NUMBERS, ...\range('a', 'f'), ...\range('A', 'F')]), 'SHA256 hash of the lowercase, trimmed email address to look up on Gravatar and Libravatar. Takes priority over the user\'s email. Pass the hash, never the address itself.', true)
            ->param('name', '', new Text(128, 0), 'Name to render initials from when no photo is found. Takes priority over the user\'s name. Max length: 128 chars.', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        int $width,
        int $height,
        int $quality,
        string $output,
        string $rating,
        string $userId,
        string $emailHash,
        string $name,
        Response $response,
        Document $user,
        Database $dbForProject,
    ): void {
        if ($userId !== 'current') {
            $user = $dbForProject->getDocument('users', $userId);

            if ($user->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND);
            }
        }

        // An explicit name takes priority over the user's own. Work on a copy —
        // the injected user document is shared with the request's other hooks.
        if (!empty($name)) {
            $user = (clone $user)->setAttribute('name', $name);
        }

        // The hash reaches the avatar services verbatim, and they expect it
        // lowercase.
        $providers = [
            new OAuth2($dbForProject),
            new Gravatar(\strtolower($emailHash)),
            new Libavatar(\strtolower($emailHash)),
            new Initials(),
            new Fallback(),
        ];

        $balancer = new Balancer(new First());

        foreach ($providers as $provider) {
            $balancer->addOption(new Option(['provider' => $provider]));
        }

        // Skip providers that lack the data they need — no email means no
        // Gravatar lookup — so we never pay for a doomed network round-trip.
        $balancer->addFilter(function (Option $option) use ($user) {
            /** @var Photo $provider */
            $provider = $option->getState('provider');

            return $provider->supports($user);
        });

        // A provider that came up empty is out of the running; without this the
        // First algorithm would hand back the same option forever.
        $balancer->addFilter(fn (Option $option) => ! $option->getState('attempted', false));

        $data = null;

        while (($option = $balancer->run()) !== null) {
            $option->setState('attempted', true);

            /** @var Photo $provider */
            $provider = $option->getState('provider');

            $data = $provider->get($user, $width, $height, $rating);

            if ($data !== null) {
                break;
            }
        }

        if ($data === null) {
            throw new Exception(Exception::AVATAR_NOT_FOUND);
        }

        $contentType = match ($output) {
            'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        $response
            ->addHeader('Cache-Control', 'private, no-store') // photo can change at any time
            ->setContentType($contentType)
            ->file($this->process($data, $width, $height, $quality, $output));
    }

    /**
     * Resize and re-encode raw image bytes.
     */
    private function process(string $raw, int $width, int $height, int $quality, string $output): string
    {
        if (! \extension_loaded('imagick') || empty($raw)) {
            return $raw;
        }

        try {
            $image = new Image($raw);

            if ($width > 0 || $height > 0) {
                $image->crop(
                    $width > 0 ? $width : 256,
                    $height > 0 ? $height : 256,
                );
            }

            return $image->output($output, $quality);
        } catch (\Throwable) {
            return $raw;
        }
    }
}
