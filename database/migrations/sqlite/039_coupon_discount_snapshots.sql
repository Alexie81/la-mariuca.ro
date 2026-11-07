ALTER TABLE coupon_redemptions ADD COLUMN merchandise_discount_bani INTEGER NOT NULL DEFAULT 0 CHECK (merchandise_discount_bani >= 0);
ALTER TABLE coupon_redemptions ADD COLUMN shipping_discount_bani INTEGER NOT NULL DEFAULT 0 CHECK (shipping_discount_bani >= 0);

-- Attribute only the components not already owned by a personal or automatic offer.
-- Current coupon scope/value is deliberately ignored: an offer may have been edited.
UPDATE coupon_redemptions
SET merchandise_discount_bani=COALESCE((SELECT CASE WHEN customer_order.promotion_id IS NULL AND customer_order.customer_discount_id IS NULL THEN customer_order.discount_bani ELSE 0 END FROM orders customer_order WHERE customer_order.id=coupon_redemptions.order_id),0),
    shipping_discount_bani=COALESCE((SELECT CASE WHEN customer_order.shipping_promotion_id IS NULL AND customer_order.customer_shipping_discount_id IS NULL THEN customer_order.shipping_discount_bani ELSE 0 END FROM orders customer_order WHERE customer_order.id=coupon_redemptions.order_id),0);
