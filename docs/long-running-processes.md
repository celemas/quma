---
title: Long-running processes
---

# Long-running processes

A web server worker (for example in FrankenPHP's worker mode) or a queue consumer keeps one `Database` instance, and its PDO connection, for many units of work: requests, jobs, messages. That saves the connection setup per unit, but whatever one unit leaves on the connection is still there for the next.

## Reset between units of work

Call `reset()` when a unit of work ends, whether it succeeded or failed:

```php
try {
	$handler->handle($job);
} finally {
	$db->reset();
}
```

`reset()` rolls back a transaction the unit left open. If a query or a transaction call failed during the unit, it also pings the connection, as the failure may have been a lost connection. If the rollback or the ping fails, for example because the server closed the connection, the connection is dropped instead of throwing, and the next statement connects anew. It returns whether an open connection is kept.

Until `reset()`, Quma keeps a lost connection, so the rest of the unit fails as well instead of continuing on a new connection outside its transaction. Quma only notices failures of its own queries and transaction calls: if a statement you run on the PDO instance from `getConn()` fails because the connection was lost, call `disconnect()`.

`reset()` also notices transactions opened with plain SQL (`BEGIN`) on PostgreSQL, MySQL and SQLite, as PDO takes the transaction state from the driver instead of tracking `begin()` calls.

## Reuse after a pause

Connections can break while they are idle: the database server restarts, or a firewall drops quiet connections. Before a connection that was idle for at least `pingAfterIdle` seconds is used again, Quma pings it and connects anew if the ping fails. Set `maxConnectionAge` to replace connections after a while regardless of their health:

```php
$conn = new Connection('pgsql:host=localhost;dbname=app', __DIR__ . '/sql')
	->pingAfterIdle(60) // default; 0 disables the check
	->maxConnectionAge(3600); // default 0 keeps connections regardless of their age
```

The check runs only between statements outside a transaction. Inside a transaction a new connection would silently lose the transaction's work, so a statement on a broken connection fails instead. Quma never retries a failed statement or commit. A query built before its connection was replaced is prepared again on the new connection when it runs next, so it joins a transaction begun there.

## Connection budget

Each process that holds a `Database` keeps its connection open while it waits for work. The number of open connections is the number of worker processes or threads, summed over all applications that share the database server. Keep that sum below the server's limit (PostgreSQL's `max_connections`, by default 100, minus the connections reserved for superusers), with room for migrations, cron jobs and administrative sessions.

Call `disconnect()` after each unit of work instead of `reset()` when connections are scarcer than the time it takes to open them.

## Session state

Every setting a unit of work changes on the connection outlives it. Keep such state local to a transaction:

- Use `SET LOCAL` or `set_config('name', 'value', true)` instead of `SET`. Both end with the transaction.
- Release advisory locks in the same unit of work, or use the transaction-level variants (`pg_advisory_xact_lock()`).
- Drop temporary tables, or create them with `ON COMMIT DROP`.
- Do not change the connection's search path, time zone or role outside a transaction.

## What stays cached

A `Database` caches compiled SQL and template scripts, and hydration metadata, for its lifetime. Changes to SQL files take effect in a new process, so restart workers after a deployment.
