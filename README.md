# Lark Notifications Channel for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/yosafatkesuma/lark-notify.svg?style=flat-square)](https://packagist.org/packages/yosafatkesuma/lark-notify)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

This package makes it easy to send **Lark / Feishu** notifications from Laravel via the Lark Bot API or a custom bot webhook.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Setting up your Lark bot](#setting-up-your-lark-bot)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Routing notifications](#routing-notifications)
  - [Text messages](#text-messages)
  - [Interactive cards](#interactive-cards)
    - [Images in cards](#images-in-cards)
  - [Images and files](#images-and-files)
  - [Location](#location)
  - [On-demand notifications](#on-demand-notifications)
  - [Handling failures](#handling-failures)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, 12.x or 13.x

Your PHP version must also meet Laravel's own requirement (Laravel 11/12 need PHP 8.2+, Laravel 13 needs PHP 8.3+).

## Installation

Install the package via Composer:

```bash
composer require yosafatkesuma/lark-notify
```

The service provider (`NotificationChannels\Lark\LarkServiceProvider`) and the `Lark` facade are registered automatically through Laravel package discovery.

<details>
<summary>Package discovery disabled?</summary>

Register the provider manually.

**Laravel 11, 12, 13** — `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    NotificationChannels\Lark\LarkServiceProvider::class,
];
```

**Laravel 10** — `config/app.php`:

```php
'providers' => [
    // ...
    NotificationChannels\Lark\LarkServiceProvider::class,
],

'aliases' => [
    // ...
    'Lark' => NotificationChannels\Lark\Facades\Lark::class,
],
```

</details>

Optionally publish the config file to `config/lark.php`:

```bash
php artisan vendor:publish --tag=lark-config
```

## Setting up your Lark bot

You can send notifications in two ways. Pick one (or use both).

### Option A — Custom bot webhook (simplest)

Good for posting to a single group chat. No app credentials needed.

1. Open the target group chat in Lark → **Settings** → **Bots** → **Add Bot** → **Custom Bot**.
2. Copy the webhook URL.
3. Set `LARK_WEBHOOK_URL` in your `.env`.

### Option B — Bot API (app bot)

Needed to message individual users or any chat by ID, and to upload images/files.

1. Create a custom app on the [Lark Open Platform](https://open.larksuite.com/app) (or [Feishu Open Platform](https://open.feishu.cn/app)).
2. Enable the **Bot** capability.
3. Grant the messaging permissions (e.g. `im:message:send_as_bot`) and, if you upload media, `im:resource`.
4. Publish a version of the app so the permissions take effect.
5. Copy the **App ID** and **App Secret** into your `.env`.
6. Add the bot to any group chat you want it to post in.

## Configuration

Add the variables you need to `.env`:

```dotenv
# Option A: webhook
LARK_WEBHOOK_URL=https://open.larksuite.com/open-apis/bot/v2/hook/xxxxxxxx

# Option B: Bot API
LARK_APP_ID=cli_xxxxxxxxxxxx
LARK_APP_SECRET=xxxxxxxxxxxxxxxx

# Optional
LARK_API_BASE_URI=https://open.larksuite.com/open-apis   # Feishu (China): https://open.feishu.cn/open-apis
LARK_TIMEOUT=10
```

If you use Feishu (China), set `LARK_API_BASE_URI=https://open.feishu.cn/open-apis`.

## Usage

Return `LarkChannel::class` from your notification's `via()` method and build the message in `toLark()`:

```php
use Illuminate\Notifications\Notification;
use NotificationChannels\Lark\LarkChannel;
use NotificationChannels\Lark\Messages\LarkMessage;

class InvoicePaid extends Notification
{
    public function __construct(private Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return [LarkChannel::class];
    }

    public function toLark(object $notifiable): LarkMessage
    {
        return LarkMessage::create("Invoice #{$this->invoice->id} has been paid")
            ->line("Amount: {$this->invoice->amount}")
            ->button('View invoice', route('invoices.show', $this->invoice));
    }
}
```

Then send it as usual:

```php
$user->notify(new InvoicePaid($invoice));
```

Notifications can be queued like any other Laravel notification by implementing `ShouldQueue`.

### Routing notifications

The recipient is resolved in this order:

1. `->to($id, $type)` on the message
2. `routeNotificationForLark()` on the notifiable
3. `LARK_WEBHOOK_URL` from config

Options 1 and 2 use the Bot API; option 3 uses the webhook.

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForLark($notification): ?string
    {
        return $this->lark_open_id;
    }
}
```

An ID returned from `routeNotificationForLark()` is sent as an `open_id`. To target another ID type, set it on the message:

```php
LarkMessage::create('Deploy finished')
    ->to('oc_xxxxxxxxxxxx', 'chat_id');   // open_id | user_id | union_id | email | chat_id
```

### Text messages

```php
LarkMessage::create('Hello!')
    ->line('Second line')
    ->lineIf($order->isUrgent(), '⚠️ Urgent')
    ->button('Open dashboard', 'https://example.com/dashboard', 'primary')
    ->buttonWithCallback('Approve', ['action' => 'approve', 'id' => 42]);
```

Adding a button automatically turns the message into an interactive message.

### Interactive cards

```php
use NotificationChannels\Lark\Messages\LarkCard;

public function toLark(object $notifiable): LarkCard
{
    return LarkCard::create('Server alert')
        ->color('red')                       // blue | green | red | yellow | grey | purple
        ->content('**CPU usage** above 90% on `web-01`')
        ->field('Host', 'web-01')
        ->field('Region', 'ap-southeast-1')
        ->button('Open Grafana', 'https://grafana.example.com', 'danger');
}
```

Card body text supports Lark Markdown.

#### Images in cards

Cards can embed images. Pass an existing `image_key`, or let the channel upload the image for you right before sending:

```php
LarkCard::create('Daily report')
    ->content('Sales are up 12% today')
    ->image('img_v2_xxxxxxxx', 'Existing image')                    // already-uploaded key
    ->attachImage(storage_path('app/charts/sales.png'), 'Sales')     // local file
    ->attachImageData(Storage::get('charts/traffic.png'), 'traffic.png') // raw bytes
    ->attachFromRequest($request->file('screenshot'));               // uploaded file
```

Each image method also accepts an optional display `mode` (`crop_center`, `fit_horizontal` or `stretch`) and a `preview` flag. When you leave them out, Lark's defaults apply: a centre crop, with click-to-preview on.

```php
->image('img_v2_xxxxxxxx', 'Diagram', mode: 'fit_horizontal', preview: false)
```

Notes:

- Uploading requires Bot API credentials (`LARK_APP_ID` / `LARK_APP_SECRET`), even if the card itself is delivered through a webhook.
- Cards can only embed **images**. Adding a file, audio or video attachment to a card throws a `CouldNotSendNotification` exception before anything is uploaded. Send those with [`LarkFile`](#images-and-files) instead.
- Avoid `attachFromRequest()` in queued notifications, because the uploaded temp file is gone by the time the job runs. Upload the file first with `Lark::uploadFromRequest()` and pass the key to `image()` instead.

### Images and files

Upload the media first (requires Bot API credentials), then send the returned key with `LarkFile`:

```php
use NotificationChannels\Lark\Facades\Lark;
use NotificationChannels\Lark\Messages\LarkFile;

public function toLark(object $notifiable): LarkFile
{
    $imageKey = Lark::uploadImage(storage_path('app/reports/chart.png'));

    return LarkFile::create()->image($imageKey);
}
```

```php
$fileKey = Lark::uploadFile(storage_path('app/reports/monthly.pdf'));

return LarkFile::create()->file($fileKey);
```

You can also upload directly from a request: `Lark::uploadFromRequest($request->file('attachment'))`.
`LarkFile` also supports `->audio($fileKey)` and `->video($fileKey)`.

### Location

```php
use NotificationChannels\Lark\Messages\LarkLocation;

return LarkLocation::create()
    ->latitude(-6.200000)
    ->longitude(106.816666);
```

The location is delivered as a text message containing the coordinates.

### On-demand notifications

Send to someone who isn't a model in your app:

```php
use Illuminate\Support\Facades\Notification;

Notification::route('lark', 'ou_xxxxxxxxxxxx')
    ->notify(new InvoicePaid($invoice));
```

### Handling failures

If Lark returns an error, a `NotificationChannels\Lark\Exceptions\CouldNotSendNotification` exception is thrown and Laravel's `Illuminate\Notifications\Events\NotificationFailed` event is dispatched with `channel = 'lark'`:

```php
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (NotificationFailed $event) {
    if ($event->channel === 'lark') {
        logger()->error('Lark notification failed', $event->data);
    }
});
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
