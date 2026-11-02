-- A drone that thinks hard (#380 Lua proof of concept): 300 rounds of
-- scoring 8 products (about 112,000 instructions) between waits, so its
-- slices run out and it is preempted, and pays fuel for thinking
prices = {712, 734, 757, 690, 866, 600, 820, 640}
costs = {22, 9, 14}
needs = {{2,3,3}, {2,3,3}, {8,0,0}, {4,2,1}, {6,0,2}, {3,3,3}, {5,1,1}, {2,2,2}}
function cost(p)
  local c = 0
  for k = 1, 3 do c = c + needs[p][k] * costs[k] end
  return c
end
function score(p) return prices[p] * 95 // 100 - cost(p) end
function choose()
  local bs, b = -32000, 0
  for p = 1, 8 do
    local s = score(p)
    if s > bs then bs, b = s, p end
  end
  return b
end
function launched()
  while true do
    for i = 1, 300 do best = choose() end
    wait(60)
  end
end
