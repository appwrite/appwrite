<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class Error extends Action
{
    public static function getName(): string
    {
        return 'installerError';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_ERROR)
            ->inject('error')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(\Throwable $error, Response $response): void
    {
        if ($response->isSent()) {
            return;
        }
        $code = $error->getCode();
        if ($code < 100 || $code > 599) {
            $code = 500;
        }
        $response->setStatusCode($code);
        $message = $code >= 500 ? 'Internal installer error' : $error->getMessage();
        $response->json(['success' => false, 'message' => $message]);
    }
}
