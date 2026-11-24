<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

enum PermissionSource: string
{
    case EXPLICIT = 'explicit';
    case INHERITED = 'inherited';
    case DEFAULT_DENY = 'default_deny';
    case RESOURCE_OFF = 'resource_off';
    case HOST_POLICY = 'host_policy';
}
