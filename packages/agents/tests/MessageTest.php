<?php

namespace Utopia\Agents\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Message;
use Utopia\Agents\Parts\Image;
use Utopia\Agents\Parts\Reasoning;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;
use Utopia\Agents\Role;
use Utopia\Agents\Usage;

class MessageTest extends TestCase
{
    public function testFactoriesSetRoleAndParts(): void
    {
        $this->assertSame(Role::System, Message::system('Be brief')->role);
        $this->assertSame('Be brief', Message::system('Be brief')->text());

        $image = new Image('bytes', 'image/png');
        $user = Message::user([new Text('What is this?'), $image]);
        $this->assertSame(Role::User, $user->role);
        $this->assertSame([$image], array_values(array_filter($user->parts, fn ($part) => $part instanceof Image)));
    }

    public function testAccessorsSplitPartsByKind(): void
    {
        $call = new ToolCall('call_1', 'weather', ['city' => 'Berlin']);
        $message = Message::assistant([new Reasoning('Think. '), new Text('Checking '), new Text('now.'), $call]);

        $this->assertSame('Checking now.', $message->text());
        $this->assertSame('Think. ', $message->reasoning());
        $this->assertSame([$call], $message->toolCalls());
    }

    public function testToolResultTextEncodesNonStrings(): void
    {
        $this->assertSame('sunny', (new ToolResult('1', 'weather', 'sunny'))->text());
        $this->assertSame('{"temp":21.0,"url":"https://x.io/a"}', (new ToolResult('1', 'weather', ['temp' => 21.0, 'url' => 'https://x.io/a']))->text());

        $results = Message::tool([new ToolResult('1', 'weather', 'sunny')]);
        $this->assertSame(Role::Tool, $results->role);
        $this->assertCount(1, $results->toolResults());
    }

    public function testImageNeedsDataOrUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Image();
    }

    public function testUsageAddsAndTotals(): void
    {
        $usage = (new Usage(10, 5, cacheRead: 2))->add(new Usage(1, 1, cacheWrite: 3, reasoning: 1));

        $this->assertSame(11, $usage->input);
        $this->assertSame(6, $usage->output);
        $this->assertSame(2, $usage->cacheRead);
        $this->assertSame(3, $usage->cacheWrite);
        $this->assertSame(1, $usage->reasoning);
        $this->assertSame(17, $usage->total());
    }
}
