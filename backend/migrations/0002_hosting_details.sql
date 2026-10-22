-- 0002 — hosting details bookkeeping
-- REG.RU does not return panel credentials for every service type. Remember
-- that details were requested, so a sync does not retry them forever.

ALTER TABLE hostings ADD COLUMN details_synced_at TEXT NULL;
