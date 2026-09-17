<?php

namespace Utopia\Agents\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Agent;

class AgentTest extends TestCase
{
    private Agent $agent;

    private Adapter $mockAdapter;

    protected function setUp(): void
    {
        // Create a mock adapter
        $this->mockAdapter = new OpenAI('test-api-key', OpenAI::MODEL_O3_MINI);
        $this->agent = new Agent($this->mockAdapter);
    }

    public function testConstructor(): void
    {
        $this->assertSame($this->mockAdapter, $this->agent->getAdapter());
        $this->assertSame('', $this->agent->getDescription());
        $this->assertSame([], $this->agent->getInstructions());
    }

    public function testSetDescription(): void
    {
        $description = 'Test agent description';

        $result = $this->agent->setDescription($description);

        $this->assertSame($this->agent, $result);
        $this->assertSame($description, $this->agent->getDescription());
    }

    public function testSetInstructions(): void
    {
        $instructions = [
            'Instruction 1' => 'This is instruction 1',
            'Instruction 2' => 'This is instruction 2',
        ];

        $result = $this->agent->setInstructions($instructions);

        $this->assertSame($this->agent, $result);
        $this->assertSame($instructions, $this->agent->getInstructions());
    }

    public function testAddInstruction(): void
    {
        // Test adding a single instruction
        $result = $this->agent->addInstruction('Instruction 1', 'This is instruction 1');
        $this->assertSame($this->agent, $result);
        $this->assertSame([
            'Instruction 1' => 'This is instruction 1',
        ], $this->agent->getInstructions());

        // Test adding a duplicate instruction (should update the content)
        $this->agent->addInstruction('Instruction 1', 'Updated content');
        $this->assertSame([
            'Instruction 1' => 'Updated content',
        ], $this->agent->getInstructions());

        // Test adding a second instruction
        $this->agent->addInstruction('Instruction 2', 'This is instruction 2');
        $this->assertSame([
            'Instruction 1' => 'Updated content',
            'Instruction 2' => 'This is instruction 2',
        ], $this->agent->getInstructions());
    }

    public function testFluentInterface(): void
    {
        $description = 'Test Description';
        $instructions = [
            'Instruction 1' => 'Content 1',
            'Instruction 2' => 'Content 2',
        ];

        $result = $this->agent
            ->setDescription($description)
            ->setInstructions($instructions)
            ->addInstruction('Instruction 3', 'Content 3');

        $this->assertSame($this->agent, $result);
        $this->assertSame($description, $this->agent->getDescription());
        $this->assertSame([
            'Instruction 1' => 'Content 1',
            'Instruction 2' => 'Content 2',
            'Instruction 3' => 'Content 3',
        ], $this->agent->getInstructions());
    }
}
