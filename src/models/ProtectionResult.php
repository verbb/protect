<?php
namespace verbb\protect\models;

use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\FindingType;

use JsonSerializable;

class ProtectionResult implements JsonSerializable
{
    // Properties
    // =========================================================================

    private readonly FindingCollection $_findings;

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly array $results,
        public readonly string $executionId,
        public readonly string $planFingerprint,
        public readonly float $durationMs,
        public readonly array $diagnostics = [],
        public readonly string $action = '',
    ) {
        $findings = [];

        foreach ($results as $result) {
            array_push($findings, ...iterator_to_array($result->findings));
        }

        $this->_findings = new FindingCollection($findings);
    }

    public function checksPassed(): bool
    {
        return !$this->hasAdverseFindings() && !$this->hasErrors();
    }

    public function hasAdverseFindings(): bool
    {
        return count(array_filter($this->results, fn(CheckResult $result) => $result->status === CheckStatus::Failed)) > 0;
    }

    public function hasErrors(): bool
    {
        return count(array_filter($this->results, fn(CheckResult $result) => $result->status === CheckStatus::Error)) > 0;
    }

    public function hasFinding(FindingType $type): bool
    {
        return count($this->findingsOfType($type)) > 0;
    }

    public function findings(): FindingCollection
    {
        return $this->_findings;
    }

    public function findingsOfType(FindingType $type): FindingCollection
    {
        return $this->_findings->ofType($type);
    }

    public function resultFor(string $check): ?CheckResult
    {
        foreach ($this->results as $result) {
            if ($result->check === $check) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Seconds until every exceeded rate limit has cleared, or null if none was exceeded.
     */
    public function retryAfter(): ?int
    {
        $retryAfter = null;

        foreach ($this->findingsOfType(FindingType::RateLimitExceeded) as $finding) {
            $retryAfter = max($retryAfter ?? 1, (int)($finding->metadata['retryAfter'] ?? 1));
        }

        return $retryAfter;
    }

    public function jsonSerialize(): array
    {
        return [
            'action' => $this->action,
            'executionId' => $this->executionId,
            'planFingerprint' => $this->planFingerprint,
            'durationMs' => $this->durationMs,
            'checks' => $this->diagnostics,
            'findings' => $this->_findings,
        ];
    }
}
