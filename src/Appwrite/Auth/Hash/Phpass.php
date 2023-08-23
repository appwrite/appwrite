<?php

/**
 * Portable PHP password hashing framework.
 * source Version 0.5 / genuine.
 * Written by Solar Designer <solar at openwall.com> in 2004-2017 and placed in
 * the public domain.  Revised in subsequent years, still public domain.
 * There's absolutely no warranty.
 * The homepage URL for the source framework is: http://www.openwall.com/phpass/
 * Please be sure to update the Version line if you edit this file in any way.
 * It is suggested that you leave the main version number intact, but indicate
 * your project name (after the slash) and add your own revision information.
 * Please do not change the "private" password hashing method implemented in
 * here, thereby making your hashes incompatible.  However, if you must, please
 * change the hash type identifier (the "$P$") to something different.
 * Obviously, since this code is in the public domain, the above are not
 * requirements (there can be none), but merely suggestions.
 *
 * @author      Solar Designer <solar@openwall.com>
 * @copyright   Copyright (C) 2017 All rights reserved.
 * @license     http://www.opensource.org/licenses/mit-license.html MIT License; see LICENSE.txt
 */

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * PHPass accepted options:
 * int iteration_count_log2; The Logarithmic cost value used when generating hash values indicating the number of rounds used to generate hashes
 * string portable_hashes
 * string random_state; The cached random state
 *
 * Reference: https://github.com/photodude/phpass
*/
class Phpass extends Hash
{
    /**
     * Alphabet used in itoa64 conversions.
     *
     * @var    string
     *
     * @since  0.1.0
     */
    protected string $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Get default options for specific hashing algo
     *
     * @return array options named array
     */
    public function getDefaultOptions(): array
    {
        $randomState = \microtime();
        if (\function_exists('getmypid')) {
            $randomState .= getmypid();
        }

        return ['iteration_count_log2' => 8, 'portable_hashes' => false, 'random_state' => $randomState];
    }

    /**
     * @param  string  $password Input password to hash
     * @return string hash
     */
    public function hash(string $password): string
    {
        $options = $this->getDefaultOptions();

        $random = '';
        if (CRYPT_BLOWFISH === 1 && ! $options['portable_hashes']) {
            $random = $this->getRandomBytes(16, $options);
            $hash = crypt($password, $this->gensaltBlowfish($random, $options));
            if (strlen($hash) === 60) {
                return $hash;
            }
        }
        if (strlen($random) < 6) {
            $random = $this->getRandomBytes(6, $options);
        }
        $hash = $this->cryptPrivate($password, $this->gensaltPrivate($random, $options));
        if (strlen($hash) === 34) {
            return $hash;
        }

        /**
         * Returning '*' on error is safe here, but would _not_ be safe
         * in a crypt(3)-like function used _both_ for generating new
         * hashes and for validating passwords against existing hashes.
         */
        return '*';
    }

    /**
     * @param  string  $password Input password to validate
     * @param  string  $hash Hash to verify password against
     * @return bool true if password matches hash
     */
    public function verify(string $password, string $hash): bool
    {
        $verificationHash = $this->cryptPrivate($password, $hash);
        if ($verificationHash[0] === '*') {
            $verificationHash = crypt($password, $hash);
        }

        /**
         * This is not constant-time.  In order to keep the code simple,
         * for timing safety we currently rely on the salts being
         * unpredictable, which they are at least in the non-fallback
         * cases (that is, when we use /dev/urandom and bcrypt).
         */
        return $hash === $verificationHash;
    }

    /**
     * @param  int  $count
     * @return string $output
     *
     * @since 0.1.0
     *
     * @throws Exception Thows an Exception if the $count parameter is not a positive integer.
     */
    protected function getRandomBytes(int $count, array $options): string
    {
        if (! is_int($count) || $count < 1) {
            throw new \Exception('Argument count must be a positive integer');
        }
        $output = '';
        if (@is_readable('/dev/urandom') && ($fh = @fopen('/dev/urandom', 'rb'))) {
            $output = fread($fh, $count);
            fclose($fh);
        }

        if (strlen($output) < $count) {
            $output = '';

            for ($i = 0; $i < $count; $i += 16) {
                $options['iteration_count_log2'] = md5(microtime().$options['iteration_count_log2']);
                $output .= md5($options['iteration_count_log2'], true);
            }

            $output = substr($output, 0, $count);
        }

        return $output;
    }

    /**
     * @param  string  $input
     * @param  int  $count
     * @return string $output
     *
     * @since 0.1.0
     *
     * @throws Exception Thows an Exception if the $count parameter is not a positive integer.
     */
    protected function encode64($input, $count)
    {
        if (! is_int($count) || $count < 1) {
            throw new \Exception('Argument count must be a positive integer');
        }
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= $this->itoa64[$value & 0x3F];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= $this->itoa64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
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
     * @param  string  $input
     * @return string $output
     *
     * @since 0.1.0
     */
    private function gensaltPrivate($input, $options)
    {
        $output = '$P$';
        $output .= $this->itoa64[min($options['iteration_count_log2'] + ((PHP_VERSION >= '5') ? 5 : 3), 30)];
        $output .= $this->encode64($input, 6);

        return $output;
    }

    /**
     * @param  string  $password
     * @param  string  $setting
     * @return string $output
     *
     * @since 0.1.0
     */
    private function cryptPrivate($password, $setting)
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
        if (strlen($salt) !== 8) {
            return $output;
        }
        /**
         * We were kind of forced to use MD5 here since it's the only
         * cryptographic primitive that was available in all versions of PHP
         * in use.  To implement our own low-level crypto in PHP
         * would have result in much worse performance and
         * consequently in lower iteration counts and hashes that are
         * quicker to crack (by non-PHP code).
         */
        $hash = md5($salt.$password, true);
        do {
            $hash = md5($hash.$password, true);
        } while (--$count);
        $output = substr($setting, 0, 12);
        $output .= $this->encode64($hash, 16);

        return $output;
    }

    /**
     * @param  string  $input
     * @return string $output
     *
     * @since 0.1.0
     */
    private function gensaltBlowfish($input, $options)
    {
        /**
         * This one needs to use a different order of characters and a
         * different encoding scheme from the one in encode64() above.
         * We care because the last character in our encoded string will
         * only represent 2 bits.  While two known implementations of
         * bcrypt will happily accept and correct a salt string which
         * has the 4 unused bits set to non-zero, we do not want to take
         * chances and we also do not want to waste an additional byte
         * of entropy.
         */
        $itoa64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $output = '$2a$';
        $output .= chr(ord('0') + $options['iteration_count_log2'] / 10);
        $output .= chr(ord('0') + $options['iteration_count_log2'] % 10);
        $output .= '$';
        $i = 0;
        do {
            $c1 = ord($input[$i++]);
            $output .= $itoa64[$c1 >> 2];
            $c1 = ($c1 & 0x03) << 4;
            if ($i >= 16) {
                $output .= $itoa64[$c1];
                break;
            }
            $c2 = ord($input[$i++]);
            $c1 |= $c2 >> 4;
            $output .= $itoa64[$c1];
            $c1 = ($c2 & 0x0F) << 2;
            $c2 = ord($input[$i++]);
            $c1 |= $c2 >> 6;
            $output .= $itoa64[$c1];
            $output .= $itoa64[$c2 & 0x3F];
        } while (1);

        return $output;
    }
}
