<?php
namespace verbb\protect\models;

use verbb\protect\enums\FindingType;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class FindingCollection implements Countable, IteratorAggregate, JsonSerializable
{
    // Public Methods
    // =========================================================================

    public function __construct(private readonly array $items = [])
    {
        foreach ($items as $item) {
            if (!$item instanceof Finding) {
                throw new InvalidArgumentException('Expected findings.');
            }
        }
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function ofType(FindingType $type): self
    {
        return new self(array_values(array_filter($this->items, fn(Finding $finding) => $finding->type === $type)));
    }

    public function forCheck(string $check): self
    {
        return new self(array_values(array_filter($this->items, fn(Finding $finding) => $finding->check === $check)));
    }

    public function forProvider(string $provider): self
    {
        return new self(array_values(array_filter($this->items, fn(Finding $finding) => $finding->provider === $provider)));
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
