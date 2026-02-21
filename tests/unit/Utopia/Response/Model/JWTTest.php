<?php

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\JWT;
use PHPUnit\Framework\TestCase;

class JWTTest extends TestCase
{
    private JWT $jwt;

    public function setUp(): void
    {
        $this->jwt = new JWT();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(JWT::class, $this->jwt);
    }

    public function testSetAndGetToken(): void
    {
        $token = 'test.jwt.token';
        $this->jwt->setToken($token);
        $this->assertEquals($token, $this->jwt->getToken());
    }

    public function testSetAndGetPayload(): void
    {
        $payload = ['user_id' => 123, 'role' => 'admin'];
        $this->jwt->setPayload($payload);
        $this->assertEquals($payload, $this->jwt->getPayload());
    }

    public function testSetAndGetAlgorithm(): void
    {
        $algorithm = 'HS256';
        $this->jwt->setAlgorithm($algorithm);
        $this->assertEquals($algorithm, $this->jwt->getAlgorithm());
    }

    public function testSetAndGetType(): void
    {
        $type = 'access';
        $this->jwt->setType($type);
        $this->assertEquals($type, $this->jwt->getType());
    }

    public function testSetAndGetExpiresIn(): void
    {
        $expiresIn = 7200; // 2 hours
        $this->jwt->setExpiresIn($expiresIn);
        $this->assertEquals($expiresIn, $this->jwt->getExpiresIn());
    }

    public function testSetAndGetIssuer(): void
    {
        $issuer = 'https://api.example.com';
        $this->jwt->setIssuer($issuer);
        $this->assertEquals($issuer, $this->jwt->getIssuer());
    }

    public function testSetAndGetAudience(): void
    {
        $audience = 'my-app-client';
        $this->jwt->setAudience($audience);
        $this->assertEquals($audience, $this->jwt->getAudience());
    }

    public function testSetAndGetSubject(): void
    {
        $subject = 'user123';
        $this->jwt->setSubject($subject);
        $this->assertEquals($subject, $this->jwt->getSubject());
    }

    public function testGetIssuedAt(): void
    {
        $payload = ['user_id' => 123, 'iat' => time() - 3600]; // Issued 1 hour ago
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = 'test-signature';
        $jwt = $header . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($jwt);
        $this->assertEquals(time() - 3600, $this->jwt->getIssuedAt());
    }

    public function testGetNotBefore(): void
    {
        $payload = ['user_id' => 123, 'nbf' => time() + 3600]; // Not valid before 1 hour from now
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = 'test-signature';
        $jwt = $header . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($jwt);
        $this->assertEquals(time() + 3600, $this->jwt->getNotBefore());
    }

//     public function testGetNotBefore(): void{
//  $decoded = $this->jwt->decodePayload($jwt);
//         $this->assertEquals($payload, $decoded);
        
//         // Test with invalid JWT
//         $this->assertNull($this->jwt->decodePayload('invalid'));
        
//         // Test with malformed JWT
//         $this->assertNull($this->jwt->decodePayload('header.payload'));
//     }

    public function testGetHeaders(): void
    {
        $payload = ['user_id' => 123];
        $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'test-key-id'];
        $headerEncoded = base64_encode(json_encode($header));
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = 'test-signature';
        $jwt = $headerEncoded . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($jwt);
        $headers = $this->jwt->getHeaders();
        
        $this->assertIsArray($headers);
        $this->assertEquals('HS256', $headers['alg']);
        $this->assertEquals('JWT', $headers['typ']);
        $this->assertEquals('test-key-id', $headers['kid']);
    }

    public function testToArray(): void
    {
        $this->jwt->setToken('test.jwt.token');
        $this->jwt->setPayload(['user_id' => 123]);
        $this->jwt->setAlgorithm('HS256');
        $this->jwt->setIssuer('https://api.example.com');
        $this->jwt->setAudience('my-app');
        $this->jwt->setSubject('user123');
        $this->jwt->setExpiresIn(3600);
        
        $array = $this->jwt->toArray();
        
        $this->assertEquals('test.jwt.token', $array['token']);
        $this->assertEquals(['user_id' => 123], $array['payload']);
        $this->assertEquals('HS256', $array['algorithm']);
        $this->assertEquals('JWT', $array['type']);
        $this->assertEquals('https://api.example.com', $array['issuer']);
        $this->assertEquals('my-app', $array['audience']);
        $this->assertEquals('user123', $array['subject']);
        $this->assertEquals(3600, $array['expiresIn']);
        $this->assertIsArray($array['headers']);
        $this->assertNotNull($array['issuedAt']);
        $this->assertNotNull($array['notBefore']);
        $this->assertFalse($array['isExpired']);
    }

    public function testFullValidation(): void
    {
        // Test completely valid JWT
        $validPayload = ['user_id' => 123, 'exp' => time() + 3600, 'iss' => 'https://api.example.com', 'aud' => 'my-app'];
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = base64_encode(json_encode($validPayload));
        $signature = 'test-signature';
        $validJWT = $header . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($validJWT);
        $this->jwt->setAlgorithm('HS256');
        $this->jwt->setIssuer('https://api.example.com');
        $this->jwt->setAudience('my-app');
        $this->jwt->setSubject('user123');
        
        $this->assertTrue($this->jwt->isValid($validJWT));
        
        // Test expired JWT
        $expiredPayload = ['user_id' => 123, 'exp' => time() - 3600, 'iss' => 'https://api.example.com', 'aud' => 'my-app'];
        $payloadEncoded = base64_encode(json_encode($expiredPayload));
        $expiredJWT = $header . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($expiredJWT);
        $this->assertFalse($this->jwt->isValid($expiredJWT));
        
        // Test JWT with wrong algorithm
        $wrongAlgPayload = ['user_id' => 123, 'alg' => 'RS256'];
        $payloadEncoded = base64_encode(json_encode($wrongAlgPayload));
        $wrongAlgJWT = $header . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($wrongAlgJWT);
        $this->assertFalse($this->jwt->isValid($wrongAlgJWT));
        
        // Test JWT with wrong type
        $wrongTypePayload = ['user_id' => 123, 'typ' => 'JWS'];
        $payloadEncoded = base64_encode(json_encode($wrongTypePayload));
        $wrongTypeJWT = $header . '.' . $payloadEncoded . '.' . $signature;
        
        $this->jwt->setToken($wrongTypeJWT);
        $this->assertFalse($this->jwt->isValid($wrongTypeJWT));
    }
}
