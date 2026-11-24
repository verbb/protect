<?php
namespace verbb\protect\enums;

enum ExecutionStage: int
{
    case Passive = 10;
    case Stateful = 20;
    case Challenge = 30;
    case Classification = 40;
}
