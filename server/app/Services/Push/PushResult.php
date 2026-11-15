<?php

namespace App\Services\Push;

/** What happened to one push across an account's (or every customer's) tokens. */
class PushResult
{
    public int $sent = 0;

    /** @var list<string> tokens FCM says will never work again; the sender deletes them */
    public array $invalid = [];

    /** @var array<string, string> token => reason, for anything else that went wrong */
    public array $failed = [];

    public function merge(self $other): self
    {
        $this->sent += $other->sent;
        $this->invalid = [...$this->invalid, ...$other->invalid];
        $this->failed = [...$this->failed, ...$other->failed];

        return $this;
    }
}
