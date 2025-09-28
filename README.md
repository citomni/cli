# CitOmni CLI

Deterministic command-line runtime for CitOmni applications.

`citomni/cli` is the dedicated CLI delivery layer in the CitOmni ecosystem. It provides the runtime boundary for command execution in the same architectural spirit as `citomni/http` provides the runtime boundary for web delivery: explicit boot, deterministic composition, minimal entrypoint code, and no framework magic disguised as convenience.

The package is intentionally narrow in scope. It owns the CLI runtime, its boot process, command dispatch, and CLI-specific failure rendering/logging. It does not attempt to absorb every command-related concern into itself. Shared abstractions and reusable command infrastructure may live in other CitOmni packages where that ownership is more appropriate.

In practical terms, `citomni/cli` gives a CitOmni application a formal command-line execution model rather than a pile of ad-hoc PHP scripts wearing the ceremonial robes of a console framework.

---

## Highlights

- **Dedicated CLI runtime for CitOmni** with explicit kernel boot and command dispatch
- **Deterministic composition model** aligned with the wider CitOmni architecture
- **Minimal entrypoint philosophy** through a slim `bin/console` front controller
- **Provider-aware boot pipeline** for CLI config and service-map contributions
- **CLI-specific error handling** with controlled diagnostics and logging behavior
- **No command scanning magic** beyond explicit package/application composition rules
- **Shared architectural DNA with CitOmni HTTP** while remaining a proper CLI runtime in its own right
- ♻️ **Low-overhead by design** - explicit boot, predictable resolution, and minimal runtime indirection

---

## What this package is

`citomni/cli` is the command-line mode of the CitOmni framework.

It provides the application-facing runtime required to execute commands in a structured and deterministic way. That includes bootstrapping the application in CLI mode, resolving CLI-relevant config and services, locating registered commands, dispatching execution, and handling runtime failures in a way appropriate to terminal usage rather than HTTP delivery.

This package therefore occupies the same conceptual layer for CLI that `citomni/http` occupies for web requests. It is not merely a convenience script collection, and it is not a general-purpose shell framework bolted onto CitOmni after the fact.

---

## What this package provides

### CLI runtime responsibilities

- CLI kernel boot and handoff
- CLI-specific config assembly
- CLI-specific service-map assembly
- Command discovery from the composed application/runtime graph
- Command dispatch from process arguments
- Command-list rendering for discovery/help scenarios
- CLI-specific error, exception, and fatal handling

### Delivery-layer concerns

- Terminal-oriented execution flow
- Exit-oriented runtime behavior
- Developer-friendly diagnostics in development contexts
- Safe, constrained failure output in non-development contexts
- Logging hooks for operational visibility where configured

---

## What this package does not own

`citomni/cli` is intentionally not a monolithic home for every command-related abstraction.

It does **not** need to own:

- Every reusable command base class
- Every argv parsing helper in the ecosystem
- Every command help formatter
- Domain command logic itself
- Shared orchestration used by both HTTP and CLI
- Persistence logic
- Application/domain services merely because they are invoked from commands

Those concerns may live in other packages when that boundary is architecturally cleaner. A command-line runtime should not annex neighboring responsibilities simply because it happens to be holding the terminal.

---

## Relationship to the wider CitOmni architecture

CitOmni separates delivery layers from orchestration, persistence, and reusable services.

Within that model:

1. `citomni/kernel` provides the application core, config/service composition, and service resolution model.
2. `citomni/http` provides HTTP delivery.
3. `citomni/cli` provides CLI delivery.
4. Shared/domain packages contribute services, config, routes, commands, and other package-owned capabilities through explicit boot metadata.
5. The application composes the final runtime.

`citomni/cli` therefore exists as a first-class delivery layer, not as an afterthought, and not as a thin wrapper around a generic command runner with ambitions above its station.

---

## Runtime model

The package follows the standard CitOmni principles:

