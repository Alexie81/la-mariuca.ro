CREATE TABLE newsletter_subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    email TEXT NOT NULL UNIQUE,
    name TEXT NULL,
    status TEXT NOT NULL DEFAULT 'subscribed' CHECK (status IN ('subscribed','unsubscribed','bounced','complained')),
    subscribe_source TEXT NOT NULL CHECK (subscribe_source IN ('footer_newsletter','contact_form','checkout','admin_import','unknown')),
    unsubscribe_source TEXT NULL CHECK (unsubscribe_source IS NULL OR unsubscribe_source IN ('email_link','contact_request','admin','bounce','complaint','unknown')),
    subscribed_at TEXT NOT NULL,
    unsubscribed_at TEXT NULL,
    last_notified_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_newsletter_subscribers_status_date ON newsletter_subscribers(status, subscribed_at);
CREATE INDEX idx_newsletter_subscribers_source ON newsletter_subscribers(subscribe_source);
CREATE INDEX idx_newsletter_subscribers_user ON newsletter_subscribers(user_id);

CREATE TABLE newsletter_subscription_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id INTEGER NOT NULL REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL CHECK (event_type IN ('subscribe','resubscribe','unsubscribe','bounce','complaint')),
    source TEXT NOT NULL,
    details_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE INDEX idx_newsletter_events_subscriber_date ON newsletter_subscription_events(subscriber_id, created_at);

CREATE TABLE newsletter_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject TEXT NOT NULL,
    preview_text TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','scheduled','sent','cancelled')),
    sent_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE newsletter_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id INTEGER NOT NULL REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
    message_id INTEGER NOT NULL REFERENCES newsletter_messages(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','delivered','opened','clicked','bounced','failed')),
    sent_at TEXT NULL,
    delivered_at TEXT NULL,
    opened_at TEXT NULL,
    clicked_at TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (subscriber_id, message_id)
);
CREATE INDEX idx_newsletter_deliveries_subscriber_date ON newsletter_deliveries(subscriber_id, sent_at);
CREATE INDEX idx_newsletter_deliveries_message_status ON newsletter_deliveries(message_id, status);
