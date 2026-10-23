require('./quietlog'); // [perf] summarise big objects in console.log
require('./servermode')('status'); // [server-mode] production/debug logging, see servermode.js
const pushOwner = require("./pushOwner"); // [ring-push]
const redis = require("redis");
const { Server } = require("socket.io");

(async () => {

  let redisClient

  (async () => {
    redisClient = redis.createClient({
      socket: {
        path: "/var/run/redis/redis-server.sock",
      },
    });
    redisClient.on("error", (error) => console.error(`Error : ${error}`));
    await redisClient.connect();
  })();
  
  console.log("✅ Redis connected");

  // [ext-access] no new-call push to an extension that is switched off (extoff) or whose Mobile App
  // permission is off (disableapp); stop-ringing pushes (decline/missed/answered) still go out.
  async function pushBlocked(ext, domain) {
    try {
      const [a, b] = await Promise.all([
        redisClient.exists(`disableapp:${ext}:${domain}`),
        redisClient.exists(`extoff:${ext}:${domain}`),
      ]);
      return a > 0 || b > 0;
    } catch (e) { return false; }
  }

  const sso = new Server({
    pingInterval: 25000, // send ping every 25s
    pingTimeout: 60000,  // disconnect if no pong for 60s
  });

  sso.listen(4060);
  console.log("✅ Socket.IO server listening on port 4060");

  sso.use((socket, next) => {
    next();
  });

  sso.on("connection", async (socket) => {

    const { extension, channel, device_uuid } = socket.handshake.auth;
    socket.data = {
      ext: extension,
      chan: channel,
      uuid: device_uuid,
    };
    console.log(`<!-- CONNECTED ${socket.id} -- ${socket.data.uuid}, ${extension}->${channel}->${device_uuid}`);

    if(socket.data){

          const deviceState = await redisClient.get(`deviceCurrentStatus-${socket.data.ext}@${socket.data.chan}-${socket.data.uuid}`);
          if(deviceState === 'deviceOn'){
              await redisClient.sAdd(`deviceOn-${socket.data.ext}@${socket.data.chan}`, socket.data.uuid);
              await redisClient.del(`deviceCurrentStatus-${socket.data.ext}@${socket.data.chan}-${socket.data.uuid}`)
          }

          if(deviceState === 'deviceOff'){
              await redisClient.sAdd(`deviceOff-${socket.data.ext}@${socket.data.chan}`, socket.data.uuid);
              await redisClient.del(`deviceCurrentStatus-${socket.data.ext}@${socket.data.chan}-${socket.data.uuid}`)
          }

          (async () => {
            try {
                const baseKey = `${extension}@${channel}`;
                const uuidKey = `sockets-uuid${device_uuid}-${baseKey}`;

                // 🔥 1. HARD CLEAN OLD SOCKETS (per device)
                const oldSockets = await redisClient.sMembers(uuidKey);
                for (const id of oldSockets) {
                if (!sso.sockets.sockets.has(id)) {
                    await redisClient.sRem(uuidKey, id);
                    await redisClient.sRem(`sockets-${baseKey}`, id);
                }
                }

                // 🔥 2. ADD CURRENT SOCKET
                await redisClient.sAdd(`sockets-${baseKey}`, socket.id);
                await redisClient.sAdd(uuidKey, socket.id);

                // 🔥 3. SINGLE SOURCE POINTER (optional but OK)
                await redisClient.set(
                `sockets-uuidSet${device_uuid}-${baseKey}`,
                socket.id
                );

                // 🔥 4. FINAL SAFETY CLEAN (per extension)
                const sockets = await redisClient.sMembers(`sockets-${baseKey}`);
                for (const id of sockets) {
                if (!sso.sockets.sockets.has(id)) {
                    await redisClient.sRem(`sockets-${baseKey}`, id);
                }
                }

            } catch (err) {
                console.error("Redis error on connect:", err);
            }
          })();

          socket.on("app-connectivity", async (payload, callback) => {

            const {
                channel,
                extension,
                device_uuid
            } = payload
            console.log("got this in appcon", channel, extension)
            const androidDeviceOn =  await redisClient.sMembers(`deviceOff-${extension}@${channel}`);
            const otherDevices = androidDeviceOn.filter(v => v === device_uuid);

            if(otherDevices.length){
              console.log(`😐${extension}@${channel} : ${socket.id} -- ${device_uuid}`, otherDevices);
              try {
                await redisClient.sAdd(`sockets-${extension}@${channel}`, socket.id);
              } catch (err) {
                console.error("Redis error on app-connectivity:", err);
              }
            }
            
          });

          socket.on("app-answered", async (payload, callback) => {
            console.log(`<!-- ANSWERED ${socket.data.ext}@${socket.data.chan} : ${socket.id} -- ${socket.data.uuid}`);
            try {
              await redisClient
                .multi()
                .del(`sockets-${socket.data.ext}@${socket.data.chan}`)
                .set(`sockets-answered${socket.data.ext}@${socket.data.chan}`, socket.data.uuid)
                .expire(`sockets-answered${socket.data.ext}@${socket.data.chan}`, 5)
                .exec();
            } catch (err) {
              console.error("Redis error on app-answered:", err);
            }
          });

          socket.on("push-call", async (payload, callback) => {

            console.log("📞 push-call payload:", payload);
            const {extension : pushExtension, domain : pushDomain, device_uuid : pushDeviceId} = payload
            // [ring-push] the new ring push (mod_ringq) rings this domain's phones: no old Android push
            if (true) { // [old-push] retired: ring push rings the phones
              if (typeof callback === "function") callback([]);
              return;
            }

            const offlineList = [];
            try {

              const baseKey = `${pushExtension}@${pushDomain}`;
              const sockets = await redisClient.sMembers(`sockets-${baseKey}`);
              const exists = await redisClient.exists(`sockets-answered${baseKey}`);
              console.log(`socketkeys`, `sockets-answered${baseKey}: ${exists}`, `sockets-${baseKey}:${sockets}`)
              const exemptions = exists
                ? await redisClient.sMembers(`sockets-uuid${pushDeviceId}-${baseKey}`)
                : [];
              const targets = exists
                ? sockets.filter((id) => !exemptions.includes(id))
                : sockets;
              // [devices] Android apps switched to "Calls off" / deleted: no new-call alert
              const STOP = ['decline', 'missed', 'answered', 'answered2'];
              const mutedA = STOP.includes(payload.call) ? [] : await redisClient.sMembers(`devMuted-${baseKey}`).catch(() => []);
              const isMuted = (id) => { const sk = sso.sockets.sockets.get(id); return !!(sk && sk.data && mutedA.includes(sk.data.uuid)); };
              const blockedA = !STOP.includes(payload.call) && await pushBlocked(pushExtension, pushDomain); // [ext-access]
              await Promise.all(
                sockets.filter((id) => !blockedA && !isMuted(id)).map( async (id) => {
                  const isOnline = sso.sockets.sockets.has(id);
                  console.log(`🐢`, isOnline, payload);
                  if (isOnline) {
                    sso.to(id).emit("app-connectivity", payload);
                    console.log("this is the socket id", id);
                    offlineList.push('success');
                  }else{
                    console.log(`❌ Socket ${id} is offline. Removing from Redis.`);
                    await redisClient.sRem(`sockets-${baseKey}`, id);
                    offlineList.push('failed');
                  }
                  sso.to(id).emit("app-connectivity", payload)
                })
              );

              if(!targets.length){
                offlineList.push('failed');
                console.log(`❌ Socket is offline.`);
              }
              console.log("➡️ Sent to sockets:", targets);
            } catch (err) {
              console.error("Redis error on push-call:", err);
            }

            if(callback){
              console.log("this if offline")
              callback(offlineList);
            }

          });

          socket.on("push-queue", async (payload, callback) => {

            console.log("📞 push-queue payload:", payload);
            const {domain : pushQDomain} = payload
            console.log("payloadlength", payload.agents.length)
            // if (payload.agents.length == 0){
            //     payload.agents.push("1135");
            //     console.log("logs add 1135")
            // }
            if (payload.call !== "decline") {
              try {
                for (const agent of payload.agents) {
                  const mutedQA = await redisClient.sMembers(`devMuted-${agent}@${pushQDomain}`).catch(() => []); // [devices]
                  if (await pushBlocked(agent, pushQDomain)) continue; // [ext-access]
                  const sockets = (await redisClient.sMembers(`sockets-${agent}@${pushQDomain}`)).filter((id) => { const sk = sso.sockets.sockets.get(id); return !(sk && sk.data && mutedQA.includes(sk.data.uuid)); });
                  console.log(`smembers: ${`sockets-${agent}@${pushQDomain}`}`)
                  console.log(`➡️ push-queue to ${agent}:`, sockets);
                  await Promise.all(
                    sockets.map((id) => sso.to(id).emit("app-connectivity", payload))
                  );
                }
              } catch (err) {
                console.error("Redis error on push-queue:", err);
              }
            }
          });

          socket.on("disconnect", async (reason) => {
            console.log(`--!> DISCONNECTED ${reason} ${socket.data.ext}@${socket.data.chan} : ${socket.id} -- ${socket.data.uuid}`);
            
                // Optional: Remove socket ID from Redis here if needed
                await redisClient.sRem(`sockets-${socket.data.ext}@${socket.data.chan}`, socket.id);
                await redisClient.sRem(`sockets-uuid${socket.data.uuid}-${socket.data.ext}@${socket.data.chan}`, socket.id);

                let isStillConnected = false;
                const sockets = await redisClient.sMembers(`sockets-${socket.data.ext}@${socket.data.chan}`);
                for (var x = 0; x < sockets.length; x++) {
                  if (!sso.sockets.sockets.has(sockets[x])) {
                    await redisClient.sRem(`sockets-${socket.data.ext}@${socket.data.chan}`, sockets[x]);
                  } else {
                    isStillConnected = true;
                  }
                }

                if(reason === 'ping timeout'){

                  const deviceOn =  await redisClient.sMembers(`deviceOn-${socket.data.ext}@${socket.data.chan}`);
                  console.log('A1', deviceOn)
                  if(deviceOn && deviceOn.length){
                      const key = deviceOn.find(x => x === socket.data.uuid);
                      if(key){
                        await redisClient.set(`deviceCurrentStatus-${socket.data.ext}@${socket.data.chan}-${socket.data.uuid}`, 'deviceOn');
                      }
                  }

                  const deviceOff =  await redisClient.sMembers(`deviceOff-${socket.data.ext}@${socket.data.chan}`);
                  console.log('A2', deviceOff)
                  if(deviceOff && deviceOff.length){
                      const key = deviceOff.find(x => x === socket.data.uuid);
                      if(key){
                          await redisClient.set(`deviceCurrentStatus-${socket.data.ext}@${socket.data.chan}-${socket.data.uuid}`, 'deviceOff');
                      }
                  }
                  await redisClient.sRem(`deviceOff-${socket.data.ext}@${socket.data.chan}`, socket.data.uuid);
                  await redisClient.sRem(`deviceOn-${socket.data.ext}@${socket.data.chan}`, socket.data.uuid);

                }

          });

    }

      socket.on("removeStatusAndroid", async (arg) => {

        console.log("LogOutTrigger2", arg);
        const {extension : logOutExtension, subdomain, socketId} = arg;
        const baseKey = `${logOutExtension}@${subdomain}`;
        if(socketId){
          await redisClient.sRem(`sockets-${baseKey}`, socketId);
        }

      });

  });

})();