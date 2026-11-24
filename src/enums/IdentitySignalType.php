<?php
namespace verbb\protect\enums;

enum IdentitySignalType: string
{
    case UserId = 'userId';
    case Email = 'email';
    case SessionId = 'sessionId';
    case ClientId = 'clientId';
}
