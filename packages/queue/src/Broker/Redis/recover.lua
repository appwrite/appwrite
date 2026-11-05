-- KEYS: reservations, ready, then declared reservation keys (required by Dragonfly).
local now = redis.call('TIME')
for i = 2, #KEYS do
    local kind = redis.call('TYPE', KEYS[i]).ok
    if kind ~= 'none' and kind ~= 'list' then return redis.error_reply('WRONGTYPE queue recovery') end
end
local moved = 0
for i = 3, #KEYS do
    local key = KEYS[i]
    local deadline = redis.call('ZSCORE', KEYS[1], key)
    if deadline and tonumber(deadline) <= tonumber(now[1]) then
        while moved < tonumber(ARGV[1]) do
            local raw = redis.call('LPOP', key)
            if not raw then break end
            redis.call('RPUSH', KEYS[2], raw)
            moved = moved + 1
        end
        if redis.call('LLEN', key) == 0 then redis.call('ZREM', KEYS[1], key) end
        if moved >= tonumber(ARGV[1]) then break end
    end
end
return moved
