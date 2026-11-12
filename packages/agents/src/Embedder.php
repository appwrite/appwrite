<?php

namespace Utopia\Agents;

/**
 * A model that turns text into vectors.
 */
interface Embedder
{
    public function getName(): string;

    public function getModel(): string;

    /**
     * Length of every vector this model returns.
     */
    public function getDimension(): int;

    /**
     * Embed every text in one batch; vectors keep the order of $texts.
     *
     * @param  list<string>  $texts
     *
     * @throws Exception
     */
    public function embed(array $texts): Embeddings;
}
