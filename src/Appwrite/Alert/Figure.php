<?php

namespace Appwrite\Alert;

/**
 * One side of an alert email's headline card: a small label, the figure, and a
 * note under it ("In the last hour", "548.73 KB", "110× the threshold").
 */
readonly class Figure
{
    public function __construct(
        public string $label,
        public string $value,
        public string $note = '',
    ) {
    }
}
