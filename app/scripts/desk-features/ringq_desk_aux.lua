--[[ ==========================================================================
 ringq_desk_aux.lua  --  Agent Status / Change Status keys on desk phones

 Dial plan ringq_desk_aux: *26<aux code id> runs this with the id. It asks the
 RingQ web app on this server (127.0.0.1, IndexController deskAux) to set the
 caller's aux code exactly as the dialer's Agent Status does, then plays a
 short confirmation tone (or an error tone).
 ========================================================================== ]]

if not session or not session:ready() then return end

local function log(level, msg) freeswitch.consoleLog(level, "[ringq_desk_aux] " .. msg .. "\n") end
local function safe(v) return (tostring(v or ""):gsub("[^%w%._%-]", "")) end

local aux = tonumber(argv[1] or "")
local domain = safe(session:getVariable("domain_name"))
local user = safe(session:getVariable("sip_from_user") or session:getVariable("caller_id_number"))
session:answer()
if not aux or domain == "" or user == "" then
  session:execute("playback", "tone_stream://%(250,250,480,620);loops=4")
  return
end

-- the tenant's own https site (as the phones fetch their config), answered on this server
local url = "https://" .. domain .. ":8443/provision/000000000000/aux?rq_desk_aux=1&domain=" .. domain .. "&user=" .. user .. "&aux=" .. math.floor(aux)
local p = io.popen("curl -sk -m 8 --resolve '" .. domain .. ":8443:127.0.0.1' -o /dev/null -w '%{http_code}' '" .. url .. "' 2>/dev/null")
local code = p and (p:read("*a") or "") or ""
if p then p:close() end
log("INFO", user .. "@" .. domain .. " aux " .. aux .. " -> HTTP " .. code)

if code == "200" then
  session:execute("playback", "tone_stream://%(150,100,880);%(150,100,1320)")
else
  session:execute("playback", "tone_stream://%(250,250,480,620);loops=4")
end
session:hangup()
