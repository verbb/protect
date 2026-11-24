<?php
namespace verbb\protect\enums;

enum ContinuationStrategy: string
{
    case RunAll = 'run-all';
    case StopOnFailure = 'stop-on-failure';
    case StopOnFailureOrError = 'stop-on-failure-or-error';
}
