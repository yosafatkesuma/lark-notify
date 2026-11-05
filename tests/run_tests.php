<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'NotificationChannels\\Lark\\';
    $base   = __DIR__ . '/../src/';
    if (str_starts_with($class, $prefix)) {
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) require_once $file;
    }
});

use GuzzleHttp\Client;
use GuzzleHttp\MockHandler;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Lark\LarkClient;
use NotificationChannels\Lark\Messages\LarkMessage;
use NotificationChannels\Lark\Messages\LarkCard;
use NotificationChannels\Lark\Messages\LarkFile;
use NotificationChannels\Lark\Messages\LarkLocation;
use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

// ── Tiny test framework ───────────────────────────────────────────────────────

$PASS = 0; $FAIL = 0; $FAILED_NAMES = [];

function it(string $name, callable $fn): void {
    global $PASS, $FAIL, $FAILED_NAMES;
    try { $fn(); echo "\033[32m  ✓\033[0m {$name}\n"; $PASS++;
    } catch (\Throwable $e) {
        echo "\033[31m  ✗\033[0m {$name}\n    → " . $e->getMessage() . "\n";
        $FAIL++; $FAILED_NAMES[] = $name;
    }
}

function describe(string $name, callable $fn): void { echo "\n\033[1m{$name}\033[0m\n"; $fn(); }

function expect(mixed $actual): object {
    return new class($actual) {
        public function __construct(private mixed $v) {}
        public function toBe(mixed $e): void {
            if ($this->v !== $e) throw new \Exception("Expected ".json_encode($e).", got ".json_encode($this->v));
        }
        public function toEqual(mixed $e): void { $this->toBe($e); }
        public function toContain(string $n): void {
            if (!str_contains((string)$this->v, $n)) throw new \Exception("Expected to contain '{$n}', got: {$this->v}");
        }
        public function toBeInstanceOf(string $c): void {
            if (!($this->v instanceof $c)) throw new \Exception("Expected {$c}, got ".get_class($this->v));
        }
        public function toBeTrue(): void  { $this->toBe(true); }
        public function toBeFalse(): void { $this->toBe(false); }
        public function toBe_null(): void { if ($this->v !== null) throw new \Exception("Expected null"); }
        public function toHaveCount(int $n): void {
            $c = is_countable($this->v) ? count($this->v) : -1;
            if ($c !== $n) throw new \Exception("Expected count {$n}, got {$c}");
        }
        public function toHaveKey(string $k): void {
            if (!array_key_exists($k, (array)$this->v)) throw new \Exception("Expected key '{$k}'");
        }
        public function notToHaveKey(string $k): void {
            if (array_key_exists($k, (array)$this->v)) throw new \Exception("Key '{$k}' should not exist");
        }
    };
}

