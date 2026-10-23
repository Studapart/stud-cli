#!/bin/sh
# beforeShellExecution policy for Cursor hooks.
# Reads JSON on stdin: {"command":"..."}. Writes JSON on stdout:
#   {"permission":"allow"} or
#   {"permission":"deny","agent_message":"...","user_message":"..."}
# Always exits 0 after emitting JSON (failClosed treats empty/invalid JSON as block).
#
# Scope: a guardrail that routes a cooperative agent's delivery through
# deliver.sh while active is stud. Every command is allowed except mutating
# git/gh (commit, push, branch creation, gh pr create), including `rtk git` /
# `rtk gh`, detected after shell
# lexing in every segment, substitution, `sh -c` and `eval` payload, behind
# env assignments, wrappers, absolute paths and git global options.
# Deliberate evasion (aliases, scripts written to disk, interpreters) is out of
# scope: server-side branch protection is the security boundary.
set -eu

responded=0

emit() {
  responded=1
  printf '%s\n' "$1"
  exit 0
}

allow() {
  emit '{"permission":"allow"}'
}

deny() {
  emit "{\"permission\":\"deny\",\"user_message\":\"$1\",\"agent_message\":\"$1\"}"
}

trap 'if [ "$responded" -eq 0 ]; then
  printf "%s\n" "{\"permission\":\"deny\",\"user_message\":\"delivery-policy: no decision emitted\",\"agent_message\":\"delivery-policy: no decision emitted\"}"
fi' EXIT

resolve_active() {
  if [ -n "${DELIVERY_ACTIVE:-}" ]; then
    printf '%s\n' "$DELIVERY_ACTIVE"
    return
  fi
  if [ -f .cursor/PROJECT_CONTEXT.md ]; then
    line="$(grep -E '^[[:space:]]*-?[[:space:]]*active:' .cursor/PROJECT_CONTEXT.md | head -n 1 || true)"
    case "$line" in
      *native*) printf '%s\n' native; return ;;
      *stud*) printf '%s\n' stud; return ;;
    esac
  fi
  if command -v stud >/dev/null 2>&1; then
    printf '%s\n' stud
  else
    printf '%s\n' native
  fi
}

# Prints one verdict: allow | commit | push | pr | branch | invalid.
# The command comes from stdin JSON, else CURSOR_SHELL_COMMAND, else argv[1].
CLASSIFY='
import json, os, shlex, sys

SEPARATORS = set("();<>|&")
WRAPPERS = {"command", "exec", "nohup", "time", "nice", "sudo", "env", "xargs", "builtin"}
SHELLS = {"sh", "bash", "zsh", "dash", "ksh"}
GIT_OPTS_WITH_VALUE = {"-c", "-C", "--git-dir", "--work-tree", "--namespace", "--config-env"}
MAX_DEPTH = 5


def read_command():
    raw = sys.stdin.read()
    if raw.strip():
        cmd = json.loads(raw).get("command")
        if not isinstance(cmd, str):
            raise ValueError("command is not a string")
        return cmd
    return os.environ.get("CURSOR_SHELL_COMMAND") or (sys.argv[1] if len(sys.argv) > 1 else "")


def segments(cmd):
    cmd = cmd.replace("`", " ; ").replace("\n", " ; ").replace("\r", " ; ")
    lexer = shlex.shlex(cmd, posix=True, punctuation_chars=True)
    lexer.whitespace_split = True
    words = []
    for token in lexer:
        if token and set(token) <= SEPARATORS:
            if words:
                yield words
            words = []
        else:
            words.append(token)
    if words:
        yield words


def is_assignment(word):
    name, sep, _ = word.partition("=")
    return bool(sep) and name.isidentifier()


def classify_git(args):
    i = 0
    while i < len(args) and args[i].startswith("-"):
        i += 2 if args[i] in GIT_OPTS_WITH_VALUE else 1
    if i >= len(args):
        return None
    sub, opts = args[i], args[i + 1:]
    if sub in ("commit", "push"):
        return sub
    if sub == "checkout" and any(o == "--orphan" or (o[:2] in ("-b", "-B")) for o in opts):
        return "branch"
    if sub == "switch" and any(
        o in ("--orphan", "--create", "--force-create")
        or o.startswith(("--create=", "--force-create="))
        or o[:2] in ("-c", "-C")
        for o in opts
    ):
        return "branch"
    return None


def classify_gh(args):
    positional, i = [], 0
    while i < len(args):
        if args[i] in ("-R", "--repo"):
            i += 2
            continue
        if not args[i].startswith("-"):
            positional.append(args[i])
        i += 1
    return "pr" if positional[:2] == ["pr", "create"] else None


def classify_words(words, depth):
    i = 0
    while i < len(words):
        if is_assignment(words[i]):
            i += 1
        elif os.path.basename(words[i]) in WRAPPERS:
            i += 1
            while i < len(words) and (words[i].startswith("-") or is_assignment(words[i])):
                i += 1
        else:
            break
    if i >= len(words):
        return None
    name, args = os.path.basename(words[i]), words[i + 1:]
    if name in SHELLS:
        for j, arg in enumerate(args):
            if arg.startswith("-") and not arg.startswith("--") and "c" in arg:
                return classify(args[j + 1], depth + 1) if j + 1 < len(args) else None
        return None
    if name == "eval":
        return classify(" ".join(args), depth + 1)
    if name == "rtk":
        # Skip only leading rtk flags (-v, --help). Keep git/gh flags
        # (-C, -b, -c) so checkout -b and git -C dir commit stay visible.
        j = 0
        while j < len(args) and args[j].startswith("-"):
            j += 1
        if j < len(args) and args[j] in ("git", "gh"):
            return classify_words(args[j:], depth)
        return None
    if name == "git":
        return classify_git(args)
    if name == "gh":
        return classify_gh(args)
    return None


def classify(cmd, depth):
    if depth > MAX_DEPTH:
        return "invalid"
    for words in segments(cmd):
        for word in words:
            if "$(" in word or set(word) & SEPARATORS:
                verdict = classify(word, depth + 1)
                if verdict:
                    return verdict
        verdict = classify_words(words, depth)
        if verdict:
            return verdict
    return None


try:
    print(classify(read_command(), 0) or "allow")
except Exception:
    print("invalid")
'

[ "$(resolve_active)" = "native" ] && allow

command -v python3 >/dev/null 2>&1 ||
  deny "delivery-policy needs python3 while active is stud"

INPUT="$(cat || true)"
VERDICT="$(printf '%s' "$INPUT" | python3 -c "$CLASSIFY" "${1:-}")"

case "$VERDICT" in
  allow) allow ;;
  commit|push|pr) deny "Denied: use sh .cursor/hooks/deliver.sh ${VERDICT}" ;;
  branch) deny "Denied: create work branches with stud start" ;;
  *) deny "Denied: delivery-policy could not parse this command" ;;
esac
