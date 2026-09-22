# Packaging and Castor agent detection

Castor 1.8 can hide its logo when it thinks it is running inside an AI agent terminal (`laravel/agent-detector`, env `CASTOR_DISABLE_AGENT_DETECTION`). That is **environment guessing**. stud-cli does not use it for product behavior.

## Policy

| Runtime | Castor AI-agent detection | stud agent JSON mode |
|---------|---------------------------|----------------------|
| **PHAR** (`Phar::running()`) | Always disabled at bootstrap (`CASTOR_DISABLE_AGENT_DETECTION=1`) | Only `--agent` |
| **Portable** launcher | Same env is exported before `exec` of the bundled PHAR | Only `--agent` |
| **Source checkout** (`php castor.php`, `vendor/bin/castor`, `./stud` from git) | Castor default (may detect Cursor/IDE). Contributors can `export CASTOR_DISABLE_AGENT_DETECTION=1` to match packaging. | Only `--agent` |

There is **no** public CLI flag to toggle Castor detection. Do not add one.

## Path / cwd

`CASTOR_USE_CHDIR` stays **`false`** so `getcwd()`, Process, and FileSystem keep pre-Castor-1.8 path behavior. Interactive prompts still use `posix_isatty(STDIN)` (and `--agent`), not Castor `supportsInteraction()`.

## Why

Shipments must not change logo or stdout wrapping based on Cursor/IDE variables. Automation always passes `--agent` when it wants JSON.
