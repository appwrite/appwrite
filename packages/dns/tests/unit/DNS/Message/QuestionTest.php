<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Message;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

final class QuestionTest extends TestCase
{
    public function testConstructorSetsName(): void
    {
        $question = new Question('www.example.com', Record::TYPE_A, Record::CLASS_IN);

        $this->assertSame('www.example.com', $question->name);
    }

    public function testConstructorSetsNameCaseInsensitive(): void
    {
        $question = new Question('WWW.EXAMPLE.COM', Record::TYPE_A, Record::CLASS_IN);

        $this->assertSame('www.example.com', $question->name);
    }

    public function testEncodeProducesExactBytes(): void
    {
        $question = new Question('www.example.com', Record::TYPE_A, Record::CLASS_IN);

        $expected = "\x03www\x07example\x03com\x00\x00\x01\x00\x01"; // www.example.com IN A
        $this->assertSame($expected, $question->encode());
    }

    public function testDecodeParsesExpectedFields(): void
    {
        $data = "\x03api\x07example\x03com\x00\x00\x1C\x00\x01"; // api.example.com IN AAAA
        $offset = 0;

        $question = Question::decode($data, $offset);

        $this->assertSame('api.example.com', $question->name);
        $this->assertSame(Record::TYPE_AAAA, $question->type);
        $this->assertSame(Record::CLASS_IN, $question->class);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testDecodeHandlesCompressionPointer(): void
    {
        $offset = 0;
        $firstQuestion = "\x05first\x07example\x03com\x00\x00\x01\x00\x01"; // first.example.com IN A
        $pointerQuestion = "\xC0\x00\x00\x1C\x00\x01"; // pointer to first name, type AAAA
        $message = $firstQuestion . $pointerQuestion;

        $parsedFirst = Question::decode($message, $offset);
        $this->assertSame('first.example.com', $parsedFirst->name);
        $this->assertSame(Record::TYPE_A, $parsedFirst->type);
        $this->assertSame(Record::CLASS_IN, $parsedFirst->class);

        $parsedSecond = Question::decode($message, $offset);
        $this->assertSame('first.example.com', $parsedSecond->name);
        $this->assertSame(Record::TYPE_AAAA, $parsedSecond->type);
        $this->assertSame(Record::CLASS_IN, $parsedSecond->class);
        $this->assertSame(\strlen($message), $offset);
    }

    public function testConstructorTrimsWhitespaceFromName(): void
    {
        $question = new Question('  www.example.com  ', Record::TYPE_A, Record::CLASS_IN);

        $this->assertSame('www.example.com', $question->name);
    }

    public function testConstructorTrimsTabsAndNewlinesFromName(): void
    {
        $question = new Question("\t\nwww.example.com\r\n", Record::TYPE_A, Record::CLASS_IN);

        $this->assertSame('www.example.com', $question->name);
    }
}
