<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Runner\Platform;
use Appwrite\Distribution\Runner\Token;
use Appwrite\Distribution\Runners;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * Enrols a macOS runner. The agent presents the operator's enrolment secret once and
 * receives its own runner token, which it uses from then on.
 */
class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRunner';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/runners')
            ->desc('Create runner')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['ip'], 10)])
            ->param('name', '', new Text(128), 'Runner name.')
            ->param('platform', '', new WhiteList(Platform::values(), true), 'Runner platform.')
            ->param('labels', [], new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Labels the runner can build for, such as the Xcode version it has installed.', true)
            ->param('capacity', 1, new Range(1, 2), 'How many builds the runner runs at once.', true)
            ->param('version', '', new Text(32), 'Runner agent version.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param list<string> $labels
     */
    public function action(
        string $name,
        string $platform,
        array $labels,
        int $capacity,
        string $version,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        $secret = System::getEnv('_APP_DISTRIBUTION_RUNNER_SECRET', '');
        if ($secret === '') {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Runner enrolment is disabled.');
        }

        $header = $request->getHeaderLine('authorization');
        if (!\str_starts_with($header, 'Bearer ') || !\hash_equals($secret, \substr($header, \strlen('Bearer ')))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid runner enrolment secret.');
        }

        try {
            $enrolled = $authorization->skip(fn () => (new Runners($dbForPlatform))->enrol($name, Platform::from($platform), $labels, $capacity, $version));
        } catch (\LogicException $error) {
            if ($error->getMessage() !== Token::KEY_REQUIRED) {
                throw $error;
            }

            throw new Exception(Exception::GENERAL_SERVER_ERROR, Token::KEY_REQUIRED);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->addHeader('Cache-Control', 'no-store')
            ->json([
                'runnerId' => $enrolled['runner']->getId(),
                'token' => $enrolled['token'],
            ]);
    }
}
