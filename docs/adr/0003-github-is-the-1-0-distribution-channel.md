# GitHub, not wordpress.org, is the 1.0 distribution channel

The Charter's Objectives line names wordpress.org, but that is a long-term **vision**, not the 1.0 plan. 1.0 is built to live on GitHub and work well in that environment, mirroring the `kntnt-gpx-blocks` template: self-hosted updates via a GitHub-release `Updater` (ported from gpx-blocks), releases shipped as a GitHub-release `.zip` asset built by `build-release-zip.sh`. No wordpress.org machinery is added — no `readme.txt`, no `.wordpress-org/` assets, no SVN deploy.

## Consequences

- Keep the gpx-blocks `Updater` component; it is required for GitHub-hosted self-updates.
- Downstream decisions must **not** be weighed against a possible future wordpress.org move. Build for GitHub now.
- A wordpress.org migration later would be a deliberate, separately-scoped effort (add `readme.txt`, plugin-page assets, SVN deploy, and drop the `Updater`), not a constraint on 1.0.
- The PHP 8.5 floor's reach concern (ADR-0001) is largely moot here: GitHub-installed plugins reach technically capable users who can ensure a modern runtime.
