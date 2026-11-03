<?php

namespace Utopia\Agents;

use Utopia\Agents\Events\Finish;
use Utopia\Agents\Events\StepFinish;
use Utopia\Agents\Events\ToolResult as ToolResultEvent;
use Utopia\Agents\Exception\Output;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;

/**
 * A model with instructions, tools and settings that runs a tool loop.
 *
 * Each step sends the conversation to the model. When the model calls tools,
 * the agent runs them, appends their results and steps again, until the
 * model answers without calling a tool, a called tool has no handler, the
 * step limit is reached or the stop condition holds.
 *
 * Tool failures do not abort the run: an unknown tool, arguments that do not
 * match the tool's schema or an exception from the handler become an error
 * result the model can react to.
 */
class Agent
{
    /**
     * @var array<string, Tool>
     */
    protected array $tools = [];

    protected ?Schema $schema = null;

    protected ?ToolChoice $toolChoice = null;

    protected int $steps = 10;

    /**
     * @var (\Closure(list<Step>): bool)|null
     */
    protected ?\Closure $until = null;

    protected ?int $maxTokens = null;

    protected ?float $temperature = null;

    protected ?float $topP = null;

    protected ?int $topK = null;

    /**
     * @var list<string>
     */
    protected array $stop = [];

    protected ?int $seed = null;

    protected ?Effort $effort = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $options = [];

    public function __construct(
        protected Model $model,
        protected string $instructions = '',
    ) {
    }

    /**
     * Run the agent to completion.
     *
     * @param  string|Message|list<Message>  $prompt
     *
     * @throws Exception
     */
    public function generate(string|Message|array $prompt): Result
    {
        return $this->run($this->messages($prompt), null);
    }

    /**
     * Run the agent, passing model deltas, tool results and step and run
     * completion to $listener as they happen.
     *
     * @param  string|Message|list<Message>  $prompt
     * @param  callable(Event): void  $listener
     *
     * @throws Exception
     */
    public function stream(string|Message|array $prompt, callable $listener): Result
    {
        return $this->run($this->messages($prompt), $listener);
    }

    /**
     * The request the next step sends for $messages.
     *
     * @param  list<Message>  $messages
     */
    public function request(array $messages): Request
    {
        if ($this->instructions !== '') {
            array_unshift($messages, Message::system($this->instructions));
        }

        return new Request(
            messages: $messages,
            tools: array_values($this->tools),
            toolChoice: $this->toolChoice,
            schema: $this->schema,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            topK: $this->topK,
            stop: $this->stop,
            seed: $this->seed,
            effort: $this->effort,
            options: $this->options,
        );
    }

    /**
     * @param  list<Message>  $messages
     * @param  (callable(Event): void)|null  $listener
     */
    protected function run(array $messages, ?callable $listener): Result
    {
        $produced = [];
        $steps = [];

        while (true) {
            $request = $this->request([...$messages, ...$produced]);
            $response = $listener === null
                ? $this->model->generate($request)
                : $this->model->stream($request, $listener);

            $produced[] = $response->message();

            $calls = $response->toolCalls();
            $results = [];
            foreach ($calls as $call) {
                $tool = $this->tools[$call->name] ?? null;
                if ($tool !== null && $tool->handler === null) {
                    continue;
                }

                $results[] = $this->execute($tool, $call);
            }

            if ($results !== []) {
                $produced[] = Message::tool($results);
            }

            $step = new Step($response, $results);
            $steps[] = $step;

            // Tool results are announced only once every tool of the step has
            // run, directly before the step finishes, so nothing with side
            // effects happens between a result event and StepFinish.
            if ($listener !== null) {
                foreach ($results as $result) {
                    $listener(new ToolResultEvent($result));
                }
                $listener(new StepFinish($step));
            }

            $pending = \count($results) < \count($calls);
            if ($calls === [] || $pending || \count($steps) >= $this->steps) {
                break;
            }
            if ($this->until !== null && ($this->until)($steps)) {
                break;
            }
        }

        $output = null;
        if ($this->schema !== null && $step->response->finish !== FinishReason::ToolCalls) {
            $output = $this->parse($this->schema, $step->text());
        }

        $result = new Result($steps, $produced, $output);
        if ($listener !== null) {
            $listener(new Finish($result));
        }

        return $result;
    }

