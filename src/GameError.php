<?php
declare(strict_types=1);

namespace Wm;

/** Error whose message is safe to show to players (maps to HTTP 400). */
final class GameError extends \RuntimeException
{
}
