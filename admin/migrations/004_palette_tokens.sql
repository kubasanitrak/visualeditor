-- 004: demo dark band uses palette tokens instead of raw colours, so the style panel
-- shows them as palette picks and they follow the global palette.

UPDATE items SET settings = '{"bg":"dark","color":"light"}'
WHERE id = 12 AND settings = '{"bg":"#161616","color":"#f6f5f2"}';
