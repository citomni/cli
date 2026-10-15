# ErrorHandler suite

Standalone checks for `\CitOmni\Cli\Service\ErrorHandler`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/error-handler/run.php
```

Expected result:

```
27 passed, 0 failed, 1 skipped
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
28 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The handler installs process-wide handlers, and many cases end the process. Every case therefore runs in its own child process, started with `PHP_BINARY -n`. Without a php.ini, local settings such as `error_log`, `memory_limit`, or Xdebug do not change the outcome.

| File | Role |
|---|---|
| `run.php` | Runner and checks. Starts the children and reads their exit code, stdout, stderr, and log files. |
| `probe.php` | Child. Installs the real handler and runs one case. |
| `oom.php` | Child. Runs out of memory on a heap with no free page left, filling one allocator size class. |
| `doubles.php` | `class_alias` doubles for `\CitOmni\Kernel\Service\BaseService` and `\CitOmni\Kernel\Arr`. |
| `faults.php` | `FaultWrapper`: a stream wrapper (`faulty://logs`) over the child's real log directory that fails the operations a case names. |

Both children give the handler the `error_handler` node of `Registry::CFG_CLI` and put the case's options on top, as an app's config would.

Each child gets its own directory under `sys_get_temp_dir()`, named `citomni_cli_error_handler_<random>`. The runner removes that directory after the check and touches nothing else.

`oom.php` is a separate script on purpose. Whether a handler without a memory reserve survives an out-of-memory fatal depends on the heap layout, and that layout includes everything compiled before the fatal. The script calls `gc_mem_caches()` and then allocates only strings of one length, so the fatal hits a heap with no free page and one full size class. The shutdown handler needs a fresh run of pages for every full size class it allocates from; the checks use 4000-byte strings (whole pages) and 615-, 1255- and 1767-byte strings, which fill the 640-, 1280- and 1792-byte classes, whose runs take 5, 5 and 7 pages. PHP cannot apply a memory limit below 2M (one heap chunk), so the checks use 4M, 16M, and 64M.

`oom.php <logDir> <mib> <bytes> <others> [argvBytes]`: With `<others>` from 0 up, the child seeds `cli_err_shutdown.jsonl` past the case's `max_bytes` of 100000, ten rotations of it, and that many files of other logs, so the shutdown record rotates the log and retention reads a large directory. `[argvBytes]` appends an argument of that size to `$_SERVER['argv']`, which is what the handler logs as the command line; Windows refuses a real command line over 32767 characters. Every run registers a shutdown function after the handler, which prints `[later]` on stderr; `probe.php` does the same for `user_error` and `compile`.

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
| R | a shutdown function registered after the handler still runs after a fatal error, after the report | `handleShutdown()` ended with `exit(1)`, which cancels every shutdown function registered after it. |
| R | out-of-memory fatal on a full heap is reported and logged, and later shutdown functions still run | Without the memory reserve, the shutdown handler ran out of memory itself. With it, `exit(1)` still cancelled the later shutdown functions. |
| R | out-of-memory fatal is reported and logged when its record rotates a log among 1000 other files | `prune()` read the whole directory with `scandir()`, which needs memory for every file in it. The handler ran out of memory itself and ended the process with exit status 255, with nothing on stderr and nothing logged. |
| R | out-of-memory fatal with a 100 KB command line is logged, with the command line cut | The record carried the whole command line and needed more than the reserve. The log was written before stderr, so the process ended with nothing on stderr and nothing logged. |
| R | a record keeps at most 4096 bytes of the command line and marks the cut | Every record carried the whole command line. |
| | rotation keeps the newest record in the live file and prunes to max_files | Existing behavior |
| R | a line the log takes only in part is cut off again, and error_log names the file | The partial line stayed and ran into the next record, and nothing was reported. |
| R | a rotation whose copy is cut short leaves the live file as it was | The live file was truncated anyway: the records that did not make it into the copy were lost. |
| R | a rotation whose rename fails removes the copy and leaves the live file as it was | The live file was truncated before the rename; the records stayed in a `.tmp` file that retention never saw. |
| R | a rotation whose truncation fails keeps the records in both files, and error_log says so | The failure was not reported. |
| R | without the lock on the live file, nothing is rotated | The handler copied and truncated without the lock, so a concurrent append could be lost. |
| R | retention keeps the newest rotations of its own log and leaves every other file alone | Any `<base>.*<ext>` file counted as a rotation, so `cli_err_phperror.backup.jsonl` was deleted. |
| R | retention works in a directory whose path contains glob syntax | `glob()` read `logs[1]` as a character class, found nothing and pruned nothing. |
| | concurrent writers lose no records across rotations | Existing behavior, with `CITOMNI_TEST_PARALLEL=1` only: 4 processes write 100 records each through dozens of rotations. |

The fault cases run `probe.php fault <faults>@<records>`: The child seeds the live log with that many records, puts `faulty://logs` over its real log directory and logs one warning through it. Ten records rotate at the case's `max_bytes` of 1000; fewer are appended to. `FaultWrapper` has no `fsync()` (PHP offers none for stream wrappers), so the rotation copy is synced only on plain files.

The fatal-error checks expect exit status 255: The shutdown handler returns instead of calling `exit()`, so PHP's status for the fatal error stands.

Against the handler at `citomni/cli` `49ac944`, with `CITOMNI_TEST_PARALLEL=1`: `20 passed, 8 failed`. The five new R checks fail, and so do the three checks of a fatal error's exit status, which was 1. Against the current handler without its memory reserve: `24 passed, 3 failed, 1 skipped`. The three out-of-memory checks fail; the report still reaches stderr, but no record is written. Without the cut of the command line: `25 passed, 2 failed, 1 skipped`. The two command-line checks fail; with 100 KB of argv, the record does not fit the reserve.
