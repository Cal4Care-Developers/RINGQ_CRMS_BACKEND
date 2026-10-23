--[[ ==========================================================================
 ringq_blf_pickup.lua  --  BLF key pickup on desk phones

 A desk phone's BLF key dials *8<SIP user of the watched extension>. The global
 dial plan blf_sip_user_pickup runs:

   ringq_blf_pickup.lua <SIP user>
       picks up, in this order:
       1. the call last dialled to that extension (hash last_dial_ext, by extension
          number, then by SIP user), while it is still ringing it (bridge app);
       2. a call ringing that phone right now. Queue calls end up here: the queue
          rings its agents itself, so they never reach last_dial_ext. The queue
          caller (cc_member_session_uuid) is taken out of the queue and the queue
          stops ringing its agents. Any other ringing leg: its originator.
       A call that is already answered is never taken (intercept_unbridged_only).

   show <uuid> <uuid>   (sched_api, 2 s after a pickup)
       Phones register with the 20-character SIP user, and after a pickup the call
       server would show each side the other's SIP user. The pickup holds those
       display updates back; this sends each side the other's extension number and
       name (ringq_pickup_display) instead.
 ========================================================================== ]]

local api = freeswitch.API()

local function log(level, msg) freeswitch.consoleLog(level, "[ringq_blf_pickup] " .. msg .. "\n") end
local function trim(s) return (tostring(s or ""):gsub("^%s+", ""):gsub("%s+$", "")) end

local function api_val(cmd)
  local v = trim(api:executeString(cmd))
  if v == "_undef_" or v:find("^%-ERR") or v:find("^%-USAGE") then return "" end
  return v
end

local function getvar(uuid, name) return api_val("uuid_getvar " .. uuid .. " " .. name) end
local function alive(uuid) return uuid ~= "" and api_val("uuid_exists " .. uuid) == "true" end
local function is_sip_user(v) return #v == 20 and not v:find("[^0-9a-f]") end

local function split(s, sep)
  local out, pos = {}, 1
  while true do
    local i = s:find(sep, pos, true)
    if not i then out[#out + 1] = s:sub(pos); return out end
    out[#out + 1] = s:sub(pos, i - 1)
    pos = i + #sep
  end
end

local mode = trim(argv[1])

-- [desk-display] after a pickup: let display updates through again and send the right ones
if mode == "show" then
  for i = 2, 3 do
    local uuid = trim(argv[i])
    if alive(uuid) then
      api:executeString("uuid_setvar " .. uuid .. " ignore_display_updates")
      local d = getvar(uuid, "ringq_pickup_display")
      if d ~= "" then api:executeString("uuid_display " .. uuid .. " " .. d) end
    end
  end
  return
end

if not session or not session:ready() then return end

local sip_user = mode:lower()
if not is_sip_user(sip_user) then return end

local domain = session:getVariable("domain_name") or ""
local own = session:get_uuid()
local number = api_val("user_data " .. sip_user .. "@" .. domain .. " attr number-alias")

-- Extension number and name for a caller ID that is a SIP user; nil for anything else
-- (an outside number already shows as it should).
local function ext_display(name, num)
  if not is_sip_user(num) then return nil end
  local ext = api_val("user_data " .. num .. "@" .. domain .. " attr number-alias")
  if ext == "" then return nil end
  local dname = api_val("user_data " .. num .. "@" .. domain .. " var effective_caller_id_name")
  if dname == "" then dname = (name ~= "" and not is_sip_user(name)) and name or ext end
  return (dname:gsub("[|%c]", " ")), ext
end

local tried = {}

local function try(uuid, why)
  if uuid == "" or uuid == own or tried[uuid] or not alive(uuid) then return false end
  tried[uuid] = true

  -- what each screen should show: the other side's extension and name
  local cname, cnum = getvar(uuid, "caller_id_name"), getvar(uuid, "caller_id_number")
  local tname, tnum = ext_display(cname, cnum)
  local pname, pnum = ext_display(session:getVariable("caller_id_name") or "", session:getVariable("caller_id_number") or "")
  session:setVariable("initial_callee_id_name", tname or (cname ~= "" and cname or cnum))
  session:setVariable("initial_callee_id_number", tnum or cnum)
  if tnum then
    session:setVariable("ringq_pickup_display", tname .. "|" .. tnum)
    session:setVariable("ignore_display_updates", "true")
  end
  if pnum then
    api:executeString("uuid_setvar " .. uuid .. " ringq_pickup_display " .. pname .. "|" .. pnum)
    api:executeString("uuid_setvar " .. uuid .. " ignore_display_updates true")
  end

  session:setVariable("intercept_unbridged_only", "true")
  log("info", "*8" .. sip_user .. " (" .. (number ~= "" and number or "?") .. ") picks up " .. uuid .. " [" .. why .. "]")
  session:execute("intercept", uuid)

  if session:ready() then
    -- not taken (answered meanwhile, or gone): undo and try the next one
    session:execute("unset", "ignore_display_updates")
    session:execute("unset", "ringq_pickup_display")
    if pnum then api:executeString("uuid_setvar " .. uuid .. " ignore_display_updates") end
    return false
  end
  if tnum or pnum then
    api:executeString("sched_api +2 none luarun ringq_blf_pickup.lua show " .. own .. " " .. uuid)
  end
  return true
end

-- 1. the call last dialled to the extension, while it is still ringing it
for _, key in ipairs({ number, sip_user }) do
  if key ~= "" then
    local uuid = api_val("hash select/" .. domain .. "-last_dial_ext/" .. key)
    if uuid ~= "" and getvar(uuid, "current_application") == "bridge" and try(uuid, "dialled " .. key) then return end
  end
end

-- 2. a call ringing the phone now (queue calls, and anything else not in last_dial_ext)
local RINGING = { DOWN = true, DIALING = true, RINGING = true, EARLY = true, RING_WAIT = true }
local out, cols = api:executeString("show channels as delim |") or "", nil
local legs = {}
for line in out:gmatch("[^\n]+") do
  local f = split(line, "|")
  if not cols then
    cols = {}
    for i, c in ipairs(f) do cols[trim(c)] = i end
    if not cols.uuid or not cols.name or not cols.direction then break end
  elseif #f >= 5 then
    local user = (f[cols.name] or ""):match("^sofia/[^/]+/([^@]+)@")
    local st = trim(f[cols.callstate or 0]):upper()
    if trim(f[cols.direction]) == "outbound" and user and (user == sip_user or user == number) and RINGING[st] then
      legs[#legs + 1] = trim(f[cols.uuid])
    end
  end
end
for _, leg in ipairs(legs) do
  local member = getvar(leg, "cc_member_session_uuid")
  if member ~= "" then
    if try(member, "queue " .. getvar(leg, "cc_queue")) then return end
  elseif try(getvar(leg, "originator"), "ringing " .. leg) then
    return
  end
end

log("info", "*8" .. sip_user .. " (" .. (number ~= "" and number or "?") .. "): no ringing call to pick up")
session:hangup("NORMAL_CLEARING")
