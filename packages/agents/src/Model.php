<?php

namespace Utopia\Agents;

/**
 * A language model behind a provider API.
 */
interface Model
{
    /**
     * Provider name, also the key for provider options in a Request.
     */
    public function getName(): string;

    /**
     * Model ID sent to the provider.
     */
    public function getModel(): string;

    /**
     * @throws Exception
     */
    public function generate(Request $request): Response;

    /**
     * Generate while passing events to $listener as they arrive.
     *
     * @param  callable(Event): void  $listener
     *
     * @throws Exception
     */
    public function stream(Request $request, callable $listener): Response;
}
