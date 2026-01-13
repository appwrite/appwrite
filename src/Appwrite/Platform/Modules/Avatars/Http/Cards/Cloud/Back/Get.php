<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Cards\Cloud\Back;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\Utopia\Response;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Logger;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getCloudCardBack';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/cards/cloud-back')
            ->desc('Get back Of Cloud Card')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resourceType', 'cards/cloud-back')
            ->label('cache.resource', 'card-back/{request.userId}')
            ->label('docs', false)
            ->label('origin', '*')
            ->param('userId', '', new UID(), 'User ID.', true)
            ->param('mock', '', new WhiteList(['golden', 'normal', 'platinum']), 'Mocking behaviour.', true)
            ->param('width', 0, new Range(0, 512), 'Resize  image width, Pass an integer between 0 to 512.', true)
            ->param('height', 0, new Range(0, 320), 'Resize image height, Pass an integer between 0 to 320.', true)
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('response')
            ->inject('heroes')
            ->inject('contributors')
            ->inject('employees')
            ->inject('logger')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $mock, int $width, int $height, Document $user, Document $project, Database $dbForProject, Database $dbForPlatform, Response $response, array $heroes, array $contributors, array $employees, ?Logger $logger, Authorization $authorization)
    {
        $user = $authorization->skip(fn () => $dbForPlatform->getDocument('users', $userId));

        if ($user->isEmpty() && empty($mock)) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if (!$mock) {
            $userId = $user->getId();
            $email = $user->getAttribute('email', '');

            $gitHub = $this->getUserGitHub($user->getId(), $project, $dbForProject, $dbForPlatform, $logger);
            $githubId = $gitHub['id'] ?? '';

            $isHero = \array_key_exists($email, $heroes);
            $isContributor = \in_array($githubId, $contributors);
            $isEmployee = \array_key_exists($email, $employees);

            $isGolden = $isEmployee || $isHero || $isContributor;
            $isPlatinum = $user->getSequence() % 100 === 0;
        } else {
            $userId = '63e0bcf3c3eb803ba530';

            $isGolden = $mock === 'golden';
            $isPlatinum = $mock === 'platinum';
        }

        $userId = 'UID ' . $userId;

        $isPlatinum = $isGolden ? false : $isPlatinum;

        $imagePath = $isGolden ? 'back-golden.png' : ($isPlatinum ? 'back-platinum.png' : 'back.png');

        $baseImage = new Imagick($this->getAppRoot() . '/public/images/cards/cloud/' . $imagePath);

        setlocale(LC_ALL, "en_US.utf8");
        // $userId = \iconv("utf-8", "ascii//TRANSLIT", $userId);

        $text = new ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont($this->getAppRoot() . '/public/fonts/SourceCodePro-Regular.ttf');
        $text->setFillColor(new ImagickPixel($isGolden ? '#664A1E' : ($isPlatinum ? '#555555' : '#E8E9F0')));
        $text->setFontSize(28);
        $text->setFontWeight(400);
        $baseImage->annotateImage($text, 512, 596, 0, $userId);

        if (!empty($width) || !empty($height)) {
            $baseImage->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($baseImage->getImageBlob());
    }
}
