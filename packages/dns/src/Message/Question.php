<?php

namespace Utopia\DNS\Message;

use Utopia\DNS\Exception\Message\DecodingException;

final readonly class Question
{
    public string $name;

    public function __construct(
        string $name,
        public int $type,
        public int $class = Record::CLASS_IN,
    ) {
        $this->name = trim(strtolower($name));
    }

    /**
     * @param-out int $offset
     */
    public static function decode(string $data, int &$offset = 0): self
    {
        $name = Domain::decode($data, $offset);

        $remaining = \strlen($data) - $offset;
        if ($remaining < 4) {
            throw new DecodingException('Question section truncated');
        }

        $typeData = unpack('ntype', substr($data, $offset, 2));
        if (!\is_array($typeData) || !\array_key_exists('type', $typeData) || !\is_int($typeData['type'])) {
            throw new DecodingException('Failed to unpack question type');
        }
        $type = $typeData['type'];
        $offset += 2;

        $classData = unpack('nclass', substr($data, $offset, 2));
        if (!\is_array($classData) || !\array_key_exists('class', $classData) || !\is_int($classData['class'])) {
            throw new DecodingException('Failed to unpack question class');
        }
        $class = $classData['class'];
        $offset += 2;

        return new self($name, $type, $class);
    }

    public function encode(): string
    {
        $encodedName = Domain::encode($this->name);

        return $encodedName . pack('nn', $this->type, $this->class);
    }
}
