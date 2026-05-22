
**Стек:** PHP 8.4 · Laravel 12 · Filament 3 · PostgreSQL 16 · Redis 7 · Laravel Sail (Docker)

---

## Быстрый старт

### Требования

- Docker Desktop (или Docker Engine + Compose)
- Ничего больше — PHP и Node не нужны локально

### 1. Клонировать и установить зависимости

```bash
git clone <repo-url> talpay && cd talpay

cp .env.example .env

# Установка PHP-зависимостей через временный контейнер (без локального PHP)
docker run --rm -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest \
  composer install --ignore-platform-reqs
```

### 2. Запустить контейнеры

```bash
./vendor/bin/sail up -d
```

Поднимает три контейнера: `app` (PHP 8.4 + Nginx), `pgsql` (PostgreSQL 16), `redis` (Redis 7).  
При первом старте PostgreSQL автоматически создаёт рабочую БД `talentpay` и тестовую `talentpay_test` — через init-скрипт `docker/pgsql/create-testing-database.sh`.

### 3. Инициализировать приложение

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

Сидер создаёт двух демо-пользователей (см. таблицу ниже) и кошелёк клиента с комиссией 5%.

### 4. Собрать фронтенд

```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

### 5. Открыть

| Панель  | URL                           |
|---------|-------------------------------|
| Админ   | http://localhost/admin        |
| Клиент  | http://localhost/client       |

---

## Тестовые учётки

| Роль   | Email              | Пароль   | Панель          | Особенности              |
|--------|--------------------|----------|-----------------|--------------------------|
| Админ  | admin@example.com  | password | /admin          | —                        |
| Клиент | client@example.com | password | /client         | Комиссия 5% на пополнение|

---

## Команды разработчика

```bash
# Тесты (внутри Sail — используется talentpay_test)
./vendor/bin/sail artisan test
# или через алиас
./vendor/bin/sail composer test

# Статический анализ (PHPStan level 6)
./vendor/bin/sail composer stan

# Форматирование (Laravel Pint)
./vendor/bin/sail composer format
```

> **Тесты локально (без Sail):** убедитесь, что в `.env` задан `DB_HOST=127.0.0.1` и существует база `talentpay_test`. `phpunit.xml` переопределяет только `DB_DATABASE=talentpay_test` — остальные параметры подключения берутся из `.env`.

---

## Функциональность

### Администратор (`/admin`)

| Раздел               | Что делает                                                                 |
|----------------------|----------------------------------------------------------------------------|
| Заявки на пополнение | Подтверждает / отклоняет заявки клиентов; при подтверждении начисляет `net = gross − commission` |
| Списание             | Прямое списание с кошелька клиента                                         |
| Операции             | Полная история транзакций по всем клиентам с фильтрами и деталями          |
| Заявки на возврат    | Подтверждает / отклоняет клиентские заявки на возврат                      |
| Клиенты              | Список клиентов, просмотр профиля, история транзакций, изменение комиссии  |

### Клиент (`/client`)

| Раздел               | Что делает                                                                 |
|----------------------|----------------------------------------------------------------------------|
| Баланс               | Текущий баланс кошелька                                                    |
| Заявки на пополнение | Создание заявки (с превью комиссии), просмотр статуса                      |
| История операций     | Все транзакции с фильтрами по типу и периоду; для пополнений с комиссией — отображение `+950 ₽ (комиссия 50 ₽)` |
| Заявки на возврат    | Создание заявки на возврат конкретного списания, просмотр статуса          |

---

## Архитектурные решения

**Деньги: bigint minor units + brick/money.**
Все суммы хранятся в копейках (`bigint`) — никаких `float`, никаких ошибок округления. Форматирование через `brick/money` (`Wallet::formatMinor()`) используется только на уровне презентации. В бизнес-логике — чистый `int`.

**Append-only ledger: `WalletTransaction` иммутабельна.**
Модель запрещает `UPDATE` и `DELETE` через модельные события — при попытке бросается исключение. Таблица `wallet_transactions` — реестр фактов: каждое движение по балансу порождает новую запись. Это упрощает аудит и исключает потерю истории.

**Денормализация валюты: `currency` на каждой записи.**
Поле `currency` продублировано в `top_up_requests`, `refund_requests` и `wallet_transactions`. История читается без джойна к `wallets` и остаётся корректной даже при смене валюты кошелька в будущем.

**Action-классы как граница бизнес-логики.**
Вся бизнес-логика сосредоточена в `app/Actions/Wallet/`:

| Action                  | Что делает                                                  |
|-------------------------|-------------------------------------------------------------|
| `CreateTopUpRequest`    | Создаёт заявку на пополнение (с идемпотентностью)           |
| `ConfirmTopUpRequest`   | Подтверждает, вычисляет комиссию, зачисляет `net` на баланс |
| `DebitWallet`           | Прямое списание с проверкой остатка                         |
| `RefundDebit`           | Прямой возврат по транзакции-списанию                       |
| `CreateRefundRequest`   | Создаёт клиентскую заявку на возврат (с проверкой лимита)   |
| `ConfirmRefundRequest`  | Подтверждает заявку → вызывает `RefundDebit` внутри         |
| `RejectRefundRequest`   | Отклоняет заявку с опциональной причиной                    |

Filament-страницы только вызывают Actions. Никакой логики в Livewire-компонентах.

**Комиссия платформы.**
Ставка фиксируется на кошельке клиента в basis points (`commission_rate_bps`, целое 0–10000, т.е. 500 = 5%). Вычитается при подтверждении пополнения: `commission = intdiv(gross × rate, 10_000)`. Фиксируется на `top_up_requests.commission_amount` и `wallet_transactions.commission_amount` — при изменении ставки старые операции не пересчитываются. Списания и возвраты комиссией не облагаются.

**Два пути возврата средств.**
`RefundDebit` — прямой возврат: администратор создаёт `WalletTransaction(type=Refund)` немедленно. `RefundRequest` — клиентский сценарий: клиент создаёт заявку, администратор обрабатывает. `CreateRefundRequest` при проверке лимита учитывает одновременно уже выполненные возвраты (`WalletTransaction::Refund`) и незакрытые заявки (`RefundRequest::Pending`) — это предотвращает двойной оверкоммит при параллельных заявках.

**State machine: `canTransitionTo` на enum + методы на модели.**
Статусы `TopUpRequest` и `RefundRequest` — PHP-enums с `canTransitionTo()`. Переходы `Pending → Confirmed`, `Pending → Rejected` инкапсулированы в `transitionTo()` на модели: она сама проверяет корректность перехода и проставляет временны́е метки.

**Идемпотентность: `nullable idempotency_key` + unique index.**
`CreateTopUpRequest` и `CreateRefundRequest` принимают опциональный `$idempotencyKey` — при повторном вызове с тем же ключом возвращают существующую запись. Инфраструктура готова для REST API с безопасными повторными запросами.

**Доменные исключения вместо generic.**
`InvalidAmountException`, `InsufficientFundsException`, `RefundExceedsDebitException`, `TopUpAlreadyProcessedException`, `RefundRequestAlreadyProcessedException` — каждая несёт конкретный контекст. Filament-обработчики перехватывают и отображают понятные уведомления.

**`restrictOnDelete` на всех FK.**
Удаление пользователя с кошельком или кошелька с транзакциями бросит исключение на уровне БД — намеренно, чтобы не потерять финансовую историю случайно.

---

## Конкурентность

### Что защищено

- Подтверждение пополнения (`ConfirmTopUpRequest`)
- Списание (`DebitWallet`)
- Прямой возврат (`RefundDebit`)
- Создание заявки на возврат (`CreateRefundRequest`)
- Подтверждение заявки на возврат (`ConfirmRefundRequest`)

### Как

Используется **pessimistic row-level locking** через `lockForUpdate()`. Фиксированный порядок захвата блокировок: сначала узкая сущность (заявка или транзакция), затем кошелёк. Это исключает deadlock.

```
ConfirmTopUpRequest:
  1. BEGIN
  2. SELECT ... FROM top_up_requests WHERE id = ? FOR UPDATE   ← заявка
  3. SELECT ... FROM wallets WHERE id = ? FOR UPDATE           ← кошелёк
  4. UPDATE wallets SET balance = balance + net
  5. INSERT INTO wallet_transactions ...
  6. COMMIT

