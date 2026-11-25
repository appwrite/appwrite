<?php

namespace Tests\Compat\Abuse;

use Ahc\Jwt\JWT;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Abuse\Challenge\Clearance;
use Utopia\Abuse\Challenge\Identity;
use Utopia\Abuse\Challenge\Issuer;
use Utopia\Abuse\Challenge\Nonce;
use Utopia\Abuse\Challenge\Nonce\None as NoNonces;
use Utopia\Abuse\Challenge\Nonce\Redis as RedisNonces;
use Utopia\Abuse\Challenge\Nonce\RedisCluster as RedisClusterNonces;
use Utopia\Abuse\Challenge\Nonce\RedisPool as RedisPoolNonces;
use Utopia\Abuse\Challenge\Password;
use Utopia\Abuse\Challenge\ProofOfWork;
use Utopia\Abuse\Challenge\ProofOfWork\Result as ProofOfWorkResult;
use Utopia\Abuse\Challenge\Result;
use Utopia\Abuse\Challenge\Status;
use Utopia\Abuse\Challenge\Token;
use Utopia\Abuse\Tests\Challenge\Nonce\Memory;
use Utopia\Abuse\Tests\Challenge\Riddle;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Proofs\Password as PasswordProof;

/**
 * The challenge operations of tests/compat/abuse/spec.json (spec.d/challenge.json),
 * mapped onto Utopia\Abuse\Challenge. Glue only: no logic beyond the PHP tests' own
 * helpers (solving a puzzle, a provably wrong answer).
 */
