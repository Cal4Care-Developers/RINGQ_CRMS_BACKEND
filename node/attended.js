require('./quietlog'); // [perf] summarise big objects in console.log
require('./servermode')('attended'); // [server-mode] production/debug logging, see servermode.js
const esl = require("modesl");
const journey = require("./callJourney"); // [call-journey]
const pushOwner = require("./pushOwner"); // [ring-push] old pushes stay quiet where the new ring push is in charge
const redis = require("redis");
const { Server } = require("socket.io");
const { io } = require("socket.io-client");
require('dotenv').config({ path: '/etc/ringq/.env' });
const { v4: uuidv4, validate } = require("uuid");
const WebSocket = require('ws');

const pg = require("pg");
const { Pool, Client } = pg;

const pool = new Pool({
     user: process.env.PostgreSQL_DB_USER,
     host: process.env.PostgreSQL_DB_HOST,
     database: process.env.PostgreSQL_DB_NAME,
     password: process.env.PostgreSQL_DB_PASSWORD,
     port: Number(process.env.PostgreSQL_DB_PORT),
});

pool.connect((err, client, release) => {
    if (err) {
        console.error('Error acquiring client', err.stack);  
        return;
    }else{
        console.log('Postgress Database connected successfully!');
    }
});

let redisClient;
let sharedWs = null;
const pendingCallbacks = new Map(); // apnsId -> { resolve, reject, timer }

/** SOCKET SERVERS */
const socketClient = new io("ws://127.0.0.1:3000", {
     autoConnect: true,
     reconnectionDelayMax: 1000,
});
socketClient.connect();

const socketClient2 = new io("ws://127.0.0.1:4060", {
     autoConnect: true,
     reconnectionDelayMax: 1000,
});
socketClient2.connect();


const socketClientQueue = new io("ws://127.0.0.1:4072", {
     autoConnect: true,
     reconnectionDelayMax: 1000,
});

socketClientQueue.connect();
/** --- END --- */

const conn = new esl.Connection('127.0.0.1', 8021, 'ClueCon');

conn.on('ready', () => {
  console.log('ESL connection established.');
});

conn.on('error', (err) => {
  console.error('ESL connection error:', err);
});

conn.on('end', () => {
  console.warn('ESL connection ended.');
});

const attended = new Server({
  maxHttpBufferSize: 6e6
});

(async () => {
  redisClient = redis.createClient();
  redisClient.on("error", (error) => console.error(`Error : ${error}`));
  await redisClient.connect();
})();