ConfirmRefundRequest:
  1. BEGIN
  2. SELECT ... FROM refund_requests WHERE id = ? FOR UPDATE   ← заявка на возврат
  3. → вызывает RefundDebit, который блокирует debit-транзакцию, затем wallet
  4. COMMIT
```

PostgreSQL не позволяет использовать агрегатные функции совместно с `FOR UPDATE`, поэтому суммы уже выполненных возвратов собираются через `get()->sum()` в PHP.

### Рассматривал альтернативы

- **Optimistic locking (`version`-поле)**: при высокой конкуренции деградирует в retry-шторм.
- **`UPDATE wallets SET balance = balance - ? WHERE balance >= ?`**: атомарно, но нет возможности вернуть контекст ошибки в транзакцию.
- **Advisory locks**: гибко, но выходит за рамки ORM и требует ручного управления жизненным циклом.
- **`SERIALIZABLE` isolation**: полная защита, но PostgreSQL бросает `serialization failure` при конфликтах — нужен retry на уровне приложения.

Pessimistic: семантика прозрачна, порядок явный, retry не нужен.

### На будущее

При введении переводов между кошельками блокировки нужно захватывать **по `wallet_id ASC`** — иначе два встречных перевода уйдут в deadlock.

---

## Структура БД

```
users
  └─< wallets (commission_rate_bps)
        ├─< top_up_requests (commission_amount)
        ├─< wallet_transactions (commission_amount)
        └─< refund_requests ──> wallet_transactions (debit)
```

---

## Вне скоупа

- **Мультивалютность** — поле `currency` есть на каждой записи, конверсий нет.
- **Нотификации** — доменные события не публикуются, слушателей нет.
- **Реальные платёжные интеграции** — пополнение подтверждается администратором вручную.
- **Soft delete пользователей и GDPR** — удаление жёстко ограничено FK.

---

## Что бы сделал ещё

- **Events + Listeners**: `TopUpConfirmed`, `WalletDebited`, `RefundCreated` → email/push через очередь.
- **Экспорт истории в CSV**: страница в клиентской панели с фильтрами по дате и типу.
- **Команда `wallet:reconcile`**: сверяет `wallets.balance` с `SUM(wallet_transactions)` — детектирует расхождения без даунтайма.
- **Исполнитель как сущность**: сейчас `created_by` — просто `user_id`. Модель `Operator` с ролями дала бы более богатую историю действий.
- **Комиссия на возврат**: инфраструктура (`commission_amount` на транзакциях) позволяет при необходимости добавить частичный возврат комиссии.
