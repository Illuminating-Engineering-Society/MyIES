<?php
/**
 * Tests for Wicket API Helper - verifies critical fixes
 */

use PHPUnit\Framework\TestCase;

class WicketApiHelperTest extends TestCase
{
    private $api;

    protected function setUp(): void
    {
        // Reset singleton for each test
        $reflection = new ReflectionClass(Wicket_API_Helper::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $this->api = Wicket_API_Helper::get_instance();
    }

    // =========================================================================
    // SINGLETON PATTERN TESTS (#20)
    // =========================================================================

    public function testSingletonReturnsSameInstance(): void
    {
        $instance1 = Wicket_API_Helper::get_instance();
        $instance2 = Wicket_API_Helper::get_instance();
        $this->assertSame($instance1, $instance2);
    }

    public function testSingletonPreventsCloning(): void
    {
        $reflection = new ReflectionMethod(Wicket_API_Helper::class, '__clone');
        $this->assertTrue($reflection->isPrivate(), '__clone() should be private');
    }

    public function testSingletonPreventsUnserialization(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');
        $this->api->__wakeup();
    }

    // =========================================================================
    // JWT TOKEN CACHING TESTS (#5)
    // =========================================================================

    public function testJwtTokenGeneration(): void
    {
        $token = $this->api->generate_jwt_token();
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        // JWT should have 3 parts
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT token should have 3 parts (header.payload.signature)');
    }

    public function testJwtTokenIsCached(): void
    {
        $token1 = $this->api->generate_jwt_token();
        $token2 = $this->api->generate_jwt_token();
        $this->assertSame($token1, $token2, 'Second call should return cached token');
    }

    public function testJwtPayloadContainsRequiredClaims(): void
    {
        $token = $this->api->generate_jwt_token();
        $parts = explode('.', $token);

        // Decode payload (add padding back)
        $payload_b64 = $parts[1];
        $payload_b64 = str_replace(['-', '_'], ['+', '/'], $payload_b64);
        $payload_b64 = str_pad($payload_b64, strlen($payload_b64) % 4 === 0 ? strlen($payload_b64) : strlen($payload_b64) + (4 - strlen($payload_b64) % 4), '=');
        $payload = json_decode(base64_decode($payload_b64), true);

        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('sub', $payload);
        $this->assertArrayHasKey('aud', $payload);
        $this->assertArrayHasKey('iss', $payload);
        $this->assertEquals('test-admin-uuid-1234', $payload['sub']);
        $this->assertGreaterThan(time(), $payload['exp']);
    }

    // =========================================================================
    // TENANT VALIDATION TESTS (#18)
    // =========================================================================

    public function testGetApiUrlReturnsValidUrl(): void
    {
        $url = $this->api->get_api_url();
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('wicketcloud.com', $url);
    }

    // =========================================================================
    // SAFE JSON DECODE TESTS (#9)
    // =========================================================================

    public function testSafeJsonDecodeWithValidJson(): void
    {
        $method = new ReflectionMethod(Wicket_API_Helper::class, 'safe_json_decode');
        $method->setAccessible(true);

        $result = $method->invoke($this->api, '{"data": [{"id": "123"}]}');
        $this->assertIsArray($result);
        $this->assertEquals('123', $result['data'][0]['id']);
    }

    public function testSafeJsonDecodeWithInvalidJson(): void
    {
        $method = new ReflectionMethod(Wicket_API_Helper::class, 'safe_json_decode');
        $method->setAccessible(true);

        $result = $method->invoke($this->api, 'not valid json {{{');
        $this->assertNull($result, 'Invalid JSON should return null');
    }

    public function testSafeJsonDecodeWithEmptyString(): void
    {
        $method = new ReflectionMethod(Wicket_API_Helper::class, 'safe_json_decode');
        $method->setAccessible(true);

        $result = $method->invoke($this->api, '');
        $this->assertNull($result, 'Empty string should return null');
    }

    public function testSafeJsonDecodeWithNull(): void
    {
        $method = new ReflectionMethod(Wicket_API_Helper::class, 'safe_json_decode');
        $method->setAccessible(true);

        $result = $method->invoke($this->api, null);
        $this->assertNull($result, 'Null should return null');
    }

    // =========================================================================
    // SAFE JSON ENCODE TESTS (#30)
    // =========================================================================

    public function testSafeJsonEncodeWithValidData(): void
    {
        $method = new ReflectionMethod(Wicket_API_Helper::class, 'safe_json_encode');
        $method->setAccessible(true);

        $result = $method->invoke($this->api, ['name' => 'test']);
        $this->assertIsString($result);
        $this->assertEquals('{"name":"test"}', $result);
    }

    // =========================================================================
    // UUID LOOKUP TESTS (#4)
    // =========================================================================

    public function testGetPersonUuidFromPrimaryKey(): void
    {
        // User 1 has wicket_person_uuid set
        $uuid = $this->api->get_person_uuid(1);
        $this->assertEquals('uuid-1234', $uuid);
    }

    public function testGetPersonUuidFallsBackToWicketUuid(): void
    {
        // User 2 only has wicket_uuid set
        $uuid = $this->api->get_person_uuid(2);
        $this->assertEquals('uuid-5678', $uuid);
    }

    public function testGetPersonUuidReturnsNullForUnknownUser(): void
    {
        $uuid = $this->api->get_person_uuid(999);
        $this->assertNull($uuid);
    }

    public function testGetPersonUuidCachesResult(): void
    {
        $uuid1 = $this->api->get_person_uuid(1);
        $uuid2 = $this->api->get_person_uuid(1);
        $this->assertSame($uuid1, $uuid2);
    }

    // =========================================================================
    // FORM ID CONSTANTS TESTS (#15)
    // =========================================================================

    public function testFormIdConstantsAreDefined(): void
    {
        $this->assertTrue(defined('MYIES_FORM_PERSONAL_DETAILS'));
        $this->assertTrue(defined('MYIES_FORM_PROFESSIONAL_INFO'));
        $this->assertTrue(defined('MYIES_FORM_ADDRESS'));
        $this->assertTrue(defined('MYIES_FORM_CONTACT_DETAILS'));
        $this->assertTrue(defined('MYIES_FORM_ADDITIONAL_INFO_EDUCATION'));
        $this->assertTrue(defined('MYIES_FORM_ORGANIZATION_INFO'));
        $this->assertTrue(defined('MYIES_FORM_COMMUNICATION_PREFS'));
    }

    public function testFormIdConstantsHaveIntegerValues(): void
    {
        $this->assertIsInt(MYIES_FORM_PERSONAL_DETAILS);
        $this->assertIsInt(MYIES_FORM_PROFESSIONAL_INFO);
        $this->assertIsInt(MYIES_FORM_ADDRESS);
        $this->assertIsInt(MYIES_FORM_CONTACT_DETAILS);
        $this->assertIsInt(MYIES_FORM_ADDITIONAL_INFO_EDUCATION);
        $this->assertIsInt(MYIES_FORM_ORGANIZATION_INFO);
        $this->assertIsInt(MYIES_FORM_COMMUNICATION_PREFS);
    }

    // =========================================================================
    // MYIES_LOG FUNCTION TESTS (#38)
    // =========================================================================

    public function testMyiesLogFunctionExists(): void
    {
        $this->assertTrue(function_exists('myies_log'));
    }

    public function testWicketApiFunctionExists(): void
    {
        $this->assertTrue(function_exists('wicket_api'));
    }

    // =========================================================================
    // TIMEOUT CONFIGURATION TEST (#29)
    // =========================================================================

    public function testGetTimeoutReturnsFilterableValue(): void
    {
        $method = new ReflectionMethod(Wicket_API_Helper::class, 'get_timeout');
        $method->setAccessible(true);

        $timeout = $method->invoke($this->api, 30);
        $this->assertEquals(30, $timeout);

        $timeout = $method->invoke($this->api, 60);
        $this->assertEquals(60, $timeout);
    }
}
