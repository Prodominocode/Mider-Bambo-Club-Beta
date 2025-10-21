-- Add indexes to improve performance
-- Index on subscribers.mobile since we query by mobile frequently
ALTER TABLE subscribers ADD INDEX idx_mobile (mobile);

-- Index on purchases.mobile since we query purchase history by mobile
ALTER TABLE purchases ADD INDEX idx_mobile (mobile);

-- Index on purchases.created_at to speed up the ORDER BY in purchase history queries
ALTER TABLE purchases ADD INDEX idx_created_at (created_at);

-- Compound index for common query patterns
ALTER TABLE purchases ADD INDEX idx_mobile_created (mobile, created_at);