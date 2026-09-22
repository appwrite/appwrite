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
use Appwrite\Utopia\Database\Validator\KeywordId;
use Appwrite\Utopia\Response;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;
use Utopia\Database\Database;
use Utopia\Database\Document;
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

                Passing `userId` — `current()` for the authenticated user — resolves the photo from everything known about that user: identity photos, email, and name. An explicit `emailHash` or `name` then overrides just that value, and the user's remaining sources stay in the chain. Without `userId`, passing `emailHash` and/or `name` resolves the avatar from those values alone: the hash is looked up on Gravatar and Libravatar, the name is rendered as initials, and the session user stays out of the chain so their own photo never shadows the avatar being asked for. When nothing is passed, the photo resolves for the currently authenticated user. Emails are only ever accepted pre-hashed, so no address ends up in a URL.
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
            ->param('userId', '', fn (Database $dbForProject) => new KeywordId('current()', $dbForProject->getAdapter()->getMaxUIDLength()), 'User ID to resolve the photo for. Pass \'current()\' for the currently authenticated user. When omitted, the session user is used only if no emailHash and no name is passed.', true, ['dbForProject'], example: 'current()')
            ->param('emailHash', '', new Text(64, 64, [...Text::NUMBERS, ...\range('a', 'f'), ...\range('A', 'F')]), 'SHA256 hash of the lowercase, trimmed email address to look up on Gravatar and Libravatar instead of the user\'s own email. Pass the hash, never the address itself.', true)
            ->param('name', '', new Text(128, 0), 'Name to render initials from instead of the user\'s own name. Max length: 128 chars.', true)
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
        $emailHash = \strtolower($emailHash);

        // An explicit emailHash or name already identifies who is being
        // asked about, so the session user only enters the chain implicitly
        // when neither is passed. An explicit userId — 'current()' included —
        // always does.
        if ($userId === '' && $emailHash === '' && $name === '') {
            $userId = 'current()';
        }

        $photoUser = new Document();

        if ($userId === 'current()') {
            $photoUser = clone $user;
        } elseif ($userId !== '') {
            $photoUser = $dbForProject->getDocument('users', $userId);
            if ($photoUser->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND);
            }
        }

        // has 'emailHash', 'name', '$id'
        $profile = new Document();

        // The user fills the profile first so a lookup resolves from
        // everything known about them. Explicit parameters then override
        // their matching attribute only — the rest of the chain stays.
        if (!$photoUser->isEmpty()) {
            $userEmail = $photoUser->getAttribute('email', '');
            $userName = $photoUser->getAttribute('name', '');

            $profile = $profile->setAttribute('$id', $photoUser->getId());

            if ($userName !== '') {
                $profile = $profile->setAttribute('name', $userName);
            }

            if ($userEmail !== '') {
                $profile = $profile->setAttribute('emailHash', \hash('sha256', \strtolower(\trim($userEmail))));
            }
        }

        if ($name !== '') {
            $profile = $profile->setAttribute('name', $name);
        }

        if ($emailHash !== '') {
            $profile = $profile->setAttribute('emailHash', $emailHash);
        }

        $providers = [];

        if ($profile->getId() !== '') {
            $providers[] = new OAuth2($dbForProject);
        }

        if ($profile->getAttribute('emailHash', '') !== '') {
            $providers[] = new Gravatar();
            $providers[] = new Libavatar();
        }

        if ($profile->getAttribute('name', '') !== '') {
            $providers[] = new Initials();
        }

        $providers[] = new Fallback();


        $balancer = new Balancer(new First());

        foreach ($providers as $provider) {
            $balancer->addOption(new Option(['provider' => $provider]));
        }

        $balancer->addFilter(function (Option $option) use ($profile) {
            /** @var Photo $provider */
            $provider = $option->getState('provider');
            return $provider->supports($profile);
        });

        $balancer->addFilter(fn (Option $option) => ! $option->getState('attempted', false));

        $data = null;

        while (($option = $balancer->run()) !== null) {
            $option->setState('attempted', true);

            /** @var Photo $provider */
            $provider = $option->getState('provider');

            $data = $provider->get($profile, $width, $height, $rating);

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
        if (! \extension_loaded('imagick') || $raw === '') {
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
