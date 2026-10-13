# Upgrade to 0.2

Prepared locally; not yet published. Upgrade the PHP and Laravel packages together.

1. Back up the application database and review your handler's order-level idempotency.
2. Install the new package and publish the additive migration/config using the normal package installer or vendor:publish. Do not overwrite edited configuration.
3. Run migrations before enabling the upgraded webhook receiver. Existing invoice/event rows remain. The new nullable destination and processing-lock table are required.
4. Ensure the current Sandbox contract is enabled for Test keys, and use a separate Live key/connection for production. Optional connection restrictions are `SATLANE_STORE_ID` and `SATLANE_ENVIRONMENT`.
5. Exercise a failed handler followed by the same delivery and verify recovery. Test raw-body signatures, completed duplicates and reversion handling.
6. Review old failed events. `satlane:replay --failed` now shares the receiver pipeline; stored handler classes or the configured default are used. Previously completed records that were silently skipped by old code need deliberate review and, if appropriate, `--force` replay.

Processing runs under per-invoice database locks, with same-connection changes inside a transaction. External effects and different database connections cannot be rolled back. Keep handlers fast and idempotent. Invoice mirroring can be disabled without disabling handlers; they receive an unsaved event snapshot.

Completed duplicates are skipped; failures remain retryable. Manual forced replay uses the saved snapshot, and does not regress a newer mirrored invoice. Timestamp ordering prevents strictly older snapshots from overwriting newer ones; equal/missing timestamps remain an API-envelope limitation. Retrieve authoritative state for business decisions that require it.

Pruning preserves failed/unprocessed rows. Review retention because deleting completed IDs ends their deduplication protection. Invoice-lock anchors remain to avoid deletion/processing races.

Laravel 10 on PHP 8.1 uses Doctrine DBAL 3 for schema alteration. The compatibility workflow pins it explicitly. If maintaining Laravel 10 on newer PHP, use a compatible DBAL 3 dependency when installing; newer Laravel versions support DBAL 4. The selected dependency versions are validated in the matrix.

The old timeline/webhook-management API-key helpers are deprecated. Use the dashboard for those operations. Installer errors now return nonzero exit codes; scripts that previously ignored warnings should handle failure.

Legacy Laravel 10/11 compatibility tests report upstream framework advisories. Use a supported, patched Laravel version for production; compatibility coverage does not certify those framework branches as secure.
