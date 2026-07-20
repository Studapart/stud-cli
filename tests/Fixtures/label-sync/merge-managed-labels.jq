# Label merge algorithm used by .github/scripts/sync-pr-labels.sh
# (jira-label-sync.yml and linear-label-sync.yml).
# Inputs: $current, $prn, $m (slurpfile map JSON object)
($m[0]) as $map
| ($map | to_entries) as $entries
| ($entries | map(.value) | unique) as $managed
| (reduce $entries[] as $e ({}; .[$e.value] += [$e.key])) as $groups
| (
    [ $current[] | . as $l | select(($managed | any(. == $l)) | not) ]
    + [
        $managed[] as $v
        | select(
            ($groups[$v] // []) as $ghs
            | any($ghs[]; . as $g | ($prn | index($g)) != null)
          )
        | $v
      ]
  )
| unique
| sort
