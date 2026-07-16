# Redis worker example

This pair of scripts uses Redis or Valkey for delivery and a PDO database for
durable job records. Apply [the schema](../../docs/database.md) first and
install `predis/predis`.

Set connection values as needed:

```bash
export DATABASE_DSN='mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4'
export DATABASE_USER='app'
export DATABASE_PASSWORD='secret'
export REDIS_HOST='127.0.0.1'
export REDIS_PORT='6379'
export QUEUE_PREFIX='myapp'
export QUEUE_NAME='default'
```

Start the worker in one terminal:

```bash
php examples/redis/worker.php
```

Dispatch work in another:

```bash
php examples/redis/dispatch.php
```

The handlers only print and simulate work. Replace them with application
services that make external side effects idempotent.
