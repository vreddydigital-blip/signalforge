# CI workflow (not yet active)

`build-infinityfree.yml` is the GitHub Actions workflow that builds the
InfinityFree upload package on every push and attaches it as a downloadable
artifact.

It lives here instead of `.github/workflows/` because the personal access
token used for the initial push did not carry the `workflow` scope, and
GitHub refuses to accept any file under `.github/workflows/` without it.

## To activate it

Either:

1. **On GitHub (no token needed):** open this file, click **Raw**, copy the
   contents, then go to *Add file → Create new file*, name it
   `.github/workflows/build-infinityfree.yml`, paste, and commit.
2. **From the command line:** create a token with both `repo` and `workflow`
   scopes, then
   `git mv docs/ci/build-infinityfree.yml .github/workflows/ && git commit -m "Enable CI" && git push`.

Once active, every push produces a `signalforge-infinityfree` artifact under
the Actions tab — that zip is exactly what goes into `htdocs`.
