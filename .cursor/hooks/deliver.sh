#!/bin/sh
# Agent-facing delivery: try Stud, then native only if HEAD and porcelain unchanged.
# Usage:
#   sh deliver.sh commit [message]
#   sh deliver.sh push
#   sh deliver.sh pr          # maps to `stud submit`; native fallback is `gh pr create`
# Optional env: DELIVER_MESSAGE (used for native commit fallback when arg omitted).
# Not wrapped: `stud please` (force-with-lease) and `stud flatten`. Those stay
# explicit Stud commands, only when the user or contract asks.
# Native fallback uses raw `git`/`gh` so HEAD and porcelain stay observable.
# Do not probe `rtk` here.
set -eu

usage() {
  echo "usage: sh $0 commit [message] | push | pr" >&2
  exit 2
}

[ "${1:-}" = "commit" ] || [ "${1:-}" = "push" ] || [ "${1:-}" = "pr" ] || usage
ACTION="$1"
shift
MSG="${1:-${DELIVER_MESSAGE:-}}"

head_before="$(git rev-parse HEAD)"
porcelain_before="$(git status --porcelain)"

run_stud() {
  case "$ACTION" in
    commit) stud commit ;;
    push) stud push ;;
    pr) stud submit ;;
  esac
}

run_native() {
  case "$ACTION" in
    commit)
      if [ -z "$(git diff --cached --name-only 2>/dev/null || true)" ]; then
        echo "deliver.sh: no staged files for native commit" >&2
        exit 1
      fi
      if [ -z "$MSG" ]; then
        echo "deliver.sh: native commit needs a message (pass as 2nd arg or DELIVER_MESSAGE)" >&2
        exit 1
      fi
      git commit -m "$MSG"
      ;;
    push)
      git push
      ;;
    pr)
      if ! command -v gh >/dev/null 2>&1; then
        echo "deliver.sh: gh not on PATH; cannot create PR" >&2
        exit 1
      fi
      gh pr create
      ;;
  esac
}

set +e
stud_out="$(run_stud 2>&1)"
stud_rc=$?
set -e

if [ "$stud_rc" -eq 0 ]; then
  printf '%s\n' "$stud_out"
  exit 0
fi

head_after="$(git rev-parse HEAD)"
porcelain_after="$(git status --porcelain)"

if [ "$head_before" != "$head_after" ] || [ "$porcelain_before" != "$porcelain_after" ]; then
  printf '%s\n' "$stud_out" >&2
  echo "deliver.sh: Stud mutated the repo; refusing native fallback" >&2
  exit "$stud_rc"
fi

echo "fallback: pre-mutation" >&2
printf '%s\n' "$stud_out" >&2
run_native
