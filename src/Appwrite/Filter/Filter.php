<?php

namespace Appwrite\Filter;

interface Filter
{
    public function apply(mixed $input): mixed;
}
