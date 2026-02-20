<?php

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\Phone;
use PHPUnit\Framework\TestCase;

/**
 * Enhanced Phone Validator Test Suite
 *
 * Tests enhanced Phone validator with new validation features,
 * formatting options, and utility functions.
 */
class PhoneEnhancedTest extends TestCase
{
    protected ?Phone $object = null;

    public function setUp(): void
    {
        $this->object = new Phone();
    }

    public function testBasicValidation(): void
    {
        // Test basic E.164 validation
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid('+1'), false); // Too short
        $this->assertEquals($this->object->isValid('+1415555'), true); // Valid
        $this->assertEquals($this->object->isValid('+16308520394'), true); // Valid
        $this->assertEquals($this->object->isValid('+5511552563253'), true); // Valid
    }

    public function testCountryRestriction(): void
    {
        $phoneValidator = new Phone(
            false,  // allowEmpty
            ['US', 'GB', 'DE'],  // allowedCountries
            [],     // allowedTypes
            false,   // strictValidation
            'US'     // defaultRegion
        );

        // Test allowed countries
        $this->assertEquals($phoneValidator->isValid('+14155552671'), true); // US
        $this->assertEquals($phoneValidator->isValid('+4420718340000'), true); // GB
        $this->assertEquals($phoneValidator->isValid('+493012345678'), true); // DE

        // Test disallowed countries
        $this->assertEquals($phoneValidator->isValid('+919367788755111'), false); // IN (not allowed)
        $this->assertEquals($phoneValidator->isValid('+8613812345678'), false); // CN (not allowed)
    }

    public function testPhoneTypeRestriction(): void
    {
        $phoneValidator = new Phone(
            false,  // allowEmpty
            [],     // allowedCountries
            ['mobile', 'landline'],  // allowedTypes
            false,   // strictValidation
            'US'     // defaultRegion
        );

        // Test mobile numbers
        $this->assertEquals($phoneValidator->isValid('+14155551234'), true); // Mobile
        $this->assertEquals($phoneValidator->getPhoneNumberType('+14155551234'), 'mobile');

        // Test landline numbers
        $this->assertEquals($phoneValidator->isValid('+14155552345'), true); // Landline
        $this->assertEquals($phoneValidator->getPhoneNumberType('+14155552345'), 'landline');

        // Test toll-free (should be rejected)
        $this->assertEquals($phoneValidator->isValid('+18005551234'), false); // Toll-free
        $this->assertEquals($phoneValidator->getPhoneNumberType('+18005551234'), 'toll_free');
    }

    public function testStrictValidation(): void
    {
        $phoneValidator = new Phone(
            false,  // allowEmpty
            [],     // allowedCountries
            [],     // allowedTypes
            true,    // strictValidation
            'US'     // defaultRegion
        );

        // Test valid numbers
        $this->assertEquals($phoneValidator->isValid('+14155552671'), true);
        $this->assertEquals($phoneValidator->isValid('+16308520394'), true);

        // Test invalid ranges (fictional numbers)
        $this->assertEquals($phoneValidator->isValid('+14155550100'), false); // Fictional range
        $this->assertEquals($phoneValidator->isValid('+14155550199'), false); // Fictional range
    }

    public function testFormatToE164(): void
    {
        // Test various formats
        $this->assertEquals($this->object->formatToE164('+14155552671'), '+14155552671');
        $this->assertEquals($this->object->formatToE164('4155552671'), '+14155552671'); // US format
        $this->assertEquals($this->object->formatToE164('(415) 555-2671'), '+14155552671'); // US format with parentheses
        $this->assertEquals($this->object->formatToE164('+44 20 7183 4000'), '+4420718340000'); // UK format with spaces
        $this->assertEquals($this->object->formatToE164('invalid'), null); // Invalid
    }

    public function testFormatForInternational(): void
    {
        $this->assertEquals($this->object->formatForInternational('+14155552671'), '+1 415-555-2671');
        $this->assertEquals($this->object->formatForInternational('+4420718340000'), '+44 20 7183 4000');
        $this->assertEquals($this->object->formatForInternational('+493012345678'), '+49 30 12345678');
        $this->assertEquals($this->object->formatForInternational('invalid'), null);
    }

    public function testFormatForNational(): void
    {
        $this->assertEquals($this->object->formatForNational('+14155552671'), '(415) 555-2671');
        $this->assertEquals($this->object->formatForNational('+4420718340000'), '020 7183 4000');
        $this->assertEquals($this->object->formatForNational('+493012345678'), '030 12345678');
        $this->assertEquals($this->object->formatForNational('invalid'), null);
    }

    public function testGetPhoneNumberType(): void
    {
        // Test mobile numbers
        $this->assertEquals($this->object->getPhoneNumberType('+14155551234'), 'mobile');
        $this->assertEquals($this->object->getPhoneNumberType('+447700900000'), 'mobile');

        // Test landline numbers
        $this->assertEquals($this->object->getPhoneNumberType('+14155552345'), 'landline');
        $this->assertEquals($this->object->getPhoneNumberType('+4420718340000'), 'landline');

        // Test toll-free numbers
        $this->assertEquals($this->object->getPhoneNumberType('+18005551234'), 'toll_free');
        $this->assertEquals($this->object->getPhoneNumberType('+448001234567'), 'toll_free');

        // Test invalid
        $this->assertEquals($this->object->getPhoneNumberType('invalid'), null);
    }

    public function testGetCountryCode(): void
    {
        $this->assertEquals($this->object->getCountryCode('+14155552671'), 'US');
        $this->assertEquals($this->object->getCountryCode('+4420718340000'), 'GB');
        $this->assertEquals($this->object->getCountryCode('+493012345678'), 'DE');
        $this->assertEquals($this->object->getCountryCode('+33123456789'), 'FR');
        $this->assertEquals($this->object->getCountryCode('invalid'), null);
    }

    public function testGetPhoneInfo(): void
    {
        $info = $this->object->getPhoneInfo('+14155552671');

        $this->assertEquals($info['original'], '+14155552671');
        $this->assertEquals($info['e164'], '+14155552671');
        $this->assertEquals($info['international'], '+1 415-555-2671');
        $this->assertEquals($info['national'], '(415) 555-2671');
        $this->assertEquals($info['country_code'], '1');
        $this->assertEquals($info['region_code'], 'US');
        $this->assertEquals($info['national_number'], '4155552671');
        $this->assertEquals($info['type'], 'landline');
        $this->assertEquals($info['is_valid'], true);
        $this->assertEquals($info['is_possible'], true);
        $this->assertEquals($info['timezone'], 'America/New_York');

        // Test invalid phone
        $invalidInfo = $this->object->getPhoneInfo('invalid');
        $this->assertEquals($invalidInfo['original'], 'invalid');
        $this->assertEquals($invalidInfo['is_valid'], false);
        $this->assertArrayHasKey('error', $invalidInfo);
    }

    public function testValidateMultiple(): void
    {
        $phones = [
            '+14155552671',
            '+4420718340000',
            'invalid',
            '+493012345678',
            '+18005551234'
        ];

        $results = $this->object->validateMultiple($phones);

        $this->assertCount(5, $results);
        $this->assertEquals($results[0]['phone'], '+14155552671');
        $this->assertEquals($results[0]['is_valid'], true);
        $this->assertEquals($results[2]['phone'], 'invalid');
        $this->assertEquals($results[2]['is_valid'], false);
        $this->assertArrayHasKey('info', $results[0]);
    }

    public function testExtractFromText(): void
    {
        $text = 'Contact us at +1-415-555-2671 or +44 20 7183 4000. UK: 020 7183 4000. Mobile: 415-555-1234';
        
        $phones = $this->object->extractFromText($text);
        
        $this->assertContains('+14155552671', $phones);
        $this->assertContains('+4420718340000', $phones);
        $this->assertContains('+14155551234', $phones);
    }

    public function testIsMobile(): void
    {
        $this->assertTrue($this->object->isMobile('+14155551234'));
        $this->assertTrue($this->object->isMobile('+447700900000'));
        $this->assertFalse($this->object->isMobile('+14155552345')); // Landline
        $this->assertFalse($this->object->isMobile('+18005551234')); // Toll-free
    }

    public function testIsLandline(): void
    {
        $this->assertTrue($this->object->isLandline('+14155552345'));
        $this->assertTrue($this->object->isLandline('+4420718340000'));
        $this->assertFalse($this->object->isLandline('+14155551234')); // Mobile
        $this->assertFalse($this->object->isLandline('+18005551234')); // Toll-free
    }

    public function testIsTollFree(): void
    {
        $this->assertTrue($this->object->isTollFree('+18005551234'));
        $this->assertTrue($this->object->isTollFree('+448001234567'));
        $this->assertFalse($this->object->isTollFree('+14155551234')); // Mobile
        $this->assertFalse($this->object->isTollFree('+14155552345')); // Landline
    }

    public function testGetSupportedCountries(): void
    {
        $countries = $this->object->getSupportedCountries();
        
        $this->assertIsArray($countries);
        $this->assertContains('US', $countries);
        $this->assertContains('GB', $countries);
        $this->assertContains('DE', $countries);
        $this->assertContains('FR', $countries);
    }

    public function testGetCountryCallingCodes(): void
    {
        $codes = $this->object->getCountryCallingCodes();
        
        $this->assertIsArray($codes);
        $this->assertContains('1', $codes); // US/Canada
        $this->assertContains('44', $codes); // UK
        $this->assertContains('49', $codes); // Germany
        $this->assertContains('33', $codes); // France
    }

    public function testNormalize(): void
    {
        $this->assertEquals($this->object->normalize('+14155552671'), '+14155552671');
        $this->assertEquals($this->object->normalize('(415) 555-2671'), '+14155552671');
        $this->assertEquals($this->object->normalize('4155552671'), '+14155552671');
        $this->assertEquals($this->object->normalize('invalid'), null);
    }

    public function testGenerateExamples(): void
    {
        // Test US examples
        $usMobile = $this->object->generateExamples('US', 'mobile');
        $this->assertIsArray($usMobile);
        $this->assertContains('+14155552671', $usMobile);

        $usLandline = $this->object->generateExamples('US', 'landline');
        $this->assertIsArray($usLandline);
        $this->assertContains('+14155552671', $usLandline);

        $usTollFree = $this->object->generateExamples('US', 'toll_free');
        $this->assertIsArray($usTollFree);
        $this->assertContains('+18005552671', $usTollFree);

        // Test GB examples
        $gbMobile = $this->object->generateExamples('GB', 'mobile');
        $this->assertIsArray($gbMobile);
        $this->assertContains('+447700900000', $gbMobile);

        // Test invalid country/type
        $invalidExamples = $this->object->generateExamples('XX', 'invalid');
        $this->assertEquals($invalidExamples, []);
    }

    public function testGetDescription(): void
    {
        // Test basic description
        $basicValidator = new Phone();
        $description = $basicValidator->getDescription();
        $this->assertStringContainsString('E.164 format', $description);

        // Test with country restrictions
        $countryValidator = new Phone(false, ['US', 'GB']);
        $countryDescription = $countryValidator->getDescription();
        $this->assertStringContainsString('allowed countries: US, GB', $countryDescription);

        // Test with type restrictions
        $typeValidator = new Phone(false, [], ['mobile', 'landline']);
        $typeDescription = $typeValidator->getDescription();
        $this->assertStringContainsString('allowed types: mobile, landline', $typeDescription);

        // Test with extensions
        $extensionValidator = new Phone(false, [], [], false, false, 'US', true);
        $extensionDescription = $extensionValidator->getDescription();
        $this->assertStringContainsString('extensions allowed', $extensionDescription);

        // Test with strict validation
        $strictValidator = new Phone(false, [], [], true);
        $strictDescription = $strictValidator->getDescription();
        $this->assertStringContainsString('strict validation enabled', $strictDescription);
    }

    public function testAllowEmpty(): void
    {
        $emptyValidator = new Phone(true); // allowEmpty = true
        
        $this->assertEquals($emptyValidator->isValid(''), true);
        $this->assertEquals($emptyValidator->isValid('   '), false); // Not truly empty
        $this->assertEquals($emptyValidator->isValid('+14155552671'), true); // Still needs to be valid
    }

    public function testEdgeCases(): void
    {
        // Test edge cases
        $this->assertEquals($this->object->isValid('+'), false); // Only plus
        $this->assertEquals($this->object->isValid('+0123456789'), false); // Starts with 0
        $this->assertEquals($this->object->isValid('+1415555267123456789'), false); // Too long
        $this->assertEquals($this->object->isValid('14155552671'), false); // Missing plus
        $this->assertEquals($this->object->isValid('+141-555-52671'), false); // Invalid format
        $this->assertEquals($this->object->isValid('abc'), false); // Non-numeric
    }

    public function testInternationalFormats(): void
    {
        // Test various international formats
        $this->assertEquals($this->object->isValid('+4915112345678'), true); // Germany
        $this->assertEquals($this->object->isValid('+33123456789'), true); // France
        $this->assertEquals($this->object->isValid('+81312345678'), true); // Japan
        $this->assertEquals($this->object->isValid('+61212345678'), true); // Australia
        $this->assertEquals($this->object->isValid('+919367788755111'), true); // India
        $this->assertEquals($this->object->isValid('+8613812345678'), true); // China
        $this->assertEquals($this->object->isValid('+5511552563253'), true); // Brazil
    }

    public function testPerformance(): void
    {
        // Test performance with multiple validations
        $startTime = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $this->object->isValid('+14155552671');
            $this->object->getPhoneNumberType('+14155552671');
            $this->object->formatToE164('+14155552671');
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should complete 3000 operations in under 1 second
        $this->assertLessThan(1.0, $executionTime);
    }
}