- explicit boot
- deterministic composition
- fail-fast behavior
- minimal entrypoint code
- no namespace scanning as a substitute for design

At runtime, a typical CLI process looks conceptually like this:

`bin/console` -> `Cli\Kernel::run()` -> `new App($configDir, Mode::CLI)` -> CLI config/services built from vendor baseline, providers, and app overrides -> CLI error handler installed -> command runner resolves and executes the requested command

This keeps CLI execution aligned with the broader CitOmni boot model while respecting the very different operational semantics of a terminal process.

---

## Deterministic composition

Like the rest of CitOmni, `citomni/cli` favors explicit composition over hidden discovery.

CLI config and services are assembled from defined sources in a deterministic order. The exact mechanics are intentionally parallel to the broader framework model: vendor baseline first, then provider contributions, then application-level overrides.

This matters operationally. A command should not change behavior because a package happened to be scanned differently, nor because an autoloading side effect quietly altered registration order. Determinism is not academic polish here; it is a practical debugging advantage.

---

## Requirements

- PHP **8.2+**
- `citomni/kernel`

OPcache is strongly recommended in production-like environments where CLI workloads are frequent or operationally important.

---

## Installation

```bash
composer require citomni/cli
composer dump-autoload -o
````

Register the package provider in your application configuration if your composition model requires it.

Your application's `composer.json` should also expose your own code through PSR-4 autoloading:

```json
{
	"autoload": {
		"psr-4": {
			"App\\": "src/"
		}
	}
}
```

Then refresh the autoloader:

```bash
composer dump-autoload -o
```

---

## Quick start

A minimal `bin/console` entrypoint typically looks like this:

```php
<?php
declare(strict_types=1);

define('CITOMNI_ENVIRONMENT', 'dev');           // dev | stage | prod
define('CITOMNI_APP_PATH', \dirname(__DIR__));

require CITOMNI_APP_PATH . '/vendor/autoload.php';

\CitOmni\Cli\Kernel::run(__DIR__);
```

The point of this file is not to become clever. Its job is to hand execution to the CLI kernel and then get out of the way.

---

## Typical app layout

```text
/app-root
  /bin
    citomni
  /config
    providers.php
    services.php
    citomni_cli_cfg.php
    citomni_cli_cfg.dev.php
    citomni_cli_cfg.stage.php
    citomni_cli_cfg.prod.php
    citomni_cli_commands.php
    citomni_cli_commands.dev.php
    citomni_cli_commands.stage.php
    citomni_cli_commands.prod.php
  /src
    /Cli
      /Command
      /Exception
    /Operation
    /Repository
    /Service
    /Util
  /var
    /cache
    /flags
    /logs
    /state
  /vendor
```

The exact application structure can vary, but the important distinction remains: commands belong to the CLI-facing adapter layer; orchestration belongs elsewhere; persistence belongs in repositories.

---

## Commands and architectural boundaries

Commands are CLI adapters.

That means they own terminal-facing concerns such as:

* receiving process arguments
* validating user input at the CLI boundary
* formatting terminal output
* choosing exit codes
* delegating actual business workflows to operations/repositories/services as appropriate

They should **not** become storage layers, mailers, HTTP simulators, or miniature god-objects with a text cursor.

In normal CitOmni architecture terms:

* **Commands** own CLI transport concerns
* **Operations** own orchestration
* **Repositories** own persistence
* **Services** provide reusable runtime capabilities

This is not merely a cleanliness preference. Command code remains easier to reason about, easier to test, and less likely to accumulate irreversible "just this once" terminal logic that metastasizes into application policy.

---

## Command discovery and dispatch

`citomni/cli` provides the runtime machinery needed to locate registered commands and dispatch them from argv input.

In a typical setup, the command runner is responsible for:

* receiving raw process arguments
* resolving the intended command
* showing grouped command lists when no command or an unknown command is supplied
* invoking the matching command class
* delegating command-specific parsing/validation to the command-side infrastructure in use

This separation is deliberate. The runtime should know how to find and launch commands; it should not need intimate knowledge of every argument grammar in the ecosystem.

---

## Error handling

CLI failure semantics differ from HTTP failure semantics, and `citomni/cli` treats them accordingly.

The CLI error handler is responsible for handling:

* uncaught exceptions
* PHP errors promoted or surfaced during runtime
* fatal shutdown scenarios where relevant

In development contexts, richer diagnostic output may be rendered to support debugging. In non-development contexts, output should remain controlled, operationally sane, and suitable for logs or automated runners rather than theatrical terminal collapse.

Fail-fast remains the governing principle. Recoverability should be explicit. Silent swallowing of runtime failures is not resilience; it is deferred confusion.

---

## Configuration

CLI configuration follows the same broad CitOmni model of explicit layered composition.

Typical sources include:

1. vendor CLI baseline
2. provider CLI config contributions
3. application CLI base config
4. optional environment overlay for CLI mode

This enables command runtimes to remain predictable across environments without collapsing environment concerns into command classes themselves.

A minimal `config/citomni_cli_cfg.php` may look like:

```php
<?php
declare(strict_types=1);

