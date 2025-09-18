<?php

namespace Tests\Unit\Functions\Validator;

use Appwrite\Functions\Validator\Headers;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;

final class HeadersBench
{
    private Headers $validator;

    public function tearDown(): void
    {
    }

    public function prepare(): void
    {
        $this->validator = new Headers();
    }

    public function providers(): iterable
    {
        yield 'empty' => [ 'value' => [] ];

        $value = [];
        for ($i = 0; $i < 10; $i++) {
            $value[bin2hex(random_bytes(8))] = bin2hex(random_bytes(8));
        }
        yield 'items_10-size_320' => [ 'value' => $value ];

        $value = [];
        for ($i = 0; $i < 100; $i++) {
            $value[bin2hex(random_bytes(8))] = bin2hex(random_bytes(8));
        }
        yield 'items_100-size_3200' => [ 'value' => $value ];

        $value = [];
        for ($i = 0; $i < 100; $i++) {
            $value[bin2hex(random_bytes(32))] = bin2hex(random_bytes(32));
        }
        yield 'items_100-size_12800' => [ 'value' => $value ];
    }

    #[BeforeMethods('prepare')]
    #[AfterMethods('tearDown')]
    #[ParamProviders('providers')]
    #[Iterations(50)]
    #[Assert('mode(variant.time.avg) < 1 ms')]
    public function benchHeadersValidator(array $data): void
    {
        $assertion = $this->validator->isValid($data['value']);
        if (!$assertion) {
            exit(1);
        }
    }
}
