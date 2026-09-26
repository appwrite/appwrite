<?php

namespace Utopia;

use InvalidArgumentException;
use Stringable;
use Utopia\Validator\Text;

class Command implements Stringable
{
    private const string TYPE_PLAIN = 'plain';

    private const string TYPE_COMPOSITE = 'composite';

    private const string TYPE_GROUP = 'group';

    private const string TYPE_REDIRECT = 'redirect';

    private const string OPERATOR_PIPE = '|';

    private const string OPERATOR_AND = '&&';

    private const string OPERATOR_OR = '||';

    private const string REDIRECT_STDOUT = '>';

    private const string REDIRECT_APPEND_STDOUT = '>>';

    private const string REDIRECT_INPUT = '<';

    private string $type = self::TYPE_PLAIN;

    /**
     * @var array<int, string>
     */
    protected array $arguments = [];

    /**
     * @var array<int, self>
     */
    private array $commands = [];

    private ?string $operator = null;

    private ?self $command = null;

    private ?string $redirect = null;

    private ?string $redirectTarget = null;

    public function __construct(string $executable)
    {
        $this->arguments[] = $this->normalize($executable, 'Command executable');
    }

    public static function pipe(Command ...$commands): self
    {
        return self::compose(self::OPERATOR_PIPE, $commands);
    }

    public static function and(Command ...$commands): self
    {
        return self::compose(self::OPERATOR_AND, $commands);
    }

    public static function or(Command ...$commands): self
    {
        return self::compose(self::OPERATOR_OR, $commands);
    }

    public static function group(Command $command): self
    {
        $expression = new self('true');
        $expression->type = self::TYPE_GROUP;
        $expression->arguments = [];
        $expression->command = $command;

        return $expression;
    }

    public static function redirectStdout(Command $command, string|Stringable $path): self
    {
        return self::redirect(self::REDIRECT_STDOUT, $command, $path);
    }

    public static function appendStdout(Command $command, string|Stringable $path): self
    {
        return self::redirect(self::REDIRECT_APPEND_STDOUT, $command, $path);
    }

    public static function redirectInput(Command $command, string|Stringable $path): self
    {
        return self::redirect(self::REDIRECT_INPUT, $command, $path);
    }

    public function flag(string $key): self
    {
        $this->ensurePlain();

        if (! preg_match('/^-[A-Za-z0-9]+$|^--[A-Za-z0-9][A-Za-z0-9_-]*$/', $key)) {
            throw new InvalidArgumentException('Invalid command flag: ' . $key);
        }

        $this->arguments[] = $key;

        return $this;
    }

    public function option(string $key, string|int|float|Stringable $value, Validator|callable|null $validator = null): self
    {
        $this->ensurePlain();

        if (! preg_match('/^-[A-Za-z0-9]$|^--[A-Za-z0-9][A-Za-z0-9_-]*$/', $key)) {
            throw new InvalidArgumentException('Invalid command option: ' . $key);
        }

        $argument = $this->normalize($value, 'Command option value');
        $this->validate($argument, $validator ?? new Text(0));

        $this->arguments[] = $key;
        $this->arguments[] = $argument;

        return $this;
    }

    public function argument(string|int|float|Stringable $value, Validator|callable|null $validator = null): self
    {
        $this->ensurePlain();

        $argument = $this->normalize($value, 'Command argument');
        $this->validate($argument, $validator ?? new Text(0));

        $this->arguments[] = $argument;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function toArray(): array
    {
        if (! $this->isPlain()) {
            throw new InvalidArgumentException('Only plain commands can be converted to an array');
        }

        return $this->arguments;
    }

    public function toString(): string
    {
        return match ($this->type) {
            self::TYPE_PLAIN => implode(' ', array_map(escapeshellarg(...), $this->arguments)),
            self::TYPE_COMPOSITE => implode(' ' . $this->operator . ' ', array_map(static fn(self $command): string => $command->toString(), $this->commands)),
            self::TYPE_GROUP => '( ' . $this->command?->toString() . ' )',
            self::TYPE_REDIRECT => $this->command?->toString() . ' ' . $this->redirect . ' ' . escapeshellarg($this->redirectTarget ?? ''),
            default => throw new InvalidArgumentException('Unsupported command type: ' . $this->type),
        };
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function isPlain(): bool
    {
        return $this->type === self::TYPE_PLAIN;
    }

    /**
     * @param  array<int, self>  $commands
     */
    private static function compose(string $operator, array $commands): self
    {
        if (\count($commands) < 2) {
            throw new InvalidArgumentException('Composed commands require at least two commands');
        }

        $expression = new self('true');
        $expression->type = self::TYPE_COMPOSITE;
        $expression->arguments = [];
        $expression->operator = $operator;
        $expression->commands = array_values($commands);

        return $expression;
    }

    private static function redirect(string $redirect, Command $command, string|Stringable $path): self
    {
        $expression = new self('true');
        $expression->type = self::TYPE_REDIRECT;
        $expression->arguments = [];
        $expression->command = $command;
        $expression->redirect = $redirect;
        $expression->redirectTarget = $expression->normalize($path, 'Command redirect target');

        return $expression;
    }

    private function ensurePlain(): void
    {
        if (! $this->isPlain()) {
            throw new InvalidArgumentException('Flags, options, and arguments can only be added to plain commands');
        }
    }

    private function normalize(string|int|float|Stringable $value, string $context): string
    {
        $value = (string) $value;

        if ($value === '') {
            throw new InvalidArgumentException($context . ' cannot be empty');
        }

        return $value;
    }

    private function validate(string $argument, Validator|callable $validator): void
    {
        if ($validator instanceof Validator) {
            if (! $validator->isValid($argument)) {
                throw new InvalidArgumentException('Invalid command argument: ' . $argument . ' (' . $validator->getDescription() . ')');
            }

            return;
        }

        $isValid = (bool) $validator($argument);

        if (! $isValid) {
            throw new InvalidArgumentException('Invalid command argument: ' . $argument);
        }
    }
}
