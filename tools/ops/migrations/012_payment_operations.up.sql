-- Durable payment outbox. Public payment/order statuses remain unchanged.
ALTER TABLE orders ADD COLUMN stock_restored_at timestamptz;
-- Historical cancellation already returned the reservation in the old lifecycle.
UPDATE orders SET stock_restored_at=now() WHERE status='CANCELLED';
ALTER TABLE payments ADD COLUMN cancel_requested_at timestamptz;
-- Business intent survives a different live/uncertain operation blocking dispatch.
ALTER TABLE payments ADD COLUMN refund_requested_at timestamptz;

CREATE TABLE payment_operations (
    id uuid PRIMARY KEY,
    payment_id uuid NOT NULL REFERENCES payments(id),
    kind text NOT NULL CHECK (kind IN ('authorize','void','refund')),
    operation_key text NOT NULL UNIQUE,
    state text NOT NULL CHECK (state IN ('READY','SENT','UNKNOWN','SUCCEEDED','ABORTED')),
    lease_token uuid,
    lease_until timestamptz,
    sent_at timestamptz,
    last_error text,
    result jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(payment_id,kind),
    CHECK (state<>'SENT' OR (lease_token IS NOT NULL AND lease_until IS NOT NULL AND sent_at IS NOT NULL))
);
CREATE UNIQUE INDEX payment_operations_one_live ON payment_operations(payment_id)
    WHERE state IN ('READY','SENT','UNKNOWN');
CREATE INDEX payment_operations_recovery ON payment_operations(state,lease_until,created_at);

-- Append-only provider observations are separate from the authoritative applied
-- result/operator evidence. A fenced response is still a fact worth retaining.
CREATE TABLE payment_operation_observations (
    id bigserial PRIMARY KEY,
    operation_id uuid NOT NULL REFERENCES payment_operations(id),
    lease_token uuid NOT NULL,
    observed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    result jsonb NOT NULL,
    UNIQUE(operation_id,lease_token)
);

-- A transaction belongs to precisely one initiated payment, including after reversal.
CREATE TABLE payment_transaction_links (
    transaction_id varchar(255) PRIMARY KEY,
    payment_id uuid NOT NULL UNIQUE REFERENCES payments(id),
    created_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO payment_transaction_links(transaction_id,payment_id)
SELECT p.transaction_id,p.id FROM payments p
WHERE p.method<>'ON_DELIVERY' AND p.transaction_id IS NOT NULL AND p.transaction_id<>''
AND (SELECT count(*) FROM payments other WHERE other.transaction_id=p.transaction_id)=1;

-- The old writer could POST and roll back its response fields. Every pre-existing
-- external PENDING row is therefore ambiguous, including NULL URL/transaction.
-- No local field proves it was unsent; quarantine rather than replay its key.
-- Rows created after this migration have no imported operation and start normally.
INSERT INTO payment_operations(id,payment_id,kind,operation_key,state,sent_at,last_error)
SELECT uuid_generate_v4(),p.id,'authorize',p.id::text||':authorize',
       CASE WHEN p.status<>'PENDING' AND l.payment_id IS NOT NULL THEN 'SUCCEEDED' ELSE 'UNKNOWN' END,
       p.updated_at,CASE WHEN p.status='PENDING' THEN 'legacy pending initiation is uncertain; do not replay'
                        WHEN l.payment_id IS NULL THEN 'legacy initiation needs reconciliation' END
FROM payments p LEFT JOIN payment_transaction_links l ON l.payment_id=p.id
WHERE p.method<>'ON_DELIVERY';

-- Early signed callbacks are quarantined until a response/operator verifies linkage.
CREATE TABLE payment_callback_inbox (
    id varchar(255) PRIMARY KEY,
    operation_id uuid NOT NULL REFERENCES payment_operations(id),
    payment_id uuid NOT NULL REFERENCES payments(id),
    transaction_id varchar(255) NOT NULL,
    status text NOT NULL CHECK(status IN ('CAPTURED','FAILED')),
    received_at timestamptz NOT NULL DEFAULT now(),
    resolved_at timestamptz
);
CREATE INDEX payment_callback_inbox_payment ON payment_callback_inbox(payment_id) WHERE resolved_at IS NULL;
