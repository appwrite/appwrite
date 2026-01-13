<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Cards\Cloud\Front;

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
        return 'getCloudCard';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/cards/cloud')
            ->desc('Get front Of Cloud Card')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resourceType', 'cards/cloud')
            ->label('cache.resource', 'card/{request.userId}')
            ->label('docs', false)
            ->label('origin', '*')
            ->param('userId', '', new UID(), 'User ID.', true)
            ->param('mock', '', new WhiteList(['employee', 'employee-2digit', 'hero', 'contributor', 'normal', 'platinum', 'normal-no-github', 'normal-long']), 'Mocking behaviour.', true)
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
            $name = $user->getAttribute('name', 'Anonymous');
            $email = $user->getAttribute('email', '');
            $createdAt = new \DateTime($user->getCreatedAt());

            $gitHub = $this->getUserGitHub($user->getId(), $project, $dbForProject, $dbForPlatform, $logger);
            $githubName = $gitHub['name'] ?? '';
            $githubId = $gitHub['id'] ?? '';

            $isHero = \array_key_exists($email, $heroes);
            $isContributor = \in_array($githubId, $contributors);
            $isEmployee = \array_key_exists($email, $employees);
            $employeeNumber = $isEmployee ? $employees[$email]['spot'] : '';

            if ($isHero) {
                $createdAt = new \DateTime($heroes[$email]['memberSince'] ?? '');
            } elseif ($isEmployee) {
                $createdAt = new \DateTime($employees[$email]['memberSince'] ?? '');
            }

            if (!$isEmployee && !empty($githubName)) {
                $employeeGitHub = \array_search(\strtolower($githubName), \array_map(fn ($employee) => \strtolower($employee['gitHub']) ?? '', $employees));
                if (!empty($employeeGitHub)) {
                    $isEmployee = true;
                    $employeeNumber = $isEmployee ? $employees[$employeeGitHub]['spot'] : '';
                    $createdAt = new \DateTime($employees[$employeeGitHub]['memberSince'] ?? '');
                }
            }

            $isPlatinum = $user->getSequence() % 100 === 0;
        } else {
            $name = $mock === 'normal-long' ? 'Sir First Walter O\'Brian Junior' : 'Walter O\'Brian';
            $createdAt = new \DateTime('now');
            $githubName = $mock === 'normal-no-github' ? '' : ($mock === 'normal-long' ? 'sir-first-walterobrian-junior' : 'walterobrian');
            $isHero = $mock === 'hero';
            $isContributor = $mock === 'contributor';
            $isEmployee = \str_starts_with($mock, 'employee');
            $employeeNumber = match ($mock) {
                'employee' => '1',
                'employee-2digit' => '18',
                default => ''
            };

            $isPlatinum = $mock === 'platinum';
        }

        if ($isEmployee) {
            $isContributor = false;
            $isHero = false;
        }

        if ($isHero) {
            $isContributor = false;
            $isEmployee = false;
        }

        if ($isContributor) {
            $isHero = false;
            $isEmployee = false;
        }

        $isGolden = $isEmployee || $isHero || $isContributor;
        $isPlatinum = $isGolden ? false : $isPlatinum;
        $memberSince = \strtoupper('Member since ' . $createdAt->format('M') . ' ' . $createdAt->format('d') . ', ' . $createdAt->format('o'));

        $imagePath = $isGolden ? 'front-golden.png' : ($isPlatinum ? 'front-platinum.png' : 'front.png');

        $baseImage = new Imagick($this->getAppRoot() . '/public/images/cards/cloud/' . $imagePath);

        if ($isEmployee) {
            $image = new Imagick($this->getAppRoot() . '/public/images/cards/cloud/employee.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 793, 35);

            $text = new ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_CENTER);
            $text->setFont($this->getAppRoot() . '/public/fonts/Inter-Bold.ttf');
            $text->setFillColor(new ImagickPixel('#FFFADF'));
            $text->setFontSize(\strlen($employeeNumber) <= 2 ? 54 : 48);
            $text->setFontWeight(700);
            $metricsText = $baseImage->queryFontMetrics($text, $employeeNumber);

            $hashtag = new ImagickDraw();
            $hashtag->setTextAlignment(Imagick::ALIGN_CENTER);
            $hashtag->setFont($this->getAppRoot() . '/public/fonts/Inter-Bold.ttf');
            $hashtag->setFillColor(new ImagickPixel('#FFFADF'));
            $hashtag->setFontSize(28);
            $hashtag->setFontWeight(700);
            $metricsHashtag = $baseImage->queryFontMetrics($hashtag, '#');

            $startX = 898;
            $totalWidth = $metricsHashtag['textWidth'] + 12 + $metricsText['textWidth'];

            $hashtagX = ($metricsHashtag['textWidth'] / 2);
            $textX = $hashtagX + 12 + ($metricsText['textWidth'] / 2);

            $hashtagX -= $totalWidth / 2;
            $textX -= $totalWidth / 2;

            $hashtagX += $startX;
            $textX += $startX;

            $baseImage->annotateImage($hashtag, $hashtagX, 150, 0, '#');
            $baseImage->annotateImage($text, $textX, 150, 0, $employeeNumber);
        }

        if ($isContributor) {
            $image = new Imagick($this->getAppRoot() . '/public/images/cards/cloud/contributor.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 793, 34);
        }

        if ($isHero) {
            $image = new Imagick($this->getAppRoot() . '/public/images/cards/cloud/hero.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 793, 34);
        }

        setlocale(LC_ALL, "en_US.utf8");
        // $name = \iconv("utf-8", "ascii//TRANSLIT", $name);
        // $memberSince = \iconv("utf-8", "ascii//TRANSLIT", $memberSince);
        // $githubName = \iconv("utf-8", "ascii//TRANSLIT", $githubName);

        $text = new ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont($this->getAppRoot() . '/public/fonts/Inter-Bold.ttf');
        $text->setFillColor(new ImagickPixel('#FFFFFF'));

        if (\strlen($name) > 32) {
            $name = \substr($name, 0, 32);
        }

        if (\strlen($name) <= 23) {
            $text->setFontSize(80);
            $scalingDown = false;
        } else {
            $text->setFontSize(54);
            $scalingDown = true;
        }
        $text->setFontWeight(700);
        $baseImage->annotateImage($text, 512, 477, 0, $name);

        $text = new ImagickDraw();
        $text->setTextAlignment(Imagick::ALIGN_CENTER);
        $text->setFont($this->getAppRoot() . '/public/fonts/Inter-SemiBold.ttf');
        $text->setFillColor(new ImagickPixel($isGolden || $isPlatinum ? '#FFFFFF' : '#FFB9CC'));
        $text->setFontSize(27);
        $text->setFontWeight(600);
        $text->setTextKerning(1.08);
        $baseImage->annotateImage($text, 512, 541, 0, \strtoupper($memberSince));

        if (!empty($githubName)) {
            $text = new ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_CENTER);
            $text->setFont($this->getAppRoot() . '/public/fonts/Inter-Regular.ttf');
            $text->setFillColor(new ImagickPixel('#FFFFFF'));
            $text->setFontSize($scalingDown ? 28 : 32);
            $text->setFontWeight(400);
            $metrics = $baseImage->queryFontMetrics($text, $githubName);

            $baseImage->annotateImage($text, 512 + 20 + 4, 373 + ($scalingDown ? 2 : 0), 0, $githubName);

            $image = new Imagick($this->getAppRoot() . '/public/images/cards/cloud/github.png');
            $image->setGravity(Imagick::GRAVITY_CENTER);
            $precisionFix = 5;
            $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 512 - ($metrics['textWidth'] / 2) - 20 - 4, 373 - ($metrics['textHeight'] - $precisionFix));
        }

        if (!empty($width) || !empty($height)) {
            $baseImage->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($baseImage->getImageBlob());
    }
}
