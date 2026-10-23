--[[
 ringq_att_voicemail.lua  --  RingQ attended transfer: agent reaches the target's voicemail

 Used by node/server.js [att-vm] when an attended-transfer consult is not answered and the
 target has voicemail. It runs on the AGENT's consult leg: the agent hears the target's
 voicemail (normal FusionPBX app/voicemail/index.lua) and can leave a message, while the
 customer stays parked on hold music. When the voicemail is finished -- saved, or ended
 without saving -- the agent is NOT hung up: it is bridged back to the customer.

 Channel variables (set by server.js before the transfer):
   voicemail_action=save, voicemail_id=<target>, voicemail_profile=default, send_to_voicemail=true
   rq_att_agent_leg = uuid of the leg to bridge back to (the customer on hold)
   rq_att_agent_ext = optional: extension to transfer to when that leg is gone

 How: voicemail ends a call with session:hangup(). This script gives the voicemail app a
 stand-in "session" whose hangup() only marks the voicemail as finished (after it,
 ready() is false and media calls do nothing, exactly as after a real hangup), so the
 voicemail code completes normally (message saved / discarded, notifications sent) and
 the real call stays up. If the agent really hangs up, nothing special happens.

 Copy to /usr/share/freeswitch/scripts/
--]]

require "resources.functions.config";

local real = session
if real == nil or not real:ready() then return end

local finished = false
local passthrough = { getVariable = true, setVariable = true, get_uuid = true, getState = true }
local proxy = setmetatable({}, {
	__index = function(_, k)
		if k == "hangup" then
			return function()
				if not finished then
					finished = true
					freeswitch.consoleLog("info", "[att-vm] voicemail finished, back to the held party\n")
				end
			end
		end
		if finished and k == "ready" then
			return function() return false end
		end
		local v = real[k]
		if type(v) ~= "function" then return v end
		if finished and not passthrough[k] then
			return function() return "" end -- media/prompt calls after the voicemail ended: no-op
		end
		return function(_, ...) return v(real, ...) end
	end
})

session = proxy
local ok, err = pcall(function()
	argv = { [0] = "app.lua", "voicemail" }
	loadfile(scripts_dir .. "/app/voicemail/index.lua")(argv)
end)
session = real
if not ok then
	freeswitch.consoleLog("err", "[att-vm] voicemail error: " .. tostring(err) .. "\n")
end

if not real:ready() then return end -- the customer hung up

local function trim(s) return (tostring(s or ""):gsub("^%s+", ""):gsub("%s+$", "")) end
local api = freeswitch.API()
local agent_leg = trim(real:getVariable("rq_att_agent_leg"))
local agent_ext = trim(real:getVariable("rq_att_agent_ext"))
local domain = trim(real:getVariable("domain_name"))

-- already bridged again (Retrieve pressed during the voicemail): nothing to do
if string.find(api:executeString("show bridged_calls"), real:get_uuid(), 1, true) then return end
-- server.js also clears rq_att_agent_leg when the agent retrieved the call
if agent_leg ~= "" and trim(api:executeString("uuid_exists " .. agent_leg)) == "true" then
	local r = trim(api:executeString("uuid_bridge " .. agent_leg .. " " .. real:get_uuid()))
	freeswitch.consoleLog("info", "[att-vm] bridged back to " .. agent_leg .. ": " .. r .. "\n")
	if r:sub(1, 3) == "+OK" then return end
end
if agent_ext ~= "" and domain ~= "" then
	freeswitch.consoleLog("info", "[att-vm] held party gone, transferring to " .. agent_ext .. "\n")
	real:execute("transfer", agent_ext .. " XML " .. domain)
end
