<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale\Countries\EU;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listCountriesEU';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/countries/eu')
            ->desc('List EU countries')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: 'locale',
                group: null,
                name: 'listCountriesEU',
                description: '/docs/references/locale/list-countries-eu.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_COUNTRY_LIST,
                    )
                ]
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(bool $includeTotal, Response $response, Locale $locale): void
    {
        $eu = Config::getParam('locale-eu');
        $output = [];

        foreach ($eu as $code) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'name' => $locale->getText('countries.' . strtolower($code)),
                    'code' => $code,
                ]);
            }
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['countries' => $output, 'total' => $includeTotal ? \count($output) : 0]), Response::MODEL_COUNTRY_LIST);
    }
}
