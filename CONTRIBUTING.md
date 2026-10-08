# Contributing to private.wf

Thanks for contributing — this is a fully open-source project (AGPL-3.0-or-later) by ternis.dev / ternis.org.

## Ground rules
- Be kind, respect privacy. Never post real user media, keys, or credentials in issues/PRs.
- German or English both fine. GDPR-first: minimize personal data in bug reports.
- Small PRs > big PRs. One concern per PR, with tests.

## Dev setup (target, M0)
```bash
cp .env.example .env
docker compose -f infra/docker/compose.yml up -d
composer install && pnpm install
```

## Checks before push
```bash
composer lint && composer analyse && composer test
pnpm lint && pnpm test
```

## Conventional Commits
`feat(api): L1 presigned upload`, `fix(web): expiry badge`, `docs(plans): ...`, `chore(infra): ...`

## Security
Do NOT open public issues for vulnerabilities — see [SECURITY.md](./SECURITY.md).
