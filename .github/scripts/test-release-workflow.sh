#!/usr/bin/env bash
#
# Exercises the `run:` blocks of .github/workflows/release.yml.
#
# The release workflow only fires on a push to main that touches
# composer.json, so no ordinary CI run ever executes its scripts — the
# first real run of a change to them is an actual release, unattended
# and with `contents: write`. This test extracts the blocks straight out
# of the workflow (so it cannot drift from what ships) and runs them
# against fixtures.
#
# It covers the three things that already went wrong here:
#   - `${{ … }}` inside a run block, which GitHub substitutes textually
#     before bash parses it, turning the version field into shell code
#   - a version field carrying shell metacharacters or newlines
#   - the CHANGELOG heading matched as a regex instead of literally

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
WORKFLOW=".github/workflows/release.yml"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

pass=0
fail=0
ok() { echo "  ok    $1"; pass=$((pass + 1)); }
no() { echo "  FAIL  $1"; fail=$((fail + 1)); }

# ---------------------------------------------------------------- setup
# Pull the run blocks out of the workflow by step name. Renaming a step
# without updating this list fails the test rather than silently
# skipping its coverage.

if ! python3 - "$WORKFLOW" "$WORK" <<'PY'
import pathlib
import sys

import yaml

workflow, work = sys.argv[1], pathlib.Path(sys.argv[2])
steps  = yaml.safe_load(open(workflow))["jobs"]["release"]["steps"]
byname = {s["name"]: s for s in steps if s.get("name") and "run" in s}

wanted = {
	"Read the version from composer.json":          "version.sh",
	"Check whether the version is already tagged":  "tag.sh",
	"Extract the release notes from the CHANGELOG": "notes.sh",
}

if missing := [name for name in wanted if name not in byname]:
	sys.exit("step(s) missing from %s: %s" % (workflow, ", ".join(missing)))

# the rule the workflow documents in a comment, enforced here
leaky = sorted(name for name, step in byname.items() if "${{" in step["run"])
(work / "leaky.txt").write_text("\n".join(leaky))

# A run: block without a `shell:` key is executed by GitHub as
# `bash -e {0}`, which is what this harness mirrors. Declaring
# `shell: bash` would switch it to
# `bash --noprofile --norc -eo pipefail {0}` — different enough
# (pipefail) that the harness would stop matching production, so
# report any step that sets one instead of silently drifting.
shells = sorted(name for name in wanted if byname[name].get("shell"))
(work / "shells.txt").write_text("\n".join(shells))

for name, filename in wanted.items():
	(work / filename).write_text(byname[name]["run"])
PY
then
	echo "could not extract the steps from $WORKFLOW"
	exit 1
fi

# Runs the extracted version step against a composer.json holding
# $1, and reports through $RC (exit code) and $OUT ($GITHUB_OUTPUT).
# A payload that reaches a shell creates $SANDBOX/CANARY.
run_version() {
	SANDBOX="$WORK/sandbox"
	rm -rf "$SANDBOX"
	mkdir -p "$SANDBOX"

	if [ "$1" = "__missing__" ]; then
		echo '{}' > "$SANDBOX/composer.json"
	else
		jq -n --arg v "$1" '{version: $v}' > "$SANDBOX/composer.json"
	fi

	: > "$SANDBOX/out"
	(cd "$SANDBOX" && GITHUB_OUTPUT="$SANDBOX/out" bash -e "$WORK/version.sh") > /dev/null 2>&1
	RC=$?
	OUT="$(cat "$SANDBOX/out")"
}

accepts() {
	local expected="version=${2-$1}"
	run_version "$1"

	if [ "$RC" -eq 0 ] && [ "$OUT" = "$expected" ]; then
		ok "accepts ${3-$(printf '%q' "$1")}"
	else
		no "expected accept of ${3-$(printf '%q' "$1")} — rc=$RC output=$(printf '%q' "$OUT")"
	fi
}

rejects() {
	run_version "$1"
	local canary=no
	[ -e "$SANDBOX/CANARY" ] && canary=yes

	if [ "$RC" -ne 0 ] && [ -z "$OUT" ] && [ "$canary" = no ]; then
		ok "rejects ${2-$(printf '%q' "$1")}"
	else
		no "expected reject of ${2-$(printf '%q' "$1")} — rc=$RC output=$(printf '%q' "$OUT") executed=$canary"
	fi
}

# Runs the extracted release-notes step over $2 (a CHANGELOG) for
# version $1 and echoes the resulting release-notes.md.
run_notes() {
	local sandbox="$WORK/notes"
	rm -rf "$sandbox"
	mkdir -p "$sandbox"
	cp "$2" "$sandbox/CHANGELOG.md"

	(cd "$sandbox" && VERSION="$1" REPOSITORY="owner/repo" bash -e "$WORK/notes.sh") > /dev/null 2>&1
	cat "$sandbox/release-notes.md"
}

