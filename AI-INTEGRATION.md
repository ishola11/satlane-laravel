# AI integration and maintenance prompt

Before writing integration code, read README.md, UPGRADING.md, composer.json and the implementation/tests in this package. The SatLane monorepo is the canonical source; satellite repositories are release mirrors.

Use the current API contract, not SDK comments from older versions. Distinguish API-key endpoints from merchant-session/TOTP dashboard endpoints. Test clients use the 2026-10-05 Sandbox contract; Sandbox has no payable destination. Live activation and billing/readiness gates are independent of selecting a UI environment.

Persist order-level idempotency keys and invoice IDs. Use explicit simulation action IDs for retries across calls. Do not expose API keys, webhook secrets, private keys or recovery phrases in browser code, examples, logs or prompts.

Verify raw-body signatures, distinguish completed duplicates from failed work, preserve DB failures as retryable errors, and keep fulfillment idempotent. Never infer payment confirmation from a received balance alone. Preserve reversion and review behavior.

Laravel HTTP delivery and CLI replay must use EventProcessor and its per-invoice lock ordering. Do not create a parallel replay implementation, blindly acknowledge database exceptions, or mark failed work complete. Preserve saved event snapshots and current projection ordering. Timestamp ties are not a monotonic revision guarantee. External side effects cannot be atomically rolled back by the package transaction.

Keep migrations additive. Preserve existing customer records. Confirm model casts, nullable Sandbox fields, custom database connections and multiple store/environment secrets. Tests must reproduce lost retries and concurrency problems rather than merely assert happy-path output.

Run the monorepo PHP checks, compatibility matrix and isolated PostgreSQL processing checks. The localhost Sandbox rehearsal is allowed only against its guarded disposable local fixture profile. Never test with real money or send test emails without user authorization.

Release scripts default to dry run. Publication requires explicit authorization, validated package versions, a clean reviewed checkout and immutable satellite tags. Do not force-push or retarget released versions. Document partial-release recovery. Do not claim a release, CI pass or matrix runtime that has not actually been verified.
