<?php

declare(strict_types=1);

namespace Tests\Unit\CI;

use PHPUnit\Framework\TestCase;

final class SuiteCoverageTest extends TestCase
{
    private string $directory;

    private string $output = "";

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpunit-coverage-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/tests/unit', 0777, true);
        mkdir($this->directory . '/tests/e2e', 0777, true);
        file_put_contents($this->directory . '/phpunit.xml', '<phpunit><testsuites><testsuite name="unit"><directory>tests/unit</directory></testsuite></testsuites></phpunit>');
        file_put_contents($this->directory . '/tests/unit/ExampleTest.php', '<?php final class ExampleTest extends \\PHPUnit\\Framework\\TestCase { public function testExample(): void { $this->assertTrue(true); } }');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testConfiguredSuiteIsRunnableAndANewDirectoryCannotDisappear(): void
    {
        $this->assertSame(0, $this->check());
        file_put_contents($this->directory . '/tests/e2e/AddedTest.php', '<?php final class AddedTest extends \\PHPUnit\\Framework\\TestCase {}');
        $this->assertSame(1, $this->check());
        $xml = file_get_contents($this->directory . '/phpunit.xml');
        file_put_contents($this->directory . '/phpunit.xml', str_replace('</testsuites>', '<testsuite name="e2e-added"><directory>tests/e2e</directory></testsuite></testsuites>', $xml));
        $this->assertSame(0, $this->check());
    }

    public function testMisnamedClassWithOnlyInheritedMethodsIsRejected(): void
    {
        file_put_contents($this->directory . '/tests/unit/Base.php', '<?php abstract class Base extends \\PHPUnit\\Framework\\TestCase { public function testInherited(): void { $this->assertTrue(true); } }');
        file_put_contents($this->directory . '/tests/unit/WrongName.php', '<?php final class WrongName extends Base {}');
        $this->assertSame(1, $this->check());
        unlink($this->directory . '/tests/unit/WrongName.php');
        file_put_contents($this->directory . '/tests/unit/InheritedTest.php', '<?php require_once __DIR__ . "/Base.php"; final class InheritedTest extends Base {}');
        $this->assertSame(0, $this->check());
        $this->assertSame(0, $this->command([
            PHP_BINARY, getcwd() . '/vendor/bin/phpunit', '--no-configuration',
            '--bootstrap', getcwd() . '/vendor/autoload.php',
            $this->directory . '/tests/unit/InheritedTest.php', '--list-tests-xml', $this->directory . '/expected.xml',
        ]));
        $this->assertStringContainsString('InheritedTest::testInherited', file_get_contents($this->directory . '/expected.xml'));
    }

    public function testAClassThatPhpunitCannotLoadFromItsFilenameIsRejected(): void
    {
        file_put_contents($this->directory . '/tests/unit/ExampleTest.php', '<?php final class DifferentTest extends \\PHPUnit\\Framework\\TestCase {}');
        $this->assertSame(1, $this->check());
    }

    public function testDuplicateMembershipCannotInflateCoverage(): void
    {
        $xml = file_get_contents($this->directory . '/phpunit.xml');
        file_put_contents($this->directory . '/phpunit.xml', str_replace('</testsuites>', '<testsuite name="duplicate"><directory>tests/unit</directory></testsuite></testsuites>', $xml));
        $this->assertSame(1, $this->check());
    }

    public function testMissingDirectoryIsNotAnEmptySuccessfulSuite(): void
    {
        unlink($this->directory . '/tests/unit/ExampleTest.php');
        rmdir($this->directory . '/tests/unit');
        $this->assertSame(1, $this->check());
    }

    public function testResultsDetectMissingCasesAndSkippedAssertions(): void
    {
        $file = $this->directory . '/tests/unit/ExampleTest.php';
        file_put_contents($file, <<<'FIXTURE'
<?php
final class ExampleTest extends \PHPUnit\Framework\TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testCase(string $value): void { $this->assertNotSame('', $value); }
    public static function values(): array { return ['one' => ['first'], ['second']]; }
    public function testUnavailable(): void { $this->markTestSkipped('fixture'); }
}
FIXTURE);
        $phpunit = [PHP_BINARY, getcwd() . '/vendor/bin/phpunit', '--no-configuration', '--bootstrap', getcwd() . '/vendor/autoload.php', $file];
        $expected = $this->directory . '/expected.xml';
        $actual = $this->directory . '/junit.xml';
        $this->assertSame(0, $this->command([...$phpunit, '--list-tests-xml', $expected]));
        $this->assertSame(0, $this->command([...$phpunit, '--log-junit', $actual]));
        $verify = [PHP_BINARY, dirname(__DIR__, 3) . '/tests/tools/results.php', $expected, $actual];
        $this->assertSame(0, $this->command($verify), $this->output);
        $this->assertSame(1, $this->command([...$verify, '--fail-on-skipped']));
        $document = new \DOMDocument();
        $document->load($actual);
        $case = $document->getElementsByTagName('testcase')->item(0);
        $case->parentNode->removeChild($case);
        $document->save($actual);
        $this->assertSame(1, $this->command($verify));
    }

    public function testAnInterruptedRunnerCannotReportSuccess(): void
    {
        $file = $this->directory . '/tests/unit/ExampleTest.php';
        $started = $this->directory . '/started';
        file_put_contents($file, '<?php final class ExampleTest extends \\PHPUnit\\Framework\\TestCase { public function testInterrupted(): void { file_put_contents(' . var_export($started, true) . ', "ready"); sleep(30); $this->assertTrue(true); } }');
        $arguments = [PHP_BINARY, getcwd() . '/vendor/bin/phpunit', '--no-configuration', '--bootstrap', getcwd() . '/vendor/autoload.php', $file];
        $expected = $this->directory . '/expected.xml';
        $actual = $this->directory . '/junit.xml';
        $this->assertSame(0, $this->command([...$arguments, '--list-tests-xml', $expected]));
        $process = proc_open([...$arguments, '--log-junit', $actual], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, getcwd());
        $this->assertIsResource($process);
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($started) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($started);
        } finally {
            proc_terminate($process);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
        }
        $this->assertNotSame(0, $status);
        $this->assertSame(1, $this->command([PHP_BINARY, dirname(__DIR__, 3) . '/tests/tools/results.php', $expected, $actual]));
    }

    private function check(): int
    {
        return $this->command([PHP_BINARY, dirname(__DIR__, 3) . '/tests/tools/suites.php', $this->directory . '/phpunit.xml']);
    }

    private function command(array $arguments): int
    {
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, getcwd());
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $this->output = $output;
        $this->assertIsString($output);

        return $status;
    }
}
