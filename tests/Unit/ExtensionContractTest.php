<?php

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\base\ChallengeProvider;
use verbb\protect\base\ClassifierProvider;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Classification;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\errors\DuplicateRegistrationException;
use verbb\protect\errors\InvalidComponentException;
use verbb\protect\models\CheckResult;
use verbb\protect\models\Finding;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProviderResult;
use verbb\protect\services\Challenges;
use verbb\protect\services\Checks;
use verbb\protect\services\Classifiers;

use yii\base\Event;

class ExtensionCheck extends Check
{
    public CheckStatus $status = CheckStatus::Passed;

    public static function handle(): string { return 'extension-check'; }
    public static function stage(): ExecutionStage { return ExecutionStage::Passive; }

    public function check(ProtectionContext $context): CheckResult
    {
        $findings = $this->status === CheckStatus::Error ? [new Finding(FindingType::ProviderUnavailable, static::class, 'extension-unavailable', 'Unavailable.', error: true)] : [];

        return new CheckResult(static::class, $this->status, $findings);
    }
}

class SecondExtensionCheck extends ExtensionCheck
{
    public static function handle(): string { return 'second-extension'; }
}

class ThirdExtensionCheck extends ExtensionCheck
{
    public static function handle(): string { return 'third-extension'; }
}

class StatefulExtensionCheck extends ExtensionCheck
{
    public static function handle(): string { return 'stateful-extension'; }
    public static function stage(): ExecutionStage { return ExecutionStage::Stateful; }
}

class ConflictingCheck extends ExtensionCheck
{
    public static function handle(): string { return 'honeypot'; }
}

class MalformedHandleCheck extends ExtensionCheck
{
    public static function handle(): string { return 'Not A Handle'; }
}

class ExtensionChallenge extends ChallengeProvider
{
    public CheckStatus $status = CheckStatus::Passed;
    public string $siteKey = 'public-key';
    public string $secretKey = 'secret';
    public string $expectedAction = 'extension';
    public array $hostnames = ['example.test'];

    public static function handle(): string { return 'extension-challenge'; }

    public function verify(string $token, ProtectionContext $context): ProviderResult
    {
        return new ProviderResult($token === 'valid-token' ? $this->status : CheckStatus::Failed, 'extension-result');
    }

    protected function siteVerifyUrl(): string { return 'https://example.test/verify'; }
}

class ConflictingChallenge extends ExtensionChallenge
{
    public static function handle(): string { return 'turnstile'; }
}

class ExtensionClassifier extends ClassifierProvider
{
    public CheckStatus $status = CheckStatus::Passed;

    public static function handle(): string { return 'extension-classifier'; }

    public function classify(ProtectionContext $context): ProviderResult
    {
        return new ProviderResult($this->status, 'extension-result');
    }
}

class ConflictingClassifier extends ExtensionClassifier
{
    public static function handle(): string { return 'akismet'; }
}

function registerProtectTypes(string $service, string $event, array $types): Closure
{
    $listener = static function($e) use ($types): void {
        array_push($e->types, ...$types);
    };

    Event::on($service, $event, $listener);

    return static fn() => Event::off($service, $event, $listener);
}

it('prepares and runs third-party registrations through the public API', function() {
    $off = [
        registerProtectTypes(Checks::class, Checks::EVENT_REGISTER_CHECK_TYPES, [ExtensionCheck::class]),
        registerProtectTypes(Challenges::class, Challenges::EVENT_REGISTER_CHALLENGE_TYPES, [ExtensionChallenge::class]),
        registerProtectTypes(Classifiers::class, Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES, [ExtensionClassifier::class]),
    ];

    try {
        $protect = Protect::getInstance();
        $plan = (new ProtectionPlan('extensions'))
            ->add(new ExtensionCheck())
            ->add(new Challenge(['provider' => new ExtensionChallenge()]))
            ->add(new Classification(['provider' => new ExtensionClassifier(['status' => CheckStatus::Failed])]));
        $manifest = $protect->prepare(new ProtectionContext('extension.submit'), $plan)->browserManifest();

        expect($manifest->challenges[0]['provider'])->toBe('extension-challenge');

        $result = $protect->check(new ProtectionContext('extension.submit', content: new ProtectionContent('Example'), evidence: new ProtectionEvidence($manifest->preparation, challenges: ['extension-challenge' => 'valid-token'])), $plan);

        expect($result->resultFor(Challenge::class)->status)->toBe(CheckStatus::Passed)
            ->and($result->hasFinding(FindingType::SpamDetected))->toBeTrue()
            ->and($result->hasErrors())->toBeFalse()
            ->and($result->results)->toHaveCount(3)
            ->and(json_encode($result))->not->toContain('valid-token');
    } finally {
        array_map(fn($undo) => $undo(), $off);
    }
});

