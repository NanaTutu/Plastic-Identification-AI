<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class PredictionsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PLASTICID_API_KEY=test-api-key');
    }

    protected function tearDown(): void
    {
        putenv('PLASTICID_API_KEY');
        parent::tearDown();
    }

    public function testStoreReturns401WithoutApiKey(): void
    {
        $result = $this->withBody(json_encode(['job_id' => 'test-123']))
            ->post('api/predictions');

        $result->assertStatus(401);
    }

    public function testStoreReturns401WithWrongApiKey(): void
    {
        $result = $this->withHeaders(['X-API-KEY' => 'wrong-key'])
            ->withBody(json_encode(['job_id' => 'test-123']))
            ->post('api/predictions');

        $result->assertStatus(401);
    }

    public function testStoreReturns400WithoutJobId(): void
    {
        $result = $this->withHeaders(['X-API-KEY' => 'test-api-key'])
            ->withBody(json_encode([]))
            ->post('api/predictions');

        $result->assertStatus(400);
    }

    public function testGetKeysReturns401WithoutApiKey(): void
    {
        $result = $this->get('api/get_keys');

        $result->assertStatus(401);
    }

    public function testHealthCheck(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
    }
}
