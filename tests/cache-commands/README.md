# Cache command suite

Standalone checks for `cache:warm` (`\CitOmni\Cli\Command\CacheWarmCommand`) and `cache:clear` (`\CitOmni\Cli\Command\CacheClearCommand`). No database, no Composer autoloader, no files written, and no setup per session.

## Run

```
php tests/cache-commands/run.php
```

Expected result:

```
16 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The commands run unchanged on top of `class_alias` doubles for the kernel classes they use, loaded from `doubles.php`:

| Double | Stands in for | Does |
|---|---|---|
| `AppDouble` | `\CitOmni\Kernel\App` | Records `warmCache()`, `clearCache()`, and HTTP App construction, and returns cache paths without touching disk. A check can make `warmCache()` throw or set what `clearCache()` returns. |
| `BaseCommandDouble` | `\CitOmni\Kernel\Command\BaseCommand` | Same constructor, constants, accessors, and IO helpers. Captures stdout and stderr lines. Parses only the option forms the cache commands receive. |
| `ModeDouble` | `\CitOmni\Kernel\Mode` | Same cases. |
| `HelpFormatterDouble` | `\CitOmni\Kernel\Command\HelpFormatter` | Returns a fixed usage line. |
| `HttpRegistryDouble` | `\CitOmni\Http\Boot\Registry` | Makes citomni/http count as installed. |

Option parsing and validation belong to `ArgvParser` and are tested in citomni/kernel.

The first eight checks run without citomni/http. `run.php` then aliases `\CitOmni\Http\Boot\Registry` and runs the remaining eight, because an alias cannot be removed again.

## Checks

| Check | Covers |
|---|---|
| Registry maps cache:warm and cache:clear to the commands | `Registry::COMMANDS_CLI` |
| cache:warm --mode=cli warms the running CLI App only | Mode routing, text output |
| cache:warm without citomni/http warms CLI and reports HTTP as skipped | `--mode=all` without citomni/http |
| cache:warm --json without citomni/http reports "skipped" for HTTP | JSON shape |
| cache:warm --mode=http without citomni/http fails before touching App | Exit code 1, message |
| cache:warm --mode=http --json without citomni/http prints ok=false and an empty modes object | JSON on failure; `modes` stays an object |
| cache:clear --mode=http without citomni/http fails before touching App | Exit code 1, message |
| cache:warm --env= is a usage error, not a cache built without an env overlay | Exit code 2, message, usage |
| cache:warm warms CLI through the running App, then HTTP through a second App in Mode::HTTP | Order, config directory, text output |
| warming HTTP writes the OPcache note to stderr | Note text |
| --env is passed to both warmCache() calls and shown in both headers | `--env` pass-through |
| cache:warm -j prints one JSON object with ok, env, and both modes | Short option, JSON shape, note on stderr |
| cache:clear --json reports removed paths and null for files that were not present | JSON shape, `null` entries |
| cache:clear lists removed files and says when a mode had nothing to clear | Text output, "(not present)", "nothing to clear" |
| cache:clear --mode=cli clears the running App only and writes no OPcache note | Mode routing, no note |
| an exception from App reaches the caller, and the CLI cache warmed before it stays | No catch in the command; nothing is rolled back |
