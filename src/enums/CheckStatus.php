<?php
namespace verbb\protect\enums;

enum CheckStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Error = 'error';
}
