<?php

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeader('x-forwarded-for', '');

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeader('X-Real-IP', '');

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeader('cf-connecting-ip');

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeaderLine('True-Client-IP');

// ruleid: php.appwrite.client-ip-header
$forwarded = $request->getHeader('forwarded', '');

// ruleid: php.appwrite.client-ip-header
$host = $request->getHeader('x-forwarded-host', '');

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeaders()['x-forwarded-for'];

// ruleid: php.appwrite.client-ip-header
$ip = $swooleRequest->header['x-real-ip'];

// ruleid: php.appwrite.client-ip-header
$ip = $request->getServer('HTTP_X_FORWARDED_FOR');

// ok: php.appwrite.client-ip-header
$ip = $request->getIP();

// ok: php.appwrite.client-ip-header
$project = $request->getHeader('x-appwrite-project', '');

// ok: php.appwrite.client-ip-header
$agent = $request->getHeaderLine('x-forwarded-user-agent');