function throws(callable $fn, string $class): void {
    try { $fn(); throw new \Exception("Expected {$class} was not thrown"); }
    catch (\Throwable $e) {
        if (!($e instanceof $class))
            throw new \Exception("Expected {$class}, got ".get_class($e).": ".$e->getMessage());
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

function makeClient(array $responses): LarkClient {
    $mock = new MockHandler($responses);
    return new LarkClient(new Client(['handler' => $mock]), 'cli_test', 'sec_test');
}
function tok(): Response { return new Response(200, [], json_encode(['tenant_access_token' => 'tok_abc', 'expire' => 7200])); }
function ok():  Response { return new Response(200, [], json_encode(['code' => 0, 'msg' => 'success'])); }
function err(int $code, string $msg): Response { return new Response(200, [], json_encode(['code' => $code, 'msg' => $msg])); }

function firstAction(array $p): array {
    return array_values(array_filter($p['content']['elements'], fn($e) => $e['tag'] === 'action'))[0];
}
function firstDiv(array $p): array {
    return array_values(array_filter($p['content']['elements'], fn($e) => isset($e['text'])))[0];
}
function firstDivWithFields(array $p): array {
    return array_values(array_filter($p['content']['elements'], fn($e) => isset($e['fields'])))[0];
}

// ═════════════════════════════════════════════════════════════════════════════
// TESTS
// ═════════════════════════════════════════════════════════════════════════════

describe("LarkMessage", function() {
    it("::create() sets content", fn() =>
        expect(LarkMessage::create('Hello')->getContent())->toBe('Hello'));

    it("->line() appends with newline", fn() =>
        expect(LarkMessage::create('Hello')->line('World')->getContent())->toBe("Hello\nWorld"));

    it("->lineIf() respects condition", function() {
        $msg = LarkMessage::create('Base')->lineIf(false, 'hidden')->lineIf(true, 'visible');
        expect($msg->getContent())->toBe("Base\nvisible");
    });

    it("->to() sets chatId and type", function() {
        $msg = LarkMessage::create()->to('ou_abc', 'user_id');
        expect($msg->getChatId())->toBe('ou_abc');
        expect($msg->getChatIdType())->toBe('user_id');
    });

    it("->to() defaults type to open_id", fn() =>
        expect(LarkMessage::create()->to('ou_abc')->getChatIdType())->toBe('open_id'));

    it("plain text produces msg_type 'text'", function() {
        $p = LarkMessage::create('Hi')->toArray();
        expect($p['msg_type'])->toBe('text');
        expect($p['content']['text'])->toBe('Hi');
    });

    it("button() upgrades msg_type to 'interactive'", fn() =>
        expect(LarkMessage::create('Hi')->button('Click', 'https://x.com')->toArray()['msg_type'])->toBe('interactive'));

    it("button() sets url and type", function() {
        $btn = firstAction(LarkMessage::create('Hi')->button('Go', 'https://a.com', 'primary')->toArray())['actions'][0];
        expect($btn['url'])->toBe('https://a.com');
        expect($btn['type'])->toBe('primary');
    });

    it("buttonWithCallback() sets value, no url", function() {
        $btn = firstAction(LarkMessage::create('Hi')->buttonWithCallback('OK', ['a' => 'b'])->toArray())['actions'][0];
        expect($btn['value'])->toEqual(['a' => 'b']);
        expect($btn)->notToHaveKey('url');
    });

    it("multiple buttons all appear in actions", function() {
        $p = LarkMessage::create('Hi')->button('A', 'https://a.com')->button('B', 'https://b.com')->toArray();
        expect(firstAction($p)['actions'])->toHaveCount(2);
    });

    it("options() merges extra params at top level", function() {
        $p = LarkMessage::create('Hi')->options(['custom_flag' => true])->toArray();
        expect($p['custom_flag'])->toBeTrue();
    });

    it("disableNotification() keeps payload intact", fn() =>
        expect(LarkMessage::create('Hi')->disableNotification()->toArray()['msg_type'])->toBe('text'));

    it("buttonWithMultiUrl() sets multi_url", function() {
        $btn = firstAction(
            LarkMessage::create('Hi')
                ->buttonWithMultiUrl('Open', ['url' => 'https://x.com', 'ios' => 'https://ios.x.com'])
                ->toArray()
        )['actions'][0];
        expect($btn['multi_url']['url'])->toBe('https://x.com');
        expect($btn['multi_url']['ios_url'])->toBe('https://ios.x.com');
    });
});

describe("LarkCard", function() {
    it("::create() sets title", fn() =>
        expect(LarkCard::create('My Title')->toArray()['content']['header']['title']['content'])->toBe('My Title'));

    it("color() sets template", fn() =>
        expect(LarkCard::create('T')->color('red')->toArray()['content']['header']['template'])->toBe('red'));

    it("default color is blue", fn() =>
        expect(LarkCard::create('T')->toArray()['content']['header']['template'])->toBe('blue'));

    it("msg_type is 'interactive'", fn() =>
        expect(LarkCard::create('T')->toArray()['msg_type'])->toBe('interactive'));

    it("field() adds two-column entry", function() {
        $div = firstDivWithFields(LarkCard::create('T')->field('Status', 'Shipped')->toArray());
        expect($div['fields'][0]['is_short'])->toBeTrue();
        expect($div['fields'][0]['text']['content'])->toContain('Status');
        expect($div['fields'][0]['text']['content'])->toContain('Shipped');
    });

    it("lineIf() works", function() {
        $p = LarkCard::create('T')->lineIf(false, 'no')->lineIf(true, 'yes')->toArray();
        expect(firstDiv($p)['text']['content'])->toBe('yes');
    });

    it("button() appends to actions", function() {
        $a = firstAction(LarkCard::create('T')->button('Go', 'https://x.com', 'primary')->toArray())['actions'][0];
        expect($a['url'])->toBe('https://x.com');
        expect($a['type'])->toBe('primary');
    });

    it("buttonWithCallback() sets value", function() {
        $a = firstAction(LarkCard::create('T')->buttonWithCallback('OK', ['k' => 'v'])->toArray())['actions'][0];
        expect($a['value'])->toEqual(['k' => 'v']);
    });

    it("wide_screen_mode defaults to true", fn() =>
        expect(LarkCard::create('T')->toArray()['content']['config']['wide_screen_mode'])->toBeTrue());

    it("wideScreenMode(false) disables it", fn() =>
        expect(LarkCard::create('T')->wideScreenMode(false)->toArray()['content']['config']['wide_screen_mode'])->toBeFalse());
});

describe("LarkFile", function() {
    it("image() sets type and image_key", function() {
        $p = LarkFile::create()->image('img_key_123')->toArray();
        expect($p['msg_type'])->toBe('image');
        expect($p['content']['image_key'])->toBe('img_key_123');
    });

    it("file() sets type and file_key", function() {
        $p = LarkFile::create()->file('file_key_abc')->toArray();
        expect($p['msg_type'])->toBe('file');
        expect($p['content']['file_key'])->toBe('file_key_abc');
    });

    it("audio() sets type to 'audio'", fn() =>
        expect(LarkFile::create()->audio('k')->toArray()['msg_type'])->toBe('audio'));

    it("video() sets type to 'media'", fn() =>
        expect(LarkFile::create()->video('k')->toArray()['msg_type'])->toBe('media'));

    it("content() adds caption", fn() =>
        expect(LarkFile::create()->image('k')->content('Caption')->toArray()['content']['text'])->toBe('Caption'));

    it("options() merges extra params", function() {
        $p = LarkFile::create()->image('k')->options(['extra' => 42])->toArray();
        expect($p['extra'])->toBe(42);
    });

    it("throws CouldNotSendNotification without file key", fn() =>
        throws(fn() => LarkFile::create()->toArray(), CouldNotSendNotification::class));
});

describe("LarkLocation", function() {
    it("builds payload with coordinates in _meta", function() {
        $p = LarkLocation::create()->latitude(1.3521)->longitude(103.8198)->toArray();
        expect($p['_meta']['latitude'])->toBe(1.3521);
        expect($p['_meta']['longitude'])->toBe(103.8198);
    });

    it("text content includes coordinates", function() {
        $p = LarkLocation::create()->latitude(1.35)->longitude(103.82)->toArray();
        expect($p['content']['text'])->toContain('1.35');
        expect($p['content']['text'])->toContain('103.82');
    });

    it("throws without latitude", fn() =>
        throws(fn() => LarkLocation::create()->longitude(103.8198)->toArray(), CouldNotSendNotification::class));

    it("throws without longitude", fn() =>
        throws(fn() => LarkLocation::create()->latitude(1.3521)->toArray(), CouldNotSendNotification::class));

    it("to() sets chatId and type", function() {
        $loc = LarkLocation::create()->to('ou_test', 'chat_id');
        expect($loc->getChatId())->toBe('ou_test');
        expect($loc->getChatIdType())->toBe('chat_id');
    });

    it("options() merges extra params", function() {
        $p = LarkLocation::create()->latitude(1.0)->longitude(2.0)->options(['note' => 'HQ'])->toArray();
        expect($p['note'])->toBe('HQ');
    });
});

describe("CouldNotSendNotification", function() {
    it("serviceRespondedWithAnError includes HTTP status", function() {
        $e = CouldNotSendNotification::serviceRespondedWithAnError(500, 'Error');
        expect($e->getMessage())->toContain('500');
        expect($e)->toBeInstanceOf(\Exception::class);
    });

    it("larkRespondedWithAnError includes API code", fn() =>
        expect(CouldNotSendNotification::larkRespondedWithAnError(99991672, 'expired')->getMessage())->toContain('99991672'));

    it("missingCredentials mentions app_id", fn() =>
        expect(CouldNotSendNotification::missingCredentials()->getMessage())->toContain('app_id'));

    it("noRecipient mentions recipient", fn() =>
        expect(CouldNotSendNotification::noRecipient()->getMessage())->toContain('recipient'));

    it("couldNotCommunicateWithLark includes reason", fn() =>
        expect(CouldNotSendNotification::couldNotCommunicateWithLark('timeout')->getMessage())->toContain('timeout'));

    it("is instanceof Exception", fn() =>
        expect(CouldNotSendNotification::missingCredentials())->toBeInstanceOf(\Exception::class));
});

describe("LarkClient", function() {
    it("getTenantToken returns token from API", function() {
        expect(makeClient([tok()])->getTenantToken())->toBe('tok_abc');
    });

    it("getTenantToken caches — only one HTTP call made", function() {
        $mock = new MockHandler([tok()]);
        $client = new LarkClient(new Client(['handler' => $mock]), 'cli_x', 'sec_y');
        $t1 = $client->getTenantToken();
        $t2 = $client->getTenantToken();
        expect($t1)->toBe($t2);
        expect(count($mock->urls))->toBe(1);
    });

    it("sendViaWebhook posts to the correct URL", function() {
        $mock = new MockHandler([ok()]);
        $http = new Client(['handler' => $mock]);
        (new LarkClient($http, '', ''))->sendViaWebhook(
            'https://hook.example.com/abc',
            ['msg_type' => 'text', 'content' => ['text' => 'hi']]
        );
        expect($mock->urls[0])->toBe('https://hook.example.com/abc');
    });

    it("throws CouldNotSendNotification on non-zero Lark code", fn() =>
        throws(function() {
            makeClient([err(99991672, 'token expired')])
                ->sendViaWebhook('https://hook.example.com', ['msg_type' => 'text', 'content' => ['text' => 'hi']]);
        }, CouldNotSendNotification::class));

    it("throws missingCredentials when app_id/app_secret are empty", fn() =>
        throws(function() {
            (new LarkClient(new Client(['handler' => new MockHandler([])]), '', ''))->getTenantToken();
        }, CouldNotSendNotification::class));

    it("sendViaBot calls token endpoint then messages endpoint", function() {
        $mock = new MockHandler([tok(), ok()]);
        $client = new LarkClient(new Client(['handler' => $mock]), 'cli_x', 'sec_y');
        $client->sendViaBot('ou_abc', 'open_id', ['msg_type' => 'text', 'content' => ['text' => 'hi']]);
        expect($mock->urls[0])->toContain('tenant_access_token');
        expect($mock->urls[1])->toContain('im/v1/messages');
    });
});

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n";
$total = $PASS + $FAIL;
if ($FAIL === 0) {
    echo "\033[1;32m ✓ {$PASS}/{$total} tests passed\033[0m\n\n";
} else {
    echo "\033[1;31m ✗ {$PASS}/{$total} passed, {$FAIL} failed\033[0m\n\n";
    foreach ($FAILED_NAMES as $n) echo "  - {$n}\n";
    exit(1);
}
