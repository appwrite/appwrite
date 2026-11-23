<?php

namespace Utopia\Abuse;

use Utopia\Abuse\Challenge\Prompt;
use Utopia\Abuse\Challenge\Result;
use Utopia\Abuse\Challenge\Status;
use Utopia\Abuse\Challenge\Token;

/**
 * Something a client must answer before it gets through: a proof-of-work
 * puzzle today, a password or a captcha later. Like {@see Adapter}, it holds
 * what every kind shares — the signed token and the params it is bound to —
 * and each kind implements issue() and verify().
 */
abstract readonly class Challenge
{
    protected Token $token;

    /** @var array<string, string> */
    protected array $params;

    /**
     * @param  string  $secret  Signing secret
     * @param  string  $label  Derives the signing key from the secret; keep it stable or issued prompts stop verifying
     * @param  string  $purpose  Stored in each prompt so no other kind of token passes as one
     * @param  int  $ttl  Prompt lifetime in seconds
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $secret, string $label, string $purpose, int $ttl)
    {
        $this->token = new Token($secret, $label, $purpose, $ttl);
        $this->params = [];
    }

    /**
     * A copy with these params added, like {@see Adapter::withParams()}. Params
     * are the fields prompts are bound to, e.g. the client's IP prefix and
     * User-Agent hash: a prompt verifies only with exactly the same params.
     *
     * @param  array<string, string>  $params
     */
    final public function withParams(array $params): static
    {
        return clone($this, ['params' => [...$this->params, ...$params]]);
    }

    final public function withParam(string $name, string $value): static
    {
        return $this->withParams([$name => $value]);
    }

    /**
     * Sign what the client needs to answer, bound to these params. Claims are
     * carried through and returned by verify.
     *
     * @param  array<string, string|int>  $claims
     *
     * @throws \InvalidArgumentException
     */
    abstract public function issue(array $claims = []): Prompt;

    /**
     * Check the client's answer to a prompt issued with the same params.
     */
    abstract public function verify(string $token, string $answer): Result;

    /**
     * Sign the challenge's own claims plus the caller's, bound to the params.
     *
     * @param  array<string, string|int>  $own  Claims the challenge itself needs; params and caller claims may not reuse their names
     * @param  array<string, string|int>  $claims
     *
     * @throws \InvalidArgumentException
     */
    protected function sign(array $own, array $claims = [], int $ttl = 0): string
    {
        foreach ([...\array_keys($this->params), ...\array_keys($claims)] as $name) {
            if (\array_key_exists($name, $own)) {
                throw new \InvalidArgumentException("claim '{$name}' is reserved");
            }
        }

        return $this->token->issue([...$claims, ...$own], $ttl, $this->params);
    }

    /**
     * The claims of a token issued by this challenge for these exact params,
     * or the failed result to return.
     *
     * @return array<mixed>|Result
     */
    protected function open(string $token): array|Result
    {
        $claims = $this->token->verify($token);
        if ($claims === null) {
            return new Result(Status::InvalidToken);
        }

        if (!Token::isBoundTo($claims, $this->params)) {
            return new Result(Status::BindingMismatch);
        }

        return $claims;
    }
}
