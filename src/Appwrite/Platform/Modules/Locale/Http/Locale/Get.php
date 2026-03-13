<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response as UtopiaResponse;
use MaxMind\Db\Reader;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends Action
{
    public static function getName(): string
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale')
            ->desc('Get user locale')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/locale/get-locale.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_LOCALE,
                    )
                ]
            ))
            ->inject('request')
            ->inject('response')
            ->inject('locale')
            ->inject('geodb')
            ->callback($this->action(...));
    }

    public function action(Request $request, UtopiaResponse $response, Locale $locale, Reader $geodb): void
    {
        $eu = Config::getParam('locale-eu');
        $currencies = Config::getParam('locale-currencies');
        $output = [];
        $ip = $request->getIP();

        $output['ip'] = $ip;

        $currency = null;

        $record = $geodb->get($ip);

        if ($record) {
            $output['countryCode'] = $record['country']['iso_code'];
            $output['country'] = $locale->getText('countries.' . \strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            $output['continent'] = $locale->getText('continents.' . \strtolower($record['continent']['code']), $locale->getText('locale.country.unknown'));
            $output['continentCode'] = $record['continent']['code'];
            $output['eu'] = (\in_array($record['country']['iso_code'], $eu)) ? true : false;

            foreach ($currencies as $code => $element) {
                if (isset($element['locations']) && isset($element['code']) && \in_array($record['country']['iso_code'], $element['locations'])) {
                    $currency = $element['code'];
                }
            }

            $output['currency'] = $currency;
        } else {
            $output['countryCode'] = '--';
            $output['country'] = $locale->getText('locale.country.unknown');
            $output['continent'] = $locale->getText('locale.country.unknown');
            $output['continentCode'] = '--';
            $output['eu'] = false;
            $output['currency'] = $currency;
        }

        $response->dynamic(new Document($output), UtopiaResponse::MODEL_LOCALE);
    }
}
