--[[ ==========================================================================
 ringq_number_block.lua  --  centralised number blocking

 Global dial plan ringq_number_block (order 38, continue) runs this once per
 call leg, before the call reaches an extension, IVR, queue or ring group.
 The list is the company's own (q_number_blocks, Global Settings > Security >
 Number blocking):

   inbound call  (call_direction inbound): caller ID matched against entries
                 for inbound / both -> rejected (603, CALL_REJECTED)
   outbound call (an extension dialling a number that is not an extension):
                 dialled number matched against entries for outbound / both
                 -> short error tone, then OUTGOING_CALL_BARRED

 Matching (must stay the same as app/library/NumberBlock.php):
   exact  : digits with leading zeros and "+" removed are equal, or the shorter
            (7+ digits) ends the longer one ("+65 9123 4567" = "91234567")
   prefix : the number's digits start with the prefix's digits, or the same
            without leading zeros
 Any error here lets the call through: a broken list must never stop calls.
 ========================================================================== ]]

if not session or not session:ready() then return end
session:setVariable("ringq_nb_done", "true")

local function log(level, msg) freeswitch.consoleLog(level, "[ringq_number_block] " .. msg .. "\n") end
local function digits(s) return (tostring(s or ""):gsub("%D", "")) end
local function canonical(s) return (digits(s):gsub("^0+", "")) end
local SUFFIX_MIN = 7

local function number_matches(number, entry)
  local c, e = canonical(number), canonical(entry)
  if c == "" or e == "" then return false end
  if c == e then return true end
  local short, long = c, e
  if #c > #e then short, long = e, c end
  return #short >= SUFFIX_MIN and long:sub(-#short) == short
end

local function prefix_matches(number, prefix)
  local p = digits(prefix)
  if p == "" then return false end
  if digits(number):sub(1, #p) == p then return true end
  local pc = canonical(prefix)
  return pc ~= "" and canonical(number):sub(1, #pc) == pc
end

local domain_uuid = session:getVariable("domain_uuid") or ""
if not domain_uuid:match("^[%x%-]+$") then return end

local direction, number
if (session:getVariable("call_direction") or "") == "inbound" then
  direction, number = "inbound", session:getVariable("caller_id_number") or ""
elseif session:getVariable("from_user_exists") == "true" and session:getVariable("user_exists") == "false" then
  direction, number = "outbound", session:getVariable("destination_number") or ""
else
  return
end
if digits(number) == "" then return end

local hit
local ok, err = pcall(function()
  require "resources.functions.config"
  local Database = require "resources.functions.database"
  local dbh = Database.new("system")
  dbh:query([[SELECT number_block_uuid, block_number, block_type FROM q_number_blocks
               WHERE domain_uuid = :d AND enabled = true AND block_direction IN (:dir, 'both')]],
    { d = domain_uuid, dir = direction },
    function(row)
      if hit then return end
      local m
      if row.block_type == "prefix" then m = prefix_matches(number, row.block_number)
      else m = number_matches(number, row.block_number) end
      if m then hit = row end
    end)
  if hit then
    dbh:query("UPDATE q_number_blocks SET block_count = block_count + 1, last_blocked_at = now() WHERE number_block_uuid = :id",
      { id = hit.number_block_uuid })
  end
  dbh:release()
end)
if not ok then
  log("ERR", "list lookup failed, call allowed: " .. tostring(err))
  return
end
if not hit then return end

log("NOTICE", direction .. " " .. number .. " blocked by " .. hit.block_type .. " " .. hit.block_number
  .. " (domain " .. domain_uuid .. ", " .. (session:getVariable("uuid") or "") .. ")")
session:setVariable("ringq_blocked", direction .. ":" .. hit.block_number)
if direction == "inbound" then
  session:hangup("CALL_REJECTED")
else
  session:execute("pre_answer")
  session:execute("playback", "tone_stream://%(250,250,480,620);loops=4")
  session:hangup("OUTGOING_CALL_BARRED")
end
