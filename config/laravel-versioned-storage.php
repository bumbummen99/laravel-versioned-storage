<?php

declare(strict_types=1);

return [

    'disk' => null,

    'connection' => null,

    // Must match the FPM hard limit; CLI/development uses a fresh limit per filesystem operation.
    'upload_timeout' => 60,

    // Allow accepted remote writes to finish after their writer has stopped.
    'cleanup_grace' => 10800,

];
