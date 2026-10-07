# ErrorHandler suite

Standalone checks for `\CitOmni\Cli\Service\ErrorHandler`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/error-handler/run.php
```

Expected result:

```
16 passed, 0 failed, 1 skipped
```

The skipped check starts several writer processes at once. It runs only when `CITOMNI_TEST_PARALLEL` is `1`:

```
:: cmd
set "CITOMNI_TEST_PARALLEL=1"
php tests/error-handler/run.php

# PowerShell
$env:CITOMNI_TEST_PARALLEL = '1'
php tests/error-handler/run.php
```

Expected result with the multi-process check:

```
17 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The handler installs process-wide handlers, and many cases end the process. Every case therefore runs in its own child process, started with `PHP_BINARY -n`. Without a php.ini, local settings such as `error_log`, `memory_limit`, or Xdebug do not change the outcome.

| File | Role |
|---|---|
| `run.php` | Runner and checks. Starts the children and reads their exit code, stdout, stderr, and log files. |
| `probe.php` | Child. Installs the real handler and runs one case. |
| `oom.php` | Child. Runs out of memory on a heap with no free page left. |
| `doubles.php` | `class_alias` doubles for `\CitOmni\Kernel\Service\BaseService` and `\CitOmni\Kernel\Arr`. |

Each child gets its own directory under `sys_get_temp_dir()`, named `citomni_cli_error_handler_<random>`. The runner removes that directory after the check and touches nothing else.

`oom.php` is a separate script on purpose. Whether a handler without a memory reserve survives an out-of-memory fatal depends on the heap layout, and that layout includes everything compiled before the fatal. The script calls `gc_mem_caches()` and then allocates only one-page strings, so the fatal hits a heap with no free page. PHP cannot apply a memory limit below 2M (one heap chunk), so the check uses 4M, 16M, and 64M.

## Checks

R marks a regression check: it fails against the handler as it was before the change it guards. The other checks pin existing behavior.

| | Check | Guards |
|---|---|---|
| | uncaught exception exits 1 and reports class, throw site, error_id, and dev trace on stderr | Existing behavior |
| R | uncaught exception is logged with its code and previous-exception chain | The log record had neither the code nor the cause chain. |
| R | uncaught exception in prod renders the cause chain without traces | "Caused by" was rendered only with dev details. |
| R | E_USER_ERROR halts the process and is reported once by the shutdown handler | E_USER_ERROR was swallowed, and execution continued. |
| R | @-suppressed warning is left to PHP: not rendered, not logged, seen by error_get_last() | `@` was ignored: the warning was rendered and logged, and `error_get_last()` never saw it. |
| R | warning excluded by error_reporting() is left to PHP | `error_reporting()` was ignored. |
| R | level only in render.trigger is rendered without error_id and not logged | `render.trigger` only worked for levels that were also in `log.trigger`. |
| R | level only in log.trigger is logged with its level name and not rendered | Records had no `level` field. |
| | level in neither mask is left to PHP | Existing behavior |
| R | invalid UTF-8 in a message is substituted in the log, not turned into null | The message was logged as `null`. |
| R | handling a deprecation raises no PHP diagnostic from the handler itself (E_STRICT) | Reading `E_STRICT` raised "Constant E_STRICT is deprecated" inside the handler. |
| R | compile fatal is printed once when PHP has no error_log destination | The CLI printed every fatal twice: PHP's own log line on stderr, then the handler's report. |
| | compile fatal still reaches an explicit error_log, and stderr shows it once | The fix above must not silence an explicit `error_log`. |
| R | compile fatal is logged by the shutdown handler with errno, level, and error_id | Records had no `level` field. |
| R | out-of-memory fatal on a full heap is still logged and rendered | The shutdown handler ran out of memory itself. The process ended with exit code 255, and nothing was logged or rendered. |
| | rotation keeps the newest record in the live file and prunes to max_files | Existing behavior |
| | concurrent writers lose no records across rotations | Existing behavior, with `CITOMNI_TEST_PARALLEL=1` only: 4 processes write 100 records each through dozens of rotations. |
