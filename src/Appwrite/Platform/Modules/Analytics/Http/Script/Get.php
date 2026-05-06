<?php

namespace Appwrite\Platform\Modules\Analytics\Http\Script;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAnalyticsScript';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/analytics/script.js')
            ->desc('Serve analytics tracking script')
            ->groups(['api', 'analytics'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'analytics',
                group: 'script',
                name: 'getScript',
                description: 'Returns the JavaScript tracking script.',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    ),
                ],
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $script = $this->buildScript();

        $response
            ->setContentType('application/javascript')
            ->addHeader('Cache-Control', 'public, max-age=3600')
            ->send($script);
    }

    private function buildScript(): string
    {
        return <<<'JS'
(function () {
  'use strict';
  var s = document.currentScript;
  if (!s) { return; }
  var endpoint = s.src.replace(/\/v1\/analytics\/script\.js.*$/, '');
  var sid = s.getAttribute('data-sid');
  if (!sid) { return; }

  function send(name) {
    var data = {
      n: name,
      u: location.href,
      d: location.hostname,
      r: document.referrer,
      sid: sid
    };
    var url = endpoint + '/v1/analytics/event';
    var body = JSON.stringify(data);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
    } else {
      var x = new XMLHttpRequest();
      x.open('POST', url, true);
      x.setRequestHeader('Content-Type', 'application/json');
      x.send(body);
    }
  }

  function page() { send('pageview'); }

  var ps = history.pushState;
  history.pushState = function () { ps.apply(history, arguments); page(); };
  window.addEventListener('popstate', page);

  window.appwriteAnalytics = function (name) { send(name || 'event'); };

  if (navigator.doNotTrack !== '1') {
    page();
  }
})();
JS;
    }
}
