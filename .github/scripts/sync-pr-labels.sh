#!/usr/bin/env bash
# Sync mapped GitHub PR labels to Jira or Linear via stud items:show + items:update --agent.
# Preserves labels that are not values in the label map (see merge-managed-labels.jq).
#
# Required env:
#   PROVIDER        jira | linear
#   LABEL_MAP_JSON  JSON object (GitHub label name -> tracker label name)
#   LABELS_JSON     GitHub PR labels array JSON (from toJson(github.event.pull_request.labels))
#   HEAD_REF        PR head branch name
#   MAP_VAR_NAME    Repository variable name for error messages (e.g. STUD_JIRA_LABEL_MAP)
#
# Soft-skip (exit 0): items:show fails / success=false for this provider (wrong tracker / not found).
# Hard-fail (exit 1): missing/invalid map, missing issue key in branch, items:update failure after successful show.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
JQ_MERGE="${REPO_ROOT}/tests/Fixtures/label-sync/merge-managed-labels.jq"

if [ -z "${PROVIDER:-}" ]; then
  echo 'PROVIDER is required (jira or linear).'
  exit 1
fi
case "${PROVIDER}" in
  jira|linear) ;;
  *)
    echo "PROVIDER must be jira or linear, got: ${PROVIDER}"
    exit 1
    ;;
esac

if [ -z "${MAP_VAR_NAME:-}" ]; then
  MAP_VAR_NAME="STUD_LABEL_MAP"
fi

if [ -z "${LABEL_MAP_JSON//[[:space:]]/}" ]; then
  echo "Repository variable ${MAP_VAR_NAME} is not set. Set it to a JSON object mapping GitHub PR label names to ${PROVIDER} labels."
  exit 1
fi

if [ ! -f "${JQ_MERGE}" ]; then
  echo "Missing merge jq program at ${JQ_MERGE}"
  exit 1
fi

MAP_FILE="$(mktemp)"
SHOW_FILE="$(mktemp)"
UPDATE_FILE="$(mktemp)"
trap 'rm -f "${MAP_FILE}" "${SHOW_FILE}" "${UPDATE_FILE}"' EXIT

printf '%s' "${LABEL_MAP_JSON}" > "${MAP_FILE}"
if ! jq -e . "${MAP_FILE}" >/dev/null 2>&1; then
  echo "${MAP_VAR_NAME} is not valid JSON."
  exit 1
fi

KEY="$(printf '%s' "${HEAD_REF:-}" | grep -oE '[A-Z][A-Z0-9]*-[0-9]+' | head -1 || true)"
if [ -z "${KEY}" ]; then
  echo "No issue key found in branch name; expected a segment like PROJ-123."
  exit 1
fi

echo "Syncing PR labels to ${PROVIDER} for issue ${KEY}."

set +e
jq -n --arg key "${KEY}" --arg provider "${PROVIDER}" \
  '{key: $key, provider: $provider}' | stud items:show --agent > "${SHOW_FILE}"
SHOW_STATUS=$?
set -e

if [ "${SHOW_STATUS}" -ne 0 ] || ! jq -e '.success == true' "${SHOW_FILE}" >/dev/null 2>&1; then
  echo "Soft-skip: stud items:show --agent failed or returned success=false for provider=${PROVIDER} key=${KEY} (wrong tracker or issue not found)."
  if [ -s "${SHOW_FILE}" ]; then
    cat "${SHOW_FILE}"
  else
    echo "(no agent JSON written; stud exit status ${SHOW_STATUS})"
  fi
  exit 0
fi

CURRENT="$(jq -c '.data.issue.labels // []' "${SHOW_FILE}")"
PR_NAMES="$(printf '%s' "${LABELS_JSON}" | jq -c '[.[].name]')"
MERGED="$(jq -cn \
  --argjson current "${CURRENT}" \
  --argjson prn "${PR_NAMES}" \
  --slurpfile m "${MAP_FILE}" \
  -f "${JQ_MERGE}")"
CUR_SORT="$(printf '%s' "${CURRENT}" | jq -c 'unique | sort')"

if [ "${MERGED}" = "${CUR_SORT}" ]; then
  echo "${PROVIDER} labels already match merged PR sync map; skipping items:update."
  exit 0
fi

FIELDS_VALUE="$(printf '%s' "${MERGED}" | jq -r 'join(",")')"
jq -n --arg key "${KEY}" --arg fields "labels=${FIELDS_VALUE}" --arg provider "${PROVIDER}" \
  '{key: $key, fields: $fields, provider: $provider}' > "${UPDATE_FILE}"

set +e
stud items:update --agent < "${UPDATE_FILE}" > "${SHOW_FILE}"
UPDATE_STATUS=$?
set -e

if [ "${UPDATE_STATUS}" -ne 0 ] || ! jq -e '.success == true' "${SHOW_FILE}" >/dev/null 2>&1; then
  echo "stud items:update --agent failed for provider=${PROVIDER} key=${KEY}."
  if [ -s "${SHOW_FILE}" ]; then
    cat "${SHOW_FILE}"
  else
    echo "(no agent JSON written; stud exit status ${UPDATE_STATUS})"
  fi
  exit 1
fi

echo "Updated ${PROVIDER} labels for ${KEY}."
