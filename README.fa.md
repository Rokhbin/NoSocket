# NoSocket

**ارتباط بلادرنگ برای هاست اشتراکی**

فارسی | [English](README.md)

NoSocket یک لایه مستقل از فریم‌ورک برای انتقال رویدادها است. هدف آن این است که وب‌سایت‌های مستقر روی هاست اشتراکی، cPanel، وردپرس یا هاست معمولی PHP بتوانند تجربه‌ای نزدیک به realtime داشته باشند؛ بدون نیاز به WebSocket، پردازش دائمی، Node.js، Redis، Message Broker، VPS یا daemon.

```php
$nosocket->emit('orders', 'order.created', ['id' => 123]);
```

```js
import { createNoSocket } from "/assets/js/nosocket.js";

const NoSocket = createNoSocket({
  namespace: `shop:user-${currentUser.id}`,
  tokenProvider: ({ channels }) => fetchToken(channels),
  onResync: ({ channel }) => refreshSnapshot(channel),
});
NoSocket.subscribe("orders");
NoSocket.on("order.created", (order) => console.log(order.id));
NoSocket.start();
```

## مسئله چگونه حل می‌شود؟

سرور رویدادها را برای مدت محدود داخل یک جدول ایندکس‌شده نگه می‌دارد. مرورگر به‌جای پرسیدن «چیز جدیدی هست؟»، آخرین شناسه دریافت‌شده هر channel را به‌عنوان cursor ارسال می‌کند:

```http
POST /nosocket/poll
Content-Type: application/json

{
  "subscriptions": {
    "orders": { "cursor": 1523, "replay": "live" },
    "notifications": { "cursor": null, "replay": "retained" }
  }
}
```

سرور فقط رویدادهای جدیدتر از cursor هر channel را برمی‌گرداند. اگر رویدادهای لازم به‌دلیل پایان TTL حذف شده باشند، SDK رویداد `nosocket.resync_required` را صادر می‌کند تا برنامه snapshot معتبر را دوباره دریافت کند.

## نوآوری اصلی: فقط یک تب درخواست می‌فرستد

اگر کاربر چند تب از یک سایت باز کند، NoSocket با Web Locks API یا یک lease کوتاه‌مدت در مرورگر یک تب را به‌عنوان leader انتخاب می‌کند:

- فقط تب leader سرور را poll می‌کند.
- تب‌های follower مستقیما به سرور درخواست نمی‌فرستند.
- leader رویدادها را با `BroadcastChannel` برای بقیه تب‌ها پخش می‌کند.
- اگر `BroadcastChannel` موجود نباشد، از storage event استفاده می‌شود.
- اگر leader بسته شود، بعد از پایان lease یک تب دیگر جای آن را می‌گیرد.
- اگر leader مخفی شود و تب قابل‌مشاهده دیگری وجود داشته باشد، leadership منتقل می‌شود.

مثلا با ۱۰ تب باز و فاصله عادی ۳۰ ثانیه:

| روش | تعداد درخواست در دقیقه |
| --- | ---: |
| polling معمولی برای هر تب | ۲۰ |
| NoSocket با یک leader | ۲ |

## polling هوشمند

فاصله پیش‌فرض درخواست‌ها با توجه به وضعیت تغییر می‌کند:

| وضعیت | فاصله درخواست |
| --- | ---: |
| حالت عادی | ۳۰ ثانیه |
| کاربر اخیرا فعال بوده | ۱۰ ثانیه |
| رویداد جدید در حال رسیدن است | ۲ ثانیه برای ۳۰ ثانیه |
| پاسخ HTTP 403 | حداقل ۶۰ ثانیه توقف |
| پاسخ HTTP 429 | حداقل ۱۲۰ ثانیه توقف |
| پاسخ HTTP 504 | حداقل ۳۰۰ ثانیه توقف |

خطاهای تکراری باعث exponential backoff می‌شوند و فاصله انتظار حداکثر به پنج دقیقه می‌رسد. هدف این است که هاست اشتراکی با درخواست‌های بی‌رویه تحت فشار قرار نگیرد.

## اجزای پروژه

| بخش | کاربرد |
| --- | --- |
| `nosocket/nosocket` | هسته PHP خام |
| `@nosocket/client` | SDK مرورگر |
| `nosocket/laravel` | Service Provider، Facade، route و migration لاراول |
| `nosocket/symfony` | controller و تنظیمات سرویس Symfony |
| `nosocket/codeigniter4` | سرویس و controller برای CodeIgniter 4 |
| `packages/wordpress/nosocket` | افزونه وردپرس |