# ------------------------------------------------------------ structure
echo "workflow structure"

leaky="$(tr '\n' ' ' < "$WORK/leaky.txt")"
if [ -z "${leaky// /}" ]; then
	ok 'no ${{ }} interpolation inside any run: block'
else
	no "run: blocks interpolate expressions (they become shell code): $leaky"
fi

shells="$(tr '\n' ' ' < "$WORK/shells.txt")"
if [ -z "${shells// /}" ]; then
	ok "every tested step runs under the default shell this harness mirrors"
else
	no "step(s) declare shell:, so 'bash -e' no longer matches them — update the flags here: $shells"
fi

# --------------------------------------------------------- version gate
echo "version field — accepted"
accepts "0.3.0"
accepts "1.0.0"
accepts "10.20.30"
accepts "0.4.0-beta.1"
accepts "1.2.3-rc1"
accepts "1.2.3+build.5"
accepts "1.2.3-alpha.1+build.5"
accepts "__missing__" "" "a composer.json without a version field"

echo "version field — rejected without reaching a shell"
rejects '0.4.0$(touch CANARY)'         'command substitution'
rejects '0.4.0`touch CANARY`'          'backtick substitution'
rejects '0.4.0"; touch CANARY; #'      'quote break + comment'
rejects '0.4.0 ]; then touch CANARY; fi; if [ -z "' 'balanced statement injection'
rejects '0.4.0; touch CANARY'          'command separator'
rejects '0.4.0 && touch CANARY'        'conditional chain'
rejects "$(printf '0.4.0\nnew=true')"  'newline forging a second step output'
rejects '0.4.0\'                       'trailing backslash'
rejects '0.4.0 '                       'trailing space'
rejects 'v1.2.3'                       'a leading v'
rejects '1.2'                          'a two-part version'
rejects 'main'                         'a non-version string'

# A hostile value that somehow got past the gate still must not execute
# when a later step consumes it: that is what passing through `env:`
# buys, as opposed to `${{ }}`.
echo "downstream consumption"
sandbox="$WORK/downstream"
rm -rf "$sandbox" && mkdir -p "$sandbox"
: > "$sandbox/out"
(cd "$sandbox" && GITHUB_OUTPUT="$sandbox/out" VERSION='0.4.0$(touch CANARY)' \
	bash -e "$WORK/tag.sh") > /dev/null 2>&1
if [ -e "$sandbox/CANARY" ]; then
	no "the tag step executed a hostile VERSION"
else
	ok "the tag step consumes a hostile VERSION as data"
fi

# --------------------------------------------------- release notes
echo "release notes"

cat > "$WORK/CHANGELOG.md" <<'EOF'
# Changelog

## 1.2.3+build.5 (2026-08-01)

- MARKER_BUILD

## 0x3x0 (2026-07-20)

- MARKER_DECOY

## 0.3.0 (2026-07-08)

- MARKER_WANTED

## 0.2.0 (2026-07-01)

- MARKER_OLDER
EOF

notes="$(run_notes "0.3.0" "$WORK/CHANGELOG.md")"
case "$notes" in
	*MARKER_WANTED*) ;;
	*) no "the 0.3.0 section was not extracted"; notes="";;
esac
if [ -n "$notes" ]; then
	if [[ "$notes" == *MARKER_DECOY* ]]; then
		no "a '## 0x3x0' heading matched 0.3.0 ('.' used as a regex wildcard)"
	elif [[ "$notes" == *MARKER_OLDER* ]]; then
		no "the section ran past its heading into 0.2.0"
	else
		ok "extracts exactly the requested section"
	fi
fi

notes="$(run_notes "1.2.3+build.5" "$WORK/CHANGELOG.md")"
if [[ "$notes" == *MARKER_BUILD* ]]; then
	ok "matches a heading carrying build metadata ('+' not a quantifier)"
else
	no "a '+build' version did not match its own heading"
fi

notes="$(run_notes "9.9.9" "$WORK/CHANGELOG.md")"
if [[ "$notes" == *"blob/main/CHANGELOG.md"* ]]; then
	ok "falls back to the CHANGELOG link for an unknown version"
else
	no "an unknown version did not fall back to the CHANGELOG link"
fi

# ----------------------------------------------------- the real files
# Guards the release process itself: the version in composer.json has to
# pass the gate and needs its own CHANGELOG section, otherwise the
# release would publish the generic fallback instead of its notes.
echo "committed composer.json and CHANGELOG"

version="$(jq -r '.version // empty' composer.json)"
accepts "$version" "$version" "the committed version ($version)"

notes="$(run_notes "$version" CHANGELOG.md)"
if [ -n "$notes" ] && [[ "$notes" != *"blob/main/CHANGELOG.md"* ]]; then
	ok "CHANGELOG.md has a section for $version"
else
	no "CHANGELOG.md has no '## $version' section — the release would publish the fallback note"
fi

# ------------------------------------------------------------- summary
echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
