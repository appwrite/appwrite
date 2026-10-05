-- KEYS: marker, ready. ARGV: envelope, ttl, pid.
local types = {'string', 'list'}
for i, kind in ipairs(types) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue coalesce') end
end
if not redis.call('SET', KEYS[1], ARGV[3], 'NX', 'EX', ARGV[2]) then return 0 end
redis.call('LPUSH', KEYS[2], ARGV[1])
return 1
