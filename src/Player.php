<?php
declare(strict_types=1);

namespace Wm;

/** One seat in a game. Plain data; all behaviour lives in Game. */
final class Player
{
    public string $id = '';
    public string $token = '';
    public string $name = '';
    public ?string $avatar = null;
    public int $avatarV = 1;
    public bool $connected = false;
    public int $lastSeen = 0; // ms; refreshed by every poll
    public ?string $role = null;
    public bool $alive = true;
    public bool $ready = false;
    public int $profileAt = 0; // ms of the last profile change (rate limit)
    public array $state = [];  // role-specific memory: healUsed, poisonUsed, shotUsed
    public array $notes = [];  // private messages [{round, text}]

    public static function fromArray(array $a): self
    {
        $p = new self();
        foreach ($a as $k => $v) {
            if (property_exists($p, $k)) {
                $p->$k = $v;
            }
        }
        return $p;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
