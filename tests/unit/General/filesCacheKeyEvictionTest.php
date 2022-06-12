<?php

namespace Appwrite\Tests;

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../../app/init.php';

class filesCacheKeyEvictionTest extends TestCase
{

    protected string $basePath = APP_STORAGE_CACHE . '/purge_test/';
    protected int  $priod = 90;

    public function setUp(): void
    {

        if (!is_dir($this->basePath)) {
            \mkdir($this->basePath, 0755, true);
        }

        for ($i = 0; $i < $this->priod; $i++) {
             system('touch -t ' . (date( "Ymdhs", strtotime( "-". rand($this->priod+2, 9999). " days" ))) .  ' ' . $this->basePath . 'test_file0'. $i. '.txt');
             system('touch -t ' . (date( "Ymdhs", strtotime( "-". rand(1, $this->priod-2). " days" ))) .  ' ' . $this->basePath . 'test_file1'. $i. '.txt');
             }

    }

    public function tearDown(): void
    {
        $this->cleanUp();
    }

    public function testKeyEviction()
    {
        $expected = shell_exec('find '.$this->basePath.' -type f ! -mtime -'.$this->priod.' | wc -l');
        $this->assertEquals($this->priod, (int)$expected);

    }

   protected function onNotSuccessfulTest(\Throwable $t): void
    {
       $this->cleanUp();
       throw $t;
    }

    public function cleanUp(): void
    {
        if ( is_dir($this->basePath) ) {
            @system('rm -r ' . $this->basePath);
        }

    }

}
