UPDATE categories
SET position = CASE slug
        WHEN 'nou-nascuti' THEN 1
        WHEN 'fetite' THEN 2
        WHEN 'baieti' THEN 3
        WHEN 'costume-traditionale' THEN 4
        WHEN 'botez' THEN 5
        WHEN 'incaltaminte' THEN 6
        WHEN 'accesorii' THEN 7
        ELSE position
    END
WHERE parent_id IS NULL
  AND position = 0
  AND slug IN ('nou-nascuti','fetite','baieti','costume-traditionale','botez','incaltaminte','accesorii');
