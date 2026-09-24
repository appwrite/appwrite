<?php

declare(strict_types=1);

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class PHPass extends Hash
{
    /**
     * Alphabet used in itoa64 conversions.
     */
    protected string $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Constructor
     */
    public function __construct()
    {
        $randomState = microtime();
        if (\function_exists('getmypid')) {
            $randomState .= getmypid();
        }

        $this->setOption('type', $this->getName());
        $this->setOption('iteration_count_log2', 8);
        $this->setOption('portable_hashes', false);
        $this->setOption('random_state', $randomState);
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        $options = $this->getOptions();
        $random = '';

        if (! $options['portable_hashes']) {
            $random = $this->getRandomBytes(16);
            $hash = crypt($value, $this->gensaltBlowfish($random));
            if (\strlen($hash) === 60) {
                return $hash;
            }
        }

        if (\strlen($random) < 6) {
            $random = $this->getRandomBytes(6);
        }

        $hash = $this->cryptPrivate($value, $this->gensaltPrivate($random));
        if (\strlen($hash) === 34) {
            return $hash;
        }

        return '*';
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $value, string $hash): bool
    {
        $verificationHash = $this->cryptPrivate($value, $hash);
        if ($verificationHash[0] === '*') {
            $verificationHash = crypt($value, $hash);
        }

        return hash_equals($hash, $verificationHash);
    }

    /**
     * Get random bytes
     */
    protected function getRandomBytes(int $count): string
    {
        if ($count < 1) {
            throw new \Exception('Argument count must be a positive integer');
        }

        $output = '';
        if (@is_readable('/dev/urandom') && ($fh = @fopen('/dev/urandom', 'rb'))) {
            $readOutput = fread($fh, $count);
            if ($readOutput !== false) {
                $output = $readOutput;
            }
            fclose($fh);
        }

        if (\strlen($output) < $count) {
            $output = '';
            $options = $this->getOptions();

            for ($i = 0; $i < $count; $i += 16) {
                $options['random_state'] = md5(microtime() . $options['random_state']);
                $output .= md5($options['random_state'], true);
            }

            $output = substr($output, 0, $count);
        }

        return $output;
    }

    /**
     * Encode in base64
     */
    protected function encode64(string $input, int $count): string
    {
        if ($count < 1) {
            throw new \Exception('Argument count must be a positive integer');
        }

        $output = '';
        $i = 0;
        do {
            $value = \ord($input[$i++]);
            $output .= $this->itoa64[$value & 0x3F];
            if ($i < $count) {
                $value |= \ord($input[$i]) << 8;
            }
            $output .= $this->itoa64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= \ord($input[$i]) << 16;
            }
            $output .= $this->itoa64[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $output .= $this->itoa64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }

    /**
     * Generate salt for private key
     */
    private function gensaltPrivate(string $input): string
    {
        $options = $this->getOptions();
        $output = '$P$';
        $output .= $this->itoa64[min($options['iteration_count_log2'] + ((PHP_VERSION >= '5') ? 5 : 3), 30)];

        return $output . $this->encode64($input, 6);
    }

    /**
     * Generate salt for Blowfish
     */
    private function gensaltBlowfish(string $input): string
    {
        $options = $this->getOptions();
        $itoa64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        $output = '$2a$';
        $output .= \chr(\ord('0') + \intval($options['iteration_count_log2'] / 10));
        $output .= \chr(\ord('0') + $options['iteration_count_log2'] % 10);
        $output .= '$';

        $i = 0;
        do {
            $c1 = \ord($input[$i++]);
            $output .= $itoa64[$c1 >> 2];
            $c1 = ($c1 & 0x03) << 4;
            if ($i >= 16) {
                $output .= $itoa64[$c1];
                break;
            }

            $c2 = \ord($input[$i++]);
            $c1 |= $c2 >> 4;
            $output .= $itoa64[$c1];
            $c1 = ($c2 & 0x0F) << 2;

            $c2 = \ord($input[$i++]);
            $c1 |= $c2 >> 6;
            $output .= $itoa64[$c1];
            $output .= $itoa64[$c2 & 0x3F];
        } while (1);

        return $output;
    }

    /**
     * Crypt private
     */
    private function cryptPrivate(string $password, string $setting): string
    {
        $output = '*0';
        if (substr($setting, 0, 2) === $output) {
            $output = '*1';
        }

        $id = substr($setting, 0, 3);
        // We use "$P$", phpBB3 uses "$H$" for the same thing
        if ($id !== '$P$' && $id !== '$H$') {
            return $output;
        }

        $count_log2 = strpos($this->itoa64, $setting[3]);
        if ($count_log2 < 7 || $count_log2 > 30) {
            return $output;
        }

        $count = 1 << $count_log2;
        $salt = substr($setting, 4, 8);
        if (\strlen($salt) !== 8) {
            return $output;
        }

        $hash = md5($salt . $password, true);
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        $output = substr($setting, 0, 12);

        return $output . $this->encode64($hash, 16);
    }

    /**
     * Set iteration count (log2)
     *
     * @param  int  $count  Iteration count (log2) between 4 and 31
     *
     * @throws \InvalidArgumentException
     */
    public function setIterationCount(int $count): PHPass
    {
        if ($count < 4 || $count > 31) {
            throw new \InvalidArgumentException('Iteration count must be between 4 and 31');
        }

        $this->setOption('iteration_count_log2', $count);

        return $this;
    }

    /**
     * Set portable hashes mode
     *
     * @param  bool  $portable  Whether to use portable hashes
     */
    public function setPortableHashes(bool $portable): PHPass
    {
        $this->setOption('portable_hashes', $portable);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'phpass';
    }
}
