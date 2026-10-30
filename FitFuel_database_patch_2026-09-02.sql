-- FitFuel post-upgrade schema patch (safe to run after the prior upgrade)
-- Backup database first.
ALTER TABLE user_profiles
  ADD COLUMN IF NOT EXISTS daily_fiber_goal DECIMAL(6,2) DEFAULT NULL AFTER daily_fat_goal;

-- Imported recipe source photo. Existing recipes remain unchanged.
ALTER TABLE recipes
  ADD COLUMN IF NOT EXISTS image_url VARCHAR(2048) DEFAULT NULL AFTER source_url;
