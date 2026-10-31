<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

/** The download client definitively refused the request; safe to report as failed rather than unknown. */
class ClientRejected extends \RuntimeException
{
}
