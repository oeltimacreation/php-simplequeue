# Examples

Examples are grouped by the services they require. They are intentionally
standalone and do not contain the canonical database schema; use
[docs/database.md](../docs/database.md) for that.

| Example | Requirements | Command |
|---|---|---|
| [basic/in-memory.php](basic/in-memory.php) | PHP and Composer dependencies | `php examples/basic/in-memory.php` |
| [redis/](redis/README.md) | PDO database, Redis/Valkey, Predis | worker and dispatcher in separate terminals |
| [benchmark/database.php](benchmark/database.php) | PDO SQLite | `php examples/benchmark/database.php [jobs]` |
| [migrations/1.3.0-lease-based-claims.sql](migrations/1.3.0-lease-based-claims.sql) | Existing v1.2 installation | apply once before upgrading |

Do not use the in-memory sample for persistent jobs: its contents disappear
when the PHP process exits.