trait Challenges
{
    /**
     * @return array<string, callable(array, Session): mixed>
     */
    private static function challenges(): array
    {
        return [
            'identity.ip_prefix' => fn (array $a, Session $s) => self::identity($a)->ipPrefix($a['ip']),
            'identity.user_agent_hash' => fn (array $a, Session $s) => self::identity($a)->userAgentHash($a['ua']),
            'issuer.new' => fn (array $a, Session $s) => $s->handle(new Issuer($a['secret'], $a['label'], $a['purpose'], $a['ttl'])),
            'issuer.issue' => fn (array $a, Session $s) => self::object($a['issuer'], $s, Issuer::class)->issue($a['claims'] ?? [], $a['ttl'] ?? 0, $a['binding'] ?? []),
            'issuer.verify' => fn (array $a, Session $s) => self::object($a['issuer'], $s, Issuer::class)->verify($a['token']),
            'issuer.params_match' => fn (array $a, Session $s) => Issuer::paramsMatch($a['claims'], $a['binding']),
            'jwt.encode' => fn (array $a, Session $s) => self::jwt($a)->encode(self::timed($a['payload'], $a['times'] ?? [])),
            'jwt.decode' => fn (array $a, Session $s) => self::jwt($a)->decode($a['token']),
            'clearance.new' => fn (array $a, Session $s) => $s->handle(new Clearance($a['secret'], $a['ttl'] ?? Clearance::DEFAULT_TTL, $a['label'] ?? 'clearance', $a['purpose'] ?? 'clearance')),
            'clearance.with_params' => fn (array $a, Session $s) => $s->handle(self::object($a['clearance'], $s, Clearance::class)->withParams($a['params'])),
            'clearance.with_param' => fn (array $a, Session $s) => $s->handle(self::object($a['clearance'], $s, Clearance::class)->withParam($a['name'], $a['value'])),
            'clearance.issue' => fn (array $a, Session $s) => self::object($a['clearance'], $s, Clearance::class)->issue($a['difficulty'] ?? 0, $a['ttl'] ?? 0, $a['claims'] ?? []),
            'clearance.verify' => fn (array $a, Session $s) => self::object($a['clearance'], $s, Clearance::class)->verify($a['token'], $a['difficulty'] ?? 0, $a['maxAge'] ?? 0),
            'nonce.new' => fn (array $a, Session $s) => $s->handle(match ($a['kind']) {
                'memory' => new Memory($a['key'] ?? 'pow', new \ArrayObject(), isset($a['error']) ? new \RuntimeException($a['error']) : null),
                'none' => new NoNonces($a['key'] ?? '', $a['params'] ?? []),
                'redis' => new RedisNonces($a['key'], self::redis($s, $a['service'] ?? 'abuse-redis'), $a['params'] ?? []),
                'cluster' => new RedisClusterNonces($a['key'], self::cluster($s), $a['params'] ?? []),
                'pool' => new RedisPoolNonces($a['key'], self::pool($s, $a['service'] ?? 'abuse-redis', $a['cluster'] ?? false), $a['params'] ?? []),
                default => throw new Fault("unknown nonce store {$a['kind']}"),
            }),
            'nonce.with_params' => fn (array $a, Session $s) => $s->handle(self::object($a['nonces'], $s, Nonce::class)->withParams($a['params'])),
            'nonce.with_param' => fn (array $a, Session $s) => $s->handle(self::object($a['nonces'], $s, Nonce::class)->withParam($a['name'], $a['value'])),
            'nonce.key' => fn (array $a, Session $s) => self::object($a['nonces'], $s, Nonce::class)->key($a['nonce']),
            'nonce.consume' => fn (array $a, Session $s) => self::object($a['nonces'], $s, Nonce::class)->consume($a['nonce'], $a['ttl']),
            'nonce.claimed' => fn (array $a, Session $s) => self::object($a['nonces'], $s, Memory::class)->claimed->getArrayCopy(),
            'pow.new' => fn (array $a, Session $s) => $s->handle(new ProofOfWork(
                $a['secret'],
                self::object($a['nonces'], $s, Nonce::class),
                $a['difficulty'],
                $a['ttl'] ?? ProofOfWork::DEFAULT_TTL,
                $a['accept'] ?? false,
                $a['label'] ?? 'challenge',
                $a['purpose'] ?? 'challenge',
            )),
            'pow.with_params' => fn (array $a, Session $s) => $s->handle(self::object($a['pow'], $s, ProofOfWork::class)->withParams($a['params'])),
            'pow.with_param' => fn (array $a, Session $s) => $s->handle(self::object($a['pow'], $s, ProofOfWork::class)->withParam($a['name'], $a['value'])),
            'pow.issue' => function (array $a, Session $s) {
                $token = self::object($a['pow'], $s, ProofOfWork::class)->issue($a['claims'] ?? [], $a['difficulty'] ?? null);

                return ['value' => $token->value, 'nonce' => $token->nonce, 'difficulty' => $token->difficulty];
            },
            'pow.verify' => fn (array $a, Session $s) => self::outcome(self::object($a['pow'], $s, ProofOfWork::class)->verify($a['token'], $a['answer'])),
            'pow.is_solution' => fn (array $a, Session $s) => ProofOfWork::isSolution($a['nonce'], $a['answer'], $a['difficulty']),
            'pow.difficulty_of' => fn (array $a, Session $s) => ProofOfWork::difficultyOf($a['hash']),
            'pow.solve' => function (array $a, Session $s) {
                // ProofOfWorkTest::solve()
                $counter = 0;
                while (!ProofOfWork::isSolution($a['nonce'], (string) $counter, $a['difficulty'])) {
                    $counter++;
                }

                return (string) $counter;
            },
            'pow.wrong' => function (array $a, Session $s) {
                // ProofOfWorkTest::wrong(): an answer its own hex counter says misses the target.
                for ($counter = 0; $counter < 64; $counter++) {
                    $candidate = 'wrong-' . $counter;
                    if (self::difficultyOfHex(\hash('sha256', $a['nonce'] . ':' . $candidate)) < $a['difficulty']) {
                        return $candidate;
                    }
                }

                throw new Fault('no candidate misses the target');
            },
            'pow.difficulty_of_hex' => fn (array $a, Session $s) => self::difficultyOfHex($a['hex']),
            'password.scrypt' => fn (array $a, Session $s) => $s->handle(new Scrypt()),
            'password.hash' => fn (array $a, Session $s) => match ($a['algorithm'] ?? 'default') {
                'default' => new PasswordProof()->hash($a['password']),
                'bcrypt' => new Bcrypt()->hash($a['password']),
                'scrypt' => self::object($a['hasher'], $s, Scrypt::class)->hash($a['password']),
                default => throw new Fault("unknown algorithm {$a['algorithm']}"),
            },
            'password.new' => fn (array $a, Session $s) => $s->handle(new Password(
                $a['secret'],
                $a['hash'],
                match ($a['proof'] ?? 'default') {
                    'default' => new PasswordProof(),
                    'scrypt' => new PasswordProof()->setHash(self::object($a['hasher'], $s, Scrypt::class)),
                    'phpass' => new PasswordProof()->setHash(new PHPass()),
                    default => throw new Fault("unknown proof {$a['proof']}"),
                },
                $a['ttl'] ?? Password::DEFAULT_TTL,
                $a['label'] ?? 'password',
                $a['purpose'] ?? 'password',
            )),
            'password.with_params' => fn (array $a, Session $s) => $s->handle(self::object($a['password'], $s, Password::class)->withParams($a['params'])),
            'password.with_param' => fn (array $a, Session $s) => $s->handle(self::object($a['password'], $s, Password::class)->withParam($a['name'], $a['value'])),
            'password.issue' => fn (array $a, Session $s) => self::object($a['password'], $s, Password::class)->issue($a['claims'] ?? [])->value,
            'password.verify' => fn (array $a, Session $s) => self::outcome(self::object($a['password'], $s, Password::class)->verify($a['token'], $a['answer'])),
            'password.version' => fn (array $a, Session $s) => self::object($a['password'], $s, Password::class)->version(),
            'riddle.new' => fn (array $a, Session $s) => $s->handle(new Riddle($a['secret'], $a['answer'])),
            'riddle.with_params' => fn (array $a, Session $s) => $s->handle(self::object($a['riddle'], $s, Riddle::class)->withParams($a['params'])),
            'riddle.with_param' => fn (array $a, Session $s) => $s->handle(self::object($a['riddle'], $s, Riddle::class)->withParam($a['name'], $a['value'])),
            'riddle.issue' => fn (array $a, Session $s) => self::object($a['riddle'], $s, Riddle::class)->issue($a['claims'] ?? [])->value,
            'riddle.verify' => fn (array $a, Session $s) => self::outcome(self::object($a['riddle'], $s, Riddle::class)->verify($a['token'], $a['answer'])),
            'challenge.result' => fn (array $a, Session $s) => self::outcome(new Result(Status::from($a['status']), $a['claims'] ?? [])),
            'challenge.token' => fn (array $a, Session $s) => new Token($a['value'])->value,
        ];
    }