it('rejects conflicting handles but allows the same class twice', function(string $service, string $event, string $method, string $extension, string $conflict) {
    $off = registerProtectTypes($service, $event, [$extension, $extension]);

    try {
        expect(Protect::getInstance()->get(lcfirst(substr(strrchr($service, '\\'), 1)))->$method())->toContain($extension);
    } finally {
        $off();
    }

    Craft::$app->setModule(Protect::ID, null);
    $off = registerProtectTypes($service, $event, [$conflict]);

    try {
        expect(fn() => Protect::getInstance()->get(lcfirst(substr(strrchr($service, '\\'), 1)))->$method())->toThrow(DuplicateRegistrationException::class);
    } finally {
        $off();
    }
})->with([
    'checks' => [Checks::class, Checks::EVENT_REGISTER_CHECK_TYPES, 'getAllCheckTypes', ExtensionCheck::class, ConflictingCheck::class],
    'challenges' => [Challenges::class, Challenges::EVENT_REGISTER_CHALLENGE_TYPES, 'getAllChallengeTypes', ExtensionChallenge::class, ConflictingChallenge::class],
    'classifiers' => [Classifiers::class, Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES, 'getAllClassifierTypes', ExtensionClassifier::class, ConflictingClassifier::class],
]);

it('rejects malformed registrations', function(string $type) {
    $off = registerProtectTypes(Checks::class, Checks::EVENT_REGISTER_CHECK_TYPES, [$type]);

    try {
        expect(fn() => Protect::getInstance()->getChecks()->getAllCheckTypes())->toThrow(InvalidComponentException::class);
    } finally {
        $off();
    }
})->with([stdClass::class, MalformedHandleCheck::class]);

it('refuses a plan using an unregistered check or provider', function() {
    expect(fn() => (new ProtectionPlan())->add(new ExtensionCheck())->validate())->toThrow(InvalidComponentException::class);
    expect(fn() => (new ProtectionPlan())->add(new Challenge(['provider' => new ExtensionChallenge()]))->validate())->toThrow(InvalidComponentException::class);
    expect(fn() => (new ProtectionPlan())->add(new Classification(['provider' => new ExtensionClassifier()]))->validate())->toThrow(InvalidComponentException::class);
});

it('orders by stage, priority and stable insertion', function() {
    $off = registerProtectTypes(Checks::class, Checks::EVENT_REGISTER_CHECK_TYPES, [StatefulExtensionCheck::class, ExtensionCheck::class, SecondExtensionCheck::class, ThirdExtensionCheck::class]);

    try {
        $plan = (new ProtectionPlan())
            ->add(new StatefulExtensionCheck(['priority' => -100]))
            ->add(new ExtensionCheck(['priority' => 10]))
            ->add(new SecondExtensionCheck(['priority' => -1]))
            ->add(new ThirdExtensionCheck(['priority' => 10]));
        $result = Protect::getInstance()->check(new ProtectionContext('ordered'), $plan);

        expect(array_column($result->results, 'check'))->toBe([SecondExtensionCheck::class, ExtensionCheck::class, ThirdExtensionCheck::class, StatefulExtensionCheck::class]);

        foreach ($result->results as $check) {
            expect($check->durationMs)->toBeGreaterThanOrEqual(0);
        }
    } finally {
        $off();
    }
});

it('respects plan and per-check continuation for operational errors', function() {
    $off = registerProtectTypes(Checks::class, Checks::EVENT_REGISTER_CHECK_TYPES, [ExtensionCheck::class, SecondExtensionCheck::class]);

    try {
        $protect = Protect::getInstance();

        foreach (ContinuationStrategy::cases() as $strategy) {
            $plan = (new ProtectionPlan(continuation: $strategy))
                ->add(new ExtensionCheck(['status' => CheckStatus::Error]))
                ->add(new SecondExtensionCheck());
            $result = $protect->check(new ProtectionContext('error'), $plan);

            expect($result->results)->toHaveCount($strategy === ContinuationStrategy::StopOnFailureOrError ? 1 : 2)
                ->and($result->hasErrors())->toBeTrue()
                ->and($result->hasAdverseFindings())->toBeFalse()
                ->and($result->checksPassed())->toBeFalse();
        }

        $plan = (new ProtectionPlan(continuation: ContinuationStrategy::StopOnFailureOrError))
            ->add(new ExtensionCheck(['status' => CheckStatus::Error, 'continuation' => ContinuationStrategy::RunAll]))
            ->add(new SecondExtensionCheck());

        expect($protect->check(new ProtectionContext('override'), $plan)->results)->toHaveCount(2);
    } finally {
        $off();
    }
});

it('keeps replay consumed after a later provider outage', function() {
    $off = registerProtectTypes(Challenges::class, Challenges::EVENT_REGISTER_CHALLENGE_TYPES, [ExtensionChallenge::class]);

    try {
        $protect = Protect::getInstance();
        $plan = (new ProtectionPlan())
            ->add(new ReplayProtection())
            ->add(new Challenge(['provider' => new ExtensionChallenge(['status' => CheckStatus::Error])]));
        $manifest = $protect->prepare(new ProtectionContext('attempt'), $plan)->browserManifest();
        $context = new ProtectionContext('attempt', evidence: new ProtectionEvidence($manifest->preparation, challenges: ['extension-challenge' => 'valid-token']));

        expect($protect->check($context, $plan)->hasErrors())->toBeTrue()
            ->and($protect->check($context, $plan)->hasFinding(FindingType::ReplayDetected))->toBeTrue();
    } finally {
        $off();
    }
});
