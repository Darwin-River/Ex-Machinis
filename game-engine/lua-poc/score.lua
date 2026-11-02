-- The benchmark (#380 Lua proof of concept): the same work as score.fth
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
  best, bscore = b, bs
end
function run() for i = 1, 1000 do choose() end end
