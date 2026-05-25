<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Any extends Model
{
    /**
     * @var bool
     */
    protected bool $any = true;

    /**
     * JSON wire-format key under which extra/dynamic attributes are exposed in
     * generated SDK models (e.g. Document<T>'s `data` slot). Default null means
     * SDK templates fall back to their hardcoded "data" key. Set this on
     * subclasses (via setAdditionalPropertiesKey) to use a custom key like
     * "metadata" while still benefiting from the generic `Model<T>` mapping.
     */
    protected ?string $additionalPropertiesKey = null;

    public function setAdditionalPropertiesKey(string $key): self
    {
        $this->additionalPropertiesKey = $key;
        return $this;
    }

    public function getAdditionalPropertiesKey(): ?string
    {
        return $this->additionalPropertiesKey;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Any';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ANY;
    }

    /**
     * Get sample data
     *
     * @return array
     */
    public function getSampleData(): array
    {
        return [];
    }
}
