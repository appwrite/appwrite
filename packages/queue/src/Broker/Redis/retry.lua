-- KEYS: failed, then the list the job moves to, if any. ARGV: pid, entry to push there.
-- The job leaves the failed list only together with its push, and only while it
-- is still the oldest failure, so a sweep that stops between reading the job and
-- moving it leaves the job for the next sweep instead of losing it.
for i = 1, #KEYS do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= 'list' then return redis.error_reply('WRONGTYPE queue retry') end
end
if redis.call('LINDEX', KEYS[1], -1) ~= ARGV[1] then return 0 end
redis.call('RPOP', KEYS[1])
if KEYS[2] then redis.call('LPUSH', KEYS[2], ARGV[2]) end
return 1
