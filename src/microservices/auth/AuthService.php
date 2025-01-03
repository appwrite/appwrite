<?php

namespace Appwrite\Microservices\Auth;

use Appwrite\Event\EventDispatcher;
use Appwrite\Auth\Auth;

class AuthService {
    private $eventDispatcher;
    private $auth;

    public function __construct(EventDispatcher $eventDispatcher, Auth $auth) {
        $this->eventDispatcher = $eventDispatcher;
        $this->auth = $auth;
    }

    public function authenticate($credentials) {
        // Authentication logic
        $result = $this->auth->authenticate($credentials);
        
        if ($result->isSuccessful()) {
            $this->eventDispatcher->dispatch('auth.success', [
                'userId' => $result->getUserId(),
                'timestamp' => time()
            ]);
        }

        return $result;
    }
}
