<?php

namespace Utopia\Query;

/**
 * Wire Protocol Query Classifier
 *
 * Classifies database wire protocol messages as Read, Write, Transaction, or Unknown
 * to enable routing queries to appropriate primary/replica backends.
 *
 * This does not parse queries: implementations read the leading keyword (or, for
 * document protocols, the first command name) and look it up. For a structural
 * parse of SQL text into a syntax tree, see {@see AST\Parser}.
 */
interface Classifier
{
    /**
     * Classify a raw wire protocol message
     *
     * @param  string  $data  Raw protocol message bytes
     * @return Type Classification result
     */
    public function classify(string $data): Type;
}
