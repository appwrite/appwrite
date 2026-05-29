<?php

namespace Utopia\Agents;

class Agent
{
    protected string $description;

    /**
     * @var array<string, string|list<string>>
     */
    protected array $instructions;

    protected ?Schema $schema = null;

    protected Adapter $adapter;

    /**
     * Create a new agent
     *
     * @param  Adapter  $adapter  The AI model adapter to use
     *
     * @throws \Exception
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->instructions = [];
        $this->description = '';

        $this->adapter->setAgent($this);
    }

    /**
     * Set the agent's description
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the agent's instructions
     *
     * @param  array<string, string|list<string>>  $instructions
     */
    public function setInstructions(array $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Add an instruction to the agent
     */
    public function addInstruction(string $name, string $content): self
    {
        $this->instructions[$name] = $content;

        return $this;
    }

    /**
     * Get the agent's description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get the agent's instructions
     *
     * @return array<string, string|list<string>>
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    /**
     * Get the agent's adapter
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Get the agent's schema
     */
    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    /**
     * Set the agent's schema
     */
    public function setSchema(Schema $schema): self
    {
        if (! $this->adapter->isSchemaSupported()) {
            throw new \Exception('Schema is not supported for this model');
        }

        $this->schema = $schema;

        return $this;
    }

    /**
     * Get embedding for input text using underlying adapter (if supported)
     *
     * @return array{
     *     embedding: array<int, float>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null ,
     *     modelLoadingDuration?: int|null
     * }
     *
     * @throws \Exception
     */
    public function embed(string $text): array
    {
        if (! $this->adapter->getSupportForEmbeddings()) {
            throw new \Exception('This adapter does not support embedding/embedding API.');
        }

        return $this->adapter->embed($text);
    }

    /**
     * Get embeddings for a batch of input texts using underlying adapter (if supported)
     *
     * @param array<int, string> $texts
     *
     * @return array{
     *     embeddings: array<int, array<int, float>>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null,
     * }
     *
     * @throws \Exception
     */
    public function bulkEmbed(array $texts): array
    {
        if (! $this->adapter->getSupportForEmbeddings()) {
            throw new \Exception('This adapter does not support embedding/embedding API.');
        }

        return $this->adapter->bulkEmbed($texts);
    }
}
