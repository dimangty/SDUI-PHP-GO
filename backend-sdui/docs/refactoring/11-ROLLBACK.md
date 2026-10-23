# Rollback

No schema/PHP/CMP/database/persistence migration is part of this change.
Environment names/routes and SDUIv1 remain compatible.

1. Retain the previously deployed Go image digest before rollout.
2. Roll back only the Go service through existing deployment mechanisms. Do not
   edit shared Compose/Nginx/deployment sources as part of this backend task.
3. Probe private live/ready, public anonymous home/catalog and an authenticated
   screen/command using the existing test-account policy.
4. Watch edge/upstream errors/timeouts. Old image lacks the new private /metrics;
   disable any separately configured scrape when reverting.
5. No data restore or PHP/client rollback is required. A timed-out mutation may
   already have committed: read/reconcile through Yii, never blindly replay it.

Source baseline isad95258. For source rollback use a normal revert of the eventual
refactoring commit after coordinating later backend changes. Never reset --hard,
force-push or restore files outside backend-sdui. Retaining the prior image avoids
rebuilding old Go1.26.0 source from mutable Docker tags. Continue regular reviewed
toolchain/base security updates even if application changes are rolled back.
