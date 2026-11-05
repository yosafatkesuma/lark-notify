<?php
// ── GuzzleHttp stubs ──────────────────────────────────────────────────────────

namespace GuzzleHttp\Psr7 {
    class Response {
        private string $body;
        private int $status;
        public function __construct(int $status, array $headers, string $body) {
            $this->status = $status; $this->body = $body;
        }
        public function getBody(): object {
            $b = $this->body;
            return new class($b) {
                public function __construct(private string $body) {}
                public function __toString(): string { return $this->body; }
            };
        }
        public function getStatusCode(): int { return $this->status; }
    }
}

namespace GuzzleHttp\Exception {
    class GuzzleException extends \RuntimeException {}
    class RequestException extends GuzzleException {}
    class ConnectException extends GuzzleException {}
}

namespace GuzzleHttp {
    use GuzzleHttp\Psr7\Response;
    class MockHandler {
        private array $queue;
        public array $urls = [];
        public function __construct(array $responses = []) { $this->queue = $responses; }
        public function __invoke(string $method, string $url, array $options): Response {
            $this->urls[] = $url;
            if (empty($this->queue)) throw new \RuntimeException("MockHandler queue is empty");
            return array_shift($this->queue);
        }
    }
    class HandlerStack {
        private $core;
        public static function create($handler = null): static { $s = new static(); $s->core = $handler; return $s; }
        public function push(callable $fn): void {}
        public function __invoke(string $method, string $url, array $options): Response {
            return ($this->core)($method, $url, $options);
        }
    }
    class Client {
        private $handler;
        public string $lastUrl = '';
        public function __construct(array $config = []) { $this->handler = $config['handler'] ?? null; }
        public function post(string $url, array $options = []): Response {
            $this->lastUrl = $url;
            if (!$this->handler) throw new \RuntimeException("No handler");
            return ($this->handler)('POST', $url, $options);
        }
        public function get(string $url, array $options = []): Response {
            if (!$this->handler) throw new \RuntimeException("No handler");
            return ($this->handler)('GET', $url, $options);
        }
    }
}

// ── Illuminate stubs ──────────────────────────────────────────────────────────

namespace Illuminate\Notifications {
    abstract class Notification {}
}
namespace Illuminate\Notifications\Events {
    class NotificationFailed {
        public function __construct(
            public $notifiable, public $notification,
            public string $channel, public array $data
        ) {}
    }
}
namespace Illuminate\Contracts\Events {
    interface Dispatcher { public function dispatch($event): void; }
}
