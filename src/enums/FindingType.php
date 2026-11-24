<?php
namespace verbb\protect\enums;

enum FindingType: string
{
    case PassiveCheckFailed = 'passive-check-failed';
    case ChallengeFailed = 'challenge-failed';
    case SpamDetected = 'spam-detected';
    case RateLimitExceeded = 'rate-limit-exceeded';
    case ReplayDetected = 'replay-detected';
    case ProviderUnavailable = 'provider-unavailable';
    case InvalidProviderResponse = 'invalid-provider-response';
    case MissingRequiredSignal = 'missing-required-signal';
    case ConfigurationError = 'configuration-error';
}
