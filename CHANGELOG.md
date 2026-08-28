# Changelog

## Unreleased

A correctness pass over `MysqliDb` and `dbObject`, plus a real test suite.

### Behaviour changes worth knowing about

These are fixes, but they change what existing code sees. Read them before upgrading.

* **`rawQuery()` prefixing is much more conservative.** It no longer rewrites table names
  inside string literals, quoted identifiers or comments, no longer treats
  `ON DUPLICATE KEY UPDATE` as an `UPDATE` statement, no longer prefixes schema qualified
  names (`otherdb.users`), and no longer double-prefixes a name that already starts with
  the prefix. It also stops stripping newlines, which previously merged `--` comments into
  the following line. With no prefix configured it is now a complete no-op.
* **`delete()` throws on failure and returns `true` on success.** It previously returned
  `affected_rows >= 0` and reported a stale error message. Use `$db->count` for the number
  of rows removed.
* **`getLastError()` / `getLastErrno()` are cleared when a new query starts.** Code that
  read them after an unrelated later query will now see an empty error instead of the old
  one.
* **`orHaving()` signature now matches `having()`**: `orHaving($prop, $value = 'DBNULL',
  $operator = '=')`. Calls that relied on the old `null` defaults produced invalid SQL and
  could not have been working.
* **`setLockMethod()` rejects anything other than `READ`/`WRITE`.** It previously accepted
  every non-empty string because of a `case "READ" || "WRITE":` bug.
* **`_determineType()` throws for unsupported types** instead of silently returning an
  empty type, which used to desynchronise the bind type string from the value count.
* **`dbObject::__call()` returns real values.** Forwarded `MysqliDb` methods that produce a
  value (`getLastError()`, `getLastQuery()`, ...) now return it; chainable builder methods
  still return the model.
* **`dbObject::table()` validates its argument** and throws for names that are not valid
  PHP identifiers.
* **`dbObject::with()` throws** for an unknown relation instead of calling `die()`.
* `_dynamicBindResults()` lost its `mysqli_stmt` type hint so the builder can be unit
  tested; it is a `protected` method, and subclasses that override it are unaffected.

### Fixed

* mysqli has thrown `mysqli_sql_exception` by default since PHP 8.1, which bypassed every
  error check in the class: connection errors, the "which query failed" exception in
  `_prepareQuery()`, and the whole `autoReconnect` feature were unreachable. All mysqli
  calls now handle both the exception and the legacy `false` return.
* `delete()` interpolated `$this->_stmtError` into its failure message before assigning it,
  and skipped `reset()` when throwing, leaking the failed query's `WHERE` clause into the
  next query on the same instance.
* `where('id', Array('>=' => 50))` and the other documented operator-as-key forms bound
  their value but emitted no operator or placeholder.
* A subquery used with `IN` was wrapped in a second pair of parentheses, making MySQL
  evaluate it as a scalar subquery and fail with "Subquery returns more than 1 row" as soon
  as it matched more than one row. `EXISTS` had the same problem plus a stray trailing
  alias.
* `conditionToSql()` emitted `NULL` comparisons without a leading space, producing
  `columnIS NULL` for `joinWhere()` conditions.
* Prepared statements were never closed on `insert()`, `update()`, `delete()` or non-SELECT
  `rawQuery()` paths, exhausting `max_prepared_stmt_count` on long-running scripts.
* `copy()` serialised the object while it still held mysqli handles and threw
  "Serialization of 'mysqli' is not allowed" on any connected instance.
* `_buildInsertQuery()` used `'/^[INSERT|REPLACE]/'`, a character class rather than an
  alternation, so it matched any query beginning with one of those letters.
* `replacePlaceHolders()` stopped at a placeholder sitting at offset 0 and read past the
  end of the value array when a query held more `?` than bound values.
* `_traceGetCaller()` dereferenced `false` once the backtrace was exhausted and assumed
  every frame has a `file` key.
* `paginate()` divided by `pageLimit` without checking it, and accepted page numbers below 1.
* `insertMulti()` let `array_combine()` raise an uncaught `ValueError` on a column/value
  count mismatch, leaving the transaction open. It now reports the offending row and rolls
  back.
* `startTransaction()` registered a new shutdown handler on every call.
* `_buildDataPairs()` read missing columns blindly, producing an "undefined array key"
  warning instead of a usable error.
* `lock()` derived comma placement from the array key, producing invalid SQL for any array
  not sequentially indexed from zero.
* `escape()` used a truthiness test, returning `"0"` unescaped, and passed non-strings to
  `real_escape_string()` (deprecated since PHP 8.1).
* `SELECT FOUND_ROWS()` result sets were never freed.
* `dbObject::prepareData()` called `count()` on a null `$data`, a `TypeError` in PHP 8, so
  inserting a model with no properties set was a fatal error.
* `dbObject::validate()` returned `!count($errors) > 0`, which only worked by accident of
  operator precedence, and called `strlen(null)` (deprecated since PHP 8.1).
* `dbObject::processArrays()` decoded json/array columns without checking they were
  present, warning whenever a query selected a subset of fields.
* `dbObject::toArray()` and the `get()`/`paginate()` loops left dangling references behind.
* Model properties (`$dbFields`, `$relations`, `$hidden`, `$timestamps`, `$jsonFields`,
  `$arrayFields`) are now declared on the base class, so `isset`/`empty` checks no longer
  route through `__get()`.
* `dbObject` now throws a clear exception when no `MysqliDb` instance exists yet, instead
  of failing later on a null.

### Security

* `orderBy()` inlined a caller-supplied `REGEXP` pattern without escaping it.
* `loadData()` and `loadXml()` interpolated the filename and every delimiter setting into
  an unprepared statement without escaping.
* The constructor expanded a settings array with variable variables, letting an arbitrary
  key overwrite unrelated local state. It now reads a fixed set of keys.
* `_buildPair()` called `getSubQuery()` on any object handed to it; it now requires a
  `MysqliDb` instance and reports a clear error otherwise.
* The demo app moved to `examples/index.php` and now escapes all output, uses a CSRF
  token, and dispatches only to an allowlist of actions.

### Added

* PHPUnit suite: `tests/unit` (156 tests, no database required) and `tests/integration`
  (29 tests, skipped unless `DB_HOST`/`DB_NAME` are set).
* GitHub Actions workflow running lint and unit tests on PHP 8.3/8.4, and the integration
  suite against MySQL 8.0.
* `composer test`, `composer test-unit`, `composer test-integration`.

### Changed

* Composer autoloading switched from `files` to `classmap`, so the classes load on demand
  rather than on every request. `ext-mysqli` is now a declared requirement.
* Removed the unused `_buildJoinOld()`, the dead PHP 5.3 check in `refValues()`, the
  unreachable `blob` case in `_determineType()`, and several unreachable `return`
  statements after `throw`.
* `_buildCondition()` now delegates to `conditionToSql()`; the two were near-identical
  copies that had already drifted apart.
* The original standalone test scripts moved to `tests/legacy/` and had their hardcoded
  paths fixed. They are excluded from Composer dist archives along with `examples/`.
