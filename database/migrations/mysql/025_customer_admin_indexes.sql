CREATE INDEX idx_users_role_created ON users(role, created_at);
CREATE INDEX idx_orders_user_created ON orders(user_id, created_at);
