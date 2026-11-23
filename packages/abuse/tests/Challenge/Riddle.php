<?php

namespace Utopia\Abuse\Tests\Challenge;

use Utopia\Abuse\Challenge;
use Utopia\Abuse\Challenge\Prompt;
use Utopia\Abuse\Challenge\Result;
use Utopia\Abuse\Challenge\Status;

/**
 * The smallest possible challenge, to prove a new kind needs only a constructor,
 * issue() and verify(): the prompt carries a hash of the expected answer.
 */
final readonly class Riddle extends Challenge
{
    public function __construct(string $secret, private string $answer)
    {
        parent::__construct($secret, 'riddle', 'riddle', 60);
    }

    #[\Override]
    public function issue(array $claims = []): Prompt
    {
        return new Prompt($this->sign(['answer' => \hash('sha256', $this->answer)], $claims));
    }

    #[\Override]
    public function verify(string $token, string $answer): Result
    {
        $claims = $this->claimsFrom($token);
        if ($claims instanceof Result) {
            return $claims;
        }

        $expected = \is_string($claims['answer'] ?? null) ? $claims['answer'] : '';

        return new Result(\hash_equals($expected, \hash('sha256', $answer)) ? Status::Valid : Status::Rejected, $claims);
    }
}
