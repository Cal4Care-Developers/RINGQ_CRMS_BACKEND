-- ringq_ring_dial.lua - called by directory.lua where it writes an extension's dial-string.
--
-- Mode comes from the FusionPBX setting ringq / ring_push_mode (text): a default setting for all
-- tenants, overridable per domain. "off" (or unset) leaves the dial-string exactly as it was.
-- "event" or "wake" routes every ring of the extension through ringq_ring.lua (see there).
--
-- Returns: dial_string_to_use, original_dial_string_or_nil

local M = {}

local cache = {}       -- domain_uuid -> { mode, at }
local TTL = 60

local function mode_for(dbh, domain_name, domain_uuid)
	local key = domain_uuid or domain_name or ""
	local hit = cache[key]
	if hit and os.time() - hit.at < TTL then return hit.mode end
	local mode = "off"
	local ok, err = pcall(function()
		-- directory.lua may already have released its handle (load-balancing path): use our own
		local Database = require "resources.functions.database"
		local Settings = require "resources.functions.lazy_settings"
		local db = Database.new("system")
		local settings = Settings.new(db, domain_name, domain_uuid)
		mode = settings:get("ringq", "ring_push_mode", "text") or "off"
		db:release()
	end)
	if not ok then freeswitch.consoleLog("err", "[ringq_ring_dial] settings: " .. tostring(err) .. "\n") end
	cache[key] = { mode = mode, at = os.time() }
	return mode
end

function M.wrap(dial_string, ext, domain_name, dbh, domain_uuid)
	if not dial_string or dial_string == "" or not ext or ext == "" or not domain_name then
		return dial_string, nil
	end
	-- do-not-disturb and other error/ dial-strings stay as they are
	if dial_string:find("^error/") then return dial_string, nil end
	local mode = mode_for(dbh, domain_name, domain_uuid)
	if mode ~= "wake" then return dial_string, nil end
	-- mod_ringq's endpoint: pushes, waits for the app, then dials the original dial-string itself and
	-- hands back the real device leg, so the call looks like any other (no extra leg). Without the
	-- module loaded the extension would be unreachable, so check it.
	local api = freeswitch.API()
	if (api:executeString("module_exists mod_ringq") or ""):match("true") == nil then
		freeswitch.consoleLog("err", "[ringq_ring_dial] mod_ringq not loaded, keeping the normal dial-string\n")
		return dial_string, nil
	end
	return "ringq/" .. ext .. "@" .. domain_name, dial_string
end

return M
