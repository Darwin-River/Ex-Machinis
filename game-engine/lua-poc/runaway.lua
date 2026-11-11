-- A drone that never stops (#380): an endless loop, and a table that grows
-- without end. The slice limit and the memory cap must hold it.
grow = {}
function launched()
  local i = 0
  while true do
    i = i + 1
    if i % 2 == 0 then grow[#grow + 1] = string.rep("x", 100) end
  end
end
