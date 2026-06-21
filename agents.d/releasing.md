Read when cutting a release.

Automated (ADR-0005). Bump the `Version:` header in `kntnt-ai-visibility.php` to `X.Y.Z`, commit, then push the tag `X.Y.Z`. `.github/workflows/release.yml` verifies the header matches the tag, runs `build-release-zip.sh` and publishes `kntnt-ai-visibility.zip` – the version-less name keeps the `latest/download` URL stable. The build ships a production `vendor/` and excludes tests, CI and dev files.
