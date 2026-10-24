-- KEYS: ready, job. ARGV: replacement payload.
-- The replacement carries a fresh pid, so nothing references the old payload again.
redis.call('LPUSH', KEYS[1], ARGV[1])
redis.call('DEL', KEYS[2])
return 1
