require('./servermode')('chat'); // [server-mode] production/debug logging, see servermode.js
//mar 2 new
const { writeFile, unlinkSync, existsSync } = require("fs");
// const { writeFile } = require("fs").promises;
const { Server } = require("socket.io");
const { io } = require("socket.io-client");
const redis = require("redis");
require("dotenv").config({ path: "/etc/ringq/.env" });
const pg = require("pg");
const { Pool, Client } = pg;
const { exec, execFile } = require("child_process");
const fs = require("fs");
const path = require("path");
const { send } = require("process");
const admin = require("firebase-admin");
const serviceAccount2 = require("./secret.json");
const { strict } = require("assert");
const { platform } = require("os");
const crypto = require("crypto");
const { log } = require("console");
const util = require("util");
const execPromise = util.promisify(exec);
var cron = require("node-cron");

const SEARCH_SCHEMA_VERSION = "3";

// const geoip = require('geoip-lite');

admin.initializeApp({
  credential: admin.credential.cert(serviceAccount2),
});

const db2 = new Pool({
  user: process.env.PostgreSQL_DB_USER,
  host: process.env.PostgreSQL_DB_HOST,
  database: process.env.PostgreSQL_DB_NAME,
  password: process.env.PostgreSQL_DB_PASSWORD,
  port: Number(process.env.PostgreSQL_DB_PORT),
});
db2.connect((err, client, release) => {
  if (err) {
    console.error("Error acquiring client", err.stack);
    return;
  } else {
    console.log("Postgress 2 Database connected successfully!");
  }
});
const pool = new Pool({
  user: process.env.PostgreSQL_DB_USER,
  host: process.env.PostgreSQL_DB_HOST,
  database: process.env.PostgreSQL_DB_NAME,
  password: process.env.PostgreSQL_DB_PASSWORD,
  port: Number(process.env.PostgreSQL_DB_PORT),
});
pool.connect((err, client, release) => {
  if (err) {
    console.error("Error acquiring client", err.stack);
    return;
  } else {
    console.log("Postgress Database connected successfully!");
  }
});

let redisClient;

(async () => {
  try {
    redisClient = redis.createClient({
      socket: {
        path: "/var/run/redis/redis-server.sock",
      },
    });

    redisClient.on("error", (error) => console.error(`Redis Error: ${error}`));

    redisClient.on("connect", () => {
      console.log("Redis socket connected.");
    });

    redisClient.on("ready", () => {
      console.log("Redis client ready to use.");
    });

    await redisClient.connect();
  } catch (err) {
    console.error("Failed to connect to Redis:", err);
  }
})();

function chatKeyOfTenant(key, subdomain) {
  return (
    typeof key === "string" &&
    typeof subdomain === "string" &&
    subdomain !== "" &&
    key.length > subdomain.length + 1 &&
    key.endsWith(`-${subdomain}`)
  );
}

function chatInboxOfTenant(key, subdomain) {
  return (
    typeof key === "string" &&
    typeof subdomain === "string" &&
    subdomain !== "" &&
    (key.startsWith(`${subdomain}-`) || key.startsWith(`${subdomain}:`))
  );
}

const socketServerQueue = new Server({
  maxHttpBufferSize: 30e6,
});

const socketSignal = new io("ws://127.0.0.1:3000", {
  autoConnect: true,
  reconnectionDelayMax: 1000,
});
socketSignal.connect();

const chatHttpServer = require("http").createServer();
socketServerQueue.attach(chatHttpServer);
chatHttpServer.listen(5001, "127.0.0.1");

socketServerQueue.use(async (socket, next) => {
  if (socket.handshake.headers["user-agent"] == "node-XMLHttpRequest") {
    console.log("✅ Passed user-agent check.");
    next();
  } else {
    console.log("⚠️ Failed user-agent check, but still allowing...");
    next();
  }
});

socketServerQueue.on("connection", (socket) => {
  socket.on("disconnectChat", async (argument, callback) => {
    try {
      const { socketid, subdomain } = argument;
      if (!socketid) {
        return;
      }
      const requestedSocket = socketid;
      const user = (await redisClient.exists(requestedSocket))
        ? await redisClient.hGetAll(requestedSocket)
        : null;
      if (!user) {
        return;
      }
      const pipeline = redisClient.multi();
      if (user.socketId === socketid) {
        const userKey = user.userKey;
        const parts = userKey.split("-");
        const [userId, type, username, email] = parts;
        let messagePayload = {
          userKey: userKey,
          socketId: user.socketId,
          status: "offline",
          clientId: userId,
        };
        const userClient = (await redisClient.exists(userKey))
          ? await redisClient.hGetAll(userKey)
          : null;
        if (!userClient) {
          return;
        }
        if (userClient?.assigned_agent) {
          let assignedAgents = [];
          try {
            assignedAgents = JSON.parse(userClient.assigned_agent || "[]");
          } catch (e) {
            console.error("Invalid assigned_agent:", e);
          }
          let agentKeyArray = user.assigned_agent;
          let agentKey;
          if (typeof agentKeyArray === "string") {
            try {
              agentKeyArray = JSON.parse(agentKeyArray);
              console.log("parsed JSON");
            } catch (e) {
              console.error("Invalid JSON:", agentKeyArray);
              agentKeyArray = [];
            }
          }
          if (Array.isArray(agentKeyArray)) {
            agentKey = agentKeyArray[0];
          }
          const agentSocketId = await redisClient.hGet(agentKey, "socketID");
          const agentDetails = await redisClient.hGetAll(agentKey);
          if (agentDetails?.roomID !== "all_messages") {
            socketSignal.emit(
              "disconnectChatParameters",
              "queueStatus",
              messagePayload,
              agentSocketId,
            );
          }
        }
        const now = Date.now();
        pipeline.hSet(userKey, {
          status: "offline",
          lastSeen: now,
        });
      }
      await pipeline.exec();
      console.log(`Pipeline executed successfully for ${socketid}`);
      if (callback) callback({ status: "done" });
    } catch (err) {
      console.error("Error in disconnectChat:", err);
      if (callback) callback({ error: err.message });
    }
  });

  socket.on("communicateChat", async (argument, mysocket) => {
    if (
      argument === "undefined" ||
      argument === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }
    console.log("arg communicateChat", argument);

    const { arg, subdomain, extension, ip } = argument;
    let myextension = argument.extension;

    console.log("[GROUP CHAT][ROUTER] communicateChat envelope", {
      hasArgument: Boolean(argument),
      hasArg: Boolean(arg),
      type: arg?.type,
      subdomain,
      extension,
      mysocket,
    });

    async function getData(data, page = 0) {
      console.log("📩 Incoming request data:", data);
      const roomID = `${argument.subdomain}-external-${data.email}-${data.id}`;
      console.log("🏷️ Room ID:", roomID);

      try {
        // Count total messages in list
        const count = await redisClient.lLen(roomID);
        console.log("📊 Total messages in Redis:", count);

        if (!count) {
          console.log("⚠️ No messages found for this room.");
          socketSignal.emit("chatParameters", "first load", [], mysocket);
          const messages = await redisClient.sMembers(
            `${subdomain}-clientQuestions`,
          );
          socketSignal.emit(
            "chatParameters",
            "related questions",
            {
              result: "success",
              content: messages,
              bool: true,
            },
            mysocket,
          );

          return;
        }

        const limit = 200;
        const offset = page * limit;
        console.log(`🔎 Page: ${page}, Limit: ${limit}, Offset: ${offset}`);

        let logs = [];
        let view = false;

        if (count > limit + 1) {
          // remaining pages calculation
          const totalPages = Math.ceil(count / limit);
          const pages = totalPages - page;
          view = pages > 1; // same as PHP (if only 1 page left, false)

          console.log(
            `📑 Total pages: ${totalPages}, Remaining pages: ${pages}, View flag: ${view}`,
          );

          if (totalPages > page - 1) {
            // in PHP they always slice offset to offset+39
            const end = offset + (limit - 1);
            console.log(`📥 Fetching range from Redis: [${offset} - ${end}]`);
            logs = await redisClient.lRange(roomID, offset, end);
          }
        } else {
          console.log(
            `📥 Fetching remaining messages from offset ${offset} to end (-1)`,
          );
          logs = await redisClient.lRange(roomID, offset, -1);
        }

        console.log(`📦 Raw logs length: ${logs.length}`);

        // Convert flat array into structured messages (same as your first code)
        const filteredMessages = [];
        for (let i = 0; i < logs.length; i += 5) {
          if (i + 4 < logs.length) {
            filteredMessages.push({
              uid: logs[i],
              profile: logs[i + 1],
              username: logs[i + 2],
              timestamp: logs[i + 3],
              message: logs[i + 4],
            });
          }
        }

        console.log(`Structured messages count: ${filteredMessages.length}`);
        console.log(
          "Example structured message:",
          filteredMessages[0] || "None",
        );

        // Response style like PHP
        socketSignal.emit(
          "chatParameters",
          "first load",
          {
            result: "success",
            content: filteredMessages,
            bool: view,
          },
          mysocket,
        );
      } catch (error) {
        console.error("Error retrieving messages:", error);
      }
    }

    async function LatestData(page = 1, id, contentdata, count) {
      try {
        const limit = parseInt(count) * 5; // same as PHP
        const offset = (page - 1) * limit;
        const redisKey = `${argument.subdomain}-external-${contentdata.email}-${contentdata.id}`;
        const totalItems = await redisClient.lLen(redisKey);

        if (totalItems > 0) {
          const totalPages = Math.ceil(totalItems / limit);
          const end = offset + limit - 1;
          // fetch items
          const content = await redisClient.lRange(redisKey, offset, end);
          const hasMore = page <= totalPages;
          const next = hasMore ? page + 1 : false;
          socketSignal.emit(
            "chatParameters",
            "latest message",
            {
              result: "success",
              content: content,
              bool: next,
              fetchfirst: "first",
              key: redisKey,
            },
            mysocket,
          );
        } else {
          socketSignal.emit(
            "chatParameters",
            "latest message",
            {
              result: "success",
              content: ["1"],
              bool: false,
              fetchfirst: "first",
            },
            mysocket,
          );
        }

        // fallback if no data
      } catch (err) {
        console.error("⚠️ Error in LatestData:", err);
        return {
          result: "error",
          message: err.message,
        };
      }
    }

    async function replaceAgentKeysWithExtensionData([agentKey]) {
      if (!agentKey) return [];

      const extensionData = await redisClient.hGet(agentKey, "extensionData");

      // This line replaces both the .push() and the return extensionList
      return extensionData ? [extensionData] : [];
    }

    // console.log("communicate", arg);
    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let uniqueId = "";
    let length = 16;

    for (let i = 0; i < length; i++) {
      uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    uniqueId = `${Date.now()}${uniqueId}`;

    if (arg["type"] === "agentgroupglobalsearch") {
      try {
        const groupSubdomain = String(subdomain || "").trim();
        const searchTerm = String(arg.searchTerm || "").trim();
        const requesterAliases = [
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ].map((value) => String(value || "").trim()).filter(Boolean);
        const queues = Array.isArray(arg.queues)
          ? arg.queues.map((queue) => String(queue || "").trim()).filter(Boolean)
          : [];
        if (!groupSubdomain || !searchTerm || requesterAliases.length === 0) {
          throw new Error("Agent and search term are required");
        }

        await createAgentGroupSearchIndex(groupSubdomain);
        await createAgentGroupMessageSearchIndex(groupSubdomain);
        const memberFilter = requesterAliases
          .map((value) => escapeRedisSearchTag(value))
          .join("|");
        let groupQuery = `@memberIds:{${memberFilter}}`;
        if (queues.length > 0) {
          const queueFilter = queues
            .map((queue) => escapeRedisSearchTag(queue))
            .join("|");
          groupQuery += ` @queue:{${queueFilter}}`;
        }
        const groups = await redisClient.ft.search(
          `${groupSubdomain}-agent-group-chats`,
          groupQuery,
          { LIMIT: { from: 0, size: 1000 }, DIALECT: 2 },
        );
        const groupsByKey = new Map(
          groups.documents.map((document) => [document.id, document.value]),
        );
        const groupKeys = [...groupsByKey.keys()];
        let content = [];
        const tokens = searchTerm
          .replace(/[^a-zA-Z0-9_]/g, " ")
          .trim()
          .split(/\s+/)
          .filter(Boolean);
        if (groupKeys.length > 0 && tokens.length > 0) {
          const conversationFilter = groupKeys
            .map((key) => escapeRedisSearchTag(key))
            .join("|");
          const textQuery = `@message:(${tokens
            .map((token) => `${token}*`)
            .join(" ")})`;
          const canMatchId = /^[A-Za-z0-9_-]+$/.test(searchTerm);
          const searchQuery = canMatchId
            ? `@conversationKey:{${conversationFilter}} (${textQuery}|@uniqueId:{${escapeRedisSearchTag(searchTerm)}})`
            : `@conversationKey:{${conversationFilter}} ${textQuery}`;
          const messages = await redisClient.ft.search(
            `${groupSubdomain}-agent-group-messages`,
            searchQuery,
            {
              SORTBY: { BY: "timestamp", DIRECTION: "DESC" },
              // Read extra index hits so stale search documents can be
              // discarded while still returning up to 10 valid messages.
              LIMIT: { from: 0, size: 50 },
              DIALECT: 2,
            },
          );
          const historyByGroup = new Map();
          for (const document of messages.documents) {
            const message = document.value;
            const groupKey = String(message.conversationKey || "");
            const group = groupsByKey.get(groupKey) || {};
            if (!historyByGroup.has(groupKey)) {
              const rawHistory = await redisClient.lRange(
                `${groupKey}:messages`,
                0,
                -1,
              );
              historyByGroup.set(groupKey, rawHistory.map((raw) => {
                try { return JSON.parse(raw); } catch (_) { return null; }
              }).filter(Boolean));
            }
            const messageId = String(message.uniqueId || "");
            const storedMessage = historyByGroup.get(groupKey).find((item) =>
              String(item.id || item.uniqueId || "") === messageId,
            );
            // A RedisSearch document can outlive its list entry. Do not offer
            // a result that cannot be opened in the actual conversation.
            if (!storedMessage) continue;
            content.push({
              id: messageId,
              message: String(storedMessage.message || message.message || ""),
              senderName: String(storedMessage.senderName || message.senderName || ""),
              senderExtension: String(storedMessage.senderExtension || message.senderExtension || ""),
              timestamp: Number(storedMessage.timestamp || message.timestamp || 0),
              groupId: String(storedMessage.groupId || message.groupId || group.id || ""),
              groupName: String(group.name || "Team group"),
              groupKey,
              queue: String(storedMessage.queue || message.queue || group.queue || ""),
              memberIds: String(group.memberIds || ""),
              memberCount: Number(group.memberCount || 0),
            });
            if (content.length === 10) break;
          }
        }
        socketSignal.emit(
          "chatParameters",
          "agent group global search results",
          { result: "success", content },
          mysocket,
        );
      } catch (error) {
        console.error("[GROUP CHAT][GLOBAL SEARCH][FAILED]", error);
        socketSignal.emit(
          "chatParameters",
          "agent group global search results",
          { result: "error", message: error.message, content: [] },
          mysocket,
        );
      }
      return;
    }

    if (arg["type"] === "searchgroupchatmessages") {
      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const searchTerm = String(arg.searchTerm || "").trim().toLowerCase();
        const requesterAliases = [
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ].map((value) => String(value || "").trim()).filter(Boolean);
        if (!groupId || !queue || !groupSubdomain || !searchTerm) {
          throw new Error("Group, queue, and search term are required");
        }

        const { groupKey, group } = await resolveRequestedAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
          requestedGroupKey: arg.groupKey,
        });
        const memberIds = String(group.memberIds || "")
          .split(",")
          .map((id) => id.trim())
          .filter(Boolean);
        if (!requesterAliases.some((id) => memberIds.includes(id))) {
          throw new Error("Agent is not a member of this group chat");
        }

        await createAgentGroupMessageSearchIndex(groupSubdomain);
        const search = await redisClient.ft.search(
          `${groupSubdomain}-agent-group-messages`,
          `@conversationKey:{${escapeRedisSearchTag(groupKey)}}`,
          { LIMIT: { from: 0, size: 10000 }, DIALECT: 2 },
        );
        const content = search.documents
          .map((document) => document.value || {})
          .filter((message) => {
            const id = String(message.uniqueId || "").toLowerCase();
            const text = String(message.message || "").toLowerCase();
            const sender = String(message.senderName || "").toLowerCase();
            return id.includes(searchTerm) ||
              text.includes(searchTerm) ||
              sender.includes(searchTerm);
          })
          .map((message) => ({
            id: String(message.uniqueId || ""),
            message: String(message.message || ""),
            senderName: String(message.senderName || ""),
            senderExtension: String(message.senderExtension || ""),
            timestamp: Number(message.timestamp || 0),
          }))
          .sort((a, b) => b.timestamp - a.timestamp)
          .slice(0, 100);

        socketSignal.emit(
          "chatParameters",
          "group chat search results",
          { result: "success", id: groupId, queue, groupKey, content },
          mysocket,
        );
      } catch (error) {
        console.error("[GROUP CHAT][SEARCH][FAILED]", error);
        socketSignal.emit(
          "chatParameters",
          "group chat search results",
          { result: "error", id: arg.id, message: error.message, content: [] },
          mysocket,
        );
      }
      return;
    }

    if (
      arg["type"] === "getgroupchatmessages"
    ) {
      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const memberExtension = String(arg.extension || "").trim();
        const memberExtensions = [
          memberExtension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ]
          .map((value) => String(value || "").trim())
          .filter(Boolean)
          .filter((value, index, values) => values.indexOf(value) === index);
        const safeKeyPart = /^[A-Za-z0-9._-]+$/;

        if (
          !groupId ||
          !queue ||
          !groupSubdomain ||
          !memberExtension ||
          !safeKeyPart.test(groupId) ||
          !safeKeyPart.test(queue) ||
          !safeKeyPart.test(groupSubdomain)
        ) {
          throw new Error("Invalid group chat message request");
        }

        const { groupKey, group } = await resolveRequestedAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
          requestedGroupKey: arg.groupKey,
        });
        const messagesKey = `${groupKey}:messages`;

        const memberIds = String(group.memberIds || "")
          .split(",")
          .map((id) => id.trim())
          .filter(Boolean);
        const matchedMemberExtension = memberExtensions.find((extensionId) =>
          memberIds.includes(extensionId),
        );
        if (!matchedMemberExtension) {
          console.error("[GROUP CHAT][MESSAGE] Membership rejected", {
            groupKey,
            requestedExtension: memberExtension,
            requestedAliases: memberExtensions,
            storedMemberIds: memberIds,
          });
          throw new Error("Agent is not a member of this group chat");
        }

        if (arg["type"] === "getgroupchatmessages") {
          const rawMessages = await redisClient.lRange(messagesKey, 0, -1);
          const messages = rawMessages
            .map((item) => {
              try {
                return JSON.parse(item);
              } catch (error) {
                console.error("[GROUP CHAT][MESSAGE] Invalid stored JSON", {
                  messagesKey,
                  error: error.message,
                });
                return null;
              }
            })
            .filter(Boolean);

          console.log("[GROUP CHAT][MESSAGE] History loaded", {
            groupKey,
            memberExtension: matchedMemberExtension,
            count: messages.length,
          });
          socketSignal.emit(
            "chatParameters",
            "group chat messages",
            {
              result: "success",
              id: groupId,
              queue,
              content: messages,
            },
            `${subdomain}-all_messages`,
          );
          return;
        }

        const messageText = String(arg.message || "").trim();
        const fileInfo = Array.isArray(arg.fileInfo) ? arg.fileInfo : [];
        const fileBuffers = Array.isArray(arg.fileBuffer) ? arg.fileBuffer : [];
        if (!messageText && fileBuffers.length === 0) {
          throw new Error("Message or attachment is required");
        }
        if (messageText.length > 500) {
          throw new Error("Group messages are limited to 500 characters");
        }

        const attachments = [];
        if (fileBuffers.length > 0) {
          const uploadDirectory =
            "/var/www/webapp/chat_file_upload/group_chat";
          await fs.promises.mkdir(uploadDirectory, { recursive: true });

          for (let index = 0; index < fileBuffers.length; index++) {
            const info = fileInfo[index] || {};
            const originalName = String(info.name || `attachment-${index}`);
            const extensionName = path
              .extname(originalName)
              .replace(/[^.A-Za-z0-9]/g, "")
              .toLowerCase();
            const storedName = `${groupId}-${uniqueId}-${index}${extensionName}`;
            const storedPath = path.join(uploadDirectory, storedName);
            const binaryData = Buffer.from(String(fileBuffers[index]), "base64");

            if (binaryData.length > 20 * 1024 * 1024) {
              throw new Error("Attachment exceeds the 20 MB limit");
            }
            await fs.promises.writeFile(storedPath, binaryData);
            attachments.push({
              name: originalName,
              size: Number(info.size || binaryData.length),
              type: String(info.type || "file"),
              path: `chat_file_upload/group_chat/${storedName}`,
            });
          }

          console.log("[GROUP CHAT][SEND][ATTACHMENTS] Files saved", {
            groupKey,
            count: attachments.length,
            paths: attachments.map((file) => file.path),
          });
        }

        const message = {
          id: uniqueId,
          groupId,
          groupName,
          queue,
          senderExtension: matchedMemberExtension,
          senderName: String(arg.senderName || memberExtension),
          message: messageText,
          attachments,
          timestamp: Date.now(),
          seen: [matchedMemberExtension],
        };

        await redisClient.rPush(messagesKey, JSON.stringify(message));
        await saveAgentGroupMessageForSearch({
          subdomain: groupSubdomain,
          groupKey,
          message,
        });
        await redisClient.hSet(groupKey, {
          lastMessage: messageText || "Attachment",
          lastMessageAt: String(message.timestamp),
        });

        console.log("[GROUP CHAT][MESSAGE] Message saved", {
          groupKey,
          messagesKey,
          messageId: message.id,
          senderExtension: matchedMemberExtension,
        });

        emitAgentGroupMessageToRoom({
          subdomain: groupSubdomain,
          groupKey,
          memberIds,
          message,
        });
        return;
      } catch (error) {
        console.error("[GROUP CHAT][MESSAGE] Request failed", {
          type: arg.type,
          id: arg.id,
          queue: arg.queue,
          message: error.message,
          stack: error.stack,
        });
        socketSignal.emit(
          "chatParameters",
          arg["type"] === "getgroupchatmessages"
            ? "group chat messages"
            : "group chat message",
          { result: "error", message: error.message, id: arg.id },
          mysocket,
        );
        return;
      }
    }

    if (arg["type"] === "sendgroupchatmessage") {
      const sendStartedAt = Date.now();
      console.log("[GROUP CHAT][SEND][REACHED] sendgroupchatmessage received", {
        id: arg.id,
        queue: arg.queue,
        subdomain,
        extension: arg.extension,
        aliasCount: Array.isArray(arg.extensions) ? arg.extensions.length : 0,
        messageLength: String(arg.message || "").length,
        mysocket,
      });

      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const memberExtension = String(arg.extension || "").trim();
        const memberExtensions = [
          memberExtension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ]
          .map((value) => String(value || "").trim())
          .filter(Boolean)
          .filter((value, index, values) => values.indexOf(value) === index);
        const safeKeyPart = /^[A-Za-z0-9._-]+$/;

        console.log("[GROUP CHAT][SEND][1] Payload normalized", {
          groupId,
          queue,
          groupSubdomain,
          memberExtension,
          memberExtensions,
        });

        if (
          !groupId ||
          !queue ||
          !groupSubdomain ||
          !memberExtension ||
          !safeKeyPart.test(groupId) ||
          !safeKeyPart.test(queue) ||
          !safeKeyPart.test(groupSubdomain)
        ) {
          throw new Error("Invalid group chat message request");
        }

        console.log("[GROUP CHAT][SEND][2] Request validation passed");

        const { groupKey, group } = await resolveRequestedAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
          requestedGroupKey: arg.groupKey,
        });
        const messagesKey = `${groupKey}:messages`;

        console.log("[GROUP CHAT][SEND][3] Group loaded", {
          groupKey,
          messagesKey,
          storedGroupId: group.id,
          storedQueue: group.queue,
        });

        const memberIds = String(group.memberIds || "")
          .split(",")
          .map((id) => id.trim())
          .filter(Boolean);
        const matchedMemberExtension = memberExtensions.find((extensionId) =>
          memberIds.includes(extensionId),
        );
        if (!matchedMemberExtension) {
          console.error("[GROUP CHAT][MESSAGE] Membership rejected", {
            groupKey,
            requestedExtension: memberExtension,
            requestedAliases: memberExtensions,
            storedMemberIds: memberIds,
          });
          throw new Error("Agent is not a member of this group chat");
        }

        console.log("[GROUP CHAT][SEND][4] Membership accepted", {
          matchedMemberExtension,
          memberCount: memberIds.length,
        });

        const messageText = String(arg.message || "").trim();
        const fileInfo = Array.isArray(arg.fileInfo) ? arg.fileInfo : [];
        const fileBuffers = Array.isArray(arg.fileBuffer) ? arg.fileBuffer : [];
        if (!messageText && fileBuffers.length === 0) {
          throw new Error("Message or attachment is required");
        }
        if (messageText.length > 500) {
          throw new Error("Group messages are limited to 500 characters");
        }

        const attachments = [];
        if (fileBuffers.length > 0) {
          const uploadDirectory =
            "/var/www/webapp/chat_file_upload/group_chat";
          await fs.promises.mkdir(uploadDirectory, { recursive: true });
          for (let index = 0; index < fileBuffers.length; index++) {
            const info = fileInfo[index] || {};
            const originalName = String(info.name || `attachment-${index}`);
            const extensionName = path
              .extname(originalName)
              .replace(/[^.A-Za-z0-9]/g, "")
              .toLowerCase();
            const storedName = `${groupId}-${uniqueId}-${index}${extensionName}`;
            const storedPath = path.join(uploadDirectory, storedName);
            const binaryData = Buffer.from(String(fileBuffers[index]), "base64");
            if (binaryData.length > 20 * 1024 * 1024) {
              throw new Error("Attachment exceeds the 20 MB limit");
            }
            await fs.promises.writeFile(storedPath, binaryData);
            const requestedType = String(info.type || "").toLowerCase();
            const imageExtensions = new Set([
              ".jpg",
              ".jpeg",
              ".png",
              ".gif",
              ".webp",
              ".bmp",
              ".svg",
              ".avif",
            ]);
            const isImage =
              requestedType.startsWith("image/") ||
              imageExtensions.has(extensionName);
            attachments.push({
              name: originalName,
              fileName: originalName,
              size: Number(info.size || binaryData.length),
              type: isImage ? "image" : "file",
              mimeType: requestedType || "application/octet-stream",
              kind: isImage ? "image" : "file",
              path: `chat_file_upload/group_chat/${storedName}`,
              url: `chat_file_upload/group_chat/${storedName}`,
            });
          }
          console.log("[GROUP CHAT][SEND][ATTACHMENTS] Files saved", {
            groupKey,
            count: attachments.length,
            paths: attachments.map((file) => file.path),
          });
        }

        const message = {
          id: uniqueId,
          groupId,
          queue,
          senderExtension: matchedMemberExtension,
          senderName: String(arg.senderName || memberExtension),
          message: messageText,
          attachments,
          timestamp: Date.now(),
          seen: [matchedMemberExtension],
        };

        const conversationLength = await redisClient.rPush(
          messagesKey,
          JSON.stringify(message),
        );
        console.log("[GROUP CHAT][SEND][5] RPUSH completed", {
          messagesKey,
          messageId: message.id,
          conversationLength,
        });

        await saveAgentGroupMessageForSearch({
          subdomain: groupSubdomain,
          groupKey,
          message,
        });

        await redisClient.hSet(groupKey, {
          lastMessage: messageText || "Attachment",
          lastMessageAt: String(message.timestamp),
        });

        const unreadKey = `${groupKey}:unread`;
        const unreadTransaction = redisClient.multi();
        for (const memberId of memberIds) {
          if (memberExtensions.includes(memberId)) {
            unreadTransaction.hSet(unreadKey, memberId, "0");
          } else {
            unreadTransaction.hIncrBy(unreadKey, memberId, 1);
          }
        }
        await unreadTransaction.exec();

        console.log("[GROUP CHAT][SEND][6] RediSearch save completed", {
          messageId: message.id,
          unreadKey,
          unreadRecipients: memberIds.filter(
            (memberId) => !memberExtensions.includes(memberId),
          ),
        });

        console.log("[GROUP CHAT][MESSAGE] Message saved", {
          groupKey,
          messagesKey,
          messageId: message.id,
          senderExtension: matchedMemberExtension,
        });

        // One successful room broadcast is enough. The sender is also joined
        // to this room, so a second mysocket emit would duplicate the message.
        emitAgentGroupMessageToRoom({
          subdomain: groupSubdomain,
          groupKey,
          memberIds,
          message,
        });

        await pushAgentConversationWeb({
          subdomain: groupSubdomain,
          groupKey,
          group,
          memberIds,
          senderAliases: memberExtensions,
          message,
        });

        console.log("[GROUP CHAT][SEND][COMPLETED] sendgroupchatmessage done", {
          groupKey,
          messageId: message.id,
          targetRoom: `${groupSubdomain}-all_messages`,
          elapsedMs: Date.now() - sendStartedAt,
        });
        return;
      } catch (error) {
        console.error("[GROUP CHAT][SEND][FAILED] sendgroupchatmessage failed", {
          type: arg.type,
          id: arg.id,
          queue: arg.queue,
          message: error.message,
          stack: error.stack,
          elapsedMs: Date.now() - sendStartedAt,
        });
        socketSignal.emit(
          "chatParameters",
          "group chat message",
          { result: "error", message: error.message, id: arg.id },
          mysocket,
        );
        console.log("[GROUP CHAT][SEND][ERROR_EMITTED]", {
          target: mysocket,
          id: arg.id,
        });
        return;
      }
    }

    if (arg["type"] === "readgroupchat") {
      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const requesterAliases = [
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ]
          .map((id) => String(id || "").trim())
          .filter(Boolean)
          .filter((id, index, values) => values.indexOf(id) === index);
        const { groupKey, group } = await resolveRequestedAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
          requestedGroupKey: arg.groupKey,
        });
        const memberIds = String(group.memberIds || "")
          .split(",")
          .map((id) => id.trim())
          .filter(Boolean);
        const seenBy = requesterAliases.find((id) => memberIds.includes(id));
        if (!seenBy) {
          throw new Error("Agent is not a member of this group chat");
        }

        const messagesKey = `${groupKey}:messages`;
        const rawMessages = await redisClient.lRange(messagesKey, 0, -1);
        const updatedMessageIds = [];
        const readTransaction = redisClient.multi();

        rawMessages.forEach((rawMessage, index) => {
          try {
            const message = JSON.parse(rawMessage);
            const seen = Array.isArray(message.seen)
              ? message.seen.map((id) => String(id))
              : String(message.seen || "")
                  .split(",")
                  .map((id) => id.trim())
                  .filter(Boolean);
            if (
              message.senderExtension !== seenBy &&
              !seen.includes(seenBy)
            ) {
              seen.push(seenBy);
              message.seen = seen;
              updatedMessageIds.push(String(message.id));
              readTransaction.lSet(
                messagesKey,
                index,
                JSON.stringify(message),
              );
              readTransaction.hSet(
                `${groupSubdomain}:groupmsg:${message.id}`,
                "seen",
                JSON.stringify(seen),
              );
            }
          } catch (error) {
            console.error("[GROUP CHAT][READ] Invalid stored message", {
              messagesKey,
              index,
              error: error.message,
            });
          }
        });

        for (const alias of requesterAliases) {
          readTransaction.hSet(`${groupKey}:unread`, alias, "0");
        }
        await readTransaction.exec();

        const payload = {
          result: "success",
          id: groupId,
          queue,
          groupKey,
          seenBy,
          seenAliases: requesterAliases,
          messageIds: updatedMessageIds,
          unreadCount: 0,
        };
        socketSignal.emit(
          "chatParameters",
          "group chat read",
          payload,
          `${groupSubdomain}-all_messages`,
        );
        console.log("[GROUP CHAT][READ][COMPLETED]", {
          ...payload,
          targetRoom: `${groupSubdomain}-all_messages`,
        });
      } catch (error) {
        console.error("[GROUP CHAT][READ][FAILED]", error);
        socketSignal.emit(
          "chatParameters",
          "group chat read",
          { result: "error", message: error.message, id: arg.id },
          mysocket,
        );
      }
      return;
    }

    if (arg["type"] === "updategroupchat") {
      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const requesterAliases = [
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ].map((id) => String(id || "").trim()).filter(Boolean);
        const requesterExtension = requesterAliases.find((id) =>
          /^\d+$/.test(id),
        );
        const permissionKey = requesterExtension
          ? `${requesterExtension}-${groupSubdomain}-permission`
          : "";
        const permission = permissionKey
          ? await redisClient.get(permissionKey)
          : null;

        console.log("[GROUP CHAT][UPDATE][PERMISSION]", {
          requesterExtension,
          permissionKey,
          permission,
          allowed: permission === "7" || permission === "8",
        });

        if (permission !== "7" && permission !== "8") {
          socketSignal.emit(
            "chatParameters",
            "queueError",
            {
              status: "failed",
              msg: "You don't have the authority to edit group members.",
              type: "updategroupchat",
              id: groupId,
              queue,
            },
            mysocket,
          );
          return;
        }

        const { groupKey, group } = await resolveAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
        });

        const previousMemberIds = String(group.memberIds || "")
          .split(",")
          .filter(Boolean);
        const actorExtension = requesterAliases.find((id) =>
          previousMemberIds.includes(id),
        );
        if (!actorExtension) {
          throw new Error("Only a group member can edit this conversation");
        }
        const actorDisplayExtension =
          requesterAliases.find((id) => /^\d+$/.test(id)) || actorExtension;

        const requestedMemberIds = Array.isArray(arg.memberIds)
          ? arg.memberIds.map((id) => String(id).trim()).filter(Boolean)
          : [];
        const memberIds = [group.createdBy, ...requestedMemberIds]
          .filter(Boolean)
          .filter((id, index, values) => values.indexOf(id) === index);
        const members = Array.isArray(arg.members) ? arg.members : [];
        const addedMemberIds = memberIds.filter(
          (id) => !previousMemberIds.includes(id),
        );
        const removedMemberIds = previousMemberIds.filter(
          (id) => !memberIds.includes(id),
        );

        await redisClient.hSet(groupKey, {
          memberIds: memberIds.join(","),
          memberCount: String(memberIds.length),
          members: JSON.stringify(members),
          updatedAt: String(Date.now()),
        });

        const unreadKey = `${groupKey}:unread`;
        if (addedMemberIds.length > 0) {
          await redisClient.hSet(
            unreadKey,
            Object.fromEntries(addedMemberIds.map((id) => [id, "0"])),
          );
        }
        if (removedMemberIds.length > 0) {
          await redisClient.hDel(unreadKey, removedMemberIds);
        }

        const membershipActions = [
          { action: "added", memberIds: addedMemberIds },
          { action: "removed", memberIds: removedMemberIds },
        ].filter((item) => item.memberIds.length > 0);
        const systemMessages = [];
        for (const [index, membershipAction] of membershipActions.entries()) {
          const affectedIds = membershipAction.memberIds;
          const actionText = membershipAction.action === "added"
            ? `${actorDisplayExtension} added ${affectedIds.join(", ")} to this conversation`
            : `${actorDisplayExtension} removed ${affectedIds.join(", ")} from this conversation`;
          const systemMessage = {
            id: `${Date.now()}-membership-${index}-${Math.random().toString(36).slice(2, 8)}`,
            groupId,
            queue,
            senderExtension: actorExtension,
            senderName: actorDisplayExtension,
            message: actionText,
            messageType: "system",
            membershipAction: membershipAction.action,
            affectedMemberIds: affectedIds,
            attachments: [],
            timestamp: Date.now(),
            seen: [actorExtension],
            unreadIncrement: index === 0,
          };

          await redisClient.rPush(
            `${groupKey}:messages`,
            JSON.stringify(systemMessage),
          );
          await saveAgentGroupMessageForSearch({
            subdomain: groupSubdomain,
            groupKey,
            message: systemMessage,
          });
          await redisClient.hSet(groupKey, {
            lastMessage: systemMessage.message,
            lastMessageAt: String(systemMessage.timestamp),
          });

          systemMessages.push(systemMessage);
        }
        if (membershipActions.length > 0) {
          const unreadTransaction = redisClient.multi();
          for (const memberId of memberIds) {
            if (requesterAliases.includes(memberId)) {
              unreadTransaction.hSet(unreadKey, memberId, "0");
            } else {
              unreadTransaction.hIncrBy(unreadKey, memberId, 1);
            }
          }
          await unreadTransaction.exec();
        }

        const payload = {
          result: "success",
          id: groupId,
          queue,
          groupKey,
          memberIds,
          previousMemberIds,
          addedMemberIds,
          removedMemberIds,
          members,
          systemMessages,
        };
        console.log("[GROUP CHAT][UPDATE][BROADCASTING]", {
          targetRoom: `${groupSubdomain}-all_messages`,
          groupKey,
          addedMemberIds,
          removedMemberIds,
        });
        socketSignal.emit(
          "chatParameters",
          "group chat updated",
          payload,
          `${groupSubdomain}-all_messages`,
        );
        for (const systemMessage of systemMessages) {
          emitAgentGroupMessageToRoom({
            subdomain: groupSubdomain,
            groupKey,
            memberIds,
            message: systemMessage,
          });
        }
        console.log("[GROUP CHAT][UPDATE][COMPLETED]", payload);
      } catch (error) {
        console.error("[GROUP CHAT][UPDATE][FAILED]", error);
        socketSignal.emit(
          "chatParameters",
          "queueError",
          {
            status: "failed",
            msg: error.message,
            type: "updategroupchat",
            id: arg.id,
            queue: arg.queue,
          },
          mysocket,
        );
        socketSignal.emit(
          "chatParameters",
          "group chat updated",
          {
            result: "error",
            message: error.message,
            id: arg.id,
            handledByQueueError: true,
          },
          mysocket,
        );
      }
      return;
    }

    if (arg["type"] === "leavegroupchat") {
      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const requesterAliases = [
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ]
          .map((id) => String(id || "").trim())
          .filter(Boolean)
          .filter((id, index, values) => values.indexOf(id) === index);
        const { groupKey, group } = await resolveAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
        });
        const previousMemberIds = String(group.memberIds || "")
          .split(",")
          .map((id) => id.trim())
          .filter(Boolean);
        const removedMemberIds = previousMemberIds.filter((id) =>
          requesterAliases.includes(id),
        );
        if (removedMemberIds.length === 0) {
          throw new Error("Agent is not a member of this group chat");
        }

        const memberIds = previousMemberIds.filter(
          (id) => !requesterAliases.includes(id),
        );
        let members = [];
        try {
          const storedMembers = JSON.parse(group.members || "[]");
          if (Array.isArray(storedMembers)) {
            members = storedMembers.filter((member) => {
              const extension = String(member?.extension || "");
              const extension2 = String(member?.extension2 || "");
              return (
                !requesterAliases.includes(extension) &&
                !requesterAliases.includes(extension2)
              );
            });
          }
        } catch (error) {
          console.error("[GROUP CHAT][LEAVE] Invalid members JSON", {
            groupKey,
            error: error.message,
          });
        }

        const deleted = memberIds.length === 0;
        let leaveMessage = null;
        if (deleted) {
          await redisClient.del([`${groupKey}:unread`, groupKey]);

          // OPTIONAL: Uncomment the next line if the last member leaving should
          // also delete its Redis list and RedisSearch message documents.
          // await deleteAgentGroupConversationHistory({ subdomain: groupSubdomain, groupKey });
        } else {
          await redisClient.hSet(groupKey, {
            memberIds: memberIds.join(","),
            memberCount: String(memberIds.length),
            members: JSON.stringify(members),
            updatedAt: String(Date.now()),
          });
          await redisClient.hDel(`${groupKey}:unread`, requesterAliases);

          const leavingExtension =
            requesterAliases.find((id) => /^\d+$/.test(id)) ||
            removedMemberIds[0];
          leaveMessage = {
            id: `${Date.now()}-membership-left-${Math.random().toString(36).slice(2, 8)}`,
            groupId,
            queue,
            senderExtension: removedMemberIds[0],
            senderName: leavingExtension,
            message: `${leavingExtension} left the conversation`,
            messageType: "system",
            membershipAction: "left",
            affectedMemberIds: removedMemberIds,
            attachments: [],
            timestamp: Date.now(),
            seen: [removedMemberIds[0]],
            unreadIncrement: true,
          };
          await redisClient.rPush(
            `${groupKey}:messages`,
            JSON.stringify(leaveMessage),
          );
          await saveAgentGroupMessageForSearch({
            subdomain: groupSubdomain,
            groupKey,
            message: leaveMessage,
          });
          await redisClient.hSet(groupKey, {
            lastMessage: leaveMessage.message,
            lastMessageAt: String(leaveMessage.timestamp),
          });
          const unreadTransaction = redisClient.multi();
          for (const memberId of memberIds) {
            unreadTransaction.hIncrBy(`${groupKey}:unread`, memberId, 1);
          }
          await unreadTransaction.exec();
        }

        const payload = {
          result: "success",
          id: groupId,
          queue,
          groupKey,
          memberIds,
          previousMemberIds,
          removedMemberIds,
          members,
          leftBy: requesterAliases,
          deleted,
          systemMessages: leaveMessage ? [leaveMessage] : [],
        };
        socketSignal.emit(
          "chatParameters",
          "group chat updated",
          payload,
          `${groupSubdomain}-all_messages`,
        );
        if (leaveMessage) {
          emitAgentGroupMessageToRoom({
            subdomain: groupSubdomain,
            groupKey,
            memberIds,
            message: leaveMessage,
          });
        }
        console.log("[GROUP CHAT][LEAVE][COMPLETED]", {
          groupKey,
          removedMemberIds,
          remainingMemberIds: memberIds,
          deleted,
          targetRoom: `${groupSubdomain}-all_messages`,
        });
      } catch (error) {
        console.error("[GROUP CHAT][LEAVE][FAILED]", error);
        socketSignal.emit(
          "chatParameters",
          "group chat updated",
          { result: "error", message: error.message, id: arg.id },
          mysocket,
        );
      }
      return;
    }

    if (arg["type"] === "deletegroupchat") {
      try {
        const groupId = String(arg.id || "").trim();
        const queue = String(arg.queue || "").trim();
        const groupSubdomain = String(subdomain || "").trim();
        const requesterAliases = [
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ].map((id) => String(id || "").trim()).filter(Boolean);
        const { groupKey, group } = await resolveAgentGroup({
          subdomain: groupSubdomain,
          groupId,
          queue,
        });
        const messagesKey = `${groupKey}:messages`;
        const memberIds = String(group.memberIds || "")
          .split(",")
          .filter(Boolean);
        if (!requesterAliases.some((id) => memberIds.includes(id))) {
          throw new Error("Only a group member can delete this conversation");
        }

        const search = await redisClient.ft.search(
          `${groupSubdomain}-agent-group-messages`,
          `@conversationKey:{${escapeRedisSearchTag(groupKey)}}`,
          { LIMIT: { from: 0, size: 10000 }, DIALECT: 2 },
        ).catch(() => ({ documents: [] }));
        const messageDocumentKeys = search.documents.map((document) => document.id);
        if (messageDocumentKeys.length > 0) {
          await redisClient.del(messageDocumentKeys);
        }
        await redisClient.del([messagesKey, `${groupKey}:unread`, groupKey]);

        const payload = { result: "success", id: groupId, queue, groupKey };

        socketSignal.emit(
          "chatParameters",
          "group chat deleted",
          payload,
          `${groupSubdomain}-all_messages`,
        );
        console.log("[GROUP CHAT][DELETE][COMPLETED]", {
          ...payload,
          deletedMessageDocuments: messageDocumentKeys.length,
        });
      } catch (error) {
        console.error("[GROUP CHAT][DELETE][FAILED]", error);
        socketSignal.emit(
          "chatParameters",
          "group chat deleted",
          { result: "error", message: error.message, id: arg.id },
          mysocket,
        );
      }
      return;
    }

    if (arg["type"] === "getgroupchats") {
      try {
        const groupSubdomain = String(subdomain || "").trim();
        const memberExtension = String(arg.extension || "").trim();
        const memberExtensions = [
          memberExtension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ]
          .map((value) => String(value || "").trim())
          .filter(Boolean)
          .filter((value, index, values) => values.indexOf(value) === index);
        const queues = Array.isArray(arg.queues)
          ? arg.queues.map((queue) => String(queue).trim()).filter(Boolean)
          : [];

        if (!groupSubdomain || !memberExtension) {
          socketSignal.emit(
            "chatParameters",
            "group chat list",
            { result: "error", message: "subdomain and extension are required" },
            mysocket,
          );
          return;
        }

        await createAgentGroupSearchIndex(groupSubdomain);

        const memberFilter = memberExtensions
          .map((value) => escapeRedisSearchTag(value))
          .join("|");
        let query = `@memberIds:{${memberFilter}}`;
        if (queues.length > 0) {
          const queueFilter = queues
            .map((queue) => escapeRedisSearchTag(queue))
            .join("|");
          query += ` @queue:{${queueFilter}}`;
        }

        console.log("[GROUP CHAT][LIST] Searching groups", {
          groupSubdomain,
          memberExtension,
          memberExtensions,
          queues,
          query,
          target: mysocket,
        });

        const result = await redisClient.ft.search(
          `${groupSubdomain}-agent-group-chats`,
          query,
          {
            SORTBY: { BY: "createdAt", DIRECTION: "DESC" },
            LIMIT: { from: 0, size: 100 },
            DIALECT: 2,
          },
        );

        console.log("[GROUP CHAT][LIST] Search completed", {
          total: result.total,
          documentIds: result.documents.map((document) => document.id),
        });

        const content = [];
        for (const document of result.documents) {
          const unreadValues = await redisClient.hmGet(
            `${document.id}:unread`,
            memberExtensions,
          );
          const unreadCount = unreadValues.reduce(
            (total, value) => total + (Number.parseInt(value || "0", 10) || 0),
            0,
          );
          content.push({
            ...document.value,
            redisKey: document.id,
            unreadCount,
          });
        }

        socketSignal.emit(
          "chatParameters",
          "group chat list",
          {
            result: "success",
            content,
            total: result.total,
          },
          mysocket,
        );
      } catch (error) {
        console.error("[GROUP CHAT] Failed loading groups:", error);
        socketSignal.emit(
          "chatParameters",
          "group chat list",
          { result: "error", message: error.message },
          mysocket,
        );
      }

      return;
    }

    if (arg["type"] === "creategroupchat") {
      const requestStartedAt = Date.now();
      console.log("[GROUP CHAT][CREATE][1] Handler entered", {
        type: arg.type,
        id: arg.id,
        queue: arg.queue,
        envelopeSubdomain: subdomain,
        createdBy: arg.createdBy,
        memberIdsIsArray: Array.isArray(arg.memberIds),
        memberIdsCount: Array.isArray(arg.memberIds) ? arg.memberIds.length : 0,
        membersCount: Array.isArray(arg.members) ? arg.members.length : 0,
        mysocket,
      });

      try {
        const groupId = String(arg.id || "").trim();
        const groupName = String(arg.name || "").trim();
        // Team rooms need no queue selection; retain the existing Redis key format.
        const queue = String(arg.queue || "").trim() || "team";
        const groupSubdomain = String(subdomain || "").trim();
        // Store the established chat-member schema while accepting parameter extensions.
        const members = (Array.isArray(arg.members) ? arg.members : [])
          .filter((member) => member && typeof member === "object")
          .map((member) => ({
            extension: String((arg.memberFormat === "extensions"
              ? member.extension2 : member.extension) || "").trim(),
            extension2: String((arg.memberFormat === "extensions"
              ? member.extension : member.extension2) || "").trim(),
            firstname: String(member.firstname || ""),
            lastname: String(member.lastname || ""),
          }));
        const memberIds = [...new Set(
          (arg.memberFormat === "extensions"
            ? members.map((member) => member.extension2)
            : (Array.isArray(arg.memberIds) ? arg.memberIds : []))
            .map((id) => String(id || "").trim()).filter(Boolean),
        )];
        const creatorAliases = [
          arg.createdBy,
          arg.extension,
          ...(Array.isArray(arg.extensions) ? arg.extensions : []),
        ]
          .map((id) => String(id || "").trim())
          .filter(Boolean)
          .filter((id, index, values) => values.indexOf(id) === index);
        const creatorExtension =
          creatorAliases.find((id) => /^\d+$/.test(id)) ||
          String(arg.createdBy || "").trim();

        console.log("[GROUP CHAT][CREATE][2] Payload normalized", {
          groupId,
          queue,
          groupSubdomain,
          memberIds,
          createdBy: creatorExtension,
          creatorAliases,
        });

        if (
          !groupSubdomain ||
          !groupId ||
          !groupName ||
          !creatorExtension ||
          memberIds.length === 0
        ) {
          console.error("[GROUP CHAT][CREATE][STOP] Missing required values", {
            hasSubdomain: Boolean(groupSubdomain),
            hasGroupId: Boolean(groupId),
            hasGroupName: Boolean(groupName),
            memberIdsCount: memberIds.length,
          });
          const emitted = socketSignal.emit(
            "chatParameters",
            "group chat created",
            {
              result: "error",
              message:
                "subdomain, id, name, creator extension, and memberIds are required",
            },
            mysocket,
          );
          console.log("[GROUP CHAT][CREATE][EMIT] Validation error sent", {
            emitted,
            target: mysocket,
          });
          return;
        }

        if (!/^[A-Za-z0-9 ]+$/.test(groupName) || groupName.length > 50) {
          console.error("[GROUP CHAT][CREATE][STOP] Invalid room name", {
            groupName,
          });
          socketSignal.emit(
            "chatParameters",
            "group chat created",
            {
              result: "error",
              message:
                "Room name must contain only letters, numbers, and spaces",
            },
            mysocket,
          );
          return;
        }

        // Keep every part safe because all three values become a Redis key.
        const safeKeyPart = /^[A-Za-z0-9._-]+$/;
        if (
          !safeKeyPart.test(groupSubdomain) ||
          !safeKeyPart.test(groupId) ||
          !safeKeyPart.test(queue)
        ) {
          console.error("[GROUP CHAT][CREATE][STOP] Unsafe Redis key part", {
            groupSubdomainValid: safeKeyPart.test(groupSubdomain),
            groupIdValid: safeKeyPart.test(groupId),
            queueValid: safeKeyPart.test(queue),
          });
          const emitted = socketSignal.emit(
            "chatParameters",
            "group chat created",
            { result: "error", message: "Invalid group chat key" },
            mysocket,
          );
          console.log("[GROUP CHAT][CREATE][EMIT] Key error sent", {
            emitted,
            target: mysocket,
          });
          return;
        }

        const groupKey = `${groupSubdomain}-${groupId}-${queue}`;
        const indexName = `${groupSubdomain}-agent-group-chats`;
        console.log("[GROUP CHAT][CREATE][3] Redis keys prepared", {
          groupKey,
          indexName,
        });

        // Include the creator in the group and avoid duplicate members.
        const allMemberIds = [creatorExtension, ...memberIds]
          .filter(Boolean)
          .filter((id, index, values) => values.indexOf(id) === index);

        console.log("[GROUP CHAT][CREATE][4] Members prepared", {
          creatorIncluded: allMemberIds.includes(creatorExtension),
          uniqueMemberCount: allMemberIds.length,
          allMemberIds,
        });

        console.log("[GROUP CHAT][CREATE][5] Ensuring search index");
        await createAgentGroupSearchIndex(groupSubdomain);
        console.log("[GROUP CHAT][CREATE][6] Search index ready", {
          indexName,
        });

        const hashWriteResult = await redisClient.hSet(groupKey, {
          id: groupId,
          name: groupName,
          queue,
          subdomain: groupSubdomain,
          createdBy: creatorExtension,
          memberIds: allMemberIds.join(","),
          memberCount: String(allMemberIds.length),
          members: JSON.stringify(members),
          createdAt: String(Date.now()),
          groupKey,
        });
        const hashExists = await redisClient.exists(groupKey);
        const storedHash = await redisClient.hGetAll(groupKey);
        if (allMemberIds.length > 0) {
          await redisClient.hSet(
            `${groupKey}:unread`,
            Object.fromEntries(allMemberIds.map((id) => [id, "0"])),
          );
        }
        console.log("[GROUP CHAT][CREATE][7] Redis hash write completed", {
          groupKey,
          hashWriteResult,
          hashExists,
          storedFields: Object.keys(storedHash),
          storedId: storedHash.id,
          storedQueue: storedHash.queue,
          storedMemberIds: storedHash.memberIds,
        });

        // A successful hash write is authoritative; search indexing may lag.
        const savedGroup = storedHash;

        console.log("[GROUP CHAT] Created", {
          groupKey,
          queue,
          memberIds: allMemberIds,
        });

        console.log("[GROUP CHAT][CREATE][10] Broadcasting success", {
          event: "chatParameters",
          channel: "group chat created",
          target: `${groupSubdomain}-all_messages`,
        });
        const creationResponse = {
          result: "success",
          id: groupId,
          name: groupName,
          queue,
          key: groupKey,
          memberIds: allMemberIds,
          members,
          group: savedGroup,
        };
        // A creator must receive confirmation even without the shared room subscription.
        socketSignal.emit("chatParameters", "group chat created", creationResponse, mysocket);
        const emitted = socketSignal.emit(
          "chatParameters", "group chat created", creationResponse,
          `${groupSubdomain}-all_messages`,
        );
        const creationMessage = {
          id: `${Date.now()}-membership-create-${Math.random().toString(36).slice(2, 8)}`,
          groupId,
          queue,
          senderExtension: creatorExtension,
          senderName: creatorExtension,
          message: `${creatorExtension} added ${memberIds.join(", ")} to this conversation`,
          messageType: "system",
          membershipAction: "added",
          affectedMemberIds: memberIds,
          attachments: [],
          timestamp: Date.now(),
          seen: [creatorExtension],
          unreadIncrement: true,
        };
        await redisClient.rPush(
          `${groupKey}:messages`,
          JSON.stringify(creationMessage),
        );
        await saveAgentGroupMessageForSearch({
          subdomain: groupSubdomain,
          groupKey,
          message: creationMessage,
        });
        await redisClient.hSet(groupKey, {
          lastMessage: creationMessage.message,
          lastMessageAt: String(creationMessage.timestamp),
        });
        const creationUnread = redisClient.multi();
        for (const groupMemberId of allMemberIds) {
          if (groupMemberId === creatorExtension) {
            creationUnread.hSet(`${groupKey}:unread`, groupMemberId, "0");
          } else {
            creationUnread.hIncrBy(`${groupKey}:unread`, groupMemberId, 1);
          }
        }
        await creationUnread.exec();
        emitAgentGroupMessageToRoom({
          subdomain: groupSubdomain,
          groupKey,
          memberIds: allMemberIds,
          message: creationMessage,
        });
        console.log("[GROUP CHAT][CREATE][DONE] Success emit called", {
          emitted,
          elapsedMs: Date.now() - requestStartedAt,
        });
      } catch (error) {
        console.error("[GROUP CHAT][CREATE][FAILED]", {
          message: error?.message,
          name: error?.name,
          stack: error?.stack,
          elapsedMs: Date.now() - requestStartedAt,
        });
        const emitted = socketSignal.emit(
          "chatParameters",
          "group chat created",
          { result: "error", message: error.message },
          mysocket,
        );
        console.log("[GROUP CHAT][CREATE][EMIT] Failure sent", {
          emitted,
          target: mysocket,
        });
      }

      return;
    }

    //Client message sent

    if (arg["type"] == "initializeAccessToken") {
      console.log("this is arg for connection + accessToken", arg);
      const verified = await verifyToken2(
        "webchatconfig",
        subdomain,
        arg.accessToken,
      );
      if (!verified) {
        console.log("Token verification failed");
        return;
      }
      const { accessToken } = verified;
      console.log("Verified Token:", accessToken);
    }

    // Website visitor message sent
    if (arg["type"] == 1) {
      await handleWebsiteClientMessage({
        arg,
        subdomain,
        mysocket,
        ip,
      });
    }

    // Human agent message sent
    if (arg["type"] == 2) {
      try {
        await handleWebsiteAgentMessage({
          arg,
          subdomain,
          mysocket,
          myextension,
        });
      } catch (err) {
        console.error("ERROR in type 2:", err);
      }
    }
    // Agent or website visitor login/session establishment
    if (arg["type"] == 3) {
      await handleChatLogin({
        arg,
        subdomain,
        mysocket,
        myextension,
        uniqueId,
        ip,
        socket,
      });
    }

    //reconnection
    if (arg["type"] == 4) {
      try {
        if (arg.connection == "reconnect") {
          getData(arg, arg.page);
        }
        if (arg.connection == "latest data") {
          console.log("for the latest data ", arg);
          LatestData(arg.current_data, arg.id, arg, arg.message_count);
        }
        // if (arg.connection == "heartbeat"){
        //   console.log("connection heartbeat is running", arg);
        //   heartBeatListening(arg);
        // }
      } catch (err) {
        console.error("error in type 4", err);
      }
    }
    // type 8 good
    if (arg["type"] == 8) {
      function updateChat(payload) {
        return new Promise((resolve, reject) => {
          const url = `https://${subdomain}:8443/chatadmin/getassign`;
          const curlArgs = [
            "-s",
            "-X",
            "POST",
            url,
            "-H",
            "Content-Type: application/json",
            "-d",
            JSON.stringify(payload),
          ];

          console.log(
            "[updateChat] POST",
            url,
            "payload:",
            JSON.stringify(payload),
          );

          execFile("curl", curlArgs, (error, stdout, stderr) => {
            if (error) {
              console.error("[updateChat] exec error:", error);
              return reject(error);
            }
            if (stderr) {
              console.log("[updateChat] stderr:", stderr);
            }
            console.log("[updateChat] raw response:", stdout);
            try {
              const parsed = JSON.parse(stdout);
              console.log("[updateChat] parsed response:", parsed);
              resolve(parsed);
            } catch {
              console.log(
                "[updateChat] response was not JSON, returning raw text",
              );
              resolve(stdout);
            }
          });
        });
      }

      try {
        console.log("type 8 ratings");
        console.log(arg);
        if (arg.about == "feedback") {
          console.log("this is the feedback");
          if (!arg.email) {
            return;
          }
          if (!arg.username) {
            return;
          }
          if (!arg.id) {
            return;
          }
          const chatKey = `${subdomain}-external-${arg.email}-${arg.id}`;
          const clientKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;

          console.log("this is type 8 chatkey", chatKey, clientKey);
          console.log("clientKey ", clientKey);
          const clientUser = (await redisClient.exists(clientKey))
            ? await redisClient.hGetAll(clientKey)
            : null;

          if (!clientUser) {
            console.log("No client found for userKey", clientKey);
            return;
          }
          console.log("progress");

          // The type-8 arg comes straight from the widget (about:"feedback") and
          // carries no clientUserKey — getassign matches @clientKey on `member`,
          // so it has to be the Redis client key built above. The recipient is
          // validated because getassign 400s on a non-email `to`.
          const isPlainEmail = (value) =>
            typeof value === "string" &&
            /^[^\s@'"`$;|&\\]+@[^\s@'"`$;|&\\]+\.[^\s@'"`$;|&\\]+$/.test(value);

          // "false" means the transcript for this conversation was already sent
          // (or was never armed by chatOldUser), so neither copy goes out.
          if (clientUser.emailtranscript === "false") {
            console.log(
              "[type 8] emailtranscript is false, not sending any transcript for",
              clientKey,
            );
          } else {
            if (!isPlainEmail(clientUser.email)) {
              console.log(
                "[type 8] skipping transcript email, unusable address:",
                clientUser.email,
              );
            } else {
              const myPayload = {
                fqdn: subdomain,
                to: clientUser.email,
                clientmessagelist: [
                  {
                    messageID: clientUser?.firstChatTimestamp ?? "",
                    member: clientKey,
                  },
                ],
                status: "End Chat",
              };

              console.log("[type 8] Sending payload to API:", myPayload);
              updateChat(myPayload)
                .then((response) => {
                  console.log("API Response Received:", response);
                })
                .catch((error) => {
                  console.error("Execution Error:", error);
                });
            }

            // Admin copy of the transcript — same recipient lookup type 12 uses.
            // `enable` is the operator's on/off switch for this address, written
            // by type 16 as a string; "false" suppresses the admin copy only.
            const emailSettingsKey = `${subdomain}-emailsettings`;
            const emailSettings = await redisClient.hGetAll(emailSettingsKey);
            const emailSettingsAddress = emailSettings?.email;

            if (emailSettings?.enable === "false") {
              console.log(
                "[type 8] emailsettings enable is false, not sending admin copy to",
                emailSettingsAddress,
              );
            } else {
              const adminPayload = {
                fqdn: subdomain,
                to: emailSettingsAddress || "cm@corp.ringq.com",
                clientmessagelist: [
                  {
                    messageID: clientUser?.firstChatTimestamp ?? "",
                    member: clientKey,
                  },
                ],
                status: "Client End Chat",
              };

              console.log(
                "[type 8] Sending admin payload to API:",
                adminPayload,
              );
              updateChat(adminPayload)
                .then((response) => {
                  console.log("API Response Received:", response);
                })
                .catch((error) => {
                  console.error("Execution Error:", error);
                });
            }
          }

          await redisClient.lPush(chatKey, [
            `The user ended the conversation`,
            `${Date.now()}`,
            `rating-Status`,
            `user`,
            `no-content`,
          ]);

          const conversationLogsData = {
            date: `${Date.now()}`,
            username: clientUser.username,
            email: clientUser.email,
            to: clientUser.extAgentName
              ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
              : "",
            messageType: clientUser.messageType,
            conversationsFetch: chatKey,
          };

          const conversationLogsResult = await conversationLogsAdd(
            "client",
            conversationLogsData,
            subdomain,
          );
          console.log(conversationLogsResult);

          const conversationLogsData1 = {
            date: `${Date.now()}`,
            username: clientUser.username,
            email: clientUser.email,
            to: clientUser.extAgentName
              ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
              : "",
            message: `The user ended the conversation`,
          };

          const chatHistoryKeyExtension = `${chatKey}`;
          const conversationLogsExtensionResult =
            await conversationLogsAddExtension(
              "client",
              conversationLogsData1,
              chatHistoryKeyExtension,
            );
          console.log(
            "conversationLogsResult2",
            conversationLogsExtensionResult,
          );

          let assignedAgents;
          if (clientUser.assigned_agent) {
            console.log(
              "this is the assigned type 8",
              clientUser.assigned_agent,
            );
            // the agentUser.assigned is a string with array so we need to parse and use push function
            try {
              assignedAgents = JSON.parse(clientUser.assigned_agent);
              console.log("thisssss", assignedAgents[0]);
              arg.agent = assignedAgents[0];
              console.log("arg.agent", arg.agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedAgents = [];
            }

            assignedAgents = [];
          } else {
            assignedAgents = [];
          }

          await redisClient.lPush(
            `${subdomain}-user_ratings`,
            JSON.stringify(arg),
          );

          await redisClient.hSet(clientKey, {
            agent_unread: "0",
            client_unread: "0",
            allocated_room: "CLOSED",
            ratings: arg.rating,
            // Transcript for this conversation has been dispatched above, so the
            // flag chatOldUser raised when the room was reopened is cleared.
            emailtranscript: "false",
          });

          const clientPresence = `${arg.username}-${arg.email}-website-${arg.id}`;

          await redisClient.zRem(
            `${subdomain}-conversation-active`,
            clientPresence,
          );
          await redisClient.zRem(
            `${subdomain}-conversation-new`,
            clientPresence,
          );
          await redisClient.zRem(
            `${subdomain}-conversation-close`,
            clientPresence,
          );
          await redisClient.zRem(`${subdomain}-conversation`, clientPresence);
          await redisClient.zAdd(`${subdomain}-conversation-close`, {
            score: Date.now(),
            value: `${clientPresence}`,
          });
          await redisClient.zAdd(`${subdomain}-conversation`, {
            score: Date.now(),
            value: `${clientPresence}`,
          });

          let objData = {
            uid: Date.now(),
            socketId: clientUser.socketID,
            username: `rating-Status`,
            email: arg.email,
            name: arg.username,
            useremail: arg.email,
            channel: subdomain,
            time: Date.now(),
            allocated_room: "CLOSED",
            client_unread: "0",
            agent_unread: "0",
            assigned_agent: "",
            roomID: "",
            message: `The user ended the conversation`,
            last_message: "The user ended the conversation",
            messageType: "website",
            id: arg.id,
            status: clientUser.status,
            agentName: clientUser.Agent_Name || "",
            appId: clientUser.appId || "",
            pageId: clientUser.pageId || "",
            ip: clientUser.ip ?? "",
            type: "queue4",
            ratings: `${arg.rating}`,
          };

          socketSignal.emit(
            "chatParameters",
            "queue4",
            objData,
            `${subdomain}-all_messages`,
          );

          // remove all
          await redisClient.zRem(
            `${subdomain}-overallUnreadCount`,
            `unread-${clientKey}`,
          );

          const overallUnreadCount = await redisClient.zCard(
            `${subdomain}-overallUnreadCount`,
          );

          let unassignedUpdateUnread = {
            agent_unread: overallUnreadCount,
          };

          if (clientUser.assigned_agent && clientUser.assigned_agent !== "[]") {
            console.log("has assigned for type 8");

            let assignedAgent = clientUser.assigned_agent;

            if (assignedAgent) {
              try {
                assignedAgent = JSON.parse(assignedAgent);
              } catch (err) {
                console.error("Invalid JSON:", assignedAgent);
                assignedAgent = [];
              }
            }

            await redisClient.hSet(clientKey, {
              assigned_agent: "",
            });
            const firstAgent = assignedAgent[0];
            const userAgent = await redisClient.hGetAll(firstAgent);
            if (!userAgent) {
              console.log("No client found for userKey", userAgent);
              return;
            }

            let assignedList = [];
            if (userAgent.assigned) {
              try {
                const parsed = JSON.parse(userAgent.assigned);
                assignedList = Array.isArray(parsed) ? parsed : [];
              } catch {
                assignedList = [];
              }
            }
            assignedList = assignedList.filter(
              (c) => c.trim() !== clientKey.trim(),
            );
            console.log("this is firstagent ended", firstAgent);
            await redisClient.hSet(firstAgent, {
              assigned: JSON.stringify(assignedList),
            });
            // Add validation for round robin
            // if (assignedList.length == 0) {
            //   console.log("papasok dapat");
            //   await noAssignedChecker(firstAgent, subdomain);
            // }

            //Remove to agent notif
            const unreadAgentKey = `unread-${firstAgent}`;
            const unreadClientKey = `unread-${clientKey}`;
            console.log(
              "pag ka out ni user p2",
              unreadAgentKey,
              unreadClientKey,
            );
            const agentUser = await redisClient.hGetAll(firstAgent);

            await redisClient.zRem(`${unreadAgentKey}`, `${unreadClientKey}`);

            const assignedCount = await redisClient.zCard(`${unreadAgentKey}`);

            unassignedUpdateUnread.total = assignedCount;
            unassignedUpdateUnread.extension = agentUser.extension;

            const overallUnreadCount = await redisClient.zCard(
              `${subdomain}-overallUnreadCount`,
            );
            unassignedUpdateUnread.totalAgent = overallUnreadCount;

            console.log(
              "this is agent user count",
              unassignedUpdateUnread.total,
              unassignedUpdateUnread.extension,
            );

            pushWeb(
              `The user ended the conversation`,
              clientUser.id +
                `-website-` +
                safe(clientUser.username) +
                "-" +
                clientUser.email +
                "-" +
                subdomain,
              subdomain,
              clientUser.username,
              "website",
              "client",
            );

            console.log(
              "pushweb type 8",
              `The user ended the conversation`,
              clientUser.id +
                `-${subdomain}-` +
                safe(clientUser.username) +
                "-" +
                clientUser.email +
                "-" +
                subdomain,
              subdomain,
              clientUser.username,
              "website",
            );

            socketSignal.emit(
              "chatParameters",
              "queue_updatedata",
              unassignedUpdateUnread,
              `${subdomain}-all_messages`,
            );
          } else {
            console.log("no assigned for type 8");
            socketSignal.emit(
              "chatParameters",
              "queue_updatedata",
              unassignedUpdateUnread,
              `${subdomain}-all_messages`,
            );

            pushWeb(
              `The user ended the conversation`,
              clientUser.id +
                `-website-` +
                safe(clientUser.username) +
                "-" +
                clientUser.email +
                "-" +
                subdomain,
              subdomain,
              clientUser.username,
              "website",
              "client",
            );
          }

          console.log("rating success");
        }
        if (arg.about == "status") {
          console.log("this is the status type 8");

          if (arg["user_type"] === "Agent") {
            console.log("checking agent");

            const userKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;
            const user = (await redisClient.exists(userKey))
              ? await redisClient.hGetAll(userKey)
              : null;
            if (!user) {
              return;
            }

            mysocket = user.socketID;
            user.status = "offline";
            user.lastSeen = new Date().toISOString();
            await redisClient.hSet(userKey, {
              status: "offline",
              lastSeen: new Date().toISOString(),
            });
            const response = {
              message: "success",
              socket: mysocket,
              status: "offline",
              email: arg.email,
              username: arg.username,
            };
            socketSignal.emit(
              "chatParameters",
              "get status",
              response,
              mysocket,
            );
            console.log("this is the response", response);
          } else if (arg["user_type"] === "customer") {
            console.log("checking customer");
            const userKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;
            const user = (await redisClient.exists(userKey))
              ? await redisClient.hGetAll(userKey)
              : null;
            if (!user) {
              return;
            }
            const prevSocket = `${user.socketID}`;
            await redisClient.hSet(userKey, {
              status: "offline",
              lastSeen: new Date().toISOString(),
            });
            const response = {
              message: "success",
              socket: mysocket,
              status: "offline",
              email: arg.email,
              username: arg.username,
            };
            socketSignal.emit(
              "chatParameters",
              "get status",
              response,
              mysocket,
            );
            console.log("this is the response", response);
          }
        }
      } catch (err) {
        console.error("error occured in type 8", err);
      }
    }

    //read client // typing
    if (arg["type"] == 9) {
      try {
        console.log("this is type 9");
        const channel = "update notifications";
        const userKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;
        console.log("userKey", userKey);
        const user = (await redisClient.exists(userKey))
          ? await redisClient.hGetAll(userKey)
          : null;
        if (!user) {
          return;
        }
        user.client_unread = 0;
        await redisClient.hSet(userKey, {
          client_unread: 0,
        });

        const response = {
          message: "success",
          socket: mysocket,
          data: JSON.stringify(user),
        };
        const typingPayload = {
          id: arg.id,
          username: arg.username,
          role: "customer",
        };

        socketSignal.emit("chatParameters", channel, response, mysocket);

        const safeParse = (str) => {
          try {
            return JSON.parse(str || "[]");
          } catch {
            return [];
          }
        };

        // 1. Get the array and pull the first ID immediately
        const [agentKey] = safeParse(user.assigned_agent);

        if (agentKey) {
          // 2. Direct call instead of a pipeline
          const agent = await redisClient.hGetAll(agentKey);

          // 3. Logic check and emit
          if (agent?.socketID && agent.roomID !== "all_messages") {
            console.log(`Targeting agent socket: ${agent.socketID}`);
            socketSignal.emit(
              "chatParameters",
              "typing_listener",
              typingPayload,
              agent.socketID,
            );
          }
        }

        socketSignal.emit(
          "chatParameters",
          "typing_listener",
          typingPayload,
          `${subdomain}-all_messages`,
        );
      } catch (err) {
        console.error("error occured in type 9", err);
      }
    }

    // read Agent
    if (arg["type"] == 10) {
      console.log("read logic for type 10");

      try {
        const uniqueId = Date.now();

        console.log("1. validation", arg);
        // ------------------- AGENT KEY -------------------
        const agentKey = arg.agent_key;
        const agentUser = await redisClient.hGetAll(agentKey);
        if (!agentUser || Object.keys(agentUser).length === 0) {
          console.log("no agent found");
          return;
        }

        // ------------------- CLIENT KEY -------------------
        const clientKey = arg.client_key;
        const clientUser = await redisClient.hGetAll(clientKey);
        if (!clientUser || Object.keys(clientUser).length === 0) {
          console.log("no client data found");
          return;
        }

        const assigned = clientUser.assigned_agent;

        let newAgentList = "";

        // CHANGED: Use .includes() because 'assigned' might look like '["Agent-123"]'

        if (assigned?.includes(agentKey)) {
          // Make sure you are deleting the field you intended!
          // If you meant to clear "assigned_agent", change "seen" to "assigned_agent"
        //   await redisClient.hDel(clientKey, "seen");
          const existingSeen = await redisClient.hGet(clientKey, "seen");
          const seenList = existingSeen
            ? existingSeen.split(",").filter(Boolean)
            : [];
          if (!seenList.includes(agentKey)) {
            seenList.push(agentKey);
          }
          newAgentList = seenList.join(",");
          await redisClient.hSet(clientKey, "seen", newAgentList);
          await redisClient.hSet(clientKey, {
            agent_unread: 0,
          });
          console.log("Condition met: Cleared field from Redis.");
          const queueUpdate = {
            uid: uniqueId,
            socketId: clientUser.socketID,
            username: clientUser.username,
            name: clientUser.username,
            useremail: clientUser.email,
            channel: subdomain,
            time: uniqueId,
            allocated_room: clientUser.allocated_room || "ACTIVE",
            client_unread: clientUser.client_unread,
            agent_unread: "0",
            assigned_agent: clientUser.assigned_agent,
            last_message: clientUser.last_message,
            messageType: clientUser.messageType,
            id: clientUser.id,
            status: clientUser.status,
            agentName: agentUser.Agent_Name,
            message: clientUser.message,
            appId: clientUser.appId,
            pageId: clientUser.pageId,
            seen: "",
          };

          await redisClient.zRem(
            `unread-${agentKey}`,
            `unread-${arg.client_key}`,
          );
          console.log(
            `Removed unread entry for client ${arg.client_key} from agent ${agentKey}`,
          );

          const unreadAgentKey = `unread-${agentKey}`;
          const totalUnread = await redisClient.zCard(unreadAgentKey);
          let updateUnread = {
            agent_unread: totalUnread.toString(),
          };
          console.log("totalUnread for agent:", totalUnread);
          console.log("updateUnread object:", updateUnread);

          socketSignal.emit(
            "chatParameters",
            "queue_update",
            queueUpdate,
            `${subdomain}-all_messages`,
          );

          const overallUnreadCount = await redisClient.zCard(
            `${subdomain}-overallUnreadCount`,
          );

          let updateUnread2 = {
            agent_unread: overallUnreadCount,
            zcardlist: `unread-${agentKey}`,
            extension: agentUser?.extension,
            total: totalUnread,
          };

          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            updateUnread2,
            `${subdomain}-all_messages`,
          );
          console.log("owner means affected everyone");
        } else {
          const existingSeen = await redisClient.hGet(clientKey, "seen");
          const seenList = existingSeen
            ? existingSeen.split(",").filter(Boolean)
            : [];
          if (!seenList.includes(agentKey)) {
            seenList.push(agentKey);
          }
          newAgentList = seenList.join(",");
          await redisClient.hSet(clientKey, "seen", newAgentList);
          const queueUpdate = {
            uid: uniqueId,
            socketId: clientUser.socketID,
            username: clientUser.username,
            name: clientUser.username,
            useremail: clientUser.email,
            channel: subdomain,
            time: uniqueId,
            allocated_room: clientUser.allocated_room || "ACTIVE",
            client_unread: clientUser.client_unread,
            agent_unread: "0",
            assigned_agent: clientUser.assigned_agent,
            roomID: agentUser.id,
            last_message: clientUser.last_message,
            messageType: clientUser.messageType,
            id: clientUser.id,
            status: clientUser.status,
            agentName: agentUser.Agent_Name,
            message: clientUser.message,
            appId: clientUser.appId,
            pageId: clientUser.pageId,
            seen: newAgentList,
          };
          socketSignal.emit(
            "chatParameters",
            "queue_update",
            queueUpdate,
            mysocket,
          );
          console.log("single means view himself from everyone");
        }
      } catch (error) {
        console.error("error occurred in type 10", error);
      }
    }

    if (arg["type"] == 11) {
      if (
        !chatKeyOfTenant(arg.clientUserKey, subdomain) ||
        !chatKeyOfTenant(arg.fullagentassignedkey, subdomain) ||
        !Array.isArray(arg.agentKeyList) ||
        !/^[0-9A-Za-z]{1,32}$/.test(String(arg.agentKeyList[0] ?? "")) ||
        (arg.clientmessagelist !== undefined &&
          arg.clientmessagelist !== "" &&
          !chatInboxOfTenant(arg.clientmessagelist, subdomain))
      ) {
        console.log("[type 11] refused: a key does not belong to", subdomain);
        return;
      }
      arg.fqdn = subdomain;
      let finalAllocatedRoom;
      let remChannel;
      let agentUserKey = arg.agentUserkey;
      let appId;
      let pageId;
      let noAssigned;
      let displayMessage;
      let actionMessage = "Transfer to";

      if (arg.defaultChatSound) {
        const validatedChecker = await chatSettingsChecker(
          arg,
          subdomain,
          mysocket,
        );

        if (validatedChecker == 0) {
          return;
        } else if (validatedChecker == 1) {
          console.log("that's okay dear");
        }
      }

      if (arg.take && arg.take !== "") {
        actionMessage = "Taken by";
      }
      console.log("this is type 11", arg);

      // ------------------- CLIENT LOOKUP -------------------

      let clientUserKey = `${arg.clientUserKey}`;
      let clientUser = await redisClient.hGetAll(clientUserKey);

      if (!clientUser || Object.keys(clientUser).length === 0) {
        console.log("No client found for userKey", clientUserKey);
        return;
      }

      const [id, channel, username, email] = clientUserKey.split("-");
      const safeUsername = username.trim().replace(/\s+/g, "/");

      let clientPresence;

      const addedName = `${arg.firstname} ${arg.lastname}`;
      const validateAssignedKey = arg.fullagentassignedkey;

      // ------------------- AGENT LOOKUP -------------------

      const validateAssignedKeyAgent =
        await redisClient.hGetAll(validateAssignedKey);

      if (
        !validateAssignedKeyAgent ||
        Object.keys(validateAssignedKeyAgent).length === 0
      ) {
        console.log("No agent found for key", validateAssignedKey);
        // return;
      }

      console.log("nice", validateAssignedKeyAgent, validateAssignedKey);

      // ------------------- UPDATE EXTENSION -------------------

      const extensionUp = arg.agentKeyList[0];

      console.log("this is the updated");

      await redisClient.hSet(extensionUp, {
        extensionData: arg.fullagentassignedkey,
      });

      // ------------------- MIMIC LOGIN -------------------

      await mimicLogin(validateAssignedKey, arg.fqdn, mysocket, addedName);

      // ------------------- FETCH AGENT DATA -------------------

      let agentKey = await redisClient.hGetAll(validateAssignedKey);

      // ------------------- CHANNEL HANDLING -------------------

      if (channel === "whatsapp") {
        appId = clientUser.appId;
        pageId = clientUser.pageId;

        clientPresence = `${safeUsername}-${id}-${channel}-${email}`;
      } else if (channel === "facebook") {
        appId = clientUser.appId;
        pageId = clientUser.pageId;

        clientPresence = `${safeUsername}-${id}-${channel}-${email}`;
      } else if (channel === "website") {
        appId = clientUser.appId;
        pageId = clientUser.pageId;

        clientPresence = `${safeUsername}-${email}-${channel}-${id}`;
      } else if (channel === "telnyx" || channel === "twilio") {
        appId = clientUser.appId;
        pageId = clientUser.pageId;

        clientPresence = `${safeUsername}-${id}-${channel}-${email}`;
      } else if (channel === "telegram" || channel === "line") {
        appId = clientUser.appId;
        pageId = clientUser.pageId;

        clientPresence = `${safeUsername}-${id}-${channel}-${email}`;
      }

      console.log(
        "this is allocated room",
        clientUser,
        clientUser.allocated_room,
      );

      // ------------------- FACEBOOK / WHATSAPP KEY REBUILD -------------------

      if (channel === "facebook" || channel === "whatsapp") {
        clientUserKey = `${id}-${channel}-${safeUsername}-${email}-${subdomain}`;

        clientUser = await redisClient.hGetAll(clientUserKey);

        console.log("hey");

        if (!clientUser || Object.keys(clientUser).length === 0) {
          console.log("No client found for userKey2111", clientUserKey);
          return;
        }
      }

      console.log("this is current", clientUser, clientUserKey);

      // ------------------- REMOVE FROM ALL CONVERSATION CHANNELS -------------------

      await redisClient.zRem(
        `${subdomain}-conversation-active`,
        clientPresence,
      );

      await redisClient.zRem(`${subdomain}-conversation-new`, clientPresence);

      await redisClient.zRem(`${subdomain}-conversation-close`, clientPresence);

      // ------------------- DETERMINE TARGET CHANNEL -------------------

      let conversationChannel;

      if (arg.setAllocatedRoom === "NEW") {
        conversationChannel = "new";
      } else if (arg.setAllocatedRoom === "ACTIVE") {
        conversationChannel = "active";
      } else if (arg.setAllocatedRoom === "CLOSED") {
        conversationChannel = "close";
      } else {
        conversationChannel = "active";
      }

      // ------------------- ADD CLIENT TO CHANNEL -------------------

      await redisClient.zAdd(
        `${subdomain}-conversation-${conversationChannel}`,
        {
          score: Date.now(),
          value: clientPresence,
        },
      );

      console.log(
        "updated to",
        `${subdomain}-conversation-${conversationChannel}`,
      );

      // ------------------- UPDATE MASTER CONVERSATION -------------------

      await redisClient.zRem(`${subdomain}-conversation`, clientPresence);

      await redisClient.zAdd(`${subdomain}-conversation`, {
        score: Date.now(),
        value: clientPresence,
      });

      console.log("making ready");

      // ------------------- UPDATE ALLOCATED ROOM -------------------

      finalAllocatedRoom = clientUser.allocated_room || "ACTIVE";

      await redisClient.hSet(clientUserKey, {
        allocated_room: "ACTIVE",
      });

      // ------------------- RESET AGENT UNREAD -------------------

      await redisClient.hSet(clientUserKey, {
        agent_unread: 0,
        chatUpdated: Date.now(),
        msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
      });

      console.log("this is nice", clientUserKey);

      const agentAssigning = await redisClient.hGetAll(agentUserKey);
      if (!agentAssigning) {
        return;
      }

      // REMOVING EACH AGENT'S CLIENT
      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        console.log(
          "this is the current agent of client user",
          clientUser.assigned_agent,
        );

        let agentKeys = [];
        try {
          agentKeys = JSON.parse(clientUser.assigned_agent);
        } catch (err) {
          console.log("Invalid assigned_agent JSON", clientUser.assigned_agent);
        }

        if (!Array.isArray(agentKeys)) {
          console.log("assigned_agent is not an array");
          return;
        }

        // DELETING TO THE PREVIOUS AGENT ASSIGNED AND SAVED BACK TO REDIS BEFORE ASSIGING AGAIN
        const agentKey = agentKeys[0]; // Or however you're accessing your single key
        const agentUser = await redisClient.hGetAll(agentKey);

        // 1. Validate the data exists
        if (agentUser && Object.keys(agentUser).length > 0) {
          let agentAssigned = [];

          // 2. Safely parse existing assignments
          try {
            agentAssigned = JSON.parse(agentUser.assigned || "[]");
          } catch (error) {
            console.error("Failed to parse agent assignments:", error);
            agentAssigned = [];
          }

          // 3. Filter out the specific client key
          const filtered = agentAssigned.filter(
            (x) => x.trim() !== clientUserKey.trim(),
          );

          // add validation here for round robin
          // await noAssignedChecker(agentKey, subdomain);

          // 4. Update Redis
          await redisClient.hSet(agentKey, {
            assigned: JSON.stringify(filtered),
          });

          console.log(
            `Updated agent: ${agentKey}. New count: ${filtered.length}`,
          );
        } else {
          console.log("Agent not found:", agentKey);
        }
      } else {
        noAssigned == 1;
      }

      // const messageType = clientUser.messageType;
      let chatKey = arg.clientmessagelist;
      // if(messageType == "website"){
      //  chatKey = `${subdomain}-external-${clientUser.email}-${clientUser.id}`;
      // }
      // else if(messageType == "facebook"){
      // //  channel:919894340047 + email
      //   chatKey = `${channel}:919894340047:${email}`
      // }
      // else if(messageType == "whatsapp"){
      //   chatKey = `${channel}:919894340047:919894340047-${email}`
      // }
      console.log("this is the chatkey", chatKey);

      const agentKeyNewlyAssigned = arg.fullagentassignedkey;
      const newlyAssignedAgentKey = agentKeyNewlyAssigned;

      if (newlyAssignedAgentKey) {
        // 2. Fetch the current 'assigned' string
        const agentUser = await redisClient.hGetAll(newlyAssignedAgentKey);
        let assignedList = [];

        // 3. Parse existing data safely
        if (agentUser.assigned) {
          try {
            const parsed = JSON.parse(agentUser.assigned);
            assignedList = Array.isArray(parsed) ? parsed : [];
          } catch {
            assignedList = [];
          }
        }

        // 4. Add the client if they aren't already in the list
        if (!assignedList.includes(clientUserKey)) {
          assignedList.unshift(clientUserKey);

          console.log("new agent assigned list", assignedList);

          // 5. Save back to Redis
          await redisClient.hSet(newlyAssignedAgentKey, {
            assigned: JSON.stringify(assignedList),
          });
        }

        if (!arg.take) {
          await redisClient.lPush(chatKey, [
            `${actionMessage} ${agentUser.extension}`,
            `${Date.now()}`,
            `${agentAssigning.extension}-Status`,
            `${addedName}`, //`${subdomain}`
            `no-content`,
          ]);

          console.log("this is trunsfer", agentAssigning);

          //
          const conversationLogsData = {
            date: `${Date.now()}`,
            username: `${agentAssigning.Agent_Name} (${agentAssigning.extension})`,
            email: `${agentAssigning.email}`,
            to: clientUser.username ? `${clientUser.username}` : "",
            messageType: clientUser.messageType ?? "",
            conversationsFetch: chatKey,
          };
          const conversationLogsResult = await conversationLogsAdd(
            "agent",
            conversationLogsData,
            subdomain,
          );

          const conversationLogsData1 = {
            date: `${Date.now()}`,
            username: `${agentAssigning.Agent_Name} (${agentAssigning.extension})`,
            email: agentAssigning.email,
            to: clientUser.username ? `${clientUser.username}` : "",
            message: `${actionMessage} ${agentUser.extension}`,
          };

          const chatHistoryKeyExtension = `${chatKey}`;
          const conversationLogsExtensionResult =
            await conversationLogsAddExtension(
              "agent",
              conversationLogsData1,
              chatHistoryKeyExtension,
            );
          console.log(
            "conversationLogsResult2",
            conversationLogsExtensionResult,
          );

          await saveMessageForSearch(
            `${actionMessage} ${agentUser.extension}`,
            `${Date.now()}`,
            `${agentAssigning.Agent_Name}`,
            `${agentAssigning.email}`,
            `agent`, // Pass profile here
            `${Date.now()}`,
            `${clientUser.appId}`,
            `${clientUser.pageId}`,
            chatKey,
            clientUser.messageType ?? "",
            `${clientUserKey}`,
            `${actionMessage} ${agentUser.extension}`,
            `${Date.now()}`,
            `${agentAssigning.extension}-Status`,
            `${agentAssigning.Agent_Name}`, //`${subdomain}`
            `no-content`,
            subdomain,
          );
        }
        // 6. Saving new message transfer to redis

        // 7. Handle the unread count key
        const agentUnreadKey = `unread-${newlyAssignedAgentKey}`;
        console.log("Updated unread count key:", agentUnreadKey);

        const extAgentKey = newlyAssignedAgentKey;
        const extAgentData = await redisClient.hGetAll(extAgentKey);
        const extAgentName = extAgentData.Agent_Name;
        const extAgentExtension = extAgentData.extension;

        // 8. Saving to client
        await redisClient.hSet(clientUserKey, {
          assigned_agent: JSON.stringify([newlyAssignedAgentKey]),
          extAgentName: extAgentName,
          extAgentExtension: extAgentExtension,
          msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
        });

        const existingSeen = await redisClient.hGet(clientUserKey, "seen");
        const seenList = existingSeen
          ? existingSeen.split(",").filter(Boolean)
          : [];
        if (!seenList.includes(agentUserKey)) {
          seenList.push(agentUserKey);
        }
        newAgentList = seenList.join(",");
        await redisClient.hSet(clientUserKey, "seen", newAgentList);

        console.log("assigned_agent updated successfully");

        const uniqueId = Date.now();
        const usernameAgent = `${agentKey.extension}-Status`;

        let objData = {
          uid: uniqueId,
          socketId: agentUser.socketID,
          username: usernameAgent,
          name: clientUser.username,
          useremail: clientUser.email,
          channel: subdomain,
          time: uniqueId,
          allocated_room: "ACTIVE",
          client_unread: clientUser.client_unread,
          agent_unread: "1",
          assigned_agent: JSON.stringify([newlyAssignedAgentKey]),
          roomID: agentUser.id,
          message: clientUser.message,
          last_message: clientUser.last_message,
          messageType: channel,
          id: clientUser.id,
          status: clientUser.status,
          agentName: agentUser.Agent_Name,
          appId: clientUser.appId,
          pageId: clientUser.pageId,
          extension: agentUser.extension,
          agentName: agentUser.Agent_Name,
          type: "11",
          chatUpdated: Date.now(),
          seen: seenList,
          //   error_type: ""
        };

        if (arg.take) {
          objData.take = "taken";
        }

        let objData2 = {
          uid: uniqueId,
          socketId: agentUser.socketID,
          username: `${agentUser.extension}-Status`,
          email: clientUser.email,
          name: clientUser.username,
          useremail: clientUser.email,
          channel: subdomain,
          time: uniqueId,
          allocated_room: "ACTIVE",
          client_unread: clientUser.client_unread,
          // agent_unread: "1",
          assigned_agent: JSON.stringify([newlyAssignedAgentKey]),
          roomID: agentUser.id,
          messageType: channel,
          id: clientUser.id,
          status: clientUser.status,
          agentName: agentUser.Agent_Name,
          appId: clientUser.appId,
          pageId: clientUser.pageId,
          ip: clientUser.ip ?? "",
          type: "queue4",
          ban: clientUser.ip ? true : false,
          chatUpdated: Date.now(),
        };

        // ✅ only add message if arg.take is false
        if (!arg.take) {
          objData2.message = `${actionMessage} ${agentUser.extension}`;
          objData2.last_message = `${actionMessage} ${agentUser.extension}`;
        }

        if (channel == "twilio" || channel == "telnyx") {
          objData2.ourNumber = clientUser.ourNumber;
        }

        // await redisClient.zAdd(`${subdomain}-overallUnreadCount`, {
        //   score: Date.now(),
        //   value: `unread-${clientUserKey}`,
        // });

        const overallUnreadCount = await redisClient.zCard(
          `${subdomain}-overallUnreadCount`,
        );

        const unreadClientOfAgent = `unread-${clientUserKey}`;
        console.log("this is the agent unread", unreadClientOfAgent);

        let prevAgent;
        let newResults;
        let prevResults;
        let agentassignedkey;

        // if (clientUser.assigned_agent) {
        prevAgent = arg.prevagent;
        agentassignedkey = arg.fullagentassignedkey;
        console.log("this is the prevAgent ", prevAgent);

        const fullprevAgent = `unread-${prevAgent}`;
        const fullagentassignedkey = `unread-${agentassignedkey}`;

        await redisClient.zRem(`${fullprevAgent}`, `${unreadClientOfAgent}`);

        console.log(
          "this fullagentassignedkey for first nice msg",
          fullagentassignedkey,
          unreadClientOfAgent,
        );

        // await redisClient.zAdd(`${fullagentassignedkey}`, {
        //   score: Date.now(),
        //   value: `${unreadClientOfAgent}`,
        // });

        prevResults = await redisClient.zCard(`${fullprevAgent}`);
        console.log("full prevresult  ", fullprevAgent);

        console.log("prevResults: ", prevResults);
        newResults = await redisClient.zCard(`${fullagentassignedkey}`);
        console.log("full newResults  ", fullagentassignedkey);
        console.log("newResults: ", newResults);

        const [
          validateExtension,
          validateType,
          validateUsername,
          validateEmail,
          validateFqdn,
        ] = agentassignedkey.split("-");

        const [
          validateExtension1,
          validateType1,
          validateUsername1,
          validateEmail1,
          validateFqdn1,
        ] = prevAgent.split("-");

        displayMessage = `${actionMessage} ${validateExtension}`;

        let updateUnread1 = {
          agent_unread: overallUnreadCount,
          total: newResults,
          zcardlist: `unread-${validateAssignedKey}`,
          fullagentlist: "fullagent",
          extension: validateExtension,
          order: "1st",
        };

        let updateUnread2 = {
          agent_unread: overallUnreadCount,
          total: prevResults,
          zcardlist: `unread-${prevAgent}`,
          prevagent: "yes",
          extension: validateExtension1,
          order: "2st",
        };

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          updateUnread1,
          `${subdomain}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          updateUnread2,
          `${subdomain}-all_messages`,
        );
        // }
        // else{
        //   console.log("dito")
        // }

        socketSignal.emit("chatParameters", "queue4", objData2, subdomain);

        // socketSignal.emit("chatParameters", "queue_update", objData, subdomain);

        if (channel == "website") {
          socketSignal.emit(
            "chatParameters",
            "queue",
            objData,
            clientUser.socketID,
          );
        }
      } else {
        console.log("Notice: No agent found in filteredList.");
      }

      // UPDATING NEW ASSIGNED_AGENT FIELD  FOR CLIENT

      console.log("this is completed for realtime assigning");

      const data = arg.clientUserKey.split("-");
      const [a, b, c, d] = data;
      console.log("this is c", c);

      if (channel == "website") {
        pushWeb(
          displayMessage != null && displayMessage !== ""
            ? displayMessage
            : `${actionMessage} an agent`,
          clientUser.id +
            `-${channel}-` +
            safe(clientUser.username) +
            "-" +
            clientUser.email +
            "-" +
            subdomain,
          subdomain,
          clientUser.username,
          "website",
          "agent",
        );
      } else if (channel == "facebook") {
        pushWeb(
          displayMessage != null && displayMessage !== ""
            ? displayMessage
            : `${actionMessage} an agent`,
          clientUser.id +
            `-${channel}-` +
            safe(c) +
            "-" +
            clientUser.email +
            "-" +
            subdomain,
          subdomain,
          clientUser.username,
          "facebook",
          "agent",
        );
      } else if (channel == "whatsapp") {
        pushWeb(
          displayMessage != null && displayMessage !== ""
            ? displayMessage
            : `${actionMessage} an agent`,
          clientUser.id +
            `-${channel}-` +
            safe(clientUser.username) +
            "-" +
            clientUser.email +
            "-" +
            subdomain,
          subdomain,
          clientUser.referenceName,
          "whatsapp",
          "agent",
        );
      } else if (channel == "telnyx" || channel == "twilio") {
        pushWeb(
          displayMessage != null && displayMessage !== ""
            ? displayMessage
            : `${actionMessage} an agent`,
          clientUser.id +
            `-${channel}-` +
            safe(c) +
            "-" +
            clientUser.email +
            "-" +
            subdomain,
          subdomain,
          clientUser.username,
          channel,
          "agent",
        );
      } else if (channel == "line" || channel == "telegram") {
        console.log("this is tg or line");
        pushWeb(
          displayMessage != null && displayMessage !== ""
            ? displayMessage
            : `${actionMessage} an agent`,
          clientUserKey,
          subdomain,
          clientUser.referenceName,
          channel,
          "agent",
        );
      }

      console.log("compirm");
      console.log(
        clientUser.id +
          `-${channel}-` +
          safe(c) +
          "-" +
          clientUser.email +
          "-" +
          subdomain,
      );
    }

    if (arg["type"] == 12) {
      if (
        !chatKeyOfTenant(arg.clientUserKey, subdomain) ||
        !chatKeyOfTenant(arg.agent_key, subdomain) ||
        (arg.clientmessagelist !== undefined &&
          arg.clientmessagelist !== "" &&
          !chatInboxOfTenant(arg.clientmessagelist, subdomain))
      ) {
        console.log("[type 12] refused: a key does not belong to", subdomain);
        return;
      }
      function updateChat(payload) {
        return new Promise((resolve, reject) => {
          const url = `https://${subdomain}:8443/chatadmin/getassign`;
          const curlArgs = [
            "-s",
            "-X",
            "POST",
            url,
            "-H",
            "Content-Type: application/json",
            "-d",
            JSON.stringify(payload),
          ];

          console.log(
            "[updateChat] POST",
            url,
            "payload:",
            JSON.stringify(payload),
          );

          execFile("curl", curlArgs, (error, stdout, stderr) => {
            if (error) {
              console.error("[updateChat] exec error:", error);
              return reject(error);
            }
            if (stderr) {
              console.log("[updateChat] stderr:", stderr);
            }
            console.log("[updateChat] raw response:", stdout);
            try {
              const parsed = JSON.parse(stdout);
              console.log("[updateChat] parsed response:", parsed);
              resolve(parsed);
            } catch {
              console.log(
                "[updateChat] response was not JSON, returning raw text",
              );
              resolve(stdout);
            }
          });
        });
      }

      console.log("type 12", arg);

      // single timestamp for the whole type-12 op — change here to change all
      const now = Date.now();

      let finalAllocatedRoom;
      let prevAllocatedRoom;
      let remChannel;
      let clientPresence;

      if (arg.defaultChatSound) {
        const validatedChecker = await chatSettingsChecker(
          arg,
          subdomain,
          mysocket,
        );

        if (validatedChecker == 0) {
          console.log("ehem");
          return;
        } else if (validatedChecker == 1) {
          console.log("that's okay dear");
        }
      }

      function sanitizeName(input) {
        return input.replace(/\s+/g, "/");
      }

      const original = arg.clientUserKey;
      const sanitized = sanitizeName(original);

      console.log("this is sanitized", sanitized);

      const [id, channel, username, email] = sanitized.split("-");

      const safeUsername = username.trim().replace(/\s+/g, "/");

      // not sure tho
      // website uses a distinct member layout (email and id swapped); this is
      // load-bearing and must byte-match the producers, so it stays separate.
      // facebook / whatsapp / and every other channel share the same layout.
      if (channel === "website") {
        clientPresence = `${safeUsername}-${email}-${channel}-${id}`;
      } else {
        clientPresence = `${safeUsername}-${id}-${channel}-${email}`;
      }

      let clientUser = (await redisClient.hGetAll(sanitized))
        ? await redisClient.hGetAll(sanitized)
        : null;
      if (!clientUser) {
        console.log("No client found for userKey", sanitized);
        return;
      }

      if (clientUser.allocated_room == "CLOSED") {
        console.log("already closed");
        return;
      }

      let agentUser = (await redisClient.hGetAll(arg.agent_key))
        ? await redisClient.hGetAll(arg.agent_key)
        : null;
      if (!agentUser) {
        console.log("No client found for userKey", arg.agent_key);
        return;
      }

      if (clientUser.allocated_room) {
        if (clientUser.allocated_room === "ACTIVE") {
          remChannel = `${subdomain}-conversation-active`;
          prevAllocatedRoom = "active";
        } else if (clientUser.allocated_room === "NEW") {
          remChannel = `${subdomain}-conversation-new`;
          prevAllocatedRoom = "new";
        } else if (clientUser.allocated_room === "CLOSED") {
          remChannel = `${subdomain}-conversation-close`;
          prevAllocatedRoom = "close";
        } else {
          console.log("Hey theres wrong1212");
        }
      }

      await redisClient.zRem(
        `${subdomain}-conversation-active`,
        clientPresence,
      );
      await redisClient.zRem(`${subdomain}-conversation-new`, clientPresence);
      await redisClient.zRem(`${subdomain}-conversation-close`, clientPresence);

      console.log("this will be remove channel previous", remChannel);

      if (
        arg.setAllocatedRoom === "CLOSED" ||
        arg.setAllocatedRoom === "closed"
      ) {
        finalAllocatedRoom = "close";
      } else if (
        arg.setAllocatedRoom === "NEW" ||
        arg.setAllocatedRoom === "new"
      ) {
        finalAllocatedRoom = "new";
      } else if (
        arg.setAllocatedRoom === "ACTIVE" ||
        arg.setAllocatedRoom === "active"
      ) {
        finalAllocatedRoom = "active";
      }

      console.log("deleted and will be transfer to", finalAllocatedRoom);

      await redisClient.zAdd(
        `${subdomain}-conversation-${finalAllocatedRoom}`,
        {
          score: now,
          value: `${clientPresence}`,
        },
      );

      await redisClient.zRem(`${subdomain}-conversation`, clientPresence);
      await redisClient.zAdd(`${subdomain}-conversation`, {
        score: now,
        value: `${clientPresence}`,
      });

      console.log(
        "bug hunt4",
        `${subdomain}-conversation-${finalAllocatedRoom}`,
      );

      await redisClient.hSet(sanitized, {
        allocated_room: arg.setAllocatedRoom,
        agent_unread: "0",
        msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
      });

      console.log("updated allocated_room");

      const uniqueId = now;
      const usernameAgent = `${agentUser.extension}-Agent`;

      const chatKey = arg.clientmessagelist;

      await redisClient.lPush(chatKey, [
        `This conversation has been resolved by ${agentUser.extension}`,
        `${now}`,
        `${agentUser.extension}-Status`,
        `${agentUser.Agent_Name}`, //`${subdomain}`
        `no-content`,
      ]);

      const conversationLogsData = {
        date: `${now}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: agentUser.email,
        to: clientUser.username ? `${clientUser.username}` : "",
        messageType: clientUser.messageType ?? "",
        conversationsFetch: chatKey,
      };

      const conversationLogsResult = await conversationLogsAdd(
        "agent",
        conversationLogsData,
        subdomain,
      );
      console.log("conversationLogsResult", conversationLogsResult);

      const conversationLogsData1 = {
        date: `${now}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: agentUser.email,
        to: clientUser.username ? `${clientUser.username}` : "",
        message: `This conversation has been resolved by ${agentUser.extension}`,
      };

      const chatHistoryKeyExtension = `${chatKey}`;
      const conversationLogsExtensionResult =
        await conversationLogsAddExtension(
          "agent",
          conversationLogsData1,
          chatHistoryKeyExtension,
        );
      console.log("conversationLogsResult2", conversationLogsExtensionResult);

      await redisClient.zRem(`${subdomain}-overallUnreadCount`, sanitized);

      let overallUnreadCount;

      // Shared by BOTH the assigned_agent and non-assigned paths:
      // removeAgentKey/removeClientKey derivation, the removeClientKey reset
      // hSet, and the saveMessageForSearch index. Written once below; the
      // assigned-only bookkeeping (previous-agent unread cleanup, removing the
      // client from that agent's assigned list, noAssignedChecker, and the
      // queue_ALLAgents emit) stays inside the conditional, and the
      // queue_updatedata emit keeps its branch-specific payload + ordering.
      const primaryAssigned = JSON.parse(clientUser?.assigned_agent || "[]");
      const removeAgentKey = primaryAssigned[0];
      const removeClientKey = arg.clientUserKey;

      const resetClientAssignment = async () => {
        await redisClient.hSet(removeClientKey, {
          assigned_agent: "",
          extAgentName: "",
          extAgentExtension: "",
          chatUpdated: now,
          msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
        });

        await saveMessageForSearch(
          `This conversation has been resolved by ${agentUser.extension}`,
          `${now}`,
          `${agentUser.Agent_Name}`,
          `${agentUser.email}`,
          `agent`, // Pass profile here
          `${now}`,
          `${clientUser.appId}`,
          `${clientUser.pageId}`,
          chatKey,
          clientUser.messageType ?? "",
          arg.clientUserKey,
          `This conversation has been resolved by ${agentUser.extension}`,
          `${now}`,
          `${agentUser.extension}-Status`,
          `${agentUser.Agent_Name}`, //`${subdomain}`
          `no-content`,
          subdomain,
        );
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        let agentKeys = [];
        try {
          agentKeys = JSON.parse(clientUser.assigned_agent);
        } catch (err) {
          console.log("Invalid assigned_agent JSON", clientUser.assigned_agent);
        }

        if (!Array.isArray(agentKeys)) {
          console.log("assigned_agent is not an array");
          return;
        }

        const agentKeyPrev = agentKeys[0]; // Or however you're accessing your single key
        const agentUserPrev = await redisClient.hGetAll(agentKeyPrev);

        const unreadAgentKey = `unread-${agentKeyPrev}`;
        const unreadClientKey = `unread-${sanitized}`;
        const totalUnread = await redisClient.zCard(unreadAgentKey);
        await redisClient.zRem(`${unreadAgentKey}`, `${unreadClientKey}`);

        await redisClient.zRem(
          `${subdomain}-overallUnreadCount`,
          `${unreadClientKey}`,
        );
        overallUnreadCount = await redisClient.zCard(
          `${subdomain}-overallUnreadCount`,
        );

        await redisClient.zRem(`${unreadAgentKey}`, `${unreadClientKey}`);

        const assignedCount = await redisClient.zCard(`${unreadAgentKey}`);

        let updateUnread1 = {
          agent_unread: overallUnreadCount,
          total: assignedCount,
          extension: agentUserPrev.extension,
        };

        let updateUnread = {
          agent_unread: totalUnread,
        };

        socketSignal.emit(
          "chatParameters",
          "queue_ALLAgents",
          updateUnread,
          `${subdomain}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          updateUnread1,
          `${subdomain}-all_messages`,
        );

        ///

        let primaryAssignedUser = (await redisClient.hGetAll(removeAgentKey))
          ? await redisClient.hGetAll(removeAgentKey)
          : null;
        if (!primaryAssignedUser) {
          console.log("No client found for userKey", removeAgentKey);
          return;
        }

        let assignedClients = [];
        try {
          const parsedAssigned = JSON.parse(
            primaryAssignedUser?.assigned || "[]",
          );
          assignedClients = Array.isArray(parsedAssigned) ? parsedAssigned : [];
        } catch {
          assignedClients = [];
        }

        assignedClients = assignedClients.filter(
          (c) => c.trim() !== removeClientKey.trim(),
        );
        console.log("removing clients", assignedClients);
        await redisClient.hSet(removeAgentKey, {
          assigned: JSON.stringify(assignedClients),
        });

        // add validation for round robin
        // if (assignedClients.length === 0) {
        //   console.log("means no assigned now to him");
        //   await noAssignedChecker(removeAgentKey, subdomain);
        // }

        let assignedAgents = [];
        try {
          const parsedAssigned = JSON.parse(clientUser?.assigned_agent || "[]");
          assignedAgents = Array.isArray(parsedAssigned) ? parsedAssigned : [];
        } catch {
          assignedAgents = [];
        }

        assignedAgents = assignedAgents.filter(
          (c) => c.trim() !== removeAgentKey.trim(),
        );

        console.log("removing agent", assignedAgents);

        await resetClientAssignment();
      } else {
        overallUnreadCount = await redisClient.zCard(
          `${subdomain}-overallUnreadCount`,
        );

        let updateUnread1 = {
          agent_unread: overallUnreadCount,
        };

        await resetClientAssignment();

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          updateUnread1,
          `${subdomain}-all_messages`,
        );
      }

      let objData = {
        uid: uniqueId,
        socketId: clientUser.socketID,
        username: `${agentUser.extension}-Status`,
        name: clientUser.username,
        useremail: clientUser.email,
        channel: subdomain,
        time: uniqueId,
        allocated_room: arg.setAllocatedRoom,
        client_unread: clientUser.client_unread,
        agent_unread: "0",
        assigned_agent: "",
        roomID: agentUser.id,
        message: `This conversation has been resolved by ${agentUser.extension}`,
        last_message: `This conversation has been resolved by ${agentUser.extension}`,
        messageType: channel,
        id: clientUser.id,
        status: clientUser.status,
        agentName: agentUser.Agent_Name,
        appId: clientUser.appId,
        pageId: clientUser.pageId,
        extension: agentUser.extension,
        type: "12",
        chatUpdated: now,
      };

      // objData2 is objData with: socketId -> agent socket, +email, +ip,
      // type "queue4" instead of "12", and NO extension field.
      let objData2 = {
        ...objData,
        socketId: agentUser.socketID,
        email: clientUser.email,
        type: "queue4",
        ip: clientUser.ip ?? "",
      };
      delete objData2.extension;

      if (channel == "twilio" || channel == "telnyx") {
        objData2.ourNumber = clientUser.ourNumber || clientUser.id;
        objData.ourNumber = clientUser.ourNumber || clientUser.id;
        console.log("thiashiad", objData2);
      }

      socketSignal.emit("chatParameters", "queue4", objData2, subdomain);
      //  const activeChatsKey = `${subdomain}-activeChats`;

      // await redisClient.sRem(activeChatsKey, sanitized);
      // socketSignal.emit("chatParameters", "queue_update", objData, subdomain);
      if (channel == "website") {
        socketSignal.emit(
          "chatParameters",
          "queue",
          objData,
          clientUser.socketID,
        );
      }

      // The client is the only cc on the transcript email. clientUser.email is
      // not always a real address (twilio/telnyx put a number there, and the
      // widget lets the visitor type it), so it is validated before going into
      // the payload.
      const isPlainEmail = (value) =>
        typeof value === "string" &&
        /^[^\s@'"`$;|&\\]+@[^\s@'"`$;|&\\]+\.[^\s@'"`$;|&\\]+$/.test(value);

      const ccEmail = isPlainEmail(clientUser.email) ? clientUser.email : "";

      if (!ccEmail && clientUser.email) {
        console.log(
          "[type 12] not cc-ing client, unusable email:",
          clientUser.email,
        );
      }

      if (ccEmail && clientUser.email) {
        const myPayload = {
          fqdn: subdomain,
          to: clientUser.email,
          clientmessagelist: [
            {
              messageID: clientUser?.firstChatTimestamp ?? "",
              member: arg.clientUserKey, // Assigns arg.clientUserKey to the 'member' key
            },
          ],
          status: "resolve",
        };
        console.log("mypayload: ", myPayload);
        updateChat(myPayload)
          .then((response) => {
            console.log("API Response Received:", response);

            // getassign echoes back the address it actually cc'd (it drops the cc
            // when it is invalid or identical to the to-address), so log that
            // rather than what we asked for.
          })
          .catch((error) => {
            console.error("Execution Error:", error);
          });
      }

      // `enable` is the operator's on/off switch for this address, written by
      // type 16 as a string; "false" suppresses the admin copy only.
      const emailSettingsKey = `${subdomain}-emailsettings`;
      const emailSettings = await redisClient.hGetAll(emailSettingsKey);
      const emailSettingsAddress = emailSettings?.email;

      if (emailSettings?.enable === "false") {
        console.log(
          "[type 12] emailsettings enable is false, not sending admin copy to",
          emailSettingsAddress,
        );
      } else {
        const myPayload = {
          fqdn: subdomain,
          to: emailSettingsAddress || "cm@corp.ringq.com",
          clientmessagelist: [
            {
              messageID: clientUser?.firstChatTimestamp ?? "",
              member: arg.clientUserKey, // Assigns arg.clientUserKey to the 'member' key
            },
          ],
          status: "resolve",
        };

        // 4. Call the function

        console.log("Sending payload to API:", myPayload);
        updateChat(myPayload)
          .then((response) => {
            console.log("API Response Received:", response);

            // getassign echoes back the address it actually cc'd (it drops the cc
            // when it is invalid or identical to the to-address), so log that
            // rather than what we asked for.
          })
          .catch((error) => {
            console.error("Execution Error:", error);
          });
      }

      // Transcript for this conversation has been dispatched above, so the flag
      // chatOldUser raised when the room was reopened is cleared.
      await redisClient.hSet(sanitized, {
        emailtranscript: "false",
      });
    }

    if (arg["type"] == 13) {
      console.log("arg 13", arg, mysocket);

      const clientKey = arg.client_key;
      const agentKey = arg.agent_key;
      const chatKey = arg.clientmessagelist;
      const ip = arg.ip;
      const assignedAgent = arg.assignedAgent;
      // await redisClient.hSet(clientKey, {
      //   chatUpdated: Date.now()
      // })

      if (arg.defaultChatSound) {
        const validatedChecker = await chatSettingsChecker(
          arg,
          subdomain,
          mysocket,
        );

        if (validatedChecker == 0) {
          return;
        } else if (validatedChecker == 1) {
          console.log("that's okay dear");
        }
      }
      let clientPresence;

      const clientUser = await redisClient.hGetAll(clientKey);

      if (!clientUser) {
        console.log("No client found for userKey", clientKey);
        return;
      }
      console.log("1212");
      const agentUser = (await redisClient.exists(agentKey))
        ? await redisClient.hGetAll(agentKey)
        : null;

      if (!agentUser) {
        console.log("No agent found for userKey", agentKey);
        return;
      }
      console.log("1212123");

      const exists = await redisClient.hGetAll(
        `${subdomain}:webchat:ratelimiting:banned:${ip}`,
      );
      if (exists) {
        console.log("this is still banned");
        await redisClient.del(`${subdomain}:webchat:ratelimiting:banned:${ip}`);

        await redisClient.lPush(chatKey, [
          `User is now unbanned by ${agentUser.extension}`,
          `${Date.now()}`,
          `unban-Status`,
          `${agentUser.extension}`,
          `no-content`,
        ]);
        console.log("12123232");
        const conversationLogsData = {
          date: `${Date.now()}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: agentUser.email,
          to: clientUser.username ? `${clientUser.username}` : "",
          messageType: clientUser.messageType ?? "website",
          conversationsFetch: chatKey,
        };

        const conversationLogsResult = await conversationLogsAdd(
          "agent",
          conversationLogsData,
          subdomain,
        );

        const conversationLogsData1 = {
          date: `${Date.now()}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: agentUser.email,
          to: clientUser.username ? `${clientUser.username}` : "",
          message: `User is now unbanned by ${agentUser.extension}`,
        };

        const chatHistoryKeyExtension = `${chatKey}`;
        const conversationLogsExtensionResult =
          await conversationLogsAddExtension(
            "agent",
            conversationLogsData1,
            chatHistoryKeyExtension,
          );
        console.log("conversationLogsResult2", conversationLogsExtensionResult);

        console.log("conversationLogsResult", conversationLogsResult);

        await redisClient.hDel(clientKey, "ip");

        await redisClient.hSet(clientKey, {
          msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
        });

        await saveMessageForSearch(
          `User is now unbanned by ${agentUser.extension}`,
          `${Date.now()}`,
          `${agentUser.Agent_Name}`,
          `${agentUser.email}`,
          `agent`, // Pass profile here
          `${Date.now()}`,
          `${clientUser.appId}`,
          `${clientUser.pageId}`,
          chatKey,
          clientUser.messageType ?? "",
          arg.clientKey,
          `User is now unbanned by ${agentUser.extension}`,
          `${Date.now()}`,
          `unban-Status`,
          `${agentUser.extension}`,
          `no-content`,
          subdomain,
        );

        let splitted = clientKey.split("-");
        const [id, website, username, email] = splitted;
        clientPresence = `${username}-${email}-${website}-${id}`;

        await redisClient.zRem(`${subdomain}-conversation`, clientPresence);
        await redisClient.zAdd(`${subdomain}-conversation`, {
          score: Date.now(),
          value: clientPresence,
        });

        let objData = {
          uid: Date.now(),
          socketId: clientUser.socketId,
          username: `banned-Status`,
          email: email,
          name: username,
          useremail: email,
          channel: subdomain,
          time: Date.now(),
          allocated_room: clientUser.allocated_room,
          client_unread: clientUser.client_unread,
          agent_unread: clientUser.agent_unread,
          assigned_agent: clientUser.assigned_agent || "",
          roomID: "",
          message: `User is now unbanned by ${agentUser.extension}`,
          last_message: `User is now unbanned by ${agentUser.extension}`,
          messageType: website,
          id: id,
          status: clientUser.status,
          agentName: clientUser.Agent_Name || "",
          appId: clientUser.appId || "",
          pageId: clientUser.pageId || "",
          chatUpdated: Date.now(),
          // ip: ip,
          // ban: false,
        };

        socketSignal.emit(
          "chatParameters",
          "queue4",
          objData,
          `${subdomain}-all_messages`,
        );
        console.log("this is queue 13 success");

        objData.type = "13";
        objData.username = `${agentUser.extension}-Status`;
        console.log("objData.username", objData.username);

        socketSignal.emit(
          "chatParameters",
          "unban",
          objData,
          clientUser.socketID,
        );

        // socketSignal.emit(
        //   "chatParameters",
        //   "unban",
        //   objData,
        //   `${subdomain}-ip-${ip}-currentSocketsConnected`,
        // );

        // socketServerQueue.in(`${subdomain}-ip-${ip}-currentSocketsConnected`).socketsLeave(`${subdomain}-ip-${ip}-currentSocketsConnected`);

        // for client
        // socketSignal.emit("chatParameters", "queue4", objData, subdomain);
      } else {
        console.log("not true");
        await redisClient.lPush(chatKey, [
          `User is now unbanned by ${agentUser.extension}`,
          `${Date.now()}`,
          `unban-Status`,
          `${agentUser.extension}`,
          `no-content`,
        ]);

        await redisClient.hDel(clientKey, "ip");

        let splitted = clientKey.split("-");
        const [id, website, username, email] = splitted;
        clientPresence = `${username}-${email}-${website}-${id}`;

        await redisClient.zRem(`${subdomain}-conversation`, clientPresence);
        await redisClient.zAdd(`${subdomain}-conversation`, {
          score: Date.now(),
          value: clientPresence,
        });

        let objData = {
          uid: Date.now(),
          socketId: clientUser.socketId,
          username: `banned-Status`,
          email: email,
          name: username,
          useremail: email,
          channel: subdomain,
          time: Date.now(),
          allocated_room: clientUser.allocated_room,
          client_unread: clientUser.client_unread,
          agent_unread: clientUser.agent_unread,
          assigned_agent: clientUser.assigned_agent || "",
          roomID: "",
          message: `User is now unbanned by ${agentUser.extension}`,
          last_message: `User is now unbanned by ${agentUser.extension}`,
          messageType: website,
          id: id,
          status: clientUser.status,
          agentName: clientUser.Agent_Name || "",
          appId: clientUser.appId || "",
          pageId: clientUser.pageId || "",
          chatUpdated: Date.now(),
          // ip: ip,
          // ban: false,
        };

        socketSignal.emit(
          "chatParameters",
          "queue4",
          objData,
          `${subdomain}-all_messages`,
        );
        console.log("this is queue 13 success");

        objData.type = "13";
        objData.username = `${agentUser.extension}-Status`;
        console.log("objData.username", objData.username);

        socketSignal.emit(
          "chatParameters",
          "unban",
          objData,
          clientUser.socketID,
        );
      }
    }

    if (arg["type"] == 14) {
      const onlineList = await redisClient.sMembers(
        `${subdomain}-onlineAgents`,
      );

      // 1. Filter out both actual nulls and the string "null"
      const cleanedList = onlineList.filter(
        (agentId) => agentId !== null && agentId !== "null",
      );

      if (cleanedList.length < onlineList.length) {
        // 2. Use a transaction to update the set
        const removedCount = await redisClient.sRem(
          `${subdomain}-onlineAgents`,
          "null",
        );
        if (removedCount > 0) {
          console.log(
            `✅ Cleaned onlineAgents set: Removed ${removedCount} null entry.`,
          );
        } else {
          console.log("ℹ️ No 'null' string found in the set.");
        }

        console.log("✅ Cleaned onlineAgents set:", cleanedList);
      } else {
        console.log("ℹ️ No nulls found, set remains unchanged.");
      }

      const enableOwner = await redisClient.get(
        `chatsettings:${subdomain}-enableOwnerManagerAssign`,
      );
      const enableRoundRobin = await redisClient.get(
        `chatsettings:${subdomain}-enableRoundRobin`,
      );
      const enableEveryAgentQ = await redisClient.get(
        `chatsettings:${subdomain}-enableEveryAgentQ`,
      );
      const defaultChatSound = await redisClient.get(
        `chatsettings:${subdomain}-defaultChatSound`,
      );

      const chatSettingsList = {
        enableOwner: enableOwner,
        enableRoundRobin: enableRoundRobin,
        enableEveryAgentQ: enableEveryAgentQ,
        defaultChatSound: defaultChatSound,
      };

      socketSignal.emit(
        "chatParameters",
        "queueChatSettings",
        chatSettingsList,
        `${subdomain}-all_messages`,
      );

      socketSignal.emit(
        "chatParameters",
        "queueOnline",
        cleanedList,
        `${subdomain}-all_messages`,
      );
    }

    if (arg["type"] == 15) {
      console.log("type 15 setting up", arg);

      const token = arg.token;
      const fqdn = arg.fqdn;
      const extension = arg.extension;

      // 1. Get the User ID (Get this from socket session, auth, or pass it in arg)

      // 2. Construct your dynamic Redis key
      // Existing callers without a platform continue registering Android tokens.
      const platform = String(arg.platform ?? "android").trim().toLowerCase();
      if (platform !== "android" && platform !== "ios") {
        console.error("Unsupported push token platform:", platform);
        return;
      }
      const redisKey = `${platform}:${extension}:${fqdn}`;

      try {
        // 3. Use SET to save it as a plain string
        await redisClient.set(redisKey, token);
        console.log(`Successfully saved token to Redis under key: ${redisKey}`);
      } catch (err) {
        console.error("Redis SET error:", err);
      }
    }

    if (arg["type"] == 16) {
      const redisKey = `${subdomain}-emailsettings`;
      try {
        await redisClient.hSet(redisKey, {
          email: arg.email,
          enable: String(arg.enable), // Redis stores everything as strings
        });

        console.log(`Successfully saved settings to ${redisKey}`);
      } catch (err) {
        console.error(`Failed to save email settings to Redis:`, err);
      }

      // --- AI settings: store under `${subdomain}-AISettings` in the shape aiAutoReply() reads.
      // Reader does: selectedAI = hash.selectedAI; token = hash[selectedAI].
      // So the active provider name goes in `selectedAI`, and each token is stored
      // under the PROVIDER's own field name (`gemini` / `chatgpt`).
      const aiKey = `${subdomain}-AISettings`;
      try {
        const aiFields = {
          selectedAI: String(arg.aiProvider ?? ""), // "gemini" | "chatgpt"
          aiEnable: String(arg.aiEnable), // "true" / "false"
        };
        // Only write a token when one was actually sent, so a blank form field
        // doesn't wipe a previously-saved key.
        if (arg.geminiToken) aiFields.gemini = String(arg.geminiToken);
        if (arg.chatgptToken) aiFields.chatgpt = String(arg.chatgptToken);
        // Custom system prompt ("brain"). Always written: a non-empty value
        // overrides the default systemPrompt in aiAutoReply(); an empty string
        // clears it so the reader falls back to the default.
        aiFields.brain = String(arg.brain ?? "");
        console.log("arg.brain", arg.brain);
        await redisClient.hSet(aiKey, aiFields);
        console.log(`Successfully saved AI settings to ${aiKey}`);
      } catch (err) {
        console.error(`Failed to save AI settings to Redis:`, err);
      }

      // --- Widget builder + plugin contact settings: store under
      // `${subdomain}-chatsettings`. Redis hashes only hold strings, so scalars
      // are coerced with String() and the `buttons` array is JSON-stringified.
      const chatKey = `${subdomain}-chatsettings`;
      try {
        const chatFields = {
          // WordPress / plugin contact email
          pluginEmail: String(arg.pluginEmail ?? ""),
          // Widget builder — FAB button
          fabIcon: String(arg.fabIcon ?? ""),
          fabIconName: String(arg.fabIconName ?? ""),
          fabIconSize: String(arg.fabIconSize ?? ""),
          fabSize: String(arg.fabSize ?? ""),
          fabColor: String(arg.fabColor ?? ""),
          // Widget builder — header
          headerTitle: String(arg.headerTitle ?? ""),
          headerSubtitle: String(arg.headerSubtitle ?? ""),
          headerColor: String(arg.headerColor ?? ""),
          // Widget builder — greetings
          openingGreeting: String(arg.openingGreeting ?? ""),
          menuPrompt: String(arg.menuPrompt ?? ""),
          // Widget builder — option buttons are stored separately in the
          // `${subdomain}-clientQuestions` set (as "emoji label" strings), not here.
          // Widget builder — toggles
          aiEnable: String(arg.aiEnable),
          autoOpen: String(arg.autoOpen),
          showOnlineIndicator: String(arg.showOnlineIndicator),
          showFooter: String(arg.showFooter),
          // Widget placement ("right" / "left") and settings-menu toggle
          position: String(arg.position ?? ""),
          showSettings: String(arg.showSettings),
          // Contact-display channels shown in the widget: a toggle plus the
          // value (number / address) for each
          showPhone: String(arg.showPhone),
          phone: String(arg.phone ?? ""),
          showEmail: String(arg.showEmail),
          contactEmail: String(arg.contactEmail ?? ""),
          showWhatsapp: String(arg.showWhatsapp),
          whatsapp: String(arg.whatsapp ?? ""),
          showSms: String(arg.showSms),
          sms: String(arg.sms ?? ""),
          // Widgets on their OWN custom design (vs the global UI), as a JSON
          // array of ids. Stored here so it rides along with -chatsettings on
          // login (type 3) and on the type-16 echo — no separate key needed.
          disabled: JSON.stringify(
            Array.isArray(arg.disabled) ? arg.disabled : [],
          ),
        };
        await redisClient.hSet(chatKey, chatFields);
        console.log(`Successfully saved chat/widget settings to ${chatKey}`);
      } catch (err) {
        console.error(`Failed to save chat/widget settings to Redis:`, err);
      }

      // --- Widget builder — option buttons: stored in `${subdomain}-clientQuestions`
      // as a SET of "emoji label" strings (e.g. "🤝 Connect me with a sales rep").
      // arg.buttons arrives as [{ emoji, label }, ...]. The set is rebuilt on every
      // save (del + re-add) so edited/removed buttons don't linger.
      const questionsKey = `${subdomain}-clientQuestions`;
      try {
        const questions = (Array.isArray(arg.buttons) ? arg.buttons : [])
          .map((b) =>
            [b?.emoji, b?.label]
              .map((s) => String(s ?? "").trim())
              .filter(Boolean)
              .join(" "),
          )
          .filter((s) => s.length > 0);
        await redisClient.del(questionsKey);
        if (questions.length) {
          await redisClient.sAdd(questionsKey, questions);
        }
        console.log(
          `Successfully saved ${questions.length} client questions to ${questionsKey}`,
        );
      } catch (err) {
        console.error(`Failed to save client questions to Redis:`, err);
      }

      // --- WordPress plugin email: store under `${subdomain}-wordpress-emailsettings`
      // as a hash with an `email` field (e.g.
      // multi.flowersconradplc.ringq.cloud-wordpress-emailsettings -> { email }).
      const wpEmailKey = `${subdomain}-wordpress-emailsettings`;
      try {
        await redisClient.hSet(wpEmailKey, {
          email: String(arg.pluginEmail ?? ""),
        });
        console.log(
          `Successfully saved WordPress email settings to ${wpEmailKey}`,
        );
      } catch (err) {
        console.error(`Failed to save WordPress email settings to Redis:`, err);
      }

      const messagePayload = await redisClient.hGetAll(redisKey);
      const aiMessagePayload = await redisClient.hGetAll(aiKey);
      const chatMessagePayload = await redisClient.hGetAll(chatKey);
      // Echo the option buttons back from the `${subdomain}-clientQuestions` set,
      // rebuilding the { emoji, label } shape by splitting each stored
      // "emoji label" string on its first space (emoji, then wording).
      const questionsRaw = await redisClient.sMembers(questionsKey);
      const buttons = questionsRaw.map((q) => {
        const trimmed = String(q).trim();
        const i = trimmed.indexOf(" ");
        return i === -1
          ? { emoji: "", label: trimmed }
          : { emoji: trimmed.slice(0, i), label: trimmed.slice(i + 1) };
      });
      socketSignal.emit(
        "chatParameters",
        "queue_settings",
        {
          ...messagePayload,
          ...aiMessagePayload,
          ...chatMessagePayload,
          buttons,
        },
        `${subdomain}-all_messages`,
      );
    }

    // --- Type 17: FETCH one widget's saved custom design (no write). Echoed on
    // `queue_unique_settings` so it never overwrites the global queue_settings.
    // Fired when the custom-design pop-up opens.
    if (arg["type"] == 17) {
      // The id scopes which widget's design to read. No id => nothing to fetch.
      if (!arg.id) {
        console.warn("[type 17] missing arg.id — skipping custom design fetch");
        return;
      }

      const customKey = `${subdomain}-${arg.id}`;
      try {
        const customPayload = await redisClient.hGetAll(customKey);
        console.log(
          `Fetched CUSTOM widget design from ${customKey}`,
          customPayload,
        );
        socketSignal.emit(
          "chatParameters",
          "queue_unique_settings",
          {
            ...customPayload,
            buttons: JSON.parse(customPayload.buttons || "[]"),
          },
          mysocket,
        );
      } catch (err) {
        console.error(`Failed to fetch custom widget design from Redis:`, err);
      }
    }

    // --- Type 18: SAVE one widget's custom design under `${subdomain}-${arg.id}`.
    // Same idea as type 16, but scoped to ONE widget. Fired when the pop-up's
    // "Save Custom Design" button is pressed.
    if (arg["type"] == 18) {
      // The id scopes the custom design. No id => nothing to key on, bail early.
      if (!arg.id) {
        console.warn("[type 18] missing arg.id — skipping custom design save");
        return;
      }

      const customKey = `${subdomain}-${arg.id}`;
      try {
        const customFields = {
          id: String(arg.id),
          // Whether this widget uses its custom design (true) or the global one.
          customEnabled: String(arg.customEnabled),
          // Account-level settings (shared with the global config)
          email: String(arg.email ?? ""),
          enable: String(arg.enable),
          pluginEmail: String(arg.pluginEmail ?? ""),
          // AI
          aiProvider: String(arg.aiProvider ?? ""),
          aiEnable: String(arg.aiEnable),
          brain: String(arg.brain ?? ""),
          // Widget builder — FAB button
          fabIcon: String(arg.fabIcon ?? ""),
          fabIconName: String(arg.fabIconName ?? ""),
          fabIconSize: String(arg.fabIconSize ?? ""),
          fabSize: String(arg.fabSize ?? ""),
          fabColor: String(arg.fabColor ?? ""),
          // Widget builder — header
          headerTitle: String(arg.headerTitle ?? ""),
          headerSubtitle: String(arg.headerSubtitle ?? ""),
          headerColor: String(arg.headerColor ?? ""),
          // Widget builder — greetings
          openingGreeting: String(arg.openingGreeting ?? ""),
          menuPrompt: String(arg.menuPrompt ?? ""),
          // Widget builder — toggles
          autoOpen: String(arg.autoOpen),
          showOnlineIndicator: String(arg.showOnlineIndicator),
          showFooter: String(arg.showFooter),
          // Option buttons stored inline as JSON (simpler than a separate set).
          buttons: JSON.stringify(
            Array.isArray(arg.buttons) ? arg.buttons : [],
          ),
        };
        // Only write a token when one was actually sent, so a blank field
        // doesn't wipe a previously-saved key.
        if (arg.geminiToken) customFields.gemini = String(arg.geminiToken);
        if (arg.chatgptToken) customFields.chatgpt = String(arg.chatgptToken);

        await redisClient.hSet(customKey, customFields);
        console.log(`Successfully saved CUSTOM widget design to ${customKey}`);

        // Keep the global `-chatsettings` disabled list in sync: a widget using
        // its custom design is "disabled" from the global UI; turning custom off
        // re-enables it. Read-modify-write the JSON array field.
        const chatKey = `${subdomain}-chatsettings`;
        try {
          const rawDisabled = await redisClient.hGet(chatKey, "disabled");
          let disabledList = [];
          try {
            disabledList = JSON.parse(rawDisabled || "[]");
          } catch (_) {
            disabledList = [];
          }
          if (!Array.isArray(disabledList)) disabledList = [];

          const idStr = String(arg.id);
          if (String(arg.customEnabled) === "true") {
            if (!disabledList.includes(idStr)) disabledList.push(idStr);
          } else {
            disabledList = disabledList.filter((x) => x !== idStr);
          }
          await redisClient.hSet(chatKey, {
            disabled: JSON.stringify(disabledList),
          });
          console.log(
            `Synced ${idStr} in ${chatKey}.disabled (customEnabled=${arg.customEnabled})`,
          );
        } catch (err) {
          console.error(
            `Failed to sync ${subdomain}-chatsettings disabled list:`,
            err,
          );
        }

        // Echo the stored settings back so we can verify the round-trip.
        const customPayload = await redisClient.hGetAll(customKey);
        socketSignal.emit(
          "chatParameters",
          "queue_unique_settings",
          {
            ...customPayload,
            buttons: JSON.parse(customPayload.buttons || "[]"),
          },
          `${subdomain}-all_messages`,
        );
      } catch (err) {
        console.error(`Failed to save custom widget design to Redis:`, err);
      }
    }

    if (arg["type"] == 20) {
      //createion of ios token

      const token = arg.token;
      const fqdn = arg.fqdn;
      const extension = arg.extension;

      // 1. Get the User ID (Get this from socket session, auth, or pass it in arg)

      // 2. Construct your dynamic Redis key
      const redisKey = `ios:${extension}:${fqdn}`;

      try {
        // 3. Use SET to save it as a plain string
        await redisClient.set(redisKey, token);
        console.log(`Successfully saved token to Redis under key: ${redisKey}`);
      } catch (err) {
        console.error("Redis SET error:", err);
      }
    }




    if (arg["type"] == "sessionclosed") {
      function updateChat(payload) {
        return new Promise((resolve, reject) => {
          const url = `https://${subdomain}:8443/chatadmin/getassign`;
          const curlArgs = [
            "-s",
            "-X",
            "POST",
            url,
            "-H",
            "Content-Type: application/json",
            "-d",
            JSON.stringify(payload),
          ];

          console.log(
            "[updateChat] POST",
            url,
            "payload:",
            JSON.stringify(payload),
          );

          execFile("curl", curlArgs, (error, stdout, stderr) => {
            if (error) {
              console.error("[updateChat] exec error:", error);
              return reject(error);
            }
            if (stderr) {
              console.log("[updateChat] stderr:", stderr);
            }
            console.log("[updateChat] raw response:", stdout);
            try {
              const parsed = JSON.parse(stdout);
              console.log("[updateChat] parsed response:", parsed);
              resolve(parsed);
            } catch {
              console.log(
                "[updateChat] response was not JSON, returning raw text",
              );
              resolve(stdout);
            }
          });
        });
      }

      const chatKey = arg.clientmessagelist;
      let timenow = Date.now();
      console.log("session closed", arg);
      let clientPresence;
      clientPresence = `${arg.id}-${arg.messageType}-${arg.username}-${arg.email}-${subdomain}`;
      // const clientUser = await redisClient.hGetAll(clientPresence);
      // console.log("clientUser in session closed", clientUser);
      const clientUser = await redisClient.hGetAll(clientPresence);
      if (!clientUser || Object.keys(clientUser).length === 0) {
        console.log("session closed: no client for key", clientPresence);
      } else {
        console.log(
          "session closed: clientUser",
          clientPresence,
          JSON.stringify(clientUser, null, 2),
        );
      }
      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        let agentKeys = [];
        try {
          agentKeys = JSON.parse(clientUser.assigned_agent);
        } catch (err) {
          console.log("Invalid assigned_agent JSON", clientUser.assigned_agent);
        }

        if (!Array.isArray(agentKeys)) {
          console.log("assigned_agent is not an array");
          return;
        }

        const agentKeyPrev = agentKeys[0]; // Or however you're accessing your single key
        const agentUserPrev = await redisClient.hGetAll(agentKeyPrev);

        const unreadAgentKey = `unread-${agentKeyPrev}`;
        const unreadClientKey = `unread-${clientPresence}`;
        const totalUnread = await redisClient.zCard(unreadAgentKey);
        await redisClient.zRem(`${unreadAgentKey}`, `${unreadClientKey}`);

        await redisClient.zRem(
          `${subdomain}-overallUnreadCount`,
          `${unreadClientKey}`,
        );
        overallUnreadCount = await redisClient.zCard(
          `${subdomain}-overallUnreadCount`,
        );

        await redisClient.zRem(`${unreadAgentKey}`, `${unreadClientKey}`);

        const assignedCount = await redisClient.zCard(`${unreadAgentKey}`);

        let updateUnread1 = {
          agent_unread: overallUnreadCount,
          total: assignedCount,
          extension: agentUserPrev.extension,
        };

        let updateUnread = {
          agent_unread: totalUnread,
        };

        socketSignal.emit(
          "chatParameters",
          "queue_ALLAgents",
          updateUnread,
          `${subdomain}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          updateUnread1,
          `${subdomain}-all_messages`,
        );

        ///

        const primaryAssigned = JSON.parse(clientUser?.assigned_agent || "[]");

        const removeAgentKey = primaryAssigned[0];
        const removeClientKey = clientPresence;

        let primaryAssignedUser = (await redisClient.hGetAll(removeAgentKey))
          ? await redisClient.hGetAll(removeAgentKey)
          : null;
        if (!primaryAssignedUser) {
          console.log("No client found for userKey", removeAgentKey);
          return;
        }

        let assignedClients = [];
        try {
          const parsedAssigned = JSON.parse(
            primaryAssignedUser?.assigned || "[]",
          );
          assignedClients = Array.isArray(parsedAssigned) ? parsedAssigned : [];
        } catch {
          assignedClients = [];
        }

        assignedClients = assignedClients.filter(
          (c) => c.trim() !== removeClientKey.trim(),
        );

        console.log("removing clients", assignedClients);
        await redisClient.hSet(removeAgentKey, {
          assigned: JSON.stringify(assignedClients),
          allocated_room: "CLOSED",
        });

        // add validation for round robin
        // if (assignedClients.length === 0) {
        //   console.log("means no assigned now to him");
        //   await noAssignedChecker(removeAgentKey, subdomain);
        // }

        console.log("removeClientKey ", removeClientKey);

        await redisClient.hSet(removeClientKey, {
          assigned_agent: "",
          extAgentName: "",
          extAgentExtension: "",
          chatUpdated: timenow,
          msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
        });

        console.log("updated ", clientPresence);
      }

      await saveMessageForSearch(
        `This conversation was resolved Offline`,
        `${timenow}`,
        `Offline`,
        `Offline`,
        `agent`, // Pass profile here
        `${timenow}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        chatKey,
        clientUser.messageType ?? "",
        clientPresence,
        `This conversation was resolved Offline`,
        `${timenow}`,
        `None-Status`,
        `Offline`, //`${subdomain}`
        `no-content`,
        subdomain,
      );
      console.log("updating the clientPresence");
      console.log("clientPresence key is ", clientPresence);
      await redisClient.hSet(clientPresence, {
        lastMessage: `This conversation was resolved Offline`,
        message: `This conversation was resolved Offline`,
        allocated_room: "CLOSED",
      });

      let objData2 = {
        uid: clientUser.id,
        username: `None-Status`,
        email: clientUser.email,
        name: clientUser.username,
        useremail: clientUser.email,
        channel: subdomain,
        time: timenow,
        allocated_room: "CLOSED",
        client_unread: clientUser.client_unread,
        agent_unread: "1",
        messageType: clientUser.messageType,
        id: clientUser.id,
        status: clientUser.status,
        appId: clientUser.appId,
        pageId: clientUser.pageId,
        ip: clientUser.ip ?? "",
        ban: clientUser.ip ? true : false,
        chatUpdated: timenow,
        message: `This conversation was resolved Offline`,
      };

      await redisClient.lPush(chatKey, [
        `This conversation was resolved Offline`,
        `${Date.now()}`,
        `None-Status`,
        `None`, //`${subdomain}`
        `no-content`,
      ]);

      // await redisClient.sRem(`${subdomain}-activeChats`, chatKey);

      socketSignal.emit(
        "chatParameters",
        "queue",
        objData2,
        `${subdomain}-all_messages`,
      );

      // getassign requires a `to` (it 400s without one) and matches @clientKey on
      // `member` — clientPresence here is the full client hash key, so it is the
      // right value. The visitor address is validated: clientUser.email is not
      // always a real address (twilio/telnyx put a number there).
      const isPlainEmail = (value) =>
        typeof value === "string" &&
        /^[^\s@'"`$;|&\\]+@[^\s@'"`$;|&\\]+\.[^\s@'"`$;|&\\]+$/.test(value);

      const clientMessageList = [
        {
          messageID: clientUser?.firstChatTimestamp ?? "",
          member: clientPresence,
        },
      ];

      // "false" means the transcript for this conversation was already sent (or
      // was never armed by chatOldUser), so neither copy goes out.
      if (clientUser.emailtranscript === "false") {
        console.log(
          "[sessionclosed] emailtranscript is false, not sending any transcript for",
          clientPresence,
        );
      } else {
        if (!isPlainEmail(clientUser.email)) {
          console.log(
            "[sessionclosed] skipping client transcript, unusable address:",
            clientUser.email,
          );
        } else {
          const myPayload = {
            fqdn: subdomain,
            to: clientUser.email,
            clientmessagelist: clientMessageList,
            status: "offline",
          };

          console.log("[sessionclosed] Sending payload to API:", myPayload);
          updateChat(myPayload)
            .then((response) => {
              console.log("API Response Received:", response);
            })
            .catch((error) => {
              console.error("Execution Error:", error);
            });
        }

        // Admin copy of the transcript — same recipient lookup type 8 / type 12 use.
        // `enable` is the operator's on/off switch for this address, written by
        // type 16 as a string; "false" suppresses the admin copy only.
        const emailSettingsKey = `${subdomain}-emailsettings`;
        const emailSettings = await redisClient.hGetAll(emailSettingsKey);
        const emailSettingsAddress = emailSettings?.email;

        if (emailSettings?.enable === "false") {
          console.log(
            "[sessionclosed] emailsettings enable is false, not sending admin copy to",
            emailSettingsAddress,
          );
        } else {
          const adminPayload = {
            fqdn: subdomain,
            to: emailSettingsAddress || "cm@corp.ringq.com",
            clientmessagelist: clientMessageList,
            status: "offline",
          };

          console.log(
            "[sessionclosed] Sending admin payload to API:",
            adminPayload,
          );
          updateChat(adminPayload)
            .then((response) => {
              console.log("API Response Received:", response);
            })
            .catch((error) => {
              console.error("Execution Error:", error);
            });
        }
      }

      // Transcript for this conversation has been dispatched above, so the flag
      // chatOldUser raised when the room was reopened is cleared.
      await redisClient.hSet(clientPresence, {
        emailtranscript: "false",
      });
    }

    if (arg["type"] === "updatetoken") {
      const agentTokenKey = arg.key;
      const agentTokenValue = arg.token;

      if (!agentTokenKey) {
        console.log("no key");
        return;
      }

      if (!agentTokenValue) {
        console.log("Token is not initialized");
      }

      updatedPushWeb(agentTokenKey, agentTokenValue, subdomain);
    }

    if (arg["type"] === "api") {
      await createSearchIndex(arg.fqdn);

      if (arg["messageType"] === "website") {
      }

      if (arg["messageType"] === "website") {
      }

      if (arg["messageType"] === "telnyx") {
        console.log("validation api website", arg);
        console.log(subdomain);

        socketSignal.emit(
          "chatParameters",
          "queue5",
          {
            id: arg.id,
            name: arg.name,
            time: arg.time,
            message: arg.message,
            username: arg.username,
            agent_unread: arg.agent_unread,
            client_unread: 0,
            assigned_agent: arg.assignedAgents,
            email: arg.ourNumber,
            allocated_room: "ACTIVE",
            last_message: arg.message,
            messageType: "telnyx",
            status: "online",
            appId: arg.appId,
            pageId: arg.pageId,
            useremail: arg.useremail,
            ourNumber: arg.ourNumber,
            chatUpdated: arg.time,
          },
          `${arg.fqdn}-all_messages`,
        );

        return;
      }

      if (arg["messageType"] === "twilio") {
        socketSignal.emit(
          "chatParameters",
          "queue6",
          {
            id: arg.id,
            name: arg.name,
            time: arg.time,
            message: arg.message,
            username: arg.username,
            agent_unread: arg.agent_unread,
            client_unread: 0,
            assigned_agent: arg.assignedAgents,
            email: arg.ourNumber,
            allocated_room: "ACTIVE",
            last_message: arg.message,
            messageType: "telnyx",
            status: "online",
            appId: arg.appId,
            pageId: arg.pageId,
            useremail: arg.useremail,
            ourNumber: arg.ourNumber,
            chatUpdated: arg.time,
          },
          `${arg.fqdn}-all_messages`,
        );
      }

      if (arg["messageType"] === "website") {
      }

      if (arg["messageType"] === "website") {
      }

      if (arg["messageType"] === "website") {
      }

      if (arg["messageType"] === "website") {
      }
    }

    // MUST BE WORKINGG!!
    if (arg["type"] === "search") {
      const searchTerm = arg.searchTerm;
      const page = arg.page || 1; // Get page from frontend request

      if (!searchTerm) {
        socketSignal.emit(
          "chatParameters",
          "searchResponse",
          {
            result: "success",
            content: { allMessages: [], totalResults: 0 },
          },
          mysocket,
        );
        return;
      }

      try {
        // Pass the page to the search function
        const searchResults = await globalSearch(searchTerm, subdomain, page);

        socketSignal.emit(
          "chatParameters",
          "searchResponse",
          {
            result: "success",
            content: searchResults,
          },
          mysocket,
        );
      } catch (err) {
        console.error("Search failed:", err);
        socketSignal.emit(
          "chatParameters",
          "searchResponse",
          {
            result: "error",
            message: err.message,
            content: { allMessages: [], allUsernames: [], totalResults: 0 },
          },
          mysocket,
        );
      }
    }

    if (arg["type"] === "searchFiltered") {
      console.log("saerch filtered", arg);

      const { searchTerm, searchClientKey, page = 1 } = arg;

      if (!searchTerm || !searchClientKey) {
        socketSignal.emit("chatParameters", "searchFilteredResponse", {
          result: "error",
          message: "Missing clientkey or term",
          content: { allMessages: [], totalResults: [] },
        });
      }

      try {
        const searchFilteredResult = await globalSearchFiltered(
          searchTerm,
          searchClientKey,
          subdomain,
          page,
        );
        socketSignal.emit(
          "chatParameters",
          "searchFilteredResponse",
          {
            result: "success",
            content: searchFilteredResult,
          },
          mysocket,
        );
      } catch (er) {
        console.error("Search failed", er);
      }
    }

    if (arg["type"] === "generateUI") {
      const messages = await redisClient.sMembers(
        `${subdomain}-clientQuestions`,
      );
      if (arg.alreadySubmitted) {
        socketSignal.emit(
          "chatParameters",
          "related questions",
          {
            result: "success",
            content: messages,
            bool: true,
          },
          mysocket,
        );
      }
    }

    if (arg["type"] === "queueGenerator") {
      console.log("this is queueGenerator", arg);

      let queue = null;
      let platform = null;
      let redisKey = null;

      if (arg.queue && arg.platform && arg.redisKey) {
        queue = arg.queue || "1144";
        platform = arg.platform || "whatsapp";
        redisKey =
          arg.redisKey ||
          "U899d7ff5a924cd74e52349b3680baed6-line-U899d7ff5a924cd74e52349b3680baed6-e10f2e3929c64d39b9561dad86ddf533";
      }

      if (queue && platform && redisKey) {
        queueGeneratorList(queue, platform, redisKey, subdomain);
      } else {
        console.log("error in queue generator");
      }
    }

    if (arg["type"] === "token") {
      console.log("this is token type", arg);

      if (arg.role === "customer") {
        const clientKey = `${arg.id}-website-${arg.username}-${arg.email}`;
        console.log("client userKey", clientKey);

        const exists = await redisClient.exists(clientKey);
        if (!exists) {
          console.log("Client does not exist in Redis");
          return;
        }

        const clientUser = await redisClient.hGetAll(clientKey);
        if (!clientUser || Object.keys(clientUser).length === 0) {
          console.log("No user data found for key:", clientKey);
          return;
        }

        if (arg.process === "submit") {
          if (clientUser.tokenValidated) {
            if (clientUser.tokenValidated === arg.token) {
              await redisClient.hSet(clientKey, {
                tokenValidated: arg.token,
                tokenEnabled: "true",
              });
              console.log("Token validated and enabled");
              // } else {
              //   console.log("Token already validated");
              // }

              console.log("This is guaranteed");

              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "success",
                },
                clientUser.socketID,
              );

              console.log("this is success");
            } else if (clientUser.tokenValidated !== arg.token) {
              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "failed",
                  reason:
                    "Your old inputted token does not matched when submitting. Please try again",
                },
                clientUser.socketID,
              );
            } else {
              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "failed",
                  reason: "An issue occured",
                },
                clientUser.socketID,
              );
            }
          }

          // this one
          else if (!clientUser.tokenValidated && !clientUser.tokenEnabled) {
            console.log("this oneeee");
            const isValid = await validateOpenAIToken(arg.token);

            if (isValid) {
              await redisClient.hSet(clientKey, {
                tokenValidated: arg.token,
                tokenEnabled: "true",
              });

              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "success",
                },
                clientUser.socketID,
              );
            } else {
              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "failed",
                  reason: "Token is not verified by OpenAi",
                },
                clientUser.socketID,
              );
            }
          }
          // if (!clientUser.tokenValidated) {
          // //Added
        } else if (arg.process === "edit") {
          const clientKey = `${arg.id}-website-${arg.username}-${arg.email}`;
          console.log("client userKey", clientKey);

          const exists = await redisClient.exists(clientKey);
          if (!exists) {
            console.log("Client does not exist in Redis");
            return;
          }

          const clientUser = await redisClient.hGetAll(clientKey);
          if (!clientUser || Object.keys(clientUser).length === 0) {
            console.log("No user data found for key:", clientKey);
            return;
          }

          if (arg.token === clientUser.tokenValidated) {
            const isValid = await validateOpenAIToken(arg.newToken);
            console.log(
              "this is token submitted",
              arg.newToken,
              "the old is",
              arg.token,
            );
            if (isValid) {
              await redisClient.hSet(clientKey, {
                tokenValidated: arg.newToken,
                tokenEnabled: "true",
              });

              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "success",
                },
                clientUser.socketID,
              );

              console.log("Token validated and enabled");
            } else {
              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "failed",
                  reason: "New token is not verified by OpenAi",
                },
                clientUser.socketID,
              );
            }
          } else {
            socketSignal.emit(
              "chatParameters",
              "tokenQueue",
              {
                id: clientUser.id,
                name: clientUser.username,
                token: clientUser.tokenValidated,
                status: "failed",
                reason:
                  "Your old inputted token does not matched when editting. Please try again",
              },
              clientUser.socketID,
            );
          }

          // if (!clientUser.tokenValidated) {

          // } else {
          //   console.log("Token already validated");
          // }

          //Added
        } else if (arg.process === "delete") {
          const clientKey = `${arg.id}-website-${arg.username}-${arg.email}`;
          console.log("client userKey", clientKey);

          const exists = await redisClient.exists(clientKey);
          if (!exists) {
            console.log("Client does not exist in Redis");
            return;
          }

          const clientUser = await redisClient.hGetAll(clientKey);
          if (!clientUser || Object.keys(clientUser).length === 0) {
            console.log("No user data found for key:", clientKey);
            return;
          }

          if (arg.token === clientUser.tokenValidated) {
            const isValid = await validateOpenAIToken(arg.token);

            if (isValid) {
              await redisClient.hDel(
                clientKey,
                "tokenValidated",
                "tokenEnabled",
              );

              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "success",
                  reason: "Deleting token successfully",
                },
                clientUser.socketID,
              );

              console.log("Token validated and enabled");
            } else {
              socketSignal.emit(
                "chatParameters",
                "tokenQueue",
                {
                  id: clientUser.id,
                  name: clientUser.username,
                  token: clientUser.tokenValidated,
                  status: "failed",
                  reason: "Deleting token was unsuccessful",
                },
                clientUser.socketID,
              );
            }
          } else {
            socketSignal.emit(
              "chatParameters",
              "tokenQueue",
              {
                id: clientUser.id,
                name: clientUser.username,
                token: clientUser.tokenValidated,
                status: "failed",
                reason: "Your old inputted token does not matched",
              },
              clientUser.socketID,
            );
          }
        }
      }
    }

    if (arg["type"] === "enableAll") {
      // Define your key (the FQDN/Subdomain)
      const redisKey = `${subdomain}-ALL-CHAT-FIREBASE`;

      // Convert the incoming status to a string "true" or "false"
      // Redis hashes store values as strings.
      const isEnabled = String(arg["enabled"] === true);

      try {
        // HSET <key> <field> <value>
        await redisClient.hSet(redisKey, "all_agents_enabled", isEnabled);
        //         const globalSettings = await redisClient.hGetAll(`${subdomain}-ALL-CHAT-FIREBASE`);
        // enableAll: globalSettings
        console.log(`Updated ${subdomain} enable status to: ${isEnabled}`);
      } catch (error) {
        console.error("Redis HSET error:", error);
      }
    }

    // From client to agent

    // From agent to client
    if (arg["type"] === "showTyping") {
      console.log("this is typingIndicator", arg);

      if (arg.role === "Agent") {
        console.log("this is agent typing", arg);

        const clientKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;

        // Check client existence efficiently
        const exists = await redisClient.exists(clientKey);
        if (!exists) return;

        const user = await redisClient.hGetAll(clientKey);
        if (!user) return;

        const clientId = arg.id || user.id;
        const clientUsername = arg.username || user.username;
        const clientEmail = arg.email || user.email;
        const clientSocketId = user.socketID;

        let objData = {
          message: "success",
          socketId: mysocket,
          id: clientId,
          username: clientUsername,
        };

        // Send typing indicator to the client
        socketSignal.emit(
          "chatParameters",
          "showTyping",
          objData,
          clientSocketId,
        );

        await redisClient.hSet(clientKey, { agent_unread: 0 });

        console.log("this is good", clientSocketId);

        // Hide typing after 3 seconds
        setTimeout(() => {
          socketSignal.emit(
            "chatParameters",
            "hideTyping",
            objData,
            clientSocketId,
          );
        }, 3000);

        // Check if client has assigned agents
        if (user?.assigned_agent && user?.assigned_agent !== "[]") {
          try {
            // 1. Parse the single agent from the JSON array
            const assignedAgents = JSON.parse(user.assigned_agent || "[]");
            const targetAgentKey = assignedAgents[0]; // Take the first (and only) agent

            if (!targetAgentKey) return;

            // 2. Identify the sender
            const senderAgentKey = `${arg.agent_id}-Agent-${arg.agent_username}-${arg.agent_email}-${subdomain}`;

            // Don't send the signal back to the person typing
            if (targetAgentKey === senderAgentKey) return;

            const senderUser = await redisClient.hGet(
              senderAgentKey,
              "Agent_Name",
            );
            if (!senderUser) {
              console.error("Sender not found in Redis");
              return;
            }

            // 3. Fetch target agent details directly (no pipeline needed for 1 agent)
            const agentDetails = await redisClient.hGetAll(targetAgentKey);

            if (agentDetails && agentDetails.roomID !== "all_messages") {
              objData.username = senderUser;

              socketSignal.emit(
                "chatParameters",
                "typing_listener",
                objData,
                `${subdomain}-all_messages`,
              );

              console.log("Typing signal sent to target agent");
            }
          } catch (e) {
            console.error("Error processing assigned agent:", e);
          }
        }
      }
    }

    // if (arg["type"] == "ai") {
    //   console.log("arg ai", arg);

    //   const clientKey = `${arg.id}-website-${arg.username}-${arg.useremail}`;
    //   const clientUser = (await redisClient.exists(clientKey))
    //   ? await redisClient.hGetAll(clientKey)
    //   : null;
    //   if (!clientUser) {
    //   console.log("No client found for userKey", clientKey);
    //   return;
    //   }

    //   const secretKey = arg.tokenValidated || clientUser.tokenValidated;
    //   const reply = await askOpenAI(arg.message, secretKey, sumbrain);
    //   if (!reply) return console.log("No AI response");

    //   console.log("AI reply:", reply);

    //   let agent = arg.agent;

    //   const clientPresence = `${arg.username}-${arg.useremail}-website-${arg.id}`;

    //   await redisClient.zRem(`${subdomain}-conversation`, clientPresence);
    //   await redisClient.zRem(
    //     `${subdomain}-conversation-new`,
    //     clientPresence
    //   );
    //   await redisClient.zAdd(`${subdomain}-conversation`, {
    //     score: Date.now(),
    //     value: clientPresence,
    //   });
    //   await redisClient.zAdd(`${subdomain}-conversation-new`, {
    //     score: Date.now(),
    //     value: clientPresence,
    //   });

    //   if(clientUser.allocated_room){
    //     console.log(`the current allocated room ${clientUser.allocated_room}`);
    //     console.log(`${subdomain}-conversation-close and clientPresence`);
    //     if(clientUser.allocated_room == "CLOSED"){
    //       await redisClient.zRem(
    //         `${subdomain}-conversation-close`,
    //         clientPresence
    //       );
    //       console.log("from close to active");
    //     }
    //   }

    //   const date = Date.now();
    //   const agentProfile = `https://${subdomain}ringq.ai:8443/img/ringq-square.png\\Agent AI`;
    //   const redisKey = `${subdomain}.ringq.ai-external-${arg.useremail}-${arg.id}`;
    //       const pushResult = await redisClient.lPush(redisKey, [
    //         `${reply}`,
    //         `${date}`,
    //         `${agent}-Agent`,
    //         `${agentProfile}`,
    //         `${date}`,
    //       ]);
    //       let messagePayload = {
    //         agentProfile: agentProfile,
    //         // uid: uniqueId,
    //         // socketId: senderSocketId,
    //         username: `${agent}-Agent`,
    //         name: arg.username,
    //         // name: arg.username,
    //         useremail: arg.useremail,
    //         channel: subdomain,
    //         message: reply,
    //         time: Date.now(),
    //         allocated_room: "ACTIVE",
    //         client_unread: Number(clientUser.client_unread) + 1,
    //         agent_unread: 0,
    //         // assigned_agent: clientUser.assigned_agent,
    //         // roomID: agentUser.agentID || agentUser.id,
    //         last_message: reply,
    //         // agentName: displayAgentName,
    //         messageType: "website",
    //         id: arg.id,
    //         status: "online",

    //         }

    //   // -- Agent Lookup --

    //   if (clientUser?.assigned_agent) {
    //     let assignedAgents = [];
    //     try {
    //       assignedAgents = JSON.parse(clientUser.assigned_agent || "[]");

    //       console.log("ssigned", assignedAgents);
    //     } catch (e) {
    //       console.error("Invalid assigned_agent:", e);
    //     }

    //     // Get the agents socket ID and details
    //     // Added Purpose: Once users send a message for the nth time
    //     // Sending it to all agents who are in the my chat category

    //     // Checks all the array in client field named assigned_agent then check each agent userkey's field
    //     // named assigned then remove and add to assigned index 0 again the client's userkey
    //     // Purpose, when user chat the message will go to index 0 in list making it realtime

    //     await Promise.all(
    //       assignedAgents.map(async (agentKey) => {
    //         console.log("promise all");
    //         const agentSocketId = await redisClient.hGet(agentKey, "socketID");
    //         const agentDetails  = await redisClient.hGetAll(agentKey);

    //         let assignedClients = [];
    //         const rawAssigned = agentDetails?.assigned;
    //         if (rawAssigned) {
    //           try {
    //             assignedClients = JSON.parse(rawAssigned);
    //           } catch (err) {
    //             console.error(`Invalid JSON in assigned field for ${agentKey}:`, err);
    //           }
    //         }

    //         // remove
    //         assignedClients = assignedClients.filter(
    //           c => c.trim() !== clientKey.trim()
    //         );

    //         // added to index 0
    //         assignedClients.unshift(clientKey.trim());

    //         await redisClient.hSet(agentKey, {
    //           assigned: JSON.stringify(assignedClients),
    //         });

    //         if (agentDetails?.roomID !== "all_messages"){
    //           socketSignal.emit("chatParameters", "queue", messagePayload, agentSocketId);
    //         }

    //             })
    //           );
    //           socketSignal.emit("chatParameters", "queue", messagePayload,`${subdomain}-all_messages`);
    //         }
    //         else{
    //           socketSignal.emit("chatParameters", "queue", messagePayload,`${subdomain}-all_messages`);
    //         }

    //         let typing = {
    //           message: "success",
    //           socketId:clientUser.socketID,
    //           id: arg.id,
    //           username: arg.username,
    //         }
    //       setTimeout(() => {
    //         socketSignal.emit(
    //           "chatParameters",
    //           "hideTypingAi",
    //           typing,
    //           clientUser.socketID
    //         );
    //         socketSignal.emit(
    //           "chatParameters",
    //           "queue",
    //           messagePayload,
    //           clientUser.socketID
    //         );
    //       }, 3000);

    //       let objData = {
    //         id: arg.id,
    //         username: "Agent AI",
    //         role: "customer",
    //       };

    //       socketSignal.emit(
    //         "chatParameters",
    //         "typing_listener",
    //         objData,
    //         "all_messages"
    //       );

    //     }
  });

  socket.on("telnyxChat", async ({ arg, subdomain, extension, mysocket }) => {
    if (!arg || !mysocket) return;

    let validationtoken = "";
    console.log("arg telnyxchat", arg);

    const { ourNumber, customerNumber, text, fqdn } = arg;
    const clientKey = `${ourNumber}-telnyx-${customerNumber}-${customerNumber}-${fqdn}`;
    const uniqueId = Date.now();

    // 1. Data Retrieval & Verification
    let clientUser = await redisClient.hGetAll(clientKey);
    console.log("clientUser telnyxchat", clientUser.pageId);

    const hasClient = Object.keys(clientUser).length > 0;

    const verified = await verifyToken4(
      "telnyxconfig:sms:config:",
      fqdn,
      { ...arg, mysocket, messageType: "telnyx" },
      clientKey,
      ourNumber,
    );

    if (!verified) {
      console.log("Token verification failed for", arg.url, arg.client_ID);
      arg.status = "failed";
      arg.msg =
        "Your Telnyx configuration appears to be invalid or misconfigured, " +
        "so this message can't be sent. Please check your settings and try again.";

      socketSignal.emit("chatParameters", "queueError", arg, mysocket);
      return;
    }

    console.log("telnyx verified: ", verified);

    const { accessToken, name: appId, id: pageId } = verified;

    let currentMsgIndex = clientUser.msgIndex ? Number(clientUser.msgIndex) : 0;

    // 2. Room Allocation & Unread Logic
    let allocatedRoom = "NEW";
    if (
      hasClient &&
      clientUser.assigned_agent &&
      clientUser.assigned_agent !== "[]"
    ) {
      allocatedRoom =
        clientUser.allocated_room === "CLOSED"
          ? "NEW"
          : clientUser.allocated_room;
    }
    const agentUnread = hasClient
      ? Number(clientUser.agent_unread || 0) + 1
      : 1;

    const time = Date.now();
    // 3. Update Redis Client State
    const clientUpdate = {
      socketID: mysocket,
      allocated_room: allocatedRoom,
      last_message: text,
      message: text,
      messageType: "telnyx",
      agent_unread: agentUnread,
      client_unread: 0,
      fqdn,
      pageId,
      appId,
      accessToken,
      ourNumber,
      chatUpdated: uniqueId,
      ...(hasClient
        ? {}
        : {
            id: ourNumber,
            username: customerNumber,
            created: uniqueId,
            email: customerNumber,
            role: "customer",
            Dept: "customer",
          }),
    };
    await redisClient.hSet(clientKey, clientUpdate);

    // 4. Conversation Logs & History
    const convKey = `${fqdn}:telnyx:${ourNumber}:${ourNumber}-${customerNumber}`;
    await redisClient.lPush(convKey, [
      text,
      `${time}`,
      customerNumber,
      customerNumber,
      `${uniqueId}`,
    ]);

    const logData = {
      date: `${time}`,
      username: customerNumber,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      messageType: clientUser.messageType || "telnyx",
      conversationsFetch: convKey,
    };

    const logData2 = {
      date: `${time}`,
      username: customerNumber,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      message: text,
    };

    (await conversationLogsAdd("client", logData, fqdn),
      await conversationLogsAddExtension("client", logData2, convKey),
      await smsConversationListUpdate(
        `${customerNumber}-${ourNumber}-telnyx-${customerNumber}`,
        allocatedRoom,
        fqdn,
      ));

    currentMsgIndex++;

    await redisClient.hSet(clientKey, {
      msgIndex: currentMsgIndex,
    });

    await saveMessageForSearch(
      `${text}`,
      `${time}`,
      `${customerNumber}`,
      `${customerNumber}`,
      `user`, // Pass profile here
      `${uniqueId}`,
      `${appId}`,
      `${appId}`,
      convKey,
      `telnyx`,
      `${clientKey}`,
      text,
      `${time}`,
      customerNumber,
      customerNumber,
      `${uniqueId}`,
      fqdn,
    );

    // 5. Agent Assignment & Unread Signaling
    let agentList = [];
    try {
      const assigned = clientUser.assigned_agent;
      agentList = Array.isArray(assigned)
        ? assigned
        : JSON.parse(assigned || "[]");
    } catch {
      agentList = [clientUser.assigned_agent].filter(Boolean);
    }

    await redisClient.zAdd(`${fqdn}-overallUnreadCount`, {
      score: uniqueId,
      value: `unread-${clientKey}`,
    });
    const overallUnread = await redisClient.zCard(`${fqdn}-overallUnreadCount`);

    if (agentList.length > 0) {
      const agentKey = agentList[0];
      const agentDetails = await redisClient.hGetAll(agentKey);

      let assigned = JSON.parse(agentDetails.assigned || "[]");
      assigned = [clientKey, ...assigned.filter((c) => c !== clientKey)];

      await redisClient.hSet(agentKey, {
        agent_unread: Number(agentDetails.agent_unread || 0) + 1,
        assigned: JSON.stringify(assigned),
      });

      await redisClient.zAdd(`unread-${agentKey}`, {
        score: uniqueId,
        value: `unread-${clientKey}`,
      });
      const agentCount = await redisClient.zCard(`unread-${agentKey}`);

      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        {
          agent_unread: overallUnread,
          total: agentCount,
          extension: agentDetails.extension,
        },
        `${fqdn}-all_messages`,
      );
    } else {
      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        { agent_unread: overallUnread },
        `${fqdn}-all_messages`,
      );
    }

    const post = {
      to: clientUser.phoneNumber,
      from: arg.customerNumber,
      pageId: clientUser.pageId,
      message: text,
      timestamp: time,
      messageID: uniqueId,
      clientkey: clientKey,
      inboxkey: convKey,
      clientdata: clientUpdate,
      type: "customer",
    };

    let urlwebhook = "";

    const webhookRaw = await redisClient.get(
      `${fqdn}-${clientUser.pageId}-telnyxwebhook`,
    );

    console.log(`stored webhook? ${fqdn}-${clientUser.pageId}-telnyxwebhook`);

    // Parse JSON string into an object (safe parse handling null/invalid JSON)

    let webhookData = {};

    try {
      webhookData = webhookRaw ? JSON.parse(webhookRaw) : {};
    } catch (e) {
      webhookData = {};
    }

    // Optional chaining + nullish coalescing (equivalent to PHP's ?? operator)

    urlwebhook = webhookData?.link ?? "";

    if (urlwebhook) {
      console.log("telnyx sending webhook", urlwebhook);
      try {
        const payload = JSON.stringify(post);

        // Pass payload via stdin to curl using printf/piping so no shell escaping issues occur
        const command = `printf '%s' ${JSON.stringify(payload)} | curl -s -X POST "${urlwebhook}" -H "Content-Type: application/json" -d @-`;

        const { stdout } = await execPromise(command);

        console.log("telnyx webhook response:", stdout);
      } catch (error) {
        console.log("error telnyx post", error.message);
      }
    }

    // 6. Final Emit & Push
    socketSignal.emit(
      "chatParameters",
      "queue5",
      {
        id: ourNumber,
        name: customerNumber,
        from: customerNumber,
        unix: uniqueId,
        time: uniqueId,
        message: text,
        username: clientUser.username || customerNumber,
        agent_unread: agentUnread,
        client_unread: 0,
        assigned_agent: agentList.length ? JSON.stringify(agentList) : "",
        email: customerNumber,
        allocated_room: allocatedRoom,
        last_message: text,
        messageType: "telnyx",
        status: "online",
        appId,
        pageId,
        useremail: customerNumber,
        ourNumber,
        chatUpdated: uniqueId,
      },
      `${fqdn}-all_messages`,
    );

    pushWeb(
      text || "default",
      clientKey,
      fqdn,
      customerNumber,
      "telnyx",
      "client",
    );
  });

  socket.on("communicate5Chat", async (argument) => {
    console.log("Telnyx !--> THIS IS CALLED");

    const { arg, subdomain, extension, mysocket } = argument;
    console.log("this is arg", arg);
    if (
      argument === "undefined" ||
      argument === null ||
      arg === "undefined" ||
      arg === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }

    await createSearchIndex(subdomain);

    if (arg.defaultChatSound) {
      const validatedChecker = await chatSettingsChecker(
        arg,
        subdomain,
        mysocket,
      );

      if (validatedChecker == 0) {
        return;
      } else if (validatedChecker == 1) {
        console.log("that's okay dear");
      }
    }

    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let uniqueId = "";
    let length = 16;

    for (let i = 0; i < length; i++) {
      uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    uniqueId = `${Date.now()}${uniqueId}`;
    const time = `${Date.now()}`;
    const time2 = `${Date.now() + 1}`;
    let uniqueId2 = uniqueId + 1;

    let validate;
    const senderSocketId = mysocket;

    let assignedClients = [];
    let agentHolder = null;

    let finalMessage = "";
    const fqdnValue = arg.url;
    let assignedAgents;
    let myextension = arg.agent_ID;
    let prevAssignedAgent = [];
    // Saved for later
    const telnyxConversationKey = arg.conversationkey;
    const allocatedRoomKey = arg.roomkey;

    const clientKey = arg.client_key;
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }

    let agentKey = "";

    if (arg.prevagent == "") {
      agentKey = arg.agent_key;
    } else {
      agentKey = arg.prevagent;
    }

    const agentUser = await redisClient.hGetAll(agentKey);
    if (!agentUser) {
      console.log("No client found for userKey", agentUser);
      return;
    }

    console.log("this is agent user", agentUser);

    //Start
    if (
      (arg.permission == false && !arg.prevagent) ||
      (arg.permission == true && !arg.prevagent)
    ) {
      console.log("okba");
      if (clientUser.assigned_agent && clientUser.assigned_agent !== null) {
        // Case 1: if client is assigned to agent who is attempting to send message

        if (clientUser.agent_unread) {
          initialAgentUnread = Number(clientUser.agent_unread);
        }

        if (clientUser.assigned_agent) {
          const failedAgentSocket = agentUser.socketID;
          let assignedAgents = [];

          try {
            assignedAgents = JSON.parse(clientUser.assigned_agent);
            if (!Array.isArray(assignedAgents)) assignedAgents = [];
          } catch {
            assignedAgents = [];
          }

          // if (!assignedAgents.includes(agentKey)) {
          // const messagePayload = {
          //   message: "You are not listed as an assigned agent to this conversation. Unable to send a message!",
          //   status: "failed",
          // };

          // socketSignal.emit(
          //   "chatParameters",
          //   "queueError",
          //   messagePayload,
          //   failedAgentSocket
          // );
          // console.log("YOU ARE NOT THE AGENT!!")

          // return;
          // }
        }

        if (clientUser.assigned_agent) {
          validate = true;
          agentHolder = agentUser;
          if (agentUser && agentUser.assigned) {
            try {
              assignedClients = JSON.parse(agentUser.assigned);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedClients = [];
            }
          } else {
            assignedClients = [];
          }

          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            assignedClients = assignedClients.filter(
              (x) => x.trim() !== clientKey.trim(),
            );

            assignedClients.unshift(clientKey.trim());
            console.log("this is the map", assignedClients);
            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          } else {
            console.log("Assigning client to agent...");
            assignedClients.unshift(clientKey);

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });

            console.log("Proceed now to validate part");
          }
        } else {
          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            try {
              assignedClients = JSON.parse(agentUser.assigned || "[]");
            } catch (e) {
              console.error("Failed to parse assigned clients, resetting:", e);
              assignedClients = [];
            }
            assignedClients.unshift(clientKey);
            agentHolder = agentUser;
            validate = true;
          }
        }
      } else {
        noAssigned = 1;
        try {
          assignedClients = JSON.parse(agentUser.assigned || "[]");
        } catch (e) {
          console.error("Failed to parse assigned clients, resetting:", e);
          assignedClients = [];
        }
        assignedClients.unshift(clientKey);
        agentHolder = agentUser;
        validate = true;
      }

      if (arg.prevagent && arg.prevagent !== "") {
        console.log("mahalaga");
        const previousAgentUser = await redisClient.hGetAll(arg.prevagent);
        prevAssignedAgent = JSON.parse(
          previousAgentUser.assigned_agent || "[]",
        );
        prevAssignedAgent = prevAssignedAgent.filter(
          (x) => x.trim() !== clientKey.trim(),
        );

        await redisClient.hSet(arg.prevagent.toString(), {
          assigned: JSON.stringify(prevAssignedAgent || []),
        });
      }
    } else if (
      (arg.permission === true && arg.prevagent) ||
      (arg.permission === false && arg.prevagent)
    ) {
      console.log("1231312312312");
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
          console.log("123131");
        } else {
          assignedAgents.unshift(arg.prevagent);
          console.log("12313133");
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      console.log("Updated agent list:", assignedAgents);
      validate = true;
    }

    clientUser.client_unread =
      parseInt(clientUser.client_unread ?? "0", 10) + 1;
    clientUser.agent_unread = 0;

    if (validate) {
      console.log("comm 5 -> validate");

      // 1. CONSOLIDATED PARSING: Get a clean JS Array
      try {
        assignedAgents =
          typeof clientUser.assigned_agent === "string"
            ? JSON.parse(clientUser.assigned_agent || "[]")
            : clientUser.assigned_agent || [];
      } catch (e) {
        console.error("Failed to parse assigned agents:", e);
        assignedAgents = [];
      }

      // 2. MODIFY ARRAY: Logic for permissions
      if (Array.isArray(assignedAgents)) {
        // Always remove the current agent first to avoid duplicates
        assignedAgents = assignedAgents.filter(
          (x) => x.trim() !== agentKey.trim(),
        );

        if (
          (arg.permission === false && !arg.prevagent) ||
          (arg.permission == true && !arg.prevagent)
        ) {
          // Add the agent to the front of the list
          assignedAgents.unshift(agentKey);
          console.log("agentkeeey", agentKey);
          console.log("Updated agent list:", assignedAgents);
        } else if (
          (arg.permission === true && arg.prevagent) ||
          (arg.permission === false && arg.prevagent)
        ) {
          console.log("1231312312312");
          assignedAgents = [];
          let newAgent = [];
          if (arg.prevagent && arg.prevagent !== "") {
            //clientUser.assigned_agent

            try {
              newAgent = JSON.parse(clientUser.assigned_agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              newAgent = [];
            }

            if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
              assignedAgents.unshift(agentKey);
              console.log("123131");
            } else {
              assignedAgents.unshift(arg.prevagent);
              console.log("12313133");
            }
          } else {
            assignedAgents.unshift(arg.agent_key);
          }

          console.log("Updated agent list:", assignedAgents);
        }
      }

      // 3. LOCK THE STRING: Stringify once for both Redis and Payloads
      console.log("this is it", assignedAgents);
      const assignedAgentsString = JSON.stringify(assignedAgents);
      clientUser.assigned_agent = assignedAgentsString;
      const extAgentKey = assignedAgents[0];
      const extAgentData = await redisClient.hGetAll(extAgentKey);
      const extAgentName = extAgentData.Agent_Name;
      const extAgentExtension = extAgentData.extension;

      // 4. PREPARE PAYLOADS
      let messagePayload;
      let messagePayload2;
      let filePathContainer = [];
      let savedMessage;
      clientUser.last_message = arg.message;
      clientUser.agentName = agentUser.agentName || agentUser.username;
      clientUser.allocated_room = "ACTIVE";

      // 5. UPDATE AGENT'S LIST (If applicable)
      if (
        (arg.permission === false && !arg.prevagent) ||
        (arg.permission == true && !arg.prevagent)
      ) {
        console.log("hi");
        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify([...new Set(assignedClients)]),
        });
      }

      //assignedAgents = []

      // 6. REDIS UPDATE (Message with Files)
      if (arg.filesData !== "" && arg.filesData !== undefined) {
        for (let index = 0; index < arg.filesData.length; index++) {
          const file = arg.filesData[index].name.split(".");
          const type = arg.filesData[index].type;
          const size = arg.filesData[index].size;
          const filetype = file[file.length - 1];
          const uniqueId = Date.now();
          const convertedFileName = arg.filesData[index].name
            .trim()
            .replace(/[-]+/g, " ");
          const fileName = uniqueId + "." + filetype;
          const binaryData = Buffer.from(
            arg.filesData[index].fileBuffer,
            "base64",
          );

          // Save the file locally first
          let localPath = "";
          if (arg.filesData[index].type.startsWith("image")) {
            localPath = `/var/www/webapp/chat_file_upload/telnyx_img/${fileName}`;
          } else {
            localPath = `/var/www/webapp/chat_file_upload/telnyx_file/${fileName}`;
          }

          writeFile(localPath, binaryData, async (err) => {
            if (err) {
              console.log({
                result: "error",
                message: "File upload failed: " + err,
                content: fileName,
              });
              return;
            }

            console.log({
              result: "success",
              message: "File uploaded locally",
              content: fileName,
            });
          });
          if (arg.filesData[index].type.startsWith("image")) {
            filePathContainer.push(
              "chat_file_upload/telnyx_img/" + fileName,
              type,
              size,
            );
          } else {
            filePathContainer.push(
              "chat_file_upload/telnyx_file/" + fileName,
              type,
              size,
            );
          }
          savedMessage = filePathContainer[0];

          await redisClient.lPush(telnyxConversationKey, [
            `${savedMessage}`,
            `${time}`,
            `${myextension}-Agent`,
            `${myextension}-Agent`,
            `${uniqueId}`,
          ]);

          await redisClient.hSet(clientKey.toString(), {
            timestamp: `${time}`,
            last_message: savedMessage?.toString() || "",
            message: savedMessage?.toString() || "",
            isReceived: "0",
            agent_unread: Number(0).toString(),
            client_unread: (
              Number(clientUser.client_unread || 0) + 1
            ).toString(),
            assigned_agent: assignedAgentsString,
            allocated_room: "ACTIVE",
            chatUpdated: `${time}`,
            extAgentExtension: extAgentExtension,
            extAgentName: extAgentName,
          });

          messagePayload2 = {
            // agentProfile: agentProfile,
            uid: uniqueId,
            socketId: senderSocketId,
            username: `${myextension}-Agent`,
            name: clientUser.username,
            useremail: clientUser.email,
            channel: subdomain,
            message: savedMessage,
            time: `${time}`,
            allocated_room: "ACTIVE",
            client_unread: clientUser.client_unread,
            agent_unread: clientUser.agent_unread,
            assigned_agent: assignedAgentsString,
            roomID: agentUser.agentID || agentUser.id,
            last_message: savedMessage,
            // agentName: displayAgentName,
            messageType: "telnyx",
            id: clientUser.id,
            agentName: agentUser.Agent_Name,
            ourNumber: clientUser.ourNumber,
            appId: clientUser.appId,
            pageId: clientUser.pageId,
            chatUpdated: `${time}`,
          };

          console.log("before 2", messagePayload2);

          if (arg.message !== "") {
            await redisClient.hSet(clientKey.toString(), {
              timestamp: `${time2}`,
              last_message: arg.message?.toString() || "",
              message: arg.message.toString() || "",
              isReceived: "0",
              agent_unread: Number(0).toString(),
              client_unread: (
                Number(clientUser.client_unread || 0) + 1
              ).toString(),
              assigned_agent: assignedAgentsString,
              allocated_room: "ACTIVE",
              chatUpdated: `${time2}`,
            });

            await redisClient.lPush(telnyxConversationKey, [
              `${arg.message}`,
              `${time2}`,
              `${myextension}-Agent`,
              `${myextension}-Agent`,
              `${uniqueId2}`,
            ]);

            messagePayload = {
              // agentProfile: agentProfile,
              uid: uniqueId + 1,
              socketId: senderSocketId,
              username: `${myextension}-Agent`,
              name: clientUser.username,
              useremail: clientUser.email,
              channel: subdomain,
              message: arg.message,
              time: time2,
              allocated_room: "ACTIVE",
              client_unread: clientUser.client_unread,
              agent_unread: clientUser.agent_unread,
              assigned_agent: assignedAgentsString,
              roomID: agentUser.agentID || agentUser.id,
              last_message: arg.message,
              // agentName: displayAgentName,
              messageType: "telnyx",
              id: clientUser.id,
              agentName: agentUser.Agent_Name,
              ourNumber: clientUser.ourNumber,
              appId: clientUser.appId,
              chatUpdated: time2,
              pageId: clientUser.pageId,
            };

            console.log("before", messagePayload);
          }
        }
      } else if (!arg.filesData) {
        console.log("this is for message");
        const messageData = {
          message: arg.message,
          time: time,
          sender: `${myextension}-Agent`,
          channel: subdomain,
          uid: uniqueId,
        };

        await redisClient.lPush(telnyxConversationKey, [
          `${arg.message}`,
          `${time}`,
          `${myextension}-Agent`,
          `${myextension}-Agent`,
          `${uniqueId}`,
        ]);

        await redisClient.hSet(clientKey.toString(), {
          timestamp: `${time}`,
          last_message: arg.message?.toString() || "",
          message: arg.message?.toString() || "",
          isReceived: "0",
          agent_unread: Number(0).toString(),
          client_unread: (Number(clientUser.client_unread || 0) + 1).toString(),
          assigned_agent: assignedAgentsString,
          allocated_room: "ACTIVE",
          chatUpdated: `${time}`,
          extAgentName: extAgentName,
          extAgentExtension: extAgentExtension,
        });

        messagePayload = {
          // agentProfile: agentProfile,
          uid: uniqueId,
          socketId: senderSocketId,
          username: `${myextension}-Agent`,
          name: clientUser.username,
          useremail: clientUser.email,
          channel: subdomain,
          message: arg.message,
          time: `${time}`,
          allocated_room: "ACTIVE",
          client_unread: clientUser.client_unread,
          agent_unread: clientUser.agent_unread,
          assigned_agent: assignedAgentsString,
          roomID: agentUser.agentID || agentUser.id,
          last_message: arg.message,
          // agentName: displayAgentName,
          messageType: "telnyx",
          id: clientUser.id,
          agentName: agentUser.Agent_Name,
          ourNumber: clientUser.ourNumber,
          appId: clientUser.appId,
          chatUpdated: `${time}`,
          pageId: clientUser.pageId,
        };
        console.log("before2", messagePayload);
      }

      const conversationUpdateKey = allocatedRoomKey;
      smsConversationListUpdate(
        conversationUpdateKey,
        clientUser.allocated_room,
        arg.url,
      );

      const conversationLogsData = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.username ? `${clientUser.username}` : "",
        messageType: clientUser.messageType ?? "telnyx",
        conversationsFetch: telnyxConversationKey,
      };

      const conversationLogsResult = await conversationLogsAdd(
        "agent",
        conversationLogsData,
        subdomain,
      );
      console.log("conversationLogsResult", conversationLogsResult);

      const conversationLogsDataExtension = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.username ? `${clientUser.username}` : "",
        message: `${arg.message}`,
      };

      const chatHistoryKeyExtension = `${telnyxConversationKey}`;
      const conversationLogsExtensionResult =
        await conversationLogsAddExtension(
          "agent",
          conversationLogsDataExtension,
          chatHistoryKeyExtension,
        );
      console.log("conversationLogsResult2", conversationLogsExtensionResult);

      let currentMsgIndex = clientUser.msgIndex
        ? Number(clientUser.msgIndex)
        : 0;

      // Para sa File
      currentMsgIndex++;

      await redisClient.hSet(clientKey, {
        msgIndex: currentMsgIndex,
      });

      console.log("dito telxnyc");
      await saveMessageForSearch(
        `${arg.message}`,
        `${time}`,
        `${agentUser.Agent_Name}`,
        `${agentUser.email}`,
        `agent`, //`${subdomain}`
        `${uniqueId}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        telnyxConversationKey,
        `telnyx`,
        `${clientKey}`,
        `${arg.message}`,
        `${time}`,
        `${myextension}-Agent`,
        `agent`,
        `${uniqueId}`,
        `${subdomain}`,
      );

      if (Number(clientUser.isReceived == 0)) {
        await redisClient.hSet(agentKey, {
          agent_unread: Number(agentUser.agent_unread) - 1,
        });
      }

      let updateUnread;
      await redisClient.zRem(
        `${subdomain}-overallUnreadCount`,
        `${subdomain}-unread-${clientKey}`,
      );
      const overallUnreadCount = await redisClient.zCard(
        `${subdomain}-overallUnreadCount`,
      );

      let unassignedUpdateUnread = {
        agent_unread: overallUnreadCount,
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        console.log("this is assigned");
        let agentKeyArray = clientUser.assigned_agent;
        let agentKey;

        console.log("raw agentList:", agentKeyArray);
        console.log("type:", typeof agentKeyArray);

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }

        const agentUnreadCount = `unread-${agentKey}`;
        const agentUserPrev = await redisClient.hGetAll(agentKey);

        await redisClient.zRem(`${agentUnreadCount}`, `unread-${clientKey}`);

        const assignedCountResult = await redisClient.zCard(
          `${agentUnreadCount}`,
        );

        unassignedUpdateUnread.total = assignedCountResult;
        unassignedUpdateUnread.extension = agentUserPrev.extension;

        //  const totalUnreadSent = unreadCountAll + updatedUnreadCount;

        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);

        if (agentDetails?.roomID !== "all_messages") {
          console.log("this is the agentsocket id", agentSocketId);
          // if(noAssigned == 1){
          //   socketSignal.emit("chatParameters", "queue", agentStatus, agentSocketId);
          // }

          console.log("1 payload", messagePayload);
          socketSignal.emit(
            "chatParameters",
            "queue5",
            messagePayload,
            agentSocketId,
          );
          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            unassignedUpdateUnread,
            agentSocketId,
          );
          if (
            messagePayload2 != null &&
            Object.keys(messagePayload2).length > 0 !== ""
          ) {
            console.log("2 payload", messagePayload2);
            socketSignal.emit(
              "chatParameters",
              "queue5",
              messagePayload2,
              agentSocketId,
            );
          }
        }

        console.log("duahduahds", fqdnValue, `${fqdnValue}-all_messages`);
        socketSignal.emit(
          "chatParameters",
          "queue5",
          messagePayload,
          `${fqdnValue}-all_messages`,
        );
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );
        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          console.log("dua2313hduahds");
          console.log("2 payload", messagePayload2);
          socketSignal.emit(
            "chatParameters",
            "queue5",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }

        console.log("Emitted to client socket:", clientUser.socketID);
      } else {
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue5",
          messagePayload,
          `${fqdnValue}-all_messages`,
        );

        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue5",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }
      }
      // prepare CURL

      // if (type === "telnyx") {
      //   return {
      //     curlAccessToken: accessTokenChallenge,
      //     curlUrl: "https://api.telnyx.com/v2/messages",
      //     curlFrom: data.phoneNumber,
      //   };
      // } else if (type === "twilio") {
      //   return {
      //     curlAccessToken: 'cd25fcbc495bb4db93666e4c64087848', // static for now
      //     curlUrl: `https://api.twilio.com/2010-04-01/Accounts/${data.accountSid}/Messages.json`,
      //     curlFrom: data.phoneNumber,
      //     curlAccountSid: data.accountSid,
      //     curlMessagingServiceSid: data.MessagingServiceSid,
      //   };
      // }

      const accessTokenChallenge = clientUser.accessToken;
      const curlTo = clientUser.email;
      const curlMessage = arg.message;
      const curlFrom = clientUser.id;
      const curlUrl = `https://api.telnyx.com/v2/messages`;
      console.log(
        "telnyxCurl",
        accessTokenChallenge,
        curlTo,
        curlMessage,
        curlUrl,
        curlFrom,
      );
      await telnyxCurl(
        accessTokenChallenge,
        curlTo,
        curlMessage,
        curlUrl,
        curlFrom,
      );

      let pushWebMessage = arg.message;
      //temp agent
      pushWeb(
        pushWebMessage,
        clientKey,
        subdomain,
        agentUser.Agent_Name,
        "telnyx",
        "agent",
      );
    }
  });

  socket.on("twilioChat", async ({ arg, subdomain, extension, mysocket }) => {
    if (!arg || !mysocket) return;

    const {
      ourNumber,
      customerNumber,
      text,
      fqdn,
      accountSid,
      messagingServiceSid,
    } = arg;
    const clientKey = `${ourNumber}-twilio-${customerNumber}-${customerNumber}-${fqdn}`;
    const uniqueId = Date.now();

    // 1. Data Retrieval & Verification

    let clientUser = await redisClient.hGetAll(clientKey);

    const hasClient = Object.keys(clientUser).length > 0;

    const verified = await verifyToken4(
      "twilioconfig:sms:config:",
      fqdn,
      { ...arg, mysocket, messageType: "twilio" },
      clientKey,
      accountSid,
      messagingServiceSid,
      ourNumber,
    );
    if (!verified) {
      console.log("Token verification failed for", arg.url, arg.client_ID);

      arg.status = "failed";
      arg.msg =
        "Your Twilio configuration appears to be invalid or misconfigured, " +
        "so this message can't be sent. Please check your settings and try again.";

      socketSignal.emit("chatParameters", "queueError", arg, mysocket);
      return;
    }
    const { accessToken, name: appId, id: pageId } = verified;

    let currentMsgIndex = clientUser.msgIndex ? Number(clientUser.msgIndex) : 0;

    // 2. Room Allocation & Unread Logic
    let allocatedRoom = "NEW";
    if (
      hasClient &&
      clientUser.assigned_agent &&
      clientUser.assigned_agent !== "[]"
    ) {
      allocatedRoom =
        clientUser.allocated_room === "CLOSED"
          ? "NEW"
          : clientUser.allocated_room;
    }
    const agentUnread = hasClient
      ? Number(clientUser.agent_unread || 0) + 1
      : 1;

    // 3. Consolidated Redis State Update
    const clientUpdate = {
      socketID: mysocket,
      allocated_room: allocatedRoom,
      last_message: text,
      message: text,
      messageType: "twilio",
      agent_unread: agentUnread,
      client_unread: 0,
      fqdn,
      number: pageId,
      pageId,
      appId,
      accessToken,
      ourNumber,
      chatUpdated: uniqueId,
      ...(hasClient
        ? {}
        : {
            id: ourNumber,
            username: customerNumber,
            created: uniqueId,
            email: customerNumber,
            role: "customer",
            Dept: "customer",
          }),
    };
    await redisClient.hSet(clientKey, clientUpdate);
    const time = Date.now();
    // 4. Logs and Conversation History
    const convKey = `${fqdn}:twilio:${ourNumber}:${ourNumber}-${customerNumber}`;
    await redisClient.lPush(convKey, [
      text,
      `${time}`,
      customerNumber,
      customerNumber,
      `${uniqueId}`,
    ]);

    console.log(
      "THIS IS INSERTED TO LRANGE FOR TWILIO",
      text,
      `${time}`,
      customerNumber,
      customerNumber,
      pageId,
    );

    const logData = {
      date: `${time}`,
      username: customerNumber,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      messageType: clientUser.messageType || "twilio",
      conversationsFetch: convKey,
    };

    const logData2 = {
      date: `${time}`,
      username: customerNumber,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      message: text,
    };

    (await conversationLogsAdd("client", logData, fqdn),
      await conversationLogsAddExtension("client", logData2, convKey),
      await smsConversationListUpdate(
        `${customerNumber}-${ourNumber}-twilio-${customerNumber}`,
        allocatedRoom,
        fqdn,
      ));

    currentMsgIndex++;

    await redisClient.hSet(clientKey, {
      msgIndex: currentMsgIndex,
    });

    console.log(
      "before sending twil",

      `${text}`,
      `${time}`,
      `${customerNumber}`,
      `${customerNumber}`,
      `user`, // Pass profile here
      `${uniqueId}`,
      `${appId}`,
      `${appId}`,
      convKey,
      `twilio`,
      `${clientKey}`,
      text,
      `${time}`,
      customerNumber,
      customerNumber,
      `${uniqueId}`,
      fqdn,
    );

    await saveMessageForSearch(
      `${text}`,
      `${time}`,
      `${customerNumber}`,
      `${customerNumber}`,
      `user`, // Pass profile here
      `${uniqueId}`,
      `${appId}`,
      `${appId}`,
      convKey,
      `twilio`,
      `${clientKey}`,
      text,
      `${time}`,
      customerNumber,
      customerNumber,
      `${uniqueId}`,
      fqdn,
    );

    // 5. Agent Assignment & Unread Logic
    let agentList = [];
    try {
      const assigned = clientUser.assigned_agent;
      agentList = Array.isArray(assigned)
        ? assigned
        : JSON.parse(assigned || "[]");
    } catch {
      agentList = [clientUser.assigned_agent].filter(Boolean);
    }

    await redisClient.zAdd(`${fqdn}-overallUnreadCount`, {
      score: uniqueId,
      value: `unread-${clientKey}`,
    });
    const overallUnread = await redisClient.zCard(`${fqdn}-overallUnreadCount`);

    if (agentList.length > 0) {
      const agentKey = agentList[0];
      const agentDetails = await redisClient.hGetAll(agentKey);

      let assigned = JSON.parse(agentDetails.assigned || "[]");
      assigned = [clientKey, ...assigned.filter((c) => c !== clientKey)];

      await redisClient.hSet(agentKey, {
        agent_unread: Number(agentDetails.agent_unread || 0) + 1,
        assigned: JSON.stringify(assigned),
      });

      await redisClient.zAdd(`unread-${agentKey}`, {
        score: uniqueId,
        value: `unread-${clientKey}`,
      });
      const agentCount = await redisClient.zCard(`unread-${agentKey}`);

      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        {
          agent_unread: overallUnread,
          total: agentCount,
          extension: agentDetails.extension,
        },
        `${fqdn}-all_messages`,
      );
    } else {
      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        { agent_unread: overallUnread },
        `${fqdn}-all_messages`,
      );
    }

    const post = {
      to: clientUser.phoneNumber,
      from: fqdn,
      pageId: clientUser.pageId,
      message: text,
      timestamp: time,
      messageID: uniqueId,
      clientkey: clientKey,
      inboxkey: convKey,
      clientdata: clientUpdate,
      type: "customer",
    };

    let urlwebhook = "";

    const webhookRaw = await redisClient.get(
      `${fqdn}-${clientUser.pageId}-twiliowebhook`,
    );

    console.log(`stored webhook? ${fqdn}-${clientUser.pageId}-twiliowebhook`);

    // Parse JSON string into an object (safe parse handling null/invalid JSON)

    let webhookData = {};

    try {
      webhookData = webhookRaw ? JSON.parse(webhookRaw) : {};
    } catch (e) {
      webhookData = {};
    }

    // Optional chaining + nullish coalescing (equivalent to PHP's ?? operator)

    urlwebhook = webhookData?.link ?? "";

    if (urlwebhook) {
      console.log("twilio sending webhook", urlwebhook);
      try {
        const payload = JSON.stringify(post);

        // Pass payload via stdin to curl using printf/piping so no shell escaping issues occur
        const command = `printf '%s' ${JSON.stringify(payload)} | curl -s -X POST "${urlwebhook}" -H "Content-Type: application/json" -d @-`;

        const { stdout } = await execPromise(command);

        console.log("telnyx webhook response:", stdout);
      } catch (error) {
        console.log("error telnyx post", error.message);
      }
    }

    // 6. Final Emit & Push
    socketSignal.emit(
      "chatParameters",
      "queue6",
      {
        id: ourNumber,
        name: customerNumber,
        from: customerNumber,
        unix: uniqueId,
        time: uniqueId,
        message: text,
        username: clientUser.username || customerNumber,
        agent_unread: agentUnread,
        client_unread: 0,
        assigned_agent: agentList.length ? JSON.stringify(agentList) : "",
        email: customerNumber,
        allocated_room: allocatedRoom,
        last_message: text,
        messageType: "twilio",
        status: "online",
        appId,
        pageId,
        useremail: customerNumber,
        ourNumber,
        chatUpdated: uniqueId,
      },
      `${fqdn}-all_messages`,
    );

    pushWeb(
      safe(text) || "default",
      clientKey,
      fqdn,
      customerNumber,
      "twilio",
      "client",
    );
  });

  socket.on("communicate6Chat", async (argument) => {
    console.log("Twilio !--> THIS IS CALLED");

    const { arg, subdomain, extension, mysocket } = argument;
    console.log("this is arg", arg);
    if (
      argument === "undefined" ||
      argument === null ||
      arg === "undefined" ||
      arg === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }
    await createSearchIndex(subdomain);

    if (arg.defaultChatSound) {
      const validatedChecker = await chatSettingsChecker(
        arg,
        subdomain,
        mysocket,
      );

      if (validatedChecker == 0) {
        return;
      } else if (validatedChecker == 1) {
        console.log("that's okay dear");
      }
    }

    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let uniqueId = "";
    let length = 16;

    for (let i = 0; i < length; i++) {
      uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    uniqueId = `${Date.now()}${uniqueId}`;
    const time = `${Date.now()}`;

    const time2 = `${time} + 1`;

    let uniqueId2 = `${uniqueId} + 1`;

    let validate;
    const senderSocketId = mysocket;
    let assignedAgent = [];
    let assignedClients = [];
    let agentHolder = null;
    let prevAssignedAgent = [];
    let finalMessage = "";
    const fqdnValue = arg.url;
    let assignedAgents;
    let myextension = arg.agent_ID;

    // Saved for later
    const twilioConversationKey = `${arg.conversationkey}`;
    const allocatedRoomKey = arg.roomkey;

    //temporary
    const clientKey = `${arg.client_key}`;
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }

    let agentKey = arg.agent_key;

    // if(arg.permission == false){
    //   if(arg.prevagent == "" || !arg.prevagent){
    //     agentKey = arg.agentKey;
    //   }
    //   else{
    //     agentKey = arg.prevagent;
    //   }
    // }
    // else if(arg.permission == true){
    //   if(arg.prevagent == "" || !arg.prevagent){
    //     agentKey = arg.agentKey;
    //   }
    //   else{
    //     agentKey = arg.prevagent;
    //   }
    // }

    const agentUser = await redisClient.hGetAll(agentKey);
    if (!agentUser) {
      console.log("No client found for userKey", agentUser);
      return;
    }
    console.log("this is agent user", agentUser);

    //Start
    if (
      (arg.permission == false && !arg.prevagent) ||
      (arg.permission == true && !arg.prevagent)
    ) {
      console.log("okba");
      if (clientUser.assigned_agent && clientUser.assigned_agent !== null) {
        // Case 1: if client is assigned to agent who is attempting to send message

        if (clientUser.agent_unread) {
          initialAgentUnread = Number(clientUser.agent_unread);
        }

        if (clientUser.assigned_agent) {
          const failedAgentSocket = agentUser.socketID;
          let assignedAgents = [];

          try {
            assignedAgents = JSON.parse(clientUser.assigned_agent);
            if (!Array.isArray(assignedAgents)) assignedAgents = [];
          } catch {
            assignedAgents = [];
          }

          // if (!assignedAgents.includes(agentKey)) {
          // const messagePayload = {
          //   message: "You are not listed as an assigned agent to this conversation. Unable to send a message!",
          //   status: "failed",
          // };

          // socketSignal.emit(
          //   "chatParameters",
          //   "queueError",
          //   messagePayload,
          //   failedAgentSocket
          // );
          // console.log("YOU ARE NOT THE AGENT!!")

          // return;
          // }
        }

        if (clientUser.assigned_agent) {
          validate = true;
          agentHolder = agentUser;
          if (agentUser && agentUser.assigned) {
            try {
              assignedClients = JSON.parse(agentUser.assigned);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedClients = [];
            }
          } else {
            assignedClients = [];
          }

          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            assignedClients = assignedClients.filter(
              (x) => x.trim() !== clientKey.trim(),
            );

            assignedClients.unshift(clientKey.trim());
            console.log("this is the map", assignedClients);
            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          } else {
            console.log("Assigning client to agent...");
            assignedClients.unshift(clientKey);

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });

            console.log("Proceed now to validate part");
          }
        } else {
          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            try {
              assignedClients = JSON.parse(agentUser.assigned || "[]");
            } catch (e) {
              console.error("Failed to parse assigned clients, resetting:", e);
              assignedClients = [];
            }
            assignedClients.unshift(clientKey);
            agentHolder = agentUser;
            validate = true;
          }
        }
      } else {
        noAssigned = 1;
        try {
          assignedClients = JSON.parse(agentUser.assigned || "[]");
        } catch (e) {
          console.error("Failed to parse assigned clients, resetting:", e);
          assignedClients = [];
        }
        assignedClients.unshift(clientKey);
        agentHolder = agentUser;
        validate = true;
      }

      if (arg.prevagent && arg.prevagent !== "") {
        console.log("mahalaga");
        const previousAgentUser = await redisClient.hGetAll(arg.prevagent);
        prevAssignedAgent = JSON.parse(
          previousAgentUser.assigned_agent || "[]",
        );
        prevAssignedAgent = prevAssignedAgent.filter(
          (x) => x.trim() !== clientKey.trim(),
        );

        await redisClient.hSet(arg.prevagent.toString(), {
          assigned: JSON.stringify(prevAssignedAgent || []),
        });
      }
    } else if (
      (arg.permission === true && arg.prevagent) ||
      (arg.permission === false && arg.prevagent)
    ) {
      console.log("1231312312312");
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
          console.log("123131");
        } else {
          assignedAgents.unshift(arg.prevagent);
          console.log("12313133");
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      console.log("Updated agent list:", assignedAgents);
      validate = true;
    }

    clientUser.client_unread =
      parseInt(clientUser.client_unread ?? "0", 10) + 1;
    clientUser.agent_unread = 0;

    if (validate) {
      console.log("comm 6 -> validate");

      // 1. CONSOLIDATED PARSING: Get a clean JS Array
      try {
        assignedAgents =
          typeof clientUser.assigned_agent === "string"
            ? JSON.parse(clientUser.assigned_agent || "[]")
            : clientUser.assigned_agent || [];
      } catch (e) {
        console.error("Failed to parse assigned agents:", e);
        assignedAgents = [];
      }

      // 2. MODIFY ARRAY: Logic for permissions
      if (Array.isArray(assignedAgents)) {
        // Always remove the current agent first to avoid duplicates
        assignedAgents = assignedAgents.filter(
          (x) => x.trim() !== agentKey.trim(),
        );

        if (
          (arg.permission === false && !arg.prevagent) ||
          (arg.permission == true && !arg.prevagent)
        ) {
          // Add the agent to the front of the list
          assignedAgents.unshift(agentKey);
          console.log("agentkeeey", agentKey);
          console.log("Updated agent list:", assignedAgents);
        } else if (
          (arg.permission === true && arg.prevagent) ||
          (arg.permission === false && arg.prevagent)
        ) {
          console.log("1231312312312");
          assignedAgents = [];
          let newAgent = [];
          if (arg.prevagent && arg.prevagent !== "") {
            //clientUser.assigned_agent

            try {
              newAgent = JSON.parse(clientUser.assigned_agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              newAgent = [];
            }

            if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
              assignedAgents.unshift(agentKey);
              console.log("123131");
            } else {
              assignedAgents.unshift(arg.prevagent);
              console.log("12313133");
            }
          } else {
            assignedAgents.unshift(arg.agent_key);
          }

          console.log("Updated agent list:", assignedAgents);
        }
      }

      // 3. LOCK THE STRING: Stringify once for both Redis and Payloads
      console.log("this is it", assignedAgents);
      const assignedAgentsString = JSON.stringify(assignedAgents);
      clientUser.assigned_agent = assignedAgentsString;
      const extAgentKey = assignedAgents[0];
      const extAgentData = await redisClient.hGetAll(extAgentKey);
      const extAgentName = extAgentData.Agent_Name;
      const extAgentExtension = extAgentData.extension;

      // 4. PREPARE PAYLOADS
      let messagePayload;
      let messagePayload2;
      let filePathContainer = [];
      let savedMessage;
      clientUser.last_message = arg.message;
      clientUser.agentName = agentUser.agentName || agentUser.username;
      clientUser.allocated_room = "ACTIVE";

      // 5. UPDATE AGENT'S LIST (If applicable)
      if (
        (arg.permission === false && !arg.prevagent) ||
        (arg.permission == true && !arg.prevagent)
      ) {
        console.log("hi");
        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify([...new Set(assignedClients)]),
        });
      }

      //assignedAgents = []

      // 6. REDIS UPDATE (Message with Files)
      if (arg.filesData !== "" && arg.filesData !== undefined) {
        for (let index = 0; index < arg.filesData.length; index++) {
          const file = arg.filesData[index].name.split(".");
          const type = arg.filesData[index].type;
          const size = arg.filesData[index].size;
          const filetype = file[file.length - 1];

          const convertedFileName = arg.filesData[index].name
            .trim()
            .replace(/[-]+/g, " ");
          const fileName = uniqueId + "." + filetype;
          const binaryData = Buffer.from(
            arg.filesData[index].fileBuffer,
            "base64",
          );

          // Save the file locally first
          let localPath = "";
          if (arg.filesData[index].type.startsWith("image")) {
            localPath = `/var/www/webapp/chat_file_upload/twilio_img/${fileName}`;
          } else {
            localPath = `/var/www/webapp/chat_file_upload/twilio_file/${fileName}`;
          }

          writeFile(localPath, binaryData, async (err) => {
            if (err) {
              console.log({
                result: "error",
                message: "File upload failed: " + err,
                content: fileName,
              });
              return;
            }

            console.log({
              result: "success",
              message: "File uploaded locally",
              content: fileName,
            });
          });
          if (arg.filesData[index].type.startsWith("image")) {
            filePathContainer.push(
              "chat_file_upload/twilio_img/" + fileName,
              type,
              size,
            );
          } else {
            filePathContainer.push(
              "chat_file_upload/twilio_file/" + fileName,
              type,
              size,
            );
          }

          savedMessage = filePathContainer[0];

          await redisClient.lPush(twilioConversationKey, [
            `${savedMessage}`,
            `${time}`,
            `${myextension}-Agent`,
            `${myextension}-Agent`,
            `${uniqueId}`,
          ]);

          await redisClient.hSet(clientKey.toString(), {
            timestamp: `${time}`,
            last_message: savedMessage?.toString() || "",
            message: savedMessage?.toString() || "",
            isReceived: "0",
            agent_unread: Number(0).toString(),
            client_unread: (
              Number(clientUser.client_unread || 0) + 1
            ).toString(),
            assigned_agent: assignedAgentsString,
            allocated_room: "ACTIVE",
            chatUpdated: `${time}`,
            extAgentExtension: extAgentExtension,
            extAgentName: extAgentName,
          });

          messagePayload2 = {
            // agentProfile: agentProfile,
            uid: uniqueId,
            socketId: senderSocketId,
            username: `${myextension}-Agent`,
            name: clientUser.username,
            useremail: clientUser.email,
            channel: subdomain,
            message: savedMessage,
            time: `${time}`,
            allocated_room: "ACTIVE",
            client_unread: clientUser.client_unread,
            agent_unread: clientUser.agent_unread,
            assigned_agent: assignedAgentsString,
            roomID: agentUser.agentID || agentUser.id,
            last_message: savedMessage,
            // agentName: displayAgentName,
            messageType: "twilio",
            id: clientUser.id,
            agentName: agentUser.Agent_Name,
            ourNumber: clientUser.ourNumber,
            appId: clientUser.appId,
            chatUpdated: `${time}`,
            pageId: clientUser.pageId,
          };

          console.log("before 2", messagePayload2);

          if (arg.message !== "") {
            await redisClient.hSet(clientKey.toString(), {
              timestamp: `${time}`,
              last_message: savedMessage?.toString() || "",
              message: savedMessage?.toString() || "",
              isReceived: "0",
              agent_unread: "0",
              client_unread: (
                Number(clientUser.client_unread || 0) + 1
              ).toString(),
              assigned_agent: assignedAgentsString, // Use the pre-stringified variable
              allocated_room: "ACTIVE",
              chatUpdated: `${time}`,
              extAgentName: extAgentName,
              extAgentExtension: extAgentExtension,
            });

            // ... (Rest of messagePayload2 logic) ...

            await redisClient.lPush(twilioConversationKey, [
              `${arg.message}`,
              `${time}`,
              `${myextension}-Agent`,
              `${myextension}-Agent`,
              `${uniqueId}`,
            ]);

            messagePayload = {
              // agentProfile: agentProfile,
              uid: uniqueId,
              socketId: senderSocketId,
              username: `${myextension}-Agent`,
              name: clientUser.username,
              useremail: clientUser.email,
              channel: subdomain,
              message: arg.message,
              time: `${time}`,
              allocated_room: "ACTIVE",
              client_unread: clientUser.client_unread,
              agent_unread: clientUser.agent_unread,
              assigned_agent: assignedAgentsString,
              roomID: agentUser.agentID || agentUser.id,
              last_message: arg.message,
              // agentName: displayAgentName,
              messageType: "twilio",
              id: clientUser.id,
              agentName: agentUser.Agent_Name,
              ourNumber: clientUser.ourNumber,
              appId: clientUser.appId,
              chatUpdated: `${time}`,
              pageId: clientUser.pageId,
            };

            console.log("before", messagePayload);
          }
        }
      }
      // Inside your file loop, when you save to Redis:

      // 7. REDIS UPDATE (Message Only)
      else if (!arg.filesData) {
        console.log("this is for message only okay?");
        const messageData = {
          message: arg.message,
          time: `${time}`,
          sender: `${myextension}-Agent`,
          channel: subdomain,
          uid: `${uniqueId}`,
        };
        console.log("this is  twiliooo, ", twilioConversationKey);

        await redisClient.lPush(twilioConversationKey, [
          `${arg.message}`,
          `${time}`,
          `${myextension}-Agent`,
          `${myextension}-Agent`,
          `${uniqueId}`,
        ]);

        await redisClient.hSet(clientKey.toString(), {
          timestamp: `${time}`,
          last_message: arg.message?.toString() || "",
          message: arg.message?.toString() || "",
          isReceived: "0",
          agent_unread: Number(0).toString(),
          client_unread: (Number(clientUser.client_unread || 0) + 1).toString(),
          assigned_agent: assignedAgentsString,
          allocated_room: "ACTIVE",
          chatUpdated: `${time}`,
          extAgentExtension: extAgentExtension,
          extAgentName: extAgentName,
        });

        messagePayload = {
          // agentProfile: agentProfile,
          uid: uniqueId,
          socketId: senderSocketId,
          username: `${myextension}-Agent`,
          name: clientUser.username,
          useremail: clientUser.email,
          channel: subdomain,
          message: arg.message,
          time: `${time}`,
          allocated_room: "ACTIVE",
          client_unread: clientUser.client_unread,
          agent_unread: clientUser.agent_unread,
          assigned_agent: assignedAgentsString,
          roomID: agentUser.agentID || agentUser.id,
          last_message: arg.message,
          // agentName: displayAgentName,
          messageType: "twilio",
          id: clientUser.id,
          agentName: agentUser.Agent_Name,
          ourNumber: clientUser.ourNumber,
          appId: clientUser.appId,
          chatUpdated: `${time}`,
          pageId: clientUser.pageId,
        };
      }

      const conversationUpdateKey = allocatedRoomKey;
      smsConversationListUpdate(
        conversationUpdateKey,
        clientUser.allocated_room,
        arg.url,
      );

      const conversationLogsData = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.username ? `${clientUser.username}` : "",
        messageType: clientUser.messageType ?? "twilio",
        conversationsFetch: twilioConversationKey,
      };

      const conversationLogsResult = await conversationLogsAdd(
        "agent",
        conversationLogsData,
        subdomain,
      );
      console.log("conversationLogsResult", conversationLogsResult);

      const conversationLogsDataExtension = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.username ? `${clientUser.username}` : "",
        message: `${arg.message}`,
      };

      const chatHistoryKeyExtension = `${twilioConversationKey}`;
      const conversationLogsExtensionResult =
        await conversationLogsAddExtension(
          "agent",
          conversationLogsDataExtension,
          chatHistoryKeyExtension,
        );
      console.log("conversationLogsResult2", conversationLogsExtensionResult);

      let currentMsgIndex = clientUser.msgIndex
        ? Number(clientUser.msgIndex)
        : 0;

      // Para sa File
      currentMsgIndex++;

      await redisClient.hSet(clientKey, {
        msgIndex: currentMsgIndex,
      });

      await saveMessageForSearch(
        `${arg.message}`,
        `${time}`,
        `${agentUser.Agent_Name}`,
        `${agentUser.email}`,
        `agent`, //`${subdomain}`
        `${uniqueId}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        twilioConversationKey,
        `twilio`,
        `${clientKey}`,
        `${arg.message}`,
        `${time}`,
        `${myextension}-Agent`,
        `agent`,
        `${uniqueId}`,
        `${subdomain}`,
      );

      if (Number(clientUser.isReceived == 0)) {
        await redisClient.hSet(agentKey, {
          agent_unread: Number(agentUser.agent_unread) - 1,
        });
      }

      let updateUnread;

      await redisClient.zRem(
        `${subdomain}-overallUnreadCount`,
        `${subdomain}-unread-${clientKey}`,
      );
      const overallUnreadCount = await redisClient.zCard(
        `${subdomain}-overallUnreadCount`,
      );

      let unassignedUpdateUnread = {
        agent_unread: overallUnreadCount,
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        console.log("this is assigned");
        let agentKeyArray = clientUser.assigned_agent;

        console.log("raw agentList:", agentKeyArray);
        console.log("type:", typeof agentKeyArray);

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }

        const agentUnreadCount = `unread-${agentKey}`;
        const agentUserPrev = await redisClient.hGetAll(agentKey);
        console.log(
          "dito baabah",
          agentUnreadCount,
          agentUnreadCount,
          `unread-${clientKey}`,
        );
        await redisClient.zRem(`${agentUnreadCount}`, `unread-${clientKey}`);
        console.log("0");
        const assignedCountResult = await redisClient.zCard(
          `${agentUnreadCount}`,
        );
        console.log("1");
        unassignedUpdateUnread.total = assignedCountResult;
        unassignedUpdateUnread.extension = agentUserPrev.extension;
        console.log("2");
        //  const totalUnreadSent = unreadCountAll + updatedUnreadCount;
        console.log("agentKEY", agentKey);
        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);
        console.log("3");

        if (agentDetails?.roomID !== "all_messages") {
          console.log("this is the agentsocket id", agentSocketId);
          // if(noAssigned == 1){
          //   socketSignal.emit("chatParameters", "queue", agentStatus, agentSocketId);
          // }

          console.log("1 payload", messagePayload);
          socketSignal.emit(
            "chatParameters",
            "queue6",
            messagePayload,
            `${fqdnValue}-all_messages`,
          );
          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            unassignedUpdateUnread,
            `${fqdnValue}-all_messages`,
          );
          if (
            messagePayload2 != null &&
            Object.keys(messagePayload2).length > 0 !== ""
          ) {
            console.log("2 payload", messagePayload2);
            socketSignal.emit(
              "chatParameters",
              "queue6",
              messagePayload2,
              `${fqdnValue}-all_messages`,
            );
          }
        }

        socketSignal.emit(
          "chatParameters",
          "queue6",
          messagePayload,
          `${fqdnValue}-all_messages`,
        );
        console.log("4");
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );
        console.log("5");
        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          console.log("2 payload", messagePayload2);
          socketSignal.emit(
            "chatParameters",
            "queue6",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }
      } else {
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue6",
          messagePayload,
          `${fqdnValue}-all_messages`,
        );

        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue6",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }
      }

      // const redisKeyVerified = `twilioconfig:sms:config:8d302e7f-b3c9-4314-942a-c9c3264d5050:incorporissintsi.flowersconradplc.ringq.cloud`
      const accessTokenChallenge = clientUser.accessToken;
      const curlTo = clientUser.email;
      const curlMessage = arg.message;
      const curlFrom = clientUser.id;
      const curlUrl = `https://api.twilio.com/2010-04-01/Accounts/${clientUser.accountSid}/Messages.json`;
      const curlAccountSid = clientUser.accountSid;
      const curlMessagingServiceSid = clientUser.messagingServiceSid;
      console.log(
        "twilioCurl",
        accessTokenChallenge,
        curlTo,
        curlMessage,
        curlUrl,
        curlFrom,
        curlAccountSid,
        curlMessagingServiceSid,
      );
      const curlResult = await twilioCurl(
        accessTokenChallenge,
        curlTo,
        curlMessage,
        curlUrl,
        curlFrom,
        curlAccountSid,
        curlMessagingServiceSid,
      );

      // pushWebMessage = arg.message;
      console.log("twilio arg????", arg);

      //temp agent
      pushWeb(
        arg.message,
        clientKey,
        subdomain,
        agentUser.Agent_Name,
        "twilio",
        "agent",
      );

      console.log("this is curl result", curlResult);
    }
  });

  socket.on("telegramChat", async ({ arg, subdomain, extension, mysocket }) => {
    if (!arg || !mysocket) return;

    const telegramData = JSON.parse(arg["0"]);
    const { message } = telegramData;
    const { id: userID, first_name, username: tgUser } = message.from;
    const fqdnValue = arg.fqdn;
    const uniqueId = Date.now();
    const referenceName = tgUser || first_name;
    let lastMessage = "";
    let caption = message.caption || "";
    let saveMessageLogs;

    // 1. Handle Message Types & File Paths
    if (message.text) {
      lastMessage = message.text;
      saveMessageLogs = message.text;
    } else if (message.photo || message.document || message.video) {
      lastMessage = arg.localFilePath
        ? arg.localFilePath.split("/").slice(4).join("/")
        : "";
      saveMessageLogs = `https://${fqdnValue}/${lastMessage}`;
      console.log("this is great", saveMessageLogs);
    } else {
      return; // Unsupported type
    }

    console.log("arg", arg, arg.idRedis);

    const verified = await verifyToken5(
      "telegramconfig:fqdn:",
      fqdnValue,
      {
        mysocket,
        text: lastMessage,
        fqdn: fqdnValue,
        messageType: "telegram",
        username: userID,
        id: userID,
        referenceName: referenceName,
      },
      arg.idRedis,
    );

    if (!verified) {
      console.log("Token verification failed for", arg.url, arg.client_ID);

      arg.status = "failed";
      arg.msg =
        "Your Telegram configuration appears to be invalid or misconfigured, " +
        "so this message can't be sent. Please check your settings and try again.";

      socketSignal.emit("chatParameters", "queueError", arg, mysocket);
      return;
    }
    const { accessToken, name: appId, id: pageId, clientKey } = verified;

    const cleanedAccessToken = accessToken
      ? accessToken.replace(/[:_-]/g, "")
      : "";

    const email = cleanedAccessToken;

    const username = String(userID);

    // 2. Data Retrieval & Verification
    let clientUser = await redisClient.hGetAll(clientKey);
    const hasClient = Object.keys(clientUser).length > 0;

    const time = Date.now();
    let currentMsgIndex = clientUser.msgIndex ? Number(clientUser.msgIndex) : 0;

    // 3. Room Allocation & Unread Logic
    let allocatedRoom = "NEW";
    if (
      hasClient &&
      clientUser.assigned_agent &&
      clientUser.assigned_agent !== "[]"
    ) {
      allocatedRoom =
        clientUser.allocated_room === "CLOSED"
          ? "NEW"
          : clientUser.allocated_room;
    }

    const agentUnread = hasClient
      ? Number(clientUser.agent_unread || 0) + 1
      : 1;

    // 4. Consolidated Redis State Update
    const clientUpdate = {
      socketID: mysocket,
      allocated_room: allocatedRoom,
      last_message: lastMessage,
      message: lastMessage,
      messageType: "telegram",
      agent_unread: agentUnread,
      client_unread: "0",
      fqdn: fqdnValue,
      pageId,
      appId,
      accessToken,
      status: "online",
      chatUpdated: uniqueId,
      referenceName: referenceName,
      ...(hasClient
        ? {}
        : {
            id: userID.toString(),
            username: username.toString(),
            created: uniqueId,
            email: email,
            role: "customer",
            Dept: "customer",
          }),
    };

    await redisClient.hSet(clientKey, clientUpdate);

    // 5. Logs and Conversation History
    const convKey = `${fqdnValue}:telegram:${userID}:${username}-${email}`;

    console.log(
      "convKey tele",
      convKey,
      lastMessage,
      uniqueId,
      username,
      pageId,
    );
    const lastMessageStr = String(lastMessage || "");
    const uniqueIdStr = String(uniqueId || Date.now());
    const usernameStr = String(username || "unknown");
    const pageIdStr = String(pageId || "");

    console.log(
      "convKey tele",
      convKey,
      lastMessageStr,
      uniqueIdStr,
      usernameStr,
      pageIdStr,
    );

    // I-pass ang sanitized values sa lPush
    await redisClient.lPush(convKey, [
      lastMessageStr,
      `${time}`,
      referenceName,
      referenceName,
      pageIdStr,
    ]);

    const logData = {
      date: `${time}`,
      username: referenceName,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      messageType: clientUser.messageType || "telegram",
      conversationsFetch: convKey,
    };

    const logData2 = {
      date: `${time}`,
      username: referenceName,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      message: saveMessageLogs,
    };
    await conversationLogsAdd("client", logData, fqdnValue);
    await conversationLogsAddExtension("client", logData2, convKey);
    await smsConversationListUpdate(
      `${username}-${userID}-telegram-${email}`,
      allocatedRoom,
      fqdnValue,
    );

    currentMsgIndex++;

    await redisClient.hSet(clientKey, {
      msgIndex: currentMsgIndex,
    });

    await saveMessageForSearch(
      `${lastMessageStr}`,
      `${time}`,
      `${username}`,
      `${username}`,
      `user`, // Pass profile here
      `${uniqueId}`,
      `${appId}`,
      `${appId}`,
      convKey,
      `telegram`,
      `${clientKey}`,
      lastMessageStr,
      `${time}`,
      referenceName,
      referenceName,
      pageIdStr,
      fqdnValue,
    );

    const uniqueId2 = Date.now();

    if (caption) {
      const time2 = Date.now();
      await redisClient.lPush(convKey, [
        caption,
        `${time2}`,
        referenceName,
        referenceName,
        pageId,
      ]);
      const logData = {
        date: `${time2}`,
        username: referenceName,
        email: "",
        to: clientUser.extAgentName
          ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
          : "",
        messageType: clientUser.messageType || "telegram",
        conversationsFetch: convKey,
      };

      console.log("my message tg", lastMessage);

      const logData2 = {
        date: `${time2}`,
        username: referenceName,
        email: "",
        to: clientUser.extAgentName
          ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
          : "",
        message: caption,
      };

      currentMsgIndex++;

      await redisClient.hSet(clientKey, {
        msgIndex: currentMsgIndex,
      });

      await saveMessageForSearch(
        `${caption}`,
        `${time2}`,
        `${username}`,
        `${username}`,
        `user`, // Pass profile here
        `${uniqueId2}`,
        `${appId}`,
        `${appId}`,
        convKey,
        `telegram`,
        `${clientKey}`,
        caption,
        `${time2}`,
        referenceName,
        referenceName,
        pageIdStr,
        fqdnValue,
      );

      await conversationLogsAdd("client", logData, fqdnValue);
      await conversationLogsAddExtension("client", logData2, convKey);
    }

    // 6. Agent Assignment & Unread Signaling
    let agentList = [];
    try {
      const assigned = clientUser.assigned_agent;
      agentList = Array.isArray(assigned)
        ? assigned
        : JSON.parse(assigned || "[]");
    } catch {
      agentList = [clientUser.assigned_agent].filter(Boolean);
    }

    await redisClient.zAdd(`${fqdnValue}-overallUnreadCount`, {
      score: uniqueId,
      value: `unread-${clientKey}`,
    });
    const overallUnread = await redisClient.zCard(
      `${fqdnValue}-overallUnreadCount`,
    );

    const updateData = { agent_unread: overallUnread };
    if (agentList.length > 0) {
      const agentKey = agentList[0];
      const agentDetails = await redisClient.hGetAll(agentKey);
      let assigned = JSON.parse(agentDetails.assigned || "[]");
      assigned = [clientKey, ...assigned.filter((c) => c !== clientKey)];

      await redisClient.hSet(agentKey, {
        agent_unread: Number(agentDetails.agent_unread || 0) + 1,
        assigned: JSON.stringify(assigned),
      });

      await redisClient.zAdd(`unread-${agentKey}`, {
        score: uniqueId,
        value: `unread-${clientKey}`,
      });
      updateData.total = await redisClient.zCard(`unread-${agentKey}`);
      updateData.extension = agentDetails.extension;
    }
    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      updateData,
      `${fqdnValue}-all_messages`,
    );

    // 7. Final Emits (Message + optional Caption) & Push
    const emitBase = {
      id: `${userID}`,
      name: referenceName,
      from: referenceName,
      username: usernameStr || userID,
      useremail: email,
      agent_unread: agentUnread,
      client_unread: "0",
      email: email,
      status: "online",
      allocated_room: allocatedRoom,
      messageType: "telegram",
      appId,
      pageId,
      ourNumber: usernameStr,
      chatUpdated: uniqueId,
      referenceName: referenceName,
    };
    console.log("emitBase", emitBase.name, emitBase.from);

    socketSignal.emit(
      "chatParameters",
      "queue7",
      {
        ...emitBase,
        unix: uniqueId,
        time: uniqueId,
        message: lastMessage,
        last_message: lastMessage,
      },
      `${fqdnValue}-all_messages`,
    );
    if (caption) {
      socketSignal.emit(
        "chatParameters",
        "queue7",
        {
          ...emitBase,
          unix: uniqueId + 1,
          time: uniqueId + 1,
          message: caption,
          last_message: caption,
        },
        `${fqdnValue}-all_messages`,
      );
    }

    pushWeb(
      safe(lastMessage) || "default",
      clientKey,
      fqdnValue,
      referenceName,
      "telegram",
      "client",
    );
  });

  socket.on("communicate7Chat", async (argument) => {
    console.log("Telegram !--> THIS IS CALLED");

    const { arg, subdomain, extension, mysocket } = argument;
    console.log("this is arg", arg);
    if (
      argument === "undefined" ||
      argument === null ||
      arg === "undefined" ||
      arg === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }

    await createSearchIndex(subdomain);

    if (arg.defaultChatSound) {
      const validatedChecker = await chatSettingsChecker(
        arg,
        subdomain,
        mysocket,
      );

      if (validatedChecker == 0) {
        return;
      } else if (validatedChecker == 1) {
        console.log("that's okay dear");
      }
    }

    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let uniqueId = "";
    let length = 16;

    let msgType = "";
    let msgMethod = "";
    let curlMessage = "";

    let curlCaption = "";
    for (let i = 0; i < length; i++) {
      uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    uniqueId = `${Date.now()}${uniqueId}`;

    const uniqueId2 = `${uniqueId} + 1`;
    const time = `${Date.now()}`;
    const time2 = `${time} + 1`;
    let validate;
    const senderSocketId = mysocket;

    let assignedClients = [];
    let agentHolder = null;

    let finalMessage = "";
    const fqdnValue = arg.url;

    let myextension = arg.agent_ID;
    let assignedAgents;
    // Saved for later
    const telegramConversationKey = `${arg.conversationkey}`;
    const allocatedRoomKey = arg.roomkey;
    let saveMessageLogs;
    //temporary
    const clientKey = `${arg.client_key}`;
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }

    let agentKey = arg.agent_key;
    const agentUser = await redisClient.hGetAll(agentKey);
    if (!agentUser) {
      console.log("No client found for userKey", agentUser);
      return;
    }

    console.log("this is agent user", agentUser);

    //Start

    if (
      (arg.permission == false && !arg.prevagent) ||
      (arg.permission == true && !arg.prevagent)
    ) {
      console.log("okba");
      if (clientUser.assigned_agent && clientUser.assigned_agent !== null) {
        // Case 1: if client is assigned to agent who is attempting to send message

        if (clientUser.agent_unread) {
          initialAgentUnread = Number(clientUser.agent_unread);
        }

        if (clientUser.assigned_agent) {
          const failedAgentSocket = agentUser.socketID;
          let assignedAgents = [];

          try {
            assignedAgents = JSON.parse(clientUser.assigned_agent);
            if (!Array.isArray(assignedAgents)) assignedAgents = [];
          } catch {
            assignedAgents = [];
          }

          // if (!assignedAgents.includes(agentKey)) {
          // const messagePayload = {
          //   message: "You are not listed as an assigned agent to this conversation. Unable to send a message!",
          //   status: "failed",
          // };

          // socketSignal.emit(
          //   "chatParameters",
          //   "queueError",
          //   messagePayload,
          //   failedAgentSocket
          // );
          // console.log("YOU ARE NOT THE AGENT!!")

          // return;
          // }
        }

        if (clientUser.assigned_agent) {
          validate = true;
          agentHolder = agentUser;
          if (agentUser && agentUser.assigned) {
            try {
              assignedClients = JSON.parse(agentUser.assigned);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedClients = [];
            }
          } else {
            assignedClients = [];
          }

          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            assignedClients = assignedClients.filter(
              (x) => x.trim() !== clientKey.trim(),
            );

            assignedClients.unshift(clientKey.trim());
            console.log("this is the map", assignedClients);
            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          } else {
            console.log("Assigning client to agent...");
            assignedClients.unshift(clientKey);

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });

            console.log("Proceed now to validate part");
          }
        } else {
          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            try {
              assignedClients = JSON.parse(agentUser.assigned || "[]");
            } catch (e) {
              console.error("Failed to parse assigned clients, resetting:", e);
              assignedClients = [];
            }
            assignedClients.unshift(clientKey);
            agentHolder = agentUser;
            validate = true;
          }
        }
      } else {
        noAssigned = 1;
        try {
          assignedClients = JSON.parse(agentUser.assigned || "[]");
        } catch (e) {
          console.error("Failed to parse assigned clients, resetting:", e);
          assignedClients = [];
        }
        assignedClients.unshift(clientKey);
        agentHolder = agentUser;
        validate = true;
      }

      if (arg.prevagent && arg.prevagent !== "") {
        console.log("mahalaga");
        const previousAgentUser = await redisClient.hGetAll(arg.prevagent);
        prevAssignedAgent = JSON.parse(
          previousAgentUser.assigned_agent || "[]",
        );
        prevAssignedAgent = prevAssignedAgent.filter(
          (x) => x.trim() !== clientKey.trim(),
        );

        await redisClient.hSet(arg.prevagent.toString(), {
          assigned: JSON.stringify(prevAssignedAgent || []),
        });
      }
    } else if (
      (arg.permission === true && arg.prevagent) ||
      (arg.permission === false && arg.prevagent)
    ) {
      console.log("dito papasok");
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
          console.log("123131");
        } else {
          assignedAgents.unshift(arg.prevagent);
          console.log("12313133");
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      console.log("Updated agent list:", assignedAgents);
      validate = true;
    }

    clientUser.client_unread =
      parseInt(clientUser.client_unread ?? "0", 10) + 1;
    clientUser.agent_unread = 0;

    if (validate) {
      console.log("comm 7 -> validate");

      // 1. CONSOLIDATED PARSING: Get a clean JS Array
      try {
        assignedAgents =
          typeof clientUser.assigned_agent === "string"
            ? JSON.parse(clientUser.assigned_agent || "[]")
            : clientUser.assigned_agent || [];
      } catch (e) {
        console.error("Failed to parse assigned agents:", e);
        assignedAgents = [];
      }

      // 2. MODIFY ARRAY: Logic for permissions
      if (Array.isArray(assignedAgents)) {
        // Always remove the current agent first to avoid duplicates
        assignedAgents = assignedAgents.filter(
          (x) => x.trim() !== agentKey.trim(),
        );

        if (
          (arg.permission === false && !arg.prevagent) ||
          (arg.permission == true && !arg.prevagent)
        ) {
          // Add the agent to the front of the list
          assignedAgents.unshift(agentKey);
          console.log("agentkeeey", agentKey);
          console.log("Updated agent list:", assignedAgents);
        } else if (
          (arg.permission === true && arg.prevagent) ||
          (arg.permission === false && arg.prevagent)
        ) {
          console.log("1231312312312");
          assignedAgents = [];
          let newAgent = [];
          if (arg.prevagent && arg.prevagent !== "") {
            //clientUser.assigned_agent

            try {
              newAgent = JSON.parse(clientUser.assigned_agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              newAgent = [];
            }

            if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
              assignedAgents.unshift(agentKey);
              console.log("123131");
            } else {
              assignedAgents.unshift(arg.prevagent);
              console.log("12313133");
            }
          } else {
            assignedAgents.unshift(arg.agent_key);
          }

          console.log("Updated agent list:", assignedAgents);
        }
      }

      // 3. LOCK THE STRING: Stringify once for both Redis and Payloads
      console.log("this is it", assignedAgents);
      const assignedAgentsString = JSON.stringify(assignedAgents);
      clientUser.assigned_agent = assignedAgentsString;
      const extAgentKey = assignedAgents[0];
      const extAgentData = await redisClient.hGetAll(extAgentKey);
      const extAgentName = extAgentData.Agent_Name;
      const extAgentExtension = extAgentData.extension;

      // 4. PREPARE PAYLOADS
      let messagePayload;
      let messagePayload2;
      let filePathContainer = [];
      let savedMessage;
      clientUser.last_message = arg.message;
      clientUser.agentName = agentUser.agentName || agentUser.username;
      clientUser.allocated_room = "ACTIVE";

      // 5. UPDATE AGENT'S LIST (If applicable)
      if (
        (arg.permission === false && !arg.prevagent) ||
        (arg.permission == true && !arg.prevagent)
      ) {
        console.log("hi");
        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify([...new Set(assignedClients)]),
        });
      }

      //assignedAgents = []

      // 6. REDIS UPDATE (Message with Files)
      if (arg.filesData !== "" && arg.filesData !== undefined) {
        for (let index = 0; index < arg.filesData.length; index++) {
          const file = arg.filesData[index].name.split(".");
          const type = arg.filesData[index].type;
          const size = arg.filesData[index].size;
          const filetype = file[file.length - 1];
          const convertedFileName = arg.filesData[index].name
            .trim()
            .replace(/[-]+/g, " ");
          const fileName = uniqueId + "." + filetype;
          const binaryData = Buffer.from(
            arg.filesData[index].fileBuffer,
            "base64",
          );

          // Save the file locally first
          let localPath = "";
          if (arg.filesData[index].type.startsWith("image")) {
            console.log("puto");
            localPath = `/var/www/webapp/chat_file_upload/telegram_image/${fileName}`;
            msgType = "photo";
            msgMethod = "sendPhoto";
            filePathContainer.push(
              "chat_file_upload/telegram_image/" + fileName,
              type,
              size,
            );
          } else if (arg.filesData[index].type.startsWith("file")) {
            localPath = `/var/www/webapp/chat_file_upload/telegram_file/${fileName}`;
            console.log("dito", localPath);
            if (arg.filesData !== "" && arg.filesData !== undefined) {
              console.log("dito 1");
              if (
                arg.filesData[index].fileExtension.startsWith("pdf") ||
                arg.filesData[index].fileExtension.startsWith("docx")
              ) {
                msgType = "document";
                msgMethod = "sendDocument";
                console.log("dito pdf");
              } else if (
                arg.filesData[index].fileExtension.startsWith("video")
              ) {
                msgType = "video";
                msgMethod = "sendVideo";
                console.log("dito video");
              }
              filePathContainer.push(
                "chat_file_upload/telegram_file/" + fileName,
                type,
                size,
              );
            } else {
              msgType = "text";
              msgMethod = "sendMessage";
            }
          } else {
            console.log("cant know");
          }

          savedMessage = filePathContainer[0];
          curlMessage = `https://${fqdnValue}/${savedMessage}`;
          saveMessageLogs = `https://${fqdnValue}/${savedMessage}`;

          if (msgType == "document") {
            curlMessage = localPath;
          }

          writeFile(localPath, binaryData, async (err) => {
            if (err) {
              console.log({
                result: "error",
                message: "File upload failed: " + err,
                content: fileName,
              });

              console.log("localpath", localPath);
              return;
            }

            console.log({
              result: "success",
              message: "File uploaded locally",
              content: fileName,
            });
          });

          await redisClient.lPush(telegramConversationKey, [
            `${savedMessage}`,
            `${time}`,
            `${myextension}-Agent`,
            `${myextension}-Agent`,
            `${uniqueId}`,
          ]);

          const conversationLogsData = {
            date: `${time}`,
            username: `${agentUser.Agent_Name} (${agentUser.extension})`,
            email: arg.agent_email,
            to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
            messageType: clientUser.messageType ?? "telegram",
            conversationsFetch: telegramConversationKey,
          };

          const conversationLogsResult = await conversationLogsAdd(
            "agent",
            conversationLogsData,
            subdomain,
          );
          console.log("conversationLogsResult", conversationLogsResult);

          const conversationLogsDataExtension = {
            date: `${time}`,
            username: `${agentUser.Agent_Name} (${agentUser.extension})`,
            email: arg.agent_email,
            to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
            message: saveMessageLogs,
          };

          const chatHistoryKeyExtension = `${telegramConversationKey}`;
          const conversationLogsExtensionResult =
            await conversationLogsAddExtension(
              "agent",
              conversationLogsDataExtension,
              chatHistoryKeyExtension,
            );
          console.log(
            "conversationLogsResult2",
            conversationLogsExtensionResult,
          );

          let currentMsgIndex = clientUser.msgIndex
            ? Number(clientUser.msgIndex)
            : 0;

          // Para sa File
          currentMsgIndex++;

          await redisClient.hSet(clientKey, {
            msgIndex: currentMsgIndex,
          });

          await saveMessageForSearch(
            `${savedMessage}`,
            `${time}`,
            `${agentUser.Agent_Name}`,
            `${agentUser.email}`,
            `agent`, //`${subdomain}`
            `${uniqueId}`,
            `${clientUser.appId}`,
            `${clientUser.pageId}`,
            telegramConversationKey,
            `telegram`,
            `${clientKey}`,
            `${savedMessage}`,
            `${uniqueId}`,
            `${myextension}-Agent`,
            `agent`,
            `${time}`,
            `${subdomain}`,
          );

          await redisClient.hSet(clientKey.toString(), {
            timestamp: time,
            last_message: savedMessage?.toString() || "",
            message: savedMessage?.toString() || "",
            isReceived: "0",
            agent_unread: Number(0).toString(),
            client_unread: (
              Number(clientUser.client_unread || 0) + 1
            ).toString(),
            assigned_agent: assignedAgentsString,
            allocated_room: "ACTIVE",
            chatUpdated: time,
            extAgentName: extAgentName,
            extAgentExtension: extAgentExtension,
          });

          messagePayload2 = {
            // agentProfile: agentProfile,
            uid: uniqueId2,
            socketId: senderSocketId,
            username: `${myextension}-Agent`,
            name: clientUser.username,
            useremail: clientUser.email,
            channel: subdomain,
            message: savedMessage,
            time: `${time2}`,
            allocated_room: "ACTIVE",
            client_unread: clientUser.client_unread,
            agent_unread: clientUser.agent_unread,
            assigned_agent: assignedAgentsString,
            roomID: agentUser.agentID || agentUser.id,
            last_message: savedMessage,
            // agentName: displayAgentName,
            messageType: "telegram",
            id: clientUser.id,
            agentName: agentUser.Agent_Name,
            ourNumber: clientUser.ourNumber,
            appId: clientUser.appId,
            chatUpdated: `${time2}`,
            referenceName: clientUser.referenceName,
            from: myextension + "-Agent",
            name: myextension + "-Agent",
          };

          console.log("before 2", messagePayload2);

          if (arg.message !== "") {
            curlCaption = arg.message;
            await redisClient.hSet(clientKey.toString(), {
              timestamp: `${time}`,
              last_message: arg.message?.toString() || "",
              message: arg.message.toString() || "",
              isReceived: "0",
              agent_unread: Number(0).toString(),
              client_unread: (
                Number(clientUser.client_unread || 0) + 1
              ).toString(),
              assigned_agent: JSON.stringify(assignedAgents || []),
              allocated_room: "ACTIVE",
              chatUpdated: `${time}`,
              extAgentName: extAgentName,
              extAgentExtension: extAgentExtension,
            });

            await redisClient.lPush(telegramConversationKey, [
              `${arg.message}`,
              `${time}`,
              `${myextension}-Agent`,
              `${myextension}-Agent`,
              `${time}`,
            ]);

            const conversationLogsData = {
              date: `${time}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              messageType: clientUser.messageType ?? "telegram",
              conversationsFetch: telegramConversationKey,
            };

            const conversationLogsResult = await conversationLogsAdd(
              "agent",
              conversationLogsData,
              subdomain,
            );
            console.log("conversationLogsResult", conversationLogsResult);

            const conversationLogsDataExtension = {
              date: `${time}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              message: `${arg.message}`,
            };

            const chatHistoryKeyExtension = `${telegramConversationKey}`;
            const conversationLogsExtensionResult =
              await conversationLogsAddExtension(
                "agent",
                conversationLogsDataExtension,
                chatHistoryKeyExtension,
              );
            console.log(
              "conversationLogsResult2",
              conversationLogsExtensionResult,
            );

            currentMsgIndex++;

            await redisClient.hSet(clientKey, {
              msgIndex: currentMsgIndex,
            });

            await saveMessageForSearch(
              `${arg.message}`,
              `${time}`,
              `${agentUser.Agent_Name}`,
              `${agentUser.email}`,
              `agent`, //`${subdomain}`
              `${uniqueId}`,
              `${clientUser.appId}`,
              `${clientUser.pageId}`,
              telegramConversationKey,
              `telegram`,
              `${clientKey}`,
              `${arg.message}`,
              `${uniqueId}`,
              `${myextension}-Agent`,
              `agent`,
              `${time}`,
              `${subdomain}`,
            );

            messagePayload = {
              // agentProfile: agentProfile,
              uid: uniqueId,
              socketId: senderSocketId,
              username: `${myextension}-Agent`,
              name: clientUser.username,
              useremail: clientUser.email,
              channel: subdomain,
              message: arg.message,
              time: `${time}`,
              allocated_room: "ACTIVE",
              client_unread: clientUser.client_unread,
              agent_unread: clientUser.agent_unread,
              assigned_agent: assignedAgentsString,
              roomID: agentUser.agentID || agentUser.id,
              last_message: arg.message,
              // agentName: displayAgentName,
              messageType: "telegram",
              id: clientUser.id,
              agentName: agentUser.Agent_Name,
              ourNumber: clientUser.ourNumber,
              appId: clientUser.appId,
              chatUpdated: `${time}`,
              referenceName: clientUser.referenceName,
              from: myextension + "-Agent",
              name: myextension + "-Agent",
            };

            console.log("before", messagePayload);
          }
        }
      } else if (!arg.filesData) {
        console.log("no filesData");
        msgType = "text";
        msgMethod = "sendMessage";
        curlMessage = arg.message;
        savedMessage = arg.message;
        console.log("this is for message only okay?");
        const messageData = {
          message: arg.message,
          time: `${time}`,
          sender: `${myextension}-Agent`,
          channel: subdomain,
          uid: uniqueId,
        };
        console.log("this is  telegram, ", telegramConversationKey);

        await redisClient.lPush(telegramConversationKey, [
          `${arg.message}`,
          `${time}`,
          `${myextension}-Agent`,
          `${myextension}-Agent`,
          `${uniqueId}`,
        ]);

        let currentMsgIndex = clientUser.msgIndex
          ? Number(clientUser.msgIndex)
          : 0;

        // Para sa File
        currentMsgIndex++;

        await redisClient.hSet(clientKey, {
          msgIndex: currentMsgIndex,
        });

        await saveMessageForSearch(
          `${arg.message}`,
          `${time}`,
          `${agentUser.Agent_Name}`,
          `${agentUser.email}`,
          `agent`, //`${subdomain}`
          `${uniqueId}`,
          `${clientUser.appId}`,
          `${clientUser.pageId}`,
          telegramConversationKey,
          `telegram`,
          `${clientKey}`,
          `${arg.message}`,
          `${time}`,
          `${myextension}-Agent`,
          `agent`,
          `${uniqueId}`,
          `${subdomain}`,
        );

        const conversationLogsData = {
          date: `${time}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
          messageType: clientUser.messageType ?? "telegram",
          conversationsFetch: telegramConversationKey,
        };

        const conversationLogsResult = await conversationLogsAdd(
          "agent",
          conversationLogsData,
          subdomain,
        );
        console.log("conversationLogsResult", conversationLogsResult);

        const conversationLogsDataExtension = {
          date: `${time}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
          message: `${arg.message}`,
        };

        const chatHistoryKeyExtension = `${telegramConversationKey}`;
        const conversationLogsExtensionResult =
          await conversationLogsAddExtension(
            "agent",
            conversationLogsDataExtension,
            chatHistoryKeyExtension,
          );
        console.log("conversationLogsResult2", conversationLogsExtensionResult);

        await redisClient.hSet(clientKey.toString(), {
          timestamp: `${time}`,
          last_message: arg.message?.toString() || "",
          message: arg.message?.toString() || "",
          isReceived: "0",
          agent_unread: Number(0).toString(),
          client_unread: (Number(clientUser.client_unread || 0) + 1).toString(),
          assigned_agent: assignedAgentsString,
          allocated_room: "ACTIVE",
          chatUpdated: `${time}`,
          extAgentName: extAgentName,
          extAgentExtension: extAgentExtension,
        });

        messagePayload = {
          // agentProfile: agentProfile,
          uid: uniqueId,
          socketId: senderSocketId,
          username: `${myextension}-Agent`,
          name: clientUser.username,
          useremail: clientUser.email,
          channel: subdomain,
          message: arg.message,
          time: `${time}`,
          allocated_room: "ACTIVE",
          client_unread: clientUser.client_unread,
          agent_unread: clientUser.agent_unread,
          assigned_agent: assignedAgentsString,
          roomID: agentUser.agentID || agentUser.id,
          last_message: arg.message,
          // agentName: displayAgentName,
          messageType: "telegram",
          id: clientUser.id,
          agentName: agentUser.Agent_Name,
          ourNumber: clientUser.ourNumber,
          appId: clientUser.appId,
          chatUpdated: `${time}`,
          referenceName: clientUser.referenceName,
          from: myextension + "-Agent",
          name: myextension + "-Agent",
        };
        saveMessageLogs = curlMessage;
      }

      const conversationUpdateKey = allocatedRoomKey;
      smsConversationListUpdate(
        conversationUpdateKey,
        clientUser.allocated_room,
        arg.url,
      );

      if (Number(clientUser.isReceived == 0)) {
        await redisClient.hSet(agentKey, {
          agent_unread: Number(agentUser.agent_unread) - 1,
        });
      }

      let updateUnread;

      await redisClient.zRem(
        `${subdomain}-overallUnreadCount`,
        `${subdomain}-unread-${clientKey}`,
      );
      const overallUnreadCount = await redisClient.zCard(
        `${subdomain}-overallUnreadCount`,
      );

      let unassignedUpdateUnread = {
        agent_unread: overallUnreadCount,
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        console.log("this is assigned");
        let agentKeyArray = clientUser.assigned_agent;
        let agentKey;

        console.log("raw agentList:", agentKeyArray);
        console.log("type:", typeof agentKeyArray);

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }

        const agentUserPrev = await redisClient.hGetAll(agentKey);
        const agentUnreadCount = `unread-${agentKey}`;
        console.log(
          "dito baabah",
          agentUnreadCount,
          agentUnreadCount,
          `unread-${clientKey}`,
        );
        await redisClient.zRem(`${agentUnreadCount}`, `unread-${clientKey}`);
        console.log("0");
        const assignedCountResult = await redisClient.zCard(
          `${agentUnreadCount}`,
        );
        console.log("1");
        unassignedUpdateUnread.total = assignedCountResult;
        unassignedUpdateUnread.extension = agentUserPrev.extension;
        console.log("2");
        //  const totalUnreadSent = unreadCountAll + updatedUnreadCount;

        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);
        console.log("3");

        if (agentDetails?.roomID !== "all_messages") {
          console.log("this is the agentsocket id", agentSocketId);
          // if(noAssigned == 1){
          //   socketSignal.emit("chatParameters", "queue", agentStatus, agentSocketId);
          // }

          console.log("1 payload", messagePayload);

          if (
            messagePayload2 != null &&
            Object.keys(messagePayload2).length > 0 !== ""
          ) {
            socketSignal.emit(
              "chatParameters",
              "queue7",
              messagePayload2,
              `${fqdnValue}-all_messages`,
            );

            console.log("2 payload", messagePayload2);
          }
          if (
            messagePayload != null &&
            Object.keys(messagePayload).length > 0 !== ""
          ) {
            socketSignal.emit(
              "chatParameters",
              "queue7",
              messagePayload,
              `${fqdnValue}-all_messages`,
            );
          }

          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            unassignedUpdateUnread,
            `${fqdnValue}-all_messages`,
          );
        }

        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          console.log("2 payload", messagePayload2);
          socketSignal.emit(
            "chatParameters",
            "queue7",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }
        if (
          messagePayload != null &&
          Object.keys(messagePayload).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue7",
            messagePayload,
            `${fqdnValue}-all_messages`,
          );
        }

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );
      } else {
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );

        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue7",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }

        if (
          messagePayload != null &&
          Object.keys(messagePayload).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue7",
            messagePayload,
            `${fqdnValue}-all_messages`,
          );
        }
      }

      // const redisKeyVerified = `twilioconfig:sms:config:8d302e7f-b3c9-4314-942a-c9c3264d5050:incorporissintsi.flowersconradplc.ringq.cloud`
      // const accessTokenChallenge = clientUser.accessToken;
      // const curlTo = clientUser.email;
      // const curlMessage = arg.message
      // const curlFrom = clientUser.id;
      // const curlUrl = `https://api.twilio.com/2010-04-01/Accounts/${clientUser.accountSid}/Messages.json`;
      // const curlAccountSid = clientUser.accountSid;
      // const curlMessagingServiceSid = clientUser.MessagingServiceSid;
      // console.log("twilioCurl", accessTokenChallenge, curlTo, curlMessage, curlUrl, curlFrom, curlAccountSid, curlMessagingServiceSid);
      // const curlResult = await twilioCurl(accessTokenChallenge, curlTo, curlMessage, curlUrl, curlFrom, curlAccountSid, curlMessagingServiceSid)

      // console.log("this is curl result", curlResult);

      // curl

      // clientUser.id

      const curlTo = clientUser.id;
      const curlAccessToken = clientUser.accessToken;
      const curlUrl = `https://api.telegram.org/bot${curlAccessToken}/${msgMethod}`;
      console.log("msgtypeeee", msgType);
      if (curlCaption !== "") {
        console.log("here ba 1");
        await telegramCurl(
          curlAccessToken,
          curlTo,
          curlMessage,
          curlUrl,
          msgType,
          curlCaption,
        );
      } else {
        console.log("here ba 2");
        await telegramCurl(
          curlAccessToken,
          curlTo,
          curlMessage,
          curlUrl,
          msgType,
        );
      }
      let pushWebMessage = savedMessage;
      //temp agent
      pushWeb(
        pushWebMessage,
        clientKey,
        subdomain,
        agentUser.Agent_Name,
        "telegram",
        "agent",
      );
    }
  });

  socket.on("lineChat", async ({ arg, subdomain, extension, mysocket }) => {
    if (!arg || !mysocket) return;

    const {
      message,
      source,
      displayName: username,
      fqdn: fqdnValue,
      idRedis,
    } = arg;
    const userID = source.userId;
    const uniqueId = Date.now();
    let lastMessage = "";
    const referenceName = username;
    let saveMessageLogs;

    // 1. Handle Message Types (Simplify File vs Text)
    if (["document", "image", "file"].includes(message.type)) {
      lastMessage = arg.localFilePath
        ? arg.localFilePath.split("/").slice(4).join("/")
        : "";
      saveMessageLogs = `https://${fqdnValue}/${lastMessage}`;
    } else if (message.type === "text") {
      lastMessage = message.text || "";
      saveMessageLogs = lastMessage;
    } else {
      return; // Skip video/audio/others
    }

    const verified = await verifyToken5(
      "lineconfig:fqdn:",
      fqdnValue,
      {
        mysocket,
        text: lastMessage,
        fqdn: fqdnValue,
        messageType: "line",
        username: userID,
        id: userID,
        referenceName: referenceName,
      },
      idRedis,
    );

    if (!verified) {
      console.log("Token verification failed for", arg.url, arg.client_ID);

      arg.status = "failed";
      arg.msg =
        "Your Line configuration appears to be invalid or misconfigured, " +
        "so this message can't be sent. Please check your settings and try again.";

      socketSignal.emit("chatParameters", "queueError", arg, mysocket);
      return;
    }
    const { accessToken, name: appId, id: pageId, clientKey } = verified;

    const cleanedPageId = pageId ? pageId.replace(/-/g, "") : "";
    const email = cleanedPageId;

    // const username = String(userID);

    // 2. Data Retrieval & Verification
    let clientUser = await redisClient.hGetAll(clientKey);
    const hasClient = Object.keys(clientUser).length > 0;

    let currentMsgIndex = clientUser.msgIndex ? Number(clientUser.msgIndex) : 0;
    console.log("this is current msg index", currentMsgIndex);

    const time = String(Date.now());

    // 3. Room Allocation & Unread Logic
    let allocatedRoom = "NEW";
    if (
      hasClient &&
      clientUser.assigned_agent &&
      clientUser.assigned_agent !== "[]"
    ) {
      allocatedRoom =
        clientUser.allocated_room === "CLOSED"
          ? "NEW"
          : clientUser.allocated_room;
    }
    const agentUnread = hasClient
      ? Number(clientUser.agent_unread || 0) + 1
      : 1;

    // 4. Consolidated Redis Update
    const clientUpdate = {
      socketID: mysocket,
      allocated_room: allocatedRoom,
      last_message: lastMessage,
      message: lastMessage,
      messageType: "line",
      agent_unread: agentUnread,
      client_unread: 0,
      fqdn: fqdnValue,
      number: pageId,
      pageId,
      appId,
      accessToken,
      status: "online",
      chatUpdated: `${time}`,
      referenceName: referenceName,
      ...(hasClient
        ? {}
        : {
            id: userID,
            username: userID,
            created: `${time}`,
            email: email,
            role: "customer",
            Dept: "customer",
          }),
    };
    await redisClient.hSet(clientKey, clientUpdate);

    const convertedEmail = email.trim().replace(/[ -]+/g, "/");
    // 5. Logs and History
    const convKey = `${fqdnValue}:line:${userID}:${userID}-${convertedEmail}`;

    const lastMessageStr = String(lastMessage || "");
    const uniqueIdStr = String(uniqueId || Date.now());
    const usernameStr = String(username || "unknown");
    const pageIdStr = String(pageId || "");

    await redisClient.lPush(convKey, [
      lastMessageStr,
      time,
      referenceName,
      referenceName,
      pageIdStr,
    ]);

    const logData = {
      date: time,
      username: referenceName,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      messageType: clientUser.messageType || "line",
      conversationsFetch: convKey,
    };
    const logData2 = {
      date: time,
      username: referenceName,
      email: "",
      to: clientUser.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "",
      message: saveMessageLogs,
    };

    (await conversationLogsAdd("client", logData, fqdnValue),
      await conversationLogsAddExtension("client", logData2, convKey),
      await smsConversationListUpdate(
        `${userID}-${userID}-line-${email}`,
        allocatedRoom,
        fqdnValue,
      ));

    currentMsgIndex++;

    await redisClient.hSet(clientKey, {
      msgIndex: currentMsgIndex,
    });
    await saveMessageForSearch(
      `${lastMessage}`,
      time,
      `${userID}`,
      `${userID}`,
      `user`, // Pass profile here
      uniqueIdStr,
      `${appId}`,
      `${appId}`,
      convKey,
      `line`,
      `${clientKey}`,
      lastMessageStr,
      time,
      referenceName,
      referenceName,
      pageIdStr,
      fqdnValue,
    );

    // 6. Agent Assignment & Unread Signaling
    let agentList = [];
    try {
      const assigned = clientUser.assigned_agent;
      agentList = Array.isArray(assigned)
        ? assigned
        : JSON.parse(assigned || "[]");
    } catch {
      agentList = [clientUser.assigned_agent].filter(Boolean);
    }

    await redisClient.zAdd(`${fqdnValue}-overallUnreadCount`, {
      score: uniqueId,
      value: `unread-${clientKey}`,
    });
    const overallUnread = await redisClient.zCard(
      `${fqdnValue}-overallUnreadCount`,
    );

    if (agentList.length > 0) {
      const agentKey = agentList[0];
      const agentDetails = await redisClient.hGetAll(agentKey);

      let assigned = JSON.parse(agentDetails.assigned || "[]");
      assigned = [clientKey, ...assigned.filter((c) => c !== clientKey)];

      await redisClient.hSet(agentKey, {
        agent_unread: Number(agentDetails.agent_unread || 0) + 1,
        assigned: JSON.stringify(assigned),
      });

      await redisClient.zAdd(`unread-${agentKey}`, {
        score: uniqueId,
        value: `unread-${clientKey}`,
      });
      const agentCount = await redisClient.zCard(`unread-${agentKey}`);

      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        {
          agent_unread: overallUnread,
          total: agentCount,
          extension: agentDetails.extension,
        },
        `${fqdnValue}-all_messages`,
      );
    } else {
      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        { agent_unread: overallUnread },
        `${fqdnValue}-all_messages`,
      );
    }

    // 7. Final Emit & Push
    socketSignal.emit(
      "chatParameters",
      "queue8",
      {
        id: userID,
        name: referenceName,
        from: referenceName,
        unix: uniqueId,
        time: uniqueId,
        message: lastMessage,
        username: usernameStr || username,
        agent_unread: agentUnread,
        client_unread: 0,
        assigned_agent: agentList.length ? JSON.stringify(agentList) : "",
        email: email,
        allocated_room: allocatedRoom,
        last_message: lastMessage,
        messageType: "line",
        status: "online",
        appId,
        pageId,
        useremail: email,
        ourNumber: userID,
        chatUpdated: uniqueId,
        referenceName: referenceName,
      },
      `${fqdnValue}-all_messages`,
    );

    pushWeb(
      safe(lastMessage) || "default",
      clientKey,
      fqdnValue,
      referenceName,
      "line",
      "client",
    );
  });

  socket.on("communicate8Chat", async (argument) => {
    console.log("Line !--> THIS IS CALLED");

    const { arg, subdomain, extension, mysocket } = argument;
    console.log("this is arg", arg);
    if (
      argument === "undefined" ||
      argument === null ||
      arg === "undefined" ||
      arg === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }
    await createSearchIndex(subdomain);
    if (arg.defaultChatSound) {
      const validatedChecker = await chatSettingsChecker(
        arg,
        subdomain,
        mysocket,
      );

      if (validatedChecker == 0) {
        return;
      } else if (validatedChecker == 1) {
        console.log("that's okay dear");
      }
    }

    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let uniqueId = "";
    let length = 16;

    let msgType = "";

    let curlMessage = "";
    let curlMessage2 = "";
    for (let i = 0; i < length; i++) {
      uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    uniqueId = `${Date.now()}${uniqueId}`;
    const time = `${Date.now()}`;
    let validate;
    const senderSocketId = mysocket;
    const time2 = `${time} + 1`;
    const uniqueId2 = `${uniqueId} + 1`;

    let assignedClients = [];
    let agentHolder = null;

    let finalMessage = "";
    const fqdnValue = arg.url;

    let myextension = arg.agent_ID;
    let assignedAgents;

    let msgFileSize = "";
    let msgFileName = "";
    let saveMessageLogs;

    // Saved for later
    const lineConversationKey = `${arg.conversationkey}`;
    const allocatedRoomKey = arg.roomkey;

    //temporary
    const clientKey = `${arg.client_key}`;
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }

    let agentKey = arg.agent_key;

    const agentUser = await redisClient.hGetAll(agentKey);
    if (!agentUser) {
      console.log("No client found for userKey", agentUser);
      return;
    }

    console.log("this is agent user", agentUser);

    //Start

    if (
      (arg.permission == false && !arg.prevagent) ||
      (arg.permission == true && !arg.prevagent)
    ) {
      console.log("okba");
      if (clientUser.assigned_agent && clientUser.assigned_agent !== null) {
        // Case 1: if client is assigned to agent who is attempting to send message

        if (clientUser.agent_unread) {
          initialAgentUnread = Number(clientUser.agent_unread);
        }

        if (clientUser.assigned_agent) {
          const failedAgentSocket = agentUser.socketID;
          let assignedAgents = [];

          try {
            assignedAgents = JSON.parse(clientUser.assigned_agent);
            if (!Array.isArray(assignedAgents)) assignedAgents = [];
          } catch {
            assignedAgents = [];
          }

          // if (!assignedAgents.includes(agentKey)) {
          // const messagePayload = {
          //   message: "You are not listed as an assigned agent to this conversation. Unable to send a message!",
          //   status: "failed",
          // };

          // socketSignal.emit(
          //   "chatParameters",
          //   "queueError",
          //   messagePayload,
          //   failedAgentSocket
          // );
          // console.log("YOU ARE NOT THE AGENT!!")

          // return;
          // }
        }

        if (clientUser.assigned_agent) {
          validate = true;
          agentHolder = agentUser;
          if (agentUser && agentUser.assigned) {
            try {
              assignedClients = JSON.parse(agentUser.assigned);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedClients = [];
            }
          } else {
            assignedClients = [];
          }

          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            assignedClients = assignedClients.filter(
              (x) => x.trim() !== clientKey.trim(),
            );

            assignedClients.unshift(clientKey.trim());
            console.log("this is the map", assignedClients);
            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          } else {
            console.log("Assigning client to agent...");
            assignedClients.unshift(clientKey);

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });

            console.log("Proceed now to validate part");
          }
        } else {
          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            try {
              assignedClients = JSON.parse(agentUser.assigned || "[]");
            } catch (e) {
              console.error("Failed to parse assigned clients, resetting:", e);
              assignedClients = [];
            }
            assignedClients.unshift(clientKey);
            agentHolder = agentUser;
            validate = true;
          }
        }
      } else {
        noAssigned = 1;
        try {
          assignedClients = JSON.parse(agentUser.assigned || "[]");
        } catch (e) {
          console.error("Failed to parse assigned clients, resetting:", e);
          assignedClients = [];
        }
        assignedClients.unshift(clientKey);
        agentHolder = agentUser;
        validate = true;
      }

      if (arg.prevagent && arg.prevagent !== "") {
        console.log("mahalaga");
        const previousAgentUser = await redisClient.hGetAll(arg.prevagent);
        prevAssignedAgent = JSON.parse(
          previousAgentUser.assigned_agent || "[]",
        );
        prevAssignedAgent = prevAssignedAgent.filter(
          (x) => x.trim() !== clientKey.trim(),
        );

        await redisClient.hSet(arg.prevagent.toString(), {
          assigned: JSON.stringify(prevAssignedAgent || []),
        });
      }
    } else if (
      (arg.permission == true && arg.prevagent) ||
      (arg.permission == false && arg.prevagent)
    ) {
      console.log("1231312312312");
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
          console.log("123131");
        } else {
          assignedAgents.unshift(arg.prevagent);
          console.log("12313133");
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      console.log("Updated agent list:", assignedAgents);
      validate = true;
    }

    clientUser.client_unread =
      parseInt(clientUser.client_unread ?? "0", 10) + 1;
    clientUser.agent_unread = 0;

    if (validate) {
      console.log("comm 7 -> validate");

      // 1. CONSOLIDATED PARSING: Get a clean JS Array
      try {
        assignedAgents =
          typeof clientUser.assigned_agent === "string"
            ? JSON.parse(clientUser.assigned_agent || "[]")
            : clientUser.assigned_agent || [];
      } catch (e) {
        console.error("Failed to parse assigned agents:", e);
        assignedAgents = [];
      }

      // 2. MODIFY ARRAY: Logic for permissions
      if (Array.isArray(assignedAgents)) {
        // Always remove the current agent first to avoid duplicates
        assignedAgents = assignedAgents.filter(
          (x) => x.trim() !== agentKey.trim(),
        );

        if (
          (arg.permission === false && !arg.prevagent) ||
          (arg.permission == true && !arg.prevagent)
        ) {
          // Add the agent to the front of the list
          assignedAgents.unshift(agentKey);
          console.log("agentkeeey", agentKey);
          console.log("Updated agent list:", assignedAgents);
        } else if (
          (arg.permission === true && arg.prevagent) ||
          (arg.permission === false && arg.prevagent)
        ) {
          console.log("1231312312312");
          assignedAgents = [];
          let newAgent = [];
          if (arg.prevagent && arg.prevagent !== "") {
            //clientUser.assigned_agent

            try {
              newAgent = JSON.parse(clientUser.assigned_agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              newAgent = [];
            }

            if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
              assignedAgents.unshift(agentKey);
              console.log("123131");
            } else {
              assignedAgents.unshift(arg.prevagent);
              console.log("12313133");
            }
          } else {
            assignedAgents.unshift(arg.agent_key);
          }

          console.log("Updated agent list:", assignedAgents);
        }
      }

      // 3. LOCK THE STRING: Stringify once for both Redis and Payloads
      console.log("this is it", assignedAgents);
      const assignedAgentsString = JSON.stringify(assignedAgents);
      clientUser.assigned_agent = assignedAgentsString;
      const extAgentKey = assignedAgents[0];
      const extAgentData = await redisClient.hGetAll(extAgentKey);
      const extAgentName = extAgentData.Agent_Name;
      const extAgentExtension = extAgentData.extension;

      // 4. PREPARE PAYLOADS
      let messagePayload;
      let messagePayload2;
      let filePathContainer = [];
      let savedMessage;
      clientUser.last_message = arg.message;
      clientUser.agentName = agentUser.agentName || agentUser.username;
      clientUser.allocated_room = "ACTIVE";

      // 5. UPDATE AGENT'S LIST (If applicable)
      if (
        (arg.permission === false && !arg.prevagent) ||
        (arg.permission == true && !arg.prevagent)
      ) {
        console.log("hi");
        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify([...new Set(assignedClients)]),
        });
      }

      //assignedAgents = []

      // 6. REDIS UPDATE (Message with Files)
      if (arg.filesData !== "" && arg.filesData !== undefined) {
        for (let index = 0; index < arg.filesData.length; index++) {
          const file = arg.filesData[index].name.split(".");
          const type = arg.filesData[index].type;
          const size = arg.filesData[index].size;
          const filetype = file[file.length - 1];
          const fileNameCurl = arg.filesData[index].name;
          const convertedFileName = arg.filesData[index].name
            .trim()
            .replace(/[-]+/g, " ");
          const fileName = uniqueId + "." + filetype;
          const binaryData = Buffer.from(
            arg.filesData[index].fileBuffer,
            "base64",
          );

          // Save the file locally first
          let localPath = "";
          if (arg.filesData[index].type.startsWith("image")) {
            localPath = `/var/www/webapp/chat_file_upload/line_image/${fileName}`;
            msgType = 2;
            filePathContainer.push(
              "chat_file_upload/line_image/" + fileName,
              type,
              size,
            );
          } else {
            localPath = `/var/www/webapp/chat_file_upload/line_file/${fileName}`;
            if (arg.filesData !== "" && arg.filesData !== undefined) {
              console.log("cheeeek", arg.filesData[index].type);
              if (
                arg.filesData[index].type.endsWith("pdf") ||
                arg.filesData[index].type.endsWith("docx")
              ) {
                console.log("okkkkk???");
                msgType = 4;
                msgFileName = fileNameCurl;
                msgFileSize = size;
                console.log("msgfikaodkas", msgFileName, msgFileSize);
              } else if (arg.filesData[index].type.startsWith("video")) {
                msgType = 3;
              }
              filePathContainer.push(
                "chat_file_upload/line_file/" + fileName,
                type,
                size,
              );
            } else {
              msgType = 1;
            }
          }

          writeFile(localPath, binaryData, async (err) => {
            if (err) {
              console.log({
                result: "error",
                message: "File upload failed: " + err,
                content: fileName,
              });
              return;
            }

            console.log({
              result: "success",
              message: "File uploaded locally",
              content: fileName,
            });
          });

          savedMessage = filePathContainer[0];
          curlMessage = `https://${fqdnValue}/${savedMessage}`;

          await redisClient.lPush(lineConversationKey, [
            `${savedMessage}`,
            `${time}`,
            `${myextension}-Agent`,
            `${myextension}-Agent`,
            `${uniqueId}`,
          ]);

          const conversationLogsData = {
            date: `${time}`,
            username: `${agentUser.Agent_Name} (${agentUser.extension})`,
            email: arg.agent_email,
            to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
            messageType: clientUser.messageType ?? "line",
            conversationsFetch: lineConversationKey,
          };

          const conversationLogsResult = await conversationLogsAdd(
            "agent",
            conversationLogsData,
            subdomain,
          );
          console.log("conversationLogsResult", conversationLogsResult);

          const conversationLogsDataExtension = {
            date: `${time}`,
            username: `${agentUser.Agent_Name} (${agentUser.extension})`,
            email: arg.agent_email,
            to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
            message: `${curlMessage}`,
          };

          const chatHistoryKeyExtension = `${lineConversationKey}`;
          const conversationLogsExtensionResult =
            await conversationLogsAddExtension(
              "agent",
              conversationLogsDataExtension,
              chatHistoryKeyExtension,
            );
          console.log(
            "conversationLogsResult2",
            conversationLogsExtensionResult,
          );

          let currentMsgIndex = clientUser.msgIndex
            ? Number(clientUser.msgIndex)
            : 0;

          // Para sa File
          currentMsgIndex++;

          await redisClient.hSet(clientKey, {
            msgIndex: currentMsgIndex,
          });

          await saveMessageForSearch(
            `${curlMessage}`,
            `${time}`,
            `${agentUser.Agent_Name}`,
            `${agentUser.email}`,
            `agent`, //`${subdomain}`
            `${uniqueId}`,
            `${clientUser.appId}`,
            `${clientUser.pageId}`,
            lineConversationKey,
            `line`,
            `${clientKey}`,
            `${curlMessage}`,
            `${uniqueId}`,
            `${myextension}-Agent`,
            `agent`,
            `${time}`,
            `${subdomain}`,
          );

          await redisClient.hSet(clientKey.toString(), {
            timestamp: `${time}`,
            last_message: savedMessage?.toString() || "",
            message: savedMessage?.toString() || "",
            isReceived: "0",
            agent_unread: Number(0).toString(),
            client_unread: (
              Number(clientUser.client_unread || 0) + 1
            ).toString(),
            assigned_agent: assignedAgentsString,
            allocated_room: "ACTIVE",
            chatUpdated: `${time}`,
            extAgentExtension: extAgentExtension,
            extAgentName: extAgentName,
          });

          messagePayload2 = {
            // agentProfile: agentProfile,
            uid: `${uniqueId2}`,
            socketId: senderSocketId,
            username: `${myextension}-Agent`,
            name: clientUser.username,
            useremail: clientUser.email,
            channel: subdomain,
            message: savedMessage,
            time: `${time2}`,
            allocated_room: "ACTIVE",
            client_unread: clientUser.client_unread,
            agent_unread: clientUser.agent_unread,
            assigned_agent: assignedAgentsString,
            roomID: agentUser.agentID || agentUser.id,
            last_message: savedMessage,
            // agentName: displayAgentName,
            messageType: "line",
            id: clientUser.id,
            agentName: agentUser.Agent_Name,
            ourNumber: clientUser.ourNumber,
            appId: clientUser.appId,
            chatUpdated: `${time2}`,
            referenceName: clientUser.referenceName,
            email: clientUser.email,
          };

          console.log("before 2", messagePayload2);

          if (arg.message !== "") {
            await redisClient.hSet(clientKey.toString(), {
              timestamp: `${time2}`,
              last_message: arg.message?.toString() || "",
              message: arg.message.toString() || "",
              isReceived: "0",
              agent_unread: Number(0).toString(),
              client_unread: (
                Number(clientUser.client_unread || 0) + 1
              ).toString(),
              assigned_agent: assignedAgentsString,
              allocated_room: "ACTIVE",
              chatUpdated: `${time2}`,
              extAgentExtension: extAgentExtension,
              extAgentName: extAgentName,
            });

            curlMessage2 = arg.message;

            await redisClient.lPush(lineConversationKey, [
              `${arg.message}`,
              `${time2}`,
              `${myextension}-Agent`,
              `${myextension}-Agent`,
              `${time}`,
            ]);

            const conversationLogsResult = await conversationLogsAdd(
              "agent",
              conversationLogsData,
              subdomain,
            );
            console.log("conversationLogsResult", conversationLogsResult);

            const conversationLogsDataExtension = {
              date: `${time2}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              message: `${curlMessage2}`,
            };

            const chatHistoryKeyExtension = `${lineConversationKey}`;
            const conversationLogsExtensionResult =
              await conversationLogsAddExtension(
                "agent",
                conversationLogsDataExtension,
                chatHistoryKeyExtension,
              );
            console.log(
              "conversationLogsResult2",
              conversationLogsExtensionResult,
            );

            // Para sa File
            currentMsgIndex++;

            await redisClient.hSet(clientKey, {
              msgIndex: currentMsgIndex,
            });

            await saveMessageForSearch(
              `${curlMessage2}`,
              `${time2}`,
              `${agentUser.Agent_Name}`,
              `${agentUser.email}`,
              `agent`, //`${subdomain}`
              `${uniqueId2}`,
              `${clientUser.appId}`,
              `${clientUser.pageId}`,
              lineConversationKey,
              `line`,
              `${clientKey}`,
              `${curlMessage2}`,
              `${uniqueId2}`,
              `${myextension}-Agent`,
              `none`,
              `${time2}`,
              `${subdomain}`,
            );

            messagePayload = {
              // agentProfile: agentProfile,
              uid: uniqueId,
              socketId: senderSocketId,
              username: `${myextension}-Agent`,
              name: clientUser.username,
              useremail: clientUser.email,
              channel: subdomain,
              message: arg.message,
              time: `${time}`,
              allocated_room: "ACTIVE",
              client_unread: clientUser.client_unread,
              agent_unread: clientUser.agent_unread,
              assigned_agent: assignedAgentsString,
              roomID: agentUser.agentID || agentUser.id,
              last_message: arg.message,
              // agentName: displayAgentName,
              messageType: "line",
              id: clientUser.id,
              agentName: agentUser.Agent_Name,
              ourNumber: clientUser.ourNumber,
              appId: clientUser.appId,
              chatUpdated: `${time}`,
              referenceName: clientUser.referenceName,
              email: clientUser.email,
            };

            console.log("before", messagePayload);
          }
        }
      } else if (!arg.filesData) {
        msgType = 1;
        curlMessage = arg.message;
        savedMessage = arg.message;
        console.log("this is for message only okay?");
        const messageData = {
          message: arg.message,
          time: `${time}`,
          sender: `${myextension}-Agent`,
          channel: subdomain,
          uid: uniqueId,
        };
        console.log("this is  line, ", lineConversationKey);

        await redisClient.lPush(lineConversationKey, [
          `${arg.message}`,
          `${time}`,
          `${myextension}-Agent`,
          `${myextension}-Agent`,
          `${uniqueId}`,
        ]);

        const conversationLogsData = {
          date: `${time}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
          messageType: clientUser.messageType ?? "line",
          conversationsFetch: lineConversationKey,
        };

        const conversationLogsResult = await conversationLogsAdd(
          "agent",
          conversationLogsData,
          subdomain,
        );
        console.log("conversationLogsResult", conversationLogsResult);

        const conversationLogsDataExtension = {
          date: `${time}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.username ? `${clientUser.username}` : "",
          message: `${arg.message}`,
        };

        const chatHistoryKeyExtension = `${lineConversationKey}`;
        const conversationLogsExtensionResult =
          await conversationLogsAddExtension(
            "agent",
            conversationLogsDataExtension,
            chatHistoryKeyExtension,
          );
        console.log("conversationLogsResult2", conversationLogsExtensionResult);

        let currentMsgIndex = clientUser.msgIndex
          ? Number(clientUser.msgIndex)
          : 0;

        currentMsgIndex++;

        await redisClient.hSet(clientKey, {
          msgIndex: currentMsgIndex,
        });

        await saveMessageForSearch(
          `${arg.message}`,
          `${time}`,
          `${agentUser.Agent_Name}`,
          `${agentUser.email}`,
          `agent`, //`${subdomain}`
          `${uniqueId}`,
          `${clientUser.appId}`,
          `${clientUser.pageId}`,
          lineConversationKey,
          `line`,
          `${clientKey}`,
          `${arg.message}`,
          `${uniqueId}`,
          `${myextension}-Agent`,
          `agent`,
          `${time}`,
          `${subdomain}`,
        );

        await redisClient.hSet(clientKey.toString(), {
          timestamp: `${time}`,
          last_message: arg.message?.toString() || "",
          message: arg.message?.toString() || "",
          isReceived: "0",
          agent_unread: Number(0).toString(),
          client_unread: (Number(clientUser.client_unread || 0) + 1).toString(),
          assigned_agent: assignedAgentsString,
          allocated_room: "ACTIVE",
          chatUpdated: `${time}`,
          extAgentExtension: extAgentExtension,
          extAgentName: extAgentName,
        });

        messagePayload = {
          // agentProfile: agentProfile,
          uid: uniqueId,
          socketId: senderSocketId,
          username: `${myextension}-Agent`,
          name: clientUser.username,
          useremail: clientUser.email,
          channel: subdomain,
          message: arg.message,
          time: `${time}`,
          allocated_room: "ACTIVE",
          client_unread: clientUser.client_unread,
          agent_unread: clientUser.agent_unread,
          assigned_agent: assignedAgentsString,
          roomID: agentUser.agentID || agentUser.id,
          last_message: arg.message,
          // agentName: displayAgentName,
          messageType: "line",
          id: clientUser.id,
          agentName: agentUser.Agent_Name,
          ourNumber: clientUser.ourNumber,
          appId: clientUser.appId,
          chatUpdated: `${time}`,
          referenceName: clientUser.referenceName,
          email: clientUser.email,
        };
      }

      const conversationUpdateKey = allocatedRoomKey;
      smsConversationListUpdate(
        conversationUpdateKey,
        clientUser.allocated_room,
        arg.url,
      );

      const conversationLogsData = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
        messageType: clientUser.messageType ?? "line",
        conversationsFetch: lineConversationKey,
      };

      if (Number(clientUser.isReceived == 0)) {
        await redisClient.hSet(agentKey, {
          agent_unread: Number(agentUser.agent_unread) - 1,
        });
      }

      let updateUnread;

      await redisClient.zRem(
        `${subdomain}-overallUnreadCount`,
        `${subdomain}-unread-${clientKey}`,
      );
      const overallUnreadCount = await redisClient.zCard(
        `${subdomain}-overallUnreadCount`,
      );

      let unassignedUpdateUnread = {
        agent_unread: overallUnreadCount,
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        console.log("this is assigned");
        let agentKeyArray = clientUser.assigned_agent;
        let agentKey;

        console.log("raw agentList:", agentKeyArray);
        console.log("type:", typeof agentKeyArray);

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }

        const agentUnreadCount = `unread-${agentKey}`;
        console.log(
          "dito baabah",
          agentUnreadCount,
          agentUnreadCount,
          `unread-${clientKey}`,
        );
        const agentUserPrev = await redisClient.hGetAll(agentKey);
        await redisClient.zRem(`${agentUnreadCount}`, `unread-${clientKey}`);
        console.log("0");
        const assignedCountResult = await redisClient.zCard(
          `${agentUnreadCount}`,
        );
        console.log("1");
        unassignedUpdateUnread.total = assignedCountResult;
        unassignedUpdateUnread.extension = agentUserPrev.extension;
        console.log("2");
        //  const totalUnreadSent = unreadCountAll + updatedUnreadCount;

        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);
        console.log("3");

        if (agentDetails?.roomID !== "all_messages") {
          console.log("this is the agentsocket id", agentSocketId);
          // if(noAssigned == 1){
          //   socketSignal.emit("chatParameters", "queue", agentStatus, agentSocketId);
          // }

          console.log("1 payload", messagePayload);

          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            unassignedUpdateUnread,
            `${fqdnValue}-all_messages`,
          );

          if (
            messagePayload2 != null &&
            Object.keys(messagePayload2).length > 0 !== ""
          ) {
            console.log("2 payload", messagePayload2);
            socketSignal.emit(
              "chatParameters",
              "queue8",
              messagePayload2,
              `${fqdnValue}-all_messages`,
            );
          }
          if (
            messagePayload != null &&
            Object.keys(messagePayload).length > 0 !== ""
          ) {
            socketSignal.emit(
              "chatParameters",
              "queue8",
              messagePayload,
              `${fqdnValue}-all_messages`,
            );
          }
        }

        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          console.log("2 payload", messagePayload2);
          socketSignal.emit(
            "chatParameters",
            "queue8",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }

        if (
          messagePayload != null &&
          Object.keys(messagePayload).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue8",
            messagePayload,
            `${fqdnValue}-all_messages`,
          );
        }

        console.log("4");
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );
        console.log("5");
      } else {
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );

        if (
          messagePayload2 != null &&
          Object.keys(messagePayload2).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue8",
            messagePayload2,
            `${fqdnValue}-all_messages`,
          );
        }

        if (
          messagePayload != null &&
          Object.keys(messagePayload).length > 0 !== ""
        ) {
          socketSignal.emit(
            "chatParameters",
            "queue8",
            messagePayload,
            `${fqdnValue}-all_messages`,
          );
        }
      }

      // const redisKeyVerified = `twilioconfig:sms:config:8d302e7f-b3c9-4314-942a-c9c3264d5050:incorporissintsi.flowersconradplc.ringq.cloud`
      // const accessTokenChallenge = clientUser.accessToken;
      // const curlTo = clientUser.email;
      // const curlMessage = arg.message
      // const curlFrom = clientUser.id;
      // const curlUrl = `https://api.twilio.com/2010-04-01/Accounts/${clientUser.accountSid}/Messages.json`;
      // const curlAccountSid = clientUser.accountSid;
      // const curlMessagingServiceSid = clientUser.MessagingServiceSid;
      // console.log("twilioCurl", accessTokenChallenge, curlTo, curlMessage, curlUrl, curlFrom, curlAccountSid, curlMessagingServiceSid);
      // const curlResult = await twilioCurl(accessTokenChallenge, curlTo, curlMessage, curlUrl, curlFrom, curlAccountSid, curlMessagingServiceSid)

      // console.log("this is curl result", curlResult);

      // curl

      // clientUser.id

      const curlTo = clientUser.id;
      const curlAccessToken = clientUser.accessToken;
      // const curlUrl = `https://api.telegram.org/bot${curlAccessToken}/${msgMethod}`;
      const curlPreviewUrl =
        "https://cap.ringq.com/partnerKit/upload_6985774096ca98.57377738.png";

      if (msgFileName !== "" && msgFileSize !== "") {
        const curlResult = await lineCurl(
          curlAccessToken,
          curlTo,
          curlMessage,
          msgType,
          curlPreviewUrl,
          msgFileName,
          msgFileSize,
        );
      } else {
        const curlResult = await lineCurl(
          curlAccessToken,
          curlTo,
          curlMessage,
          msgType,
          curlPreviewUrl,
        );
      }

      console.log("msgtypeee line", msgType);
      console.log("eii", curlMessage, curlPreviewUrl);
      if (curlMessage2 !== "") {
        console.log("this is cm2", curlMessage2);
      }

      if (curlMessage2 !== "") {
        console.log("ack");
        await lineCurl(curlAccessToken, curlTo, curlMessage2, 1, "");
      }

      pushWebMessage = savedMessage;
      //temp agent
      pushWeb(
        pushWebMessage,
        clientKey,
        subdomain,
        agentUser.Agent_Name,
        "line",
        "agent",
      );
    }
  });

  socket.on("whatsappChat", async (argument) => {
    try {
      const { arg, mysocket } = argument;
      if (!arg || !mysocket) return;
      console.log("this is whatzaf", arg);
      let param;
      // --- JSON PARSING LOGIC ---
      if (Array.isArray(arg)) {
        const firstItem = arg[0];
        if (typeof firstItem === "string") {
          try {
            param = JSON.parse(firstItem);
          } catch (err) {
            param = { raw: firstItem };
          }
        } else {
          param = firstItem;
        }
      } else if (typeof arg === "string") {
        try {
          param = JSON.parse(arg);
        } catch (err) {
          param = { raw: arg };
        }
      } else {
        param = arg;
      }

      console.log("this is whatsapp param", param);

      const value = param.entry?.[0]?.changes?.[0]?.value;
      const fqdnValue = param.fqdn;
      const appIdValue = String(param.appId);
      if (!value || !value.messages || !value.contacts) return;

      const message = value.messages[0];
      const contact = value.contacts[0];
      const metadata = value.metadata;
      const displayNumber = metadata?.display_phone_number;

      const id = message.from_user_id;
      const fromNumber = message.from;
      const username = id;

      const fromId = displayNumber;
      const pushwebUsername = contact.profile.name.trim();
      const userName = contact.profile.name.trim();
      const referenceName = String(userName);
      const clientKey = `${id}-whatsapp-${id}-${appIdValue}-${fqdnValue}`;
      const type = message.type;
      const email = appIdValue;

      const time = String(Date.now());
      await createSearchIndex(fqdnValue);

      let resultStr = "";
      let caption = "";

      const enableOwner = await redisClient.get(
        `chatsettings:${fqdnValue}-enableOwnerManagerAssign`,
      );
      const enableRoundRobin = await redisClient.get(
        `chatsettings:${fqdnValue}-enableRoundRobin`,
      );
      const enableEveryAgentQ = await redisClient.get(
        `chatsettings:${fqdnValue}-enableEveryAgentQ`,
      );
      console.log(
        "chatsettings checker",
        "en-owner:",
        enableOwner,
        "en-agent:",
        enableEveryAgentQ,
        "en-robin",
        enableRoundRobin,
      );

      let assignedAgentRoundRobin = "";

      // id username id, appIdValue email, refer
      // --- OPTIMIZED FILE HANDLING (THE FIX) ---
      if (param.fileBuffer && param.fileBuffer.length > 0) {
        let filePathContainer = [];
        try {
          for (let index = 0; index < param.fileBuffer.length; index++) {
            const fileInfo = param.fileInfo[index];
            const fileExt = fileInfo.name.split(".").pop();
            const fileName = `whatsapp-${fqdnValue}-${Date.now()}-${index}.${fileExt}`;
            const savePath = "/var/www/webapp/chat_file_upload/" + fileName;

            const binaryData = Buffer.from(param.fileBuffer[index], "base64");
            // Force wait for disk I/O before continuing
            writeFile(localPath, binaryData, async (err) => {
              if (err) {
                console.log({
                  result: "error",
                  message: "File upload failed: " + err,
                  content: fileName,
                });
                return;
              }

              console.log({
                result: "success",
                message: "File uploaded locally",
                content: fileName,
              });
            });

            filePathContainer.push("chat_file_upload/" + fileName);
          }
          resultStr = filePathContainer[0];

          // CRITICAL: Immediately remove massive strings from memory
          delete param.fileBuffer;
        } catch (err) {
          console.error("WhatsApp File Write Error:", err);
        }
      } else if (param.filePath) {
        resultStr = param.filePath.split("/").slice(4).join("/");
      }

      // Determine content based on type
      let MessageContent = "";
      let saveLogsMessage;
      if (type === "text") {
        MessageContent = message.text?.body || "";
        saveLogsMessage = message.text?.body || "";
      } else if (["document", "image", "video", "audio"].includes(type)) {
        MessageContent = resultStr;
        saveLogsMessage = `https://${fqdnValue}/${resultStr}`;
        if (message[type]?.caption) caption = message[type].caption;
        if (["video", "audio"].includes(type) && !MessageContent) return;
      }

      const messageTypeMap = {
        text: message.text?.body || "",
        document: resultStr || "",
        image: resultStr || "",
        video: resultStr || "",
        audio: resultStr || "",
      };

      // --- REDIS USER LOGIC ---
      let user = (await redisClient.exists(clientKey))
        ? await redisClient.hGetAll(clientKey)
        : null;
      console.log("am i", user, clientKey);
      let allocatedRoom = "NEW";
      let agentUnread = 1;

      if (!user) {
        user = {
          id,
          username: username,
          created: `${time}`,
          email: appIdValue,
          role: "customer",
          socketID: mysocket,
          Dept: "customer",
          client_unread: 0,
          agent_unread: 1,
          last_message: MessageContent,
          allocated_room: "NEW",
          messageType: "whatsapp",
          fqdn: fqdnValue,
          number: displayNumber,
          pageId: appIdValue,
          appId: appIdValue,
          chatUpdated: `${time}`,
          referenceName: referenceName,
          phone: fromNumber,
          msgIndex: 0,
        };

        await redisClient.hSet(clientKey, user);
        await redisClient.lPush(
          `${fqdnValue}-client_data`,
          `${id}-whatsapp-${id}-${appIdValue}-${fqdnValue}`,
        );
        await createSearchIndexProfile(fqdnValue);
        console.log("first time user added createSearchIndexProfile");
        await saveProfileDataForSearch(
          `${username}`, // username
          `${id}`, // email
          `${clientKey}`,
          `${referenceName}`,
        );

        // const noAssignedList = await redisClient.zRange(
        //   `${fqdnValue}-noAssigned`,
        //   0,
        //   0,
        // );
        // round robin
        // if (enableRoundRobin == 1 && noAssignedList.length > 0) {
        //   assignedAgentRoundRobin = noAssignedList[0];

        //   // Remove them from the pool (or update score to move to back)
        //   // await redisClient.zRem(
        //   //   `${fqdnValue}-noAssigned`,
        //   //   assignedAgentRoundRobin,
        //   // );

        //   const agentKey = assignedAgentRoundRobin;
        //   agentUser = await redisClient.hGetAll(agentKey);

        //   if (agentUser) {
        //     // Prepare agent's assignment list
        //     let currentAgentAssigned = [];
        //     try {
        //       currentAgentAssigned = JSON.parse(agentUser.assigned || "[]");
        //     } catch {
        //       currentAgentAssigned = [];
        //     }

        //     // Add client to agent
        //     if (!currentAgentAssigned.includes(clientKey)) {
        //       currentAgentAssigned.unshift(clientKey);
        //     }

        //     await redisClient.hSet(agentKey, {
        //       assigned: JSON.stringify(currentAgentAssigned),
        //     });

        //     // Add agent to client
        //     await redisClient.hSet(clientKey, {
        //       assigned_agent: JSON.stringify([agentKey]), // Simplified if single assignment
        //     });

        //     user.assigned_agent = JSON.stringify([agentKey]);
        //     // Handle Unread Logic
        //     const agentUnreadKey = `unread-${agentKey}`;
        //     await redisClient.zAdd(agentUnreadKey, {
        //       score: Date.now(),
        //       value: `unread-${clientKey}`,
        //     });

        //     const agentTotalUnread = await redisClient.zCard(agentUnreadKey);
        //     const overallUnread = await redisClient.zCard(
        //       `${fqdnValue}-overallUnreadCount`,
        //     );

        //     result = await redisClient.zCard(`${agentUnreadKey}`);

        //     // Emit update
        //     socketSignal.emit(
        //       "chatParameters",
        //       "queue_updatedata",
        //       {
        //         agent_unread: overallUnread,
        //         total: agentTotalUnread,
        //         extension: agentUser.extension,
        //       },
        //       `${fqdnValue}-all_messages`,
        //     );
        //   }
        // } else if (enableRoundRobin == 1 && noAssignedList.length == 0) {
        //   // NO AGENT AVAILABLE: Add client to a pending queue
        //   // RPUSH adds to the end of the list (First-In, First-Out)
        //   user.assigned_agent = "";
        //   await redisClient.rPush(`${fqdnValue}-waitingQueue`, clientKey);
        //   console.log(
        //     `🕒 No agents free. Client ${clientKey} added to waiting queue.`,
        //   );

        //   // Optional: Emit to the client that they are in a queue
        //   //socketSignal.emit("queueStatus", { position: "waiting" }, clientKey);
        // } else {
        //   user.assigned_agent = "";
        // }

        user = await redisClient.hGetAll(clientKey);
      } else {
        agentUnread = Number(user.agent_unread || 0) + 1;
        // allocatedRoom = user.allocated_room;
        console.log("user.allocated_room", user.allocated_room, user);
        allocatedRoom =
          user.allocated_room === "CLOSED" ? "NEW" : user.allocated_room;
        console.log("allocated_room display", allocatedRoom);

        console.log(
          "existing",
          clientKey,
          mysocket,
          allocatedRoom,
          MessageContent,
          agentUnread,
          0,
          fqdnValue,
          displayNumber,
          appIdValue,
          appIdValue,
          referenceName,
          fromNumber,
          user.msgIndex ? Number(user.msgIndex) + 1 : 1,
        );
        await redisClient.hSet(clientKey, {
          seen: "",
          socketID: mysocket,
          allocated_room: allocatedRoom,
          last_message: MessageContent,
          agent_unread: agentUnread,
          client_unread: 0,
          fqdn: fqdnValue,
          number: displayNumber,
          pageId: appIdValue,
          appId: appIdValue,
          referenceName: referenceName,
          chatUpdated: `${time}`,
          phone: fromNumber,
          msgIndex: user.msgIndex ? Number(user.msgIndex) + 1 : 1,
        });
      }

      let currentMsgIndex = user.msgIndex ? Number(user.msgIndex) : 0;

      // --- REDIS MESSAGE PERSISTENCE ---
      const chatHistoryKey = `${fqdnValue}:whatsapp:919894340047:${id}-${appIdValue}`;
      const msgData = [
        MessageContent,
        `${time}`,
        contact.profile.name,
        contact.profile.name,
        message.id,
      ];
      await redisClient.lPush(chatHistoryKey, msgData);

      const time2 = `${Date.now()}`;
      // const uniqueId = `${Date.now() + 1}` balikan
      if (caption) {
        await redisClient.lPush(chatHistoryKey, [
          caption,
          time2,
          contact.profile.name,
          contact.profile.name,
          message.id,
        ]);
      }

      // --- UNREAD COUNTS ---
      // Ditooo
      await redisClient.zRem(
        `${fqdnValue}-overallUnreadCount`,
        `unread-${clientKey}`,
      );
      await redisClient.zAdd(`${fqdnValue}-overallUnreadCount`, {
        score: Date.now(),
        value: `unread-${clientKey}`,
      });
      const overallUnreadCount = await redisClient.zCard(
        `${fqdnValue}-overallUnreadCount`,
      );

      // --- AGENT ASSIGNMENT & EMIT ---
      let agentList = [];
      try {
        agentList = JSON.parse(user.assigned_agent || "[]");
      } catch (e) {
        agentList = [];
      }
      console.log("chck for agentlist", agentList);
      console.log("allocated_room:", allocatedRoom);

      const usernameStr = String(id || "unknown");
      const emitPayload = {
        seen: "",
        id,
        name: referenceName,
        from: referenceName,
        unix: message.timestamp,
        phone: fromNumber,
        username: id,
        message: MessageContent,
        agent_unread: agentUnread,
        client_unread: 0,
        assigned_agent: user.assigned_agent,
        email: appIdValue,
        allocated_room: allocatedRoom,
        last_message: MessageContent,
        messageType: "whatsapp",
        status: "online",
        appId: appIdValue,
        number: displayNumber,
        useremail: email,
        chatUpdated: `${time}`,
        referenceName: referenceName,
      };

      if (agentList.length > 0) {
        const agentKey = agentList[0];
        const agentDetails = await redisClient.hGetAll(agentKey);

        let assignedClients = [];
        try {
          assignedClients = JSON.parse(agentDetails.assigned || "[]");
        } catch (e) {}
        assignedClients = [
          clientKey,
          ...assignedClients.filter((c) => c !== clientKey),
        ];

        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify(assignedClients),
        });
        await redisClient.zAdd(`unread-${agentKey}`, {
          score: Date.now(),
          value: `unread-${clientKey}`,
        });
        const assignedCountResult = await redisClient.zCard(
          `unread-${agentKey}`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          {
            agent_unread: overallUnreadCount,
            total: assignedCountResult,
            extension: agentDetails.extension,
          },
          `${fqdnValue}-all_messages`,
        );
      } else {
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          { agent_unread: overallUnreadCount },
          `${fqdnValue}-all_messages`,
        );
      }

      // Emit the message(s)
      socketSignal.emit(
        "chatParameters",
        "queue3",
        emitPayload,
        `${fqdnValue}-all_messages`,
      );
      if (caption) {
        socketSignal.emit(
          "chatParameters",
          "queue3",
          { ...emitPayload, message: caption, last_message: caption },
          `${fqdnValue}-all_messages`,
        );
      }

      // Final External Calls
      pushWeb(
        MessageContent,
        clientKey,
        fqdnValue,
        contact.profile.name,
        "whatsapp",
        "client",
      );
      whatsappConversationList(
        userName,
        email,
        id,
        `whatsapp:919894340047:${displayNumber}-${id}`,
        fqdnValue,
        allocatedRoom,
      );

      const conversationLogsData = {
        date: `${time}`,
        username: referenceName,
        email: "",
        to: user.extAgentName
          ? `${user.extAgentName} (${user.extAgentExtension})`
          : "",
        messageType: user.messageType ?? "whatsapp",
        conversationsFetch: chatHistoryKey,
      };

      const chatHistoryKeyExtension = `${chatHistoryKey}`;

      const conversationLogsResult = await conversationLogsAdd(
        "client",
        conversationLogsData,
        fqdnValue,
      );

      console.log("check messagecontent", MessageContent);

      const conversationLogsDataExtension = {
        date: `${time}`,
        username: referenceName,
        email: "",
        to: user.extAgentName
          ? `${user.extAgentName} (${user.extAgentExtension})`
          : "",
        message: saveLogsMessage,
      };

      currentMsgIndex++;

      await redisClient.hSet(clientKey, {
        msgIndex: currentMsgIndex,
      });

      await saveMessageForSearch(
        `${saveLogsMessage}`,
        `${time}`,
        `${userName}`,
        `${id}`,
        `user`, // Pass profile here
        `${message.timestamp}`,
        `${appIdValue}`,
        `${appIdValue}`,
        chatHistoryKey,
        `whatsapp`,
        `${clientKey}`,
        saveLogsMessage,
        `${time}`,
        contact.profile.name,
        contact.profile.name,
        message.id,
        fqdnValue,
      );

      const conversationLogsExtensionResult =
        await conversationLogsAddExtension(
          "client",
          conversationLogsDataExtension,
          chatHistoryKeyExtension,
        );
      if (caption) {
        console.log("has caption whatsapp");
        const conversationLogsData = {
          date: `${time2}`,
          username: referenceName,
          email: "",
          to: user.extAgentName
            ? `${user.extAgentName} (${user.extAgentExtension})`
            : "",
          messageType: user.messageType ?? "whatsapp",
          conversationsFetch: chatHistoryKey,
        };

        await conversationLogsAdd("client", conversationLogsData, fqdnValue);
        const conversationLogsDataExtension = {
          date: `${time2}`,
          username: referenceName,
          email: "",
          to: user.extAgentName
            ? `${user.extAgentName} (${user.extAgentExtension})`
            : "",
          message: caption,
        };

        currentMsgIndex++;

        await redisClient.hSet(clientKey, {
          msgIndex: currentMsgIndex,
        });

        const uniqueId = Date.now();
        await saveMessageForSearch(
          `${caption}`,
          `${time2}`,
          `${userName}`,
          `${id}`,
          `user`, // Pass profile here
          `${uniqueId}`,
          `${appIdValue}`,
          `${appIdValue}`,
          chatHistoryKey,
          `whatsapp`,
          `${clientKey}`,
          caption,
          `${time2}`,
          contact.profile.name,
          contact.profile.name,
          message.id,
          fqdnValue,
        );

        await conversationLogsAddExtension(
          "client",
          conversationLogsDataExtension,
          chatHistoryKeyExtension,
        );
      }
      console.log("conversationLogsResult", conversationLogsResult);
      console.log("conversationLogsResult2", conversationLogsExtensionResult);
    } catch (err) {
      console.error("Critical error in whatsappChat event:", err);
    }
  });

  socket.on("communicate3Chat", async (argument) => {
    console.log("samples comchats?");
    const { arg, subdomain, extension, mysocket } = argument;
    let myextension = argument.extension;
    if (
      argument === "undefined" ||
      argument === null ||
      arg === "undefined" ||
      arg === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }
    console.log("this is communicate3 arg", arg);

    const clientPresence =
      `whatsapp:919894340047:` + arg.to + ":" + arg.client_ID;

    console.log("this is the whatsapp clientpresence", clientPresence);

    // Flags and placeholders

    let validate = false;
    let assignedClients = [];
    let agentHolder = null;
    let assignedAgents;
    let finalMessage = "";
    let fqdnValue = arg.url;

    await createSearchIndex(subdomain);

    if (arg.defaultChatSound) {
      const validatedChecker = await chatSettingsChecker(
        arg,
        subdomain,
        mysocket,
      );

      if (validatedChecker == 0) {
        return;
      } else if (validatedChecker == 1) {
        console.log("that's okay dear");
      }
    }

    // ------------------- CLIENT KEY -------------------
    const clientKey = `${arg.to}-whatsapp-${arg.client_username}-${arg.client_ID}-${fqdnValue}`;
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }

    const verified = await verifyToken(
      "whatsappconfig",
      arg.url,
      arg.client_email,
    );

    if (!verified) {
      console.log("Token verification failed for", arg.url, arg.client_ID);

      arg.status = "failed";
      arg.msg =
        "Your Whatsapp configuration appears to be invalid or misconfigured, " +
        "so this message can't be sent. Please check your settings and try again.";

      socketSignal.emit("chatParameters", "queueError", arg, mysocket);
      return;
    }

    const { accessToken, appId } = verified;

    console.log("Verified Token:", accessToken, appId);

    // ------------------- AGENT KEY -------------------
    const agentKey = `${arg.agent_ID}-Agent-${arg.agent_username}-${arg.agent_email}-${fqdnValue}`;

    const agentUser = (await redisClient.exists(agentKey))
      ? await redisClient.hGetAll(agentKey)
      : null;
    if (!agentUser) {
      console.log("No client found for userKey", agentUser);
      return;
    }

    // ------------------- CLIENT -------------------
    if (
      (arg.permission == false && !arg.prevagent) ||
      (arg.permission == true && !arg.prevagent)
    ) {
      console.log("okba tes te");
      if (clientUser.assigned_agent && clientUser.assigned_agent !== null) {
        console.log("samples1");
        // Case 1: if client is assigned to agent who is attempting to send message

        if (clientUser.agent_unread) {
          initialAgentUnread = Number(clientUser.agent_unread);
        }

        if (clientUser.assigned_agent) {
          const failedAgentSocket = agentUser.socketID;
          let assignedAgents = [];

          try {
            assignedAgents = JSON.parse(clientUser.assigned_agent);
            if (!Array.isArray(assignedAgents)) assignedAgents = [];
          } catch {
            assignedAgents = [];
          }
        }

        if (clientUser.assigned_agent && clientUser.assigned_agent !== "[]") {
          validate = true;
          agentHolder = agentUser;
          if (agentUser && agentUser.assigned) {
            try {
              assignedClients = JSON.parse(agentUser.assigned);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedClients = [];
            }
          } else {
            assignedClients = [];
          }

          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            assignedClients = assignedClients.filter(
              (x) => x.trim() !== clientKey.trim(),
            );

            assignedClients.unshift(clientKey.trim());
            console.log("this is the map", assignedClients);
            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          } else {
            console.log("Assigning client to agent...");
            assignedClients.unshift(clientKey);

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });

            console.log("Proceed now to validate part");
          }
        } else {
          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            try {
              assignedClients = JSON.parse(agentUser.assigned || "[]");
            } catch (e) {
              console.error("Failed to parse assigned clients, resetting:", e);
              assignedClients = [];
            }
            assignedClients.unshift(clientKey);
            agentHolder = agentUser;
            validate = true;
          }
        }
        console.log("first if");
      } else {
        console.log("samples2");
        noAssigned = 1;
        try {
          assignedClients = JSON.parse(agentUser.assigned || "[]");
        } catch (e) {
          console.error("Failed to parse assigned clients, resetting:", e);
          assignedClients = [];
        }
        console.log("aaaaaa");
        console.log("dito icheck b4", assignedClients);
        assignedClients.unshift(clientKey);
        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify(assignedClients),
        });
        console.log("dito icheck aer", assignedClients);
        agentHolder = agentUser;
        validate = true;
        console.log("2nd if");
      }

      if (arg.prevagent && arg.prevagent !== "") {
        console.log("mahalaga");
        const previousAgentUser = await redisClient.hGetAll(arg.prevagent);
        prevAssignedAgent = JSON.parse(
          previousAgentUser.assigned_agent || "[]",
        );
        prevAssignedAgent = prevAssignedAgent.filter(
          (x) => x.trim() !== clientKey.trim(),
        );

        await redisClient.hSet(arg.prevagent.toString(), {
          assigned: JSON.stringify(prevAssignedAgent || []),
        });
      }
      console.log("3rd if");
    } else if (arg.permission === true && arg.prevagent) {
      console.log("1231312312312");
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
          console.log("123131");
        } else {
          assignedAgents.unshift(arg.prevagent);
          console.log("12313133");
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      console.log("Updated agent list:", assignedAgents);
      validate = true;
    } else if (arg.permission === false && arg.prevagent) {
      console.log("dito papasok");
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
          console.log("123131");
        } else {
          assignedAgents.unshift(arg.prevagent);
          console.log("12313133");
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      console.log("Updated agent list:", assignedAgents);
      validate = true;
    }

    console.log("4th if");

    client_count = clientUser.client_unread;
    // show as string
    clientUser.client_unread =
      parseInt(clientUser.client_unread ?? "0", 10) + 1;

    clientUser.agent_unread = 0;

    const time = Date.now();
    console.log("validate: ", validate);
    // ------------------- UPDATE STATE -------------------
    if (validate && accessToken) {
      console.log("comm 3 -> validate");
      console.log("this is checking", arg.to, arg.client_ID);

      if (!clientUser.assigned_agent) {
        clientUser.assigned_agent = JSON.stringify([]);
      }
      // const totalClientCount = await redisClient.zCard(agentUnreadCount);

      // IMPORTANT SOLO ASSIGNING JUST DELETE THE BELOW CODE

      try {
        assignedAgents =
          typeof clientUser.assigned_agent === "string"
            ? JSON.parse(clientUser.assigned_agent || "[]")
            : clientUser.assigned_agent || [];
      } catch (e) {
        console.error("Failed to parse assigned agents:", e);
        assignedAgents = [];
      }

      if (Array.isArray(assignedAgents)) {
        // Always remove the current agent first to avoid duplicates
        assignedAgents = assignedAgents.filter(
          (x) => x.trim() !== agentKey.trim(),
        );

        if (
          (arg.permission === false && !arg.prevagent) ||
          (arg.permission == true && !arg.prevagent)
        ) {
          // Add the agent to the front of the list
          assignedAgents.unshift(agentKey);
          console.log("agentkeeey", agentKey);
          console.log("Updated agent list:", assignedAgents);
        } else if (
          (arg.permission === true && arg.prevagent) ||
          (arg.permission === false && arg.prevagent)
        ) {
          console.log("1231312312312");
          assignedAgents = [];
          let newAgent = [];
          if (arg.prevagent && arg.prevagent !== "") {
            //clientUser.assigned_agent

            try {
              newAgent = JSON.parse(clientUser.assigned_agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              newAgent = [];
            }

            if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
              assignedAgents.unshift(agentKey);
              console.log("123131");
            } else {
              assignedAgents.unshift(arg.prevagent);
              console.log("12313133");
            }
          } else {
            assignedAgents.unshift(arg.agent_key);
          }

          console.log("Updated agent list:", assignedAgents);
        }
      }

      const assignedAgentsString = JSON.stringify(assignedAgents);
      clientUser.assigned_agent = assignedAgentsString;

      const extAgentKey = assignedAgents[0];
      const extAgentData = await redisClient.hGetAll(extAgentKey);
      const extAgentName = extAgentData.Agent_Name;
      const extAgentExtension = extAgentData.extension;

      // DOWN TO HERE
      let username = arg.client_username.trim();

      console.log(
        `checcck ${fqdnValue}-conversation-active`,
        `${username}-${username}-whatsapp-${arg.client_ID}`,
      );

      const rem = await redisClient.zRem(
        `${fqdnValue}-conversation-active`,
        `${username}-${username}-whatsapp-${arg.client_ID}`,
      );
      const sent = await redisClient.zAdd(`${fqdnValue}-conversation-active`, {
        score: Date.now(),
        value: `${username}-${username}-whatsapp-${arg.client_ID}`,
      });

      const rem2 = await redisClient.zRem(
        `${fqdnValue}-conversation`,
        `${username}-${username}-whatsapp-${arg.client_ID}`,
      );
      const sent2 = await redisClient.zAdd(`${fqdnValue}-conversation`, {
        score: Date.now(),
        value: `${username}-${username}-whatsapp-${arg.client_ID}`,
      });

      const rem3 = await redisClient.zRem(
        `${fqdnValue}-conversation-new`,
        `${username}-${username}-whatsapp-${arg.client_ID}`,
      );
      const rem4 = await redisClient.zRem(
        `${fqdnValue}-conversation-close`,
        `${username}-${username}-whatsapp-${arg.client_ID}`,
      );
      if ((rem && rem2) & (sent && sent2)) {
        console.log("rem && rem2 && rem3 && rem4");
      }

      console.log(
        "this is conv",
        `${fqdnValue}-conversation`,
        `${username}-${username}-whatsapp-${arg.client_ID}`,
      );

      console.log("appId is", appId);
      const sendApiUrl = `https://graph.facebook.com/v18.0/${appId}/messages`;

      var filePathContainer = [];
      let caption = "";

      if (arg.filesData !== "" && arg.filesData !== undefined) {
        for (var index = 0; index < arg.filesData.length; index++) {
          var file = arg.filesData[index].name.split(".");
          var type = arg.filesData[index].type;
          var size = arg.filesData[index].size;
          var filetype = file[file.length - 1];
          const uniqueId = Date.now();

          // contact.profile.name.trim().replace(/[ -]+/g, "/");

          var convertedFileName = arg.filesData[index].name
            .trim()
            .replace(/[-]+/g, " ");

          var fileName = uniqueId + "." + filetype + "-" + convertedFileName;
          // this must fix up

          const binaryData = Buffer.from(
            arg.filesData[index].fileBuffer,
            "base64",
          );

          if (arg.filesData[index].type.startsWith("image")) {
            try {
              // 1. Sanitize the filename to prevent shell/URL breakage
              const sanitizedName = fileName
                .replace(/\s+/g, "_")
                .replace(/[()]/g, "");
              const localPath =
                "/var/www/webapp/chat_file_upload/wp_img/" + sanitizedName;

              // 2. Ensure we are writing binary data (Buffer)
              // If your binaryData is a base64 string, use Buffer.from(binaryData, 'base64')
              const bufferData = Buffer.isBuffer(binaryData)
                ? binaryData
                : Buffer.from(binaryData, "binary");

              console.log(`[DEBUG] Writing image to: ${localPath}`);

              writeFile(localPath, bufferData, (err) => {
                if (err) {
                  console.error("[ERROR] writeFile failed:", err);
                  return;
                }

                // 3. Verify the file exists and has size > 0
                if (fs.existsSync(localPath)) {
                  const stats = fs.statSync(localPath);
                  console.log(
                    `[VERIFIED] File saved. Size: ${stats.size} bytes`,
                  );

                  if (stats.size === 0) {
                    console.error("[ERROR] File saved but is empty (0 bytes).");
                    return;
                  }
                } else {
                  console.error(
                    "[ERROR] File does not exist on disk after write.",
                  );
                  return;
                }

                // 4. Construct URL and send via WhatsApp Link
                const contentUrl = `https://${fqdnValue}/chat_file_upload/wp_img/${sanitizedName}`;

                const sendPayload = {
                  messaging_product: "whatsapp",
                  to: arg.phone,
                  type: "image",
                  image: { link: contentUrl },
                };

                const sendCurl = `curl -s -X POST "${sendApiUrl}" \
                        -H "Authorization: Bearer ${accessToken}" \
                        -H "Content-Type: application/json" \
                        -d '${JSON.stringify(sendPayload)}'`;

                exec(sendCurl, (sendErr, sendStdout) => {
                  if (sendErr) {
                    console.error("[ERROR] WhatsApp send failed:", sendErr);
                    return;
                  }
                  console.log("[SUCCESS] WhatsApp image sent:", sendStdout);
                });
              });

              // Set tracking variables for Redis
              finalMessage = "chat_file_upload/wp_img/" + sanitizedName;
              filePathContainer.push(finalMessage);
            } catch (error) {
              console.error("[CRITICAL] Image block exception:", error);
            }
          } else if (arg.filesData[index].type.startsWith("file")) {
            const localPath =
              "/var/www/webapp/chat_file_upload/wp_file/" + fileName;

            writeFile(localPath, binaryData, (err) => {
              console.log({
                result: err ? "error" : "success",
                message: err
                  ? "Sorry file upload request has failed."
                  : "Success, file upload completed.",
                content: fileName,
              });
            });

            filePathContainer.push("chat_file_upload/wp_file/" + fileName);
            console.log("this is filepathcont file", filePathContainer[0]);

            const contentUrl = `https://${fqdnValue}/${filePathContainer[0]}`;

            const curlCommand1 = `
              curl -X POST "${sendApiUrl}" \
              -H "Authorization: Bearer ${accessToken}" \
              -H "Content-Type: application/json" \
              -d '${JSON.stringify({
                messaging_product: "whatsapp",
                to: arg.phone,
                type: "document",
                document: {
                  link: contentUrl,
                  filename: arg.filesData[index].name || "",
                },
              })}'
            `;

            exec(curlCommand1, (error, stdout, stderr) => {
              if (error) {
                console.error("Error executing curl:", error);
                if (typeof callback === "function") {
                  callback({ error: "Error executing curl" });
                }
                return;
              }

              const result = JSON.parse(stdout);
              if (typeof callback === "function") {
                callback(result);
              }
            });

            finalMessage = filePathContainer[0];
          } else {
            console.log(
              "Unsupported file type, skipping:",
              arg.filesData[index].name,
            );
          }
          console.log(filePathContainer);
          finalMessage = filePathContainer[0];

          console.log(
            "this is filepath saved in redis and send to q3",
            filePathContainer,
          );
          console.log(`this is finalmessage ${finalMessage}`);
          let success;

          if (finalMessage !== "" && arg.message !== "") {
            console.log("lezcheck", finalMessage, arg.message);

            const saveFile = `https://${fqdnValue}/${finalMessage}`;
            await redisClient.lPush(
              subdomain +
                ":whatsapp:" +
                "919894340047" +
                ":" +
                arg.to +
                "-" +
                arg.client_ID,
              [
                `${finalMessage}`,
                `${time}`,
                `${myextension}-Agent`,
                `${subdomain}`,
                `agent`,
              ],
            );
            const time2 = Date.now();
            await redisClient.lPush(
              subdomain +
                ":whatsapp:" +
                "919894340047" +
                ":" +
                arg.to +
                "-" +
                arg.client_ID,
              [
                `${arg.message}`,
                `${time2}`,
                `${myextension}-Agent`,
                `${subdomain}`,
                `agent`,
              ],
            );

            const whatsappConversationKey =
              subdomain +
              ":whatsapp:" +
              "919894340047" +
              ":" +
              arg.to +
              "-" +
              arg.client_ID;

            const conversationLogsData = {
              date: `${time}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              messageType: clientUser.messageType ?? "whatsapp",
              conversationsFetch: whatsappConversationKey,
            };

            const conversationLogsResult = await conversationLogsAdd(
              "agent",
              conversationLogsData,
              subdomain,
            );
            console.log("conversationLogsResult", conversationLogsResult);

            const conversationLogsDataExtension = {
              date: `${time}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              message: saveFile,
            };

            const chatHistoryKeyExtension = `${whatsappConversationKey}`;
            const conversationLogsExtensionResult =
              await conversationLogsAddExtension(
                "agent",
                conversationLogsDataExtension,
                chatHistoryKeyExtension,
              );
            console.log(
              "conversationLogsResult2",
              conversationLogsExtensionResult,
            );

            const conversationLogsDataExtension1 = {
              date: `${time2}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              message: arg.message,
            };

            const conversationLogsExtensionResult1 =
              await conversationLogsAddExtension(
                "agent",
                conversationLogsDataExtension1,
                chatHistoryKeyExtension,
              );
            console.log(
              "conversationLogsResult2",
              conversationLogsExtensionResult1,
            );

            let currentMsgIndex = clientUser.msgIndex
              ? Number(clientUser.msgIndex)
              : 0;

            // Para sa File
            currentMsgIndex++;

            await redisClient.hSet(clientKey, {
              msgIndex: currentMsgIndex,
            });
            const uniqueId1 = Date.now();
            const uniqueId = Date.now() + 1;

            await saveMessageForSearch(
              `${finalMessage}`,
              `${time}`,
              `${agentUser.Agent_Name}`,
              `${agentUser.email}`,
              `agent`, //`${subdomain}`
              `${uniqueId}`,
              `${clientUser.appId}`,
              `${clientUser.pageId}`,
              whatsappConversationKey,
              `whatsapp`,
              `${clientKey}`,
              `${finalMessage}`,
              `${time}`,
              `${myextension}-Agent`,
              `none`,
              `${uniqueId}`,
              `${fqdnValue}`,
            );

            currentMsgIndex++;

            await redisClient.hSet(clientKey, {
              msgIndex: currentMsgIndex,
            });

            await saveMessageForSearch(
              `${arg.message}`,
              `${time}`,
              `${agentUser.Agent_Name}`,
              `${agentUser.email}`,
              `agent`, //`${subdomain}`
              `${uniqueId1}`,
              `${clientUser.appId}`,
              `${clientUser.pageId}`,
              whatsappConversationKey,
              `whatsapp`,
              `${clientKey}`,
              `${arg.message}`,
              `${time}`,
              `${myextension}-Agent`,
              `none`,
              `${uniqueId1}`,
              `${subdomain}`,
            );

            socketSignal.emit(
              "chatParameters",
              "queue3",
              {
                id: clientUser.id,
                name: clientUser.username,
                socketId: mysocket,
                username: arg.client_username,
                email: clientUser.email,
                // assigned_agent: "",
                channel: subdomain,
                message: finalMessage,
                unix: Date.now(),
                time: Date.now(),
                from: myextension + "-Agent",
                client_unread: 0,
                agent_unread: 0,
                assigned_agent: assignedAgentsString,
                allocated_room: "ACTIVE",
                last_message: finalMessage,
                appId: clientUser.pageId,
                number: clientUser.number,
                useremail: clientUser.email,
                chatUpdated: Date.now(),
                referenceName: clientUser.referenceName,
                phone: clientUser.phone,
              },
              `${fqdnValue}-all_messages`,
            );

            // emitting message
            socketSignal.emit(
              "chatParameters",
              "queue3",
              {
                id: clientUser.id,
                name: clientUser.username,
                socketId: mysocket,
                username: arg.client_username,
                email: arg.client_email,
                // assigned_agent: "",
                channel: subdomain,
                message: arg.message,
                unix: Date.now() + 1,
                time: Date.now() + 1,
                from: myextension + "-Agent",
                client_unread: 0,
                agent_unread: 0,
                assigned_agent: assignedAgentsString,
                allocated_room: "ACTIVE",
                last_message: arg.message,
                appId: clientUser.pageId,
                number: clientUser.number,
                useremail: clientUser.email,
                chatUpdated: Date.now(),
                referenceName: clientUser.referenceName,
                phone: clientUser.phone,
              },
              `${fqdnValue}-all_messages`,
            );
          } else if (!arg.message || arg.message == "") {
            const uniqueId = Date.now();
            console.log("lezzcheck 2");
            success = await redisClient.lPush(
              subdomain +
                ":whatsapp:" +
                "919894340047" +
                ":" +
                arg.to +
                "-" +
                arg.client_ID,
              [
                `${finalMessage}`,
                `${time}`,
                `${myextension}-Agent`,
                `${subdomain}`,
                `agent`,
              ],
            );
            const saveFile = `https://${fqdnValue}/${finalMessage}`;
            const whatsappConversationKey =
              subdomain +
              ":whatsapp:" +
              "919894340047" +
              ":" +
              arg.to +
              "-" +
              arg.client_ID;

            const conversationLogsData = {
              date: `${time}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              messageType: clientUser.messageType ?? "whatsapp",
              conversationsFetch: whatsappConversationKey,
            };

            const conversationLogsResult = await conversationLogsAdd(
              "agent",
              conversationLogsData,
              subdomain,
            );
            console.log("conversationLogsResult", conversationLogsResult);

            const conversationLogsDataExtension = {
              date: `${time}`,
              username: `${agentUser.Agent_Name} (${agentUser.extension})`,
              email: arg.agent_email,
              to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
              message: saveFile,
            };

            const chatHistoryKeyExtension = `${whatsappConversationKey}`;
            const conversationLogsExtensionResult =
              await conversationLogsAddExtension(
                "agent",
                conversationLogsDataExtension,
                chatHistoryKeyExtension,
              );
            console.log(
              "conversationLogsResult2",
              conversationLogsExtensionResult,
            );

            let currentMsgIndex = clientUser.msgIndex
              ? Number(clientUser.msgIndex)
              : 0;

            // Para sa File
            currentMsgIndex++;

            await redisClient.hSet(clientKey, {
              msgIndex: currentMsgIndex,
            });

            await saveMessageForSearch(
              `${finalMessage}`,
              `${time}`,
              `${agentUser.Agent_Name}`,
              `${agentUser.email}`,
              `agent`, //`${subdomain}`
              `${uniqueId}`,
              `${clientUser.appId}`,
              `${clientUser.pageId}`,
              whatsappConversationKey,
              `whatsapp`,
              `${clientKey}`,
              `${finalMessage}`,
              `${time}`,
              `${myextension}-Agent`,
              `none`,
              `${uniqueId}`,
              `${fqdnValue}`,
            );

            // emitting message
            socketSignal.emit(
              "chatParameters",
              "queue3",
              {
                id: clientUser.id,
                name: clientUser.username,
                socketId: mysocket,
                username: arg.client_username,
                email: clientUser.email,
                // assigned_agent: "",
                channel: subdomain,
                message: finalMessage,
                unix: `${time}`,
                time: `${time}`,
                from: myextension + "-Agent",
                client_unread: 0,
                agent_unread: 0,
                assigned_agent: assignedAgentsString,
                allocated_room: "ACTIVE",
                last_message: finalMessage,
                appId: clientUser.pageId,
                number: clientUser.number,
                useremail: clientUser.email,
                chatUpdated: `${time}`,
                referenceName: clientUser.referenceName,
                phone: clientUser.phone,
              },
              `${fqdnValue}-all_messages`,
            );
          }

          // text
          const curlCommand = `curl -X POST "${sendApiUrl}" -H "Authorization: Bearer ${accessToken}" -H "Content-Type: application/json" -d '${JSON.stringify(
            {
              messaging_product: "whatsapp",
              to: arg.phone,
              type: "text",
              text: { body: arg["message"] },
              language: { code: "en_US" },
            },
          )}'`;
          exec(curlCommand, (error, stdout, stderr) => {
            if (error) {
              console.error("Error executing curl:", error);

              if (typeof callback === "function") {
                callback({ error: "Error executing curl" });
              }
              return;
            }

            const result = JSON.parse(stdout);

            if (typeof callback === "function") {
              callback(result);
            }
          });
        }

        await redisClient.hSet(clientKey, {
          timestamp: Date.now().toString(),
          last_message: finalMessage,
          message: finalMessage,
          agent_unread: 0 || "0",
          client_unread: Number(clientUser.client_unread) + 1,
          socketID: mysocket,
          allocated_room: "ACTIVE",
          // agentName: agentUser.agentName || agentUser.username,
          assigned_agent: assignedAgentsString,
          chatUpdated: Date.now(),
          extAgentName: extAgentName,
          extAgentExtension: extAgentExtension,
          // IMPORTANT SOLO ASSIGNING JUST DELETE THE ABOVE CODE
          // assigned_agent: agentKey || agentUser.id,
        });
      } else if (!arg.filesData) {
        console.log("whalang. payls");

        const uniqueId = Date.now();
        const whatsappConversationKey =
          subdomain +
          ":whatsapp:" +
          "919894340047" +
          ":" +
          arg.to +
          "-" +
          arg.client_ID;
        finalMessage = arg.message;
        const trdy = JSON.stringify(assignedAgents);
        console.log(
          arg.message,
          Number(clientUser.client_unread) + 1,
          mysocket,
          trdy,
        );

        console.log(
          "thisss",
          clientKey,
          arg.message,
          assignedAgentsString,
          extAgentName,
          extAgentExtension,
          mysocket,
          Date.now().toString(),
        );

        await redisClient.hSet(clientKey, {
          timestamp: Date.now().toString(),
          last_message: arg.message,
          message: arg.message,
          agent_unread: 0 || "0",
          client_unread: Number(clientUser.client_unread) + 1,
          allocated_room: "ACTIVE",
          assigned_agent: assignedAgentsString,
          chatUpdated: `${time}`,
          extAgentName: extAgentName,
          extAgentExtension: extAgentExtension,
        });

        let success = await redisClient.lPush(
          subdomain +
            ":whatsapp:" +
            "919894340047" +
            ":" +
            arg.to +
            "-" +
            arg.client_ID,
          [
            `${arg.message}`,
            `${time}`,
            `${myextension}-Agent`,
            `${subdomain}`,
            `agent`,
          ],
        );
        const conversationLogsData = {
          date: `${time}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
          messageType: clientUser.messageType ?? "whatsapp",
          conversationsFetch: whatsappConversationKey,
        };

        const conversationLogsResult = await conversationLogsAdd(
          "agent",
          conversationLogsData,
          subdomain,
        );
        console.log("conversationLogsResult", conversationLogsResult);

        const conversationLogsDataExtension = {
          date: `${time}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.referenceName ? `${clientUser.referenceName}` : "",
          message: arg.message,
        };

        const chatHistoryKeyExtension = `${whatsappConversationKey}`;
        const conversationLogsExtensionResult =
          await conversationLogsAddExtension(
            "agent",
            conversationLogsDataExtension,
            chatHistoryKeyExtension,
          );
        console.log("conversationLogsResult2", conversationLogsExtensionResult);

        let currentMsgIndex = clientUser.msgIndex
          ? Number(clientUser.msgIndex)
          : 0;

        // Para sa File
        currentMsgIndex++;

        await redisClient.hSet(clientKey, {
          msgIndex: currentMsgIndex,
        });

        console.log("ayan na sha saveMessageForSearch");

        await saveMessageForSearch(
          `${arg.message}`,
          `${time}`,
          `${agentUser.Agent_Name}`,
          `${agentUser.email}`,
          `agent`, //`${subdomain}`
          `${uniqueId}`,
          `${clientUser.appId}`,
          `${clientUser.pageId}`,
          whatsappConversationKey,
          `whatsapp`,
          `${clientKey}`,
          `${arg.message}`,
          `${time}`,
          `${myextension}-Agent`,
          `none`,
          `${uniqueId}`,
          `${fqdnValue}`,
        );

        console.log("ayan na sha saveMessageForSearch done");
        if (success) {
          socketSignal.emit(
            "chatParameters",
            "queue3",
            {
              id: clientUser.id,
              name: clientUser.username,
              socketId: mysocket,
              username: arg.client_username,
              email: clientUser.email,
              // assigned_agent: "",
              channel: subdomain,
              message: arg.message,
              unix: `${time}`,
              time: `${time}`,
              from: myextension + "-Agent",
              client_unread: 0,
              agent_unread: 0,
              assigned_agent: assignedAgentsString,
              allocated_room: "ACTIVE",
              last_message: arg.message,
              appId: clientUser.pageId,
              number: clientUser.number,
              useremail: clientUser.email,
              chatUpdated: `${time}`,
              referenceName: clientUser.referenceName,
              phone: clientUser.phone,
            },
            `${fqdnValue}-all_messages`,
          );
          console.log("you were trying to send this payload from whatsapp:");

          console.log(
            "arg.phone",
            arg.phone,
            typeof arg.phone,
            arg["message"],
            arg.to,
          );
          // text
          const curlCommand = `curl -X POST "${sendApiUrl}" -H "Authorization: Bearer ${accessToken}" -H "Content-Type: application/json" -d '${JSON.stringify(
            {
              messaging_product: "whatsapp",
              to: arg.phone,
              type: "text",
              text: { body: arg["message"] },
              language: { code: "en_US" },
            },
          )}'`;

          exec(curlCommand, (error, stdout, stderr) => {
            if (error) {
              console.error("Error executing curl:", error);

              if (typeof callback === "function") {
                callback({ error: "Error executing curl" });
              }
              return;
            }

            const result = JSON.parse(stdout);

            if (typeof callback === "function") {
              callback(result);
            }

            console.log("this is response 24/7", result);
          });
        }
      }

      console.log(
        "this much reachdadasdasdashdjkashdjkashdkashdkasdhkahdkjahdjkas",
      );

      console.log("whatsapp reached");

      ((pushWebMessage = finalMessage),
        console.log("arg.message:::::", arg.message));

      if (pushWebMessage == "") {
        if (finalMessage != "") {
          console.log("this is a fileeezzz", finalMessage);
        }
      }

      const conversationKeyUpdate =
        subdomain +
        ":whatsapp:" +
        "919894340047" +
        ":" +
        arg.to +
        "-" +
        arg.client_ID;

      //temp agent
      pushWeb(
        pushWebMessage,
        clientKey,
        subdomain,
        agentUser.Agent_Name,
        "whatsapp",
        "agent",
      );

      await redisClient.zRem(
        `${fqdnValue}-overallUnreadCount`,
        `unread-${clientKey}`,
      );
      const overallUnreadCount = await redisClient.zCard(
        `${fqdnValue}-overallUnreadCount`,
      );

      const agentUnreadCount = `unread-${agentKey}`;

      await redisClient.zRem(`${agentUnreadCount}`, `unread-${clientKey}`);

      // const assignedCount = await unreadProcess(agentKey, clientKey);
      const assignedCountResult = await redisClient.zCard(
        `${agentUnreadCount}`,
      );

      const unassignedUpdateUnread = {
        agent_unread: overallUnreadCount,
        total: assignedCountResult,
        extension: myextension,
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        console.log("this is assigned");

        // await Promise.all(
        //   assignedAgents.map(async (agentKey) => {

        let agentKeyArray = clientUser.assigned_agent;
        let agentKey;

        console.log("raw agentList:", agentKeyArray);
        console.log("type:", typeof agentKeyArray);

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }

        console.log("this is reply agentKey", agentKey);
        // const agentUserAgentUnread = Number(clientUser.agent_unread);

        const agentUserPrev = await redisClient.hGetAll(agentKey);
        await redisClient.zRem(`unread-${agentKey}`, `unread-${clientKey}`);

        const updatedUnreadCount = await redisClient.zCard(
          `unread-${agentKey}`,
        );
        //  const totalUnreadSent = unreadCountAll + updatedUnreadCount;
        const totalUnreadSent = updatedUnreadCount;
        updateUnread = {
          agent_unread: totalUnreadSent,
          agentKey: agentKey,
        };
        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);

        unassignedUpdateUnread.extension = agentUserPrev.extension;
        // Test

        // console.log("yeah");
        if (agentDetails?.roomID !== "all_messages") {
          console.log("agent details");
          console.log("this is the agentsocket id", agentSocketId);
          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            unassignedUpdateUnread,
            `${fqdnValue}-all_messages`,
          );
        }
        //   })
        // );
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );

        console.log("Emitted to client socket:", clientUser.socketID);
      } else {
        const unassignedUpdateUnread = {
          agent_unread: overallUnreadCount,
          // total: assignedCountResult,
          // extension: myextension
        };

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );
      }
    }
  });

  socket.on("facebookChat", async (argument) => {
    try {
      const { arg, subdomain, mysocket } = argument;
      if (!arg || !mysocket) return;

      let param;
      let agentUnread;
      let allocatedRoom;
      let unassignedUpdateUnread;
      let roomID = "";
      let agentList = [];

      // --- JSON PARSING ---
      if (typeof arg === "string") {
        param = JSON.parse(arg);
      } else if (Array.isArray(arg) && typeof arg[0] === "string") {
        param = JSON.parse(arg[0]);
      } else {
        param = arg;
      }

      let fqdnValue = param.fqdn;
      await createSearchIndex(fqdnValue);
      let username = param.userName.trim().replace(/[ -]+/g, "/");
      let pushwebUsername = param.userName.trim();
      let savedMessage = param.message || "";
      let pushWebFile = "";
      let saveMessageLogs = param.message || "";

      const enableOwner = await redisClient.get(
        `chatsettings:${fqdnValue}-enableOwnerManagerAssign`,
      );
      const enableRoundRobin = await redisClient.get(
        `chatsettings:${fqdnValue}-enableRoundRobin`,
      );
      const enableEveryAgentQ = await redisClient.get(
        `chatsettings:${fqdnValue}-enableEveryAgentQ`,
      );

      let assignedAgentRoundRobin = "";
      const time = String(Date.now());
      // --- OPTIMIZED FILE HANDLING (THE FIX) ---
      if (param.fileBuffer && param.fileBuffer.length > 0) {
        try {
          let filePathContainer = [];
          for (let index = 0; index < param.fileBuffer.length; index++) {
            const fileInfo = param.fileInfo[index];
            const fileExt = fileInfo.name.split(".").pop();
            const fileName = `fb-${fqdnValue}-${Date.now()}-${index}.${fileExt}`;
            const savePath = "/var/www/webapp/chat_file_upload/" + fileName;

            const binaryData = Buffer.from(param.fileBuffer[index], "base64");
            // Wait for disk I/O to finish
            writeFile(localPath, binaryData, async (err) => {
              if (err) {
                console.log({
                  result: "error",
                  message: "File upload failed: " + err,
                  content: fileName,
                });
                return;
              }

              console.log({
                result: "success",
                message: "File uploaded locally",
                content: fileName,
              });
            });

            filePathContainer.push("chat_file_upload/" + fileName);
          }
          // Set the message to the first saved path
          savedMessage = filePathContainer[0];
          saveMessageLogs = `https://${fqdnValue}/${savedMessage}`;
          pushWebFile = savedMessage.split("/").pop();

          // CRITICAL: Wipe the massive buffer from memory
          delete param.fileBuffer;
        } catch (err) {
          console.error("Facebook File Write Error:", err);
        }
      } else if (param.attachmentUrls) {
        // Logic for existing URLs
        const fullPath = param.attachmentUrls;
        const splitFilePath = fullPath.includes("chat_file_upload/")
          ? fullPath.split("chat_file_upload/")[1]
          : null;
        if (splitFilePath) {
          savedMessage = `chat_file_upload/${splitFilePath}`;
          saveMessageLogs = `https://${fqdnValue}/${savedMessage}`;
          pushWebFile = fullPath.split("/").pop();
        }
      }

      const clientKey = `${safe(param.recipient)}-facebook-${safe(username)}-${safe(param.sender_id)}-${fqdnValue}`;
      let clientUser = (await redisClient.exists(clientKey))
        ? await redisClient.hGetAll(clientKey)
        : null;

      allocatedRoom = "NEW";

      // --- USER STATE MANAGEMENT ---
      if (clientUser) {
        agentUnread = Number(clientUser.agent_unread || 0) + 1;
        if (clientUser?.assigned_agent && clientUser?.assigned_agent !== "[]") {
          allocatedRoom =
            clientUser.allocated_room === "CLOSED"
              ? "NEW"
              : clientUser.allocated_room || "NEW";
        }
        await redisClient.hSet(clientKey, {
          socketID: mysocket,
          allocated_room: allocatedRoom,
          agent_unread: agentUnread,
          last_message: savedMessage,
          message: savedMessage,
          fqdn: fqdnValue,
          pageId: param.recipient,
          appId: param.recipient,
          chatUpdated: `${time}`,
          msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
          ...(clientUser.allocated_room.toString() === "CLOSED" && {
            firstChatTimestamp: time,
          }),
        });
        roomID = clientUser.id || param.recipient;
      } else {
        agentUnread = 1;
        const newUser = {
          id: safe(param.recipient),
          username: safe(param.userName),
          created: Date.now().toString(),
          email: safe(param.sender_id),
          role: "customer",
          socketID: safe(mysocket),
          Dept: "customer",
          client_unread: "0",
          agent_unread: "1",
          status: "online",
          messageType: "facebook",
          allocated_room: allocatedRoom,
          message: safe(savedMessage),
          last_message: safe(savedMessage),
          pageId: param.recipient,
          appId: param.recipient,
          fqdn: fqdnValue,
          chatUpdated: `${time}`,
          msgIndex: 0,
        };

        await createSearchIndexProfile(fqdnValue);
        await saveProfileDataForSearch(
          pushwebUsername, // username
          `${safe(param.recipient)}`, // email
          `${clientKey}`,
          `${pushwebUsername}`,
        );

        // const noAssignedList = await redisClient.zRange(
        //   `${fqdnValue}-noAssigned`,
        //   0,
        //   0,
        // );
        // round robin
        if (enableRoundRobin == 1 && noAssignedList.length > 0) {
          assignedAgentRoundRobin = noAssignedList[0];

          // Remove them from the pool (or update score to move to back)
          // await redisClient.zRem(
          //   `${fqdnValue}-noAssigned`,
          //   assignedAgentRoundRobin,
          // );

          const agentKey = assignedAgentRoundRobin;
          agentUser = await redisClient.hGetAll(agentKey);

          if (agentUser) {
            // Prepare agent's assignment list
            let currentAgentAssigned = [];
            try {
              currentAgentAssigned = JSON.parse(agentUser.assigned || "[]");
            } catch {
              currentAgentAssigned = [];
            }

            // Add client to agent
            if (!currentAgentAssigned.includes(clientKey)) {
              currentAgentAssigned.unshift(clientKey);
            }

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(currentAgentAssigned),
            });

            // Add agent to client
            await redisClient.hSet(clientKey, {
              assigned_agent: JSON.stringify([agentKey]), // Simplified if single assignment
              ...(clientUser.allocated_room.toString() === "CLOSED" && {
                firstChatTimestamp: time,
              }),
            });

            newUser.assigned_agent = JSON.stringify([agentKey]);
            // Handle Unread Logic
            const agentUnreadKey = `unread-${agentKey}`;
            await redisClient.zAdd(agentUnreadKey, {
              score: Date.now(),
              value: `unread-${clientKey}`,
            });

            const agentTotalUnread = await redisClient.zCard(agentUnreadKey);
            const overallUnread = await redisClient.zCard(
              `${fqdnValue}-overallUnreadCount`,
            );

            result = await redisClient.zCard(`${agentUnreadKey}`);

            // Emit update
            socketSignal.emit(
              "chatParameters",
              "queue_updatedata",
              {
                agent_unread: overallUnread,
                total: agentTotalUnread,
                extension: agentUser.extension,
              },
              `${fqdnValue}-all_messages`,
            );
          }
        } else if (enableRoundRobin == 1 && noAssignedList.length == 0) {
          // NO AGENT AVAILABLE: Add client to a pending queue
          // RPUSH adds to the end of the list (First-In, First-Out)
          newUser.assigned_agent = "";
          await redisClient.rPush(`${fqdnValue}-waitingQueue`, clientKey);
          console.log(
            `🕒 No agents free. Client ${clientKey} added to waiting queue.`,
          );

          // Optional: Emit to the client that they are in a queue
          //socketSignal.emit("queueStatus", { position: "waiting" }, clientKey);
        } else {
          newUser.assigned_agent = "";
        }

        await redisClient.hSet(clientKey, newUser);
        await redisClient.lPush(
          `${fqdnValue}-client_data`,
          `${safe(param.recipient)}-facebook-${safe(username)}-${safe(param.sender_id)}`,
        );
        roomID = param.recipient;

        clientUser = await redisClient.hGetAll(clientKey);
      }

      let currentMsgIndex = clientUser.msgIndex
        ? Number(clientUser.msgIndex)
        : 0;

      currentMsgIndex++;
      // --- NOTIFICATIONS & QUEUE ---
      facebookConversationList(
        fqdnValue,
        username,
        param.recipient,
        param.sender_id,
        allocatedRoom.toLowerCase(),
      );
      pushWeb(
        savedMessage || "Attachment",
        clientKey,
        fqdnValue,
        pushwebUsername,
        "facebook",
        "client",
      );

      await redisClient.zRem(
        `${fqdnValue}-overallUnreadCount`,
        `unread-${clientKey}`,
      );
      await redisClient.zAdd(`${fqdnValue}-overallUnreadCount`, {
        score: Date.now(),
        value: `unread-${clientKey}`,
      });
      const overallUnreadCount = await redisClient.zCard(
        `${fqdnValue}-overallUnreadCount`,
      );

      // --- AGENT ASSIGNMENT LOGIC ---
      let assignedAgentRaw = clientUser?.assigned_agent || "[]";
      try {
        agentList = JSON.parse(assignedAgentRaw);
      } catch {
        agentList =
          typeof assignedAgentRaw === "string" ? [assignedAgentRaw] : [];
      }
      agentList = Array.isArray(agentList) ? agentList : [];

      if (agentList.length > 0 && agentList[0]) {
        const agentKey = agentList[0];
        const agentDetails = await redisClient.hGetAll(agentKey);

        // Update agent assigned list order
        let assignedClients = [];
        try {
          assignedClients = JSON.parse(agentDetails.assigned || "[]");
        } catch {
          assignedClients = [];
        }
        assignedClients = [
          clientKey.trim(),
          ...assignedClients.filter((c) => c.trim() !== clientKey.trim()),
        ];

        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify(assignedClients),
          agent_unread: Number(agentDetails.agent_unread || 0) + 1,
        });

        const agentUnreadCountKey = `unread-${agentKey}`;
        await redisClient.zAdd(agentUnreadCountKey, {
          score: Date.now(),
          value: `unread-${clientKey}`,
        });
        const assignedCountResult =
          await redisClient.zCard(agentUnreadCountKey);

        unassignedUpdateUnread = {
          agent_unread: overallUnreadCount,
          total: assignedCountResult,
          extension: agentDetails.extension,
        };
      } else {
        unassignedUpdateUnread = { agent_unread: overallUnreadCount };
      }

      // --- EMIT TO ALL AGENTS/MANAGERS ---
      const emitData = {
        id: roomID,
        name: param.userName,
        from: param.userName,
        unix: param.timestamp,
        time: param.timestamp,
        message: savedMessage,
        agent_unread: agentUnread,
        client_unread: "1",
        assigned_agent: clientUser?.assigned_agent || "[]",
        email: param.sender_id,
        allocated_room: allocatedRoom,
        last_message: savedMessage,
        messageType: "facebook",
        status: "online",
        appId: param.recipient,
        useremail: param.sender_id,
        chatUpdated: Date.now(),
      };

      socketSignal.emit(
        "chatParameters",
        "queue2",
        emitData,
        `${fqdnValue}-all_messages`,
      );
      socketSignal.emit(
        "chatParameters",
        "queue_updatedata",
        unassignedUpdateUnread,
        `${fqdnValue}-all_messages`,
      );

      // Persist message to Redis List
      const historyKey = `${fqdnValue}:facebook:919894340047:${param.sender_id}`;

      await redisClient.lPush(historyKey, [
        savedMessage,
        `${time}`,
        param.userName,
        param.userName,
        param.message_id || Date.now().toString(),
      ]);

      const toValue = clientUser?.extAgentName
        ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
        : "";

      // 2. Prepare the common log data
      const conversationLogsData = {
        date: `${time}`,
        username: pushwebUsername,
        email: `${safe(param.recipient)}`,
        to: toValue,
        messageType: clientUser?.messageType ?? "facebook", // Defaults to facebook if null
        conversationsFetch: historyKey,
      };

      await conversationLogsAdd("client", conversationLogsData, fqdnValue);

      // 3. Prepare the extension log data
      const conversationLogsDataExtension = {
        date: `${time}`,
        username: pushwebUsername,
        email: `${safe(param.recipient)}`,
        to: toValue,
        message: saveMessageLogs || param.message,
      };

      currentMsgIndex++;

      await redisClient.hSet(clientKey, {
        msgIndex: currentMsgIndex,
      });

      await saveMessageForSearch(
        savedMessage || param.message,
        `${time}`,
        `${pushwebUsername}`,
        `${safe(param.recipient)}`,
        `user`, // Pass profile here
        param.timestamp.toString(),
        `${safe(param.sender_id)}`,
        `${safe(param.sender_id)}`,
        historyKey,
        `facebook`,
        `${clientKey}`,
        savedMessage,
        `${time}`,
        param.userName,
        param.userName,
        param.message_id || Date.now().toString(),
        fqdnValue,
      );

      //keyChatAdded(clientKey, fqdnValue);

      const chatHistoryKeyExtension = `${historyKey}`;
      await conversationLogsAddExtension(
        "client",
        conversationLogsDataExtension,
        chatHistoryKeyExtension,
      );
    } catch (err) {
      console.error("Critical error in facebookChat:", err);
    }
  });

  socket.on("communicate4Chat", async (argument) => {
    // =====================================================================
    // communicate4Chat — an agent sends a message to a Facebook client.
    //
    // Flow:
    //   1. Validate the incoming payload.
    //   2. Sync the agent's chat settings with the server.
    //   3. Load the client + agent records from Redis.
    //   4. Verify the Facebook page token / config.
    //   5. Work out which agent owns this conversation.
    //   6. Send the message (and any files) to Facebook.
    //   7. Persist the message + update unread/conversation state.
    //   8. Emit the update to the dashboard room.
    // =====================================================================

    // --- 1. Validate the incoming payload ---
    const { arg, subdomain, extension, mysocket } = argument;
    if (
      argument === "undefined" ||
      argument === null ||
      arg === "undefined" ||
      arg === null ||
      mysocket === "undefined" ||
      mysocket === null
    ) {
      return;
    }
    await createSearchIndex(subdomain);

    // --- 2. Make sure the agent's chat settings match the server's ---
    if (arg.defaultChatSound) {
      const validatedChecker = await chatSettingsChecker(
        arg,
        subdomain,
        mysocket,
      );

      if (validatedChecker == 0) {
        // settings were out of sync; chatSettingsChecker already told the client
        return;
      } else if (validatedChecker == 1) {
        // settings are valid, continue
      }
    }

    let myextension = argument.extension;
    const clientPresence =
      `facebook:919894340047:` + arg["sender_id"] + ":" + arg.roomID;

    let validate = false;
    let assignedClients = [];
    let agentHolder = null;
    let assignedAgents;
    let safeUsername = arg.client_username.trim().replace(/[ -]+/g, "/");
    let fqdnValue = arg.url;
    // --- 3. Load the client record from Redis ---
    const clientKey = `${arg.client_ID}-facebook-${safeUsername}-${arg.client_email}-${fqdnValue}`;
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }

    // --- 4. Verify the Facebook page token / config ---
    const verified = await verifyToken(
      "facebookconfig",
      arg.url,
      arg.client_ID,
    );

    if (!verified) {
      console.log("Token verification failed for", arg.url, arg.client_ID);

      arg.status = "failed";
      arg.msg =
        "Your Facebook configuration appears to be invalid or misconfigured, " +
        "so this message can't be sent. Please check your settings and try again.";

      socketSignal.emit("chatParameters", "queueError", arg, mysocket);
      return;
    }

    const { accessToken, appId } = verified;

    // Single source timestamp — reused everywhere below so every stored and
    // emitted time for this message matches exactly.
    const time = Date.now();

    // Build a unique message id: timestamp + 16 random characters.
    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

    let uniqueId = "";
    let length = 16;

    for (let i = 0; i < length; i++) {
      uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    uniqueId = `${time}${uniqueId}`;

    // --- 3b. Load the agent record from Redis ---
    const agentKey = `${arg.agent_ID}-Agent-${arg.agent_username}-${arg.agent_email}-${fqdnValue}`;
    const agentUser = (await redisClient.exists(agentKey))
      ? await redisClient.hGetAll(agentKey)
      : null;
    if (!agentUser) {
      console.log("No client found for userKey", agentUser);
      return;
    }

    // --- 5. Work out which agent owns this conversation ---
    // (the four branches below cover new vs. existing assignment and
    //  whether the message is being handed over from a previous agent)
    if (
      (arg.permission == false && !arg.prevagent) ||
      (arg.permission == true && !arg.prevagent)
    ) {
      // No previous agent: assign or confirm the current agent.
      if (clientUser.assigned_agent && clientUser.assigned_agent !== null) {
        // Case 1: client is already assigned to the agent sending this message

        if (clientUser.agent_unread) {
          initialAgentUnread = Number(clientUser.agent_unread);
        }

        if (clientUser.assigned_agent) {
          const failedAgentSocket = agentUser.socketID;
          let assignedAgents = [];

          try {
            assignedAgents = JSON.parse(clientUser.assigned_agent);
            if (!Array.isArray(assignedAgents)) assignedAgents = [];
          } catch {
            assignedAgents = [];
          }

          // NOTE: the "is this agent allowed to reply" check is disabled for now.
        }

        if (clientUser.assigned_agent) {
          validate = true;
          agentHolder = agentUser;
          if (agentUser && agentUser.assigned) {
            try {
              assignedClients = JSON.parse(agentUser.assigned);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              assignedClients = [];
            }
          } else {
            assignedClients = [];
          }

          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            // already in the list -> move this client to the front
            assignedClients = assignedClients.filter(
              (x) => x.trim() !== clientKey.trim(),
            );

            assignedClients.unshift(clientKey.trim());
            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          } else {
            // not in the list yet -> add it to the front
            assignedClients.unshift(clientKey);

            await redisClient.hSet(agentKey, {
              assigned: JSON.stringify(assignedClients),
            });
          }
        } else {
          if (assignedClients.map((x) => x.trim()).includes(clientKey.trim())) {
            try {
              assignedClients = JSON.parse(agentUser.assigned || "[]");
            } catch (e) {
              console.error("Failed to parse assigned clients, resetting:", e);
              assignedClients = [];
            }
            assignedClients.unshift(clientKey);
            agentHolder = agentUser;
            validate = true;
          }
        }
      } else {
        // Case 2: client has no assigned agent yet -> assign this one
        noAssigned = 1;
        try {
          assignedClients = JSON.parse(agentUser.assigned || "[]");
        } catch (e) {
          console.error("Failed to parse assigned clients, resetting:", e);
          assignedClients = [];
        }
        assignedClients.unshift(clientKey);
        agentHolder = agentUser;
        validate = true;
      }
      if (arg.prevagent && arg.prevagent !== "") {
        // remove this client from the previous agent's assigned list
        const previousAgentUser = await redisClient.hGetAll(arg.prevagent);
        prevAssignedAgent = JSON.parse(
          previousAgentUser.assigned_agent || "[]",
        );
        prevAssignedAgent = prevAssignedAgent.filter(
          (x) => x.trim() !== clientKey.trim(),
        );

        await redisClient.hSet(arg.prevagent.toString(), {
          assigned: JSON.stringify(prevAssignedAgent || []),
        });
      }
    } else if (arg.permission === true && arg.prevagent) {
      // Handover (with permission): keep the previous or current agent.
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
        } else {
          assignedAgents.unshift(arg.prevagent);
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      validate = true;
    } else if (arg.permission === false && arg.prevagent) {
      // Handover (without permission): same resolution as above.
      assignedAgents = [];
      let newAgent = [];
      if (arg.prevagent && arg.prevagent !== "") {
        //clientUser.assigned_agent

        try {
          newAgent = JSON.parse(clientUser.assigned_agent);
        } catch (e) {
          console.error("Invalid JSON in agentUser.assigned:", e);
          newAgent = [];
        }

        if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
          assignedAgents.unshift(agentKey);
        } else {
          assignedAgents.unshift(arg.prevagent);
        }
      } else {
        assignedAgents.unshift(arg.agent_key);
      }

      validate = true;
    }

    // --- 5b. Bump the client's unread counter ---
    client_count = clientUser.client_unread;
    clientUser.client_unread =
      parseInt(clientUser.client_unread ?? "0", 10) + 1;
    clientUser.agent_unread = 0;

    if (validate && accessToken) {
      // --- 5c. Finalise the assigned-agent list, then send the message ---
      const agentUnreadCount = `unread-${agentKey}`;
      await redisClient.zRem(`${agentUnreadCount}`, `unread-${clientKey}`);

      var filePathContainer = [];
      var messagePayload2;
      var contentUrl;
      let messagePayload;
      let savedMessage;
      let saveMessageLogs;

      try {
        assignedAgents =
          typeof clientUser.assigned_agent === "string"
            ? JSON.parse(clientUser.assigned_agent || "[]")
            : clientUser.assigned_agent || [];
      } catch (e) {
        console.error("Failed to parse assigned agents:", e);
        assignedAgents = [];
      }

      if (Array.isArray(assignedAgents)) {
        // Always remove the current agent first to avoid duplicates
        assignedAgents = assignedAgents.filter(
          (x) => x.trim() !== agentKey.trim(),
        );

        if (
          (arg.permission === false && !arg.prevagent) ||
          (arg.permission == true && !arg.prevagent)
        ) {
          // Add the agent to the front of the list
          assignedAgents.unshift(agentKey);
        } else if (
          (arg.permission === true && arg.prevagent) ||
          (arg.permission === false && arg.prevagent)
        ) {
          assignedAgents = [];
          let newAgent = [];
          if (arg.prevagent && arg.prevagent !== "") {
            //clientUser.assigned_agent

            try {
              newAgent = JSON.parse(clientUser.assigned_agent);
            } catch (e) {
              console.error("Invalid JSON in agentUser.assigned:", e);
              newAgent = [];
            }

            if (newAgent.map((x) => x.trim()).includes(agentKey.trim())) {
              assignedAgents.unshift(agentKey);
            } else {
              assignedAgents.unshift(arg.prevagent);
            }
          } else {
            assignedAgents.unshift(arg.agent_key);
          }
        }
      }

      // Lock the final list: stringify once for both Redis and the payloads below.
      const assignedAgentsString = JSON.stringify(assignedAgents);
      clientUser.assigned_agent = assignedAgentsString;

      const extAgentKey = assignedAgents[0];
      const extAgentData = await redisClient.hGetAll(extAgentKey);
      const extAgentName = extAgentData.Agent_Name;
      const extAgentExtension = extAgentData.extension;

      // Prepare the payload fields shared by the emits below.
      clientUser.last_message = arg.message;
      clientUser.agentName = agentUser.agentName || agentUser.username;
      clientUser.allocated_room = "ACTIVE";

      if (
        arg.permission === false ||
        (arg.permission == true && !arg.prevagent)
      ) {
        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify([...new Set(assignedClients)]),
        });
      }

      // --- 6. Send the message (and any attachments) to Facebook ---
      let caption = "";
      if (arg.filesData !== "" && arg.filesData !== undefined) {
        for (let index = 0; index < arg.filesData.length; index++) {
          const file = arg.filesData[index].name.split(".");
          const type = arg.filesData[index].type;
          const size = arg.filesData[index].size;
          const filetype = file[file.length - 1];
          // filename id derived from the single message timestamp, kept unique
          // per file via the loop index (so multiple files don't collide)
          const uniqueId = `${time}${index}`;
          const convertedFileName = arg.filesData[index].name
            .trim()
            .replace(/[-]+/g, " ");
          const fileName = uniqueId + "." + filetype;
          const binaryData = Buffer.from(
            arg.filesData[index].fileBuffer,
            "base64",
          );

          // Save the file locally first
          let localPath = "";
          if (arg.filesData[index].type.startsWith("image")) {
            localPath = `/var/www/webapp/chat_file_upload/fb_image/${fileName}`;
            filePathContainer.push(
              "chat_file_upload/fb_image/" + fileName,
              type,
              size,
            );
          } else {
            localPath = `/var/www/webapp/chat_file_upload/fb_files/${fileName}`;
            filePathContainer.push(
              "chat_file_upload/fb_files/" + fileName,
              type,
              size,
            );
          }

          writeFile(localPath, binaryData, async (err) => {
            if (err) {
              console.log({
                result: "error",
                message: "File upload failed: " + err,
                content: fileName,
              });
              return;
            }

            console.log({
              result: "success",
              message: "File uploaded locally",
              content: fileName,
            });

            const attachmentType = arg.filesData[index].type.startsWith("image")
              ? "image"
              : "file";

            // Correct payload format for attachment upload
            const uploadPayload = `{
            "attachment": {
              "type": "${attachmentType}",
              "payload": {
                "is_reusable": true
              }
            }
          }`;

            const uploadUrl = `https://graph.facebook.com/v18.0/me/message_attachments?access_token=${accessToken}`;
            const curlUploadCommand = `curl -X POST "${uploadUrl}" -F 'message=${uploadPayload}' -F "filedata=@${localPath}"`;

            console.log("Uploading attachment:", curlUploadCommand);

            exec(curlUploadCommand, (uploadErr, stdout, stderr) => {
              if (uploadErr) {
                console.error("Error uploading attachment:", uploadErr);
                return;
              }

              let attachmentResponse;
              try {
                attachmentResponse = JSON.parse(stdout);
              } catch (parseError) {
                console.error(
                  "Error parsing attachment response:",
                  parseError,
                  stdout,
                );
                return;
              }

              const attachmentId = attachmentResponse?.attachment_id;
              if (!attachmentId) {
                console.error(
                  "Attachment upload failed, no attachment_id returned:",
                  attachmentResponse,
                );
                return;
              }

              // ---------------- Send Message using attachment_id ----------------
              const messagePayload = JSON.stringify({
                messaging_type: "RESPONSE",
                recipient: { id: arg.sender_id },
                message: {
                  attachment: {
                    type: attachmentType,
                    payload: {
                      attachment_id: attachmentId,
                    },
                  },
                },
              });

              const sendApiUrl = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;
              const curlSendCommand = `curl -X POST "${sendApiUrl}" -H "Content-Type: application/json" -d '${messagePayload}'`;

              console.log("Sending message with attachment:", curlSendCommand);

              exec(curlSendCommand, (sendErr, stdout, stderr) => {
                if (sendErr) {
                  console.error("Error sending message:", sendErr);
                  return;
                }

                try {
                  const response = JSON.parse(stdout);
                  console.log("Message sent successfully:", response);
                } catch (parseError) {
                  console.error(
                    "Error parsing send message response:",
                    parseError,
                  );
                }
              });
            });
          });

          savedMessage = filePathContainer[0];
          saveMessageLogs = `https://${fqdnValue}/${savedMessage}`;
          if (arg.message !== "") {
            caption = arg.message;

            const payload = {
              messaging_type: "RESPONSE",
              recipient: { id: arg.sender_id },
              message: { text: arg.message },
            };

            const safePayload = JSON.stringify(payload).replace(/'/g, "'\\''");
            const sendApiUrl = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;

            const curlCommand = `
          curl -X POST "${sendApiUrl}" \
          -H "Content-Type: application/json" \
          -d '${safePayload}'
          `;

            exec(curlCommand, (error, stdout, stderr) => {
              if (error) {
                console.error("Error executing curl:", error);
                return;
              }
              try {
                const response = JSON.parse(stdout);
                console.log("Response sent successfully:", response);
              } catch (parseError) {
                console.error("Error parsing response:", parseError);
              }
              console.log("Response sent successfully");
            });
          }
        }
      } else {
        const payload = {
          messaging_type: "RESPONSE",
          recipient: { id: arg.sender_id },
          message: { text: arg.message },
        };

        const safePayload = JSON.stringify(payload).replace(/'/g, "'\\''");
        const sendApiUrl = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;

        const curlCommand = `
        curl -X POST "${sendApiUrl}" \
        -H "Content-Type: application/json" \
        -d '${safePayload}'
        `;

        exec(curlCommand, (error, stdout, stderr) => {
          if (error) {
            console.error("Error executing curl:", error);
            return;
          }
          try {
            const response = JSON.parse(stdout);
            console.log("Response sent successfully:", response);
          } catch (parseError) {
            console.error("Error parsing response:", parseError);
          }
          console.log("Response sent successfully");
        });

        savedMessage = arg.message;
        saveMessageLogs = arg.message;
      }
      console.log("adding on ");
      // await keyChatAdded(clientKey, subdomain);

      await redisClient.zRem(
        `${fqdnValue}-overallUnreadCount`,
        `unread-${clientKey}`,
      );
      const overallUnreadCount = await redisClient.zCard(
        `${fqdnValue}-overallUnreadCount`,
      );

      // const assignedCount = await unreadProcess(agentKey, clientKey);
      await redisClient.zRem(`unread-${agentKey}`, clientKey);

      const assignedCount = await redisClient.zCard(`unread-${agentKey}`);

      const unassignedUpdateUnread = {
        agent_unread: overallUnreadCount,
        total: assignedCount,
        extension: myextension,
      };

      if (
        clientUser.assigned_agent &&
        clientUser.assigned_agent !== null &&
        clientUser.assigned_agent !== "[]"
      ) {
        let agentKeyArray = clientUser.assigned_agent;
        let agentKey;

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }
        // const agentUserAgentUnread = Number(clientUser.agent_unread);

        const agentUserPrev = await redisClient.hGetAll(agentKey);
        await redisClient.zRem(`unread-${agentKey}`, `unread-${clientKey}`);

        const updatedUnreadCount = await redisClient.zCard(
          `unread-${agentKey}`,
        );
        //  const totalUnreadSent = unreadCountAll + updatedUnreadCount;
        const totalUnreadSent = updatedUnreadCount;
        updateUnread = {
          agent_unread: totalUnreadSent,
          agentKey: agentKey,
        };

        unassignedUpdateUnread.extension = agentUserPrev.extension;

        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);

        if (agentDetails?.roomID !== "all_messages") {
          socketSignal.emit(
            "chatParameters",
            "queue_updatedata",
            unassignedUpdateUnread,
            `${fqdnValue}-all_messages`,
          );
        }
      }
      console.log(`new log: ${clientUser.allocated_room.toString()}`);
      console.log(`new log: ${clientKey}`);
      await redisClient.hSet(clientKey, {
        timestamp: `${time}`,
        last_message: savedMessage,
        message: savedMessage,
        agent_unread: Number(clientUser.agent_unread),
        client_unread: clientUser.client_unread.toString(),
        socketID: mysocket,
        allocated_room: clientUser.allocated_room.toString(),
        // agentName: agentUser.agentName || agentUser.username,
        assigned_agent: assignedAgentsString,
        chatUpdated: `${time}`,
        extAgentExtension: extAgentExtension,
        extAgentName: extAgentName,
      });

      const rem = await redisClient.zRem(
        `${fqdnValue}-conversation-active`,
        `${safeUsername}-${arg.client_ID}-facebook-${arg.client_email}`,
      );
      const sent = await redisClient.zAdd(`${fqdnValue}-conversation-active`, {
        score: time,
        value: `${safeUsername}-${arg.client_ID}-facebook-${arg.client_email}`,
      });

      const rem2 = await redisClient.zRem(
        `${fqdnValue}-conversation`,
        `${safeUsername}-${arg.client_ID}-wfacebook-${arg.client_email}`,
      );
      const sent2 = await redisClient.zAdd(`${fqdnValue}-conversation`, {
        score: time,
        value: `${safeUsername}-${arg.client_ID}-facebook-${arg.client_email}`,
      });

      const rem3 = await redisClient.zRem(
        `${fqdnValue}-conversation-new`,
        `${safeUsername}-${arg.client_ID}-facebook-${arg.client_email}`,
      );
      const rem4 = await redisClient.zRem(
        `${fqdnValue}-conversation-close`,
        `${safeUsername}-${arg.client_ID}-facebook-${arg.client_email}`,
      );

      let success = await redisClient.lPush(
        // "facebook:919894340047:" + arg.sender_id + "-" + arg.sender_id,
        fqdnValue + ":facebook:919894340047:" + arg.sender_id,
        [
          `${savedMessage}`,
          `${time}`,
          `${myextension}-Agent`,
          `${subdomain}`,
          `agent`,
        ],
      );

      const conversationKeyUpdate =
        fqdnValue + ":facebook:919894340047:" + arg.sender_id;
      const chatHistoryKeyExtension = `${conversationKeyUpdate}`;

      const conversationLogsData = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.username ? `${clientUser.username}` : "",
        messageType: clientUser.messageType ?? "facebook",
        conversationsFetch: conversationKeyUpdate,
      };

      const conversationLogsResult = await conversationLogsAdd(
        "agent",
        conversationLogsData,
        subdomain,
      );
      console.log("conversationLogsResult", conversationLogsResult);

      const conversationLogsDataExtension = {
        date: `${time}`,
        username: `${agentUser.Agent_Name} (${agentUser.extension})`,
        email: arg.agent_email,
        to: clientUser.username ? `${clientUser.username}` : "",
        message: `${saveMessageLogs}`,
      };

      let currentMsgIndex = clientUser.msgIndex
        ? Number(clientUser.msgIndex)
        : 0;

      // Para sa File
      currentMsgIndex++;

      await redisClient.hSet(clientKey, {
        msgIndex: currentMsgIndex,
      });

      await saveMessageForSearch(
        `${savedMessage}`,
        `${time}`,
        `${agentUser.Agent_Name}`,
        `${agentUser.email}`,
        `agent`, //`${subdomain}`
        `${uniqueId}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        chatHistoryKeyExtension,
        `facebook`,
        `${clientKey}`,
        `${savedMessage}`,
        `${time}`,
        `${myextension}-Agent`,
        `agent`,
        `${uniqueId}`,
        `${subdomain}`,
      );

      const conversationLogsExtensionResult =
        await conversationLogsAddExtension(
          "agent",
          conversationLogsDataExtension,
          chatHistoryKeyExtension,
        );
      console.log("conversationLogsResult2", conversationLogsExtensionResult);
      let uniqueId2 = null;
      let time2 = time; // keep the caption on the same timestamp as the main message
      if (caption !== "") {
        time2 = time;
        uniqueId2 = uniqueId + 1;
        await redisClient.lPush(
          fqdnValue + ":facebook:919894340047:" + arg.sender_id,
          [
            `${caption}`,
            `${time2}`,
            `${myextension}-Agent`,
            `${subdomain}`,
            `agent`,
          ],
        );

        const conversationLogsDataExtension2 = {
          date: `${time2}`,
          username: `${agentUser.Agent_Name} (${agentUser.extension})`,
          email: arg.agent_email,
          to: clientUser.username ? `${clientUser.username}` : "",
          message: `${savedMessage}`,
        };
        await conversationLogsAddExtension(
          "agent",
          conversationLogsDataExtension2,
          chatHistoryKeyExtension,
        );

        currentMsgIndex++;

        await redisClient.hSet(clientKey, {
          msgIndex: currentMsgIndex,
        });

        await saveMessageForSearch(
          `${caption}`,
          `${time2}`,
          `${agentUser.Agent_Name}`,
          `${agentUser.email}`,
          `agent`, //`${subdomain}`
          `${uniqueId2}`,
          `${clientUser.appId}`,
          `${clientUser.pageId}`,
          chatHistoryKeyExtension,
          `facebook`,
          `${clientKey}`,
          `${caption}`,
          `${time2}`,
          `${myextension}-Agent`,
          `agent`,
          `${uniqueId2}`,
          `${subdomain}`,
        );
      }

      if (success) {
        if (caption !== "") {
          socketSignal.emit(
            "chatParameters",
            "queue2",
            {
              id: clientUser.id,
              name: clientUser.username,
              socketId: mysocket,
              username: arg.client_username,
              email: arg.client_email,
              // assigned_agent: "",
              channel: subdomain,
              message: caption,
              unix: time2 ?? "",
              time: time2 ?? "",
              from: myextension + "-Agent",
              client_unread: 0,
              agent_unread: 0,
              assigned_agent: assignedAgentsString,
              allocated_room: "ACTIVE",
              last_message: caption,
              appId: clientUser.pageId,
              chatUpdated: time,
            },
            `${fqdnValue}-all_messages`,
          );
        }
        socketSignal.emit(
          "chatParameters",
          "queue2",
          {
            id: clientUser.id,
            name: clientUser.username,
            socketId: mysocket,
            username: arg.client_username,
            email: arg.client_email,
            // assigned_agent: "",
            channel: subdomain,
            message: savedMessage,
            unix: time2,
            time: time2,
            from: myextension + "-Agent",
            client_unread: 0,
            agent_unread: 0,
            assigned_agent: assignedAgentsString,
            allocated_room: "ACTIVE",
            last_message: savedMessage,
            appId: clientUser.pageId,
            chatUpdated: time,
          },
          `${fqdnValue}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${fqdnValue}-all_messages`,
        );
      }

      let pushWebMessage = savedMessage;
      //temp agent
      pushWeb(
        pushWebMessage,
        clientKey,
        subdomain,
        agentUser.Agent_Name,
        "facebook",
        "agent",
      );
    }
  });
});

async function chatNewUser(channel, clientPresence, objData, subdomain) {
  const userKeyClient = `${objData.id}-website-${objData.username}-${objData.useremail}-${subdomain}`;
  const enableOwner = await redisClient.get(
    `chatsettings:${subdomain}-enableOwnerManagerAssign`,
  );
  const enableEveryAgentQ = await redisClient.get(
    `chatsettings:${subdomain}-enableEveryAgentQ`,
  );
  console.log(
    "chatsettings checker",
    "en-owner:",
    enableOwner,
    "en-agent:",
    enableEveryAgentQ,
    "en-robin",
  );

  let assignedAgentRoundRobin = "";
  let agentUser = "";
  let clientAssignedList = [];
  let result = "";
  
  try {
    let clientInfo = clientPresence.split("-");

    await redisClient.zAdd(`${channel}-conversation`, {
      score: Date.now(),
      value: clientPresence,
    });
    await redisClient.zAdd(`${channel}-conversation-new`, {
      score: Date.now(),
      value: clientPresence,
    });

    const newUserListRecord = [
      `${objData.message}`,
      `${objData.time}`,
      `${clientInfo[0]}-user`,
      "user",
      `${objData.uid}`,
    ];
    console.log("[type1][message] writing new-user Redis list record", {
      chatKey: `${channel}-external-${clientInfo[1]}-${objData.id}`,
      uid: objData.uid,
      lPushArgumentOrder: ["message", "time", "sender", "profile", "uid"],
      lRangeStoredOrder: ["uid", "profile", "sender", "time", "message"],
      record: newUserListRecord,
    });
    let sendMessage = await redisClient.lPush(
      `${channel}-external-${clientInfo[1]}-${objData.id}`,
      newUserListRecord,
    );

    // const unreadClient = `${subdomain}-unread-${userKeyClient}`;

    // Add client

    // Tama

    // const agentUnreadCount = `unread-${agentKey}`;
    // const clientUnreadCount = `unread-${userKeyClient}`;

    //WRONGTYPE Operation against a key holding the wrong kind of value]

    if (!sendMessage) {
      console.error("Message not added to external");
      return;
    }
    const exist = await redisClient.exists(userKeyClient);
    if (!exist) {
      console.log("No client found for userKey", userKeyClient);
      return;
    }

    let clientUserKey = userKeyClient;
    let user = await redisClient.hGetAll(clientUserKey);
    await redisClient.hSet(clientUserKey, {
      seen: "",
      last_message: objData.message,
      message: objData.message,
      client_unread: 0,
      agent_unread: 1,
      allocated_room: "NEW",
      status: "online",
      accessToken: objData.accessToken,
      alreadySubmitted: "true",
      chatUpdated: objData.time,
      msgIndex: 1,
      firstChatTimestamp: objData.time,
      emailtranscript: "true",
      aiEnable: "true",
    });

    // const tokenTest =
    objData.agent_unread = 1;
    objData.client_unread = 0;
    objData.allocated_room = "NEW";
    objData.last_message = objData.last_message;
    objData.message = objData.message;
    objData.status = user.status;
    objData.appId = user.appId;
    objData.pageId = user.pageId;
    objData.messageType = "website";
    objData.username = objData.username + "-user";
    objData.chatUpdated = objData.time;

    const redisKey = `${channel}-external-${clientInfo[1]}-${objData.id}`;
    await createSearchIndexProfile(channel);

    //Not necessary
    await createSearchMessageIndex(channel, userKeyClient);

    //Many at a time
    await saveMessageForSearch(
      `${objData.message}`,
      `${objData.time}`,
      `${clientInfo[0]}`,
      `${clientInfo[1]}`,
      `user`, // Pass profile here
      `${objData.uid}`,
      `${objData.appId}`,
      `${objData.pageId}`,
      redisKey,
      `website`,
      `${userKeyClient}`,
      `${objData.message}`,
      `${objData.time}`,
      `${clientInfo[0]}-user`,
      `user`, // `${channel}`
      `${objData.uid}`,
      subdomain,
    );

    console.log(
      "this is for saveMessage for search TYPE CHATNEWUSER",

      `${objData.message}`,
      `${objData.time}`,
      `${clientInfo[0]}`,
      `${clientInfo[1]}`,
      `user`, // Pass profile here
      `${objData.uid}`,
      `${objData.appId}`,
      `${objData.pageId}`,
      redisKey,
      `website`,
      `${userKeyClient}`,
      `${objData.message}`,
      `${objData.time}`,
      `${clientInfo[0]}-user`,
      `user`, // `${channel}`
      `${objData.uid}`,
      subdomain,
    );

    // `${objData.message}`,
    //   `${objData.time}`,
    //   `${clientInfo[0]}-user`,
    //   `user`, // `${channel}`
    //   `${objData.uid}`,

    //one at a time
    await saveProfileDataForSearch(
      `${clientInfo[0]}`, // username
      `${clientInfo[1]}`, // email
      `${userKeyClient}`,
      `${clientInfo[0]}`,
    );

    await redisClient.zAdd(`${subdomain}-overallUnreadCount`, {
      score: Date.now(),
      value: `unread-${userKeyClient}`,
    });

    const totalUnreadSent = await redisClient.zCard(
      `${subdomain}-overallUnreadCount`,
    );

    const updateUnread = {
      agent_unread: totalUnreadSent,
      // total: 0,
    };

    if (result != "") {
      updateUnread.total = result;
    }

    console.log("cha got", objData.username);
    const rawUsername = objData.username;
    let addLogsUsername;
    if (rawUsername && rawUsername.includes("-")) {
      // Split by the hyphen and take the first part (index 0)
      const nameOnly = rawUsername.split("-")[0];

      console.log("Extracted name:", nameOnly); // Output: LeeSon
      addLogsUsername = nameOnly;
    } else {
      // Fallback in case the string isn't in the expected format
      console.log("Name format unexpected:", rawUsername);
      addLogsUsername = rawUsername;
    }

    console.log("rediskey", redisKey);
    const conversationLogsData = {
      date: objData.time,
      username: addLogsUsername,
      email: objData.useremail,
      to: user.extAgentName
        ? `${user.extAgentName} (${user.extAgentExtension})`
        : "",
      messageType: objData.messageType,
      conversationsFetch: redisKey,
    };

    const conversationLogsData2 = {
      date: objData.time,
      username: addLogsUsername,
      email: objData.useremail,
      to: user.extAgentName
        ? `${user.extAgentName} (${user.extAgentExtension})`
        : "",
      message: objData.saveLogsMessage ?? objData.message,
    };

    const conversationLogsResult = await conversationLogsAdd(
      "client",
      conversationLogsData,
      subdomain,
    );
    console.log(conversationLogsResult);

    const chatHistoryKeyExtension = `${redisKey}`;
    const conversationLogsExtensionResult = await conversationLogsAddExtension(
      "client",
      conversationLogsData2,
      chatHistoryKeyExtension,
    );
    console.log("conversationLogsResult2", conversationLogsExtensionResult);

    socketSignal.emit(
      "chatParameters",
      "queue",
      objData,
      `${subdomain}-all_messages`,
    );
    socketSignal.emit("chatParameters", "queue", objData, objData.socketId);
    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      updateUnread,
      `${subdomain}-all_messages`,
    );

    // socketSignal.emit(
    //   "chatParameters",
    //   "queue_updatedata",
    //   updateUnread,
    //   "assign_messages",
    // );
  } catch (err) {
    console.error("Error in chatnewuser", err);
  }
}

// async function askOpenAI(userQuery, apiKey, sumbrain) {
//   const response = await fetch("https://api.openai.com/v1/chat/completions", {
//     method: "POST",
//     headers: {
//       "Content-Type": "application/json",
//       "Authorization": `Bearer ${apiKey}`,
//     },
//     body: JSON.stringify({
//       model: "gpt-4o-mini",
//       messages: [
//         {
//           role: "user",
//           content: `${sumbrain}\n\n${userQuery}`, // combine both as input
//         },
//       ],
//     }),
//   });

//   const data = await response.json();
//   console.log("this is apikey", apiKey)
//   console.log("this is data askopenai", data)
//   return data.choices?.[0]?.message?.content || "No response";
// }

// async function validateOpenAIToken(token) {
//   try {
//     const res = await fetch("https://api.openai.com/v1/models", {
//       method: "GET",
//       headers: {
//         "Authorization": `Bearer ${token}`,
//       },
//     });

//     if (res.status === 200) {
//       // token works and is authorized
//       return true;
//     } else if (res.status === 401) {
//       // invalid or expired key
//       return false;
//     } else {
//       console.log("Unexpected response:", res.status);
//       return false;
//     }
//   } catch (err) {
//     console.error("Token validation failed:", err.message);
//     return false;
//   }
// }

// =============================================================================
// AI auto-reply (Gemini). Called at the end of the type==1 handler.
// Reads the per-tenant key from Redis hash `${subdomain}-AISettings`:
//   selectedAI -> "gemini"   gemini -> "<api key>"   (chatgpt -> "<key>")
// If no token is configured it returns early (no reply). Never throws.
// =============================================================================

async function aiAutoReply({
  subdomain,
  arg,
  visitorSocket,
  clientKey,
  chatKey,
}) {
  try {
    console.log("AI auto-reply triggered for", subdomain, "message:", arg.text);
    const userText = (arg.text || "").trim();

    if (!userText) return; // nothing to answer (e.g. a file-only message)

    // --- Per-conversation switch: off once a human agent has replied (type 2) ---
    // Only an explicit "false" blocks the reply, so conversations predating this
    // field keep working as before.

    const uniquesettings = await redisClient.hGetAll(clientKey);
    console.log("[aiAutoReply] uniquesettings for", clientKey, uniquesettings);
    console.log(
      "[aiAutoReply] uniquesettings.accessToken =",
      uniquesettings?.accessToken,
      "-> widget config key:",
      `${subdomain}-${uniquesettings?.accessToken}`,
    );

    // --- The widget this visitor is chatting through. Its own design + AI config
    // lives under `${subdomain}-${accessToken}` — the same key the admin API
    // writes (ChatadminController: "{fqdn}-{accessToken}").
    const widgetKey = `${subdomain}-${uniquesettings?.accessToken}`;
    const widgetSettings = await redisClient.hGetAll(widgetKey);
    console.log("[aiAutoReply] widgetSettings for", widgetKey, widgetSettings);
    console.log("[aiAutoReply] widget gates:", {
      customEnabled: widgetSettings?.customEnabled,
      aiEnable: widgetSettings?.aiEnable,
      aiProvider: widgetSettings?.aiProvider,
      hasToken: Boolean(widgetSettings?.[widgetSettings?.aiProvider || ""]),
      brain: widgetSettings?.brain,
    });

    const globalsettings = await redisClient.hGetAll(`${subdomain}-AISettings`);
    // console.log("[aiAutoReply] widgetSettings for", widgetKey, widgetSettings);
    // console.log(`widgetSettings?.customEnabled : ${widgetSettings?.customEnabled} and widgetSettings?.aiEnable : ${widgetSettings?.aiEnable}`)
    // return;

    if (
      widgetSettings?.customEnabled == "true" &&
      widgetSettings?.aiEnable == "false"
    ) {
      console.log(`custom is on but ai is off on custom`);
      return;
    } else if (
      widgetSettings?.customEnabled == "false" &&
      globalsettings?.aiEnable == "false"
    ) {
      console.log(`custom is off but ai on global is off`);
      return;
    }

    const clientAiEnable = await redisClient.hGet(clientKey, "aiEnable");
    if (clientAiEnable == "false") {
      console.log(`AI reply skipped: aiEnable is false for ${clientKey}`);
      return;
    }

    console.log("reply ng ai para sa client key ", clientKey);

    // --- Pull the AI config. Which hash wins is decided by the WIDGET's own
    // aiEnable (read above from `${subdomain}-${accessToken}`):
    //   "true"  -> use the widget's config: its brain + its provider token.
    //   else    -> use the tenant-wide `${subdomain}-AISettings`.
    // The two hashes name the provider field differently — the widget calls it
    // `aiProvider`, the global calls it `selectedAI` — but BOTH store the token
    // under the provider's own field name (`gemini` / `chatgpt`), so the
    // aiSettings[selectedAI] lookup below is identical for either source.
    let aiSettings;
    let selectedAI;
    let aiSource;
    if (widgetSettings?.customEnabled === "true") {
      aiSettings = widgetSettings;
      selectedAI = widgetSettings.aiProvider;
      aiSource = widgetKey;
      console.log("detected true??");
    } else {
      console.log("detected false but this is on global settings??");
      aiSettings = await redisClient.hGetAll(`${subdomain}-AISettings`);
      selectedAI = aiSettings?.selectedAI;
      aiSource = `${subdomain}-AISettings`;
      // if (aiSettings?.aiEnable == "false") {
      //   console.log(`AI reply skipped: aiEnable is false for ${aiSource}`);
      //   return;
      // }
    }

    console.log(
      `AI config resolved from ${aiSource} (provider="${selectedAI}")`,
    );

    const aiKey = selectedAI ? aiSettings[selectedAI] : null;
    if (!aiKey) {
      console.log(`AI reply skipped: no token set for ${aiSource}`);
      return; // early return when no token was set
    }
    console.log("aiKey ", aiKey);
    if (selectedAI !== "gemini" && selectedAI !== "chatgpt") {
      console.log(`AI reply skipped: unsupported provider "${selectedAI}"`);
      return;
    }

    // --- Rebuild a short conversation history for context ---
    const flat = await redisClient.lRange(chatKey, 0, 49); // ~10 newest messages, newest-first
    const history = [];
    for (let i = 0; i + 4 < flat.length; i += 5) {
      const profile = flat[i + 1];
      const message = flat[i + 4];
      if (!message) continue;
      history.unshift({
        role: profile === "user" ? "user" : "model",
        parts: [{ text: message }],
      });
    }
    while (history.length && history[0].role === "model") history.shift(); // Gemini must start on a user turn
    const lastTurn = history[history.length - 1];
    if (
      !(
        lastTurn &&
        lastTurn.role === "user" &&
        lastTurn.parts[0].text === userText
      )
    ) {
      history.push({ role: "user", parts: [{ text: userText }] });
    }

    // --- Build the provider-specific request (Gemini OR ChatGPT) ---
    // Use the tenant's custom "brain" prompt when set; otherwise fall back to the default.
    console.log(`AI brain content is ${aiSettings?.brain}`);
    const systemPrompt =
      (aiSettings?.brain && aiSettings.brain.trim()) ||
      "You are a helpful, concise website support assistant. Reply in the customer's language.";
    console.log(`systemPrompt: ${systemPrompt}`);
    let url,
      payloadBody,
      extraHeader = "";

    if (selectedAI === "gemini") {
      // NOTE: verify this model exists for your key (e.g. gemini-2.0-flash / gemini-2.5-flash).
      // "gemini-3.5-flash" does not exist yet and will 404.
      // const model = aiSettings.geminiModel || "gemini-2.0-flash";
      const model = "gemini-3.1-flash-lite";
      url = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${aiKey}`;
      payloadBody = JSON.stringify({
        system_instruction: { parts: [{ text: systemPrompt }] },
        contents: history, // Gemini shape: [{ role, parts: [{ text }] }]
      });
    } else {
      // ChatGPT / OpenAI
      // const model = aiSettings.chatgptModel || "gpt-4o-mini";
      const model = "gpt-4o-mini";
      url = "https://api.openai.com/v1/chat/completions";
      extraHeader = `-H "Authorization: Bearer ${aiKey}"`; // OpenAI auth = header (not in the URL)
      payloadBody = JSON.stringify({
        model,
        messages: [
          { role: "system", content: systemPrompt },
          // reuse the same history, mapped to OpenAI's shape
          ...history.map((h) => ({
            role: h.role === "model" ? "assistant" : "user",
            content: h.parts[0].text,
          })),
        ],
      });
    }

    // Safely escape single quotes for the shell, then POST the JSON body via curl.
    const escapedPayload = payloadBody.replace(/'/g, "'\\''");
    const curlCommand = `curl -s -X POST "${url}" -H "Content-Type: application/json" ${extraHeader} -d '${escapedPayload}'`;
    const { stdout } = await execPromise(curlCommand, {
      maxBuffer: 1024 * 1024 * 5,
    });

    let data;
    try {
      data = JSON.parse(stdout);
    } catch {
      console.error("AI non-JSON response:", stdout);
      return;
    }

    // Each provider nests the reply text differently:
    const reply =
      selectedAI === "gemini"
        ? data?.candidates?.[0]?.content?.parts?.[0]?.text?.trim()
        : data?.choices?.[0]?.message?.content?.trim();

    if (!reply) {
      console.error(`${selectedAI} returned no reply:`, JSON.stringify(data));
      return;
    }

    console.log(`[aiAutoReply] ${selectedAI} reply ready`, {
      chatKey,
      replyLength: reply.length,
    });

    // The AI request may take several seconds. A human could have taken over
    // while it was running, so check the conversation switch again before save.
    const [latestAiEnable, latestMsgIndex] = await Promise.all([
      redisClient.hGet(clientKey, "aiEnable"),
      redisClient.hGet(clientKey, "msgIndex"),
    ]);
    if (latestAiEnable === "false") {
      console.log("[aiAutoReply] reply discarded: human agent took over", {
        clientKey,
      });
      return;
    }

    // Use one UID and sender identity in the list, search hash, and socket payload.
    const time = Date.now();
    const uid = await generateRandomUniqueId();
    // Keep the existing sender tag for client compatibility; unlike the UID,
    // this label intentionally identifies the message as AI-generated.
    const aiSender = "RingQ AI-AI";
    const aiProfile = "AI";
    const msgIndex = Number(latestMsgIndex || 0) + 1;
    const listRecord = [reply, String(time), aiSender, aiProfile, uid];

    console.log("[aiAutoReply][message] writing Redis list record", {
      chatKey,
      clientKey,
      uid,
      msgIndex,
      lPushArgumentOrder: ["message", "time", "sender", "profile", "uid"],
      lRangeStoredOrder: ["uid", "profile", "sender", "time", "message"],
      record: listRecord,
    });
    const listLength = await redisClient.lPush(chatKey, listRecord);
    console.log("[aiAutoReply][message] Redis list record written", {
      chatKey,
      uid,
      listLength,
    });

    await redisClient.hSet(clientKey, {
      last_message: reply,
      message: reply,
      chatUpdated: `${time}`,
      msgIndex: String(msgIndex),
    });

    // --- Deliver to the visitor's widget (and mirror to the agent dashboard) ---
    const room = await redisClient.hGet(`${clientKey}`, "allocated_room");
    const appId = await redisClient.hGet(`${clientKey}`, "appId");
    const clientname = await redisClient.hGet(`${clientKey}`, "username");
    const pageId = await redisClient.hGet(`${clientKey}`, "pageId");
    const channel = await redisClient.hGet(`${clientKey}`, "messageType");
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;
    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return;
    }
    const payload = {
      uid,
      socketId: visitorSocket,
      username: aiSender,
      name: clientUser.username,
      useremail: arg.email,
      channel: subdomain,
      message: reply,
      time,
      last_message: reply,
      messageType: "website",
      id: arg.id,
      referenceName: clientname,
      allocated_room: room,
      msgId: uid,
      chatUpdated: `${time}`,
      isAI: true,
      assigned_agent: clientUser.assigned_agent ?? "",
      agent_unread: clientUser.agent_unread ?? 0,
    };

    await saveMessageForSearch(
      `${reply}`,
      `${time}`,
      aiSender,
      `${arg.email}`,
      aiProfile,
      uid,
      `${appId}`,
      `${pageId}`,
      chatKey,
      `${channel}`,
      `${clientKey}`,
      `${reply}`,
      `${time}`,
      aiSender,
      aiProfile,
      uid,
      subdomain,
    );
    console.log("[aiAutoReply][message] search record write completed", {
      chatKey,
      clientKey,
      uid,
      msgIndex,
    });

    socketSignal.emit("chatParameters", "queue", payload, visitorSocket);
    socketSignal.emit(
      "chatParameters",
      "queue",
      payload,
      `${subdomain}-all_messages`,
    );
  } catch (err) {
    console.error("aiAutoReply error:", err);
  }
}

async function unreadProcess(agentKey, clientKey) {
  const agentKeyUnread = `unreadCount-${agentKey}`;

  let assignedRaw = await redisClient.hGet(agentKeyUnread, "assignedClients");
  let newAssigned = [];
  try {
    newAssigned = JSON.parse(assignedRaw || "[]");
  } catch (err) {
    console.log("Invalid assignedClients JSON (prevAgent)", assignedRaw);
  }

  if (Array.isArray(newAssigned)) {
    if (!newAssigned.includes(clientKey)) {
      newAssigned.unshift(clientKey);
    }

    await redisClient.hSet(agentKeyUnread, {
      assignedClients: JSON.stringify(newAssigned),
    });
  }

  const assignedCount = newAssigned.length;
  console.log("New agent assigned count:", assignedCount);

  return assignedCount;
}

async function chatOldUser(
  channel,
  clientPresence,
  objData,
  subdomain,
  mysocket,
) {
  try {
    let oldroomstatus = "";
    let clientInfo = clientPresence.split("-");
    const redisKey = `${channel}-external-${clientInfo[1]}-${objData.id}`;

    console.log("chatOldUser", redisKey);
    // console.log("clientUser.allocated_room", clientUser.allocated_room);

    // Client key
    const userKeyClient = `${objData.id}-website-${objData.username}-${objData.useremail}-${subdomain}`;
    const unreadClient = `unread-${userKeyClient}`;
    // console.log("this is rediskey", redisKey);

    let agentList = [];
    let allocatedRoom = "";

    // const totalAgentUnreadSent = await redisClient.hGet("unreadCount", "unread_count_all");
    // ------------------- CLIENT LOOKUP -------------------
    let clientUser = (await redisClient.hGetAll(userKeyClient))
      ? await redisClient.hGetAll(userKeyClient)
      : null;
    console.log("existing na");

    if (!clientUser) {
      console.log("No client found for userKey", userKeyClient);
      return;
    }

    let assignedAgent = "";

    if (clientUser.assigned_agent) {
      assignedAgent = clientUser.assigned_agent;
    }

    try {
      agentList = JSON.parse(clientUser.assigned_agent || "[]");
    } catch (e) {
      agentList = [];
    }

    await redisClient.zRem(`${channel}-conversation`, clientPresence);
    await redisClient.zAdd(`${channel}-conversation`, {
      score: Date.now(),
      value: clientPresence,
    });

    await redisClient.zRem(`${channel}-conversation-active`, clientPresence);
    await redisClient.zRem(`${channel}-conversation-new`, clientPresence);
    await redisClient.zRem(`${channel}-conversation-close`, clientPresence);

    if (agentList.length > 0) {
      console.log("clientUser.allocated_room:", clientUser.allocated_room);

      if (clientUser.allocated_room === "CLOSED") {
        oldroomstatus = "CLOSED";
        clientUser.allocated_room = "NEW";
        allocatedRoom = "NEW";

        console.log("From close to new");

        await redisClient.zAdd(`${channel}-conversation-new`, {
          score: Date.now(),
          value: clientPresence,
        });
      } else if (clientUser.allocated_room === "NEW") {
        oldroomstatus = "NEW";
        clientUser.allocated_room = "NEW";
        allocatedRoom = "NEW";
        await redisClient.zAdd(`${channel}-conversation-new`, {
          score: Date.now(),
          value: clientPresence,
        });
      } else {
        oldroomstatus = "";
        clientUser.allocated_room = "ACTIVE";
        allocatedRoom = "ACTIVE";

        console.log("ALWAYS ACTIVE");

        await redisClient.zAdd(`${channel}-conversation-active`, {
          score: Date.now(),
          value: clientPresence,
        });
      }
      console.log("this is triggered active");
    } else {
      console.log("no agents assigned?");

      await redisClient.zAdd(`${channel}-conversation-new`, {
        score: Date.now(),
        value: clientPresence,
      });
      allocatedRoom = "NEW";
      oldroomstatus = clientUser.allocated_room;
      console.log("this is triggered new");
    }

    if (clientUser.allocated_room) {
      if (clientUser.allocated_room === "CLOSED") {
        clientUser.allocated_room = "NEW";
        await redisClient.zRem(`${channel}-conversation-close`, clientPresence);
      }
    }

    const existingUserListRecord = [
      `${objData.message}`,
      `${objData.time}`,
      `${clientInfo[0]}-user`,
      "user",
      `${objData.uid}`,
    ];
    console.log("[type1][message] writing existing-user Redis list record", {
      chatKey: redisKey,
      uid: objData.uid,
      lPushArgumentOrder: ["message", "time", "sender", "profile", "uid"],
      lRangeStoredOrder: ["uid", "profile", "sender", "time", "message"],
      record: existingUserListRecord,
    });
    const pushResult = await redisClient.lPush(
      redisKey,
      existingUserListRecord,
    );

    console.log(clientInfo, "thisi s client info", objData, "ubjety]");

    if (pushResult) {
      console.log("already sent message to database ");
    }
    clientUser.last_message = objData.last_message;
    clientUser.message = objData.message;

    //First assigning of clientUser agent_unread client_unread
    // clientUser.client_unread = 0;
    // clientUser.agent_unread = Number(clientUser.agent_unread || 0) + 1;

    const clientUnread = 0;
    const agentUnread = Number(clientUser.agent_unread || 0) + 1;

    clientUser.status = "online";
    let isMatch = clientUser.id === objData.id;

    // ------------------- CLIENT UPDATE -------------------

    if (isMatch) {
      objData.agent_unread = agentUnread;
      objData.client_unread = clientUnread;
      objData.email = clientUser.email;
      objData.allocated_room = allocatedRoom;
      objData.last_message = objData.last_message;
      objData.username = objData.username + "-user";
      objData.messageType = "website";
      objData.socketId = clientUser.socketID;
      objData.id = clientUser.id;
      objData.status = "online";
      objData.unread_agent = clientUser.agent_unread;
      objData.chatUpdated = Date.now();
      objData.seen = "";

      ((objData.appId = clientUser.appId),
        (objData.pageId = clientUser.pageId),
        console.log(
          "check this",
          objData.last_message,
          objData.message,
          agentUnread,
          clientUnread,
          allocatedRoom,
        ));
      // Saving redis

      console.log("olduser", objData);
      console.log("msgindx", Number(clientUser.msgIndex) + 1);
      // await redisClient.hSet(userKeyClient, {
      //   last_message: objData.last_message,
      //   message: objData.message,
      //   agent_unread: agentUnread,
      //   allocated_room: allocatedRoom,
      //   client_unread: clientUnread,
      //   chatUpdated: Date.now(),

      //   msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1
      // });
      await redisClient.hSet(userKeyClient, {
        seen: "",
        last_message: objData.last_message,
        message: objData.message,
        agent_unread: agentUnread,
        allocated_room: allocatedRoom,
        client_unread: clientUnread,
        chatUpdated: Date.now(),
        msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
        // "true" only when this message reopens a CLOSED room, "false" otherwise.
        // Stored as a string because redis hashes hold strings, so read it back
        // with === "true", not as a boolean.
        emailtranscript: oldroomstatus === "CLOSED" ? "true" : "false",

        // This safely adds the key only if the condition is met
        ...(oldroomstatus === "CLOSED" && { firstChatTimestamp: objData.time }),

        // NOTE: aiEnable is deliberately NOT written here, not even when this
        // message reopens a CLOSED room. Reopening carries the previous value
        // forward, so a conversation a human agent already answered (type 2 set
        // "false") stays AI-off after it closes and reopens. Only chatNewUser arms
        // it, on a genuinely first-time conversation.
      });
      const messageResult = await keyChatAdded(userKeyClient, subdomain);
      console.log("userKeyClient:", userKeyClient);
      console.log("oldroomstatus:", oldroomstatus);

      await saveMessageForSearch(
        `${objData.message}`,
        `${objData.time}`,
        `${clientInfo[0]}`,
        `${clientInfo[1]}`,
        `user`, // Pass profile here
        `${objData.uid}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        redisKey,
        `website`,
        `${userKeyClient}`,
        `${objData.message}`,
        `${objData.time}`,
        `${clientInfo[0]}-user`,
        `user`, // `${channel}`
        `${objData.uid}`,
        subdomain,
      );

      console.log(
        "this is for saveMessage for search TYPE chatolduser",
        `${objData.message}`,
        `${objData.time}`,
        `${clientInfo[0]}`,
        `${clientInfo[1]}`,
        `user`, // Pass profile here
        `${objData.uid}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        redisKey,
        `website`,
        `${userKeyClient}`,
        `${objData.message}`,
        `${objData.time}`,
        `${clientInfo[0]}-user`,
        `user`, // `${channel}`
        `${objData.uid}`,
        subdomain,
      );

      let unassignedUpdateUnread;

      await redisClient.zRem(
        `${subdomain}-overallUnreadCount`,
        `unread-${userKeyClient}`,
      );
      
      await redisClient.zAdd(`${subdomain}-overallUnreadCount`, {
        score: Date.now(),
        value: `unread-${userKeyClient}`,
      });
      const overallUnreadCount = await redisClient.zCard(
        `${subdomain}-overallUnreadCount`,
      );

      console.log(
        "type of agent_unread",
        typeof clientUser.unreadClient,
        typeof clientUser.agent_unread,
        clientUser.agent_unread,
      );

      if (clientUser?.Agent_Name) {
        objData.Agent_Name = clientUser.Agent_Name;
      }

      const rawUsername = objData.username;
      let addLogsUsername;
      if (rawUsername && rawUsername.includes("-")) {
        // Split by the hyphen and take the first part (index 0)
        const nameOnly = rawUsername.split("-")[0];

        console.log("Extracted name:", nameOnly); // Output: LeeSon
        addLogsUsername = nameOnly;
      } else {
        // Fallback in case the string isn't in the expected format
        console.log("Name format unexpected:", rawUsername);
        addLogsUsername = rawUsername;
      }

      const conversationLogsData = {
        date: objData.time,
        username: addLogsUsername,
        email: objData.useremail,
        to: clientUser.extAgentName
          ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
          : "",
        messageType: clientUser.messageType,
        conversationsFetch: redisKey,
      };

      const conversationLogsData2 = {
        date: objData.time,
        username: addLogsUsername,
        email: objData.useremail,
        to: clientUser.extAgentName
          ? `${clientUser.extAgentName} (${clientUser.extAgentExtension})`
          : "",
        message: objData.saveLogsMessage ?? objData.message,
      };

      const conversationLogsResult = await conversationLogsAdd(
        "client",
        conversationLogsData,
        subdomain,
      );
      console.log(conversationLogsResult);

      const chatHistoryKeyExtension = `${redisKey}`;
      const conversationLogsExtensionResult =
        await conversationLogsAddExtension(
          "client",
          conversationLogsData2,
          chatHistoryKeyExtension,
        );
      console.log("conversationLogsResult2", conversationLogsExtensionResult);

      if (clientUser?.assigned_agent && clientUser?.assigned_agent !== "[]") {
        let assignedAgents = [];
        try {
          assignedAgents = JSON.parse(clientUser.assigned_agent || "[]");
        } catch (e) {
          console.error("Invalid assigned_agent:", e);
        }

        objData.assigned_agent = clientUser.assigned_agent;

        console.log("this is obj assigned", objData.assigned_agent);

        let agentKeyArray = clientUser.assigned_agent;
        let agentKey;

        console.log("raw agentList:", agentKeyArray);
        console.log("type:", typeof agentKeyArray);

        if (typeof agentKeyArray === "string") {
          try {
            agentKeyArray = JSON.parse(agentKeyArray);
            console.log("parsed JSON");
          } catch (e) {
            console.error("Invalid JSON:", agentKeyArray);
            agentKeyArray = [];
          }
        }

        if (Array.isArray(agentKeyArray)) {
          console.log("arr lengt:", agentKeyArray.length);
          console.log("1st value:", agentKeyArray[0]);
          agentKey = agentKeyArray[0];
        } else {
          console.log("not arry, value:", agentKeyArray);
        }

        const agentSocketId = await redisClient.hGet(agentKey, "socketID");
        const agentDetails = await redisClient.hGetAll(agentKey);

        const agentUnreadCount = `unread-${agentKey}`;

        await redisClient.zAdd(`${agentUnreadCount}`, {
          score: Date.now(),
          value: `unread-${userKeyClient}`,
        });

        const count = await redisClient.zCard(`${agentUnreadCount}`);

        unassignedUpdateUnread = {
          agent_unread: overallUnreadCount,
          total: count,
          extension: agentDetails.extension,
        };

        let assignedClients = [];
        const rawAssigned = agentDetails?.assigned;
        if (rawAssigned) {
          try {
            assignedClients = JSON.parse(rawAssigned);
          } catch (err) {
            console.error(
              `Invalid JSON in assigned field for ${agentKey}:`,
              err,
            );
          }
        }

        // remove
        assignedClients = assignedClients.filter(
          (c) => c.trim() !== userKeyClient.trim(),
        );

        // added to index 0
        assignedClients.unshift(userKeyClient.trim());

        await redisClient.hSet(agentKey, {
          assigned: JSON.stringify(assignedClients),
        });

        if (userKeyClient.agent_unread == 0) {
          await redisClient.hSet(agentKey, {
            agent_unread: agentDetails.agent_unread++,
          });
        }

        // if (agentDetails?.roomID !== "all_messages") {
        //   socketSignal.emit("chatParameters", "queue", objData, agentSocketId);
        //   socketSignal.emit(
        //     "chatParameters",
        //     "queue_updatedata",
        //     unassignedUpdateUnread,
        //     agentSocketId,
        //   );
        //   console.log("this is done");
        // }

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${subdomain}-all_messages`,
        );

        console.log(
          "this is sending the count of unread on agent",
          unassignedUpdateUnread,
        );
        socketSignal.emit(
          "chatParameters",
          "queue",
          objData,
          `${subdomain}-all_messages`,
        );
      } else {
        console.log(
          "not assigned and this will be triggered if last message is agent",
        );

        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          unassignedUpdateUnread,
          `${subdomain}-all_messages`,
        );

        socketSignal.emit(
          "chatParameters",
          "queue",
          objData,
          `${subdomain}-all_messages`,
        );

        if (Number(clientUser.isReceived) == 0) {
          await redisClient.hSet(userKeyClient, {
            isReceived: 1,
          });
        } else {
          console.log(" is received is 1 do nothing");
        }

        unassignedUpdateUnread = {
          agent_unread: overallUnreadCount,
          // total: 0,
        };
      }

      const socketID = clientUser.socketID.replace(/"/g, "");

      if (mysocket === socketID) {
        socketSignal.emit("chatParameters", "queue", objData, mysocket);
      } else {
        await redisClient.hSet(userKeyClient, {
          socketID: mysocket,
        });
        socketSignal.emit("chatParameters", "queue", objData, mysocket);
      }
    } else {
      console.log("No matching client for update.");
    }
  } catch (err) {
    console.error("ERROR in chatOldUser:", err);
  }
}

async function smsConversationListUpdate(
  conversationKey,
  allocatedRoom,
  fqdnValue,
) {
  let setAllocatedRoom;

  if (allocatedRoom === "NEW") {
    setAllocatedRoom = "new";
  } else if (allocatedRoom === "ACTIVE") {
    setAllocatedRoom = "active";
  } else if (allocatedRoom === "CLOSED") {
    setAllocatedRoom = "close";
  } else {
    console.log("what's wrong honey");
  }
  console.log(`${fqdnValue}-conversation-${setAllocatedRoom} saved`);

  console.log("start test sms conv", allocatedRoom, setAllocatedRoom);

  await redisClient.zRem(
    `${fqdnValue}-conversation-close`,
    `${conversationKey}`,
  );
  await redisClient.zRem(
    `${fqdnValue}-conversation-active`,
    `${conversationKey}`,
  );

  await redisClient.zRem(`${fqdnValue}-conversation-new`, `${conversationKey}`);
  await redisClient.zAdd(`${fqdnValue}-conversation-${setAllocatedRoom}`, {
    score: Date.now(),
    value: `${conversationKey}`,
  });
  /** end */

  // console.log("this is the data", `${userName}-${displayNumber}-whatsapp-${id}`);
  /** for all chat */
  await redisClient.zRem(`${fqdnValue}-conversation`, `${conversationKey}`);
  await redisClient.zAdd(`${fqdnValue}-conversation`, {
    score: Date.now(),
    value: `${conversationKey}`,
  });
  /** end */

  // Updating the client userkey
}

async function facebookConversationList(
  fqdnValue,
  username,
  recipient,
  senderId,
  setAllocatedRoom,
) {
  const rem = await redisClient.zRem(
    `${fqdnValue}-conversation-new`,
    `${username}-${recipient}-facebook-${senderId}`,
  );
  const rem3 = await redisClient.zRem(
    `${fqdnValue}-conversation-active`,
    `${username}-${recipient}-facebook-${senderId}`,
  );
  const rem4 = await redisClient.zRem(
    `${fqdnValue}-conversation-close`,
    `${username}-${recipient}-facebook-${senderId}`,
  );
  const sent = await redisClient.zAdd(
    `${fqdnValue}-conversation-${setAllocatedRoom}`,
    {
      score: Date.now(),
      value: `${username}-${recipient}-facebook-${senderId}`,
    },
  );
  console.log("bughunt", `${fqdnValue}-conversation-${setAllocatedRoom}`);

  const rem2 = await redisClient.zRem(
    `${fqdnValue}-conversation`,
    `${username}-${recipient}-facebook-${senderId}`,
  );
  const sent2 = await redisClient.zAdd(`${fqdnValue}-conversation`, {
    score: Date.now(),
    value: `${username}-${recipient}-facebook-${senderId}`,
  });
  console.log(`Yow ${username}-${recipient}-facebook-${senderId}`);

  console.log(
    `${fqdnValue}-conversation-new`,
    `${username}-${recipient}-facebook-${senderId}`,
  );
}

async function whatsappConversationList(
  userName,
  email,
  id,
  clientPresence,
  fqdnValue,
  allocatedRoom,
) {
  console.log(`whats app details`, userName, id);
  /** for new chat */
  console.log("this is safeusername", userName, clientPresence, allocatedRoom);

  let setAllocatedRoom;

  if (allocatedRoom === "NEW") {
    setAllocatedRoom = "new";
  } else if (allocatedRoom === "ACTIVE") {
    setAllocatedRoom = "active";
  } else if (allocatedRoom === "CLOSED") {
    setAllocatedRoom = "close";
  } else {
    console.log("what's wrong honey");
  }
  console.log(`${fqdnValue}-conversation-${setAllocatedRoom} saved`);
  await redisClient.zRem(
    `${fqdnValue}-conversation-close`,
    `${id}-${id}-whatsapp-${email}`,
  );
  await redisClient.zRem(
    `${fqdnValue}-conversation-active`,
    `${id}-${id}-whatsapp-${email}`,
  );

  await redisClient.zRem(
    `${fqdnValue}-conversation-new`,
    `${id}-${id}-whatsapp-${email}`,
  );
  await redisClient.zAdd(`${fqdnValue}-conversation-${setAllocatedRoom}`, {
    score: Date.now(),
    value: `${id}-${id}-whatsapp-${email}`,
  });
  /** end */

  console.log("this is the data", `${id}-${id}-whatsapp-${email}`);
  /** for all chat */
  await redisClient.zRem(
    `${fqdnValue}-conversation`,
    `${id}-${id}-whatsapp-${email}`,
  );
  await redisClient.zAdd(`${fqdnValue}-conversation`, {
    score: Date.now(),
    value: `${id}-${id}-whatsapp-${email}`,
  });
  /** end */

  // Updating the client userkey
}

async function tokenTest(keyParam, url, accessToken) {
  const list = await redisClient.lRange(keyParam, 0, -1);
  console.log("this is token test data", url, accessToken, keyParam);

  for (const raw of list) {
    let data;
    try {
      data = JSON.parse(raw.replace(/[“”]/g, '"'));
    } catch {
      continue;
    }

    console.log("data url", data.url, "accesstoken", data.accessToken);
    if (data.url === url && data.accessToken === accessToken) {
      return data.accessToken;
    }
  }
}

async function addToken(config, challengeFqdn, challengeAccessToken, userKey) {
  const verifyKey = `${config}:fqdn:${challengeFqdn}`;
  const list = await redisClient.lRange(verifyKey, 0, -1);

  for (const raw of list) {
    let data;
    try {
      data = JSON.parse(raw.replace(/[“”]/g, '"'));
    } catch {
      continue;
    }

    console.log("data.fqdn", data.fqdn, challengeFqdn, data.accessToken);

    if (
      data.fqdn === challengeFqdn &&
      data.accessToken === challengeAccessToken
    ) {
      const user = (await redisClient.exists(userKey))
        ? await redisClient.hGetAll(userKey)
        : null;
      if (user) {
        await redisClient.hSet(userKey, {
          accessToken: data.accessToken,
          pageId: data.id,
          appId: data.name,
        });
        console.log("access token is successfully added to", userKey);
      }
      console.log("already sentback");
      return {
        verifiedToken: data.accessToken,
        pageId: data.id,
        appId: data.name,
      };
    }
  }
}

async function verifyToken(config, challengeFqdn, challengeNumber) {
  const verifyKey = `${config}:fqdn:${challengeFqdn}`;
  const verifyParameters = await redisClient.lRange(verifyKey, 0, -1);
  console.log(
    "verify start...",
    verifyKey,
    config,
    challengeFqdn,
    challengeNumber,
  );
  if (!verifyParameters.length) return null;

  for (const raw of verifyParameters) {
    let data;
    try {
      data = JSON.parse(raw.replace(/[“”]/g, '"'));
    } catch {
      continue;
    }

    if (data.fqdn !== challengeFqdn || !data.accessToken) continue;

    if (config === "facebookconfig") {
      if (String(data.appId) === String(challengeNumber)) {
        return {
          accessToken: data.accessToken,
          appId: data.appId,
        };
      }
    }

    if (config === "whatsappconfig") {
      console.log("whatsappConfig pass");
      if (String(data.appId) === String(challengeNumber)) {
        console.log(
          "this is verified with",
          data.appId,
          data.accessToken,
          data.number,
        );
        return {
          accessToken: data.accessToken,
          appId: data.appId,
        };
      }
    }
  }

  return null;
}

async function verifyToken2(config, challengeFqdn, challengeAccessToken) {
  const verifyKey = `${config}:fqdn:${challengeFqdn}`;
  const list = await redisClient.lRange(verifyKey, 0, -1);

  for (const raw of list) {
    let data;
    try {
      data = JSON.parse(raw.replace(/[“”]/g, '"'));
    } catch {
      continue;
    }

    if (
      data.fqdn === challengeFqdn &&
      data.accessToken === challengeAccessToken
    ) {
      console.log("this is accesstoken from verify token2",
        data.accessToken,
        challengeAccessToken,
      );
      return data.accessToken;
    }
  }

  return null;
}

// async function verifyToken3(
//   redisKey,
//   number,
//   clientKey,
//   insertArg,
//   currentSms,
// ) {
//   console.log("insert arg", insertArg)
//   const getRedisKey = await redisClient.get(redisKey);

//   if (typeof getRedisKey !== "string") {
//     console.log("Redis key missing or invalid:", redisKey);
//     return false;
//   }

//   console.log("verified token3", getRedisKey);

//   let data;
//   try {
//     data = JSON.parse(getRedisKey.replace(/[“”]/g, '"'));
//   } catch (err) {
//     console.error("Invalid JSON in Redis:", err);
//     return false;
//   }

//   if (data.phoneNumber !== number) return false;

//   console.log("this is verifyied?", data.phoneNumber, number);
//   let user = (await redisClient.exists(clientKey))
//     ? await redisClient.hGetAll(clientKey)
//     : null;

//   if (!user) {
//     console.log("this is not existing add new");

//     validate = true;
//     await redisClient.hSet(clientKey, {
//       id: data.id,
//       username: insertArg.customerNumber,
//       created: Date.now(),
//       email: insertArg.customerNumber,
//       role: "customer",
//       socketID: insertArg.mysocket,
//       Dept: "customer",
//       client_unread: 0,
//       agent_unread: 1,
//       last_message: insertArg.text,
//       allocated_room: "NEW",
//       messageType: currentSms,
//       fqdn: insertArg.fqdn,
//       number: data.id,
//       pageId: data.id,
//       appId: data.name,
//       accessToken: data.accessToken,
//       ourNumber: insertArg.ourNumber,
//     });

//     const userKeyNoUrl = clientKey.split("-");
//     const [id, channel, username, email, url] = userKeyNoUrl
//     const userKeyNoUrlPush = id + "-" + channel + "-" + username + "-" + email
//     await redisClient.lPush(`${insertArg.fqdn}-client_data`, userKeyNoUrlPush);
//   }
//   else {
//     console.log("already existed");
//   }

//   await redisClient.hSet(clientKey, {
//     accessToken: data.accessToken,
//     pageId: data.id,
//     appId: data.name,
//     redisKey: redisKey
//   });

//   return {
//     accessToken: data.accessToken,
//     name: data.name,
//     id: data.id,
//   };
// }

async function verifyToken5(
  config,
  challengeFqdn,
  arg,
  challengeParameter1,
  challengeParameter2,
  challengeParameter3,
) {
  await createSearchIndex(challengeFqdn);
  const verifyKey = `${config}${challengeFqdn}`;
  console.log("verifykey");
  const list = await redisClient.lRange(verifyKey, 0, -1);
  console.log("received", config, challengeFqdn, arg, challengeParameter1);
  if (challengeParameter2) {
    console.log("received", challengeParameter2);
  }
  let assignedAgentRoundRobin = "";
  console.log("verifie 4", verifyKey);
  for (const raw of list) {
    let data;

    try {
      data = JSON.parse(raw.replace(/[“”]/g, '"'));
    } catch {
      continue;
    }
    console.log("data: ", data);

    console.log("data.fqdn", data.fqdn);
    console.log("challengeFqdn", challengeFqdn);
    console.log(" data.accessToken", data.accessToken);
    console.log("challengeParameter1", challengeParameter1);
    console.log("challengeParameter2", challengeParameter2);
    console.log("arg.messageType", arg.messageType);

    console.log("adasdad", config, challengeFqdn, arg, challengeParameter1);

    const isTelegram =
      data.fqdn === challengeFqdn &&
      data.id === challengeParameter1 &&
      arg.messageType == "telegram";

    const isLine =
      data.fqdn === challengeFqdn &&
      data.id === challengeParameter1 &&
      arg.messageType == "line";

    if (isTelegram || isLine) {
      const originalAccessToken = data.accessToken;
      const cleanedAccessToken = originalAccessToken
        ? originalAccessToken.replace(/[:_-]/g, "")
        : "";

      //Telegram: Email == accesstoken
      //Line: Email == Page.id
      const originalPageID = data.id;
      const cleanedPageID = originalPageID
        ? originalPageID.replace(/-/g, "")
        : "";
      let clientKey;

      if (isTelegram) {
        clientKey = `${arg.id}-${arg.messageType}-${arg.id}-${cleanedAccessToken}-${challengeFqdn}`;
        console.log("client key for tg is", clientKey);
        console.log("this is cleaned", cleanedAccessToken, cleanedPageID);
      } else if (isLine) {
        clientKey = `${arg.id}-${arg.messageType}-${arg.id}-${cleanedPageID}-${challengeFqdn}`;
      }

      let user = (await redisClient.exists(clientKey))
        ? await redisClient.hGetAll(clientKey)
        : null;

      if (!user) {
        console.log("this is not existing add new");
        const userData = {
          id: arg.id,
          username: arg.id,
          created: Date.now(),
          email: isTelegram ? cleanedAccessToken : cleanedPageID,
          role: "customer",
          socketID: arg.mysocket,
          Dept: "customer",
          client_unread: 0,
          agent_unread: 0,
          last_message: arg.text,
          message: arg.text,
          allocated_room: "NEW",
          messageType: arg.messageType,
          fqdn: arg.fqdn,
          number: data.id,
          pageId: data.id,
          appId: data.name,
          accessToken: originalAccessToken,
          ourNumber: arg.id,
          referenceName: arg.referenceName,
        };

        const assignedValue = await assignedRoundRobin(
          clientKey,
          userData,
          challengeFqdn,
        );

        await redisClient.hSet(clientKey, {
          id: arg.id,
          username: arg.id,
          created: Date.now(),
          email: isTelegram ? cleanedAccessToken : cleanedPageID,
          role: "customer",
          socketID: arg.mysocket,
          Dept: "customer",
          client_unread: 0,
          agent_unread: 0,
          last_message: arg.text,
          message: arg.text,
          allocated_room: "NEW",
          messageType: arg.messageType,
          fqdn: arg.fqdn,
          number: data.id,
          pageId: data.id,
          appId: data.name,
          accessToken: originalAccessToken,
          ourNumber: arg.id,
          referenceName: arg.referenceName,
          assigned_agent: assignedValue,
          // msgIndex: 1,
        });

        const userKeyNoUrl = clientKey.split("-");
        const [id, channel, username, email] = userKeyNoUrl;
        const userKeyNoUrlPush = `${id}-${channel}-${username}-${email}`;

        await redisClient.lPush(`${arg.fqdn}-client_data`, userKeyNoUrlPush);
        await createSearchIndexProfile(arg.fqdn);
        await saveProfileDataForSearch(
          `${arg.id}`, // username
          isTelegram ? cleanedAccessToken : cleanedPageID, // email
          `${clientKey}`,
          `${arg.referenceName}`,
        );
      } else {
        console.log("already existed");
      }

      await redisClient.hSet(clientKey, {
        accessToken: data.accessToken,
        pageId: data.id,
        appId: data.name,
      });

      return {
        accessToken: data.accessToken,
        name: data.name,
        id: data.id,
        clientKey: clientKey,
      };
    }

    // else if(Telegram || isLine){

    //   const originalAccessToken = data.accessToken;
    //   const cleanedAccessToken = originalAccessToken ? originalAccessToken.replace(/:/g, "") : "";

    //   const clientKey = `${arg.id}-line-${arg.id}-${cleanedAccessToken}-${challengeFqdn}`

    //   let user = (await redisClient.exists(clientKey))
    //   ? await redisClient.hGetAll(clientKey)
    //   : null;

    // if (!user) {

    //   console.log("this is not existing add new");

    //   await redisClient.hSet(clientKey, {
    //     id: arg.id,
    //     username: arg.id,
    //     created: Date.now(),
    //     email: cleanedAccessToken,
    //     role: "customer",
    //     socketID: arg.mysocket,
    //     Dept: "customer",
    //     client_unread: 0,
    //     agent_unread: 1,
    //     last_message: arg.text,
    //     message: arg.text,
    //     allocated_room: "NEW",
    //     messageType: arg.messageType,
    //     fqdn: arg.fqdn,
    //     number: data.id,
    //     pageId: data.id,
    //     appId: data.name,
    //     accessToken: originalAccessToken,
    //     ourNumber: arg.id,
    //     referenceName: arg.referenceName
    //   });

    //   const userKeyNoUrl = clientKey.split("-");
    //   const [id, channel, username, email] = userKeyNoUrl;
    //   const userKeyNoUrlPush = `${id}-${channel}-${username}-${email}`;

    //   await redisClient.lPush(`${arg.fqdn}-client_data`, userKeyNoUrlPush);
    // } else {
    //   console.log("already existed");
    // }

    // await redisClient.hSet(clientKey, {
    //   accessToken: data.accessToken,
    //   pageId: data.id,
    //   appId: data.name,
    // });

    // return {
    //   accessToken: data.accessToken,
    //   name: data.name,
    //   id: data.id,
    //   clientKey: clientKey,
    // };

    // }
    else {
      console.log("not telegram or line");
    }
  }
}

// async function verifyToken4(
//   config,
//   challengeFqdn,
//   arg,
//   clientKey,
//   challengeParameter1,
//   challengeParameter2,
//   challengeParameter3,
// ) {

//   const enableOwner = await redisClient.get(`chatsettings:${challengeFqdn}-enableOwnerManagerAssign`);
//   const enableRoundRobin = await redisClient.get(`chatsettings:${challengeFqdn}-enableRoundRobin`);
//   const enableEveryAgentQ = await redisClient.get(`chatsettings:${challengeFqdn}-enableEveryAgentQ`);
//   console.log("chatsettings checker", "en-owner:", enableOwner, "en-agent:", enableEveryAgentQ, "en-robin", enableRoundRobin)

//   let assignedAgentRoundRobin = "";

//   await createSearchIndex(challengeFqdn);
//   const verifyKey = `${config}${challengeFqdn}`;
//   const list = await redisClient.lRange(verifyKey, 0, -1);
//   console.log(
//     "received",
//     config,
//     challengeFqdn,
//     arg,
//     clientKey,
//     challengeParameter1,
//   );
//   if (challengeParameter2) {
//     console.log("received", challengeParameter2);
//   }
//   console.log("verifie 4", verifyKey);
//   for (const raw of list) {
//     let data;

//     try {
//       data = JSON.parse(raw.replace(/[“”]/g, '"'));
//     } catch {
//       continue;
//     }
//     console.log("data: ", data);

//     console.log("data.fqdn", data.fqdn);
//     console.log("challengeFqdn", challengeFqdn);
//     console.log(" data.accessToken", data.accessToken);
//     console.log("data.phoneNumber", data.phoneNumber);
//     console.log("data.accountSid", data.accountSid);
//     console.log("challengeParameter1", challengeParameter1);
//     console.log("data.MessagingServiceSid", data.messagingServiceSid);
//     console.log("challengeParameter2", challengeParameter2);
//     console.log("arg.messageType", arg.messageType);

//     console.log(
//       "adasdad",
//       config,
//       challengeFqdn,
//       arg,
//       clientKey,
//       challengeParameter1,
//     );

//     if (arg.messageType === "telnyx" || arg.messageType === "twilio") {
//       if (data.phoneNumber === arg.customerNumber) {
//         console.log(
//           "please use different personal number as your number is same as indicated in configuration which should be not",
//         );
//       }
//     }

//     // support Twilio OR Telnyx verification
//     const isTwilio =
//       data.fqdn === challengeFqdn &&
//       data.accountSid === challengeParameter1 &&
//       data.messagingServiceSid === challengeParameter2 &&
//       data.phoneNumber === challengeParameter3;
//     const isTelnyx =
//       data.fqdn === challengeFqdn && data.phoneNumber === challengeParameter1;
//     const isTelegram =
//       data.fqdn === challengeFqdn &&
//       data.id === challengeParameter1 &&
//       arg.messageType == "telegram";
//     const isLine =
//       data.fqdn === challengeFqdn &&
//       data.id === challengeParameter1 &&
//       arg.messageType == "line";

//     if (isTwilio || isTelnyx) {
//       console.log(
//         "this is accesstoken from verifyToken4",
//         data.accessToken,
//         data.messagingServiceSid,
//         data.accountSid,
//       );

//       let user = (await redisClient.exists(clientKey))
//         ? await redisClient.hGetAll(clientKey)
//         : null;

//       if (!user) {
//         console.log("this is not existing add new");
//         const userData = {
//           id: arg.ourNumber,
//           username: arg.customerNumber,
//           created: Date.now(),
//           email: arg.customerNumber,
//           role: "customer",
//           socketID: arg.mysocket,
//           Dept: "customer",
//           client_unread: 0,
//           agent_unread: 1,
//           last_message: arg.text,
//           allocated_room: "NEW",
//           messageType: arg.messageType,
//           fqdn: arg.fqdn,
//           number: data.id,
//           pageId: data.id,
//           appId: data.name,
//           accessToken: data.accessToken,
//           ourNumber: arg.ourNumber,
//         }

//         const assignedValue = await assignedRoundRobin(clientKey, userData, challengeFqdn);

//         await redisClient.hSet(clientKey, {
//           id: arg.ourNumber,
//           username: arg.customerNumber,
//           created: Date.now(),
//           email: arg.customerNumber,
//           role: "customer",
//           socketID: arg.mysocket,
//           Dept: "customer",
//           client_unread: 0,
//           agent_unread: 1,
//           last_message: arg.text,
//           allocated_room: "NEW",
//           messageType: arg.messageType,
//           fqdn: arg.fqdn,
//           number: data.id,
//           pageId: data.id,
//           appId: data.name,
//           accessToken: data.accessToken,
//           ourNumber: arg.ourNumber,
//           assigned_agent: assignedValue
//           // msgIndex: 1
//         });

//         const userKeyNoUrl = clientKey.split("-");
//         const [id, channel, username, email] = userKeyNoUrl;
//         const userKeyNoUrlPush = `${id}-${channel}-${username}-${email}`;

//         await redisClient.lPush(`${arg.fqdn}-client_data`, userKeyNoUrlPush);
//         await createSearchIndexProfile(arg.fqdn);
//         await saveProfileDataForSearch(
//           `${username}`, // username
//           `${arg.customerNumber}`, // email
//           `${clientKey}`,
//           `${username}`
//         );

//       }
//       else {
//         const data = await redisClient.hGetAll(clientKey);
//         await redisClient.hSet(clientKey, {
//           msgIndex: data.msgIndex ? Number(data.msgIndex) + 1 : 1
//         })
//       }

//       await redisClient.hSet(clientKey, {
//         accessToken: data.accessToken,
//         pageId: data.id,
//         appId: data.name,
//       });

//       if (isTelnyx) {
//         await redisClient.hSet(clientKey, {
//           phoneNumber: data.phoneNumber,
//         });
//       } else if (isTwilio) {
//         await redisClient.hSet(clientKey, {
//           messagingServiceSid: data.messagingServiceSid,
//           accountSid: data.accountSid,
//           phoneNumber: data.phoneNumber,
//         });
//       }

//       // if (type === "telnyx") {
//       //   return {
//       //     curlAccessToken: accessTokenChallenge,
//       //     curlUrl: "https://api.telnyx.com/v2/messages",
//       //     curlFrom: data.phoneNumber,
//       //   };
//       // } else if (type === "twilio") {
//       //   return {
//       //     curlAccessToken: 'cd25fcbc495bb4db93666e4c64087848', // static for now
//       //     curlUrl: `https://api.twilio.com/2010-04-01/Accounts/${data.accountSid}/Messages.json`,
//       //     curlFrom: data.phoneNumber,
//       //     curlAccountSid: data.accountSid,
//       //     curlMessagingServiceSid: data.MessagingServiceSid,
//       //   };
//       // }

//       return {
//         accessToken: data.accessToken,
//         name: data.name,
//         id: data.id,
//       };
//     } else if (isTelegram || isLine) {

//       //this is telegram
//       let user = (await redisClient.exists(clientKey))
//         ? await redisClient.hGetAll(clientKey)
//         : null;

//       if (!user) {
//         // let insertArg = {
//         //   mysocket: mysocket,
//         //   text: text,
//         //   fqdn: fqdnValue,
//         //   messageType: "telegram",
//         //   username: username,
//         //   email: username,
//         //   id: userID
//         // };

//         console.log("this is not existing add new");

//         await redisClient.hSet(clientKey, {
//           id: arg.id,
//           username: arg.username,
//           created: Date.now(),
//           email: arg.username,
//           role: "customer",
//           socketID: arg.mysocket,
//           Dept: "customer",
//           client_unread: 0,
//           agent_unread: 1,
//           last_message: arg.text,
//           message: arg.text,
//           allocated_room: "NEW",
//           messageType: arg.messageType,
//           fqdn: arg.fqdn,
//           number: data.id,
//           pageId: data.id,
//           appId: data.name,
//           accessToken: data.accessToken,
//           ourNumber: arg.id,
//           msgIndex: 1
//         });

//         const userKeyNoUrl = clientKey.split("-");
//         const [id, channel, username, email] = userKeyNoUrl;
//         const userKeyNoUrlPush = `${id}-${channel}-${username}-${email}`;

//         await redisClient.lPush(`${arg.fqdn}-client_data`, userKeyNoUrlPush);
//       } else {
//         const data = await redisClient.hGetAll(clientKey);
//         await redisClient.hSet(clientKey, {
//           msgIndex: data.msgIndex ? Number(data.msgIndex) + 1 : 1
//         })
//       }

//       await redisClient.hSet(clientKey, {
//         accessToken: data.accessToken,
//         pageId: data.id,
//         appId: data.name,
//       });

//       return {
//         accessToken: data.accessToken,
//         name: data.name,
//         id: data.id,
//       };
//     }
//   }

//   return null;
// }

async function verifyToken4(
  config,
  challengeFqdn,
  arg,
  clientKey,
  challengeParameter1,
  challengeParameter2,
  challengeParameter3,
) {
 


  let assignedAgentRoundRobin = "";

  await createSearchIndex(challengeFqdn);
  const verifyKey = `${config}${challengeFqdn}`;
  const list = await redisClient.lRange(verifyKey, 0, -1);
  console.log(
    "received",
    config,
    challengeFqdn,
    arg,
    clientKey,
    challengeParameter1,
  );
  if (challengeParameter2) {
    console.log("received", challengeParameter2);
  }
  console.log("verifie 4", verifyKey);
  for (const raw of list) {
    let data;

    try {
      data = JSON.parse(raw.replace(/[“”]/g, '"'));
    } catch {
      continue;
    }
    console.log("data: ", data);

    console.log("data.fqdn", data.fqdn);
    console.log("challengeFqdn", challengeFqdn);
    console.log(" data.accessToken", data.accessToken);
    console.log("data.phoneNumber", data.phoneNumber);
    console.log("data.accountSid", data.accountSid);
    console.log("challengeParameter1", challengeParameter1);
    console.log("data.MessagingServiceSid", data.messagingServiceSid);
    console.log("challengeParameter2", challengeParameter2);
    console.log("arg.messageType", arg.messageType);

    console.log(
      "adasdad",
      config,
      challengeFqdn,
      arg,
      clientKey,
      challengeParameter1,
    );

    // support Twilio OR Telnyx verification
    const isTwilio =
      data.fqdn === challengeFqdn &&
      data.accountSid === challengeParameter1 &&
      data.messagingServiceSid === challengeParameter2 &&
      data.phoneNumber === challengeParameter3;
    const isTelnyx =
      data.fqdn === challengeFqdn && data.phoneNumber === challengeParameter1;
    const isTelegram =
      data.fqdn === challengeFqdn &&
      data.id === challengeParameter1 &&
      arg.messageType == "telegram";
    const isLine =
      data.fqdn === challengeFqdn &&
      data.id === challengeParameter1 &&
      arg.messageType == "line";

    if (isTwilio || isTelnyx) {
      console.log("valid for telnyx/twilio")

      let user = (await redisClient.exists(clientKey))
        ? await redisClient.hGetAll(clientKey)
        : null;

      if (!user) {
        console.log("this is not existing add new");
        const userData = {
          id: arg.ourNumber,
          username: arg.customerNumber,
          created: Date.now(),
          email: arg.customerNumber,
          role: "customer",
          socketID: arg.mysocket,
          Dept: "customer",
          client_unread: 0,
          agent_unread: 1,
          last_message: arg.text,
          allocated_room: "NEW",
          messageType: arg.messageType,
          fqdn: arg.fqdn,
          number: data.id,
          pageId: data.id,
          appId: data.name,
          accessToken: data.accessToken,
          ourNumber: arg.ourNumber,
        };

        await redisClient.hSet(clientKey, {
          id: arg.ourNumber,
          username: arg.customerNumber,
          created: Date.now(),
          email: arg.customerNumber,
          role: "customer",
          socketID: arg.mysocket,
          Dept: "customer",
          client_unread: 0,
          agent_unread: 1,
          last_message: arg.text,
          allocated_room: "NEW",
          messageType: arg.messageType,
          fqdn: arg.fqdn,
          number: data.id,
          pageId: data.id,
          appId: data.name,
          accessToken: data.accessToken,
          ourNumber: arg.ourNumber,
          // assigned_agent: assignedValue,
          // msgIndex: 1
        });

        const userKeyNoUrl = clientKey.split("-");
        const [id, channel, username, email] = userKeyNoUrl;
        const userKeyNoUrlPush = `${id}-${channel}-${username}-${email}`;

        await redisClient.lPush(`${arg.fqdn}-client_data`, userKeyNoUrlPush);
        await createSearchIndexProfile(arg.fqdn);
        await saveProfileDataForSearch(
          `${username}`, // username
          `${arg.customerNumber}`, // email
          `${clientKey}`,
          `${username}`,
        );
      } else {
        const data = await redisClient.hGetAll(clientKey);
        await redisClient.hSet(clientKey, {
          msgIndex: data.msgIndex ? Number(data.msgIndex) + 1 : 1,
        });
      }

      await redisClient.hSet(clientKey, {
        accessToken: data.accessToken,
        pageId: data.id,
        appId: data.name,
      });



      if (isTelnyx) {
        await redisClient.hSet(clientKey, {
          phoneNumber: data.phoneNumber,
        });
      } else if (isTwilio) {
        await redisClient.hSet(clientKey, {
          messagingServiceSid: data.messagingServiceSid,
          accountSid: data.accountSid,
          phoneNumber: data.phoneNumber,
        });
      }

      // const assignedValue = await assignedRoundRobin(
      //   clientKey,
      //   "",
      //   challengeFqdn,
      // );

      return {
        accessToken: data.accessToken,
        name: data.name,
        id: data.id,
      };
    } else if (isTelegram || isLine) {
      //this is telegram
      let user = (await redisClient.exists(clientKey))
        ? await redisClient.hGetAll(clientKey)
        : null;

      if (!user) {
        // let insertArg = {
        //   mysocket: mysocket,
        //   text: text,
        //   fqdn: fqdnValue,
        //   messageType: "telegram",
        //   username: username,
        //   email: username,
        //   id: userID
        // };

        console.log("this is not existing add new");

        await redisClient.hSet(clientKey, {
          id: arg.id,
          username: arg.username,
          created: Date.now(),
          email: arg.username,
          role: "customer",
          socketID: arg.mysocket,
          Dept: "customer",
          client_unread: 0,
          agent_unread: 1,
          last_message: arg.text,
          message: arg.text,
          allocated_room: "NEW",
          messageType: arg.messageType,
          fqdn: arg.fqdn,
          number: data.id,
          pageId: data.id,
          appId: data.name,
          accessToken: data.accessToken,
          ourNumber: arg.id,
          msgIndex: 1,
        });

        const userKeyNoUrl = clientKey.split("-");
        const [id, channel, username, email] = userKeyNoUrl;
        const userKeyNoUrlPush = `${id}-${channel}-${username}-${email}`;

        await redisClient.lPush(`${arg.fqdn}-client_data`, userKeyNoUrlPush);
      } else {
        const data = await redisClient.hGetAll(clientKey);
        await redisClient.hSet(clientKey, {
          msgIndex: data.msgIndex ? Number(data.msgIndex) + 1 : 1,
        });
      }

      await redisClient.hSet(clientKey, {
        accessToken: data.accessToken,
        pageId: data.id,
        appId: data.name,
      });

      const assignedValue = await assignedRoundRobin(
        clientKey,
        "",
        challengeFqdn,
      );

      return {
        accessToken: data.accessToken,
        name: data.name,
        id: data.id,
      };
    }

  }

  return null;
}

async function twilioCurl(
  curlAccessToken,
  curlTo,
  curlMessage,
  curlUrl,
  curlFrom,
  curlAccountSid,
  curlMessagingServiceSid,
) {
  // Validate access and get Twilio credentials from Redis
  // const verified = await validateSms(redisKeyVerified, accessTokenChallenge, "twilio");

  // if (!verified) {
  //   console.log("this is not verified sms");
  //   return;
  // }

  console.log(
    "pasok sa twilio,",
    curlAccessToken,
    curlUrl,
    curlFrom,
    curlAccountSid,
    curlMessagingServiceSid,
  );

  const payload = {
    MessagingServiceSid: curlMessagingServiceSid,
    To: `+${curlTo}`,
    Body: `${curlMessage}`,
  };

  console.log("sending twilio message to", `+${curlTo}`);

  function shellEscapeJson(obj) {
    return JSON.stringify(obj).replace(/\\/g, "\\\\").replace(/'/g, `'\\''`);
  }

  const safePayload = shellEscapeJson(payload);

  // Use URLSearchParams to convert object to form-urlencoded
  const formPayload = new URLSearchParams(payload).toString();

  const curlCmd = `
    curl -s -w "\\n%{http_code}" -X POST "${curlUrl}" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -u "${curlAccountSid}:${curlAccessToken}" \
    -d '${formPayload}' \
    --max-time 30
  `;

  exec(curlCmd, (error, stdout) => {
    if (error) {
      console.error(
        JSON.stringify({
          status: 500,
          error: error.message,
        }),
      );
      return;
    }

    const lines = stdout.trim().split("\n");
    const httpCode = parseInt(lines.pop(), 10);
    const body = lines.join("\n");

    let response;

    try {
      response = JSON.parse(body);
    } catch {
      response = body;
    }

    console.log("this is success");
    console.log(
      JSON.stringify({
        status: httpCode,
        response,
      }),
    );
  });
}

async function telnyxCurl(
  curlAccessToken,
  curlTo,
  curlMessage,
  curlUrl,
  curlFrom,
) {
  console.log(
    "pasok curl telnyx",
    curlAccessToken,
    curlTo,
    curlMessage,
    curlUrl,
    curlFrom,
  );

  const payload = {
    from: `+${curlFrom}`, // Telnyx phone number from Redis
    to: `+${curlTo}`, // Customer number
    text: `${curlMessage}`, // text only
  };
  console.log(
    "curlPayload before sending",
    payload.from,
    payload.to,
    payload.text,
  );

  const safePayload = shellEscapeJson(payload);
  const curlCmd = `
    curl -s -w "\\n%{http_code}" -X POST "${curlUrl}" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${curlAccessToken}" \
    -d '${safePayload}' \
    --max-time 30
    `;
  function shellEscapeJson(obj) {
    return JSON.stringify(obj).replace(/\\/g, "\\\\").replace(/'/g, `'\\''`);
  }

  exec(curlCmd, (error, stdout) => {
    if (error) {
      console.error(
        JSON.stringify({
          status: 500,
          error: error.message,
        }),
      );
      return;
    }

    const lines = stdout.trim().split("\n");
    const httpCode = parseInt(lines.pop(), 10);
    const body = lines.join("\n");

    let response;
    try {
      response = JSON.parse(body);
    } catch {
      response = body;
    }
    console.log("this is success");
    console.log(
      JSON.stringify({
        status: httpCode,
        response,
      }),
    );
  });
}

async function telegramCurl(
  curlAccessToken,
  curlTo,
  curlMessage,
  curlUrl,
  curlMessageType,
  curlCaption,
) {
  console.log("pasok sa telegram,", curlAccessToken, curlUrl);

  console.log("corlmesejdtayp", curlMessageType);
  let payload = {
    chat_id: curlTo,
    [curlMessageType]: curlMessage,
  };

  console.log("this is payload difference", payload);

  if (["photo", "video", "document"].includes(curlMessageType) && curlCaption) {
    payload.caption = curlCaption;
  }

  // ✅ Convert like http_build_query
  const formPayload = new URLSearchParams(payload).toString();

  let curlCmd;
  // this one is working

  curlCmd = `
    curl -s -w "\\n%{http_code}" -X POST "${curlUrl}" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d '${formPayload}' \
    --max-time 30
  `;

  if (["document", "video"].includes(curlMessageType)) {
    console.log("pomasok");
    curlCmd = `
    curl -X POST "${curlUrl}" \
      -F chat_id="${curlTo}" \
      -F document=@"${curlMessage}" \
      ${curlCaption ? `-F caption="${curlCaption}"` : ""}
    `;
  }

  // if(curlCaption){
  //   const curlCmd = `
  //   curl -X POST "${curlUrl}" \
  //     -F chat_id="${curlTo}" \
  //     -F ${curlMessageType}=@"${curlMessage}" \
  //     ${curlCaption ? `-F caption="${curlCaption}"` : ""}
  //   `;
  // }

  exec(curlCmd, (error, stdout) => {
    if (error) {
      console.error(
        JSON.stringify({
          status: 500,
          error: error.message,
        }),
      );
      return;
    }

    const lines = stdout.trim().split("\n");
    const httpCode = parseInt(lines.pop(), 10);
    const body = lines.join("\n");

    let response;
    try {
      response = JSON.parse(body);
    } catch {
      response = body;
    }

    console.log("this is success");
    console.log(
      JSON.stringify({
        status: httpCode,
        response,
      }),
    );
  });
}

async function lineCurl(
  curlAccessToken,
  curlTo,
  curlMessage,
  msgType,
  previewUrl,
  msgFileName,
  msgFileSize,
) {
  console.log("LINE Integration Triggered", { msgType, msgFileName });

  let lineMessage = {};

  // Ensure msgType is a number for the switch case
  switch (Number(msgType)) {
    case 1: // TEXT
      lineMessage = {
        type: "text",
        text: curlMessage,
      };
      break;

    case 2: // IMAGE
      lineMessage = {
        type: "image",
        originalContentUrl: curlMessage,
        previewImageUrl: curlMessage, // Standard bypass
      };
      break;

    case 3: // VIDEO
      lineMessage = {
        type: "video",
        originalContentUrl: curlMessage,
        previewImageUrl: previewUrl || curlMessage,
      };
      break;

    // case 4: // FILE (PDF, DOCX, etc.)
    // lineMessage = {
    // type: "file",
    // fileName: msgFileName, // The name shown in the chat
    // fileSize: msgFileSize, // Number: size of file in bytes
    // originalContentUrl: curlMessage, // HTTPS URL to the file
    // };
    // break;

    case 4: // FLEX FILE WORKAROUND (Bypasses "Message type not available" error)
      const downloadUrl = curlMessage.includes("?")
        ? `${curlMessage}&openExternalBrowser=1`
        : `${curlMessage}?openExternalBrowser=1`;

      lineMessage = {
        type: "flex",
        altText: `File: ${msgFileName || "Document"}`,
        contents: {
          type: "bubble",
          // Note: 'size' is omitted here to prevent "invalid property /size" errors
          body: {
            type: "box",
            layout: "vertical",
            contents: [
              {
                type: "text",
                text: "FILE RECEIVED",
                weight: "bold",
                color: "#1DB446",
                size: "sm",
              },
              {
                type: "text",
                text: msgFileName || "Untitled File",
                weight: "bold",
                size: "md",
                margin: "md",
                wrap: true,
              },
              {
                type: "text",
                text: `${((msgFileSize || 0) / 1024).toFixed(2)} KB`,
                size: "xs",
                color: "#aaaaaa",
                margin: "xs",
              },
              {
                type: "separator",
                margin: "lg",
              },
              {
                type: "button",
                action: {
                  type: "uri",
                  label: "VIEW / DOWNLOAD",
                  uri: downloadUrl,
                },
                style: "primary",
                color: "#1DB446",
                margin: "lg",
                height: "sm",
              },
            ],
          },
        },
      };
      break;

    default:
      console.error("Unknown msgType:", msgType);
      return;
  }

  const payload = {
    to: curlTo,
    messages: [lineMessage],
  };

  /**
   * Robust JSON escaping for Shell/Curl
   * 1. Stringify the object
   * 2. Replace single quotes with the shell-safe sequence: '\''
   */
  const safePayload = JSON.stringify(payload).replace(/'/g, "'\\''");
  const retryKey = crypto.randomUUID();

  const curlCmd =
    `curl -s -w "\\n%{http_code}" -X POST "https://api.line.me/v2/bot/message/push" ` +
    `-H "Content-Type: application/json" ` +
    `-H "Authorization: Bearer ${curlAccessToken}" ` +
    `-H "X-Line-Retry-Key: ${retryKey}" ` +
    `-d ${JSON.stringify(safePayload)} ` + // Using JSON.stringify handles the escaping for you
    `--max-time 30`;

  exec(curlCmd, (error, stdout) => {
    if (error) {
      console.error("Exec Error:", error.message);
      return;
    }

    const lines = stdout.trim().split("\n");
    const httpCode = parseInt(lines.pop(), 10);
    const body = lines.join("\n");

    let response;
    try {
      response = JSON.parse(body);
    } catch {
      response = body;
    }

    if (httpCode === 200) {
      console.log("LINE: Message sent successfully");
    } else {
      console.error(
        "LINE: API Error",
        JSON.stringify({ status: httpCode, response }),
      );
    }
  });
}

async function validateSms(redisKey, accessTokenChallenge, type) {
  const getRedisKey = await redisClient.get(redisKey);
  if (!getRedisKey) return false;

  console.log("validatesms pumasok", redisKey, accessTokenChallenge);

  let data;
  try {
    data = JSON.parse(getRedisKey.replace(/[“”]/g, '"'));
  } catch (err) {
    console.error("Invalid JSON in Redis:", err);
    return false;
  }

  if (data.accessToken !== accessTokenChallenge) return false;

  if (type === "telnyx") {
    return {
      curlAccessToken: accessTokenChallenge,
      curlUrl: "https://api.telnyx.com/v2/messages",
      curlFrom: data.phoneNumber,
    };
  } else if (type === "twilio") {
    return {
      curlAccessToken: "cd25fcbc495bb4db93666e4c64087848", // static for now
      curlUrl: `https://api.twilio.com/2010-04-01/Accounts/${data.accountSid}/Messages.json`,
      curlFrom: data.phoneNumber,
      curlAccountSid: data.accountSid,
      curlMessagingServiceSid: data.MessagingServiceSid,
    };
  }
}

async function banChecker(ip, channel, socketId) {
  if (!ip) return 0;

  const baseKey = `${channel}:webchat:ratelimiting`;
  const bannedKey = `${baseKey}:banned:${ip}`;
  const shadowSetKey = `${baseKey}:banned-set:${ip}`;

  // 1. Check if the Ban Hash exists
  // We use .exists() because hSet creates a Hash, and .get() would return an error
  const isBanned = await redisClient.exists(bannedKey);

  if (isBanned) {
    console.log(
      `[BAN CHECK] IP ${ip} is still banned. Capturing socket: ${socketId}`,
    );

    // 2. Add the current socketId to the shadow set
    // This ensures this specific tab gets the unban signal later
    if (socketId) {
      await redisClient.sAdd(shadowSetKey, socketId);

      // 3. Set a long safety TTL (24h)
      // The Cron job will delete this, but this ensures it doesn't leak if the Cron fails
      await redisClient.expire(shadowSetKey, 86400);
    }

    return 1; // User is blocked
  }

  return 0; // User is clear
}

async function rateLimitMessages(
  redisClient,
  baseKey,
  existingKey = null,
  ip,
  clientKey,
  chatKey,
  subdomain,
  mysocket,
) {
  const baseKey2 = `${subdomain}:webchat:ratelimiting`;
  const activeBansGlobalSet = `global:active_bans_list`; // The tracking list

  const BAN_DURATION = 60 * 60; // Set to 60 for your 1-minute test
  const BAN_DURATION_MS = BAN_DURATION * 1000;
  const WINDOW = 1;
  const LIMIT = 1000;

  const bannedKey = `${baseKey2}:banned:${ip}`;
  const bannedClientsSet = `${baseKey2}:banned-set:${ip}`;

  // 1️⃣ Check if IP is already banned
  const isBanned = await redisClient.exists(bannedKey);
  if (isBanned) {
    // Collect new socket ID even if already banned (for reconnects)
    if (mysocket) await redisClient.sAdd(bannedClientsSet, clientKey);
    return { allowed: false, bannedKey };
  }

  // 2️⃣ Rate counting logic
  const countKey = `${baseKey2}:count:${ip}`;
  let count = await redisClient.get(countKey);
  count = count ? parseInt(count, 10) : 0;
  count += 1;

  // 3️⃣ If limit exceeded, mark IP as banned
  if (count > LIMIT) {
    const clientUser = (await redisClient.exists(clientKey))
      ? await redisClient.hGetAll(clientKey)
      : null;

    if (!clientUser) {
      console.log("No client found for userKey", clientKey);
      return { allowed: false };
    }

    if (clientUser.message == "User is banned due to spam") {
      return;
    }

    const expiresAt = Date.now() + BAN_DURATION_MS;

    // --- NEW GLOBAL TRACKING LOGIC ---

    // 1. Save the ban hash with metadata
    console.log("this is the banned clientKey", clientKey);
    // await redisClient.hSet(clientKey, {
    //   clientKey: clientKey
    // });

    await redisClient.hSet(bannedKey, {
      ip: ip,
      subdomain: subdomain,
      expiresAt: expiresAt.toString(),
    });

    // 2. Add this specific key to the global tracking set
    await redisClient.sAdd(activeBansGlobalSet, bannedKey);

    // 3. Save the socket ID into the shadow set
    await redisClient.sAdd(bannedClientsSet, clientKey);

    // 4. Set safety TTLs (24h)
    await redisClient.expire(bannedKey, 86400);
    await redisClient.expire(bannedClientsSet, 86400);

    // Reset current rate count
    await redisClient.del(countKey);

    // Update clientKey with IP for reference
    await redisClient.hSet(clientKey, {
      ip: ip,
      message: `User is banned due to spam`,
      last_message: `User is banned due to spam`,
      msgIndex: clientUser.msgIndex ? Number(clientUser.msgIndex) + 1 : 1,
    });

    // --- PUSH & SOCKET LOGIC ---
    if (clientUser.assigned_agent) {
      pushWeb(
        `User is banned due to spam`,
        `${clientUser.id}-website-${safe(clientUser.username)}-${clientUser.email}-${subdomain}`,
        subdomain,
        clientUser.username,
        "website",
        "client",
      );
    }

    let splitted = clientKey.split("-");
    const [id, website, username, email] = splitted;

    let objData = {
      uid: Date.now(),
      socketId: clientUser.socketID,
      username: `banned-Status`,
      email: email,
      name: username,
      useremail: email,
      channel: subdomain,
      time: Date.now(),
      allocated_room: clientUser.allocated_room,
      client_unread: clientUser.client_unread,
      agent_unread: clientUser.client_unread,
      assigned_agent: clientUser.assigned_agent || "",
      roomID: "",
      message: `User is banned due to spam`,
      last_message: "User is banned due to spam",
      messageType: website,
      id: id,
      status: clientUser.status,
      agentName: clientUser.Agent_Name || "",
      appId: clientUser.appId || "",
      pageId: clientUser.pageId || "",
      ip: ip,
      ban: true,
      type: "queue4",
      username1: "error-Status",
      msg: "User is banned due to spam.",
    };

    setTimeout(async () => {
      await redisClient.lPush(chatKey, [
        `User is banned due to spam`,
        `${Date.now()}`,
        `banned-Status`,
        `user`,
        `no-content`,
      ]);

      await saveMessageForSearch(
        `User is banned due to spam`,
        `${Date.now()}`,
        `${clientUser.username}`,
        `${clientUser.email}`,
        `user`, // Pass profile here
        `${Date.now()}`,
        `${clientUser.appId}`,
        `${clientUser.pageId}`,
        chatKey,
        `website`,
        `${clientKey}`,
        `User is banned due to spam`,
        `${Date.now()}`,
        `banned-Status`,
        `user`,
        `no-content`,
        subdomain,
      );

      socketSignal.emit(
        "chatParameters",
        "queue4",
        objData,
        `${subdomain}-all_messages`,
      );
      socketSignal.emit(
        "chatParameters",
        "queue4",
        objData,
        clientUser.socketID,
      );
    }, 1000);

    return { allowed: false, rateLimitingUserKey: ip };
  }

  // 4️⃣ Update count with short TTL
  await redisClient.setEx(countKey, WINDOW, count.toString());
  return { allowed: true, rateLimitingUserKey: ip };
}
async function mimicLogin(validateAssignedKey, fqdn, mysocket, agentName) {
  // Validation for data of agent
  const [
    validateExtension,
    validateType,
    validateUsername,
    validateEmail,
    validateFqdn,
  ] = validateAssignedKey.split("-");

  console.log("this is validated", validateAssignedKey);

  const agentExtensionBuilder = `${validateEmail}-${validateExtension}-${fqdn}`;

  if (validateEmail && validateExtension && fqdn) {
    const key = `${validateEmail}-${validateExtension}-${fqdn}`;

    const existing = await redisClient.hGet(key, "extensionData");
    console.log("this is type 11 key", key);
    if (existing !== null) {
      console.log("already existing", existing);
      await redisClient.hSet(key, {
        extensionData: validateAssignedKey,
      });
    } else {
      console.log("creating whole key");

      await redisClient.hSet(key, {
        extensionData: validateAssignedKey,
      });

      await redisClient.sAdd(`${fqdn}-extensiondatalist`, key);

      console.log("created and added to set");
    }
  } else {
    console.log("should no undefined data");
  }

  // Validation for login part

  if (validateType == "Agent") {
    if (validateExtension) {
      const agentProfileParam = `${validateExtension}:${fqdn}`;

      console.log("agentProfile param", agentProfileParam);

      console.log("this is agent profile", agentProfileParam);

      let agentProfile = await redisClient.get(agentProfileParam);
      if (!agentProfile) {
        // If Redis key doesn't exist, use default image
        agentProfile = `https://${fqdn}:8443/img/ringq-square.png`;
        // console.log("agentProfile")
      } else {
        agentProfile = `https://${fqdn}:8443/avr/${agentProfile}`;
        console.log("this is the profile picture of client", agentProfile);
      }

      // agentProfile = `${agentProfile}`;
      console.log("this is agent profile login", agentProfile);

      let key;
      let client_unread = "";
      let agent_unread = "";
      let userId = "";

      console.log("userKey", validateAssignedKey);
      const user = (await redisClient.exists(validateAssignedKey))
        ? await redisClient.hGetAll(validateAssignedKey)
        : null;
      if (user) {
        const prevSocket = user.socketID;

        unread_agent = user.agent_unread || 0;
        unread_client = user.client_unread || 0;
        user.socketID = mysocket || prevSocket;
        user.status = "online";
        user.lastSeen = new Date();

        userId = user.id;
        user.id = user.id;
        user.role = user.role;
        user.username = user.username;
        user.email = user.email;
        user.roomID = "all_messages";
        key = `${user.id}-${user.role}-${user.username}-${user.email}-${fqdn}`;
        await redisClient.hSet(validateAssignedKey, {
          socketID: String(user.socketID ?? mysocket),
          status: "online",
          lastSeen: "",
          extension: validateExtension,
          profile: `${agentProfile}`,
        });

        const agentUnreadCount = `unread-${validateAssignedKey}`;
        await redisClient.zAdd(`${fqdn}-totalAgentUnread`, {
          score: Date.now(),
          value: `${agentUnreadCount}`,
        });
      } else {
        if (
          validateExtension == null &&
          validateUsername == null &&
          validateEmail == null
        ) {
          const messagePayload = {
            message: `Please check your input credentials and try again`,
            status: "failed",
          };
          // socketSignal.emit(
          //   "chatParameters",
          //   "queueError",
          //   messagePayload,
          //   `${subdomain}-all_messages`,
          // );
          return;
        }
        key = `${validateExtension}-${validateType}-${validateUsername}-${validateEmail}-${fqdn}`;
        await redisClient.lPush(`${fqdn}-agent_data`, key);
        console.log("no data");
        const role = "Agent";
        console.log("no agent existed");
        unread_agent = 0;
        unread_client = 0;

        await redisClient.hSet(key, {
          id: validateExtension,
          username: validateUsername,
          email: validateEmail,
          Agent_Name: agentName,
          role: validateType,
          socketID: "",
          Dept: "Agent",
          // client_unread: 0,
          agent_unread: 0,
          roomID: "all_messages",
          status: "online",
          assigned: "",
          extension: validateExtension,
          profile: `${agentProfile}`,
        });
      }

      const overallUnreadCount = await redisClient.zCard(
        `${fqdn}-overallUnreadCount`,
      );

      const keys = await redisClient.zRange(`${fqdn}-totalAgentUnread`, 0, -1);

      const keyUnread = `unread-${validateAssignedKey}`;

      const result = await redisClient.zCard(`${keyUnread}`);

      let updateUnread = {
        agent_unread: overallUnreadCount,
        total: result,
      };

      console.log("this is the result", result);
      console.log("this is my socket", mysocket);

      const savedsocket = mysocket;
      if (savedsocket) {
        await redisClient.hSet(savedsocket, {
          userKey: key,
          socketId: mysocket,
        });
      }
    }
  }
}

// async function noAssignedChecker(key, subdomain) {
//   let assignedList = [];
//   const keyGet = await redisClient.hGetAll(`${key}`);
//   console.log("11");
//   if (keyGet.assigned && keyGet.assigned !== "[]" && keyGet.assigned !== "") {
//     console.log("12");
//     try {
//       const parsed = JSON.parse(keyGet.assigned);
//       assignedList = Array.isArray(parsed) ? parsed : [];
//     } catch {
//       assignedList = [];
//     }
//   }

//   // FIX: In JS, [] == [] is always false. Use .length instead.
//   if (assignedList.length === 0) {
//     // 1. Check the waiting queue FIRST
//     const nextWaitingClient = await redisClient.lPop(
//       `${subdomain}-waitingQueue`,
//     );

//     if (nextWaitingClient) {
//       console.log(
//         `🎯 Found a waiting client (${nextWaitingClient}). Assigning to agent: ${key}`,
//       );

//       // 2. Trigger your assignment function (Perform the actual link between agent and client)
//       // Note: You'll need to pass the logic that updates Redis for the specific chat type
//       await performQueueAssignment(subdomain, key, nextWaitingClient);
//     } else {
//       // 3. If NO ONE is waiting, then put the agent in the Idle Round Robin pool
//       await redisClient.zAdd(`${subdomain}-noAssigned`, {
//         score: Date.now(),
//         value: key,
//       });
//       console.log("Agent added to Round Robin pool (no one waiting):", key);
//     }
//   } else {
//     console.log("assigned then return");
//     // still has assigned so just return
//     return;
//   }
// }

// used for agent round robin
async function performQueueAssignment(subdomain, agentKey, clientKey) {
  console.log("performmmm");
  const agentUser = (await redisClient.exists(agentKey))
    ? await redisClient.hGetAll(agentKey)
    : null;

  const clientUser = (await redisClient.exists(clientKey))
    ? await redisClient.hGetAll(clientKey)
    : null;

  let assignedClientList = [];
  let assignedAgentList = [];

  if (agentUser.assigned) {
    try {
      const parsed = JSON.parse(agentUser.assigned);
      assignedAgentList = Array.isArray(parsed) ? parsed : [];
    } catch {
      assignedAgentList = [];
    }
  }
  if (!assignedAgentList.includes(clientKey)) {
    assignedAgentList.unshift(clientKey);
  }

  await redisClient.hSet(agentKey, {
    assigned: JSON.stringify(assignedAgentList),
  });

  if (clientUser.assigned) {
    try {
      const parsed = JSON.parse(clientUser.assigned);
      assignedClientList = Array.isArray(parsed) ? parsed : [];
    } catch {
      assignedClientList = [];
    }
  }

  if (!assignedAgentList.includes(agentKey)) {
    assignedAgentList.unshift(agentKey);
  }

  await redisClient.hSet(clientKey, {
    assigned_agent: JSON.stringify(assignedClientList),
  });

  const agentUnreadKey = `unread-${agentKey}`;
  const clientUnreadKey = `unread-${clientKey}`;
  await redisClient.zAdd(`${agentUnreadKey}`, {
    score: Date.now(),
    value: `${clientUnreadKey}`,
  });

  const overallUnreadCount = await redisClient.zCard(
    `${subdomain}-overallUnreadCount`,
  );
  const result = await redisClient.zCard(`${agentUnreadKey}`);

  let updateUnread1 = {
    agent_unread: overallUnreadCount,
    total: result,
    extension: agentUser.extension,
  };

  socketSignal.emit(
    "chatParameters",
    "queue_updatedata",
    updateUnread1,
    `${subdomain}-all_messages`,
  );

  pushWeb(
    `Assigned to ${agentUser.extension}`,
    clientUser.id +
      `-${clientUser.messageType}-` +
      safe(clientUser.username) +
      "-" +
      clientUser.email +
      "-" +
      subdomain,
    subdomain,
    clientUser.username,
    clientUser.messageType,
    "client",
  );
}

async function conversationLogsAdd(type, conversationLogsData, subdomain) {
  const conversationLogs = `${subdomain}-logs`;

  await redisClient.lPush(conversationLogs, [
    `${conversationLogsData.conversationsFetch}`,
    `${conversationLogsData.messageType}`,
    `${conversationLogsData.to}`,
    `${conversationLogsData.email}`,
    `${conversationLogsData.username}`,
    `${conversationLogsData.date}`,
  ]);

  console.log("pushCOnversation good?", conversationLogs, type);

  return "ok added";
}

async function conversationLogsAddExtension(
  type,
  conversationLogsData,
  chatHistoryKeyExtension,
) {
  const conversationLogsExtension = `${chatHistoryKeyExtension}-logs`;

  await redisClient.lPush(conversationLogsExtension, [
    `${conversationLogsData.message}`,
    `${conversationLogsData.to}`,
    `${conversationLogsData.email}`,
    `${conversationLogsData.username}`,
    `${conversationLogsData.date}`,
  ]);

  return "ok added";
}

async function updatedPushWeb(tokenKey, tokenValue, subdomain) {
  console.log("Updating token for:", tokenKey);

  const tokenData = {
    tokenKey: tokenKey,
    token: "",
  };

  try {
    if (tokenValue !== "" && tokenValue !== null) {
      // 1. I-save muna ang bagong token sa Redis
      // await redisClient.set(tokenKey, tokenValue);

      // 2. I-update ang tokenData para sa socket emit
      tokenData.token = tokenValue;

      console.log("✅ Token updated and saved.");
      socketSignal.emit(
        "chatParameters",
        "tokenUpdate",
        tokenData,
        `${subdomain}-all_messages`,
      );
    } else {
      // 3. Kapag empty ang tokenValue, burahin o i-empty ang nasa Redis
      await redisClient.set(tokenKey, "");
      tokenData.token = "";

      console.log(" Token cleared.");
      socketSignal.emit(
        "chatParameters",
        "tokenUpdate",
        tokenData,
        `${subdomain}-all_messages`,
      );
    }
  } catch (err) {
    console.error(" Error in updatedPushWeb:", err.message);
  }
}


async function pushWeb(payload, key, subdomain, clientName, platform, type) {
  try {
    const clientKey = String(key || "").trim();
    if (!clientKey) {
      console.log("[pushWeb] skipped: missing client key");
      return;
    }

    const clientData = await getRedisHash(clientKey);
    if (!clientData) {
      console.log("[pushWeb] skipped: client not found", clientKey);
      return;
    }

    delete clientData.username;
    const preview = trimPushPreview(payload);
    const context = {
      payload: preview,
      clientData,
      clientName,
      platform,
      subdomain,
    };

    if (shouldPushToAssignedAgent(type, clientData)) {
      await pushToAssignedAgent(context);
      return;
    }

    const ownerManagerSetting = await redisClient.get(
      `chatsettings:${subdomain}-enableOwnerManagerAssign`,
    );
    await pushToMatchingQueueAgents({
      ...context,
      requireNotificationPermission: ownerManagerSetting !== "0",
    });
  } catch (error) {
    console.error("[pushWeb] failed:", error);
  }
}

// Agent-to-agent notifications are intentionally separate from pushWeb.
// pushWeb derives recipients from customer/queue assignments; this function
// targets only the members stored on the selected Team Chat group.
async function pushAgentConversationWeb({
  subdomain,
  groupKey,
  group,
  memberIds,
  senderAliases,
  message,
}) {
  const logPrefix = "[pushWeb][agentConversation]";
  try {
    const normalizedMembers = [...new Set(
      (Array.isArray(memberIds) ? memberIds : [])
        .map((id) => String(id || "").trim())
        .filter(Boolean),
    )];
    const senderIds = new Set(
      (Array.isArray(senderAliases) ? senderAliases : [])
        .map((id) => String(id || "").trim())
        .filter(Boolean),
    );
    const recipients = normalizedMembers.filter((id) => !senderIds.has(id));

    console.log(`${logPrefix} recipient resolution`, {
      subdomain,
      groupKey,
      groupId: message?.groupId,
      messageId: message?.id,
      storedMembers: normalizedMembers,
      senderAliases: [...senderIds],
      recipients,
    });

    if (recipients.length === 0) {
      console.log(`${logPrefix} skipped: no other group members`, {
        groupKey,
        messageId: message?.id,
      });
      return;
    }

    const webTokenKeys = recipients.map(
      (extension) => `${extension}@@@${subdomain}`,
    );
    const androidTokenKeys = recipients.map(
      (extension) => `android:${extension}:${subdomain}`,
    );
    const iosTokenKeys = recipients.map(
      (extension) => `ios:${extension}:${subdomain}`,
    );
    const [webTokenValues, androidTokenValues, iosTokenValues] = await Promise.all([
      redisClient.mGet(webTokenKeys),
      redisClient.mGet(androidTokenKeys),
      redisClient.mGet(iosTokenKeys),
    ]);
    const webTokenEntries = [];
    const androidTokenEntries = [];
    const iosTokenEntries = [];
    recipients.forEach((extension, index) => {
      const webToken = String(webTokenValues[index] || "").trim();
      const androidToken = String(androidTokenValues[index] || "").trim();
      if (webToken) {
        webTokenEntries.push({
          extension,
          token: webToken,
          tokenKey: webTokenKeys[index],
        });
      }
      if (androidToken) {
        androidTokenEntries.push({
          extension,
          token: androidToken,
          tokenKey: androidTokenKeys[index],
        });
      }
      const iosToken = String(iosTokenValues[index] || "").trim();
      if (iosToken) {
        iosTokenEntries.push({ extension, token: iosToken, tokenKey: iosTokenKeys[index] });
      }
      if (!webToken && !androidToken && !iosToken) {
        console.log(`${logPrefix} member skipped: no push token`, {
          extension,
          webTokenKey: webTokenKeys[index],
          androidTokenKey: androidTokenKeys[index],
          iosTokenKey: iosTokenKeys[index],
          groupKey,
        });
      }
    });

    if (webTokenEntries.length === 0 && androidTokenEntries.length === 0 && iosTokenEntries.length === 0) {
      console.log(`${logPrefix} skipped: members have no push tokens`, {
        groupKey,
        recipients,
      });
      return;
    }

    const attachmentName = Array.isArray(message?.attachments) &&
        message.attachments.length > 0
      ? String(message.attachments[0]?.name || "Attachment")
      : "";
    const preview = trimPushPreview(
      String(message?.message || attachmentName || "New Team Chat message"),
    );
    //ryan 0907 - server uses the saved API setting only to select the Android channel.
    const chatSound = await getAndroidChatNotificationConfig(subdomain);
    // FCM data values must be strings. `agentConversation` is the explicit
    // discriminator index.html can use without colliding with client chats.
    const data = {
      agentConversation: "true",
      conversationType: "agent-to-agent",
      platform: "agentConversation",
      payload: String(preview || ""),
      username: String(message?.senderName || "Agent"),
      //ryan 0907 - required by main.dart's data-only background handler.
      title: String(message?.senderName || "Agent"),
      body: String(preview || "New Team Chat message"),
      //ryan 0907 - tells the mobile app the real-time Redis sound selection.
      defaultChatSound: chatSound.soundIndex,
      senderExtension: String(message?.senderExtension || ""),
      groupId: String(message?.groupId || group?.id || ""),
      groupName: String(group?.name || "Team Chat"),
      groupKey: String(groupKey || ""),
      queue: String(message?.queue || group?.queue || ""),
      messageId: String(message?.id || ""),
      timestamp: String(message?.timestamp || Date.now()),
      domain: `${subdomain}/cloudapp`,
    };

    console.log(`${logPrefix} sending`, {
      groupKey,
      messageId: data.messageId,
      webRecipientExtensions: webTokenEntries.map((entry) => entry.extension),
      androidRecipientExtensions: androidTokenEntries.map(
        (entry) => entry.extension,
      ),
      webTokenCount: webTokenEntries.length,
      androidTokenCount: androidTokenEntries.length,
      iosTokenCount: iosTokenEntries.length,
      iosRecipientExtensions: iosTokenEntries.map((entry) => entry.extension),
      data,
    });

    const pushBatches = [
      {
        platform: "web",
        entries: webTokenEntries,
        message: {
          tokens: webTokenEntries.map((entry) => entry.token),
          data,
        },
      },
      {
        platform: "android",
        entries: androidTokenEntries,
        message: {
          tokens: androidTokenEntries.map((entry) => entry.token),
          data,
          //ryan 0907 - OS-rendered notification survives app termination.
          notification: {
            title: String(message?.senderName || "Agent"),
            body: String(preview || "New Team Chat message"),
          },
          android: {
            ...chatSound.androidConfig,
            notification: {
              ...chatSound.androidConfig?.notification,
              icon: "ic_notification",
              
            },
          },
        },
      },
      {
        platform: "ios",
        entries: iosTokenEntries,
        message: {
          tokens: iosTokenEntries.map((entry) => entry.token),
          data,
          notification: {
            title: String(message?.senderName || "Agent"),
            body: String(preview || "New Team Chat message"),
          },
          apns: getIosChatNotificationConfig(),
        },
      },
    ].filter((batch) => batch.entries.length > 0);
    console.log(`this is the url of the image https://${subdomain}/cloudapp/icons/Icon-192.png`);

    for (const batch of pushBatches) {
      const response = await admin.messaging().sendEachForMulticast(
        batch.message,
      );

      console.log(`${logPrefix} ${batch.platform} completed`, {
        groupKey,
        messageId: data.messageId,
        successCount: response.successCount,
        failureCount: response.failureCount,
      });

      for (let index = 0; index < response.responses.length; index++) {
        const result = response.responses[index];
        const owner = batch.entries[index];
        if (result.success) {
          console.log(`${logPrefix} ${batch.platform} member sent`, {
            extension: owner.extension,
            messageId: data.messageId,
            firebaseMessageId: result.messageId,
          });
          continue;
        }

        const errorCode = result.error?.code || "unknown";
        console.error(`${logPrefix} ${batch.platform} member failed`, {
          extension: owner.extension,
          messageId: data.messageId,
          errorCode,
          errorMessage: result.error?.message,
        });
        if (
          errorCode === "messaging/registration-token-not-registered" ||
          errorCode === "messaging/invalid-registration-token"
        ) {
          await redisClient.del(owner.tokenKey);
          console.log(`${logPrefix} invalid token removed`, {
            extension: owner.extension,
            tokenKey: owner.tokenKey,
          });
        }
      }
    }
  } catch (error) {
    // Push failure must never roll back a message already saved/broadcast.
    console.error(`${logPrefix} failed`, {
      groupKey,
      messageId: message?.id,
      error: error.message,
      stack: error.stack,
    });
  }
}

function trimPushPreview(payload) {
  if (typeof payload !== "string") return payload;
  return payload.length > 27 ? `${payload.slice(0, 27)}...` : payload;
}

function shouldPushToAssignedAgent(type, clientData) {
  if (type === "agent") return true;
  if (type !== "client") return false;
  return safeParseJSON(clientData.assigned_agent).length > 0;
}

function getPushPlatformKey(platform) {
  const platformKeys = {
    website: "webchat",
    whatsapp: "whatsapp",
    facebook: "facebook",
    twilio: "twilio",
    telnyx: "telnyx",
    telegram: "telgram",
    line: "line",
  };
  return platformKeys[platform];
}

function buildAgentPushData({
  payload,
  clientData,
  clientName,
  platform,
  subdomain,
}) {
  if (payload && typeof payload === "object") {
    //ryan 0907 - FCM data values used by the background handler must be strings.
    return {
      ...payload,
      ...clientData,
      title: String(payload.title ?? clientName ?? ""),
      body: String(
        payload.body ?? payload.message ?? payload.payload ?? "New message",
      ),
    };
  }

  return {
    payload: String(payload ?? ""),
    username: String(clientName ?? ""),
    //ryan 0907 - main.dart displays these fields in the background notification.
    title: String(clientName ?? ""),
    body: String(payload ?? ""),
    domain: `${subdomain}/cloudapp`,
    platform: String(platform ?? ""),
    ...clientData,
  };
}

//ryan 0907 - /api/index/1 reads this same Redis setting. Android needs the
// channel in the FCM payload because main.dart cannot run before a terminated
// app's notification is displayed.
async function getAndroidChatNotificationConfig(subdomain) {
  //ryan 0907 - read Redis for every push so backend setting changes apply
  // immediately even if the mobile app has not refreshed /api/index/1.
  let configuredValue = "0";
  try {
    configuredValue =
      (await redisClient.get(
        `chatsettings:${subdomain}-defaultChatSound`,
      )) ?? "0";
  } catch (error) {
    //ryan 0907 - a Redis failure must not prevent notification delivery.
    console.error(
      `[pushWeb] defaultChatSound lookup failed for ${subdomain}; using 0:`,
      error,
    );
    configuredValue = "0";
  }

  const parsedValue = Number.parseInt(configuredValue ?? "0", 10);
  //ryan 0907 - null, non-numeric and out-of-range values also use sound 0.
  const soundIndex =
    Number.isInteger(parsedValue) && parsedValue >= 0 && parsedValue <= 3
      ? parsedValue
      : 0;
  const soundNumber = soundIndex + 1;
  return {
    //ryan 0907 - string value is safe for the FCM data payload.
    soundIndex: String(soundIndex),
    androidConfig: {
      priority: "high",
      notification: {
        channelId: `chat_sound_${soundNumber}_soft_v1`,
        sound: `chat${soundNumber}_soft`,
        defaultVibrateTimings: true,
      },
    },
  };
}

// Use the system sound until custom sound files are supplied by the iOS app.
function getIosChatNotificationConfig() {
  return {
    headers: { "apns-priority": "10", "apns-push-type": "alert" },
    payload: { aps: { sound: "default" } },
  };
}

async function pushToAssignedAgent(context) {
  const { clientData, subdomain, clientName, payload } = context;
  const [agentKey] = safeParseJSON(clientData.assigned_agent);
  if (!agentKey) {
    console.log("[pushWeb] direct push skipped: no assigned agent");
    return;
  }

  const agent = await getRedisHash(agentKey);
  if (!agent?.extension) {
    console.log("[pushWeb] direct push skipped: agent not found", agentKey);
    return;
  }

  const [matchedQueueIds, webToken, androidToken, iosToken] = await Promise.all([
    findMatchedPushQueues(subdomain, context.platform, clientData),
    redisClient.get(`${agent.extension}@@@${subdomain}`),
    redisClient.get(`android:${agent.extension}:${subdomain}`),
    redisClient.get(`ios:${agent.extension}:${subdomain}`),
  ]);
  const data = buildAgentPushData(context);
  //ryan 0907 - select the channel from the same server setting used by /api/index/1.
  const chatSound = await getAndroidChatNotificationConfig(subdomain);
  //ryan 0907 - send the real-time Redis selection to main.dart.
  data.defaultChatSound = chatSound.soundIndex;
  if (matchedQueueIds.length) {
    data.matchedQueueIds = matchedQueueIds.join(",");
  }

  await Promise.all([
    sendAgentPush({
      tokens: webToken ? [webToken] : [],
      data,
      label: "assigned agent web",
      subdomain,
    }),
    sendAgentPush({
      tokens: androidToken ? [androidToken] : [],
      data,
      notification: {
        title: String(clientName ?? ""),
        body: String(payload ?? ""),
      },
      //ryan 0907 - Android renders this even after the app is swiped away.
      androidNotification: chatSound.androidConfig,
      label: "assigned agent Android",
      subdomain,
    }),
    sendAgentPush({
      tokens: iosToken ? [iosToken] : [],
      data,
      notification: {
        title: String(clientName ?? ""),
        body: String(payload ?? ""),
      },
      apns: getIosChatNotificationConfig(),
      label: "assigned agent iOS",
      subdomain,
    }),
  ]);

  emitAgentPushSocket(subdomain, data, matchedQueueIds);
}

async function pushToMatchingQueueAgents({
  requireNotificationPermission,
  ...context
}) {
  const { subdomain, clientData, clientName, payload } = context;
  const queueResult = await resolvePushQueueRecipients({
    subdomain,
    platform: context.platform,
    clientData,
    requireNotificationPermission,
  });
  if (!queueResult) return;

  const { extensions, matchedQueueIds } = queueResult;
  const data = buildAgentPushData(context);
  //ryan 0907 - select the channel from the same server setting used by /api/index/1.
  const chatSound = await getAndroidChatNotificationConfig(subdomain);
  //ryan 0907 - send the real-time Redis selection to main.dart.
  data.defaultChatSound = chatSound.soundIndex;
  if (matchedQueueIds.length) {
    data.matchedQueueIds = matchedQueueIds.join(",");
  }

  const tokenResult = await getAgentPushTokens(extensions, subdomain);
  await Promise.all([
    sendAgentPush({
      tokens: tokenResult.webTokens,
      data,
      label: "queue web",
      tokenOwners: tokenResult.webTokenOwners,
      subdomain,
    }),
    sendAgentPush({
      tokens: tokenResult.androidTokens,
      data,
      notification: {
        title: String(clientName ?? ""),
        body: String(payload ?? ""),
      },
      //ryan 0907 - Android renders this even after the app is swiped away.
      androidNotification: chatSound.androidConfig,
      label: "queue Android",
      subdomain,
    }),
    sendAgentPush({
      tokens: tokenResult.iosTokens,
      data,
      notification: {
        title: String(clientName ?? ""),
        body: String(payload ?? ""),
      },
      apns: getIosChatNotificationConfig(),
      label: "queue iOS",
      subdomain,
    }),
  ]);

  emitAgentPushSocket(subdomain, data, matchedQueueIds);
}

async function findMatchedPushQueues(subdomain, platform, clientData) {
  const result = await resolvePushQueueRecipients({
    subdomain,
    platform,
    clientData,
    requireNotificationPermission: false,
  });
  return result?.matchedQueueIds || [];
}

async function resolvePushQueueRecipients({
  subdomain,
  platform,
  clientData,
  requireNotificationPermission,
}) {
  const platformKey = getPushPlatformKey(platform);
  if (!platformKey) {
    console.log(`[pushWeb] skipped: unknown platform ${platform}`);
    return null;
  }

  const rawAssignments = await redisClient.get(
    `queueAssigmentsAgents-${subdomain}`,
  );
  if (!rawAssignments) {
    console.log("[pushWeb] skipped: no queue assignments");
    return null;
  }

  let queueAssignments;
  try {
    queueAssignments = JSON.parse(rawAssignments);
  } catch {
    console.log("[pushWeb] skipped: invalid queue-assignment JSON");
    return null;
  }
  if (!Array.isArray(queueAssignments)) return null;

  const matchedQueueIds = [];
  const extensions = new Set();

  for (const queue of queueAssignments) {
    const entry = Object.entries(queue || {})[0];
    if (!entry) continue;
    const [queueId, queueExtensions] = entry;
    if (!Array.isArray(queueExtensions)) continue;

    const platformMembers = await redisClient.lRange(
      `${queueId}@${subdomain}-${platformKey}-chat`,
      0,
      -1,
    );
    const channelId =
      platformKey === "whatsapp" || platformKey === "facebook"
        ? clientData.appId
        : clientData.pageId;
    if (!platformMembers.includes(String(channelId))) continue;

    matchedQueueIds.push(queueId);
    if (!requireNotificationPermission) {
      queueExtensions.forEach((extension) =>
        extensions.add(String(extension)),
      );
      continue;
    }

    const permissionKeys = queueExtensions.map(
      (extension) => `${extension}-${subdomain}-permission`,
    );
    const permissions = await redisClient.mGet(permissionKeys);
    queueExtensions.forEach((extension, index) => {
      if (permissions[index] === "7" || permissions[index] === "8") {
        extensions.add(String(extension));
      }
    });
  }

  return {
    extensions: [...extensions],
    matchedQueueIds,
  };
}

async function getAgentPushTokens(extensions, subdomain) {
  if (extensions.length === 0) {
    return {
      webTokens: [],
      androidTokens: [],
      iosTokens: [],
      webTokenOwners: {},
    };
  }

  const webKeys = extensions.map((extension) =>
    `${extension}@@@${subdomain}`,
  );
  const androidKeys = extensions.map((extension) =>
    `android:${extension}:${subdomain}`,
  );
  const iosKeys = extensions.map((extension) => `ios:${extension}:${subdomain}`);
  const [webValues, androidValues, iosValues] = await Promise.all([
    redisClient.mGet(webKeys),
    redisClient.mGet(androidKeys),
    redisClient.mGet(iosKeys),
  ]);

  const webTokens = [];
  const androidTokens = [];
  const iosTokens = [];
  const webTokenOwners = {};
  extensions.forEach((extension, index) => {
    if (webValues[index]) {
      webTokens.push(webValues[index]);
      webTokenOwners[webValues[index]] = extension;
    }
    if (androidValues[index]) androidTokens.push(androidValues[index]);
    if (iosValues[index]) iosTokens.push(iosValues[index]);
  });

  return { webTokens, androidTokens, iosTokens, webTokenOwners };
}

async function sendAgentPush({
  tokens,
  data,
  notification,
  //ryan 0907 - contains the server-selected channel for terminated delivery.
  androidNotification,
  apns,
  label,
  tokenOwners,
  subdomain,
}) {
  if (tokens.length === 0) {
    console.log(`[pushWeb] ${label}: no tokens`);
    return;
  }

  try {
    const response = await admin.messaging().sendEachForMulticast({
      data,
      tokens,
      ...(notification && { notification }),
      ...(apns && { apns }),
      ...(!apns && { android: {
        ...androidNotification,
        notification: {
          ...androidNotification?.notification,
          icon: "ic_notification",
        },
      } }),
    });
    console.log(
      `[pushWeb] ${label}: ${response.successCount} sent, ${response.failureCount} failed`,
    );
    console.log(`this is the url of the image https://${subdomain}/cloudapp/icons/Icon-192.png`);

    if (!tokenOwners || !subdomain) return;
    for (let index = 0; index < response.responses.length; index++) {
      const errorCode = response.responses[index].error?.code;
      if (
        errorCode === "messaging/registration-token-not-registered" ||
        errorCode === "messaging/invalid-registration-token"
      ) {
        const extension = tokenOwners[tokens[index]];
        if (extension) await redisClient.del(`${extension}@@@${subdomain}`);
      }
    }
  } catch (error) {
    console.error(`[pushWeb] ${label} failed:`, error);
  }
}

function emitAgentPushSocket(subdomain, data, matchedQueueIds) {
  socketSignal.emit(
    "chatParameters",
    `${subdomain}notification`,
    {
      ...data,
      matchedQueueIds: matchedQueueIds.join(","),
    },
    `${subdomain}-all_messages`,
  );
}

// Sends a Firebase (FCM) push notification straight to the CLIENT's own device.
// Unlike pushWeb (which targets agents' tokens), this reads the client's device
// token from the client hash field `pushtoken` (saved via hSet on the clientKey).
async function clientPushWeb(payload, key, subdomain, senderName, platform) {
  console.log(
    "clientPushWeb arg",
    payload,
    key,
    subdomain,
    senderName,
    platform,
  );

  const redisData = await redisClient.hGetAll(key.trim());

  // The client's FCM device token lives on the client hash under `pushtoken`.
  const clientToken = redisData.pushtoken;
  if (!clientToken) {
    console.log(`No client push token found for key: ${key}`);
    return;
  }

  // Don't leak the raw token/username into the data payload.
  delete redisData.username;
  delete redisData.pushtoken;

  // Trim the preview to 27 chars, matching pushWeb's behaviour.
  if (typeof payload === "string" && payload.length > 27) {
    payload = payload.slice(0, 27) + "...";
  }

  //ryan 0907 - select the channel from the same server setting used by /api/index/1.
  const chatSound = await getAndroidChatNotificationConfig(subdomain);

  const message = {
    token: clientToken,
    apns: getIosChatNotificationConfig(),
    //ryan 0907 - normal notification payload works when the app is terminated.
    notification: {
      title: String(senderName || subdomain),
      body: String(payload),
    },
    android: {
      ...chatSound.androidConfig,
      notification: {
        ...chatSound.androidConfig?.notification,
        icon: "ic_notification",
      
      },
    },
    data:
      typeof payload === "object" && payload !== null
        ? {
            ...payload,
            ...redisData,
            //ryan 0907 - required by main.dart's background handler.
            title: String(senderName || subdomain),
            body: String(payload.message || payload.body || "New message"),
            //ryan 0907 - real-time Redis selection for mobile foreground playback.
            defaultChatSound: chatSound.soundIndex,
          }
        : {
            payload: String(payload),
            username: String(senderName),
            //ryan 0907 - required by main.dart's background handler.
            title: String(senderName || subdomain),
            body: String(payload),
            domain: `${subdomain}/cloudapp`,
            platform: String(platform || "website"),
            ...redisData,
            //ryan 0907 - keep real-time Redis value authoritative over stored client data.
            defaultChatSound: chatSound.soundIndex,
          },
  };

  try {
    const response = await admin.messaging().send(message);
    console.log(`Client push notification sent successfully: ${response}`);
  } catch (error) {
    console.error("Error sending client push notification:", error);
    // Drop dead tokens so we stop retrying them.
    if (
      error?.code === "messaging/registration-token-not-registered" ||
      error?.code === "messaging/invalid-registration-token"
    ) {
      await redisClient.hDel(key.trim(), "pushtoken");
    }
  }
}

async function chatSettingsChecker(arg, subdomain, mysocket) {
  let enableOwner =
    (await redisClient.get(
      `chatsettings:${subdomain}-enableOwnerManagerAssign`,
    )) || "0";
  let enableRoundRobin =
    (await redisClient.get(`chatsettings:${subdomain}-enableRoundRobin`)) ||
    "0";
  let enableEveryAgentQ =
    (await redisClient.get(`chatsettings:${subdomain}-enableEveryAgentQ`)) ||
    "0";

  console.log(
    "this is enable 11 owner from redis",
    enableOwner,
    arg.enableOwnerManagerAssign,
  );
  console.log(
    "this is enable every agents from redis",
    enableEveryAgentQ,
    arg.enableEveryAgentQ,
  );
  // Helper to send the error and return the "failure" signal
  const fail = (msg) => {
    arg.enableOwnerManagerAssign = enableOwner;
    arg.enableEveryAgentQ = enableEveryAgentQ;
    arg.enableRoundRobin = enableRoundRobin;
    arg.msg =
      msg ||
      "Configuration mismatch detected. We've updated your chat settings — please try again.";

    // socketSignal.emit("chatParameters", "queueError", arg, mysocket);
    return 0;
  };

  // 2. Validate Sync (Check both key flags)
  if (
    arg.enableOwnerManagerAssign !== (enableOwner === "1") ||
    arg.enableEveryAgentQ !== (enableEveryAgentQ === "1")
  ) {
    return fail();
  }

  // 3. Rule 1: Owner vs EveryAgentQ
  if (enableOwner === "1" && enableEveryAgentQ === "1") {
    enableEveryAgentQ = "0"; // Correcting for the payload
    return fail();
  }

  // 4. Rule 2: The Round Robin dependency
  if (
    enableOwner === "0" &&
    enableEveryAgentQ === "0" &&
    enableRoundRobin === "1"
  ) {
    enableRoundRobin = "0"; // Correcting for the payload
    return fail();
  }

  if (enableOwner === "0" && arg.enableOwnerManagerAssign == true) {
    return fail();
  }

  if (enableOwner === "1" && arg.enableOwnerManagerAssign == false) {
    return fail();
  }
  return 1; // SUCCESS
}
// For whatsapp and Facebook below ---->>>>

async function globalSearch(queryTerm, subdomain, page = 1, targetId = null) {
  const pageSize = 20;
  const offset = (page - 1) * pageSize;
  const cleanTerm = queryTerm.trim();

  if (!cleanTerm)
    return {
      allMessages: [],
      allUsernames: [],
      allEmails: [],
      totalResults: 0,
    };

  const tokens = cleanTerm
    .replace(/[^a-zA-Z0-9_]/g, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  const baseQuery = tokens.length
    ? `(${tokens.map((token) => `${token}*`).join(" ")})`
    : "*";

  const queryMessage = `@message:${baseQuery}`;
  const queryUsername = `@referenceName:${baseQuery}`;
  const queryEmail = `@email:${baseQuery}`;

  try {
    const [resMsg, resEmail, resUser] = await Promise.all([
      redisClient.ft.search(`${subdomain}-database`, queryMessage, {
        SORTBY: { BY: "timestamp", DIRECTION: "DESC" },
        LIMIT: { from: 0, size: 100 },
        DIALECT: 2,
      }),
      redisClient.ft.search(`${subdomain}-profiles`, queryEmail, {
        LIMIT: { from: 0, size: 20 },
        DIALECT: 2,
      }),
      redisClient.ft.search(`${subdomain}-profiles`, queryUsername, {
        LIMIT: { from: 0, size: 20 },
        DIALECT: 2,
      }),
    ]);

    const allMessages = await Promise.all(
      resMsg.documents.map(async (doc) => {
        const actualData = doc.value;
        const fullHashData = await redisClient.hGetAll(actualData.clientKey);
        return {
          ...actualData,
          userData: {
            ...fullHashData,
          },
        };
      }),
    );

    const allUsernames = await Promise.all(
      resUser.documents.map(async (doc) => {
        const actualData = doc.value;
        const fullHashData = await redisClient.hGetAll(actualData.clientKey);
        return {
          ...actualData,
          msgKey: actualData.profileKey,
          userData: {
            ...fullHashData,
          },
        };
      }),
    );

    return {
      allMessages: allMessages,
      allUsernames: allUsernames,
    };
  } catch (err) {
    console.error("Global Search Error:", err);
    return { allMessages: [], allUsernames: [], totalResults: 0 };
  }
}

async function globalSearchFiltered(
  queryTerm,
  queryClientKey,
  subdomain,
  page = 1,
) {
  console.log("globalserts");
  const pageSize = 20;
  const offset = (page - 1) * pageSize;
  const cleanTerm = queryTerm.trim();
  // Escape ALL special characters for the TAG field ({})
  const escapedClientKey = queryClientKey.replace(/([^a-zA-Z0-9])/g, "\\$1");

  // const tokens = cleanTerm.replace(/[^a-zA-Z0-9]/g, " ").trim().split(/\s+/);
  const tokens = cleanTerm
    .replace(/[^a-zA-Z0-9_]/g, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  // Logic: Kung walang search term, wag mag-filter sa @message
  let combinedQuery = `@clientKey:{${escapedClientKey}}`;

  if (tokens.length > 0 && tokens[0] !== "") {
    combinedQuery += ` @message:(${tokens.map((token) => `${token}*`).join(" ")})`;
  }
  console.log("combinedquery", combinedQuery);

  try {
    const resMsg = await redisClient.ft.search(
      `${subdomain}-database`,
      combinedQuery,
      {
        SORTBY: { BY: "timestamp", DIRECTION: "DESC" },
        LIMIT: { from: offset, size: pageSize },
        DIALECT: 2,
      },
    );

    const allMessages = await Promise.all(
      resMsg.documents.map(async (doc) => {
        const actualData = doc.value;
        // Kunin ang full profile data mula sa clientKey
        const fullHashData = await redisClient.hGetAll(actualData.clientKey);

        return {
          ...actualData,
          msgKey: doc.id,
          userData: {
            ...fullHashData,
          },
        };
      }),
    );
    console.log("returninggg", allMessages, resMsg.total, page);
    return {
      allMessages: allMessages,
      totalResults: resMsg.total,
      currentPage: page,
    };
  } catch (err) {
    console.error("Filtered Global Search Error:", err);
    return { allMessages: [], totalResults: 0 };
  }
}

async function createSearchIndex(subdomain) {
  // 🔥 Early return if RediSearch is unavailable
  try {
    await redisClient.sendCommand(["FT._LIST"]);
  } catch (err) {
    console.log("❌ RediSearch module is not installed or unavailable.");
    return;
  }

  const indexName = `${subdomain}-database`;
  const versionKey = `${indexName}:schema-version`;

  try {
    const indexes = await redisClient.sendCommand(["FT._LIST"]);
    const indexExists = indexes.includes(indexName);
    const currentVersion = await redisClient.get(versionKey);

    // Rebuild if the schema version changed
    if (indexExists && currentVersion !== SEARCH_SCHEMA_VERSION) {
      console.log(`♻️ Search schema changed. Rebuilding: ${indexName}`);

      // No "DD", so the existing HASH data is preserved
      await redisClient.sendCommand(["FT.DROPINDEX", indexName]);
    }

    if (!indexExists || currentVersion !== SEARCH_SCHEMA_VERSION) {
      await redisClient.ft.create(
        indexName,
        {
          message: { type: "TEXT" },
          timestamp: { type: "NUMERIC", SORTABLE: true },
          username: { type: "TEXT", SORTABLE: true },
          email: { type: "TEXT", SORTABLE: true },
          profile: { type: "TAG" },
          uid: { type: "TAG" },
          roomID: { type: "TAG" },
          appId: { type: "TAG" },
          pageId: { type: "TAG" },
          messageType: { type: "TAG" },
          clientKey: { type: "TAG" },
          msgKey: { type: "TAG" },

          // Exact-match searchable field
          accessToken: { type: "TAG" },

          Data1: { type: "TEXT" },
          Data2: { type: "TEXT" },
          Data3: { type: "TEXT" },
          Data4: { type: "TEXT" },
          Data5: { type: "TEXT" },
        },
        {
          ON: "HASH",
          PREFIX: `${subdomain}:msg:`,
          STOPWORDS: [],
        },
      );

      await redisClient.set(versionKey, SEARCH_SCHEMA_VERSION);

      console.log(`✅ Search index created for: ${subdomain}`);
      return;
    }

    console.log(`ℹ️ Search index already exists: ${indexName}`);
  } catch (e) {
    console.log("❌ Failed creating search index:", e.message);
  }
}

function escapeRedisSearchTag(value) {
  return String(value).replace(/([,.<>{}\[\]"':;!@#$%^&*()\-+=~|/\\ ])/g, "\\$1");
}

async function createAgentGroupSearchIndex(subdomain) {
  const indexName = `${subdomain}-agent-group-chats`;

  try {
    await redisClient.sendCommand(["FT._LIST"]);
  } catch (error) {
    throw new Error("RediSearch module is not installed or unavailable");
  }

  try {
    await redisClient.ft.create(
      indexName,
      {
        id: { type: "TAG" },
        name: { type: "TEXT" },
        queue: { type: "TAG" },
        subdomain: { type: "TAG" },
        createdBy: { type: "TAG" },
        memberIds: { type: "TAG", SEPARATOR: "," },
        memberCount: { type: "NUMERIC", SORTABLE: true },
        members: { type: "TEXT" },
        createdAt: { type: "NUMERIC", SORTABLE: true },
        groupKey: { type: "TAG" },
      },
      {
        ON: "HASH",
        PREFIX: `${subdomain}-agent-group-`,
        STOPWORDS: [],
      },
    );
    console.log(`✅ Agent group search index created: ${indexName}`);
  } catch (error) {
    if (
      error.message?.includes("Index already exists") ||
      error.message?.includes("Duplicate index name")
    ) {
      return;
    }
    throw error;
  }
}

async function resolveAgentGroup({ subdomain, groupId, queue }) {
  const normalizedSubdomain = String(subdomain || "").trim();
  const normalizedGroupId = String(groupId || "").trim();
  const normalizedQueue = String(queue || "").trim();

  if (!normalizedSubdomain || !normalizedGroupId || !normalizedQueue) {
    throw new Error("subdomain, groupId, and queue are required");
  }

  await createAgentGroupSearchIndex(normalizedSubdomain);

  const indexName = `${normalizedSubdomain}-agent-group-chats`;
  const query =
    `@id:{${escapeRedisSearchTag(normalizedGroupId)}} ` +
    `@queue:{${escapeRedisSearchTag(normalizedQueue)}}`;
  const result = await redisClient.ft.search(indexName, query, {
    LIMIT: { from: 0, size: 1 },
    DIALECT: 2,
  });
  const document = result.documents[0];

  console.log("[GROUP CHAT][RESOLVE] RedisSearch completed", {
    indexName,
    query,
    groupId: normalizedGroupId,
    queue: normalizedQueue,
    total: result.total,
    redisKey: document?.id || null,
  });

  if (!document) throw new Error("Group chat does not exist");

  const groupKey = document.id;
  const group = await redisClient.hGetAll(groupKey);
  if (!group.id) {
    console.error("[GROUP CHAT][RESOLVE] Search document has no group hash", {
      groupKey,
      groupId: normalizedGroupId,
      queue: normalizedQueue,
    });
    throw new Error("Group chat data is missing");
  }

  return { groupKey, group };
}

async function resolveRequestedAgentGroup({
  subdomain,
  groupId,
  queue,
  requestedGroupKey,
}) {
  const exactKey = String(requestedGroupKey || "").trim();
  if (!exactKey) return resolveAgentGroup({ subdomain, groupId, queue });

  // Group keys are deterministic. Accepting only the expected key prevents a
  // caller from using search navigation to read a different Redis hash.
  const expectedKey = `${subdomain}-${groupId}-${queue}`;
  if (exactKey !== expectedKey) {
    throw new Error("Search result does not match the requested group");
  }
  const group = await redisClient.hGetAll(exactKey);
  if (!group.id || String(group.id) !== String(groupId) ||
      String(group.queue) !== String(queue)) {
    throw new Error("Group chat data is missing or inconsistent");
  }
  return { groupKey: exactKey, group };
}

async function createAgentGroupMessageSearchIndex(subdomain) {
  const indexName = `${subdomain}-agent-group-messages`;

  try {
    await redisClient.sendCommand(["FT._LIST"]);
    await redisClient.ft.create(
      indexName,
      {
        uniqueId: { type: "TAG" },
        groupId: { type: "TAG" },
        queue: { type: "TAG" },
        senderExtension: { type: "TAG" },
        senderName: { type: "TEXT" },
        message: { type: "TEXT" },
        timestamp: { type: "NUMERIC", SORTABLE: true },
        conversationKey: { type: "TAG" },
      },
      {
        ON: "HASH",
        PREFIX: `${subdomain}:groupmsg:`,
        STOPWORDS: [],
      },
    );
    console.log(`✅ Agent group message index created: ${indexName}`);
  } catch (error) {
    if (
      error.message?.includes("Index already exists") ||
      error.message?.includes("Duplicate index name")
    ) {
      return;
    }
    throw error;
  }
}

async function saveAgentGroupMessageForSearch({
  subdomain,
  groupKey,
  message,
}) {
  await createAgentGroupMessageSearchIndex(subdomain);

  const messageSearchKey = `${subdomain}:groupmsg:${message.id}`;
  await redisClient.hSet(messageSearchKey, {
    uniqueId: String(message.id),
    groupId: String(message.groupId),
    queue: String(message.queue),
    senderExtension: String(message.senderExtension),
    senderName: String(message.senderName || ""),
    message: String(message.message || ""),
    timestamp: String(message.timestamp),
    conversationKey: String(groupKey),
    seen: JSON.stringify(Array.isArray(message.seen) ? message.seen : []),
    messageType: String(message.messageType || "message"),
    membershipAction: String(message.membershipAction || ""),
    affectedMemberIds: JSON.stringify(
      Array.isArray(message.affectedMemberIds)
        ? message.affectedMemberIds
        : [],
    ),
  });

  const result = await redisClient.ft.search(
    `${subdomain}-agent-group-messages`,
    `@uniqueId:{${escapeRedisSearchTag(message.id)}}`,
    { LIMIT: { from: 0, size: 1 }, DIALECT: 2 },
  );

  console.log("[GROUP CHAT][SEARCH] Message indexed", {
    uniqueId: message.id,
    messageSearchKey,
    found: result.total > 0,
  });
}

async function deleteAgentGroupConversationHistory({ subdomain, groupKey }) {
  const search = await redisClient.ft.search(
    `${subdomain}-agent-group-messages`,
    `@conversationKey:{${escapeRedisSearchTag(groupKey)}}`,
    { LIMIT: { from: 0, size: 10000 }, DIALECT: 2 },
  ).catch(() => ({ documents: [] }));
  const messageDocumentKeys = search.documents.map((document) => document.id);
  if (messageDocumentKeys.length > 0) {
    await redisClient.del(messageDocumentKeys);
  }
  await redisClient.del(`${groupKey}:messages`);
}

function emitAgentGroupMessageToRoom({
  subdomain,
  groupKey,
  memberIds,
  message,
}) {
  const targetRoom = `${subdomain}-all_messages`;
  const payload = {
    result: "success",
    groupKey,
    queue: message.queue,
    memberIds,
    message: { ...message, groupKey },
  };

  socketSignal.emit(
    "chatParameters",
    "group chat message",
    payload,
    targetRoom,
  );

  console.log("[GROUP CHAT][MESSAGE] Broadcast to domain room", {
    targetRoom,
    groupKey,
    groupId: message.groupId,
    queue: message.queue,
    messageId: message.id,
    memberCount: memberIds.length,
  });
}

async function createSearchIndexProfile(subdomain) {
  // 🔥 Early return if RediSearch is unavailable
  try {
    await redisClient.sendCommand(["FT._LIST"]);
  } catch (err) {
    console.log("❌ RediSearch module is not installed or unavailable.");
    return;
  }

  try {
    console.log(" ey ey ey profile");
    await redisClient.ft.create(
      `${subdomain}-profiles`,
      {
        username: { type: "TEXT", SORTABLE: true },
        email: { type: "TEXT", SORTABLE: true },
        clientKey: { type: "TAG" },
        referenceName: { type: "TEXT", SORTABLE: true },
        profileKey: { type: "TAG" },
      },
      {
        ON: "HASH",
        PREFIX: "profileData:",
        STOPWORDS: [],
      },
    );

    console.log(`✅ Profile search index created: ${subdomain}-profiles`);
  } catch (e) {
    // Ignore existing index errors
    if (
      e.message?.includes("Index already exists") ||
      e.message?.includes("Duplicate index name")
    ) {
      console.log(`ℹ️ Profile index already exists: ${subdomain}-profiles`);
      return;
    }

    console.log("❌ Failed creating profile index:", e.message);
  }
}

async function saveProfileDataForSearch(
  username,
  email,
  clientKey,
  referenceName,
) {
  // 🔥 Early return if RediSearch is unavailable
  try {
    await redisClient.sendCommand(["FT._LIST"]);
  } catch (err) {
    console.log("❌ RediSearch module is not installed or unavailable.");
    return;
  }

  try {
    const randUniqueId = await generateRandomUniqueId();
    const profileKey = `profileData:${username}:${email}:${randUniqueId}`;

    await redisClient.hSet(profileKey, {
      username: String(username || ""),
      email: String(email || ""),
      clientKey: String(clientKey || ""),
      referenceName: String(referenceName || ""),
      profileKey: String(profileKey),
    });

    console.log(`✅ Profile data saved for search: ${profileKey}`);
  } catch (e) {
    console.log("❌ Failed saving profile data for search:", e.message);
  }
}

async function saveMessageForSearch(
  message,
  timestamp,
  username,
  email,
  profile,
  uid,
  appId,
  pageId,
  roomID,
  messageType,
  clientKey,
  data1,
  data2,
  data3,
  data4,
  data5,
  subdomain,
) {
  // 🔥 Early return if RediSearch is unavailable
  try {
    await redisClient.sendCommand(["FT._LIST"]);
  } catch (err) {
    console.log("❌ RediSearch module is not installed or unavailable.");
    return;
  }

  console.log("time is", data2);
  try {
    const randUniqueId = await generateRandomUniqueId();
    const msgKey = `${subdomain}:msg:${roomID}:${randUniqueId}`;

    console.log("this is clientsave", clientKey, "WITHHH", msgKey);

    const data = await redisClient.hGetAll(clientKey);

    await redisClient.hSet(msgKey, {
      message: String(message || ""),
      timestamp: Number(timestamp || Date.now()),
      username: String(username || ""),
      email: String(email || ""),
      profile: String(profile || ""),
      uid: String(uid || ""),
      appId: String(appId || ""),
      pageId: String(pageId || ""),
      roomID: String(roomID || ""),
      messageType: String(messageType || ""),
      clientKey: String(clientKey || ""),
      msgKey: String(msgKey || ""),
      msgIndex: String(data?.msgIndex || "0"),
      Data1: String(data1 || ``),
      Data2: String(data2 || ``),
      Data3: String(data3 || ``),
      Data4: String(data4 || ``),
      Data5: String(data5 || ``),
    });

    console.log(`✅ Message saved for search: ${msgKey}`);
  } catch (e) {
    console.log("❌ Failed saving search message:", e.message);
  }
}

async function createSearchMessageIndex(subdomain) {
  // 🔥 Early return if RediSearch is not available
  try {
    await redisClient.sendCommand(["FT._LIST"]);
  } catch (err) {
    console.log("❌ RediSearch module is not installed or unavailable.");
    return;
  }

  try {
    await redisClient.ft.create(
      `${subdomain}-msgindex`,
      {
        message: { type: "TEXT" },
        timestamp: { type: "NUMERIC", SORTABLE: true },
        username: { type: "TEXT", SORTABLE: true },
        profile: { type: "TAG" },
        uid: { type: "TAG" },
        roomID: { type: "TAG" },
      },
      {
        ON: "HASH",
        PREFIX: "msgindex:",
        STOPWORDS: [],
      },
    );

    console.log(`✅ Search index created: ${subdomain}-msgindex`);
  } catch (e) {
    // Ignore "Index already exists"
    if (
      e.message?.includes("Index already exists") ||
      e.message?.includes("Duplicate index name")
    ) {
      console.log(`ℹ️ Index already exists: ${subdomain}-msgindex`);
      return;
    }

    console.log("❌ Failed creating index:", e.message);
  }
}

async function getCountryLocation(ip) {
  const token = "12c84f1e75268d";

  // Iwasan ang lookup kung localhost
  if (ip === "::1" || ip === "127.0.0.1") return "Localhost";

  // Pagbuo ng curl command (parang sa terminal)
  const curlCommand = `curl -s -X GET "https://ipinfo.io/${ip}/json" \
        -H "Authorization: Bearer ${token}" \
        --max-time 5`;

  try {
    const { stdout, stderr } = await execPromise(curlCommand);

    if (stderr) {
      console.error("Curl Stderr:", stderr);
    }

    const ipData = JSON.parse(stdout);
    return ipData.country || "Unknown";
  } catch (error) {
    console.error("Curl Execution Error:", error.message);
    return "Unknown";
  }
}

async function queueGeneratorList(queue, platform, redisKey, subdomain) {
  const redisData = await redisClient.hGetAll(redisKey.trim());

  // 1. Initial Setup & Validation
  const platformMap = {
    website: "webchat",
    whatsapp: "whatsapp",
    facebook: "facebook",
    twilio: "twilio",
    telnyx: "telnyx",
    telegram: "telgram",
    line: "line",
  };

  const platformCheck = platformMap[platform];
  if (!platformCheck) {
    console.log(`Unknown platform: ${platform}. Aborting.`);
    return;
  }

  const rawData = await redisClient.get(`queueAssigmentsAgents-${subdomain}`);
  if (!rawData) return console.log("No data found for pattern.");
  const queueAssignments = JSON.parse(rawData);
  const validExtensions = new Set();
  const matchedQueueIds = [];

  for (const queueObj of queueAssignments) {
    const [queueId, extensions] = Object.entries(queueObj)[0];

    const platformList = await redisClient.lRange(
      `${queueId}@${subdomain}-${platformCheck}-chat`,
      0,
      -1,
    );
    console.log("queueId push", queueId);
    // Platform Check Logic
    if (platformCheck === "whatsapp" || platformCheck === "facebook") {
      if (!platformList.includes(String(redisData.appId))) {
        console.log(
          `🚫 QUEUE SKIP: ${queueId} (AppID ${redisData.appId} not in ${platformCheck})`,
        );
        continue;
      }
    } else {
      if (!platformList.includes(String(redisData.pageId))) {
        console.log(
          `🚫 QUEUE SKIP: ${queueId} (pageId ${redisData.pageId} not in ${platformCheck})`,
        );
        continue;
      }
    }

    // --- ADDED THIS LINE ---
    matchedQueueIds.push(queueId);
    // -----------------------

    console.log(
      `✅ MATCHED QUEUE: ${queueId} | AppID: ${redisData.appId} || pageID: ${redisData.pageId} found in ${platformCheck} list.`,
    );

    const permKeys = extensions.map((ext) => `${ext}-${subdomain}-permission`);
    const permissions = await redisClient.mGet(permKeys);

    extensions.forEach((ext, index) => {
      validExtensions.add(ext);
    });
  }

  console.log("All Matched Queues for queueGenerator: ", matchedQueueIds);
}

async function generateRandomUniqueId() {
  // const uniqueId = Date.now();

  const generalChars =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  let generalUniqueId = "";
  let length = 16;

  for (let i = 0; i < length; i++) {
    generalUniqueId += generalChars.charAt(
      Math.floor(Math.random() * generalChars.length),
    );
  }

  generalUniqueId = `${Date.now()}${generalUniqueId}`;

  return generalUniqueId;
}

function safe(v) {
  if (v === undefined || v === null) return "";
  return String(v);
}

// Added helpers

// Line exclusive

async function saveLineFile(fileData, fqdnValue) {
  const fileType = fileData.name.split(".").pop();
  const fileName = `${Date.now()}.${fileType}`;
  const binaryData = Buffer.from(fileData.fileBuffer, "base64");

  let folder = "line_file";
  let msgType = 4;
  let msgFileName = "";
  let msgFileSize = "";

  if (fileData.type.startsWith("image")) {
    folder = "line_image";
    msgType = 2;
  } else if (fileData.type.startsWith("video")) {
    msgType = 3;
  } else if (fileData.type.endsWith("pdf") || fileData.type.endsWith("docx")) {
    msgType = 4;
    msgFileName = fileData.name;
    msgFileSize = fileData.size;
  }

  const localPath = `/var/www/webapp/chat_file_upload/${folder}/${fileName}`;
  const savedMessage = `chat_file_upload/${folder}/${fileName}`;
  const curlMessage = `https://${fqdnValue}/${savedMessage}`;

  await writeFileAsync(localPath, binaryData);

  return {
    msgType,
    savedMessage,
    curlMessage,
    msgFileName,
    msgFileSize,
  };
}

// Telegram exclusive

async function saveTelegramFile(fileData, fqdnValue) {
  const fileType = fileData.name.split(".").pop();
  const fileName = `${Date.now()}.${fileType}`;
  const binaryData = Buffer.from(fileData.fileBuffer, "base64");

  let folder = "telegram_file";
  let msgType = "document";
  let msgMethod = "sendDocument";

  if (fileData.type.startsWith("image")) {
    folder = "telegram_image";
    msgType = "photo";
    msgMethod = "sendPhoto";
  } else if (fileData.fileExtension?.startsWith("video")) {
    msgType = "video";
    msgMethod = "sendVideo";
  }

  const localPath = `/var/www/webapp/chat_file_upload/${folder}/${fileName}`;
  const savedMessage = `chat_file_upload/${folder}/${fileName}`;

  await writeFileAsync(localPath, binaryData);

  return {
    msgType,
    msgMethod,
    savedMessage,
    curlMessage:
      msgType === "document"
        ? localPath
        : `https://${fqdnValue}/${savedMessage}`,
  };
}

async function saveTelegramMessage(data) {
  const {
    arg,
    subdomain,
    clientKey,
    clientUser,
    agentUser,
    senderSocketId,
    telegramConversationKey,
    myextension,
    assignedAgentsString,
    extAgentName,
    extAgentExtension,
    message,
    uid,
  } = data;

  const now = Date.now();

  await redisClient.lPush(telegramConversationKey, [
    `${message}`,
    `${now}`,
    `${myextension}-Agent`,
    `${myextension}-Agent`,
    `${now}`,
  ]);

  const currentMsgIndex = Number(clientUser.msgIndex || 0) + 1;

  await redisClient.hSet(clientKey, {
    msgIndex: currentMsgIndex,
    timestamp: `${now}`,
    last_message: message?.toString() || "",
    message: message?.toString() || "",
    isReceived: "0",
    agent_unread: "0",
    client_unread: `${Number(clientUser.client_unread || 0) + 1}`,
    assigned_agent: assignedAgentsString,
    allocated_room: "ACTIVE",
    chatUpdated: now,
    extAgentName,
    extAgentExtension,
  });

  await saveMessageForSearch(
    `${message}`,
    `${now}`,
    `${agentUser.Agent_Name}`,
    `${agentUser.email}`,
    "agent",
    `${now}`,
    `${clientUser.appId}`,
    `${clientUser.pageId}`,
    telegramConversationKey,
    "telegram",
    `${clientKey}`,
    `${message}`,
    `${now}`,
    `${myextension}-Agent`,
    `${myextension}-Agent`,
    `${now}`,
    `${subdomain}`,
  );

  await saveTelegramConversationLogs({
    arg,
    subdomain,
    agentUser,
    clientUser,
    telegramConversationKey,
    message,
  });

  return {
    uid,
    socketId: senderSocketId,
    username: `${myextension}-Agent`,
    name: `${myextension}-Agent`,
    useremail: clientUser.email,
    channel: subdomain,
    message,
    time: now,
    allocated_room: "ACTIVE",
    client_unread: clientUser.client_unread,
    agent_unread: clientUser.agent_unread,
    assigned_agent: assignedAgentsString,
    roomID: agentUser.agentID || agentUser.id,
    last_message: message,
    messageType: "telegram",
    id: clientUser.id,
    agentName: agentUser.Agent_Name,
    ourNumber: clientUser.ourNumber,
    appId: clientUser.appId,
    chatUpdated: now,
    referenceName: clientUser.referenceName,
    from: `${myextension}-Agent`,
    email: clientUser.email,
  };
}

async function saveTelegramConversationLogs({
  arg,
  subdomain,
  agentUser,
  clientUser,
  telegramConversationKey,
  message,
}) {
  const now = `${Date.now()}`;

  await conversationLogsAdd(
    "agent",
    {
      date: now,
      username: `${agentUser.Agent_Name} (${agentUser.extension})`,
      email: arg.agent_email,
      to: clientUser.referenceName || "",
      messageType: clientUser.messageType ?? "telegram",
      conversationsFetch: telegramConversationKey,
    },
    subdomain,
  );

  await conversationLogsAddExtension(
    "agent",
    {
      date: now,
      username: `${agentUser.Agent_Name} (${agentUser.extension})`,
      email: arg.agent_email,
      to: clientUser.referenceName || "",
      message,
    },
    telegramConversationKey,
  );
}

function emitQueuePayload(fqdnValue, payload) {
  if (!payload || Object.keys(payload).length === 0) return;

  socketSignal.emit(
    "chatParameters",
    "queue7",
    payload,
    `${fqdnValue}-all_messages`,
  );
}

// Facebook exclusive

async function sendFacebookText(accessToken, senderId, message) {
  if (!message) return;

  const payload = {
    messaging_type: "RESPONSE",
    recipient: { id: senderId },
    message: { text: message },
  };

  const sendApiUrl = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;
  return executeFacebookCurl(sendApiUrl, payload);
}

async function sendFacebookAttachment({
  accessToken,
  senderId,
  localPath,
  attachmentType,
}) {
  const uploadPayload = {
    attachment: {
      type: attachmentType,
      payload: {
        is_reusable: true,
      },
    },
  };

  const uploadUrl = `https://graph.facebook.com/v18.0/me/message_attachments?access_token=${accessToken}`;

  const uploadCommand = `curl -s -X POST "${uploadUrl}" \
    -F 'message=${JSON.stringify(uploadPayload)}' \
    -F "filedata=@${localPath}"`;

  const uploadResult = await executeRawCurl(uploadCommand);
  const attachmentId = uploadResult?.attachment_id;

  if (!attachmentId) {
    throw new Error(
      "Facebook attachment upload failed. No attachment_id returned.",
    );
  }

  const sendPayload = {
    messaging_type: "RESPONSE",
    recipient: { id: senderId },
    message: {
      attachment: {
        type: attachmentType,
        payload: {
          attachment_id: attachmentId,
        },
      },
    },
  };

  const sendApiUrl = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;
  return executeFacebookCurl(sendApiUrl, sendPayload);
}

function executeFacebookCurl(url, payload) {
  const safePayload = JSON.stringify(payload).replace(/'/g, "'\\''");

  const command = `curl -s -X POST "${url}" \
    -H "Content-Type: application/json" \
    -d '${safePayload}'`;

  return executeRawCurl(command);
}

function executeRawCurl(command) {
  return new Promise((resolve, reject) => {
    exec(command, (error, stdout) => {
      if (error) return reject(error);

      try {
        resolve(JSON.parse(stdout));
      } catch {
        resolve(stdout);
      }
    });
  });
}

async function updateFacebookClient({
  clientKey,
  clientUser,
  mysocket,
  assignedAgentsString,
  lastMessage,
  extAgentName,
  extAgentExtension,
}) {
  await redisClient.hSet(clientKey, {
    timestamp: Date.now().toString(),
    last_message: lastMessage || "",
    message: lastMessage || "",
    agent_unread: "0",
    client_unread: clientUser.client_unread.toString(),
    socketID: mysocket,
    allocated_room: "ACTIVE",
    assigned_agent: assignedAgentsString,
    chatUpdated: Date.now(),
    extAgentName,
    extAgentExtension,
  });
}

async function setFacebookConversationActive({
  fqdnValue,
  safeUsername,
  clientId,
  clientEmail,
}) {
  const presence = `${safeUsername}-${clientId}-facebook-${clientEmail}`;

  await redisClient.zRem(`${fqdnValue}-conversation-active`, presence);
  await redisClient.zRem(`${fqdnValue}-conversation`, presence);
  await redisClient.zRem(`${fqdnValue}-conversation-new`, presence);
  await redisClient.zRem(`${fqdnValue}-conversation-close`, presence);

  await redisClient.zAdd(`${fqdnValue}-conversation-active`, {
    score: Date.now(),
    value: presence,
  });

  await redisClient.zAdd(`${fqdnValue}-conversation`, {
    score: Date.now(),
    value: presence,
  });
}

async function saveFacebookMessage({
  facebookKey,
  clientKey,
  clientUser,
  agentUser,
  myextension,
  subdomain,
  message,
}) {
  await redisClient.lPush(facebookKey, [
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${subdomain}`,
    "agent",
  ]);

  const msgIndex = Number(clientUser.msgIndex || 0) + 1;

  await redisClient.hSet(clientKey, {
    msgIndex,
  });

  clientUser.msgIndex = msgIndex;

  await saveMessageForSearch(
    `${message}`,
    `${Date.now()}`,
    `${agentUser.Agent_Name}`,
    `${agentUser.email}`,
    "agent",
    `${Date.now()}`,
    `${clientUser.appId}`,
    `${clientUser.pageId}`,
    facebookKey,
    "facebook",
    `${clientKey}`,
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${subdomain}`,
    "agent",
    `${subdomain}`,
  );
}

async function saveFacebookLogs({
  subdomain,
  facebookKey,
  agentUser,
  clientUser,
  agentEmail,
  message,
}) {
  const baseLog = {
    date: `${Date.now()}`,
    username: `${agentUser.Agent_Name} (${agentUser.extension})`,
    email: agentEmail,
    to: clientUser.username || "",
  };

  await conversationLogsAdd(
    "agent",
    {
      ...baseLog,
      messageType: clientUser.messageType ?? "facebook",
      conversationsFetch: facebookKey,
    },
    subdomain,
  );

  await conversationLogsAddExtension(
    "agent",
    {
      ...baseLog,
      message,
    },
    facebookKey,
  );
}

async function handleFacebookFiles({
  filesData,
  fqdnValue,
  accessToken,
  senderId,
}) {
  const messages = [];

  for (const fileData of filesData) {
    const fileType = fileData.name.split(".").pop();
    const fileName = `${Date.now()}.${fileType}`;
    const binaryData = Buffer.from(fileData.fileBuffer, "base64");

    const isImage = fileData.type.startsWith("image");
    const folder = isImage ? "fb_image" : "fb_files";
    const attachmentType = isImage ? "image" : "file";

    const localPath = `/var/www/webapp/chat_file_upload/${folder}/${fileName}`;
    const redisPath = `chat_file_upload/${folder}/${fileName}`;

    await writeFileAsync(localPath, binaryData);

    await sendFacebookAttachment({
      accessToken,
      senderId,
      localPath,
      attachmentType,
    });

    messages.push({
      message: redisPath,
      lastMessage: redisPath,
      type: "file",
    });
  }

  return messages;
}

function emitFacebookQueue({
  fqdnValue,
  clientUser,
  arg,
  mysocket,
  myextension,
  subdomain,
  assignedAgentsString,
  message,
  lastMessage,
}) {
  console.log("emmiting facebook");
  socketSignal.emit(
    "chatParameters",
    "queue2",
    {
      id: clientUser.id,
      name: clientUser.username,
      socketId: mysocket,
      username: arg.client_username,
      email: arg.client_email,
      channel: subdomain,
      message,
      unix: Date.now(),
      time: Date.now(),
      from: `${myextension}-Agent`,
      client_unread: 0,
      agent_unread: 0,
      assigned_agent: assignedAgentsString,
      allocated_room: "ACTIVE",
      last_message: lastMessage,
      appId: clientUser.pageId,
      chatUpdated: Date.now(),
    },
    `${fqdnValue}-all_messages`,
  );
}

async function updateFacebookUnreadCounts({
  fqdnValue,
  clientKey,
  assignedAgents,
  extension,
}) {
  const clientUser = await redisClient.hGetAll(clientKey);
  await redisClient.zRem(
    `${fqdnValue}-overallUnreadCount`,
    `unread-${clientKey}`,
  );
  const overallUnreadCount = await redisClient.zCard(
    `${fqdnValue}-overallUnreadCount`,
  );

  if (
    clientUser.assigned_agent &&
    clientUser.assigned_agent !== "" &&
    clientUser.assigned_agent !== "[]"
  ) {
    const assignedAgent = assignedAgents[0];

    await redisClient.zRem(`unread-${assignedAgent}`, `unread-${clientKey}`);

    const assignedCount = await redisClient.zCard(`unread-${assignedAgent}`);
    const agentDetails = await redisClient.hGetAll(assignedAgent);

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      {
        agent_unread: overallUnreadCount,
        total: assignedCount,
        extension: agentDetails.extension || extension,
      },
      `${fqdnValue}-all_messages`,
    );
  } else {
    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      { agent_unread: overallUnreadCount },
      `${fqdnValue}-all_messages`,
    );
    return;
  }
}

// Whatsapp exclusive

async function sendWhatsappText(sendApiUrl, accessToken, phone, message) {
  if (!message) return;

  const payload = {
    messaging_product: "whatsapp",
    to: phone,
    type: "text",
    text: { body: message },
    language: { code: "en_US" },
  };

  return executeCurl(sendApiUrl, accessToken, payload);
}

async function sendWhatsappMedia({
  sendApiUrl,
  accessToken,
  phone,
  type,
  link,
  filename,
}) {
  const payload = {
    messaging_product: "whatsapp",
    to: phone,
    type,
  };

  if (type === "image") {
    payload.image = { link };
  }

  if (type === "document") {
    payload.document = {
      link,
      filename: filename || "",
    };
  }

  return executeCurl(sendApiUrl, accessToken, payload);
}

function executeCurl(url, accessToken, payload) {
  return new Promise((resolve, reject) => {
    const command = `curl -s -X POST "${url}" \
      -H "Authorization: Bearer ${accessToken}" \
      -H "Content-Type: application/json" \
      -d '${JSON.stringify(payload)}'`;

    exec(command, (error, stdout) => {
      if (error) return reject(error);

      try {
        resolve(JSON.parse(stdout));
      } catch {
        resolve(stdout);
      }
    });
  });
}

async function updateWhatsappClient({
  clientKey,
  clientUser,
  mysocket,
  assignedAgentsString,
  lastMessage,
  message,
  extAgentName,
  extAgentExtension,
}) {
  await redisClient.hSet(clientKey, {
    timestamp: Date.now().toString(),
    last_message: lastMessage || "",
    message: message || "",
    agent_unread: "0",
    client_unread: Number(clientUser.client_unread || 0) + 1,
    socketID: mysocket,
    allocated_room: "ACTIVE",
    assigned_agent: assignedAgentsString,
    chatUpdated: Date.now(),
    extAgentName,
    extAgentExtension,
  });
}

function emitWhatsappQueue({
  fqdnValue,
  clientUser,
  arg,
  mysocket,
  myextension,
  subdomain,
  assignedAgentsString,
  message,
  lastMessage,
}) {
  socketSignal.emit(
    "chatParameters",
    "queue3",
    {
      id: clientUser.id,
      name: clientUser.username,
      socketId: mysocket,
      username: arg.client_username,
      email: clientUser.email,
      channel: subdomain,
      message,
      unix: Date.now(),
      time: Date.now(),
      from: `${myextension}-Agent`,
      client_unread: 0,
      agent_unread: 0,
      assigned_agent: assignedAgentsString,
      allocated_room: "ACTIVE",
      last_message: lastMessage,
      appId: clientUser.pageId,
      number: clientUser.number,
      useremail: clientUser.email,
      chatUpdated: Date.now(),
      referenceName: clientUser.referenceName,
      phone: clientUser.phone,
    },
    `${fqdnValue}-all_messages`,
  );
}

async function updateWhatsappUnreadCounts({
  fqdnValue,
  clientKey,
  assignedAgents,
  overallExtension,
}) {
  const clientUser = await redisClient.hGetAll(clientKey);
  await redisClient.zRem(
    `${fqdnValue}-overallUnreadCount`,
    `unread-${clientKey}`,
  );

  const overallUnreadCount = await redisClient.zCard(
    `${fqdnValue}-overallUnreadCount`,
  );

  if (
    clientUser.assigned_agent &&
    clientUser.assigned_agent !== "" &&
    clientUser.assigned_agent !== "[]"
  ) {
    const assignedAgent = assignedAgents[0];
    await redisClient.zRem(`unread-${assignedAgent}`, `unread-${clientKey}`);

    const assignedCount = await redisClient.zCard(`unread-${assignedAgent}`);
    const agentDetails = await redisClient.hGetAll(assignedAgent);

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      {
        agent_unread: overallUnreadCount,
        total: assignedCount,
        extension: agentDetails.extension || overallExtension,
      },
      `${fqdnValue}-all_messages`,
    );
  } else {
    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      { agent_unread: overallUnreadCount },
      `${fqdnValue}-all_messages`,
    );
    return;
  }
}

async function setWhatsappConversationActive(fqdnValue, arg) {
  const username = arg.client_username.trim();
  const presence = `${username}-${arg.client_email}-whatsapp-${arg.client_ID}`;

  await redisClient.zRem(`${fqdnValue}-conversation-active`, presence);
  await redisClient.zRem(`${fqdnValue}-conversation`, presence);
  await redisClient.zRem(`${fqdnValue}-conversation-new`, presence);
  await redisClient.zRem(`${fqdnValue}-conversation-close`, presence);

  await redisClient.zAdd(`${fqdnValue}-conversation-active`, {
    score: Date.now(),
    value: presence,
  });

  await redisClient.zAdd(`${fqdnValue}-conversation`, {
    score: Date.now(),
    value: presence,
  });
}

async function saveWhatsappMessage({
  whatsappKey,
  clientKey,
  clientUser,
  agentUser,
  myextension,
  subdomain,
  message,
}) {
  await redisClient.lPush(whatsappKey, [
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${subdomain}`,
    "agent",
  ]);

  const msgIndex = Number(clientUser.msgIndex || 0) + 1;

  await redisClient.hSet(clientKey, {
    msgIndex,
  });

  clientUser.msgIndex = msgIndex;
  await saveMessageForSearch(
    `${message}`,
    `${Date.now()}`,
    `${agentUser.Agent_Name}`,
    `${agentUser.email}`,
    "agent",
    `${Date.now()}`,
    `${clientUser.appId}`,
    `${clientUser.pageId}`,
    whatsappKey,
    "whatsapp",
    `${clientKey}`,
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${subdomain}`,
    "agent",
    `${subdomain}`,
  );
}

async function saveWhatsappLogs({
  subdomain,
  whatsappKey,
  agentUser,
  clientUser,
  agentEmail,
  message,
}) {
  const baseLog = {
    date: `${Date.now()}`,
    username: `${agentUser.Agent_Name} (${agentUser.extension})`,
    email: agentEmail,
    to: clientUser.referenceName || "",
  };

  await conversationLogsAdd(
    "agent",
    {
      ...baseLog,
      messageType: clientUser.messageType ?? "whatsapp",
      conversationsFetch: whatsappKey,
    },
    subdomain,
  );

  await conversationLogsAddExtension(
    "agent",
    {
      ...baseLog,
      message,
    },
    whatsappKey,
  );
}

async function handleWhatsappFiles({
  filesData,
  fqdnValue,
  sendApiUrl,
  accessToken,
  phone,
}) {
  const messages = [];

  for (const fileData of filesData) {
    const fileType = fileData.name.split(".").pop();
    const uniqueId = Date.now();

    const convertedName = fileData.name
      .trim()
      .replace(/[-]+/g, " ")
      .replace(/\s+/g, "_")
      .replace(/[()]/g, "");

    const fileName = `${uniqueId}.${fileType}-${convertedName}`;
    const binaryData = Buffer.from(fileData.fileBuffer, "base64");

    if (fileData.type.startsWith("image")) {
      const localPath = `/var/www/webapp/chat_file_upload/wp_img/${fileName}`;
      const redisPath = `chat_file_upload/wp_img/${fileName}`;
      const contentUrl = `https://${fqdnValue}/${redisPath}`;

      await writeFileAsync(localPath, binaryData);

      await sendWhatsappMedia({
        sendApiUrl,
        accessToken,
        phone,
        type: "image",
        link: contentUrl,
      });

      messages.push({
        message: redisPath,
        lastMessage: redisPath,
        type: "file",
      });
    } else if (fileData.type.startsWith("file")) {
      const localPath = `/var/www/webapp/chat_file_upload/wp_file/${fileName}`;
      const redisPath = `chat_file_upload/wp_file/${fileName}`;
      const contentUrl = `https://${fqdnValue}/${redisPath}`;

      await writeFileAsync(localPath, binaryData);

      await sendWhatsappMedia({
        sendApiUrl,
        accessToken,
        phone,
        type: "document",
        link: contentUrl,
        filename: fileData.name,
      });

      messages.push({
        message: redisPath,
        lastMessage: redisPath,
        type: "file",
      });
    }
  }

  return messages;
}

// Twilio Exclusive

async function updateTwilioClient({
  clientKey,
  clientUser,
  assignedAgentsString,
  mysocket,
  lastMessage,
  extAgentName,
  extAgentExtension,
}) {
  await redisClient.hSet(clientKey, {
    timestamp: Date.now().toString(),
    last_message: lastMessage || "",
    message: lastMessage || "",
    isReceived: "0",
    agent_unread: "0",
    client_unread: Number(clientUser.client_unread || 0).toString(),
    socketID: mysocket,
    assigned_agent: assignedAgentsString,
    allocated_room: "ACTIVE",
    chatUpdated: Date.now(),
    extAgentName,
    extAgentExtension,
  });
}

function buildTwilioPayload({
  item,
  uniqueId,
  senderSocketId,
  myextension,
  clientUser,
  agentUser,
  subdomain,
  assignedAgentsString,
}) {
  return {
    uid: uniqueId,
    socketId: senderSocketId,
    username: `${myextension}-Agent`,
    name: clientUser.username,
    useremail: clientUser.email,
    channel: subdomain,
    message: item.message,
    time: Date.now(),
    allocated_room: "ACTIVE",
    client_unread: clientUser.client_unread,
    agent_unread: clientUser.agent_unread,
    assigned_agent: assignedAgentsString,
    roomID: agentUser.agentID || agentUser.id,
    last_message: item.lastMessage,
    messageType: "twilio",
    id: clientUser.id,
    agentName: agentUser.Agent_Name,
    ourNumber: clientUser.ourNumber,
    appId: clientUser.appId,
    pageId: clientUser.pageId,
    chatUpdated: Date.now(),
  };
}

async function emitTwilioUpdates({
  fqdnValue,
  subdomain,
  clientKey,
  assignedAgents,
  payloads,
}) {
  const clientUser = await redisClient.hGetAll(clientKey);
  await redisClient.zRem(
    `${subdomain}-overallUnreadCount`,
    `${subdomain}-unread-${clientKey}`,
  );

  const overallUnreadCount = await redisClient.zCard(
    `${subdomain}-overallUnreadCount`,
  );

  const unreadData = {
    agent_unread: overallUnreadCount,
  };

  if (
    clientUser.assigned_agent &&
    clientUser.assigned_agent !== "[]" &&
    clientUser.assigned_agent !== ""
  ) {
    const assignedAgent = assignedAgents[0];
    const agentUnreadKey = `unread-${assignedAgent}`;

    await redisClient.zRem(agentUnreadKey, `unread-${clientKey}`);

    unreadData.total = await redisClient.zCard(agentUnreadKey);

    const agentDetails = await redisClient.hGetAll(assignedAgent);
    unreadData.extension = agentDetails.extension;

    for (const payload of payloads) {
      socketSignal.emit(
        "chatParameters",
        "queue6",
        payload,
        `${fqdnValue}-all_messages`,
      );
    }

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      unreadData,
      `${fqdnValue}-all_messages`,
    );
  } else {
    for (const payload of payloads) {
      socketSignal.emit(
        "chatParameters",
        "queue6",
        payload,
        `${fqdnValue}-all_messages`,
      );
    }

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      unreadData,
      `${fqdnValue}-all_messages`,
    );
  }
}

async function handleTwilioFiles(filesData) {
  const messages = [];

  for (const fileData of filesData) {
    const fileType = fileData.name.split(".").pop();
    const fileName = `${Date.now()}.${fileType}`;
    const binaryData = Buffer.from(fileData.fileBuffer, "base64");

    const isImage = fileData.type.startsWith("image");
    const folder = isImage ? "twilio_img" : "twilio_file";

    const localPath = `/var/www/webapp/chat_file_upload/${folder}/${fileName}`;
    const redisPath = `chat_file_upload/${folder}/${fileName}`;

    await writeFileAsync(localPath, binaryData);

    messages.push({
      message: redisPath,
      lastMessage: redisPath,
      type: "file",
    });
  }

  return messages;
}

async function saveTwilioMessage({
  twilioConversationKey,
  clientKey,
  clientUser,
  myextension,
  message,
}) {
  await redisClient.lPush(twilioConversationKey, [
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${myextension}-Agent`,
    `${Date.now()}`,
  ]);

  const msgIndex = Number(clientUser.msgIndex || 0) + 1;

  await redisClient.hSet(clientKey, {
    msgIndex,
  });

  clientUser.msgIndex = msgIndex;
}

async function saveTwilioLogs({
  subdomain,
  twilioConversationKey,
  agentUser,
  clientUser,
  agentEmail,
  message,
}) {
  const baseLog = {
    date: `${Date.now()}`,
    username: `${agentUser.Agent_Name} (${agentUser.extension})`,
    email: agentEmail,
    to: clientUser.username || "",
  };

  await conversationLogsAdd(
    "agent",
    {
      ...baseLog,
      messageType: clientUser.messageType ?? "twilio",
      conversationsFetch: twilioConversationKey,
    },
    subdomain,
  );

  await conversationLogsAddExtension(
    "agent",
    {
      ...baseLog,
      message,
    },
    twilioConversationKey,
  );
}

async function saveTwilioSearchMessage({
  twilioConversationKey,
  clientKey,
  clientUser,
  agentUser,
  message,
  fqdnValue,
}) {
  await saveMessageForSearch(
    `${message}`,
    `${Date.now()}`,
    `${agentUser.Agent_Name}`,
    `${agentUser.email}`,
    "agent",
    `${Date.now()}`,
    `${clientUser.appId}`,
    `${clientUser.pageId}`,
    twilioConversationKey,
    "twilio",
    `${clientKey}`,
    `${fqdnValue}`,
    fqdnValue,
  );
}

// Telnyx  Exclusive

function generateUniqueId(length = 16) {
  const chars =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  let id = "";

  for (let i = 0; i < length; i++) {
    id += chars.charAt(Math.floor(Math.random() * chars.length));
  }

  return `${Date.now()}${id}`;
}

async function handleTelnyxFiles(filesData) {
  const messages = [];

  for (const fileData of filesData) {
    const fileType = fileData.name.split(".").pop();
    const fileName = `${Date.now()}.${fileType}`;
    const binaryData = Buffer.from(fileData.fileBuffer, "base64");

    const isImage = fileData.type.startsWith("image");
    const folder = isImage ? "telnyx_img" : "telnyx_file";

    const localPath = `/var/www/webapp/chat_file_upload/${folder}/${fileName}`;
    const redisPath = `chat_file_upload/${folder}/${fileName}`;

    await writeFileAsync(localPath, binaryData);

    messages.push({
      message: redisPath,
      lastMessage: redisPath,
      type: "file",
    });
  }

  return messages;
}

async function saveTelnyxMessage({
  telnyxConversationKey,
  clientKey,
  clientUser,
  agentUser,
  myextension,
  message,
}) {
  await redisClient.lPush(telnyxConversationKey, [
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${myextension}-Agent`,
    `${Date.now()}`,
  ]);

  const msgIndex = Number(clientUser.msgIndex || 0) + 1;

  await redisClient.hSet(clientKey, {
    msgIndex,
  });

  clientUser.msgIndex = msgIndex;
}

async function saveTelnyxLogs({
  subdomain,
  telnyxConversationKey,
  agentUser,
  clientUser,
  agentEmail,
  message,
}) {
  const baseLog = {
    date: `${Date.now()}`,
    username: `${agentUser.Agent_Name} (${agentUser.extension})`,
    email: agentEmail,
    to: clientUser.username || "",
  };

  await conversationLogsAdd(
    "agent",
    {
      ...baseLog,
      messageType: clientUser.messageType ?? "telnyx",
      conversationsFetch: telnyxConversationKey,
    },
    subdomain,
  );

  await conversationLogsAddExtension(
    "agent",
    {
      ...baseLog,
      message,
    },
    telnyxConversationKey,
  );
}

async function saveTelnyxSearchMessage({
  telnyxConversationKey,
  clientKey,
  clientUser,
  agentUser,
  message,
  fqdnValue,
}) {
  await saveMessageForSearch(
    `${message}`,
    `${Date.now()}`,
    `${agentUser.Agent_Name}`,
    `${agentUser.email}`,
    "agent",
    `${Date.now()}`,
    `${clientUser.appId}`,
    `${clientUser.pageId}`,
    telnyxConversationKey,
    "telnyx",
    `${clientKey}`,
    `${fqdnValue}`,
  );
}

async function updateTelnyxClient({
  clientKey,
  clientUser,
  assignedAgentsString,
  mysocket,
  lastMessage,
  extAgentName,
  extAgentExtension,
}) {
  await redisClient.hSet(clientKey, {
    timestamp: Date.now().toString(),
    last_message: lastMessage || "",
    message: lastMessage || "",
    isReceived: "0",
    agent_unread: "0",
    client_unread: Number(clientUser.client_unread || 0).toString(),
    socketID: mysocket,
    assigned_agent: assignedAgentsString,
    allocated_room: "ACTIVE",
    chatUpdated: Date.now(),
    extAgentName,
    extAgentExtension,
  });
}

function buildTelnyxPayload({
  item,
  uniqueId,
  senderSocketId,
  myextension,
  clientUser,
  agentUser,
  subdomain,
  assignedAgentsString,
}) {
  return {
    uid: uniqueId,
    socketId: senderSocketId,
    username: `${myextension}-Agent`,
    name: clientUser.username,
    useremail: clientUser.email,
    channel: subdomain,
    message: item.message,
    time: Date.now(),
    allocated_room: "ACTIVE",
    client_unread: clientUser.client_unread,
    agent_unread: clientUser.agent_unread,
    assigned_agent: assignedAgentsString,
    roomID: agentUser.agentID || agentUser.id,
    last_message: item.lastMessage,
    messageType: "telnyx",
    id: clientUser.id,
    agentName: agentUser.Agent_Name,
    ourNumber: clientUser.ourNumber,
    appId: clientUser.appId,
    pageId: clientUser.pageId,
    chatUpdated: Date.now(),
  };
}

async function emitTelnyxUpdates({
  fqdnValue,
  subdomain,
  clientKey,
  assignedAgents,
  payloads,
}) {
  const clientUser = await redisClient.hGetAll(clientKey);

  await redisClient.zRem(
    `${subdomain}-overallUnreadCount`,
    `${subdomain}-unread-${clientKey}`,
  );

  const overallUnreadCount = await redisClient.zCard(
    `${subdomain}-overallUnreadCount`,
  );

  const unreadData = {
    agent_unread: overallUnreadCount,
  };

  if (
    clientUser.assigned_agent &&
    clientUser.assigned_agent !== "[]" &&
    clientUser.assigned_agent !== ""
  ) {
    const assignedAgent = assignedAgents[0];
    const agentUnreadKey = `unread-${assignedAgent}`;

    await redisClient.zRem(agentUnreadKey, `unread-${clientKey}`);

    unreadData.total = await redisClient.zCard(agentUnreadKey);

    const agentDetails = await redisClient.hGetAll(assignedAgent);
    unreadData.extension = agentDetails.extension;

    for (const payload of payloads) {
      socketSignal.emit(
        "chatParameters",
        "queue5",
        payload,
        `${fqdnValue}-all_messages`,
      );
    }

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      unreadData,
      `${fqdnValue}-all_messages`,
    );
  } else {
    for (const payload of payloads) {
      socketSignal.emit(
        "chatParameters",
        "queue5",
        payload,
        `${fqdnValue}-all_messages`,
      );
    }

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      unreadData,
      `${fqdnValue}-all_messages`,
    );
  }
}

// Exclusive for Webchat

async function emitChatUpdates({
  subdomain,
  clientKey,
  payloads,
  overallUnreadCount,
}) {
  const clientUser = await redisClient.hGetAll(clientKey);
  if (!clientUser.assigned_agent || clientUser.assigned_agent === "[]") {
    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      { agent_unread: overallUnreadCount },
      `${subdomain}-all_messages`,
    );
    return;
  }

  let assignedAgents = safeParseJSON(clientUser.assigned_agent);
  const agentAssigned = assignedAgents[0];

  const agentUnreadKey = `unread-${agentAssigned}`;
  const clientUnreadKey = `unread-${clientKey}`;

  await redisClient.zRem(agentUnreadKey, clientUnreadKey);

  const updatedUnreadCount = await redisClient.zCard(agentUnreadKey);
  const agentDetails = await redisClient.hGetAll(agentAssigned);

  const updateUnread = {
    agent_unread: overallUnreadCount,
    total: updatedUnreadCount,
    totalAgent: overallUnreadCount,
    extension: agentDetails.extension,
  };

  socketSignal.emit(
    "chatParameters",
    "queue_updatedata",
    updateUnread,
    `${subdomain}-all_messages`,
  );

  for (const payload of payloads) {
    socketSignal.emit(
      "chatParameters",
      "queue",
      payload,
      `${subdomain}-all_messages`,
    );
    socketSignal.emit("chatParameters", "queue", payload, clientUser.socketID);
  }
}

async function getAgentProfile(myextension, subdomain, agentUser) {
  const profileKey = `${myextension}:${subdomain}`;
  let profile = await redisClient.get(profileKey);

  profile = profile
    ? `https://${subdomain}:8443/avr/${profile}`
    : `https://${subdomain}:8443/img/ringq-square.png`;

  return `${profile}\\${agentUser.Agent_Name}`;
}

async function updateConversationStatus(subdomain, clientUser) {
  const clientPresence = `${clientUser.username}-${clientUser.email}-website-${clientUser.id}`;

  await redisClient.zRem(`${subdomain}-conversation`, clientPresence);
  await redisClient.zRem(`${subdomain}-conversation-close`, clientPresence);
  await redisClient.zRem(`${subdomain}-conversation-new`, clientPresence);
  await redisClient.zRem(`${subdomain}-conversation-active`, clientPresence);

  await redisClient.zAdd(`${subdomain}-conversation`, {
    score: Date.now(),
    value: clientPresence,
  });

  await redisClient.zAdd(`${subdomain}-conversation-active`, {
    score: Date.now(),
    value: clientPresence,
  });
}

async function saveUploadedFile(arg, subdomain, uniqueId) {
  const filePathContainer = [];

  for (let i = 0; i < arg.fileBuffer.length; i++) {
    const fileInfo = arg.fileInfo[i];
    const fileType = fileInfo.name.split(".").pop();
    const fileName = `${subdomain}-${uniqueId}.${fileType}`;
    const savePath = `/var/www/webapp/chat_file_upload/${fileName}`;

    const binaryData = Buffer.from(arg.fileBuffer[i], "base64");
    writeFile(savePath, binaryData, (err) => {
      if (err) console.error(`Write error for ${fileName}:`, err);
    });

    filePathContainer.push(
      `chat_file_upload/${fileName}`,
      fileInfo.type,
      fileInfo.size,
    );
  }

  const sanitizedName = arg.fileInfo[0].name
    .normalize("NFKC")
    .replace(/[\u00A0\u1680\u2000-\u200A\u202F\u205F\u3000]/g, " ")
    .replace(/-/g, "_")
    .trim();

  return {
    message: `${filePathContainer[0]}-${sanitizedName}`,
    lastMessage: filePathContainer[0],
    filePath: filePathContainer[0],
    filePathContainer,
    hasFile: true,
  };
}

async function keyChatAdded(key, subdomain) {
  console.log("addedd to allMessagesList");
  console.log(`will add this key ${key}`);
  console.log(`will add this subdomain ${subdomain}`);

  const activeChatKey = `${subdomain}-activeChats`;
  console.log("adding to main activeChatKey");
  // await redisClient.sAdd(`activeChats`, activeChatKey);
  // console.log("adding to second", key);
  await redisClient.sAdd(`${activeChatKey}`, `${key}`);

  return `added to allMessagesList ${key}`;
}

async function saveChatMessage({
  chatKey,
  clientKey,
  clientUser,
  agentUser,
  myextension,
  agentProfile,
  uniqueId,
  message,
  subdomain,
}) {
  await redisClient.lPush(chatKey, [
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${agentProfile}`,
    `${uniqueId}`,
  ]);

  const msgIndex = Number(clientUser.msgIndex || 0) + 1;

  await redisClient.hSet(clientKey, {
    msgIndex,
  });

  await saveMessageForSearch(
    `${message}`,
    `${Date.now()}`,
    `${agentUser.Agent_Name}`,
    `${agentUser.email}`,
    "agent",
    `${uniqueId}`,
    `${clientUser.appId}`,
    `${clientUser.pageId}`,
    chatKey,
    "website",
    `${clientKey}`,
    `${message}`,
    `${Date.now()}`,
    `${myextension}-Agent`,
    `${agentProfile}`,
    `${uniqueId}`,
    `${subdomain}`,
  );
}

async function saveConversationLogs({
  subdomain,
  chatKey,
  agentUser,
  clientUser,
  agentEmail,
  message,
}) {
  const baseLog = {
    date: `${Date.now()}`,
    username: `${agentUser.Agent_Name} (${agentUser.extension})`,
    email: agentEmail,
    to: clientUser.username || "",
  };

  await conversationLogsAdd(
    "agent",
    {
      ...baseLog,
      messageType: clientUser.messageType ?? "website",
      conversationsFetch: chatKey,
    },
    subdomain,
  );

  await conversationLogsAddExtension(
    "agent",
    {
      ...baseLog,
      message,
    },
    chatKey,
  );
}

async function handleChatLogin({
  arg,
  subdomain,
  mysocket,
  myextension,
  uniqueId,
  ip,
  socket,
}) {
  // ──────────────────────────────────────────────────────────────────────
  // type 3 = LOGIN / session establishment. Two kinds of caller:
  //   • user_type "Agent"    -> dashboard agent coming online
  //   • user_type "customer" -> website visitor opening the widget
  // For each it upserts the Redis user record, refreshes presence, and emits
  // the "login session" payload back to the caller's socket.
  // Logs are prefixed "[type3]" so you can grep one login end-to-end.
  // ──────────────────────────────────────────────────────────────────────
  try {
    const now = Date.now();

    if (!subdomain) {
      console.error("[type3] unauthorized login attempt (no subdomain)");
    } else if (arg["user_type"] === "Agent") {
      // ===================== AGENT LOGIN =====================
      if (myextension) {
        // All of these fields are required; bail out silently (as before) if any is missing.
        const requiredAgentFields = [
          "id",
          "email",
          "username",
          "Dept",
          "role",
          "Agent_Name",
          "agent_extension",
          "url",
        ];
        for (const field of requiredAgentFields) {
          if (!arg[field]) {
            console.log(`[type3] agent login rejected: missing "${field}"`);
            return;
          }
        }

        // Resolve the agent's avatar (fallback to the default square logo).
        const agentProfileParam = `${myextension}:${subdomain}`;
        let agentProfile = await redisClient.get(agentProfileParam);
        agentProfile = agentProfile
          ? `https://${subdomain}.ringq.ai:8443/avr/${agentProfile}`
          : `https://${subdomain}.ringq.ai:8443/img/ringq-square.png`;

        let key;
        let client_unread = "";
        let agent_unread = "";
        let userId = "";
        const userKey = `${myextension}-Agent-${arg.username}-${arg.email}-${subdomain}`;
        const user = (await redisClient.exists(userKey))
          ? await redisClient.hGetAll(userKey)
          : null;

        if (user) {
          // ---- Existing agent: refresh socket / status ----
          console.log("[type3] existing agent online", { userKey });
          // NOTE: this assigns unread_agent/unread_client, but the `response` below reads
          //       agent_unread/client_unread (which stay "") — so the response reports 0/0.
          //       Preserved as-is; flag if you want the real counts returned.
          unread_agent = user.agent_unread || 0;
          unread_client = user.client_unread || 0;
          user.socketID = mysocket;
          user.status = "online";
          user.lastSeen = new Date();
          userId = user.id;
          user.id = user.id || arg.id;
          user.role = user.role || arg.role;
          user.username = user.username || arg.username;
          user.email = user.email || arg.email;
          user.roomID = "all_messages";
          key = `${myextension}-${user.role}-${user.username}-${user.email}-${subdomain}`;
          // if (
          //   user.assigned &&
          //   user.assigned !== "" &&
          //   user.assigned !== "[]"
          // ) {
          //   noAssignedChecker(key, subdomain);
          // }
          await redisClient.hSet(key, {
            socketID: String(user.socketID ?? mysocket),
            status: "online",
            lastSeen: String(now),
            extension: myextension,
            Agent_Name: arg.Agent_Name,
            profile: agentProfile,
          });
        } else {
          // ---- New agent: create the record ----
          if (arg.id == null || arg.username == null || arg.email == null) {
            console.log(
              "[type3] new agent rejected: missing id/username/email",
            );
            // socketSignal.emit("chatParameters", "queueError", {
            //   message: `Please check your input credentials and try again`,
            //   status: "failed",
            // }, `${subdomain}-all_messages`);
            return;
          }
          console.log("[type3] new agent online", { userKey });
          const role = "Agent";
          key = `${myextension}-${role}-${arg.username}-${arg.email}-${subdomain}`;
          // noAssignedChecker(key, subdomain);
          await redisClient.lPush(`${subdomain}-agent_data`, key);
          unread_agent = 0;
          unread_client = 0;
          userId = uniqueId;

          await redisClient.hSet(key, {
            id: arg.id,
            username: arg.username,
            email: arg.email,
            Agent_Name: arg.Agent_Name,
            role: role,
            socketID: mysocket,
            Dept: arg.Dept,
            agent_unread: 0,
            roomID: "all_messages",
            status: "online",
            assigned: "",
            lastSeen: String(now),
            extension: myextension,
            profile: agentProfile,
          });
        }

        // Socket.IO room membership is a Set, but keep the type-3 login path
        // explicit: both new and existing agents join the domain room once,
        // and repeated type-3 payloads do not call join again. Do not
        // leave/rejoin here because that creates a window for missed events.
        const agentDomainRoom = `${subdomain}-all_messages`;
        if (!socket.rooms.has(agentDomainRoom)) {
          await socket.join(agentDomainRoom);
          console.log("[type3][room] agent joined domain room", {
            room: agentDomainRoom,
            socketId: socket.id,
            requestedSocketId: mysocket,
            extension: myextension,
          });
        } else {
          console.log("[type3][room] join skipped: already in domain room", {
            room: agentDomainRoom,
            socketId: socket.id,
            requestedSocketId: mysocket,
            extension: myextension,
          });
        }

        // ---- Map the agent's extension <-> userKey ----
        if (arg.email && arg.agent_extension && subdomain) {
          const extensionKey = `${arg.email}-${arg.agent_extension}-${subdomain}`;
          await redisClient.hSet(arg.agent_extension, {
            extensionData: extensionKey,
          });
          const existing = await redisClient.hGet(
            extensionKey,
            "extensionData",
          );
          await redisClient.hSet(extensionKey, { extensionData: userKey });
          if (existing === null) {
            // first time we've seen this extensionKey — register it in the set
            await redisClient.sAdd(
              `${subdomain}-extensiondatalist`,
              extensionKey,
            );
          }
        }

        if (!arg.agent_extension) {
          console.error(
            "[type3] validation failed: missing agent extension",
          );
          return;
        }
        await redisClient.sAdd(
          `${subdomain}-onlineAgents`,
          arg.agent_extension,
        );
        if (!key) {
          console.error("[type3] validation failed: missing key");
          return;
        }

        // ---- Unread tallies + socket->key mapping ----
        const overallUnreadCount = await redisClient.zCard(
          `${subdomain}-overallUnreadCount`,
        );
        const agentUnreadCount = `unread-${userKey}`;
        const assignedCount = await redisClient.zCard(
          `${agentUnreadCount}`,
        );
        const updateUnread = {
          agent_unread: overallUnreadCount,
          total: assignedCount,
          extension: arg.agent_extension,
        };
        await redisClient.hSet(mysocket, {
          userKey: key,
          socketId: mysocket,
        });

        // ---- Emit settings + login session + unread counters ----
        const globalSettings = await redisClient.hGetAll(
          `${subdomain}-ALL-CHAT-FIREBASE`,
        );
        const response = {
          message: "success",
          socket: mysocket,
          id: userId,
          email: arg.email,
          username: arg.username,
          agent_unread: agent_unread || 0,
          client_unread: client_unread || 0,
          globalSettings,
          status: "online",
        };

        const redisKey = `${subdomain}-emailsettings`;
        const aiKey = `${subdomain}-AISettings`;
        const chatKey = `${subdomain}-chatsettings`;
        const questionsKey = `${subdomain}-clientQuestions`;
        const messagePayload = await redisClient.hGetAll(redisKey);
        const aiMessagePayload = await redisClient.hGetAll(aiKey);
        const chatMessagePayload = await redisClient.hGetAll(chatKey);
        // Option buttons live in the `${subdomain}-clientQuestions` set as
        // "emoji label" strings — rebuild the { emoji, label } shape the
        // builder saved by splitting on the first space (emoji, then wording).
        const questionsRaw = await redisClient.sMembers(questionsKey);
        const buttons = questionsRaw.map((q) => {
          const trimmed = String(q).trim();
          const i = trimmed.indexOf(" ");
          return i === -1
            ? { emoji: "", label: trimmed }
            : { emoji: trimmed.slice(0, i), label: trimmed.slice(i + 1) };
        });
        socketSignal.emit(
          "chatParameters",
          "queue_settings",
          {
            ...messagePayload,
            ...aiMessagePayload,
            ...chatMessagePayload,
            buttons,
          },
          mysocket,
        );
        socketSignal.emit(
          "chatParameters",
          "login session",
          response,
          mysocket,
        );
        socketSignal.emit(
          "chatParameters",
          "queue_updatedata",
          updateUnread,
          mysocket,
        );
        console.log("[type3] agent login complete", {
          id: userId,
          email: arg.email,
        });
      }
    } else if (arg["user_type"] == "customer") {
      // ===================== CUSTOMER (WEBSITE VISITOR) LOGIN =====================
      const currentLimitKey = arg.currentLimitKey;
      const banResult = await banChecker(currentLimitKey, subdomain);
      if (banResult == 1) {
        console.log("[type3] customer login blocked: banned", {
          currentLimitKey,
        });
        socketSignal.emit(
          "chatParameters",
          "queue",
          {
            username: "error-Status",
            error_type: "RATE_LIMIT_LOGIN",
            message: "You are banned. Try again logging in some time",
            errorKey: currentLimitKey,
          },
          mysocket,
        );
        return;
      }

      let unread_client = "";
      let unread_agent = "";
      let userId = "";
      let key;
      let payloadAccessToken;
      const userKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;
      const user = (await redisClient.exists(userKey))
        ? await redisClient.hGetAll(userKey)
        : null;

      const locationCountry = await getCountryLocation(ip);
      console.log("[type3] visitor location", locationCountry);

      // AI settings for this tenant: tell the customer whether AI is on and
      // which provider is active. Default aiEnable to false if not set.
      const aiSettings = await redisClient.hGetAll(
        `${subdomain}-AISettings`,
      );
      const aiEnable = aiSettings?.aiEnable === "true";
      const selectedAI = aiEnable ? aiSettings?.selectedAI || "" : "";
      console.log("[type3] AI settings", { aiEnable, selectedAI });

      if (user) {
        // ---- Existing visitor: refresh session ----
        console.log("[type3] existing customer online", { userKey });
        unread_agent = user.agent_unread || 0;
        unread_client = user.client_unread || 0;
        if (user.allocated_room == "CLOSED") {
          console.log("[type3] reopening CLOSED room -> NEW", { userKey });
          user.allocated_room = "NEW";
          await redisClient.hSet(userKey, "status", "new");
        }
        const prevSocket = `${user.socketID}`;
        await redisClient.del(prevSocket);
        user.socketID = mysocket;
        user.status = "online";
        user.lastSeen = new Date();
        userId = user.id;
        const website = "website";
        key = `${user.id}-${website}-${user.username}-${user.email}-${subdomain}`;

        const verification = await addToken(
          "webchatconfig",
          arg.currentChannel,
          arg.accessToken,
          key,
        );
        if (!verification) {
          console.log(
            "[type3] token verification failed (existing customer)",
          );
          return;
        }
        console.log("[type3] customer token verified");

        if (arg.mirroredUrl) {
          await redisClient.hSet(key, { mirroredUrl: arg.mirroredUrl });
        }

        await redisClient.hSet(key, {
          pushtoken: arg.push_token ?? "",
          socketID: user.socketID,
          allocated_room: user.allocated_room || "NEW",
          lastSeen: String(now),
          status: "online",
          currentUrl: arg.currentUrl,
          currentIp: ip,
          locationCountry: locationCountry,
        });

        const onlinemessagePayload = {
          socketId: mysocket,
          status: "online",
          userKey: key,
          clientId: userId,
          ...(user.assigned_agent
            ? { assignedAgents: user.assigned_agent }
            : {}),
        };
        socketSignal.emit(
          "chatParameters",
          "queueStatus",
          onlinemessagePayload,
          `${subdomain}-all_messages`,
        );

        const globalSettings = await redisClient.hGetAll(
          `${subdomain}-ALL-CHAT-FIREBASE`,
        );
        payloadAccessToken = arg.accessToken;
        const response = {
          message: "success",
          socket: mysocket,
          id: userId,
          email: arg.email,
          username: arg.username,
          unread_agent,
          unread_client,
          accessToken: payloadAccessToken,
          globalSettings,
          aiEnable,
          selectedAI,
        };
        socketSignal.emit(
          "chatParameters",
          "login session",
          response,
          mysocket,
        );
        console.log("[type3] existing customer login complete", {
          id: userId,
        });
      } else {
        // ---- New visitor: create the record ----
        console.log("[type3] new customer online", { userKey });
        unread_agent = 0;
        unread_client = 0;
        const role = "website";
        userId = uniqueId;
        key = `${uniqueId}-${role}-${arg.username}-${arg.email}-${subdomain}`;

        if (arg.mirroredUrl) {
          await redisClient.hSet(key, {
            mirroredUrl: arg.mirroredUrl || arg.currentChannel,
          });
        }
        await redisClient.hSet(key, {
          id: uniqueId,
          pushtoken: arg.push_token ?? "",
          username: arg.username,
          email: arg.email,
          role: "customer",
          socketID: mysocket,
          Dept: arg.Dept,
          client_unread: 0,
          agent_unread: 0,
          status: "online",
          messageType: role,
          lastSeen: String(now),
          currentUrl: arg.currentUrl,
          currentIp: ip,
          locationCountry: locationCountry,
          firstChatTimestamp: `${Date.now()}`,
        });

        const verification = await addToken(
          "webchatconfig",
          arg.currentChannel,
          arg.accessToken,
          key,
        );
        if (!verification) {
          console.log("[type3] token verification failed (new customer)");
          return;
        }
        // (verifiedToken / pageId available from `verification` if needed downstream)
        const listKey = `${uniqueId}-${role}-${arg.username}-${arg.email}`;
        await redisClient.lPush(`${subdomain}-client_data`, listKey);

        const globalSettings = await redisClient.hGetAll(
          `${subdomain}-ALL-CHAT-FIREBASE`,
        );
        const response = {
          message: "success",
          socket: mysocket,
          id: userId,
          email: arg.email,
          username: arg.username,
          unread_agent,
          unread_client,
          accessToken: payloadAccessToken, // NOTE: undefined for new customers (unassigned), as before
          globalSettings,
          status: "online",
          aiEnable,
          selectedAI,
        };
        socketSignal.emit(
          "chatParameters",
          "login session",
          response,
          mysocket,
        );

        // Send the canned "related questions" only if the visitor already submitted the pre-chat form.
        if (arg.alreadySubmitted) {
          const messages = await redisClient.sMembers(
            `${subdomain}-clientQuestions`,
          );
          socketSignal.emit(
            "chatParameters",
            "related questions",
            {
              result: "success",
              content: messages,
              bool: true,
            },
            mysocket,
          );
        }
        const messageResult = await keyChatAdded(key, subdomain);
        console.log("[type3] new customer login complete", {
          id: userId,
          messageResult,
        });
      }
    }
  } catch (err) {
    console.error("[type3] ERROR", err);
  }
}

async function handleWebsiteClientMessage({
  arg,
  subdomain,
  mysocket,
  ip,

}) {
  // ──────────────────────────────────────────────────────────────────────
  // type 1 = inbound message from a WEBSITE VISITOR (the external "client").
  // Flow: verify token -> rate-limit -> build message (text and/or files) ->
  //       push notification -> route to agent dashboard -> AI auto-reply.
  // Every log is prefixed "[type1]" so you can grep one request end-to-end.
  // ──────────────────────────────────────────────────────────────────────
  console.log("[type1] inbound visitor message", {
    subdomain,
    id: arg.id,
    email: arg.email,
    msgId: arg.msgId,
  });
  try {
    // 1) Ensure this tenant has a search index so the message is searchable later.
    await createSearchIndex(subdomain);

    // 2) Reject the message if the visitor's access token isn't valid.
    const verified = await verifyToken2(
      "webchatconfig",
      subdomain,
      arg.accessToken,
    );

    

    if (!verified) {
      console.log("[type1] ABORT: token verification failed", {
        subdomain,
        id: arg.id,
      });
      return;
    }

    // 3) Redis keys: per-IP rate-limit bucket, the visitor's identity, the chat thread.
    const rateLimitKey = `${subdomain}:webchat:ratelimiting:${ip}`;
    const clientKey = `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`;
    const chatKey = `${subdomain}-external-${arg.email}-${arg.id}`;

    // 4) Spam guard. If the visitor is over the limit / banned, notify them and stop.
    const { allowed, rateLimitingUserKey } = await rateLimitMessages(
      redisClient,
      rateLimitKey,
      arg.errorKey || null,
      ip,
      clientKey,
      chatKey,
      subdomain,
      mysocket,
    );
    if (!allowed) {
      console.log("[type1] ABORT: rate-limited / banned", {
        clientKey,
        rateLimitingUserKey,
      });
      socketSignal.emit(
        "chatParameters",
        "queue",
        {
          username: "error-Status",
          error_type: "RATE_LIMIT",
          message: "User is banned due to spam.",
          errorKey: rateLimitingUserKey,
          msgId: arg.msgId,
        },
        arg.socketId,
      );
      return;
    }

    // 5) Build the message payload(s).
    // Fields shared by every message this branch emits (file message, text message, caption).
    const primaryMessageUid = await generateRandomUniqueId();
    const primaryMessageTime = Date.now();
    const baseMessage = {
      uid: primaryMessageUid,
      socketId: mysocket,
      username: arg.username,
      useremail: arg.email,
      type: 1,
      id: arg.id,
      name: arg.username,
      msgId: arg.msgId,
      accessToken: arg.accessToken,
    };

    let objMessageData; // primary message (file or text)
    let objMessageData2 = null; // optional 2nd message: caption text sent with a file
    let pushWebMessage = arg.text || ""; // short preview used for the push notification

    const hasFiles = arg.fileBuffer && arg.fileBuffer.length > 0;
    if (hasFiles) {
      // 5a) File upload(s).
      // NOTE: each file pushes [path, type, size] into ONE flat array, and only
      //       filePathContainer[0] is read below — so multi-file uploads currently
      //       only surface the FIRST file downstream. Kept as-is to preserve behavior.
      console.log(`[type1] handling ${arg.fileBuffer.length} file(s)`);
      const filePathContainer = [];
      for (let index = 0; index < arg.fileBuffer.length; index++) {
        const fileInfo = arg.fileInfo[index];
        const fileExt = fileInfo.name.split(".").pop();
        const fileName = `${subdomain}-${Date.now()}.${fileExt}`;

        // Route images -> /client_img, everything else -> /client_files.
        const imageExts = [
          "jpg",
          "jpeg",
          "png",
          "gif",
          "webp",
          "bmp",
          "svg",
          "heic",
          "heif",
          "tiff",
          "tif",
          "avif",
        ];
        const isImage =
          (fileInfo.type && fileInfo.type.startsWith("image/")) ||
          imageExts.includes(fileExt.toLowerCase());
        const subDir = isImage ? "client_img" : "client_files";
        const uploadDir = `/var/www/webapp/chat_file_upload/${subDir}`;

        // Make sure the target folder exists (create it 777 if missing).
        if (!existsSync(uploadDir)) {
          fs.mkdirSync(uploadDir, { recursive: true });
          fs.chmodSync(uploadDir, 0o777); // force 777 (mkdir mode is masked by umask)
          console.log(`[type1] created upload dir ${uploadDir} (777)`);
        }

        const savePath = `${uploadDir}/${fileName}`;

        // Decode base64 -> binary and write to disk (callback = fire-and-forget, not awaited).
        const binaryData = Buffer.from(arg.fileBuffer[index], "base64");
        writeFile(savePath, binaryData, (err) => {
          if (err)
            return console.error(
              `[type1] file write FAILED for ${fileName}:`,
              err,
            );
          console.log(`[type1] file written: ${savePath}`);
        });

        filePathContainer.push(
          `chat_file_upload/${subDir}/${fileName}`,
          fileInfo.type,
          fileInfo.size,
        );
      }

      const filePath = filePathContainer[0]; // first file's relative path
      const saveFile = `https://${subdomain}/${filePath}`;
      const fileOrigName = `${filePath}-${arg.fileInfo?.[0]?.name}`;
      pushWebMessage = arg.fileInfo?.[0]?.name; // notification preview = file name

      objMessageData = {
        ...baseMessage,
        message: fileOrigName,
        time: primaryMessageTime,
        filePath: filePathContainer,
        fileInfo: arg.fileInfo,
        last_message: filePath,
        saveLogsMessage: saveFile,
      };

      // If the visitor also typed text with the file, send it as a 2nd message.
      // Give the caption its own UID and a timestamp immediately after the file.
      if (arg.text) {
        console.log("[type1] file has an accompanying text caption");
        const captionUid = await generateRandomUniqueId();
        objMessageData2 = {
          ...objMessageData,
          uid: captionUid,
          message: arg.text,
          last_message: arg.text,
          msgId: arg.msgId + "a",
          time: primaryMessageTime + 1,
          saveLogsMessage: arg.text,
        };
      }
    } else {
      // 5b) Text-only message.
      console.log("[type1] handling text-only message");
      objMessageData = {
        ...baseMessage,
        message: arg.text,
        time: primaryMessageTime,
        last_message: arg.text,
        saveLogsMessage: arg.text,
      };
    }

    console.log("[type1] prepared Redis/search message IDs", {
      writeOrder: [
        { kind: hasFiles ? "file" : "text", uid: objMessageData.uid },
        ...(objMessageData2
          ? [{ kind: "caption", uid: objMessageData2.uid }]
          : []),
      ],
      lPushArgumentOrder: ["message", "time", "sender", "profile", "uid"],
      lRangeStoredOrder: ["uid", "profile", "sender", "time", "message"],
    });

    // 6) Trim the push-notification preview to 27 chars.
    if (pushWebMessage.length > 27)
      pushWebMessage = pushWebMessage.slice(0, 27) + "...";

    // 7) Is this visitor already in an open conversation? (presence tracked in a sorted set)
    const clientPresence = `${arg.username}-${arg.email}-website-${arg.id}`;
    const chatUserList = await redisClient.zScore(
      `${subdomain}-conversation`,
      clientPresence,
    );
    const isExisting = chatUserList !== null;
    console.log(
      `[type1] conversation lookup -> ${isExisting ? "EXISTING user" : "NEW user"}`,
    );

    // 8) Send the web/browser push notification.
    pushWeb(
      pushWebMessage,
      clientKey,
      subdomain,
      arg.username,
      "website",
      "client",
    );

    // 9) Route the message: existing -> chatOldUser, brand-new -> chatNewUser.
    //    The optional caption (objMessageData2) is always appended via chatOldUser.
    //    Awaited so the client hash is fully written before step 9b overwrites
    //    aiEnable and before aiAutoReply reads it — both of those set aiEnable, and
    //    unawaited they land in the wrong order.
    //    chatOldUser wraps its whole body in try/catch so it never rejects.
    //    chatNewUser's try starts partway in, so an early redis failure there now
    //    lands in this handler's catch (previously an unhandled rejection) and skips
    //    the rest of type 1 rather than taking the process down.
    if (isExisting) {
      await chatOldUser(
        subdomain,
        clientPresence,
        objMessageData,
        subdomain,
        mysocket,
      );
    } else {
      await chatNewUser(
        subdomain,
        clientPresence,
        objMessageData,
        subdomain,
      );
    }
    if (objMessageData2)
      await chatOldUser(
        subdomain,
        clientPresence,
        objMessageData2,
        subdomain,
        mysocket,
      );

    if (
      (arg.text || "").trim().toLowerCase().includes("chat with an agent")
    ) {
      await redisClient.hSet(clientKey, {
        aiEnable: "false",
      });
      console.log(
        "[type1] visitor requested an agent, AI disabled for",
        clientKey,
      );
    }

  // const enableRoundRobin = await redisClient.get(`chatsettings:${subdomain}-enableRoundRobin`);

  // // Convert "1" -> true, "0" or null -> false
  // const isRoundRobinEnabled = enableRoundRobin === "1" || enableRoundRobin === "true";
  // if (isRoundRobinEnabled) {
  //   await addToWaitingQueue(subdomain, clientKey);
  // } else {
  //   // Logic when Round Robin is DISABLED or KEY DOES NOT EXIST
  // }
    

    // 10) AI auto-reply — runs last, after the visitor's message is stored/broadcast.
    const AICHATS = true; // master switch; set false to disable AI replies
    if (AICHATS) {
      console.log("[type1] dispatching AI auto-reply");
      await aiAutoReply({
        subdomain,
        arg,
        visitorSocket: mysocket, // the visitor's live socket
        clientKey, // `${arg.id}-website-${arg.username}-${arg.email}-${subdomain}`
        chatKey, // `${subdomain}-external-${arg.email}-${arg.id}`
      });
    }

    console.log("[type1] done", { msgId: arg.msgId });
  } catch (err) {
    console.error("[type1] ERROR", err);
  }
}

async function handleWebsiteAgentMessage({
  arg,
  subdomain,
  mysocket,
  myextension,
}) {
  await createSearchIndex(subdomain);

  const clientKey = `${arg.id}-website-${arg.username}-${arg.useremail}-${subdomain}`;
  const agentKey = `${arg.agent_ID}-Agent-${arg.agent}-${arg.agent_email}-${subdomain}`;

  if (arg.defaultChatSound) {
    const isValid = await chatSettingsChecker(arg, subdomain, mysocket);
    console.log("[type2] chat settings validation", {
      clientKey,
      isValid: isValid != 0,
    });
    if (isValid == 0) {
      console.log("[type2] stopped: chat settings mismatch", { clientKey });
      return;
    }
  }

  const [clientUser, agentUser] = await Promise.all([
    getRedisHash(clientKey),
    getRedisHash(agentKey),
  ]);

  if (!clientUser) {
    console.log("[type2] No client found:", clientKey);
    return;
  }
  if (!agentUser) {
    console.log("[type2] No agent found:", agentKey);
    return;
  }

  const previousClientState = {
    assigned_agent: clientUser.assigned_agent,
    allocated_room: clientUser.allocated_room,
    client_unread: clientUser.client_unread,
    agent_unread: clientUser.agent_unread,
    aiEnable: clientUser.aiEnable,
    msgIndex: clientUser.msgIndex,
    last_message: clientUser.last_message,
  };

  // A valid human reply takes over the conversation. Do not return when AI is
  // enabled: hSet intentionally overwrites "true" with the Redis string "false".
  const previousAiEnable = clientUser.aiEnable;
  console.log("[type2][redis] overwriting client AI state", {
    key: clientKey,
    field: "aiEnable",
    previousValue: previousAiEnable ?? "<missing>",
    nextValue: "false",
  });
  const aiUpdateResult = await redisClient.hSet(clientKey, {
    aiEnable: "false",
  });
  console.log("[type2][redis] client AI state written", {
    key: clientKey,
    hSetResult: aiUpdateResult,
    storedValue: await redisClient.hGet(clientKey, "aiEnable"),
  });
  clientUser.aiEnable = "false";

  // Preserve the current owner. The replying agent may claim the conversation
  // only when the client has no assigned agent yet.
  const existingAssignedAgents = safeParseJSON(clientUser.assigned_agent).filter(
    Boolean,
  );
  const isUnassigned = existingAssignedAgents.length === 0;
  const assignedAgents = isUnassigned
    ? [agentKey]
    : existingAssignedAgents;
  const assignedAgentsString = JSON.stringify(assignedAgents);
  const activeAgentKey = assignedAgents[0] || agentKey;
  const activeAgent = (await getRedisHash(activeAgentKey)) || agentUser;

  console.log("[type2] assignment decision", {
    clientKey,
    replyingAgent: agentKey,
    existingAssignedAgents,
    activeAgent: activeAgentKey,
    action: isUnassigned ? "CLAIM_UNASSIGNED_CLIENT" : "KEEP_EXISTING_AGENT",
  });

  if (isUnassigned) {
    await updateAssignedAgentList(agentKey, clientKey, agentUser);
    await removeClientFromPreviousAgent(arg.prevagent, clientKey, agentKey);
  }

  clientUser.id = arg.id;
  clientUser.assigned_agent = assignedAgentsString;
  clientUser.agent_unread = 0;
  clientUser.client_unread = Number(clientUser.client_unread || 0) + 1;
  clientUser.allocated_room = "ACTIVE";

  await updateConversationStatus(subdomain, clientUser);

  const time = Date.now();
  const chatKey = `${subdomain}-external-${arg.useremail}-${arg.id}`;
  const agentProfile = await getAgentProfile(
    myextension,
    subdomain,
    agentUser,
  );
  const outgoing = await buildWebsiteAgentMessages({
    arg,
    subdomain,
    time,
  });

  if (outgoing.length === 0) {
    console.log("[type2] No text or file to send");
    return;
  }

  const payloads = [];
  let msgIndex = Number(clientUser.msgIndex || 0);

  for (const item of outgoing) {
    msgIndex += 1;
    await persistWebsiteAgentMessage({
      item,
      chatKey,
      clientKey,
      clientUser,
      agentUser,
      agentProfile,
      myextension,
      subdomain,
      msgIndex,
    });
    payloads.push(
      buildWebsiteAgentPayload({
        item,
        arg,
        clientUser,
        agentUser,
        agentProfile,
        assignedAgentsString,
        mysocket,
        myextension,
        subdomain,
      }),
    );
  }

  await saveWebsiteAgentConversationLogs({
    outgoing,
    chatKey,
    clientUser,
    agentUser,
    agentEmail: arg.agent_email,
    subdomain,
    time,
  });

  const lastItem = outgoing[outgoing.length - 1];
  const uploadedItem = outgoing.find((item) => item.hasFile);
  const clientUpdate = {
    timestamp: String(time),
    last_message: uploadedItem?.lastMessage || lastItem.lastMessage,
    message: lastItem.message,
    isReceived: "0",
    agent_unread: "0",
    client_unread: String(clientUser.client_unread),
    // Do not rewrite ownership when another agent already owns the client.
    ...(isUnassigned && { assigned_agent: assignedAgentsString }),
    extAgentName: activeAgent.Agent_Name || "",
    extAgentExtension: activeAgent.extension || "",
    allocated_room: "ACTIVE",
    chatUpdated: String(lastItem.time),
    msgIndex: String(msgIndex),
    aiEnable: "false",
  };
  console.log("[type2][redis] overwriting client conversation", {
    key: clientKey,
    previous: previousClientState,
    update: clientUpdate,
  });
  const clientUpdateResult = await redisClient.hSet(clientKey, clientUpdate);
  console.log("[type2][redis] client conversation written", {
    key: clientKey,
    hSetResult: clientUpdateResult,
    assignedAgent: assignedAgents,
    messageCount: outgoing.length,
  });

  if (
    activeAgentKey === agentKey &&
    Number(clientUser.isReceived || 0) === 0
  ) {
    const nextAgentUnread = Math.max(
      0,
      Number(agentUser.agent_unread || 0) - 1,
    );
    console.log("[type2][redis] overwriting agent unread count", {
      key: agentKey,
      previousValue: agentUser.agent_unread ?? "<missing>",
      nextValue: nextAgentUnread,
    });
    const unreadUpdateResult = await redisClient.hSet(agentKey, {
      agent_unread: nextAgentUnread,
    });
    console.log("[type2][redis] agent unread count written", {
      key: agentKey,
      hSetResult: unreadUpdateResult,
    });
  } else if (activeAgentKey !== agentKey) {
    console.log("[type2][redis] agent unread update skipped", {
      replyingAgent: agentKey,
      activeAgent: activeAgentKey,
      reason: "replying agent does not own this conversation",
    });
  }

  await redisClient.zRem(
    `${subdomain}-overallUnreadCount`,
    `unread-${clientKey}`,
  );
  const overallUnreadCount = await redisClient.zCard(
    `${subdomain}-overallUnreadCount`,
  );

  if (arg.prevagent && arg.prevagent !== agentKey) {
    pushWeb(
      lastItem.message,
      clientKey,
      subdomain,
      agentUser.Agent_Name,
      "website",
      "agent",
    );
  }

  await emitChatUpdates({
    subdomain,
    clientKey,
    payloads,
    overallUnreadCount,
  });
}

async function removeClientFromPreviousAgent(prevAgentKey, clientKey, agentKey) {
  if (!prevAgentKey || prevAgentKey === agentKey) return;

  const previousAgent = await getRedisHash(prevAgentKey);
  if (!previousAgent) return;

  const assignedClients = safeParseJSON(previousAgent.assigned).filter(
    (key) => key.trim() !== clientKey.trim(),
  );
  const update = {
    assigned: JSON.stringify(assignedClients),
  };
  console.log("[type2][redis] overwriting previous agent assignments", {
    key: prevAgentKey,
    previousValue: previousAgent.assigned ?? "<missing>",
    nextValue: update.assigned,
  });
  const hSetResult = await redisClient.hSet(prevAgentKey, update);
  console.log("[type2][redis] previous agent assignments written", {
    key: prevAgentKey,
    hSetResult,
  });
}

async function buildWebsiteAgentMessages({ arg, subdomain, time }) {
  const outgoing = [];

  if (arg.fileInfo?.length) {
    const uploaded = await saveUploadedFile(arg, subdomain, time);
    outgoing.push({
      ...uploaded,
      uid: await generateRandomUniqueId(),
      time,
      fileInfo: arg.fileInfo,
    });
  }

  if ((arg.message || "").trim()) {
    outgoing.push({
      message: arg.message,
      lastMessage: arg.message,
      uid: await generateRandomUniqueId(),
      time: time + outgoing.length,
      hasFile: false,
    });
  }

  return outgoing;
}

async function persistWebsiteAgentMessage({
  item,
  chatKey,
  clientKey,
  clientUser,
  agentUser,
  agentProfile,
  myextension,
  subdomain,
  msgIndex,
}) {
  const listRecord = [
    item.message,
    String(item.time),
    `${myextension}-Agent`,
    agentProfile,
    item.uid,
  ];
  console.log("[type2][message] writing Redis list record", {
    chatKey,
    uid: item.uid,
    msgIndex,
    lPushArgumentOrder: ["message", "time", "sender", "profile", "uid"],
    lRangeStoredOrder: ["uid", "profile", "sender", "time", "message"],
    record: listRecord,
  });
  const listLength = await redisClient.lPush(chatKey, listRecord);
  console.log("[type2][message] Redis list record written", {
    chatKey,
    uid: item.uid,
    listLength,
  });
  await redisClient.hSet(clientKey, { msgIndex: String(msgIndex) });
  console.log("[type2][message] writing search record", {
    chatKey,
    clientKey,
    uid: item.uid,
    msgIndex,
  });
  await saveMessageForSearch(
    item.message,
    String(item.time),
    String(agentUser.Agent_Name || ""),
    String(agentUser.email || ""),
    "agent",
    item.uid,
    String(clientUser.appId || ""),
    String(clientUser.pageId || ""),
    chatKey,
    "website",
    clientKey,
    item.message,
    String(item.time),
    `${myextension}-Agent`,
    agentProfile,
    item.uid,
    subdomain,
  );
  console.log("[type2][message] search record write completed", {
    chatKey,
    clientKey,
    uid: item.uid,
    msgIndex,
  });
}

async function saveWebsiteAgentConversationLogs({
  outgoing,
  chatKey,
  clientUser,
  agentUser,
  agentEmail,
  subdomain,
  time,
}) {
  const baseLog = {
    date: String(time),
    username: `${agentUser.Agent_Name} (${agentUser.extension})`,
    email: agentEmail,
    to: clientUser.username || "",
  };

  await conversationLogsAdd(
    "agent",
    {
      ...baseLog,
      messageType: clientUser.messageType ?? "website",
      conversationsFetch: chatKey,
    },
    subdomain,
  );

  for (const item of outgoing) {
    const message = item.hasFile
      ? `https://${subdomain}/${item.filePath}`
      : item.message;
    await conversationLogsAddExtension(
      "agent",
      { ...baseLog, message },
      chatKey,
    );
  }
}

function buildWebsiteAgentPayload({
  item,
  arg,
  clientUser,
  agentUser,
  agentProfile,
  assignedAgentsString,
  mysocket,
  myextension,
  subdomain,
}) {
  return {
    agentProfile,
    uid: item.uid,
    socketId: mysocket,
    username: `${myextension}-Agent`,
    name: clientUser.username,
    useremail: arg.useremail,
    channel: subdomain,
    message: item.message,
    ...(item.hasFile && {
      filePath: item.filePathContainer,
      fileInfo: item.fileInfo,
    }),
    time: String(item.time),
    allocated_room: "ACTIVE",
    client_unread: clientUser.client_unread,
    agent_unread: 0,
    assigned_agent: assignedAgentsString,
    roomID: agentUser.agentID || agentUser.id,
    last_message: item.lastMessage,
    messageType: "website",
    id: arg.id,
    agentName: agentUser.Agent_Name,
    appId: clientUser.appId,
    pageId: clientUser.pageId,
    msgId: item.uid,
    chatUpdated: String(item.time),
    ...(arg.queueId && { queueId: arg.queueId }),
  };
}

// Global Helpers

function isValidArgument(argument) {
  return (
    argument &&
    argument !== "undefined" &&
    argument.arg &&
    argument.arg !== "undefined" &&
    argument.mysocket &&
    argument.mysocket !== "undefined"
  );
}

async function getRedisHash(key) {
  const exists = await redisClient.exists(key);
  return exists ? await redisClient.hGetAll(key) : null;
}

function safeParseJSON(value, fallback = []) {
  try {
    const parsed =
      typeof value === "string" ? JSON.parse(value || "[]") : value;
    return Array.isArray(parsed) ? parsed : fallback;
  } catch {
    return fallback;
  }
}

async function updateAssignedAgentList(agentKey, clientKey, agentUser) {
  const assignedClients = safeParseJSON(agentUser.assigned);

  const filteredClients = assignedClients.filter(
    (x) => x.trim() !== clientKey.trim(),
  );

  filteredClients.unshift(clientKey);

  const update = {
    assigned: JSON.stringify([...new Set(filteredClients)]),
  };
  console.log("[type2][redis] overwriting assigned agent client list", {
    key: agentKey,
    previousValue: agentUser.assigned ?? "<missing>",
    nextValue: update.assigned,
  });
  const hSetResult = await redisClient.hSet(agentKey, update);
  console.log("[type2][redis] assigned agent client list written", {
    key: agentKey,
    hSetResult,
  });
}

function writeFileAsync(path, data) {
  return new Promise((resolve, reject) => {
    writeFile(path, data, (err) => {
      if (err) reject(err);
      else resolve();
    });
  });
}

async function emitAgentTotalUnread(
  subdomain,
  clientKey,
  agentKey,
  overallUnreadCount,
  extAgentData,
) {
  console.log("totalUnread", subdomain, clientKey, extAgentData);
  const clientUser = await redisClient.hGetAll(clientKey);

  const agentUnreadKey = `unread-${agentKey}`;
  const clientUnreadKey = `unread-${clientKey}`;

  await redisClient.zRem(`${agentUnreadKey}`, `${clientUnreadKey}`);

  const agentUnreadCount = await redisClient.zCard(agentUnreadKey);

  let totalRead = {
    agent_unread: overallUnreadCount,
  };

  if (
    clientUser.assigned_agent &&
    clientUser.assigned_agent !== "" &&
    clientUser.assigned_agent !== "[]"
  ) {
    totalRead.total = agentUnreadCount;
    totalRead.extension = extAgentData.extension;

    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      totalRead,
      `${subdomain}-all_messages`,
    );
  } else {
    socketSignal.emit(
      "chatParameters",
      "queue_updatedata",
      totalRead,
      `${subdomain}-all_messages`,
    );
  }
}

// async function assignedRoundRobin(clientKey, user, fqdnValue) {
//   let assignedAgentRoundRobin = "";
//   const noAssignedList = await redisClient.zRange(
//     `${fqdnValue}-noAssigned`,
//     0,
//     0,
//   );
//   const enableOwner = await redisClient.get(
//     `chatsettings:${fqdnValue}-enableOwnerManagerAssign`,
//   );
//   const enableRoundRobin = await redisClient.get(
//     `chatsettings:${fqdnValue}-enableRoundRobin`,
//   );
//   const enableEveryAgentQ = await redisClient.get(
//     `chatsettings:${fqdnValue}-enableEveryAgentQ`,
//   );
//   // round robin
//   if (enableRoundRobin == 1 && noAssignedList.length > 0) {
//     assignedAgentRoundRobin = noAssignedList[0];

//     // Remove them from the pool (or update score to move to back)
//     await redisClient.zRem(`${fqdnValue}-noAssigned`, assignedAgentRoundRobin);

//     const agentKey = assignedAgentRoundRobin;
//     agentUser = await redisClient.hGetAll(agentKey);

//     if (agentUser) {
//       // Prepare agent's assignment list
//       let currentAgentAssigned = [];
//       try {
//         currentAgentAssigned = JSON.parse(agentUser.assigned || "[]");
//       } catch {
//         currentAgentAssigned = [];
//       }

//       // Add client to agent
//       if (!currentAgentAssigned.includes(clientKey)) {
//         currentAgentAssigned.unshift(clientKey);
//       }

//       await redisClient.hSet(agentKey, {
//         assigned: JSON.stringify(currentAgentAssigned),
//       });

//       // Add agent to client
//       await redisClient.hSet(clientKey, {
//         assigned_agent: JSON.stringify([agentKey]), // Simplified if single assignment
//       });

//       user.assigned_agent = JSON.stringify([agentKey]);
//       // Handle Unread Logic
//       const agentUnreadKey = `unread-${agentKey}`;
//       await redisClient.zAdd(agentUnreadKey, {
//         score: Date.now(),
//         value: `unread-${clientKey}`,
//       });

//       const agentTotalUnread = await redisClient.zCard(agentUnreadKey);
//       const overallUnread = await redisClient.zCard(
//         `${fqdnValue}-overallUnreadCount`,
//       );

//       result = await redisClient.zCard(`${agentUnreadKey}`);

//       // Emit update
//       socketSignal.emit(
//         "chatParameters",
//         "queue_updatedata",
//         {
//           agent_unread: overallUnread,
//           total: agentTotalUnread,
//           extension: agentUser.extension,
//         },
//         `${fqdnValue}-all_messages`,
//       );
//     }
//   } else if (enableRoundRobin == 1 && noAssignedList.length == 0) {
//     // NO AGENT AVAILABLE: Add client to a pending queue
//     // RPUSH adds to the end of the list (First-In, First-Out)
//     user.assigned_agent = "";
//     await redisClient.rPush(`${fqdnValue}-waitingQueue`, clientKey);
//     console.log(
//       `🕒 No agents free. Client ${clientKey} added to waiting queue.`,
//     );

//     // Optional: Emit to the client that they are in a queue
//     //socketSignal.emit("queueStatus", { position: "waiting" }, clientKey);
//   } else {
//     user.assigned_agent = "";
//   }
//   console.log("befoere return", user);
//   return user.assigned_agent;
// }


// async function assignedRoundRobin(clientKey, fqdnValue) {
//   const enableRoundRobin = await redisClient.get(
//     `chatsettings:${fqdnValue}-enableRoundRobin`
//   );

//   // 1. If 1: Add clientKey to the sorted set
//   if (enableRoundRobin === "1" || enableRoundRobin === 1) {
//     await redisClient.zAdd(`${fqdnValue}-noAssigned`, {
//       score: Date.now(),
//       value: clientKey,
//     });

//   // 2. If 0: Remove clientKey from the sorted set
//   } else if (enableRoundRobin === "0" || enableRoundRobin === 0) {
//     await redisClient.zRem(`${fqdnValue}-noAssigned`, clientKey);

//   // 3. If null/undefined: Delete the whole sorted set key
//   } else {
//     await redisClient.del(`${fqdnValue}-noAssigned`);
//   }

// }
