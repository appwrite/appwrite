<?php

// Times the password hashes on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/auth.php . <utopia.so> 5 scrypt`.
// Argon2, Bcrypt, Scrypt and PHPass are their algorithms' cost, by design: the numbers say
// what the class around them adds or saves, not that a hash got cheaper to crack.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Auth\Hash;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\MD5;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Plaintext;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\ScryptModified;
use Utopia\Auth\Hashes\Sha;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Hash::class))->isInternal();
$requested = in_array('auth', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' auth, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// What requests do: build the project's hash (the default Argon2, or an imported user's), then
// hash a new password or verify a sign-in. Imported hashes use the options their source used.
$password = 'correct horse battery staple';
$argon2 = new Argon2();
$bcrypt = new Bcrypt();
$md5 = new MD5();
$sha = new Sha();
$plaintext = new Plaintext();
$scrypt = (new Scrypt())->setSalt('salt')->setCpuCost(16384)->setMemoryCost(8)->setParallelCost(1)->setLength(64);
$firebase = (new ScryptModified())->setOptions([
    'salt' => '42xEC+ixf3L2lw==', 'saltSeparator' => 'Bw==',
    'signerKey' => 'jxspr8Ki0RYycVU8zykbdLGjFQ3McFUH0uiiTvC8pVMXAn210wjLNmdZJzxUECKbm0QsEmYUSDzZvpjeJ9WmXA==',
]);
$wordpress = (new PHPass())->setPortableHashes(true);
$phpass = new PHPass();
$hashes = [
    'Argon2' => [$argon2, 3], 'Bcrypt' => [$bcrypt, 20], 'MD5' => [$md5, 20000], 'Sha' => [$sha, 20000], 'Plaintext' => [$plaintext, 20000],
    'Scrypt' => [$scrypt, 5], 'ScryptModified' => [$firebase, 5], 'PHPass portable' => [$wordpress, 50], 'PHPass' => [$phpass, 20],
];
$cases = [
    'new Argon2' => [fn () => new Argon2(), 20000],
    'new ScryptModified' => [fn () => new ScryptModified(), 20000],
    'new PHPass' => [fn () => new PHPass(), 20000],
    'setOptions' => [fn () => (new Bcrypt())->setOptions(['cost' => 10, 'type' => 'bcrypt']), 20000],
];
foreach ($hashes as $name => [$hash, $n]) {
    $hashed = $hash->hash($password);
    $cases[$name . ' hash'] = [fn () => $hash->hash($password), $n];
    $cases[$name . ' verify'] = [fn () => $hash->verify($password, $hashed), $n];
}
$out = [];
foreach ($cases as $name => [$fn, $n]) {
    for ($i = 0; $i < max(1, intdiv($n, 20)); $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