درایورهای دیتابیس فعلی:

- MySQL
- MariaDB
- PostgreSQL

پشتیبانی از SQLite در roadmap قرار دارد.

## نصب PHP خام

```bash
composer require nosocket/nosocket
mysql -u app -p app_db < database/mysql/schema.sql
```

متغیرهای محیطی زیر را تنظیم کنید:

```dotenv
NOSOCKET_DSN=mysql:host=localhost;dbname=app;charset=utf8mb4
NOSOCKET_DB_USER=app
NOSOCKET_DB_PASSWORD=secret
NOSOCKET_SECRET=a-random-secret-with-at-least-32-characters
```

یک route را به [`public/poll.php`](public/poll.php) متصل کنید. سپس بعد از احراز هویت و بررسی دسترسی کاربر، token محدود به channelهای مجاز صادر کنید و فایل [`assets/js/nosocket.js`](assets/js/nosocket.js) را در مرورگر بارگذاری کنید.

راهنماهای تکمیلی:

- [نصب](docs/installation.md)
- [معماری](docs/architecture.md)
- [مرجع API](docs/api.md)
- [وردپرس](docs/wordpress.md)
- [لاراول](docs/laravel.md)
- [مقایسه با WebSocket، SSE، Pusher و Ably](docs/comparison.md)
- [راهنمای مهاجرت نسخه ۰.۲](docs/upgrade-0.2.md)

## نمونه وردپرس

افزونه وردپرس یک action ساده در اختیار شما می‌گذارد:

```php
do_action('nosocket_emit', 'orders', 'order.created', ['id' => 123], 3600);
```

برای WooCommerce، اعلان‌ها، فرم‌ها، دیدگاه‌ها و داشبورد مدیریت می‌توان از همین الگو استفاده کرد.

## نمونه لاراول

```php
use NoSocket\Laravel\Facades\NoSocket;

NoSocket::emit('orders', 'order.created', ['id' => $order->id]);
```

## امنیت

- channelها باید قبل از صدور token در برنامه شما authorize شوند.
- tokenها با HMAC-SHA256 امضا می‌شوند و شامل subject، زمان انقضا و nonce هستند.
- endpoint صدور token باید احراز هویت و محافظت CSRF داشته باشد.
- استفاده از HTTPS الزامی است.
- tokenها باید کوتاه‌عمر و محدود به کمترین channel موردنیاز باشند.
- برای production از `tokenProvider` استفاده کنید تا leader بتواند مجوز union کانال‌های تب‌ها را تازه‌سازی کند.
- namespace را برای هر کاربر خصوصی کنید؛ مثلا `shop:user-42`.
- payloadها پیش از emit باید اعتبارسنجی شوند.
- limiter دیتابیسی داخلی کمک‌کننده است، اما در صورت امکان جایگزین rate limiting لبه شبکه نیست.

## محدودیت‌های مهم

- NoSocket جایگزین کم‌تاخیر WebSocket برای چت پرترافیک یا ویرایش مشارکتی نیست.
- بازیابی آفلاین فقط تا زمانی ممکن است که رویدادها با TTL تعیین‌شده هنوز حذف نشده باشند.
- اگر storage مرورگر غیرفعال باشد، انتخاب leader بین تب‌ها تضمین نمی‌شود و ممکن است هر تب جداگانه poll کند.
- writeهای اصلی برنامه باید همچنان از routeهای معمولی و معتبر انجام شوند؛ NoSocket برای همگام‌سازی رابط کاربری است.

## تست و benchmark

```bash
composer test
npm test
npm run test:e2e
php benchmarks/run.php
```

مدل درخواست موجود در benchmark برای ۱۰۰ کاربر با ۱۰ تب باز و فاصله عادی ۳۰ ثانیه:

| روش | درخواست در دقیقه | کاهش |
| --- | ---: | ---: |
| polling جداگانه برای هر تب | ۲۰۰۰ | - |
| polling با leader در NoSocket | ۲۰۰ | ۹۰٪ |

این اعداد مدل محاسباتی هستند، نه ادعای ظرفیت یک هاست مشخص. پیش از انتشار روی محیط واقعی باید با محدودیت‌های همان سرویس میزبانی تست انجام شود.

## مجوز

پروژه با مجوز MIT منتشر می‌شود. فایل [LICENSE](LICENSE) را ببینید.
