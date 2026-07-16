# Maintainer notes

## Stable release policy

Use one version value in each of these forms for a release such as 1.6.0:

| Surface | Required form |
|---|---|
| Release branch | `release/1.6.0` |
| Composer/Packagist version | `1.6.0` |
| Git tag | `v1.6.0` |
| GitHub release title | `v1.6.0` |

The difference is intentional. GitHub keeps the project's conventional `v`
tag format, while Packagist must expose the unprefixed semantic version. Set
the Composer/Packagist release metadata to `1.6.0` in the release commit so
Packagist presents that version even though the Git tag is `v1.6.0`.

## Release workflow

1. Create `release/<version>` from the default branch (`master` or `main`),
   for example `release/1.6.0`.
2. Update `CHANGELOG.md` with a dated `<version>` section and make any required
   version-metadata changes. Do not commit internal planning documents unless
   they are explicitly intended for users.
3. Run the release checks from a clean checkout. This includes Composer
   validation and audit, tests, static analysis, style checks, coverage, and a
   fresh dependency-install smoke test.
4. Open a pull request from `release/<version>` to the default branch. A stable
   release is never cut directly from a feature branch or an unreviewed local
   commit.
5. Merge after every required CI status is green. Fetch the merge commit from
   the default branch and create the immutable Git tag `v<version>` there.
6. Create the GitHub release titled `v<version>`. Copy the corresponding
   `CHANGELOG.md` section into the GitHub release notes; do not leave
   auto-generated compare links as the release notes.
7. Verify Packagist's package metadata and a clean Composer install resolve
   the unprefixed `<version>` to the tagged commit. Check both the displayed
   version and the source/dist reference.

## Packagist lessons

- A stable version becomes immutable after Packagist indexes it. Do not force
  move, delete-and-recreate, or otherwise repoint a published stable tag.
  Correct a release with the next patch version instead.
- Packagist and Composer repository CDN variants may update at different
  times. A newly indexed version can appear in the package page or normal
  metadata before it is visible to Composer's compressed repository request.
  Wait for propagation, clear Composer's cache, and repeat the clean install
  smoke test rather than retagging.
- Confirm the repository's Packagist webhook remains active. A successful
  webhook response only queues the update; it does not make a mutable tag safe
  to change.

## Commands and checks

Adapt `<version>` and `<default-branch>` for the release:

```bash
git switch -c release/<version> origin/<default-branch>
composer validate --strict --no-check-lock --no-check-version
composer audit
composer check

# After the release PR is merged and CI is green:
git fetch origin <default-branch> --tags
git tag v<version> origin/<default-branch>
git push origin v<version>

# Use the changelog section as GitHub release notes.
gh release create v<version> --title v<version> --notes-file <release-notes-file>

# Verify the published artifact without modifying this checkout.
tmpdir=$(mktemp -d)
(
  cd "$tmpdir"
  composer init --name oeltimacreation/php-simplequeue-release-smoke --no-interaction
  composer --no-cache require oeltimacreation/php-simplequeue:<version>
)
rm -rf "$tmpdir"
```

The non-prefixed Composer/Packagist version metadata is intentional for this
project. Composer normally warns about a `version` field in a VCS-published
package, so the release validation explicitly uses `--no-check-version`. Keep
the metadata and the release version synchronized exactly.
