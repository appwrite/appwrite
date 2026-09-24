<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class HostnameTest extends TestCase
{
    public function testCanValidateHostname(): void
    {
        $validator = new Hostname();

        $this->assertSame(\Utopia\Validator::TYPE_STRING, $validator->getType());
        $this->assertFalse($validator->isArray());

        $this->assertTrue($validator->isValid('myweb.com'));
        $this->assertTrue($validator->isValid('httpmyweb.com'));
        $this->assertTrue($validator->isValid('httpsmyweb.com'));
        $this->assertTrue($validator->isValid('wsmyweb.com'));
        $this->assertTrue($validator->isValid('wssmyweb.com'));
        $this->assertTrue($validator->isValid('vercel.app'));
        $this->assertTrue($validator->isValid('web.vercel.app'));
        $this->assertTrue($validator->isValid('my-web.vercel.app'));
        $this->assertTrue($validator->isValid('my-project.my-web.vercel.app'));
        $this->assertTrue($validator->isValid('my-commit.my-project.my-web.vercel.app'));
        $this->assertTrue($validator->isValid('myapp.co.uk'));
        $this->assertTrue($validator->isValid('*.myapp.com'));
        $this->assertTrue($validator->isValid('myapp.*'));

        $this->assertFalse($validator->isValid('https://myweb.com'));
        $this->assertFalse($validator->isValid('ws://myweb.com'));
        $this->assertFalse($validator->isValid('wss://myweb.com'));
        $this->assertFalse($validator->isValid('http://myweb.com'));
        $this->assertFalse($validator->isValid('http://myweb.com:3000'));
        $this->assertFalse($validator->isValid('http://myweb.com/blog'));
        $this->assertFalse($validator->isValid('myweb.com:80'));
        $this->assertFalse($validator->isValid('myweb.com:3000'));
        $this->assertFalse($validator->isValid('myweb.com/blog'));
        $this->assertFalse($validator->isValid('myweb.com/blog/article1'));

        // Max length test
        $domain = str_repeat('bestdomain', 25); // 250 chars total

        $domain .= '.sk'; // Exactly at the limit
        $this->assertTrue($validator->isValid($domain));

        $domain .= 'a'; // Exactly over the limit
        $this->assertFalse($validator->isValid($domain));
    }

    public function testCanValidateHostnamesWithAllowList(): void
    {
        // allowList tests
        $validator = new Hostname([
            'myweb.vercel.app',
            'myweb.com',
            '*.myapp.com',
        ]);

        $this->assertTrue($validator->isValid('myweb.vercel.app'));
        $this->assertFalse($validator->isValid('myweb.vercel.com'));
        $this->assertFalse($validator->isValid('myweb2.vercel.app'));
        $this->assertFalse($validator->isValid('vercel.app'));
        $this->assertFalse($validator->isValid('mycommit.myweb.vercel.app'));

        $this->assertTrue($validator->isValid('myweb.com'));
        $this->assertFalse($validator->isValid('myweb.eu'));
        $this->assertFalse($validator->isValid('project.myweb.eu'));
        $this->assertFalse($validator->isValid('commit.project.myweb.eu'));

        $this->assertTrue($validator->isValid('project1.myapp.com'));
        $this->assertTrue($validator->isValid('project2.myapp.com'));
        $this->assertTrue($validator->isValid('project-with-dash.myapp.com'));
        $this->assertTrue($validator->isValid('anything.myapp.com'));
        $this->assertTrue($validator->isValid('commit.anything.myapp.com'));
        $this->assertFalse($validator->isValid('anything.myapp.eu'));
        $this->assertFalse($validator->isValid('myapp.com'));

        $validator = new Hostname(['localhost']);
        $this->assertTrue($validator->isValid('localhost'));
    }

    public function testCanValidateHostnamesWithWildcard(): void
    {
        $validator = new Hostname();
        $this->assertTrue($validator->isValid('*'));

        $validator = new Hostname(['netlify.*']);
        $this->assertFalse($validator->isValid('netlify.com'));
        $this->assertFalse($validator->isValid('netlify.eu'));
        $this->assertFalse($validator->isValid('netlify.app'));

        $validator = new Hostname(['*.*.app.io']);
        $this->assertFalse($validator->isValid('app.io'));
        $this->assertFalse($validator->isValid('project.app.io'));
        $this->assertFalse($validator->isValid('commit.project.app.io'));
        $this->assertFalse($validator->isValid('api.commit.project.app.io'));

        $validator = new Hostname(['*']);
        $this->assertTrue($validator->isValid('*'));
        $this->assertTrue($validator->isValid('localhost'));
        $this->assertTrue($validator->isValid('anything')); // Like localhost
        $this->assertTrue($validator->isValid('anything.com'));
        $this->assertTrue($validator->isValid('anything.with.subdomains.eu'));
    }
}