return [
	'identity' => [
		'app_name' => 'My CitOmni App',
	],

	// Add CLI-specific runtime settings here, such as error-handler
	// options, logging paths, or package-specific CLI configuration.
];
```

Environment-specific overlays can then refine operational details without contaminating the baseline.

---

## Services

As with the rest of CitOmni, services are resolved through explicit service maps rather than runtime scanning.

That means:

* predictable resolution
* clear ownership
* cacheable composition
* lower runtime overhead
* fewer surprises when debugging boot behavior

If your application or provider contributes CLI-relevant services, they should do so through the normal explicit registration mechanisms rather than magical discovery strategies that behave impressively until examined closely.

---

## Providers

Providers may contribute CLI-specific metadata through the standard CitOmni boot/registry pattern.

That can include:

* CLI service-map entries
* CLI config overlays
* CLI command registrations where applicable

This allows packages to participate in the CLI runtime without requiring the CLI package itself to know package-specific details in advance.

In other words, composition remains explicit, but it is not parochial.

---

## Operational philosophy

`citomni/cli` is designed for systems that value:

* low runtime overhead
* explicit architecture
* repeatable behavior
* production sanity
* composable package boundaries

It is not trying to be a maximalist "developer experience" console framework where every ergonomic flourish is purchased with hidden indirection, runtime scanning, and enough implicit behavior to qualify as folklore.

CitOmni's position is simpler: commands should run predictably, boot cheaply, fail clearly, and respect architectural boundaries.

That is usually more useful than spectacle.

---

## Performance notes

* Use optimized Composer autoloading in production:

  ```json
  {
  	"config": {
  		"optimize-autoloader": true,
  		"classmap-authoritative": true,
  		"apcu-autoloader": true
  	}
  }
  ```

* Then run:

  ```bash
  composer dump-autoload -o
  ```

* Keep vendor baselines lean

* Prefer explicit service registration over dynamic discovery

* Avoid putting domain orchestration directly into commands

* Use OPcache in operational environments where CLI processes are frequent

The package is aligned with the broader CitOmni preference for predictable low-cost execution rather than clever abstractions with a surprisingly healthy appetite for CPU cycles.

---

## Contributing

* PHP 8.2+
* PSR-4
* Tabs for indentation
* K&R brace style
* Keep delivery-layer boundaries sharp
* Keep persistence in repositories
* Keep orchestration out of command adapters unless the task is genuinely trivial
* Avoid framework magic
* Prefer explicit behavior over implicit convenience

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni CLI** is open-source under the **MIT License**.
See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may not use the CitOmni name or logo to imply endorsement or affiliation without prior written permission.
For details, see [NOTICE](NOTICE).

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.
For details, see [NOTICE](NOTICE).

---

## Author

Developed by Lars Grove Mortensen © 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.