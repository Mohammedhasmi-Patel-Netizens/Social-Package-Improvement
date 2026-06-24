<?php

namespace BeePost\SocialPoster\Enums;

enum PlanDuration: int 
{
    use EnumTrait;

    case MONTHLY    = 1;
    case YEARLY     = 2;
    case UNLIMITED  = -1;
}
