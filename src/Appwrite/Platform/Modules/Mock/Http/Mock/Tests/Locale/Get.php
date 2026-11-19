<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Locale;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getLocale';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/locale')
            ->desc('Mock locale translation key')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->inject('locale')
            ->inject('localeCodes')
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Locale $locale, array $localeCodes, Request $request, Response $response): void
    {
        $localeParam = (string) $request->getParam('locale', $request->getHeaderLine('x-appwrite-locale', ''));
        if (\in_array($localeParam, $localeCodes)) {
            $locale->setDefault($localeParam);
        }

        $response->send($locale->getText('mock'));
    }
}
