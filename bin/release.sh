#!/usr/bin/env bash
#
# Releases a new version of the bundle: moves the [Unreleased] section of
# CHANGELOG.md under the new version, commits it, creates an annotated tag,
# pushes main and the tag, and creates the GitHub release from the changelog.
#
# Usage: bin/release.sh [--dry-run] <version>
# Run with --help for details.

set -euo pipefail

remote='origin'
branch='main'
repository_url='https://github.com/cristianradulescu/staccato-bundle'
dry_run=0
assume_yes=0
version=''

usage() {
    cat <<'EOF'
Usage: bin/release.sh [options] <version>

Releases <version> (e.g. 0.2.0, without a leading "v"):

  1. checks that main is clean, up to date with origin and that the tag is new
  2. runs the quality gate (composer qa)
  3. moves the [Unreleased] section of CHANGELOG.md under the new version
  4. commits "Release <version>" and creates the annotated tag v<version>
  5. pushes main and the tag to origin
  6. creates the GitHub release v<version> with the changelog section as notes

Options:
  --dry-run   Run the checks and the quality gate, then show the changelog
              changes and release notes without committing, tagging,
              pushing or creating the release
  -y, --yes   Do not ask for confirmation before pushing
  -h, --help  Show this help
EOF
}

fail() {
    echo "Error: $*" >&2
    exit 1
}

step() {
    printf '\n\033[1;32m==>\033[0m %s\n' "$*"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) dry_run=1; shift ;;
        -y|--yes) assume_yes=1; shift ;;
        -h|--help) usage; exit 0 ;;
        -*) fail "unknown option $1 (see --help)" ;;
        *) [[ -z "$version" ]] || fail "only one version can be given"; version="$1"; shift ;;
    esac
done

[[ -n "$version" ]] || { usage >&2; exit 1; }
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "version must look like 1.2.3 (no leading \"v\"), got \"$version\""

tag="v$version"
today="$(date +%Y-%m-%d)"

cd "$(dirname "$0")/.."

step "Checking the repository"
command -v gh >/dev/null || fail "the GitHub CLI (gh) is not installed"
gh auth status >/dev/null 2>&1 || fail "the GitHub CLI is not logged in, run: gh auth login"

[[ "$(git branch --show-current)" == "$branch" ]] || fail "releases are made from $branch, currently on $(git branch --show-current)"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail "the working tree has uncommitted changes"

git fetch --quiet --tags "$remote" "$branch"
[[ -z "$(git rev-list "HEAD..$remote/$branch")" ]] || fail "$branch is behind $remote/$branch, pull first"

if git rev-parse --quiet --verify "refs/tags/$tag" >/dev/null; then
    fail "tag $tag already exists"
fi

latest_tag="$(git describe --tags --abbrev=0 2>/dev/null || true)"
if [[ -n "$latest_tag" ]]; then
    latest_version="${latest_tag#v}"
    [[ "$(printf '%s\n%s\n' "$latest_version" "$version" | sort -V | tail -n 1)" == "$version" && "$latest_version" != "$version" ]] \
        || fail "$version is not newer than the latest tag $latest_tag"
    echo "Latest tag: $latest_tag, releasing: $tag"
    echo "Commits since $latest_tag:"
    git log --oneline "$latest_tag..HEAD" | sed 's/^/  /'
fi

step "Running the quality gate"
composer qa

step "Updating CHANGELOG.md"
# Prints the release notes (the [Unreleased] section) and, unless dry-running, rewrites the changelog.
release_notes="$(php -r '
    [, $file, $version, $date, $url, $write] = $argv;
    $changelog = file_get_contents($file);

    if (1 !== preg_match("/^## \[Unreleased\]\n(.*?)(?=^## \[)/ms", $changelog, $match)) {
        fwrite(STDERR, "CHANGELOG.md has no [Unreleased] section followed by a released version.\n");
        exit(1);
    }

    $notes = trim($match[1]);

    if ("" === $notes) {
        fwrite(STDERR, "The [Unreleased] section of CHANGELOG.md is empty.\n");
        exit(1);
    }

    $changelog = str_replace($match[0], "## [Unreleased]\n\n## [$version] - $date\n\n$notes\n\n", $changelog);

    $links = "[Unreleased]: $url/compare/v$version...HEAD\n[$version]: $url/releases/tag/v$version\n";
    $changelog = preg_replace("/^\[Unreleased\]: .*\n/m", $links, $changelog, 1, $count);

    if (1 !== $count) {
        fwrite(STDERR, "CHANGELOG.md has no [Unreleased] link reference.\n");
        exit(1);
    }

    if ("1" === $write) {
        file_put_contents($file, $changelog);
    }

    echo $notes, "\n";
' CHANGELOG.md "$version" "$today" "$repository_url" "$((1 - dry_run))")"

notes_file="$(mktemp)"
trap 'rm -f "$notes_file"' EXIT
{
    printf '```bash\ncomposer require cristianradulescu/staccato-bundle:^%s\n```\n\n' "$version"
    printf '%s\n' "$release_notes"
    if [[ -n "$latest_tag" ]]; then
        printf '\n**Full changelog:** %s/compare/%s...%s\n' "$repository_url" "$latest_tag" "$tag"
    fi
} > "$notes_file"

if [[ "$dry_run" -eq 1 ]]; then
    step "Release notes for $tag (dry run)"
    cat "$notes_file"
    step "Dry run complete"
    echo "Nothing was committed, tagged, pushed or released. Run again without --dry-run to release $tag."
    exit 0
fi

git --no-pager diff --stat CHANGELOG.md

step "Committing and tagging $tag"
git add CHANGELOG.md
git commit --quiet -m "Release $version"
git tag --annotate "$tag" -m "Release $version"
git --no-pager log --oneline -1 --decorate

if [[ "$assume_yes" -ne 1 ]]; then
    printf '\nPush %s and %s to %s and create the GitHub release? [y/N] ' "$branch" "$tag" "$remote"
    read -r answer
    if [[ "$answer" != [yY] ]]; then
        echo "Stopped before pushing. To undo the local release commit and tag:"
        echo "  git tag -d $tag && git reset --hard HEAD~1"
        exit 1
    fi
fi

step "Pushing $branch and $tag"
git push --atomic "$remote" "$branch" "$tag"

step "Creating the GitHub release"
gh release create "$tag" --verify-tag --title "$tag" --notes-file "$notes_file" --latest

step "Done"
echo "Released $tag: $repository_url/releases/tag/$tag"
echo "Packagist picks up the new tag through the GitHub webhook; check https://packagist.org/packages/cristianradulescu/staccato-bundle"
