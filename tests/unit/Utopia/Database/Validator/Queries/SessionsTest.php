<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Sessions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class SessionsTest extends TestCase
{
    public function testAllowedAttributes(): void
    {
        $validator = new Sessions();

        $this->assertTrue($validator->isValid([]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('ip', ['127.0.0.1'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('countryCode', ['DE'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('continentCode', ['EU'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('provider', ['email'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('userAgent', ['Chrome'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('osName', ['Windows'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('osCode', ['WIN'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('osVersion', ['10'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('clientType', ['browser'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('clientName', ['Chrome'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('clientCode', ['CH'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('deviceName', ['desktop'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('deviceBrand', ['Apple'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('deviceModel', ['MacBookPro18,3'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::equal('deviceId', ['device'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::greaterThan('expire', '2026-01-01 00:00:00')]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::contains('factors', ['email'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::orderDesc('$createdAt')]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::limit(10)]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::offset(10)]), $validator->getDescription());
    }

    public function testDisallowedAttributes(): void
    {
        $validator = new Sessions();

        $this->assertFalse($validator->isValid([Query::equal('userInternalId', ['user'])]), $validator->getDescription());
        $this->assertFalse($validator->isValid([Query::equal('deviceInternalId', ['device'])]), $validator->getDescription());
        $this->assertFalse($validator->isValid([Query::equal('secret', ['secret'])]), $validator->getDescription());
        $this->assertFalse($validator->isValid([Query::equal('unknown', ['value'])]), $validator->getDescription());
    }
}
