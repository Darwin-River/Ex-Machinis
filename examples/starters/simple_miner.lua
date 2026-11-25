-- Miner: digs ore, refines it, and sells what it makes.
-- Choose a route in the table below, or change these settings.
ore = wolfite        -- what it digs
mine_at = moon       -- where it digs
sell_at = earth      -- TRY FIRST: where it sells (the Moon may pay more)
digs = 4             -- hours of digging a trip
goods = {metals_a1, metals_a2, inorganics_a2}

function launched()
  equip(mining_kit)              -- buys a Mining Kit at Earth
  while true do
    go(mine_at)
    for dig = 1, digs do
      mine(ore)                  -- an hour's digging
    end
    refine(ore)                  -- the ore into goods
    go(sell_at)
    for _, good in ipairs(goods) do
      sell_here(units(good), good)
    end
    buy_here(20, fuel)           -- fuel for the next trip
  end
end