attended.listen(4070);
attended.use(async (socket, next) => {
  if (socket.handshake.headers["user-agent"] == "node-XMLHttpRequest") {
    next();
  } else {
    next();
  }
}).on("connection", (socket) => {

     socket.on("node-parkAttended", async (payload, callback) => {

            console.log('PAYLOAD =>', payload);
    
            const time1 = (Date.now() * 1000).toString();
    
            const arg = payload["arg"];
            const subdomain = payload["subdomain"];
            const presenceExtension = payload["presenceExtension"];
            const SIP = await redisClient.get(`SIP-${presenceExtension}@${subdomain}`);
    
            try {
    
                const res = await promisifiedApi(conn, 'show calls as json');
                const { row_count, rows } = JSON.parse(res.body);
                if (!rows.length) return;
    
                // Process rows in parallel with proper async handling
                const processedRows = await Promise.all(rows.map(async (x) => {
                const update = {};
                
                    // Helper function for processing presence IDs
                    const processPresence = async (presenceKey, subdomain) => {
                        const presenceValue = x[presenceKey];
                        if (presenceValue?.includes(subdomain)) {
                        const [extension] = presenceValue.split('@');
                        if (!isValidNumber(extension)) {
                            const response = await redisClient.get(`extension-${extension}@${subdomain}`);
                            if(response){
                                const { extension: validExtension } = JSON.parse(response);
                                return `${validExtension}@${subdomain}`;
                            }
                            console.log(`extension-${extension}@${subdomain} 1`)
                        }
                        }
                        return presenceValue; // Return original if no changes
                    };
    
                    // Process both presence fields
                    update.presence_id = await processPresence('presence_id', subdomain);
                    update.b_presence_id = await processPresence('b_presence_id', subdomain);
                    return { ...x, ...update };
                }));
            
                console.log('RESPONSE ATTENDED TESTING', {
                    test: processedRows,
                    new: processedRows.filter(row => row), // Filter out undefined entries
                    SIP
                });
    
                /** name */
                // "ChannelName-1000@multi.flowersconradplc.ringq.cloud"
                const channelName = await redisClient.sMembers(`ChannelName-${presenceExtension}@${subdomain}`);
                // /** end */
                
                const currentPresence = `${presenceExtension}@${subdomain}`;
                // const getInternalName = (name = '') => {
                //     return name.split('internal-udp/')[1] || name.split('internal/')[1];
                // };
                // const isCurrentChannel = (name) => {
                //     const internalName = getInternalName(name);
                //     return Boolean(internalName && channelName.includes(internalName));
                // };

                // console.log("THESE ARE PROCESSED ROWS", processedRows);
                // console.log("currentPresence: ", currentPresence); 

                // const currentCallA = processedRows.find(el =>
                //     el.presence_id === currentPresence || isCurrentChannel(el.name)
                // );

                // const currentCallB = currentCallA ? null : processedRows.find(el =>
                //     el.b_presence_id === currentPresence || isCurrentChannel(el.b_name)
                // );

                // if(!currentCallA && !currentCallB){
                //     console.log("No attended transfer call found for", currentPresence);
                //     return;
                // }


              const getInternalName = (name = '') => {
                    return name.split('internal-udp/')[1] || name.split('internal/')[1];
                };

                const isCurrentChannel = (name) => {
                    const internalName = getInternalName(name);
                    return Boolean(internalName && channelName.includes(internalName));
                };

                console.log("THESE ARE PROCESSED ROWS", processedRows);
                console.log("currentPresence:", currentPresence);

                // B-leg has priority
                const currentCallB = processedRows.find(el =>
                    el.b_presence_id === currentPresence ||
                    isCurrentChannel(el.b_name)
                );

                // Only check A-leg if B-leg did not match
                const currentCallA = currentCallB ? null : processedRows.find(el =>
                    el.presence_id === currentPresence ||
                    isCurrentChannel(el.name)
                );

                if (currentCallB) {
                    console.log("🔥currentCallB🔥", currentCallB);
                } else if (currentCallA) {
                    console.log("🔥currentCallA🔥", currentCallA);
                }
                // console.log("currentCallB:", currentCallB);
                // console.log("currentCallA:", currentCallA);

                
                 if(currentCallA){
                  
                                  console.log(`🔥currentCallA🔥`);
                          

                                  await promisifiedApi(conn, `uuid_setvar ${currentCallA.uuid} hangup_after_bridge false`)
                                  conn.api(`uuid_park ${currentCallA.b_uuid}`)
                                  conn.api(`uuid_park ${currentCallA.uuid}`)
                  
                                  const [existsB, existsA] = await Promise.all([
                                      promisifiedApi(conn, `uuid_exists ${currentCallA.b_uuid}`),
                                      promisifiedApi(conn, `uuid_exists ${currentCallA.uuid}`)
                                  ]);
                                  console.log(`K1 Params`, existsB, existsA);
                                  const bLegCall = existsB.body === 'true';
                                  const aLegCall = existsA.body === 'true';
                  
                                  if(bLegCall && aLegCall){
                                      console.log("transfer 1");
                                      console.log(`Check these uuids ${currentCallA.uuid} ${currentCallA.b_uuid}`);
                                      
                                      conn.api(`uuid_exists ${currentCallA.uuid}`, (res) => console.log(`existB->☎️☎️ ${currentCallA.uuid}`,res));
                                      conn.api(`uuid_exists ${currentCallA.b_uuid}`, (res) => console.log(`existA->☎️☎️ ${currentCallA.b_uuid}`,res))
                                      

                                      await promisifiedApi(conn, `uuid_broadcast ${currentCallA.uuid} misc/Please-wait_while_we_transfer.wav`);
                                      await promisifiedApi(conn, `uuid_broadcast ${currentCallA.uuid} ${await mohForUuid(currentCallA.uuid)}`);
                                      await promisifiedApi(conn, `sched_hangup +3600 ${currentCallA.uuid} ALLOTTED_TIMEOUT`);


                                    //   await promisifiedApi(conn, `uuid_broadcast ${currentCallB.uuid} misc/Please-wait_while_we_transfer.wav`);
                                    // await promisifiedApi(conn, `uuid_broadcast ${currentCallB.uuid} ${await mohForUuid(currentCallB.uuid)}`);
                                    // await promisifiedApi(conn, `sched_hangup +3600 ${currentCallB.uuid} ALLOTTED_TIMEOUT`);
                                      //Added 09-01-26
                                      //await promisifiedApi(conn, `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`);
              
                                      const androidDeviceOff = await redisClient.sMembers(`deviceOff-${payload.arg}@${payload.subdomain}`);
                                      const iosDevicePush =  await redisClient.sMembers(`iosDevicePushToken-${payload.arg}@${payload.subdomain}`);
                                      console.log(`111 deviceOff-${payload.arg}@${payload.subdomain}`);
              
                                      const retrieveUUIDS = {
                                              aLeg: currentCallA.uuid,
                                              bLeg: currentCallA.b_uuid    
                                      }
                                      //Different scenarios single device (webapp, android, ios) multiple (webapp + android || webapp + ios || android + ios)
                                      //payload applies only on android and ios
                                      //uuid_transfer applies only on webapp or multiple webapp + android || webapp + ios (with 2 secs delay on push)
                                      const hasAndroid = androidDeviceOff.length > 0;
                                      const hasIOS = iosDevicePush.length > 0;
              
                                      // WebApp validation
                                      const currentPushToken = await redisClient.get(
                                          `currentPushWebToken-${payload.arg}@${payload.subdomain}`
                                      );
              
              
                                      const webPushTokens = await redisClient.sMembers(
                                          `deviceOnWeb-${payload.arg}@${payload.subdomain}`
                                      );
              
                                      console.log("this is gettinggg",`currentPushWebToken-${payload.arg}@${payload.subdomain}`, `deviceOnWeb-${payload.arg}${payload.subdomain}`)
                                      console.log("values: ", currentPushToken, webPushTokens, !!currentPushToken)
              
                                      const hasWebApp =
                                          !!currentPushToken &&
                                          webPushTokens.includes(currentPushToken);
              
                                      console.log("DEVICE STATUS:", {
                                          hasWebApp,
                                          hasAndroid,
                                          hasIOS
                                      });

                                    redisClient.set(`addValidateUUIDPark-${currentCallA.cid_name}`, `${currentCallA.dest}`);
                                    console.log("set this", `addValidateUUIDPark-${currentCallA.cid_name} with ${currentCallA.dest}`);
                                      // ========================================
                                      // WEBAPP EXISTS
                                      // ========================================
                                      if (hasWebApp) {
              
                                          // WebApp only
                                          if (!hasAndroid && !hasIOS) {
                                              console.log("WebApp only");
                                            
                                              setTimeout(async()=>{
                                                 await promisifiedApi(
                                                  conn,
                                                  `uuid_transfer ${currentCallA.b_uuid} ${arg} XML ${subdomain}`
                                              );
                                              }, 1000)
                                          }
              
                                          // WebApp + Android
                                          else if (hasAndroid && !hasIOS) {
                                              console.log("WebApp + Android");
              
                                              redisClient.set(
                                                  `retrieveCalleeDecline-${payload.arg}@${payload.subdomain}`,
                                                  JSON.stringify(retrieveUUIDS)
                                              );
              
                                              //MAHALAGA
                                              redisClient.set(
                                                  `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                                  "1"
                                              );
              
                                              // Allow push first
                                              // await new Promise(resolve => setTimeout(resolve, 2000));
              
                                              await promisifiedApi(
                                                  conn,
                                                  `uuid_transfer ${currentCallA.b_uuid} ${arg} XML ${subdomain}`
                                              );
                                          }
              
                                          // WebApp + iOS 
                                          else if (!hasAndroid && hasIOS) {
                                              console.log("WebApp + iOS");
              
                                              conn.api(
                                                  `sched_hangup +30 ${retrieveUUIDS.bLeg} NO_ANSWER`
                                              );
              
                                              redisClient.set(
                                              `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                              "1"
                                              );
              
              
                                              redisClient.set(
                                                  `schedDelUUID-${payload.arg}@${payload.subdomain}`,
                                                  `${retrieveUUIDS.bLeg}`
                                              );
              
                                              redisClient.set(
                                                  `retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`,
                                                  JSON.stringify(retrieveUUIDS)
                                              );
              
                                              // Allow push first
                                              // await new Promise(resolve => setTimeout(resolve, 2000));
              
                                              await promisifiedApi(
                                                  conn,
                                                  `uuid_transfer ${currentCallA.b_uuid} ${arg} XML ${subdomain}`
                                              );
              
              
                                              redisClient.set(`addUUIDbreak-${payload.arg}@${payload.subdomain}`, `${currentCallA.b_uuid}`);
                                              console.log("adding this CurrentA");
                                              
                                              const breakPayload = {
                                                  aLeg: currentCallA.uuid,
                                                  bLeg: currentCallA.b_uuid
                                              }
              
                                              redisClient.set(`uuidBridge-${payload.arg}@${payload.subdomain}`, JSON.stringify(breakPayload));
              
              
              
                                          }
              
                                          // WebApp + Android + iOS
                                          else if (hasAndroid && hasIOS) {
                                              console.log("WebApp + Android + iOS");
              
                                              redisClient.set(
                                                  `retrieveCalleeDecline-${payload.arg}@${payload.subdomain}`,
                                                  JSON.stringify(retrieveUUIDS)
                                              );
              
                                              redisClient.set(
                                                  `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                                  "1"
                                              );
              
                                              conn.api(
                                                  `sched_hangup +30 ${retrieveUUIDS.bLeg} NO_ANSWER`
                                              );
              
                                              redisClient.set(
                                                  `schedDelUUID-${payload.arg}@${payload.subdomain}`,
                                                  `${retrieveUUIDS.bLeg}`
                                              );
              
                                              redisClient.set(
                                                  `retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`,
                                                  JSON.stringify(retrieveUUIDS)
                                              );
              
                                              // Allow Android/iOS push first
                                              // await new Promise(resolve => setTimeout(resolve, 2000));
              
                                              await promisifiedApi(
                                                  conn,
                                                  `uuid_transfer ${currentCallA.b_uuid} ${arg} XML ${subdomain}`
                                              );
                                          }
                                      }
              
                                      // ========================================
                                      // NO WEBAPP
                                      // Android / iOS push only
                                      // ========================================
                                      else {
                                          console.log("nooo")
                                          if (hasAndroid) {
                                              console.log("Android only / Android + iOS");
              
                                              redisClient.set(
                                                  `retrieveCalleeDecline-${payload.arg}@${payload.subdomain}`,
                                                  JSON.stringify(retrieveUUIDS)
                                              );
              
                                              // 115|attended    | TypeError: Cannot read property 'b_uuid' of null
                                              // 115|attended    |     at Socket.<anonymous> (/var/www/ringq/node/attended.js:392:67)
                                              // 115|attended    |     at processTicksAndRejections (internal/process/task_queues.js:95:5)
              
              
                                              redisClient.set(
                                                  `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                                  "1"
                                              );
                                          }
              
                                          if (hasIOS) {
                                              console.log("iOS only / Android + iOS");
              
                                              conn.api(
                                                  `sched_hangup +30 ${retrieveUUIDS.bLeg} NO_ANSWER`
                                              );
              
                                              redisClient.set(
                                                  `schedDelUUID-${payload.arg}@${payload.subdomain}`,
                                                  `${retrieveUUIDS.bLeg}`
                                              );
              
                                              redisClient.set(
                                                  `retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`,
                                                  JSON.stringify(retrieveUUIDS)
                                              );
                                          }
              
                                          if (!hasIOS && !hasAndroid){
                                              console.log("check no ios no android")
                                              await promisifiedApi(
                                                  conn,
                                                  `uuid_transfer ${currentCallA.b_uuid} ${arg} XML ${subdomain}`
                                              );
                                          }
              
                                          // IMPORTANT:
                                          // No uuid_transfer here because WebApp does not exist
                                      }
                                      ////Added 09-01-26
                  
                                      await promisifiedApi(conn, `uuid_setvar ${currentCallA.uuid} callFeatures Attended_Transfer`);
                                      await promisifiedApi(conn, `uuid_setvar ${currentCallA.uuid} attendedPreviousCall ${currentCallA.b_name}`);
                                      
                                      console.log("SET TO (a)", `attendedSent-${presenceExtension}@${subdomain}`)
                                      
                                      //Editted 09-09-26
                                      redisClient.set(`attendedSent-${presenceExtension}@${subdomain}`, 
                                          JSON.stringify({parkCall: currentCallA.uuid, transferCall: currentCallA.b_uuid})
                                      );
                        redisClient.set(`attIdx:${currentCallA.b_uuid}`, `attendedSent-${presenceExtension}@${subdomain}`, { EX: 7200 }).catch(() => {}); // [perf] lookup by consult leg, no KEYS scan
                        // [call-journey] attended transfer started (customer on hold, agent consulting)
                        journey.init(redisClient);
                        journey.link(currentCallA.b_uuid, currentCallA.uuid).then(() => journey.add(currentCallA.uuid, 'attended_start', { by: presenceExtension, to: arg })).catch(() => {});

                                    ///Editted 09-09-26
              
                                        console.log("VALUE: ", JSON.stringify({parkCall: currentCallA.b_uuid, transferCall: currentCallA.uuid}))
                                      
                                      /** Payload */
                                      const username = await redisClient.get(`effectiveName-${presenceExtension}@${subdomain}`);
                                      const payloadWebPush = {
                                          domain: subdomain,
                                          extension: arg,
                                          time: time1,
                                          id: uuidv4(),
                                          call: currentCallA.uuid,
                                          caller_id: presenceExtension,
                                          nameCaller: username,
                                          time2: time1
                                      }
                  
                                      async function getPayloadWithExtension(payloadWebPush) {
                                          if (payloadWebPush.extension <= 1000 || payloadWebPush.extension >= 10000) {
                                              const origExtension = await redisClient.get(`sipIp:${payloadWebPush.extension}@${payloadWebPush.domain}`);
                                              if (origExtension) {
                                                  return { ...payloadWebPush, 
                                                      extension: origExtension,
                                                  };
                                              }
                                          }
                                          return payloadWebPush;
                                      }
                  
                                      const payload2 = await getPayloadWithExtension(payloadWebPush);
                                      attendedPushWeb(payloadWebPush);
                                      getToken(payload2);
                  
                                      /** End */
                  
                                      conn.api(`uuid_dump ${currentCallA.b_uuid} JSON`, async ({ body }) => {
                  
                                          if(body === '-ERR No such channel!\n') return;
                                          const data = JSON.parse(body); 
                                          if(data['Channel-Name'].includes('internal')){
                  
                                              const extensionNumber = async (ext, subdomain) => {
                                              const { rows } = await pool.query(
                                              `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                              [ext , subdomain]
                                              );
                                              return rows.length ? rows[0].extension : ext;
                                              }
                                              const extension2 = await presenceId(data['Channel-Name']);
                                              const origExtension2 = await extensionNumber(extension2, subdomain);
                                          }
                                      })
                  
                                  }else{
                                      console.log("kill 1")
                                      if(existsB.body === 'false'){
                                          console.log("kill 1.1")
                                          await promisifiedApi(conn, `uuid_kill ${currentCallA.uuid}`)
                                      }
                  
                                      if(existsA.body === 'false'){
                                          console.log("kill 1.2")
                                          await promisifiedApi(conn, `uuid_kill ${currentCallA.b_uuid}`)
                                      }
                  
                                  }
                  
                              }
                
                if(currentCallB){
    
                    console.log(`🔥currentCallB🔥`);
                    await promisifiedApi(conn, `uuid_setvar ${currentCallB.uuid} hangup_after_bridge false`)
                    conn.api(`uuid_park ${currentCallB.b_uuid}`)
                    conn.api(`uuid_park ${currentCallB.uuid}`)
                    
                    const [existsB, existsA] = await Promise.all([
                        promisifiedApi(conn, `uuid_exists ${currentCallB.b_uuid}`),
                        promisifiedApi(conn, `uuid_exists ${currentCallB.uuid}`)
                    ]);
                    console.log(`K2 Params`, existsB, existsA);
                    const bLegCall = existsB.body === 'true';
                    const aLegCall = existsA.body === 'true';
    
                    if(bLegCall && aLegCall){
                        
                        console.log("transfer 2")

                        console.log("Check these UUID first", `${currentCallB.uuid}`, `${currentCallB.b_uuid}`)
                        conn.api(`uuid_exists ${currentCallB.uuid}`, (res) => console.log(`existB->☎️☎️ ${currentCallB.uuid}`,res));
                        conn.api(`uuid_exists ${currentCallB.b_uuid}`, (res) => console.log(`existA->☎️☎️ ${currentCallB.b_uuid}`,res))

    
                        await promisifiedApi(conn, `uuid_broadcast ${currentCallB.uuid} misc/Please-wait_while_we_transfer.wav`);
                        await promisifiedApi(conn, `uuid_broadcast ${currentCallB.uuid} ${await mohForUuid(currentCallB.uuid)}`);
                        await promisifiedApi(conn, `sched_hangup +3600 ${currentCallB.uuid} ALLOTTED_TIMEOUT`);

                          
                        //Added 09-01-26
                        //await promisifiedApi(conn, `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`);
 
                        const androidDeviceOff = await redisClient.sMembers(`deviceOff-${payload.arg}@${payload.subdomain}`);
                        const iosDevicePush =  await redisClient.sMembers(`iosDevicePushToken-${payload.arg}@${payload.subdomain}`);
                        console.log(`111 deviceOff-${payload.arg}@${payload.subdomain}`);

                        const retrieveUUIDS = {
                                aLeg: currentCallB.uuid,
                                bLeg: currentCallB.b_uuid    
                        }
                        const hasAndroid = androidDeviceOff.length > 0;
                        const hasIOS = iosDevicePush.length > 0;

                        // WebApp validation
                        const currentPushToken = await redisClient.get(
                            `currentPushWebToken-${payload.arg}@${payload.subdomain}`
                        );

                        const webPushTokens = await redisClient.sMembers(
                            `deviceOnWeb-${payload.arg}@${payload.subdomain}`
                        );

                        console.log("this is gettinggg",`currentPushWebToken-${payload.arg}@${payload.subdomain}`, `deviceOnWeb-${payload.arg}${payload.subdomain}`)
                        console.log("values: ", currentPushToken, webPushTokens, !!currentPushToken)

                        const hasWebApp =
                            !!currentPushToken &&
                            webPushTokens.includes(currentPushToken);

                        console.log("DEVICE STATUS:", {
                            hasWebApp,
                            hasAndroid,
                            hasIOS
                        });

                        // ========================================
                        // WEBAPP EXISTS
                        // ========================================
                        if (hasWebApp) {

                            // WebApp only
                            if (!hasAndroid && !hasIOS) {
                                console.log("WebApp only B!");
   
                                //Here

                                const username = await redisClient.get(`effectiveName-${presenceExtension}@${subdomain}`);
                                const username2 = await redisClient.get(`effectiveName-${payload.arg}@${subdomain}`);
                                console.log("these are 2 usernames", username, username2);
                                
                                 await promisifiedApi(
                                    conn,
                                    `uuid_setvar ${currentCallB.b_uuid} effective_caller_id_name "${username || presenceExtension}"` /* [att-display] show transferring agent, not the target itself */
                                );

                                await promisifiedApi(
                                    conn,
                                    `uuid_setvar ${currentCallB.b_uuid} effective_caller_id_number "${presenceExtension}"` /* [att-display] */
                                );

                                await promisifiedApi(
                                    conn, `uuid_setvar ${currentCallB.b_uuid} variable_transferType "Attended"`
                                )


                                await promisifiedApi(
                                    conn,
                                    `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`
                                );

                                console.log("this is transferType1", `${currentCallB.b_uuid}`, `uuid_setvar ${currentCallB.b_uuid} effective_caller_id_number "${payload.arg}"`)
                                
                            }

                            // WebApp + Android
                            else if (hasAndroid && !hasIOS) {
                                console.log("WebApp + Android B!");

                                redisClient.set(
                                    `retrieveCalleeDecline-${payload.arg}@${payload.subdomain}`,
                                    JSON.stringify(retrieveUUIDS)
                                );

                                redisClient.set(
                                    `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                    "1"
                                );

                                

                                // Allow push first
                                // await new Promise(resolve => setTimeout(resolve, 2000));

                                //Test

                                const username = await redisClient.get(`effectiveName-${presenceExtension}@${subdomain}`);
                                const username2 = await redisClient.get(`effectiveName-${payload.arg}@${subdomain}`);
                                console.log("these are 2 usernames", username, username2)
                                // 1146
                                // Boboy Outlook
                                
                                await promisifiedApi(
                                    conn,
                                    `uuid_setvar ${currentCallB.b_uuid} effective_caller_id_name "${username || presenceExtension}"` /* [att-display] show transferring agent, not the target itself */
                                );

                                await promisifiedApi(
                                    conn,
                                    `uuid_setvar ${currentCallB.b_uuid} effective_caller_id_number "${presenceExtension}"` /* [att-display] */
                                );

                                await promisifiedApi(
                                    conn, `uuid_setvar ${currentCallB.b_uuid} variable_transferType "Attended"`
                                )

                                console.log("this is transferType", `${currentCallB.b_uuid}`, `uuid_setvar ${currentCallB.b_uuid} effective_caller_id_number "${payload.arg}"`)

                                await promisifiedApi(
                                    conn,
                                    `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`
                                );

                            }

                            // WebApp + iOS
                            else if (!hasAndroid && hasIOS) {
                                console.log("WebApp + iOS");

                                conn.api(
                                    `sched_hangup +30 ${retrieveUUIDS.bLeg} NO_ANSWER`
                                );

                                redisClient.set(
                                    `schedDelUUID-${payload.arg}@${payload.subdomain}`,
                                    `${retrieveUUIDS.bLeg}`
                                );

                                redisClient.set(
                                `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                "1"
                                );


                                redisClient.set(
                                    `retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`,
                                    JSON.stringify(retrieveUUIDS)
                                );

                                // Allow push first
                                // await new Promise(resolve => setTimeout(resolve, 2000));

                                await promisifiedApi(
                                    conn,
                                    `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`
                                );

                                redisClient.set(`addUUIDbreak-${payload.arg}@${payload.subdomain}`, `${currentCallB.b_uuid}`);
                                console.log("adding this", `addUUIDbreak-${payload.arg}@${payload.subdomain}`)

                                      
                                const breakPayload = {
                                    aLeg: currentCallB.uuid,
                                    bLeg: currentCallB.b_uuid
                                }

                                redisClient.set(`uuidBridge-${payload.arg}@${payload.subdomain}`, JSON.stringify(breakPayload));

                            }

                            // WebApp + Android + iOS
                            else if (hasAndroid && hasIOS) {
                                console.log("WebApp + Android + iOS");

                                redisClient.set(
                                    `retrieveCalleeDecline-${payload.arg}@${payload.subdomain}`,
                                    JSON.stringify(retrieveUUIDS)
                                );

                                redisClient.set(
                                    `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                    "1"
                                );

                                conn.api(
                                    `sched_hangup +30 ${retrieveUUIDS.bLeg} NO_ANSWER`
                                );

                                redisClient.set(
                                    `schedDelUUID-${payload.arg}@${payload.subdomain}`,
                                    `${retrieveUUIDS.bLeg}`
                                );

                                redisClient.set(
                                    `retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`,
                                    JSON.stringify(retrieveUUIDS)
                                );

                                // Allow Android/iOS push first
                                // await new Promise(resolve => setTimeout(resolve, 2000));

                                await promisifiedApi(
                                    conn,
                                    `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`
                                );
                            }
                        }

                        // ========================================
                        // NO WEBAPP
                        // Android / iOS push only
                        // ========================================
                        else {
                            console.log("nooo")
                            if (hasAndroid) {
                                console.log("Android only / Android + iOS");

                                redisClient.set(
                                    `retrieveCalleeDecline-${payload.arg}@${payload.subdomain}`,
                                    JSON.stringify(retrieveUUIDS)
                                );

                                redisClient.set(
                                    `addPushNotif-${payload.arg}@${payload.subdomain}`,
                                    "1"
                                );
                            }

                            if (hasIOS) {
                                console.log("iOS only / Android + iOS");

                                conn.api(
                                    `sched_hangup +30 ${retrieveUUIDS.bLeg} NO_ANSWER`
                                );

                                redisClient.set(
                                    `schedDelUUID-${payload.arg}@${payload.subdomain}`,
                                    `${retrieveUUIDS.bLeg}`
                                );

                                redisClient.set(
                                    `retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`,
                                    JSON.stringify(retrieveUUIDS)
                                );
                            }

                            if (!hasIOS && !hasAndroid){
                            console.log("check no ios no android b")
                                await promisifiedApi(
                                    conn,
                                    `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`
                                );
                            }

                            // IMPORTANT:
                            // No uuid_transfer here because WebApp does not exist
                        }

                        //"iosDevicePushToken-1204@multi.flowersconradplc.ringq.cloud"
                        // redisClient.set(`retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`, JSON.stringify(retrieveUUIDS));
                        // if(iosDevicePush.length){
                        //     await promisifiedApi(conn, `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`);
                        //     redisClient.set(`retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`, JSON.stringify(retrieveUUIDS));
                        // }
                        // else{
                        //     await promisifiedApi(conn, `uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`);
                        // }
                        

                        ////Added 09-01-26

                        console.log(`trasnfer logs uuid_transfer ${currentCallB.b_uuid} ${arg} XML ${subdomain}`)
                        await promisifiedApi(conn, `uuid_setvar ${currentCallB.b_uuid} callFeatures Attended_Transfer`);
                        await promisifiedApi(conn, `uuid_setvar ${currentCallB.b_uuid} attendedPreviousCall ${currentCallB.name}`);


                        console.log("SET TO (b)", `attendedSent-${presenceExtension}@${subdomain}`)
                        redisClient.set(`attendedSent-${presenceExtension}@${subdomain}`, 
                            JSON.stringify({parkCall: currentCallB.uuid, transferCall: currentCallB.b_uuid})
                        );
                        redisClient.set(`attIdx:${currentCallB.b_uuid}`, `attendedSent-${presenceExtension}@${subdomain}`, { EX: 7200 }).catch(() => {}); // [perf] lookup by consult leg, no KEYS scan
                        // [call-journey] attended transfer started (customer on hold, agent consulting)
                        journey.init(redisClient);
                        journey.link(currentCallB.b_uuid, currentCallB.uuid).then(() => journey.add(currentCallB.uuid, 'attended_start', { by: presenceExtension, to: arg })).catch(() => {});

                        console.log("thiiis", `retrieveFirstCalleeExtension-${presenceExtension}`)
                        if(currentCallB.cid_name.length = 4){
                            redisClient.set(`retrieveFirstCalleeExtension-${presenceExtension}`, currentCallB.cid_name)
                            console.log("thiiis11", `retrieveFirstCalleeExtension-${presenceExtension}`)
                        }
                        console.log("VALUE :", JSON.stringify({parkCall: currentCallB.uuid, transferCall: currentCallB.b_uuid}))

    
                        /** Payload */
                  
                        const username = await redisClient.get(`effectiveName-${presenceExtension}@${subdomain}`);
                        const payloadWebPush  = {
                            domain: subdomain,
                            extension: arg,
                            time: time1,
                            id: uuidv4(),
                            call: currentCallB.b_uuid,
                            caller_id: presenceExtension,
                            nameCaller: username,
                            time2: time1
                         }
                        
                         console.log("console.log", payloadWebPush);
                         
    
                        async function getPayloadWithExtension(payloadWebPush) {
                            if (payloadWebPush.extension <= 1000 || payloadWebPush.extension >= 10000) {
                                const origExtension = await redisClient.get(`sipIp:${payloadWebPush.extension}@${payloadWebPush.domain}`);
                                if (origExtension) {
                                    return { ...payloadWebPush, 
                                        extension: origExtension,
                                    };
                                }
                            }
                            return payloadWebPush;
                        }

                      


                        

                        const payload2 = await getPayloadWithExtension(payloadWebPush);
                        attendedPushWeb(payloadWebPush);

                        // setTimeout(()=>{
                        //     console.log("hey")

                        // Added 09-03-26     

 
                        // }, 2000)
                        //redisClient.set(`addPushNotif-${payload.arg}@${payload.subdomain}`, "1");
                        const getPushValidation = await redisClient.get(`addPushNotif-${payload.arg}@${payload.subdomain}`);
                        console.log("zz", `addPushNotif-${payload.arg}@${payload.subdomain}`)
                        if(getPushValidation){
                            redisClient.del(`addPushNotif-${payload.arg}@${payload.subdomain}`);
                            setTimeout(()=>{
                                console.log("add and delte")
                                getToken(payload2);
                                getTokenIOS(payload2);
                            }, 3000)
                        }
                        else{
                            getToken(payload2);
                            getTokenIOS(payload2)
                        }
                        /// Added 09-03-26

                        /** End */
                        // const payload3 = {...payload2}


                        
                        // End test



    
                    }else{
                        console.log("kill 2.1")
                        if(existsB.body === 'false'){
                            await promisifiedApi(conn, `uuid_kill ${currentCallB.uuid}`)
                             console.log("kill 2.11")
                        }
                        if(existsA.body === 'false'){
                            await promisifiedApi(conn, `uuid_kill ${currentCallB.b_uuid}`)
                             console.log("kill 2.12")
                        }
    
                    }
                }
    
            } catch (error) {
                console.error(error);
            }
    
        });

    socket.on("node-parkAttendedTransfer", async (payload, callback) => {
        
        // const {
        // 	subdomain,
        // 	presenceExtension
        // } = payload

        // const attendedPresence = await redisClient.get(`attendedSent-${presenceExtension}@${subdomain}`);
        // const dest2 = payload.dest
        // const { parkCall , transferCall} = JSON.parse(attendedPresence);
        // const bId = await redisClient.get(`answered-presence-${dest2}@${subdomain}`);
        // console.log(`☎️`, bId);
        // conn.api(`uuid_bridge ${parkCall} ${bId}`);
        // conn.api(`uuid_exists ${bId}`, (res) => console.log(`Dest->☎️`,res));
        // conn.api(`uuid_exists ${parkCall}`, (res) => console.log(`parkCall->☎️`,res));


        // async function sipIdDestination(dest) {
        // 	if (dest <= 1000 || dest >= 10000) {
        // 		return dest
        // 	}
        // 	return null;
        // }
        // const sipId = await sipIdDestination(payload.dest);
        // async function destination(payload) {
        // 	if (payload.dest <= 1000 || payload.dest >= 10000) {
        // 		const origExtension = await redisClient.get(`sipIp:${payload.dest}@${payload.subdomain}`);
        // 		if (origExtension) {
        // 			return origExtension
        // 		}
        // 	}
        // 	return payload.dest;
        // }
        
        // const dest = await destination(payload);
        // await redisClient.del(`${subdomain}${presenceExtension}-RingQ`);

        // try {

        //     redisClient.set(`attendedProcess-${presenceExtension}@${subdomain}`, 1, {EX:2});
        //     console.log('Attended Process Button', {
        //          socket: `${presenceExtension}@${subdomain}`,
        //          destination : dest,
        //          attendedPresence: attendedPresence
        //     });

        //     if(attendedPresence){

        //          const channelPresence = `${dest}@${subdomain}`;
        //         //  const { parkCall , transferCall} = JSON.parse(attendedPresence);
        //          const res = await promisifiedApi(conn, 'show calls as json');
        //          const { row_count, rows } = JSON.parse(res.body);
        //          if (!rows.length) return;

        //          const channelName = await redisClient.sMembers(`ChannelName-${presenceExtension}@${subdomain}`);
        // 		 const findAnswerUID = rows.map(el => {

        //             if(el.name.includes('internal/')){
        //                 if(channelName.includes(el.name.split('internal/')[1])){
        //                     conn.api(`uuid_park ${el.b_uuid}`)
        //                     return el.b_uuid
        //                 }
        //             }

        //             if(el.name.includes('internal/')){
        //                 if(channelName.includes(el.b_name.split('internal/')[1])){
        //                     conn.api(`uuid_park ${el.uuid} park`)
        //                     return el.uuid
        //                 }
        //             }

        //          })
        //         .find(x => x);
                
        //         const findUser = rows.find(x => x.uuid === parkCall);

        // 		 console.log('ALL CALLS', rows, channelName, `ChannelName-${presenceExtension}@${subdomain}`);
        // 		 if(findUser){

        // 			console.log('Attended Process Button-Bug', {
        // 				 findUser,
        // 				 findAnswerUID,
        // 			});
                        
        //                 console.log(`Attended Process `, {
        //                     'channelPresence': channelPresence,
        //                     'FindAnswer' : findAnswerUID,
        //                     'parkCall' : parkCall,
        //                 });

        // 				const bridgeRes = await promisifiedApi(conn, `uuid_bridge ${parkCall} ${findAnswerUID}`);


        //                 const callIdExist = await promisifiedApi(conn, `uuid_exists ${findAnswerUID}`);
        //                 const callIdExist2 = await promisifiedApi(conn, `uuid_exists ${parkCall}`);
        //                 await promisifiedApi(conn, `sched_del ${parkCall}`);

        //                 if(callIdExist.body == 'true' && callIdExist2.body == 'true'){

        //                     const bridgeRes = await promisifiedApi(conn, `uuid_bridge ${parkCall} ${findAnswerUID}`);
        //                     if(bridgeRes){
        //                         showCalls(subdomain).then((result) => {
        //                             showcallsEventRefresh(subdomain);
        //                             callback({
        //                                 state : true,
        //                                 result: result,
        //                                 dest: dest,
        //                                 sipId: sipId
        //                             });
        //                         });
        //                     }
        //                     const queueList = await redisClient.lRange(`queueCallBoard-${subdomain}`, 0, -1)
        //                     if(queueList){
        //                         const parseCallBoard = queueList.map(el => JSON.parse(el));
        //                         const findCallBoard = parseCallBoard.find(item => item.uuid === parkCall || item.uuid === findAnswerUID);
        //                         if(findCallBoard !== undefined && findCallBoard !== null){
        //                             const findIndexCallBoard = parseCallBoard.findIndex(item => item.uuid === parkCall || item.uuid === findAnswerUID);
        //                             await redisClient.lRem(`queueCallBoard-${subdomain}`, findIndexCallBoard, JSON.stringify(findCallBoard));
        //                         }
        //                     }
        //                     return

        //                 }
        //                 await promisifiedApi(conn, `uuid_kill ${findAnswerUID}`);
        //                 await promisifiedApi(conn, `uuid_kill ${parkCall}`);
        // 		 }
        //     } else {
        // 		showcallsEventRefresh(subdomain);
        // 		callback({
        // 			state : false,
        // 		});
        //     }


        // } catch (error) {
        //     console.error('Error processing call data:', error);
        // }

    });

    socket.on("node-unparkAttendedTransfer", async(payload, callback) => {

        console.log(`CHANNEL NAME : node-unparkAttendedTransfer`)
        async function destination(payload) {
            if (payload.dest <= 1000 || payload.dest >= 10000) {
                const origExtension = await redisClient.get(`sipIp:${payload.dest}@${payload.subdomain}`);
                if (origExtension) {
                    return origExtension
                }
            }
            return payload.dest;
        }

        const dest = await destination(payload);
        const subdomain = payload["subdomain"];
        const presenceExtension = payload["presenceExtension"];
        const SIP = await redisClient.get(`SIP-${presenceExtension}@${subdomain}`);

        console.log('Attended Button2 Unpark', {
            socket: `${presenceExtension}@${subdomain}`,
            destination : dest
        });

        try {

            const res = await promisifiedApi(conn, 'show calls as json');
            const { row_count, rows } = JSON.parse(res.body);
            if (!rows.length) return;

                // Process rows in parallel with proper async handling
                const processedRows = await Promise.all(rows.map(async (x) => {
                const update = {};
                 
                // Helper function for processing presence IDs
                const processPresence = async (presenceKey, subdomain) => {
                const presenceValue = x[presenceKey];
                   
                   if (presenceValue?.includes(subdomain)) {
                     const [extension] = presenceValue.split('@');
                     if (!isValidNumber(extension)) {
                       const response = await redisClient.get(`extension-${extension}@${subdomain}`);
                       const { extension: validExtension } = JSON.parse(response);
                       return `${validExtension}@${subdomain}`;
                     }
                   }
                   return presenceValue; // Return original if no changes
                 };
           
                 // Process both presence fields
                 update.presence_id = await processPresence('presence_id', subdomain);
                 update.b_presence_id = await processPresence('b_presence_id', subdomain);
                 return { ...x, ...update };
            }));

            const parkCall = `${dest}@${subdomain}`
            const uuidPark = processedRows.find(presence => presence.presence_id === parkCall || 
            presence.name.includes(dest));

            const currentCall = processedRows.find(presence => 
                 presence.presence_id === presenceExtension +'@'+ subdomain ||
                 presence.b_presence_id === presenceExtension +'@'+ subdomain
            );

            console.log('RESPONSE ATTENDED UNPARK', {
                 park: parkCall,
                 new:  processedRows.filter(row => row),
                 test: uuidPark,
                 current:  currentCall
            });

            if(currentCall.presence_id && currentCall.presence_id === presenceExtension +'@'+ subdomain){
                 
                if(currentCall.uuid && uuidPark.uuid && currentCall.b_uuid){

                    await promisifiedApi(conn, `uuid_setvar ${currentCall.uuid} callFeatures Attended_Transfer`);
                    const callIdExist = await promisifiedApi(conn, `uuid_exists ${currentCall.uuid}`);
                    const callIdExist2 = await promisifiedApi(conn, `uuid_exists ${uuidPark.uuid}`);
                    console.log(`CHANGE CALLER K1`, currentCall.uuid, uuidPark.uuid, callIdExist, callIdExist2)

                    if(callIdExist.body == 'true' && callIdExist2.body == 'true'){
                        console.log("transfer 3")
                        conn.api(`uuid_transfer ${currentCall.b_uuid} park inline`)
                        conn.api(`uuid_transfer ${currentCall.uuid} park inline`)
                        await promisifiedApi(conn, `uuid_broadcast ${currentCall.b_uuid} ${await mohForUuid(currentCall.b_uuid)}`)
                        await promisifiedApi(conn, `uuid_bridge ${currentCall.uuid} ${uuidPark.uuid}`);
                        console.log("bridging... a1")
                        await promisifiedApi(conn, `sched_del ${uuidPark.uuid}`);

                        console.log("SET TO (c)", `attendedSent-${presenceExtension}@${subdomain}`,   JSON.stringify({parkCall: currentCall.b_uuid, transferCall: currentCall.uuid}))
                        await redisClient.set(`attendedSent-${presenceExtension}@${subdomain}`, 
                            JSON.stringify({parkCall: currentCall.b_uuid, transferCall: currentCall.uuid})
                        );
                        redisClient.set(`attIdx:${currentCall.uuid}`, `attendedSent-${presenceExtension}@${subdomain}`, { EX: 7200 }).catch(() => {}); // [perf] lookup by consult leg, no KEYS scan
                        await redisClient.del(`attendedSent-${parkCall}`);
                        return
                    }
                     console.log("kill 3.1")
                    await promisifiedApi(conn, `uuid_kill ${currentCall.uuid}`)
                    await promisifiedApi(conn, `uuid_kill ${uuidPark.uuid}`)
                    
                }
        
            }

            if(currentCall.b_uuid && currentCall.b_presence_id === presenceExtension +'@'+ subdomain){

                      console.log(`K2`, currentCall.b_uuid ,uuidPark.uuid);
                      await promisifiedApi(conn, `uuid_setvar ${currentCall.b_uuid} callFeatures Attended_Transfer`);
                      const callIdExist = await promisifiedApi(conn, `uuid_exists ${currentCall.b_uuid}`);
                      const callIdExist2 = await promisifiedApi(conn, `uuid_exists ${uuidPark.uuid}`);
                      if(callIdExist.body == 'true' && callIdExist2.body == 'true'){
                        console.log("transfer 4")
                        conn.api(`uuid_transfer ${currentCall.b_uuid} park inline`)
                        conn.api(`uuid_transfer ${currentCall.uuid} park inline`)
                        await promisifiedApi(conn, `uuid_broadcast ${currentCall.b_uuid} ${await mohForUuid(currentCall.b_uuid)}`)
                        await promisifiedApi(conn, `uuid_bridge ${currentCall.b_uuid} ${uuidPark.uuid}`);
                        console.log("bridging... a2")
                        await promisifiedApi(conn, `sched_del ${uuidPark.uuid}`);
                        
                        console.log("SET TO (d)", `attendedSent-${presenceExtension}@${subdomain}`,  JSON.stringify({parkCall: currentCall.uuid, transferCall: currentCall.b_uuid}))
                        await redisClient.set(`attendedSent-${presenceExtension}`, 
                            JSON.stringify({parkCall: currentCall.uuid, transferCall: currentCall.b_uuid})
                        );
                        redisClient.set(`attIdx:${currentCall.b_uuid}`, `attendedSent-${presenceExtension}`, { EX: 7200 }).catch(() => {}); // [perf] lookup by consult leg, no KEYS scan
                        await redisClient.del(`attendedSent-${parkCall}`);
                        return
                      }

                         console.log("kill 4.1")
                      await promisifiedApi(conn, `uuid_kill ${currentCall.b_uuid}`);
                      await promisifiedApi(conn, `uuid_kill ${uuidPark.uuid}`);
                     
            }

        } catch (error) {
            console.error('Error processing call data:', error);
        }

    }); 

    socket.on("node-pushBackCall", async(payload, callback)=>{
        console.log(`🚩node-pushBackCall`.toUpperCase(), payload);

        const getPushPayloadAndroid = await redisClient.get(`pushNotificationAndroid-${payload.presenceExtension}@${payload.subdomain}`);

        if(getPushPayloadAndroid){
            const dataPayload = JSON.parse(getPushPayloadAndroid);
            const payload = {
                domain: dataPayload.domain,
                extension: dataPayload.extension,
                caller_id: dataPayload.caller_id,
                nameCaller: dataPayload.nameCaller,
                avatar: dataPayload.avatar,
                pushToken: dataPayload.pushToken,
                id: dataPayload.id,
                call: "missed",
                time: `${Date.now()}`,
                time2: `${Date.now()}`,
            }

                redisClient.del(`pushNotificationAndroid-${payload.extension}@${payload.domain}`);
                socketClient2.emit('push-call', payload);

                const schedUUID = await redisClient.get(`schedDelUUID-${payload.extension}@${payload.domain}`);
                console.log("get this", `schedDelUUID-${payload.extension}@${payload.domain}`)
                if(schedUUID){
                    console.log("has sched uuid", schedUUID);
                   await promisifiedApi(conn, `sched_del ${schedUUID}`);
                   
                }
        }

    })

    socket.on("node-unparkAttendedTransfer2", async(payload, callback) => {
        
        console.log(`🚩node-unparkAttendedTransfer2`.toUpperCase(), payload)
        const {
            presenceExtension,
            subdomain,
            dest
        } = payload
        
        const aId = await redisClient.get(`answered-presence-${presenceExtension}@${subdomain}`);
        const bId = await redisClient.get(`answered-presence-${dest}@${subdomain}`);

        console.log(`☎️`, aId, bId)

        //Added 09-01-26
        console.log("bridging... a3")
        conn.api(`uuid_bridge ${aId} ${bId}`);

        if(payload.presenceExtension && payload.subdomain){
           
             const getPushPayload = await redisClient.get(`pushNotification-${payload.presenceExtension}@${payload.subdomain}`);
             const availableTokens = await redisClient.get(`pushNotificationOther-${payload.presenceExtension}@${payload.subdomain}`);
             const getPushPayloadAndroid = await redisClient.get(`pushNotificationAndroid-${payload.presenceExtension}@${payload.subdomain}`);
            
             console.log("gettinggg", `pushNotification-${payload.presenceExtension}@${payload.subdomain}`, `pushNotificationOther-${payload.presenceExtension}@${payload.subdomain}`);
             
             if((getPushPayload && availableTokens) && !getPushPayloadAndroid){
                console.log(`☎️--☎️--☎️`, aId, bId)
                console.log(`☎️---☎️ only IOS`, aId, bId)
                const dataPayload = JSON.parse(getPushPayload);
                const payload = {
                    domain: dataPayload.domain,
                    extension: dataPayload.extension,
                    caller_id: dataPayload.caller_id,
                    nameCaller: dataPayload.nameCaller,
                    avatar: dataPayload.avatar,
                    pushToken: dataPayload.pushToken,
                    id: dataPayload.id,
                    call: "missed",
                    time: `${Date.now()}`,
                    time2: `${Date.now()}`,
                }
                pushVoip({ ...payload, pushToken: availableTokens }, availableTokens);
                console.log(`this is unpark missed for IOS`);
                redisClient.del(`pushNotification-${payload.extension}@${payload.domain}`);
                redisClient.del(`pushNotificationOther-${payload.extension}@${payload.domain}`);
                
                const schedUUID = await redisClient.get(`schedDelUUID-${payload.extension}@${payload.domain}`);
                console.log("get this", `schedDelUUID-${payload.extension}@${payload.domain}`)
                if(schedUUID){
                    console.log("has sched uuid", schedUUID);
                   await promisifiedApi(conn, `sched_del ${schedUUID}`);
                }
                //conn.api(`sched_del`)
             }
             else if(getPushPayloadAndroid && (!getPushPayload && !availableTokens)){
                  console.log(`☎️---☎️ only android`, aId, bId)
                const dataPayload = JSON.parse(getPushPayloadAndroid);
                console.log("this is datapayload", dataPayload);

                const payload = {
                    domain: dataPayload.domain,
                    extension: dataPayload.extension,
                    caller_id: dataPayload.caller_id,
                    nameCaller: dataPayload.nameCaller,
                    avatar: dataPayload.avatar,
                    pushToken: dataPayload.pushToken,
                    id: dataPayload.id,
                    call: "missed",
                    time: `${Date.now()}`,
                    time2: `${Date.now()}`,
                }

                console.log("this is payload", payload);
                // pushVoip({ ...payload, pushToken: availableTokens }, availableTokens);
                console.log(`this is unpark missed for android`);
                redisClient.del(`pushNotificationAndroid-${payload.extension}@${payload.domain}`);
                socketClient2.emit('push-call', payload);

                const schedUUID = await redisClient.get(`schedDelUUID-${payload.extension}@${payload.domain}`);
                console.log("get this", `schedDelUUID-${payload.extension}@${payload.domain}`)
                if(schedUUID){
                    console.log("has sched uuid", schedUUID);
                   await promisifiedApi(conn, `sched_del ${schedUUID}`);
                   
                }
             }
             else {
                console.log("why")
             }
        }
        else{
            console.log("this is niceeee")
        }
       


        ///Added 09-01-26
        
    });

    socket.on("node-recoverAttendedTransfer", async(payload, callback) => {

        console.log(`🚩CHANNEL NAME : node-recoverAttendedTransfer`.toUpperCase(), payload);
        async function destination(payload) {
            if (payload.dest <= 1000 || payload.dest >= 10000) {
                const origExtension = await redisClient.get(`sipIp:${payload.dest}@${payload.subdomain}`);
                if (origExtension) {
                    return origExtension
                }
            }
            return payload.dest;
        }
        const dest = await destination(payload);
        const subdomain = payload["subdomain"];
        const presenceExtension = payload["presenceExtension"];

        const SIPMobile = await redisClient.get(`SIP-${presenceExtension}@${subdomain}`);
        const SIPWeb = await redisClient.get(`SIPWEB-${presenceExtension}@${subdomain}`);

        console.log('Attended Recover', {
            socket: `${presenceExtension}@${subdomain}`,
            destination : dest,
            payload,
            SIPMobile,
            SIPWeb
        });


        try {

            const findParkCall = async () => {
                const res = await promisifiedApi(conn, 'show calls as json');
                const { row_count, rows } = JSON.parse(res.body);
                if (!row_count) return null;

                // Process presence IDs in parallel
                const processedRows = await Promise.all(
                rows.map(async (call) => {
                    const update = {};
                    
                    const processPresence = async (presenceKey, subdomain) => {
                        const presenceValue = call[presenceKey];
                        if (presenceValue?.includes(subdomain)) {
                            const [extension] = presenceValue.split('@');
                            if (!isValidNumber(extension)) {
                            const response = await redisClient.get(`extension-${extension}@${subdomain}`);
                            const { extension: validExtension } = JSON.parse(response);
                            return `${validExtension}@${subdomain}`;
                            }
                        }
                        return presenceValue;
                    };

                    update.presence_id = await processPresence('presence_id', subdomain);
                    update.b_presence_id = await processPresence('b_presence_id', subdomain);
                    return { ...call, ...update };
                }));

                const parkCall = `${dest}@${subdomain}`;
                const sipToFind = SIPMobile?.split('@')[0];

                console.log('Process Rows Recover', processedRows, `params${parkCall}-${sipToFind}-${dest}`);

                return processedRows.find(
                call =>
                    (call.presence_id === parkCall && call.callee_num === sipToFind) ||
                    (call.name?.includes(dest) && call.callee_num === sipToFind)
                );
            };
            const holdCall = await findParkCall();
            console.log(`This is called Recover 2 hold`, holdCall)


            if (!holdCall) {
                console.log('No parked call found. Exiting...');
                return;
            }

            const { uuid: parkUID } = holdCall;
            // [att-vm] customer is leaving a voicemail for the transfer target: don't pull it out
            if (await redisClient.get(`attVm:${parkUID}`)) {
                const app = String((await promisifiedApi(conn, `uuid_getvar ${parkUID} current_application`))?.body || '').trim();
                if (app === 'lua') { console.log('Recover skipped: customer is in voicemail', parkUID); return; }
            }
            conn.api(`uuid_exists ${parkUID}`, async (res) => {
            if(res.body == 'true'){
                console.log("transfer 5")
                await promisifiedApi(conn, `sched_del ${parkUID}`);

                await promisifiedApi(conn, `uuid_transfer ${parkUID} ${presenceExtension} XML ${subdomain}`);
                return
            }
                console.log('EXIST PARK', res);
            });

            console.log('RESPONSE ATTENDED RECOVER', {
                holdCall: holdCall,
            });

        } catch (error) {
            console.error('Error processing call data:', error);
        }


    });

});

const isValidNumber = (input) =>  {
     const regex = /^\+?\d+$/;
     return regex.test(input);
}

// [moh] the domain's own music on hold (Global Settings > Music on hold) when it has uploaded
// files, else the default stream. Hard-coded local_stream://default / local_stream://moh meant
// custom hold music never played on park / transfer waits.
const _mohCache = new Map();
function mohStream(domain) {
  const d = String(domain || "");
  if (!/^[a-z0-9.-]+$/i.test(d)) return "local_stream://default";
  const c = _mohCache.get(d);
  if (c && Date.now() - c.t < 60000) return c.v;
  let v = "local_stream://default";
  try {
    if (require("fs").readdirSync(`/usr/share/freeswitch/sounds/music/${d}/8000`).some((f) => /\.(wav|mp3)$/i.test(f))) v = `local_stream://${d}/moh`;
  } catch (e) { /* no custom music */ }
  _mohCache.set(d, { v, t: Date.now() });
  return v;
}
async function mohForUuid(uuid) {
  try {
    const res = await new Promise((resolve) => conn.api(`uuid_getvar ${uuid} domain_name`, (r) => resolve(r)));
    return mohStream(String((res && res.body) || "").trim());
  } catch (e) { return "local_stream://default"; }
}

const promisifiedApi = (conn, command) => {

     return new Promise((resolve, reject) => {    
     conn.api(command, (res) => {
          console.log(`result-promisifiedApi`, res);
          if (res.error) reject(res.error);
          else resolve(res);
          });
     });

};

async function showCalls(subdomain) {

    await new Promise((resolve) => setTimeout(resolve, 1500));

    const res = await new Promise((resolve) => {
     conn.api(`show calls as json`, resolve);
    });

    const data = JSON.parse(res.body);

    if (data.row_count > 0) {
     for (const [index, element] of data.rows.entries()) {
         if (element.dest >= 10000) {
             const query = `
                 SELECT did.did_number, did.extension, domain.domain_name 
                 FROM q_dids AS did 
                 LEFT JOIN v_domains AS domain 
                 ON did.domain_uuid = domain.domain_uuid 
                 WHERE did.did_number = $1
             `;
             const data2 = await pool.query(query, [element.dest]);

             if (data2.rows.length > 0) {
                 const domainName = data2.rows[0].domain_name;
                 data.rows[index].dest = data2.rows[0].extension;

                 console.log('DATABASE POSTGRES SQL2', JSON.stringify(data));
                 return { data: JSON.stringify(data), subdomain: domainName };
             } else {
                 return { data: res.body, subdomain };
             }
         }
     }
     return { data: res.body, subdomain };
    } else {
     return { data: res.body, subdomain };
    }
}

async function attendedPushWeb(payload){
      const webTokens = await redisClient.sMembers(`deviceWebToken-${payload.extension}@${payload.domain}`);
      console.log("This is token! WEB " + webTokens, payload);
      if(webTokens){
          const web_ws = new WebSocket('ws://157.245.60.143:7770');

          web_ws.on('open', () => {
               console.log('Connected to web push server');
               const message = {
                    webTokens: webTokens,
                    payload: payload,
                    status: 'single',
               };
               web_ws.send(JSON.stringify(message));
          });

          web_ws.on('message', async (data) => {
               console.log('SUCCESS WEB PUSH!');
          });

          web_ws.on('close', () => {
               console.log('Connection closed by web push server');
          });

          web_ws.on('error', (error) => {
               console.error('WebSocket web push error:', error);
          });

      }else{
          console.log('NO WEB TOKEN!');
      }
}

async function internalPush2(payload){
     try {

          const { extension, domain } = payload;

          if(
                Number(extension) >= 10 && 
                Number(extension) < 3000 || 
                Number(extension) >= 7000 && 
                Number(extension) < 9000
          ){

               const exist = await redisClient.exists(`${extension}+${domain}`)

               if(exist == 0){
                   await redisClient.set(`${extension}+${domain}`, '0')
               }
          
               const position = await redisClient.get(`${extension}+${domain}`);

               if(position && position !== ""){

                    const num = parseInt(position);
                    const forwardAll = await redisClient.lRange(`${extension}@${domain}-forward-all-enabled`, num, num)
                    const callRelay = await redisClient.lRange(`${extension}@${domain}-forward-me-enabled`, num, num)
                    
                    if(forwardAll == 'false'){

                         const timeout = await redisClient.lRange(`${payload.extension}@${payload.domain}-forward-no-answer-timeout`, num, num)
                         if(timeout){

                              await cacheCalls(payload.domain, payload.extension, payload.call);
                              payload.time2 =  payload.time2 || Number(parseInt(timeout) * 1000000).toString();
                              if(payload.call !== 'decline'){
                                   socketClient.emit("callProcessStatus", { channel: payload.domain, caller: payload.caller_id, callee: payload.extension, status: "2" });
                              }
                              getToken(payload);
                              const callerPresence = `${payload.caller_id}@${payload.domain}`;
                              const calleePresence = `${payload.extension}@${payload.domain}`;
                              await Promise.all([
                                redisClient.set(`decline:${callerPresence}`, payload.call, { EX: timeout }),
                                redisClient.set(`decline:${calleePresence}`, payload.call, { EX: timeout })
                              ]);
          
                         }
          
                    }
               }
               
          }
     } catch (error) {
          console.error(error.message);
     }
}

async function cacheCalls(host, extension, callId) {

     try {
          if(host){
               if (host && extension && callId) {
                    await redisClient.set(host + extension, callId, {EX:65});
               }
          }
     } catch (error) {
          console.error(error.message);
     }
     
}

async function getToken(payload, cause) {
    return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push

     try {

        console.log("<!-- Payload Push Attended Transfer", payload);
        const {extension, domain, call, status} = payload

        if(
            extension >= 10 && 
            extension < 3000 || 
            extension >= 7000 && 
            extension < 9000
        ){
            console.log("checkpoint 1")
            const androidDeviceOn =  await redisClient.sMembers(`deviceOn-${extension}@${domain}`);
            const androidDeviceOff = await redisClient.sMembers(`deviceOff-${extension}@${domain}`);
            const androidStatus = androidDeviceOff.map(val => androidDeviceOn.includes(val)).includes(false);
            console.log(`android`, androidDeviceOff, androidStatus);
            console.log(`android2 deviceOff-${extension}@${domain}` );
            if(androidDeviceOff.length && androidStatus){
                console.log("checkpoint 2")
                    console.log('Presence - IN');
                    if(
                    call !== 'decline' && 
                    call !== 'missed' && 
                    call !== 'answered' && 
                    call !== 'answered2'
                    ){  
                        console.log("checkpoint 3", call)
                        const callId = await redisClient.set(`${domain}${extension}`, call);
                        await redisClient.set(`${domain}${extension}-RingQ`, call)
                        console.log('PUSH CALL UNIQUE ID', call)
                        if(callId){
                            //Added 08-26-26
                            setTimeout(()=>{
                                console.log("waiting 2 secs");

                                socketClient2.emit('push-call', payload,  async (offlineSockets) => {
                                console.log('⛔ Offline sockets:', offlineSockets);
                                if(offlineSockets.includes('success')){
                                    //Removed 08-26-25
                                    // conn.api("uuid_park " + payload.call);
                                    // conn.api("uuid_broadcast " + payload.call + " playback::misc/falsering.wav aleg");
                                    ////Removed 08-26-25
                                    conn.api(`sched_hangup +60 ${payload.call} NO_ANSWER`);
                                    redisClient.set(`schedDelUUID-${payload.caller_id}@${payload.domain}`, `${payload.call}`);
                                    redisClient.set(`pushNotificationAndroid-${payload.caller_id}@${payload.domain}`, JSON.stringify(payload), { EX: 300});
                                   // redisClient.set(`pushNotificationOther-${payload.caller_id}@${payload.domain}`, availableTokens[0], { EX: 300});

                                    console.log("will this happen now?");
                                    if(!payload.status){
                                        console.log(`EMIT PUSH CALL2`);
                                    }
                                }
                            });
                            }, 2000)
                            ////Added 08-26-26
                        }
                        
                    }else{
                        socketClient2.emit('push-call', payload);
                        console.log(`EMIT PUSH CALL DECLINE`, payload);
                    }
            }

        }
     } catch (error) {
          console.error(error.message)
     }
     
}


async function pushVoipMutipleDevice(payload, tokens) {
    return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push
    console.log("hi?")
     // ADDED EMAN 08-27-26 START
     if (isDeclinedInternalExtension(payload)) return;
     // ADDED EMAN 08-27-26 END

     console.log("Sending VoIP push notifications...");

     // EMAN ADDED 08-11-26
     const results = await Promise.all(
       tokens.map(token => redisClient.exists(`prevent-push-ios-${token}`))
     );
     tokens = tokens.filter((_, index) => !results[index]);
     // EMAN ADDED 08-11-26

     const ws = new WebSocket('ws://157.245.60.143:8880');
     const apnsId = uuidv4();

     ws.on('open', () => {

          console.log('Connected to server');
          const message = {
               token: tokens,
               payload: payload,
               device: 'ios',
               status: 'multiple',
               apnsId: apnsId
          };
          ws.send(JSON.stringify(message));
     });

     ws.on('message', async (data) => {

          const str = data.toString();
          console.log('Received raw data:', str);

          if (str.trim().startsWith('{') || str.trim().startsWith('[')) {
               try {

                    const message = JSON.parse(str);
                    console.log('Parsed message:', message);
                     if(message.status === 'success'){

                         if(message.call === 'answered'){
                              await redisClient.del(`pushTriggerIOS-${message.token}-RingQ`);
                              await redisClient.del(`${message.subdomain}${message.extension}-RingQ`);
                              await redisClient.sRem(`iosSucessPushToken-${message.extension}@${message.subdomain}`, message.token)
                              return
                         }

                         if(message.call === 'decline'){
                              await redisClient.del(`pushTriggerIOS-${message.token}-RingQ`);
                              await redisClient.del(`${message.subdomain}${message.extension}-RingQ`);
                              await redisClient.sRem(`iosSucessPushToken-${message.extension}@${message.subdomain}`, message.token)
                              return
                         }

                         await redisClient.set(`pushTriggerIOS-${message.token}-RingQ`, 1);
                         await redisClient.sAdd(`iosSucessPushToken-${message.extension}@${message.subdomain}`, message.token)
                         await redisClient.set(`${message.subdomain}${message.extension}-RingQ`, message.call);
                         await redisClient.set(`IOS-${message.subdomain}@${message.extension}`, message.call);
                         
                    }

               } catch (err) {
                    console.error('Failed to parse JSON:', err.message);
               }
          } else {
               console.log('Non-JSON message received:', str);
          }

     });

     ws.on('close', () => {
          console.log('Connection closed by server multiple');
     });

     ws.on('error', (error) => {
          console.error('WebSocket error multiple:', error);
     });
     console.log("All VoIP IOS notifications sent.");
}




                        // Test add
                        // console.log("test here now")
                        // --- Shared WebSocket connection (module-level, outside the function) ---

                        
                        function getWebSocket() {
                          if (sharedWs && sharedWs.readyState === WebSocket.OPEN) return sharedWs;
                        
                          const ws = new WebSocket('ws://157.245.60.143:8880');
                        
                          ws.on('open', () => {
                            console.log('[WS] Connected to push server');
                          });
                        
                          ws.on('message', async (data) => {
                             const str = data.toString();
                             if (!str.trim().startsWith('{') && !str.trim().startsWith('[')) {
                             console.log('[WS] Non-JSON message:', str);
                             return;
                             }
                        
                             let message;
                             try {
                                  message = JSON.parse(str);
                             } catch (err) {
                                  console.error('[WS] Failed to parse JSON:', err.message);
                                  return;
                             }
                        
                             console.log('[WS] Received:', message);
                        
                             // ✅ resolve by token
                             const pending = pendingCallbacks.get(message.token);
                             if (pending) {
                             clearTimeout(pending.timer);
                             pendingCallbacks.delete(message.token);
                             message.status === 'success' ? pending.resolve(message) : pending.reject(new Error(`Push failed: ${message.status}`));
                             }
                        
                             if (message.status !== 'success') return;
                        
                             try {
                             const isTerminal = message.call === 'answered' || message.call === 'decline';
                             await Promise.all(
                                  isTerminal
                                  ? [
                                       redisClient.del(`pushTriggerIOS-${message.token}-RingQ`),
                                       redisClient.del(`${message.subdomain}${message.extension}-RingQ`),
                                       redisClient.sRem(`iosSucessPushToken-${message.extension}@${message.subdomain}`, message.token),
                                  ]
                                  : [
                                       redisClient.set(`pushTriggerIOS-${message.token}-RingQ`, 1),
                                       redisClient.sAdd(`iosSucessPushToken-${message.extension}@${message.subdomain}`, message.token),
                                       redisClient.set(`${message.subdomain}${message.extension}-RingQ`, message.call),
                                       redisClient.set(`IOS-${message.subdomain}@${message.extension}`, message.call, {EX:60})
                                  ]
                             );
                             } catch (err) {
                             console.error('[WS] Redis error after push success:', err.message);
                             }
                          });
                        
                          ws.on('close', () => {
                            console.log('[WS] Connection closed — will reconnect on next push');
                            sharedWs = null;
                        
                            // Reject all pending callbacks
                            for (const [id, pending] of pendingCallbacks) {
                              clearTimeout(pending.timer);
                              pending.reject(new Error('WebSocket closed before response'));
                            }
                            pendingCallbacks.clear();
                          });
                        
                          ws.on('error', (error) => {
                            console.error('[WS] WebSocket error:', error.message);
                          });
                        
                          sharedWs = ws;
                          return ws;
                        }


                        async function pushVoip(payload, token, timeoutMs = 5000) {
                            return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push
                            console.log('[pushVoip] Sending to token:', token, payload);

                            const message = {
                                token,
                                payload,
                                device: 'ios',
                                status: 'single',
                            };

                            return new Promise((resolve, reject) => {
                                const timer = setTimeout(() => {
                                pendingCallbacks.delete(token);
                                reject(new Error(`pushVoip timed out for token ${token}`));
                                }, timeoutMs);

                                pendingCallbacks.set(token, { resolve, reject, timer });

                                const ws = getWebSocket();
                                const send = () => ws.send(JSON.stringify(message));

                                if (ws.readyState === WebSocket.OPEN) {
                                send();
                                } else if (ws.readyState === WebSocket.CONNECTING) {
                                ws.once('open', send);
                                ws.once('error', (err) => {
                                    clearTimeout(timer);
                                    pendingCallbacks.delete(token);
                                    reject(err);
                                });
                                } else {
                                clearTimeout(timer);
                                pendingCallbacks.delete(token);
                                reject(new Error(`WebSocket not available (state: ${ws.readyState})`));
                                }
                            });
                        }

                        async function getTokenIOS(payload, arg = null, token = null,) {
                        return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push
                        console.log(`PAYLOAD IOS`, payload, arg);
                        
                        
                        const { call, extension, domain, caller_id } = payload;

                        const [androidDeviceOff, allKeys] = await Promise.all([
                            redisClient.sMembers(`deviceOff-${extension}@${domain}`),
                            redisClient.sMembers(`iosDevicePushToken-${extension}@${domain}`),
                        ]);

                        // Validate and clean up stale tokens in parallel
                        //   const pTokens = (
                        //     await Promise.all(
                        //       allKeys.map(async (token) => {
                        //      //    const exist = await redisClient.exists(`iosTokenActive-${extension}@${domain}:${token}`);
                        //      //    if (!exist) await redisClient.sRem(`iosDevicePushToken-${extension}@${domain}`, token);
                        //      //    return exist ? token : null;
                        //           return token
                        //       })
                        //     )
                        //   ).filter(Boolean);

                        const pTokens = allKeys.filter((key)=> {
                            if(key !== token){
                                return key
                            }
                        })

                        console.log(`IOS🍷`, pTokens);

                        if (!pTokens.length) return; // early exit — nothing to push to
                        console.log("adding it now", `validatePark-${payload.extension}`);

                        // conn.api(`uuid_transfer ${hangupCallId} *99${arg} XML ${socket.handshake.auth.channel}`);
                       // conn.api(`sched_transfer +30 ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);
                        redisClient.set(`validatePark-${payload.extension}`, 1);
                        const activeCalls = await redisClient.sMembers(`activeCall-${extension}@${domain}`);
                        const availableTokens = pTokens.filter((token) => !activeCalls.includes(token));
                        console.log('TOKEN CALLS IOS GET TOKEN2', { pTokens, activeCalls, availableTokens });
                        const TERMINAL_CALLS = new Set(['decline', 'missed', 'answered', 'answered2', '']);
                        const avatar = `https://${domain}:8443/img/ringq-square.png`;

                        if(call === ''){
                            const pushPayload = { ...payload, avatar };
                            if (availableTokens.length === 1) {
                                const existToken = await redisClient.exists(availableTokens[0]);
                                console.log("pushvoippp", availableTokens[0]);
                                    // EMAN ADDED 08-11-26
                                const preventSelf =  await redisClient.exists(`prevent-push-ios-${availableTokens[0]}`);
                                if (preventSelf) return;
                                // EMAN ADDED 08-11-26

                                if (existToken) pushVoip({ ...pushPayload, pushToken: availableTokens[0] }, availableTokens[0]);

                            } else {
                                console.log("falsering 1")
                                conn.api(`uuid_broadcast ${call} misc/falsering.wav aleg`);
                                pushVoipMutipleDevice(pushPayload, availableTokens);
                            }
                            return;
                        }

                        // --- Incoming call (new ring) ---
                        if (!TERMINAL_CALLS.has(call) && availableTokens.length && !androidDeviceOff.length) {
                        //     if(Number(caller_id) < 10000){
                        //      // conn.api(`sofia_count_reg internal/${arg}@${domain}`, async({ body }) => {
                        //      //      if (Number(body) == 0){
                        //      //           // conn.api(`uuid_broadcast ${call} misc/falsering.wav aleg`);
                        //      //      };
                        //      // })
                        //     }
                            console.log('Single Device IOS');
                            await redisClient.set(`${domain}${extension}`, call);
                            const pushPayload = { ...payload, avatar };
                            if (availableTokens.length === 1) {
                            conn.api(`uuid_broadcast ${call} misc/falsering.wav aleg`);
                            const existToken = await redisClient.exists(availableTokens[0]);
                            
                            // EMAN ADDED 08-11-26
                            const preventSelf =  await redisClient.exists(`prevent-push-ios-${availableTokens[0]}`);
                            if (preventSelf) return;
                            // EMAN ADDED 08-11-26
                            console.log("adding", `pushNotification-${payload.caller_id}@${payload.domain}`, `pushNotificationOther-${payload.caller_id}@${payload.domain}`);
                            redisClient.set(`pushNotification-${payload.caller_id}@${payload.domain}`, JSON.stringify(pushPayload), { EX: 300});
                            redisClient.set(`pushNotificationOther-${payload.caller_id}@${payload.domain}`, availableTokens[0], { EX: 300});
                            if (existToken) pushVoip({ ...pushPayload, pushToken: availableTokens[0] }, availableTokens[0]);
                            } else {
                            conn.api(`uuid_broadcast ${call} misc/falsering.wav aleg`);
                            pushVoipMutipleDevice(pushPayload, availableTokens);
                            }
                            return;
                        }

                        // --- Answered on another device (answered2) ---
                        if (call === 'answered2') {
                            const normalizedPayload = { ...payload, call: 'answered' };
                            const activeCall = await redisClient.get(`${domain}${extension}-RingQ`);
                            if (!activeCall) return;

                            const activeCalls2 = await redisClient.sMembers(`activeCall-${extension}@${domain}`);
                            const remainingTokens = pTokens.filter((t) => !activeCalls2.includes(t));

                            if (!remainingTokens.length) return;

                            if (remainingTokens.length === 1) {
                            const existToken = await redisClient.exists(remainingTokens[0]);
                            if (existToken) pushVoip(normalizedPayload, remainingTokens[0]);
                            } else {
                            pushVoipMutipleDevice(normalizedPayload, remainingTokens);
                            }
                            return;
                        }

                        // --- Decline ---
                        if (call === 'decline') {
                            const activeCalls2 = await redisClient.sMembers(`activeCall-${extension}@${domain}`);
                            const remainingTokens = pTokens.filter((t) => !activeCalls2.includes(t));

                            console.log('🎪',remainingTokens)

                            if (!remainingTokens.length) return;

                            const results = await Promise.all(
                            remainingTokens.map(async (token) => {
                                const active = await redisClient.get(`activeCallEnd@${domain}-${token}`);
                                return active ? null : token;
                            })
                            );
                            const finalTokens = results.filter(Boolean);
                            console.log('🎪🎪',finalTokens)

                            if (!finalTokens.length) return;

                            const declinePayload = { ...payload, call: '' };

                            if (finalTokens.length === 1) {
                            const existToken = await redisClient.exists(finalTokens[0]);
                            if (existToken) pushVoip(declinePayload, finalTokens[0]);
                            } else {
                            pushVoipMutipleDevice(declinePayload, finalTokens);
                            }
                        }

                        }


async function showcallsEventRefresh(subdomain){
     
     setTimeout( async () => {
          conn.api(`show calls as json`, res => {
               console.log('RES***', res);
               socketClient.emit(`progressRinging`, {data:res.body, subdomain: subdomain});                         
          })
     }, 4000)

}

const presenceId = async (presence) => {
  const result = presence.match(/\/([^/@]+)@/)?.[1];
  const result2 = presence.match(/sofia\/\w+\/(\d+)/)?.[1];
  const dest = result || result2;
  return dest;
};
