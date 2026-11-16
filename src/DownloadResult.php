<?php

declare(strict_types=1);

namespace Mirror;

enum DownloadResult
{
    case Complete;
    /** The deadline ran out. The partial file was kept so a later run can resume. */
    case Stopped;
    case Failed;
}
