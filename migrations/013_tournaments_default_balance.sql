-- All tournaments use the single default scale (levels live on balance_ratings).
UPDATE tournaments t
	INNER JOIN balances d ON d.is_default = 1
SET t.balance_id = d.id
WHERE t.balance_id IS NULL OR t.balance_id <> d.id;
