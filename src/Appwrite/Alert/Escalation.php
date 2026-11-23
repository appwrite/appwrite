<?php

namespace Appwrite\Alert;

use Utopia\Database\Document;

/**
 * An escalation's steps and repeats, and where an alert's escalation
 * goes after each step.
 */
readonly class Escalation
{
    /** Longest wait before a step, as long as a monitor's longest cooldown. */
    public const int MAX_DELAY = 2592000;

    /** Most repeats of the steps, as PagerDuty allows. */
    public const int MAX_REPEAT = 9;

    /**
     * @param list<Step> $steps
     */
    public function __construct(
        public array $steps,
        public int $repeat = 0,
    ) {
    }

    /**
     * Steps and repeats as a request sends them: at least one step, each waiting
     * `delay` seconds (up to 30 days) and notifying at least one of `$roles` or
     * one member.
     *
     * @param array<mixed> $steps
     * @param array<string> $roles the organization roles a step may name
     * @throws \InvalidArgumentException naming what is wrong
     */
    public static function parse(array $steps, int $repeat, array $roles): self
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('An escalation needs at least one step.');
        }

        $parsed = [];
        foreach (\array_values($steps) as $index => $step) {
            if (!\is_array($step)) {
                throw new \InvalidArgumentException("Step {$index} must be an object with `delay` and `roles` or `userIds`.");
            }

            $unknown = \array_diff(\array_keys($step), ['delay', 'roles', 'userIds']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("Step {$index} has unknown keys: " . \implode(', ', $unknown) . '.');
            }

            $delay = $step['delay'] ?? 0;
            if (!\is_int($delay) || $delay < 0 || $delay > self::MAX_DELAY) {
                throw new \InvalidArgumentException("Step {$index} needs a `delay` in seconds between 0 and " . self::MAX_DELAY . '.');
            }

            foreach (['roles', 'userIds'] as $key) {
                if (!\is_array($step[$key] ?? []) || \array_filter($step[$key] ?? [], fn ($value) => !\is_string($value) || $value === '') !== []) {
                    throw new \InvalidArgumentException("Step {$index} needs `{$key}` to be a list of strings.");
                }
            }

            $parsed[] = $parsedStep = Step::fromArray($step);

            $unknownRoles = \array_diff($parsedStep->roles, $roles);
            if ($unknownRoles !== []) {
                throw new \InvalidArgumentException("Step {$index} names roles the organization does not have: " . \implode(', ', $unknownRoles) . '. Use ' . \implode(', ', $roles) . '.');
            }

            if ($parsedStep->roles === [] && $parsedStep->userIds === []) {
                throw new \InvalidArgumentException("Step {$index} notifies nobody: name `roles`, `userIds` or both.");
            }
        }

        return new self($parsed, $repeat);
    }

    /**
     * The steps and repeats an escalating alert started with, which it keeps
     * however its escalation is edited later, as it keeps its monitor's rules.
     */
    public static function fromAlert(Document $alert): self
    {
        return self::fromDocument(new Document([
            'steps' => $alert->getAttribute('escalationSteps', []),
            'repeat' => $alert->getAttribute('escalationRepeat', 0),
        ]));
    }

    public static function fromDocument(Document $escalation): self
    {
        return new self(
            \array_values(\array_map(
                fn (array|Document $step) => Step::fromArray($step instanceof Document ? $step->getArrayCopy() : $step),
                $escalation->getAttribute('steps', []),
            )),
            (int) $escalation->getAttribute('repeat', 0),
        );
    }

    /**
     * Every member a step names, to check they belong to the organization.
     *
     * @return list<string>
     */
    public function userIds(): array
    {
        return \array_values(\array_unique(\array_merge([], ...\array_map(fn (Step $step) => $step->userIds, $this->steps))));
    }

    /**
     * @return list<array{delay: int, roles: list<string>, userIds: list<string>}>
     */
    public function toArray(): array
    {
        return \array_map(fn (Step $step) => $step->toArray(), $this->steps);
    }

    /**
     * Where the escalation goes once `$step` of `$round` has been notified: the
     * next step, its round and the seconds to wait for it, or null when that was
     * the last step of the last round. A new round starts `$cooldown` seconds
     * after the last step, which is the monitor's own limit on how often its
     * team is notified.
     *
     * @return array{step: int, round: int, delay: int}|null
     */
    public function after(int $step, int $round, int $cooldown): ?array
    {
        if (isset($this->steps[$step + 1])) {
            return ['step' => $step + 1, 'round' => $round, 'delay' => $this->steps[$step + 1]->delay];
        }

        if ($round < $this->repeat) {
            return ['step' => 0, 'round' => $round + 1, 'delay' => $cooldown + $this->steps[0]->delay];
        }

        return null;
    }

}
