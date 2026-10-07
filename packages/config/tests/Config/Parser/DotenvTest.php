<?php

declare(strict_types=1);

namespace Utopia\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Utopia\Config\Attribute\Key;
use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser\Dotenv;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

final class DotenvTest extends TestCase
{
    protected Dotenv $parser;

    protected function setUp(): void
    {
        $this->parser = new Dotenv();
    }

    protected function tearDown(): void {}

    public function testDotenvBasicTypes(): void
    {
        $dotenv = <<<DOTENV
          STRING=hello world
          UNICODE_STRING=ä你こحب🌍
          INTEGER=42
          FLOAT=3.14159
          NEGATIVE=-50
          BOOLEAN_TRUE=true
          BOOLEAN_FALSE=false
          NULL_VALUE=null
        DOTENV;

        $data = $this->parser->parse($dotenv);

        $this->assertSame('hello world', $data['STRING']);
        $this->assertSame('ä你こحب🌍', $data['UNICODE_STRING']);
        $this->assertSame('42', $data['INTEGER']);
        $this->assertSame('3.14159', $data['FLOAT']);
        $this->assertSame('-50', $data['NEGATIVE']);

        $this->assertSame('true', $data['BOOLEAN_TRUE']);
        $this->assertSame('false', $data['BOOLEAN_FALSE']);
        $this->assertNull($data['NULL_VALUE']);
    }

    public function testDotenvParseExceptionInvalid(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse('=b');
    }

    public function testDotenvParseExceptionNumber(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(12);
    }

    public function testDotenvParseExceptionBoolean(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(false);
    }

    public function testDotenvParseExceptionNull(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse(null);
    }

    public function testDotenvEdgeCases(): void
    {
        $data = $this->parser->parse('');
        $this->assertCount(0, $data);
        $data = $this->parser->parse('KEY=');
        $this->assertCount(1, $data);
        $this->assertSame('', $data['KEY']);
    }

    public function testDotenvComment(): void
    {
        $data = $this->parser->parse(
            <<<DOTENV
            HOST=127.0.0.1
            PORT=3306 # A comment
            # Another comment, with empty line below intentionally

            PASSWORD=secret
            DOTENV
        );

        $this->assertSame('127.0.0.1', $data['HOST']);
        $this->assertSame('3306', $data['PORT']);
        $this->assertSame('secret', $data['PASSWORD']);
        $this->assertCount(3, array_keys($data));
        $this->assertArrayNotHasKey('PASSWORD2', $data);
    }

    public function testDotenvLineWithoutEqualsThrows(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse("HOST=127.0.0.1\nDATABASE_PASSWORD");
    }

    public function testDotenvHashInsideQuotesPreserved(): void
    {
        $data = $this->parser->parse(
            <<<DOTENV
            PASSWORD="abc#123"
            TOKEN='x#y#z'
            URL="https://example.com/path#fragment"
            PLAIN=value # trailing comment
            DOTENV
        );

        $this->assertSame('abc#123', $data['PASSWORD']);
        $this->assertSame('x#y#z', $data['TOKEN']);
        $this->assertSame('https://example.com/path#fragment', $data['URL']);
        $this->assertSame('value', $data['PLAIN']);
    }

    public function testDotenvEscapedQuoteInsideValue(): void
    {
        $data = $this->parser->parse('KEY="abc\"#def"');
        $this->assertSame('abc"#def', $data['KEY']);
    }

    public function testDotenvLiteralBackslashPreserved(): void
    {
        // \t is not a supported escape, so the backslash is kept verbatim
        // (e.g. a Windows path); \\ collapses to a single backslash.
        $data = $this->parser->parse("PATH=\"C:\\tmp\"\nESCAPED=\"a\\\\b\"");
        $this->assertSame('C:\tmp', $data['PATH']);
        $this->assertSame('a\b', $data['ESCAPED']);
    }

    public function testDotenvTrailingGarbageAfterQuoteThrows(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse('KEY="prod"oops');
    }

    public function testDotenvUnterminatedQuoteThrows(): void
    {
        $this->expectException(Parse::class);
        $this->parser->parse('KEY="abc');
    }

    public function testValueConvertor(): void
    {
        $data = $this->parser->parse(
            <<<DOTENV
            KEY1=1 # Becomes true
            KEY2=on # Becomes true
            KEY3=enabled # Becomes true
            KEY4=Enabled # Becomes true
            KEY5=true # Becomes true
            KEY6=TRUE # Becomes true
            KEY7=yes # Becomes true

            KEY8=0 # Becomes false
            KEY9=off # Becomes false
            KEY10=disabled # Becomes false
            KEY11=Disabled # Becomes false
            KEY12=false # Becomes false
            KEY13=FALSE # Becomes false
            KEY14=no # Becomes false

            KEY15=11  # Preserves value
            KEY16=20  # Preserves value

            KEY17=online # Preserves value
            KEY18=offline # Preserves value

            KEY19=notenabled # Preserves value
            KEY20=notdisabled # Preserves value

            KEY21=yesterday # Preserves value
            KEY22=november # Preserves value

            KEY23=agree # Preserves value
            KEY24=disagree # Preserves value
            
            KEY25=1
            KEY26=0
            KEY27=null

            DOTENV,
            new \ReflectionClass(TestValueConvertorConfig::class),
        );

        $expectedValues = [
            'KEY1' => true,
            'KEY2' => true,
            'KEY3' => true,
            'KEY4' => true,
            'KEY5' => true,
            'KEY6' => true,
            'KEY7' => true,

            'KEY8' => false,
            'KEY9' => false,
            'KEY10' => false,
            'KEY11' => false,
            'KEY12' => false,
            'KEY13' => false,
            'KEY14' => false,

            'KEY15' => '11',
            'KEY16' => '20',
            'KEY17' => 'online',
            'KEY18' => 'offline',
            'KEY19' => 'notenabled',
            'KEY20' => 'notdisabled',
            'KEY21' => 'yesterday',
            'KEY22' => 'november',
            'KEY23' => 'agree',
            'KEY24' => 'disagree',

            'KEY25' => '1',
            'KEY26' => '0',
            'KEY27' => null,
        ];

        $this->assertCount(\count($expectedValues), array_keys($data));

        foreach ($expectedValues as $key => $value) {
            $this->assertSame($value, $data[$key], "Failed for key: $key");
        }
    }
}

class TestValueConvertorConfig
{
    #[Key('KEY1', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key1;

    #[Key('KEY2', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key2;

    #[Key('KEY3', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key3;

    #[Key('KEY4', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key4;

    #[Key('KEY5', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key5;

    #[Key('KEY6', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key6;

    #[Key('KEY7', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key7;

    #[Key('KEY8', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key8;

    #[Key('KEY9', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key9;

    #[Key('KEY10', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key10;

    #[Key('KEY11', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key11;

    #[Key('KEY12', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key12;

    #[Key('KEY13', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key13;

    #[Key('KEY14', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key14;

    #[Key('KEY15', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key15;

    #[Key('KEY16', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key16;

    #[Key('KEY17', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key17;

    #[Key('KEY18', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key18;

    #[Key('KEY19', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key19;

    #[Key('KEY20', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key20;

    #[Key('KEY21', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key21;

    #[Key('KEY22', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key22;

    #[Key('KEY23', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key23;

    #[Key('KEY24', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key24;

    #[Key('KEY25', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public string $key25;

    #[Key('KEY26', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public string $key26;

    #[Key('KEY27', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public string $key27;
}
