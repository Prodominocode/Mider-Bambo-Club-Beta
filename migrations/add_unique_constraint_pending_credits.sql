-- Migration: Add unique constraint to prevent duplicate pending credits for same purchase
-- Purpose: Prevent double credit issues by ensuring only one pending credit per purchase

-- Add unique constraint to prevent duplicate pending credits for same purchase
ALTER TABLE pending_credits 
ADD UNIQUE KEY unique_purchase_pending (purchase_id, subscriber_id) 
COMMENT 'Prevent duplicate pending credits for same purchase';

-- Add optimized index for transfer queries
CREATE INDEX idx_pending_transfer_ready ON pending_credits (transferred, created_at, active);