-- Crashes each time it runs. At Earth it waits a minute first (an action
-- that works ends a run of crashes); elsewhere it crashes at once.
function launched()
  print("crasher-run " .. tostring(carried))
  report()
  carried = "kept"
  if location() == earth then wait(1) end
  error("smoke-crash")
end
