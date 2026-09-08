<?php

namespace Mgknetcom\Scopus\Enums;

enum SyncStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
