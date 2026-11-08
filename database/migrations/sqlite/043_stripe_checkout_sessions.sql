ALTER TABLE payments ADD COLUMN checkout_session_id TEXT NULL;
CREATE UNIQUE INDEX payments_checkout_session_id_unique ON payments(checkout_session_id);
UPDATE payments SET checkout_session_id=provider_payment_id,provider_payment_id=NULL
WHERE provider='stripe' AND provider_payment_id LIKE 'cs\_%' ESCAPE '\';
