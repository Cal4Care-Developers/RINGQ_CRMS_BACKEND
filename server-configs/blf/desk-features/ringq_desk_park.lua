--[[ ==========================================================================
 ringq_desk_park.lua  --  Shared Parking from desk phones

 The RingQ apps park calls in 5 shared slots: Redis list "<domain>:parked"
 (index 0-4, a slot holds the parked call's uuid or ""). This script lets a
 desk phone use the same slots, through the dial plan ringq_desk_park:

   *591 .. *595, called by a call the phone transferred there  -> park it in that slot
                                                                  (next free slot if taken)
   *591 .. *595, dialled from a phone with no call             -> pick up the call parked there

   lamps <domain>   (API: luarun ringq_desk_park.lua lamps <domain>)
       sends every slot's state to the desk phones whose BLF key watches *59N,
       so the lamp follows parks and pickups made in the apps too.

 After each change it fires CUSTOM ringq::park, so the RingQ server sends the
 apps the new slot list (parkStatus).
 ========================================================================== ]]

local api = freeswitch.API()
local SLOTS = 5

local function log(level, msg) freeswitch.consoleLog(level, "[ringq_desk_park] " .. msg .. "\n") end
local function trim(s) return (tostring(s or ""):gsub("^%s+", ""):gsub("%s+$", "")) end
local function safe(v) return (tostring(v or ""):gsub("[^%w%._%-]", "")) end

local function redis(cmd)
  local p = io.popen("redis-cli " .. cmd .. " 2>/dev/null")
  if not p then return "" end
  local out = trim(p:read("*a") or "")
  p:close()
  return out
end

local function list_key(domain) return "'" .. safe(domain) .. ":parked'" end

local function ensure_list(domain)
  if redis("EXISTS " .. list_key(domain)) ~= "1" then
    redis("RPUSH " .. list_key(domain) .. " '' '' '' '' ''")
  end
end

local function get_slot(domain, n) return redis("LINDEX " .. list_key(domain) .. " " .. (n - 1)) end
local function set_slot(domain, n, uuid) redis("LSET " .. list_key(domain) .. " " .. (n - 1) .. " '" .. safe(uuid) .. "'") end

local function alive(uuid)
  return uuid ~= nil and uuid ~= "" and trim(api:executeString("uuid_exists " .. uuid)) == "true"
end

-- BLF lamp for slot n: lit (confirmed) while a call is parked there, off otherwise
local function lamp(domain, n, uuid)
  local user = "*59" .. n
  local event = freeswitch.Event("PRESENCE_IN")
  event:addHeader("proto", "sip")
  event:addHeader("event_type", "presence")
  event:addHeader("alt_event_type", "dialog")
  event:addHeader("Presence-Call-Direction", "outbound")
  event:addHeader("from", user .. "@" .. domain)
  event:addHeader("login", user .. "@" .. domain)
  event:addHeader("unique-id", (uuid ~= nil and uuid ~= "") and uuid or ("ringq-park-" .. n))
  if uuid ~= nil and uuid ~= "" then
    event:addHeader("status", "Active (1 waiting)")
    event:addHeader("rpid", "busy")
    event:addHeader("answer-state", "confirmed")
  else
    event:addHeader("status", "Available")
    event:addHeader("rpid", "unknown")
    event:addHeader("answer-state", "terminated")
  end
  event:fire()
end

-- tell the RingQ server the slots changed (it sends the apps parkStatus)
local function changed(domain)
  local event = freeswitch.Event("CUSTOM", "ringq::park")
  event:addHeader("domain", domain)
  event:fire()
end

local function all_lamps(domain)
  ensure_list(domain)
  for n = 1, SLOTS do
    local uuid = get_slot(domain, n)
    if uuid ~= "" and not alive(uuid) then
      set_slot(domain, n, "")
      uuid = ""
    end
    lamp(domain, n, uuid)
  end
end

-- API mode: luarun ringq_desk_park.lua lamps <domain>
if argv[1] == "lamps" then
  if argv[2] and argv[2] ~= "" then all_lamps(argv[2]) end
  return
end

if not session or not session:ready() then return end

local n = tonumber(argv[1] or "")
local domain = session:getVariable("domain_name") or ""
if not n or n < 1 or n > SLOTS or domain == "" then
  log("WARNING", "bad slot or domain: " .. tostring(argv[1]) .. " " .. domain)
  return
end
ensure_list(domain)
local me = session:getVariable("uuid")
local current = get_slot(domain, n)
-- a call that was talking to someone and got transferred here (blind transfer / park key during a call),
-- as opposed to a phone dialling the slot with no call
local history = session:getVariable("transfer_history") or ""
local transferred = (session:getVariable("last_bridge_to") or "") ~= ""
  or (history:find("bl_xfer", 1, true) ~= nil and history:find("*59", 1, true) ~= nil)

if not transferred then
  -- a phone with no call pressed the key: pick up what is parked there
  if alive(current) and current ~= me then
    log("INFO", "slot " .. n .. " picked up by " .. tostring(session:getVariable("caller_id_number")))
    set_slot(domain, n, "")
    api:executeString("uuid_setvar " .. current .. " ringq_park_taken true")
    lamp(domain, n, "")
    changed(domain)
    session:execute("intercept", current)
  else
    if current ~= "" then set_slot(domain, n, "") lamp(domain, n, "") changed(domain) end
    session:execute("playback", "tone_stream://%(500,500,480,620);loops=3")
    session:hangup()
  end
  return
end

-- a call was transferred here: park it (next free slot when this one is taken)
if alive(current) and current ~= me then
  local free = nil
  for i = 1, SLOTS do
    local u = get_slot(domain, i)
    if not alive(u) then free = i break end
  end
  if not free then
    log("WARNING", "all parking slots are taken")
    session:execute("playback", "tone_stream://%(250,250,480,620);loops=8")
    session:hangup("NORMAL_CIRCUIT_CONGESTION")
    return
  end
  n = free
end

session:answer()
session:setVariable("ringq_park_slot", tostring(n))
set_slot(domain, n, me)
lamp(domain, n, me)
changed(domain)
log("INFO", "parked " .. me .. " in slot " .. n)

local moh = session:getVariable("hold_music")
if not moh or moh == "" then moh = "local_stream://" .. domain .. "/moh" end
session:execute("playback", "misc/parkslot.wav")
while session:ready() do
  session:execute("playback", moh)
  freeswitch.msleep(300)
  -- picked up (desk phone or app) or moved: the slot no longer holds this call
  if session:getVariable("ringq_park_taken") == "true" or (session:getVariable("park_pickup") or "") ~= ""
    or get_slot(domain, n) ~= me then return end
end

-- the parked caller hung up
if get_slot(domain, n) == me then
  set_slot(domain, n, "")
  lamp(domain, n, "")
  changed(domain)
end
