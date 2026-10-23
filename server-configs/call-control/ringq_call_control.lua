--[[ ==========================================================================
 ringq_call_control.lua  --  RingQ "Call Control" (per call-profile forwarding)

 Applies the extension's Call Control settings (q_call_behaviors, 5 slots =
 Available, Away, Busy, Custom 1, Custom 2; Internal = no suffix, External = "2")
 for the profile the user currently has selected (Redis "<ext>+<domain>", set by
 the dialer's changeProfile) -- so a status change takes effect on the next call.

   resolve <uuid>  (API, run inline from dialplan "ringq-call-control", order 36)
       sets forward_all_*, forward_all_*2, call_timeout, rq_* variables
   screen          (app, local_extension before the first bridge) -- call screening
   failover        (app, local_extension after the bridges) -- Call Relay,
                   On Busy and No Answer Action. Returns silently when nothing
                   applies, so the normal voicemail step still runs.
 ========================================================================== ]]

require "resources.functions.config"
local Database = require "resources.functions.database"
local api = freeswitch.API()

local function log(level, msg) freeswitch.consoleLog(level, "[ringq_call_control] " .. msg .. "\n") end
local function split(s, sep)
  local t = {}
  s = tostring(s or "")
  if s == "" then return t end
  for part in (s .. sep):gmatch("(.-)" .. sep:gsub("%p", "%%%0")) do t[#t + 1] = part end
  return t
end
local function trim(s) return (tostring(s or ""):gsub("^%s+", ""):gsub("%s+$", "")) end
local function slot(csv, idx, def)
  local v = split(csv, ",")[idx + 1]
  if v == nil then return def end
  return trim(v)
end
local function bool(v) v = tostring(v or ""):lower() return v == "true" or v == "t" or v == "1" end
local function safe(v) return (tostring(v or ""):gsub("[^%w%*#%+@%._%-|]", "")) end

-- current profile index 0..4 (default Available)
local function profile_index(ext, domain)
  local idx = 0
  local key = safe(ext) .. "+" .. safe(domain)
  local p = io.popen("redis-cli GET '" .. key .. "' 2>/dev/null")
  if p then
    local out = trim(p:read("*a") or "")
    p:close()
    local n = tonumber(out)
    if n and n >= 0 and n <= 4 then idx = math.floor(n) end
  end
  return idx
end

-- Call Relay destinations for one slot: 15 values (3 per slot) or 5 values "a|b|c"
local function relay_dests(csv, idx)
  local all = split(csv, ",")
  local out = {}
  if #all >= 15 then
    for i = 1, 3 do out[#out + 1] = trim(all[idx * 3 + i]) end
  else
    for _, d in ipairs(split(all[idx + 1] or "", "|")) do out[#out + 1] = trim(d) end
  end
  return out
end

-- "" / "*99" / "*99<own ext>" mean the extension's own voicemail (normal path)
local function is_own_vm(dest, ext)
  return dest == nil or dest == "" or dest == "*99" or dest == ("*99" .. ext)
end

local mode = argv[1]

------------------------------------------------------------------ resolve
if mode == "resolve" then
  local uuid = argv[2]
  if not uuid or uuid == "" then stream:write("") return end
  local function gv(n) local v = api:executeString("uuid_getvar " .. uuid .. " " .. n); if v == "_undef_" or v:find("^%-ERR") then return "" end return trim(v) end
  local ext = gv("destination_number")
  local domain = gv("domain_name")
  local ext_uuid = gv("extension_uuid")
  if ext_uuid == "" then stream:write("") return end
  local dbh = Database.new('system')
  local row = dbh:first_row("SELECT * FROM q_call_behaviors WHERE extension_uuid = :u LIMIT 1", { u = ext_uuid })
  dbh:release()
  if not row then stream:write("no-behaviors") return end
  local idx = profile_index(ext, domain)
  local external = gv("call_direction") ~= "local"
  local s = external and "2" or ""
  local vars = {}
  local function set(k, v) vars[#vars + 1] = k .. "=" .. safe(v) end
  local raw = {} -- values with [] , = / that uuid_setvar_multi can't carry

  local na_timeout = tonumber(slot(row["forward_no_answer_timeout" .. s], idx, "")) or 30
  if na_timeout < 5 then na_timeout = 5 end
  if na_timeout > 300 then na_timeout = 300 end

  -- Always (call-forward-all = internal, call-forward-all2 = external)
  local function always(sfx)
    local en = bool(slot(row["forward_all_enabled" .. sfx], idx, "false"))
    local dest = slot(row["forward_all_destination" .. sfx], idx, "")
    if is_own_vm(dest, ext) then dest = "*99" .. ext end
    set("forward_all_enabled" .. sfx, en and "true" or "false")
    set("forward_all_destination" .. sfx, dest)
  end
  always("")
  always("2")

  set("call_timeout", na_timeout)
  set("follow_me_enabled", "false") -- relay handled by failover below
  set("rq_cc_profile", idx)
  set("rq_cc_dir", external and "external" or "internal")
  set("rq_cc_timeout", na_timeout)
  set("rq_busy_enabled", bool(slot(row["forward_busy_enabled" .. s], idx, "false")) and "true" or "false")
  set("rq_busy_dest", slot(row["forward_busy_destination" .. s], idx, ""))
  set("rq_na_enabled", bool(slot(row["forward_no_answer_enabled" .. s], idx, "false")) and "true" or "false")
  set("rq_na_dest", slot(row["forward_no_answer_destination" .. s], idx, ""))
  local relay_en = bool(slot(row["follow_me_enabled" .. s], idx, "false"))
  local relays = {}
  if relay_en then
    for _, d in ipairs(relay_dests(row["follow_me_destinations" .. s], idx)) do
      if not is_own_vm(d, ext) and d ~= ext then relays[#relays + 1] = d end
    end
  end
  set("rq_relay", table.concat(relays, "|"))
  set("rq_screen", bool(slot(row["call_screening" .. s], idx, "false")) and "true" or "false")
  -- Parallel Ringing: ring the extension's mobile number at the same time
  local par = bool(slot(row["simultaneous_destination_enabled" .. s], idx, "false"))
  local mobile = tostring(row.mobile_number or ""):gsub("[^%d%+]", "")
  if par and #mobile >= 5 then
    raw.rq_parallel = ",[leg_timeout=" .. na_timeout .. ",rq_cc_leg=parallel]loopback/" .. mobile .. "/" .. safe(domain)
  else
    set("rq_parallel", "")
  end

  -- uuid_setvar_multi separates with ";" -- values were sanitised above
  api:executeString("uuid_setvar_multi " .. uuid .. " " .. table.concat(vars, ";"))
  for k, v in pairs(raw) do api:executeString("uuid_setvar " .. uuid .. " " .. k .. " " .. v) end
  log("info", ext .. "@" .. domain .. " profile=" .. idx .. " dir=" .. (external and "external" or "internal") .. " " .. table.concat(vars, " "))
  stream:write("ok")
  return
end

------------------------------------------------------------------ app modes
if not session or not session:ready() then return end
local function sv(n) return session:getVariable(n) or "" end
local ext = sv("dialed_extension") ~= "" and sv("dialed_extension") or sv("destination_number")
local domain = sv("domain_name")

-- [on-busy] is the extension already talking on another call? Its channels carry its SIP user id
-- (user "id", e.g. 3732c094...) or the extension number as presence / channel user.
local function ext_on_call(ext, domain, own_uuid)
  local ids = { [ext] = true }
  local uid = trim(api:executeString("user_data " .. ext .. "@" .. domain .. " attr id"))
  if uid ~= "" and not uid:find("^%-ERR") then ids[uid] = true end
  local out = api:executeString("show channels as delim |") or ""
  local cols, n = nil, 0
  for line in out:gmatch("[^\n]+") do
    local f = split(line, "|")
    if not cols then
      cols = {}
      for i, c in ipairs(f) do cols[trim(c)] = i end
      if not cols.uuid then return false end
    elseif #f >= 5 then
      local uuid = f[cols.uuid] or ""
      local st = (f[cols.callstate or 0] or ""):upper()
      if uuid ~= own_uuid and (st == "ACTIVE" or st == "HELD") then
        local pres = (f[cols.presence_id or 0] or ""):match("^([^@]+)@" .. domain:gsub("%p", "%%%0") .. "$")
        local user = (f[cols.name or 0] or ""):match("^sofia/[^/]+/([^@]+)@")
        if (pres and ids[pres]) or (user and ids[user]) then n = n + 1 end
      end
    end
  end
  return n > 0
end

if mode == "screen" then
  -- [on-busy] On Busy is switched on and the extension is already on a call: don't ring it
  -- (apps and phones ring a second call as call waiting, so "busy" was never reported).
  if sv("rq_busy_enabled") == "true" and sv("rq_busy_checked") ~= ext then
    session:setVariable("rq_busy_checked", ext)
    if ext_on_call(ext, domain, sv("uuid")) then
      local dest = trim(sv("rq_busy_dest"))
      if dest == "hangup" then
        log("info", ext .. " on a call -> on-busy hangup")
        session:hangup("USER_BUSY")
        return
      end
      if is_own_vm(dest, ext) then dest = "*99" .. ext end
      log("info", ext .. " on a call -> on-busy " .. dest)
      session:setVariable("rq_failover_done", ext) -- the failover step must not act again
      if sv("rq_cc_dir") == "external" then
        session:setVariable("outbound_caller_id_name", sv("caller_id_name"))
        session:setVariable("outbound_caller_id_number", sv("caller_id_number"))
      end
      session:execute("transfer", dest .. " XML " .. domain)
      return
    end
  end
  if sv("rq_screen") ~= "true" or sv("rq_screened") == ext then return end
  session:setVariable("rq_screened", ext)
  local file = "/tmp/" .. safe(domain) .. "-screen-" .. sv("uuid") .. ".wav"
  session:answer()
  session:sleep(500)
  session:execute("playback", "phrase:voicemail_record_name")
  session:execute("playback", "tone_stream://%(500,0,640)")
  session:setVariable("playback_terminators", "#*0123456789")
  session:execute("record", file .. " 5 200 2")
  session:setVariable("group_confirm_key", "1")
  session:setVariable("fail_on_single_reject", "true")
  session:setVariable("group_confirm_file", "phrase:screen_confirm:" .. file)
  session:setVariable("group_confirm_cancel_timeout", "1")
  return
end

if mode == "failover" then
  local disp = sv("originate_disposition")
  if disp == "SUCCESS" or disp == "ANSWER" or sv("rq_failover_done") == ext then return end
  session:setVariable("rq_failover_done", ext)
  local external = sv("rq_cc_dir") == "external"
  local busy = (disp == "USER_BUSY" or disp == "CALL_REJECTED")

  local function forward(dest, why)
    if dest == "hangup" then
      log("info", ext .. " " .. why .. " -> hangup")
      session:hangup(busy and "USER_BUSY" or "NO_ANSWER")
      return true
    end
    if is_own_vm(dest, ext) then return false end -- own voicemail = normal path
    log("info", ext .. " " .. why .. " (" .. disp .. ") -> " .. dest)
    if external then
      session:setVariable("outbound_caller_id_name", sv("caller_id_name"))
      session:setVariable("outbound_caller_id_number", sv("caller_id_number"))
    end
    session:execute("transfer", dest .. " XML " .. domain)
    return true
  end

  if busy then
    if sv("rq_busy_enabled") == "true" then forward(sv("rq_busy_dest"), "on-busy") end
    return
  end

  -- not answered / not reachable: Call Relay first, then No Answer Action
  local relay = sv("rq_relay")
  if relay ~= "" then
    local t = sv("rq_cc_timeout") ~= "" and sv("rq_cc_timeout") or "30"
    for _, d in ipairs(split(relay, "|")) do
      if not session:ready() then return end
      log("info", ext .. " call relay -> " .. d)
      -- an extension rings only its phones (not its own forwarding/voicemail);
      -- anything else (mobile/external number, IVR, queue...) goes through the dialplan
      local is_ext = trim(api:executeString("user_exists id " .. d .. " " .. domain)) == "true"
      local target = is_ext and ("user/" .. d .. "@" .. domain) or ("loopback/" .. d .. "/" .. domain)
      session:execute("bridge", "[leg_timeout=" .. t .. ",rq_cc_leg=relay]" .. target)
      local r = session:getVariable("originate_disposition") or ""
      if r == "SUCCESS" or r == "ANSWER" or not session:ready() then return end
    end
  end
  if session:ready() and sv("rq_na_enabled") == "true" then forward(sv("rq_na_dest"), "no-answer") end
  return
end