    /**
     * Run one tool call, turning every failure into an error result.
     */
    protected function execute(?Tool $tool, ToolCall $call): ToolResult
    {
        if ($tool === null) {
            $available = implode(', ', array_keys($this->tools));

            return new ToolResult($call->id, $call->name, "Unknown tool '{$call->name}'. Available tools: {$available}", true);
        }

        $errors = $tool->input->validate($call->arguments);
        if ($errors !== []) {
            return new ToolResult($call->id, $call->name, 'Invalid arguments: '.implode('; ', $errors), true);
        }

        try {
            return new ToolResult($call->id, $call->name, $tool->execute($call));
        } catch (\Throwable $error) {
            return new ToolResult($call->id, $call->name, $error->getMessage(), true);
        }
    }

    /**
     * Decode and validate structured output. Tolerates a Markdown code fence
     * around the JSON.
     *
     * @throws Output
     */
    protected function parse(Schema $schema, string $text): mixed
    {
        $json = trim($text);
        if (str_starts_with($json, '```')) {
            $json = substr($json, 3);
            if (str_ends_with($json, '```')) {
                $json = substr($json, 0, -3);
            }
            $json = trim($json);

            // Drop a language tag such as "json" before the payload.
            if ($json !== '' && ! \in_array($json[0], ['{', '['], true)) {
                $end = strcspn($json, " \t\r\n{[");
                $json = trim(substr($json, $end));
            }
        }

        try {
            $output = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Output('Structured output is not valid JSON: '.$error->getMessage(), $text);
        }

        $errors = $schema->validate($output);
        if ($errors !== []) {
            throw new Output('Structured output does not match the schema: '.implode('; ', $errors), $text, $errors);
        }

        return $output;
    }

    /**
     * @param  string|Message|list<Message>  $prompt
     * @return list<Message>
     */
    protected function messages(string|Message|array $prompt): array
    {
        if (\is_string($prompt)) {
            return [Message::user($prompt)];
        }

        if ($prompt instanceof Message) {
            return [$prompt];
        }

        return $prompt;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function setInstructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        return array_values($this->tools);
    }

    public function addTool(Tool $tool): static
    {
        $this->tools[$tool->name] = $tool;

        return $this;
    }

    /**
     * @param  list<Tool>  $tools
     */
    public function setTools(array $tools): static
    {
        $this->tools = [];
        foreach ($tools as $tool) {
            $this->addTool($tool);
        }

        return $this;
    }

    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    /**
     * Ask for JSON matching $schema; the run's Result::$output holds it decoded.
     */
    public function setSchema(?Schema $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function setToolChoice(?ToolChoice $toolChoice): static
    {
        $this->toolChoice = $toolChoice;

        return $this;
    }

    /**
     * Most model calls one run makes.
     */
    public function setSteps(int $steps): static
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException('An agent needs at least one step');
        }

        $this->steps = $steps;

        return $this;
    }

    /**
     * Stop the loop early once $until returns true for the steps so far.
     *
     * @param  (\Closure(list<Step>): bool)|null  $until
     */
    public function setUntil(?\Closure $until): static
    {
        $this->until = $until;

        return $this;
    }

    public function setMaxTokens(?int $maxTokens): static
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function setTemperature(?float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function setTopP(?float $topP): static
    {
        $this->topP = $topP;

        return $this;
    }

    public function setTopK(?int $topK): static
    {
        $this->topK = $topK;

        return $this;
    }

    /**
     * @param  list<string>  $stop
     */
    public function setStop(array $stop): static
    {
        $this->stop = $stop;

        return $this;
    }

    public function setSeed(?int $seed): static
    {
        $this->seed = $seed;

        return $this;
    }

    public function setEffort(?Effort $effort): static
    {
        $this->effort = $effort;

        return $this;
    }

    /**
     * Raw provider parameters keyed by adapter name; see Request::$options.
     *
     * @param  array<string, array<string, mixed>>  $options
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }
}
