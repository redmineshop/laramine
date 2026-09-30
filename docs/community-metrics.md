# Community metrics

Locked definitions for **September 2026**. This file records how those KPIs are counted. It does not record live totals. Parity remains **NOT VERIFIED** ([parity-checklist.md](parity-checklist.md)). The v1 checklist in [../QUALITY.md](../QUALITY.md) is unchanged.

Do not invent a current count in this file. Stars, release downloads, and image pulls change outside the git history.

## Definitions

| KPI | Definition | Target this month |
| --- | --- | --- |
| Stars | GitHub stargazer count on `redmineshop/laramine`. | 1000 |
| Downloads / tải | Cumulative GitHub Release asset download counts, plus Docker Hub pulls **when an image exists**. Sum asset `download_count` values across releases. Add Docker Hub pulls only after an image is published. Views, unique visitors, and Traffic clones stay out of this sum. | 10000, using the definition in this row |
| GitHub Traffic clones | Secondary leading indicator only. GitHub’s traffic clones (the repository Traffic view / clones API). A short rolling window, not a lifetime total. | None. This series is not the 10,000 target. |

## 10,000 target

The 10,000 target is the Downloads / tải row: cumulative GitHub Release asset downloads, plus Docker Hub pulls once an image exists.

It is not GitHub Traffic clones, unique clones, or clone uniques. Do not report clones as that target, and do not add clone counts to the downloads sum.

## Sources that exist today

- Stars: the GitHub repository stargazer count.
- GitHub Release assets: none. `git tag` and GitHub Releases are empty, so the asset term is not yet measurable.
- Docker Hub: no image is published from this repository yet, so the pull term is not yet included.
- Clones: GitHub Traffic, which is a short rolling window (14 days in the traffic API), not a lifetime download total. Use it only as a leading indicator.

## What not to mix in

- Package-manager installs (Packagist, npm), whether or not a listing exists
- CI cache hits, git fetches by maintainers, or local `composer install`
- SQLite smoke runs, PHPUnit runs, or container starts in GitHub Actions
