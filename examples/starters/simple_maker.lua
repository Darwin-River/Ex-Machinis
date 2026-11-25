-- Maker: buys parts from the House, makes something, and sells it.
-- Choose a product in the table below, or change these settings.
product = alloys_a1  -- TRY FIRST: what it makes (the table lists more)
buy_at = moon        -- where it buys what goes into it
sell_at = moon       -- where it sells what it made

function launched()
  equip(factory_kit)             -- buys a Factory Kit at Earth
  local parts = recipe(product).uses
  while true do
    go(buy_at)
    for part, n in pairs(parts) do
      buy_here(n, part)          -- what goes into one
    end
    make(product)
    go(sell_at)
    sell_here(units(product), product)
    if units(fuel) < 20 then
      buy_here(20, fuel)         -- fuel for the next round
    end
  end
end
