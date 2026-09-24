<?php

declare(strict_types=1);

/**
 * Utopia Http
 *
 * @package Http
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/http
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class URLTest extends TestCase
{
    protected ?URL $url;

    public function setUp(): void
    {
        $this->url = new URL();
    }

    public function tearDown(): void
    {
        $this->url = null;
    }

    public function testIsValid(): void
    {
        $this->assertSame('Value must be a valid URL', $this->url->getDescription());
        $this->assertTrue($this->url->isValid('http://example.com'));
        $this->assertTrue($this->url->isValid('https://example.com'));
        $this->assertTrue($this->url->isValid('htts://example.com')); // does not validate protocol
        $this->assertFalse($this->url->isValid('example.com')); // though, requires some kind of protocol
        $this->assertFalse($this->url->isValid('http:/example.com'));
        $this->assertTrue($this->url->isValid('http://exa-mple.com'));
        $this->assertFalse($this->url->isValid('htt@s://example.com'));
        $this->assertTrue($this->url->isValid('http://www.example.com/foo%2\u00c2\u00a9zbar'));
        $this->assertTrue($this->url->isValid('http://www.example.com/?q=%3Casdf%3E'));
        $this->assertTrue($this->url->isValid('https://example.com/callback#fragment'));
    }

    public function testIsValidAllowedSchemes(): void
    {
        $this->url = new URL(['http', 'https']);
        $this->assertSame('Value must be a valid URL with following schemes (http, https)', $this->url->getDescription());
        $this->assertTrue($this->url->isValid('http://example.com'));
        $this->assertTrue($this->url->isValid('https://example.com'));
        $this->assertFalse($this->url->isValid('gopher://www.example.com'));
    }

    public function testAllowEmpty(): void
    {
        $urlAllowEmpty = new URL([], true);
        $this->assertTrue($urlAllowEmpty->isValid(''));
        $this->assertFalse($urlAllowEmpty->isValid(null));
        $this->assertTrue($urlAllowEmpty->isValid('https://example.com'));
        $this->assertFalse($urlAllowEmpty->isValid('not-a-url'));

        $this->assertFalse($this->url->isValid(''));
        $this->assertFalse($this->url->isValid(null));
    }

    public function testDisallowFragments(): void
    {
        $urlWithoutFragments = new URL(allowFragments: false);

        $this->assertSame('Value must be a valid URL without a fragment component', $urlWithoutFragments->getDescription());
        $this->assertTrue($urlWithoutFragments->isValid('https://example.com/callback'));
        $this->assertFalse($urlWithoutFragments->isValid('https://example.com/callback#fragment'));
        $this->assertFalse($urlWithoutFragments->isValid('https://example.com/callback#'));
    }

    public function testDisallowFragmentsAllowedSchemes(): void
    {
        $urlWithoutFragments = new URL(['http', 'https'], allowFragments: false);

        $this->assertSame('Value must be a valid URL with following schemes (http, https) and without a fragment component', $urlWithoutFragments->getDescription());
        $this->assertTrue($urlWithoutFragments->isValid('https://example.com/callback'));
        $this->assertFalse($urlWithoutFragments->isValid('https://example.com/callback#fragment'));
        $this->assertFalse($urlWithoutFragments->isValid('gopher://www.example.com'));
    }

    public function testAllowPrivateUseSchemes(): void
    {
        // Default: private-use schemes are rejected (backward compatibility).
        $default = new URL();
        $this->assertFalse($default->isValid('com.raycast-x:/oauth'));
        $this->assertFalse($default->isValid('com.example.app:/oauth2redirect/example-provider'));

        $url = new URL(allowPrivateUseSchemes: true);

        // Happy path — RFC 8252 §7.1 private-use URI scheme redirect URIs.
        $this->assertTrue($url->isValid('com.raycast-x:/oauth'));
        $this->assertTrue($url->isValid('com.example.app:/oauth2redirect/example-provider'));
        $this->assertTrue($url->isValid('com.raycast-x:/oauth?state=abc'));   // query allowed
        $this->assertTrue($url->isValid('com.raycast-x:oauth'));              // path-rootless (opaque) form

        // Standard hierarchical URLs still validate through the normal path.
        $this->assertTrue($url->isValid('https://example.com/callback'));
        $this->assertTrue($url->isValid('http://127.0.0.1:8080/callback'));   // loopback redirect

        // Edge-case failures.
        $this->assertFalse($url->isValid('http:/example.com'));   // dotless standard scheme, no authority — still invalid
        $this->assertFalse($url->isValid('1com.raycast:/oauth')); // scheme must not start with a digit (RFC 3986)
        $this->assertFalse($url->isValid('com raycast:/oauth'));  // space in scheme
        $this->assertFalse($url->isValid(':/oauth'));             // missing scheme
        $this->assertFalse($url->isValid('/oauth'));              // no scheme at all
        $this->assertFalse($url->isValid('comraycast:/oauth'));   // no dot -> not treated as reverse-DNS private-use scheme
        $this->assertFalse($url->isValid('not a url'));
        $this->assertFalse($url->isValid(''));                    // allowEmpty not set
    }

    public function testAllowPrivateUseSchemesWithConstraints(): void
    {
        // Fragments forbidden must also apply to private-use schemes (RFC 6749 §3.1.2).
        $noFragments = new URL(allowFragments: false, allowPrivateUseSchemes: true);
        $this->assertTrue($noFragments->isValid('com.raycast-x:/oauth'));
        $this->assertFalse($noFragments->isValid('com.raycast-x:/oauth#frag'));

        // allowEmpty composes as before.
        $allowEmpty = new URL(allowEmpty: true, allowPrivateUseSchemes: true);
        $this->assertTrue($allowEmpty->isValid(''));
        $this->assertTrue($allowEmpty->isValid('com.raycast-x:/oauth'));

        // allowedSchemes still gates private-use schemes.
        $scoped = new URL(['com.raycast-x'], allowPrivateUseSchemes: true);
        $this->assertTrue($scoped->isValid('com.raycast-x:/oauth'));
        $this->assertFalse($scoped->isValid('com.evil-app:/oauth'));
    }

    public function testHttpsOrLoopback(): void
    {
        $url = new URL(allowFragments: false, allowPrivateUseSchemes: true, httpsOrLoopback: true);

        $this->assertSame(
            'Value must be a valid URL without a fragment component restricted to https or http on a loopback host',
            $url->getDescription(),
        );

        // Accepted.
        $this->assertTrue($url->isValid('https://app.example.com/callback'));
        $this->assertTrue($url->isValid('https://app.example.com/callback?foo=bar'));
        $this->assertTrue($url->isValid('http://localhost:6000/callback'));
        $this->assertTrue($url->isValid('http://127.0.0.1:6000/callback'));
        $this->assertTrue($url->isValid('http://[::1]:6000/callback'));
        $this->assertTrue($url->isValid('http://localhost/callback'));
        $this->assertTrue($url->isValid('com.example.app:/oauth'));

        // Rejected.
        $this->assertFalse($url->isValid('http://app.example.com/callback'));       // routable http
        $this->assertFalse($url->isValid('http://localhost.evil.com/callback'));    // loopback lookalike
        $this->assertFalse($url->isValid('ftp://app.example.com/callback'));        // non-http(s) standard scheme
        $this->assertFalse($url->isValid('https://app.example.com/callback#frag')); // fragment
        $this->assertFalse($url->isValid('com.example.app:/oauth#frag'));           // private-use with fragment
        $this->assertFalse($url->isValid('not a valid uri'));
        $this->assertFalse($url->isValid(''));
    }

    public function testHttpsOrLoopbackDefaultsToFalse(): void
    {
        // Flag off (default): routable http is still valid, and the transport clause
        // is absent from the description.
        $default = new URL();
        $this->assertSame('Value must be a valid URL', $default->getDescription());
        $this->assertTrue($default->isValid('http://app.example.com/callback'));

        // The flag is independent of and additive to allowedSchemes: both must hold.
        $both = new URL(['https'], httpsOrLoopback: true);
        $this->assertTrue($both->isValid('https://app.example.com/callback'));
        $this->assertFalse($both->isValid('http://localhost/callback')); // http not in allowedSchemes
    }
}
