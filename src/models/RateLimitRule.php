<?php
namespace verbb\protect\models;

use verbb\protect\base\FingerprintTrait;

use craft\base\Model;

class RateLimitRule extends Model
{
    // Traits
    // =========================================================================

    use FingerprintTrait;

    // Properties
    // =========================================================================

    public string $handle = 'default';
    public int $limit = 5;
    public int $window = 60;
    public array $dimensions = ['request.ipAddress'];
    public bool $includeAction = true;
    public bool $includeSubject = false;
    public bool $includeSite = true;

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-z][a-z0-9-]*$/'];
        $rules[] = [['limit', 'window'], 'integer', 'min' => 1, 'max' => 86400];
        $rules[] = [['dimensions'], 'required'];

        return $rules;
    }
}