    private static function identity(array $a): Identity
    {
        return new Identity($a['ipv4Bits'] ?? 24, $a['ipv6Bits'] ?? 48);
    }

    /**
     * `new JWT($key, 'HS256', $maxAge, 0)`, the key derived from `label` as Issuer derives it when one is given.
     */
    private static function jwt(array $a): JWT
    {
        $key = isset($a['label']) ? \hash_hmac('sha256', $a['label'], $a['key']) : $a['key'];

        return new JWT($key, 'HS256', $a['maxAge'] ?? 3600, 0);
    }

    /**
     * The payload with each claim of `times` set to time() plus its offset, in place.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $times
     * @return array<string, mixed>
     */
    private static function timed(array $payload, array $times): array
    {
        foreach ($times as $name => $offset) {
            $payload[$name] = \time() + $offset;
        }

        return $payload;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function object(mixed $handle, Session $s, string $class): object
    {
        $object = $s->get($handle);

        return $object instanceof $class ? $object : throw new Fault("not a {$class}");
    }

    /**
     * @return array<string, mixed>
     */
    private static function outcome(Result $result): array
    {
        $out = ['status' => $result->status->value, 'claims' => $result->claims, 'isValid' => $result->isValid()];
        if ($result instanceof ProofOfWorkResult) {
            $out['difficulty'] = $result->difficulty;
            $out['elapsedMs'] = $result->elapsedMs;
        }
        $out['error'] = $result->error === null ? null : ['class' => $result->error::class, 'message' => $result->error->getMessage()];

        return $out;
    }

    /**
     * ProofOfWorkTest::difficultyOfHex(): leading zero bits counted over the hex digest.
     */
    private static function difficultyOfHex(string $hex): int
    {
        $bits = 0;
        $length = \strlen($hex);
        for ($i = 0; $i < $length; $i++) {
            $value = \intval($hex[$i], 16);
            if ($value === 0) {
                $bits += 4;

                continue;
            }
            for ($mask = 0x8; $mask > 0; $mask >>= 1) {
                if (($value & $mask) !== 0) {
                    return $bits;
                }
                $bits++;
            }
        }

        return $bits;
    }
}
