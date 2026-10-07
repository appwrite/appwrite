<?php

namespace Utopia\Database;

class Change
{
    public function __construct(
        protected Document $old,
        protected Document $new,
    ) {
    }

    public function getOld(): Document
    {
        return $this->old;
    }

    public function setOld(Document $old): void
    {
        $this->old = $old;
    }

    public function getNew(): Document
    {
        return $this->new;
    }

    public function setNew(Document $new): void
    {
        $this->new = $new;
    }
}
