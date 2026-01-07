<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Cards\Cloud\OG;

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
        return 'getCloudCardOG';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/cards/cloud-og')
            ->desc('Get OG image From Cloud Card')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resourceType', 'cards/cloud-og')
            ->label('cache.resource', 'card-og/{request.userId}')
            ->label('docs', false)
            ->label('origin', '*')
            ->param('userId', '', new UID(), 'User ID.', true)
            ->param('mock', '', new WhiteList(['employee', 'employee-2digit', 'hero', 'contributor', 'normal', 'platinum', 'normal-no-github', 'normal-long', 'normal-long-right', 'normal-long-middle', 'normal-bg2', 'normal-bg3', 'normal-right', 'normal-middle', 'platinum-right', 'platinum-middle', 'hero-middle', 'hero-right', 'contributor-right', 'employee-right', 'contributor-middle', 'employee-middle', 'employee-2digit-middle', 'employee-2digit-right']), 'Mocking behaviour.', true)
            ->param('width', 0, new Range(0, 1024), 'Resize  image card width, Pass an integer between 0 to 1024.', true)
            ->param('height', 0, new Range(0, 1024), 'Resize image card height, Pass an integer between 0 to 1024.', true)
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('response')
            ->inject('heroes')
            ->inject('contributors')
            ->inject('employees')
            ->inject('logger')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $mock, int $width, int $height, Document $user, Document $project, Database $dbForProject, Database $dbForPlatform, Response $response, array $heroes, array $contributors, array $employees, ?Logger $logger)
    {
        $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));

        if ($user->isEmpty() && empty($mock)) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if (!$mock) {
            $sequence = $user->getSequence();
            $bgVariation = $sequence % 3 === 0 ? '1' : ($sequence % 3 === 1 ? '2' : '3');
            $cardVariation = $sequence % 3 === 0 ? '1' : ($sequence % 3 === 1 ? '2' : '3');

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
            $bgVariation = \str_ends_with($mock, '-bg2') ? '2' : (\str_ends_with($mock, '-bg3') ? '3' : '1');
            $cardVariation = \str_ends_with($mock, '-right') ? '2' : (\str_ends_with($mock, '-middle') ? '3' : '1');
            $name = \str_starts_with($mock, 'normal-long') ? 'Sir First Walter O\'Brian Junior' : 'Walter O\'Brian';
            $createdAt = new \DateTime('now');
            $githubName = $mock === 'normal-no-github' ? '' : (\str_starts_with($mock, 'normal-long') ? 'sir-first-walterobrian-junior' : 'walterobrian');
            $isHero = \str_starts_with($mock, 'hero');
            $isContributor = \str_starts_with($mock, 'contributor');
            $isEmployee = \str_starts_with($mock, 'employee');
            $employeeNumber = match ($mock) {
                'employee' => '1',
                'employee-right' => '1',
                'employee-middle' => '1',
                'employee-2digit' => '18',
                'employee-2digit-right' => '18',
                'employee-2digit-middle' => '18',
                default => ''
            };

            $isPlatinum = \str_starts_with($mock, 'platinum');
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

        $baseImage = new Imagick(__DIR__ . "/../../../../../../../../public/images/cards/cloud/og-background{$bgVariation}.png");

        $cardType = $isGolden ? '-golden' : ($isPlatinum ? '-platinum' : '');

        $image = new Imagick(__DIR__ . "/../../../../../../../../public/images/cards/cloud/og-card{$cardType}{$cardVariation}.png");
        $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 1008 / 2 - $image->getImageWidth() / 2, 1008 / 2 - $image->getImageHeight() / 2);

        $imageLogo = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/og-background-logo.png');
        $imageShadow = new Imagick(__DIR__ . "/../../../../../../../../public/images/cards/cloud/og-shadow{$cardType}.png");
        if ($cardVariation === '1') {
            $baseImage->compositeImage($imageLogo, Imagick::COMPOSITE_OVER, 32, 1008 - $imageLogo->getImageHeight() - 32);
            $baseImage->compositeImage($imageShadow, Imagick::COMPOSITE_OVER, -450, 700);
        } elseif ($cardVariation === '2') {
            $baseImage->compositeImage($imageLogo, Imagick::COMPOSITE_OVER, 1008 - $imageLogo->getImageWidth() - 32, 1008 - $imageLogo->getImageHeight() - 32);
            $baseImage->compositeImage($imageShadow, Imagick::COMPOSITE_OVER, -20, 710);
        } else {
            $baseImage->compositeImage($imageLogo, Imagick::COMPOSITE_OVER, 1008 - $imageLogo->getImageWidth() - 32, 1008 - $imageLogo->getImageHeight() - 32);
            $baseImage->compositeImage($imageShadow, Imagick::COMPOSITE_OVER, -135, 710);
        }

        if ($isEmployee) {
            $file = $cardVariation === '3' ? 'employee-skew.png' : 'employee.png';
            $image = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/' . $file);
            $image->setGravity(Imagick::GRAVITY_CENTER);

            $hashtag = new ImagickDraw();
            $hashtag->setTextAlignment(Imagick::ALIGN_LEFT);
            $hashtag->setFont(__DIR__ . '/../../../../../../../../public/fonts/Inter-Bold.ttf');
            $hashtag->setFillColor(new ImagickPixel('#FFFADF'));
            $hashtag->setFontSize(20);
            $hashtag->setFontWeight(700);

            $text = new ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_LEFT);
            $text->setFont(__DIR__ . '/../../../../../../../../public/fonts/Inter-Bold.ttf');
            $text->setFillColor(new ImagickPixel('#FFFADF'));
            $text->setFontSize(\strlen($employeeNumber) <= 1 ? 36 : 28);
            $text->setFontWeight(700);

            if ($cardVariation === '3') {
                $hashtag->setFontSize(16);
                $text->setFontSize(\strlen($employeeNumber) <= 1 ? 30 : 26);

                $hashtag->skewY(20);
                $hashtag->skewX(20);
                $text->skewY(20);
                $text->skewX(20);
            }

            $metricsHashtag = $baseImage->queryFontMetrics($hashtag, '#');
            $metricsText = $baseImage->queryFontMetrics($text, $employeeNumber);

            $group = new Imagick();
            $groupWidth = $metricsHashtag['textWidth'] + 6 + $metricsText['textWidth'];

            if ($cardVariation === '1') {
                $group->newImage($groupWidth, $metricsText['textHeight'], '#00000000');
                $group->annotateImage($hashtag, 0, $metricsText['textHeight'], 0, '#');
                $group->annotateImage($text, $metricsHashtag['textWidth'] + 6, $metricsText['textHeight'], 0, $employeeNumber);

                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), -20);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 612, 203);

                $group->rotateImage(new ImagickPixel('#00000000'), -22);

                if (\strlen($employeeNumber) <= 1) {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 660, 245);
                } else {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 655, 247);
                }
            } elseif ($cardVariation === '2') {
                $group->newImage($groupWidth, $metricsText['textHeight'], '#00000000');
                $group->annotateImage($hashtag, 0, $metricsText['textHeight'], 0, '#');
                $group->annotateImage($text, $metricsHashtag['textWidth'] + 6, $metricsText['textHeight'], 0, $employeeNumber);

                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), 30);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 715, 425);

                $group->rotateImage(new ImagickPixel('#00000000'), 32);

                if (\strlen($employeeNumber) <= 1) {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 775, 465);
                } else {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 767, 470);
                }
            } else {
                $group->newImage(300, 300, '#00000000');

                $hashtag->annotation(0, $metricsText['textHeight'], '#');
                $text->annotation($metricsHashtag['textWidth'] + 2, $metricsText['textHeight'], $employeeNumber);

                $group->drawImage($hashtag);
                $group->drawImage($text);

                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 640, 293);

                if (\strlen($employeeNumber) <= 1) {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 670, 317);
                } else {
                    $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, 663, 322);
                }
            }
        }

        if ($isContributor) {
            $file = $cardVariation === '3' ? 'contributor-skew.png' : 'contributor.png';
            $image = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/' . $file);
            $image->setGravity(Imagick::GRAVITY_CENTER);

            if ($cardVariation === '1') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), -20);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 612, 203);
            } elseif ($cardVariation === '2') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), 30);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 715, 425);
            } else {
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 640, 293);
            }
        }

        if ($isHero) {
            $file = $cardVariation === '3' ? 'hero-skew.png' : 'hero.png';
            $image = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/' . $file);
            $image->setGravity(Imagick::GRAVITY_CENTER);

            if ($cardVariation === '1') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), -20);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 612, 203);
            } elseif ($cardVariation === '2') {
                $image->resizeImage(120, 120, Imagick::FILTER_LANCZOS, 1);
                $image->rotateImage(new ImagickPixel('#00000000'), 30);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 715, 425);
            } else {
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 640, 293);
            }
        }

        setlocale(LC_ALL, "en_US.utf8");
        // $name = \iconv("utf-8", "ascii//TRANSLIT", $name);
        // $memberSince = \iconv("utf-8", "ascii//TRANSLIT", $memberSince);
        // $githubName = \iconv("utf-8", "ascii//TRANSLIT", $githubName);

        $textName = new ImagickDraw();
        $textName->setTextAlignment(Imagick::ALIGN_CENTER);
        $textName->setFont(__DIR__ . '/../../../../../../../../public/fonts/Inter-Bold.ttf');
        $textName->setFillColor(new ImagickPixel('#FFFFFF'));

        if (\strlen($name) > 32) {
            $name = \substr($name, 0, 32);
        }

        if ($cardVariation === '1') {
            if (\strlen($name) <= 23) {
                $scalingDown = false;
                $textName->setFontSize(54);
            } else {
                $scalingDown = true;
                $textName->setFontSize(36);
            }
        } elseif ($cardVariation === '2') {
            if (\strlen($name) <= 23) {
                $scalingDown = false;
                $textName->setFontSize(50);
            } else {
                $scalingDown = true;
                $textName->setFontSize(34);
            }
        } else {
            if (\strlen($name) <= 23) {
                $scalingDown = false;
                $textName->setFontSize(44);
            } else {
                $scalingDown = true;
                $textName->setFontSize(32);
            }
        }

        $textName->setFontWeight(700);

        $textMember = new ImagickDraw();
        $textMember->setTextAlignment(Imagick::ALIGN_CENTER);
        $textMember->setFont(__DIR__ . '/../../../../../../../../public/fonts/Inter-Medium.ttf');
        $textMember->setFillColor(new ImagickPixel($isGolden || $isPlatinum ? '#FFFFFF' : '#FFB9CC'));
        $textMember->setFontWeight(500);
        $textMember->setTextKerning(1.12);

        if ($cardVariation === '1') {
            $textMember->setFontSize(21);

            $baseImage->annotateImage($textName, 550, 600, -22, $name);
            $baseImage->annotateImage($textMember, 585, 635, -22, $memberSince);
        } elseif ($cardVariation === '2') {
            $textMember->setFontSize(20);

            $baseImage->annotateImage($textName, 435, 590, 31.37, $name);
            $baseImage->annotateImage($textMember, 412, 628, 31.37, $memberSince);
        } else {
            $textMember->setFontSize(16);

            $textName->skewY(20);
            $textName->skewX(20);
            $textName->annotation(320, 700, $name);

            $textMember->skewY(20);
            $textMember->skewX(20);
            $textMember->annotation(330, 735, $memberSince);

            $baseImage->drawImage($textName);
            $baseImage->drawImage($textMember);
        }

        if (!empty($githubName)) {
            $text = new ImagickDraw();
            $text->setTextAlignment(Imagick::ALIGN_LEFT);
            $text->setFont(__DIR__ . '/../../../../../../../../public/fonts/Inter-Regular.ttf');
            $text->setFillColor(new ImagickPixel('#FFFFFF'));
            $text->setFontSize($scalingDown ? 16 : 20);
            $text->setFontWeight(400);

            if ($cardVariation === '1') {
                $metrics = $baseImage->queryFontMetrics($text, $githubName);

                $group = new Imagick();
                $groupWidth = $metrics['textWidth'] + 32 + 4;
                $group->newImage($groupWidth, $metrics['textHeight'] + 10, '#00000000');
                $image = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/github.png');
                $image->setGravity(Imagick::GRAVITY_CENTER);
                $image->resizeImage(32, 32, Imagick::FILTER_LANCZOS, 1);
                $precisionFix = -1;

                $group->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
                $group->annotateImage($text, 32 + 4, $metrics['textHeight'] - $precisionFix, 0, $githubName);

                $group->rotateImage(new ImagickPixel('#00000000'), -22);
                $x = 510 - $group->getImageWidth() / 2;
                $y = 530 - $group->getImageHeight() / 2;
                $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, $x, $y);
            } elseif ($cardVariation === '2') {
                $metrics = $baseImage->queryFontMetrics($text, $githubName);

                $group = new Imagick();
                $groupWidth = $metrics['textWidth'] + 32 + 4;
                $group->newImage($groupWidth, $metrics['textHeight'] + 10, '#00000000');
                $image = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/github.png');
                $image->setGravity(Imagick::GRAVITY_CENTER);
                $image->resizeImage(32, 32, Imagick::FILTER_LANCZOS, 1);
                $precisionFix = -1;

                $group->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
                $group->annotateImage($text, 32 + 4, $metrics['textHeight'] - $precisionFix, 0, $githubName);

                $group->rotateImage(new ImagickPixel('#00000000'), 31.11);
                $x = 485 - $group->getImageWidth() / 2;
                $y = 530 - $group->getImageHeight() / 2;
                $baseImage->compositeImage($group, Imagick::COMPOSITE_OVER, $x, $y);
            } else {
                $text->skewY(20);
                $text->skewX(20);
                $text->setTextAlignment(Imagick::ALIGN_CENTER);

                $text->annotation(320 + 15 + 2, 640, $githubName);
                $metrics = $baseImage->queryFontMetrics($text, $githubName);

                $image = new Imagick(__DIR__ . '/../../../../../../../../public/images/cards/cloud/github-skew.png');
                $image->setGravity(Imagick::GRAVITY_CENTER);
                $baseImage->compositeImage($image, Imagick::COMPOSITE_OVER, 512 - ($metrics['textWidth'] / 2), 518 + \strlen($githubName) * 1.3);

                $baseImage->drawImage($text);
            }
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
