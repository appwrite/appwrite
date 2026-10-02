<?php

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeader('x-forwarded-for', '');

// ruleid: php.appwrite.client-ip-header
$ip = $request->getHeader('X-Real-IP', '');

// ok: php.appwrite.client-ip-header
$ip = $request->getIP();

// ok: php.appwrite.client-ip-header
$project = $request->getHeader('x-appwrite-project', '');
