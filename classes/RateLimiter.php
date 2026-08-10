<?php

class RateLimiter {
    private array $lastRequestTime = [];
    private int $defaultDelayMs;

    public function __construct(int $defaultDelayMs = 500) {
        $this->defaultDelayMs = $defaultDelayMs;
    }

    public function canProceed(string $domain, ?int $customDelayMs = null): bool {
        $delay = $customDelayMs ?? $this->defaultDelayMs;
        if (!isset($this->lastRequestTime[$domain])) {
            return true;
        }
        $elapsed = (microtime(true) - $this->lastRequestTime[$domain]) * 1000;
        return $elapsed >= $delay;
    }

    public function recordRequest(string $domain): void {
        $this->lastRequestTime[$domain] = microtime(true);
    }

    public function waitIfNeeded(string $domain, ?int $customDelayMs = null): void {
        $delay = $customDelayMs ?? $this->defaultDelayMs;
        if (isset($this->lastRequestTime[$domain])) {
            $elapsed = (microtime(true) - $this->lastRequestTime[$domain]) * 1000;
            if ($elapsed < $delay) {
                usleep((int)(($delay - $elapsed) * 1000));
            }
        }
    }
}
