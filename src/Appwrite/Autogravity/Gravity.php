<?php

namespace Appwrite\Autogravity;

class Gravity
{
    public function __construct(
        public readonly float $x,
        public readonly float $y
    ) {
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new Exception('Autogravity returned an invalid gravity');
        }
    }

    public function unrotate(int $rotation): self
    {
        return match (($rotation % 360 + 360) % 360) {
            0 => $this,
            90 => new self($this->y, 1 - $this->x),
            180 => new self(1 - $this->x, 1 - $this->y),
            270 => new self(1 - $this->y, $this->x),
            default => throw new Exception('Autogravity cannot reverse an unsupported rotation'),
        };
    }

    /**
     * @return array{x: float, y: float}
     */
    public function getArrayCopy(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
