Error.stackTraceLimit = Infinity;
require('./quietlog'); // [perf] summarise big objects in console.log

const fs = require("fs");

// [reg-match] a registration belongs to <id> when its Auth-User is <id> or its Contact's SIP user
// is exactly <id>. (Was Contact.includes(id): a short id like "1031" matched any contact that
// merely contains those digits - e.g. port 51031 - and showed the wrong extension as ringing.)
// [tenant-reg] a registration belongs to the tenant in its Auth-Realm (or the domain part of User).
// `sofia status profile internal reg` lists every tenant on the server, so callers filter with this.
function regDomain(r) {
     if (!r) return '';
     const realm = String(r['Auth-Realm'] || '').trim();
     if (realm) return realm.toLowerCase();
     const u = String(r.User || '');
     const at = u.lastIndexOf('@');
     return at >= 0 ? u.slice(at + 1).trim().toLowerCase() : '';
}
function regInDomain(r, domain) {
     if (!domain) return true;
     return regDomain(r) === String(domain).trim().toLowerCase();
}

function regMatch(x, id) {
     if (!x || id === undefined || id === null || id === '') return false;
     const s = String(id);
     if (x['Auth-User'] === s) return true;
     const c = String(x.Contact || '');
     const m = c.match(/sip:([^@;>\s]+)@/);
     if (m && m[1] === s) return true;
     // desk phones label the line '"1004 | Name" <sip:...>' or '"1004" <sip:...>': that number counts too
     const d = c.match(/^\s*"\s*([^"|]*?)\s*[|"]/);
     return Boolean(d && d[1] === s);
}
const path = require("path");
const esl = require("modesl");
const redis = require("redis");
const { Pool } = require("pg");
const { v4: uuidv4, validate } = require("uuid");
const { io } = require("socket.io-client");
const util = require('util');
const cron = require('node-cron');
const WebSocket = require('ws');

const ffmpegStatic = require('ffmpeg-static');
const ffmpeg = require('fluent-ffmpeg');

ffmpeg.setFfmpegPath(ffmpegStatic);
require('dotenv').config({ path: '/etc/ringq/.env' });

require('./servermode')('server'); // [server-mode] production/debug logging, see servermode.js

const admin = require('firebase-admin');
const serviceAccount2 = require('./secret.json');
const { rejects } = require("assert");
const journey = require("./callJourney"); // [call-journey]
const outboundPin = require("./outboundPin"); // [outbound-pin]
const ringPush = require("./ringPush"); // [ring-push]
const apns = require("./apns"); // [apns-direct]
try { require("./turnCredentials").start(); } catch (e) { console.log("[turn] not started:", e.message); } // [turn]
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount2)
});

let redisClient;
let socketClient;
let socketClient2;
let socketClientQueue;
// let socketClientGel
let eslReady = false;

const ESL_RETRY_MIN_MS = 3000;
const ESL_RETRY_MAX_MS = 60000;
const GATEWAY_STATUS_TTL_SECONDS = 180;
const GATEWAY_WATCH_KEY = 'gatewayStatusWatch';
const GATEWAY_WATCH_INTERVAL_MS = 3000;
const gatewayStatusPrevious = new Map();
let gatewayRegStatusRunning = false;

const db2 = new Pool({
     user: process.env.PostgreSQL_DB_USER,
     host: process.env.PostgreSQL_DB_HOST,
     database: process.env.PostgreSQL_DB_NAME,
     password: process.env.PostgreSQL_DB_PASSWORD,
     port: Number(process.env.PostgreSQL_DB_PORT),
});

db2.connect((err, client, release) => {
     if (err) {
          console.error('Error acquiring client', err.stack);  
          return;
     }else{
          console.log('Postgress 2 Database connected successfully!');
     }
});

(async () => {
     redisClient = redis.createClient({
          socket: {
               path: '/var/run/redis/redis-server.sock'
          }
     });
     redisClient.on("error", (error) => console.error(`Error : ${error}`));
     await redisClient.connect();
     // [apns-direct] off unless APNS_* is set in /etc/ringq/.env. A token Apple says is gone
     // (app deleted / reinstalled, or not one of our apps) is removed so it isn't pushed again.
     apns.init({
          redis: redisClient,
          onDeadToken: async (token, reason) => {
               const { rowCount } = await db2.query('DELETE FROM q_devices WHERE push_token = $1', [token]);
               // SCAN, not KEYS: no blocking Redis call on a live server
               for await (const k of redisClient.scanIterator({ MATCH: 'iosDevicePushToken-*', COUNT: 500 })) {
                    await redisClient.sRem(k, token).catch(() => {});
               }
               console.log('[apns] dead token removed', token.slice(0, 12), reason, 'devices', rowCount);
          },
     });
     ringPush.init({ redis: redisClient, db: db2, firebaseAdmin: admin }); // [ring-push]
})();

socketClient = new io("ws://127.0.0.1:3000");
socketClient.connect();

socketClient2 = new io("ws://127.0.0.1:4060");
socketClient2.connect();

socketClientQueue = new io("ws://127.0.0.1:4072");
socketClientQueue.connect();

// socketClientGel = new io("ws://127.0.0.1:3333");
// socketClientGel.connect();

let conn;

/** New ESL Connection **/
let eslRetryTimer = null;
let eslRetryDelay = ESL_RETRY_MIN_MS;

function scheduleEslReconnect() {
  if (eslRetryTimer) return;
  const delay = eslRetryDelay;
  eslRetryDelay = Math.min(eslRetryDelay * 2, ESL_RETRY_MAX_MS);
  console.log(`🔁 ESL reconnect in ${delay}ms`);
  eslRetryTimer = setTimeout(() => {
    eslRetryTimer = null;
    connectESL();
  }, delay);
}

function connectESL() {

  console.log('🔌 Connecting to FreeSWITCH ESL...');

  if (conn) {
    try { conn.removeAllListeners(); } catch (err) { console.error('❌ ESL detach failed', err); }
  }

  conn = new esl.Connection(
    '127.0.0.1',
    8021,
    'ClueCon'
  );

  conn.on('esl::ready', () => {
    eslReady = true;
    eslRetryDelay = ESL_RETRY_MIN_MS;
    if (eslRetryTimer) {
      clearTimeout(eslRetryTimer);
      eslRetryTimer = null;
    }
    console.log('✅ ESL connected');
//     socketClientGel.emit('test','test1');
    conn.subscribe(['CUSTOM avmd::beep']);
    conn.events('json', 'all');
  });

  conn.on('error', (err) => {
    eslReady = false;
    console.error('❌ ESL error', err);
    scheduleEslReconnect();
  });

  conn.on('esl::end', () => {
    eslReady = false;
    console.log('🔁 ESL disconnected, retrying...');
    scheduleEslReconnect();
  });

  conn.on('esl::event::**', handleEvent);
  ringPush.attach(conn); // [ring-push]
  conn.on('esl::event::CHANNEL_HANGUP_COMPLETE::**', iosStopOnHangup); // [apns-direct]
}
connectESL();

const extensionRules = [
  { min: 9000, max: 9500, type: 'Queue' },
  { min: 3000, max: 3100, type: 'SpecialIVR' },
  { min: 5555, max: 5600, type: 'AI' },
  { min: 7777, max: 7780, type: 'ExternalAI' },
  { min: 6000, max: 6500, type: 'IVR' },
  { min: 2000, max: 2500, type: 'Teams' },
  { min: 4000, max: 4020, type: 'Conference' },
  { min: 10000, max: Infinity, type: 'ExternalNumber' },
];

async function handleEvent(ev, h, bd) {

  if (!bd || !isValidJSON_FS(bd)) return;
     const event = ev.getHeader('Event-Name');
     if (!event) return;
     try { await journeyOnEvent(ev); } catch (e) { console.error('[call-journey]', e?.message || e); } // [call-journey]
     // console.log(`✅️`, 
     //      util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     // );
     try {
          switch (event) {
          case "CHANNEL_CREATE":
               await handleChannelCreate(ev, bd, conn);
               break;
          case "HEARTBEAT":
               await handleHeartbeat(ev, bd, conn);
               break;
          case "RE_SCHEDULE":
               await handleReSched(ev, bd, conn);
               break;
          case "DEL_SCHEDULE":
               await handleDelSchedule(ev, bd, conn);
               break;
          case "CUSTOM":
               await handleCustom(ev, bd, conn);
               break;
          case "CHANNEL_BRIDGE":
               attHandleBridge(ev).catch(() => {}); // [att-vm] / [att-answered]
               await handleChanneBridge(ev, bd, conn);
               break;
          case "CHANNEL_CALLSTATE":
               await handleChannelCallState(ev, bd, conn);
               break;
          case "API":
               await handleAPI(ev, bd, conn);
               break;
          case "RECORD_START":
               await handleRecordStart(ev, bd, conn);
               break;
          case "RECORD_STOP":
               await handleRecordStop(ev, bd, conn);
               break;
          case "CHANNEL_STATE":
               await handleChannelState(ev, bd, conn);
               break;
          case "PRESENCE_IN":
               await handlePresenceIn(ev, bd, conn);
               break;
          case "CHANNEL_ANSWER":
               await handleAnswer(ev, bd, conn);
               break;
          case "DTMF":
               await handleDTMF(ev, bd, conn);
               break
          case "CHANNEL_PARK":
               await handlePark(ev, bd, conn)
               break;
          case "CHANNEL_HANGUP":
               await handleHangUp(ev, bd, conn);
               break;
          case "ADD_SCHEDULE":
               await addSchedule(ev, bd, conn);
               break;
          case "CHANNEL_DESTROY":
               await handleDestroy(ev, bd, conn);
               break;
          case "BACKGROUND_JOB":
               await playbackStart(ev, bd, conn);
               break;
          case "CHANNEL_OUTGOING":
               await channelOutgoing(ev, bd, conn);
               break;
          
          case "CHANNEL_HANGUP_COMPLETE":
               handleAttendedConsultHangup(ev); // [att-recover]
               await channelHangupComplete(ev, bd, conn);
               break;

          case "CHANNEL_EXECUTE":
               await handleAttendedConsultExecute(ev); // [att-recover]
               break;

          default:
               console.log(`Unhandled ESL event: ${event}`);
          }
     } catch (error) {
          console.error("❌ handleEvent Error:", error);
     }

}
/** End */

async function showcallsEventRefresh(subdomain){

     function apiPromise(command){
          return new Promise((resolve, reject) => {
               conn.api(command, (res) => {
                    if (res) resolve(res);
                    else reject(new Error("API returned no result"));
               });
          });
     }
     const res = await apiPromise("show calls as json");
     socketClient.emit("progressRinging", {
          data: res.body,
          subdomain
     });

}

async function prevActiveCallsRefresh(subdomain, duration, uid){
     socketClient.emit(`prevActiveCallsRefresh`, {
          subdomain: subdomain, 
          duration: duration, 
          uid: uid
     });
}

async function deleteConferenceHost(extension, domain) {
     
     try {
          console.log(`check this: host:conference:number+${extension}@${domain}`);
          let data = await redisClient.exists("host:conference:number"+extension+"@"+domain)
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });
          if(data){
               console.log("deleteConferenceHost is triggered", data);
               socketClient.emit("conferenceExtensionRemove", extension+"@"+domain);
               await redisClient.del("host:conference:number"+extension+"@"+domain)
               .catch(error => {
                    // Handle the error here
                      console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                      console.error("Stack Trace:", error.stack);

               });
          }
     } catch (error) {
          console.error(error);
     }

} 

async function conferenceUUID(inbound, outbound, caller, calle, domain, callerName){
     try {
          if(inbound != ""){
               console.log("this isthe conference", inbound);
               await redisClient.set("conference:inbound"+caller+"@"+domain, inbound, {EX: 3600})
               await redisClient.set("conference:inbound"+calle+"@"+domain, outbound, {EX: 3600})
               await redisClient.set("conference:outbound"+calle+"@"+domain, inbound, {EX: 3600})
              
          }else{
               if(callerName == 'Conference'){
                   // Caller-Caller-ID-Number
                    socketClient.emit("conferenceExtensionAnswer", {extension:calle, domain:domain}, (error, response) => {
                         if(error){
                              console.error('Error:', error);
                         }else{
                              
                         }
                    });
                    
                    await redisClient.set("invited:conference:number"+calle+"@"+domain, outbound)
                    .catch(error => {
                         // Handle the error here
                           console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                           console.error("Stack Trace:", error.stack);

                    });

                    await redisClient.set("host:conference:number"+caller+"@"+domain, outbound)
                    .catch(error => {
                         // Handle the error here
                         console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                         console.error("Stack Trace:", error.stack);
                    });

               }
               await redisClient.set("conference:outbound"+calle+"@"+domain, outbound)
          }
     } catch (error) {
          console.error(error);
     }
}

async function internalPush2(payload, cause){
     try {

          if(
               classifyNumber(Number(payload.extension)) === 'InternalExtension'
          ){

               let exist = await redisClient.exists(payload.extension+"+"+payload.domain)
               .catch(error => {
                    // Handle the error here
                    console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                    console.error("Stack Trace:", error.stack);
               });

               if(exist == 0){
                   await redisClient.set(payload.extension+"+"+payload.domain, "0")
                   .catch(error => {
                      // Handle the error here
                      console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                      console.error("Stack Trace:", error.stack);
                   });
               }
          
               var position = await redisClient.get(payload.extension+"+"+payload.domain);
               if(position !== null || position !== ""){

                    var num = parseInt(position);
                    let forwardAll = await redisClient.lRange(payload.extension+"@"+payload.domain+"-forward-all-enabled", num, num)
                    .catch(error => {
                         // Handle the error here
                           console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                           console.error("Stack Trace:", error.stack);
                    });

                    let callRelay = await redisClient.lRange(payload.extension+"@"+payload.domain+"-forward-me-enabled", num, num)
                    .catch(error => {
                         // Handle the error here
                         console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                         console.error("Stack Trace:", error.stack);
                    });
                    
                    if(forwardAll == 'false'){
                         var timeout = await redisClient.lRange(`${payload.extension}@${payload.domain}-forward-no-answer-timeout`, num, num)
                         .catch(error => {
                              // Handle the error here
                                console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                console.error("Stack Trace:", error.stack);

                         });
                         let realtimeOut = timeout * 1000;

                         if(timeout){

                              cacheCalls(payload.domain, payload.extension, payload.call);
                              payload.time2 =  payload.time2 || Number(parseInt(timeout) * 1000000);

                              getToken(payload, cause);
                              let host = `${payload.extension}@${payload.domain}`;
     
                              if(callRelay == 'false'){

                                   await Promise.all([
                                        redisClient.set("decline:" + payload.caller_id +"@"+ payload.domain, payload.call, { EX: timeout }).catch(error => {
                                             // Handle the error here
                                               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                               console.error("Stack Trace:", error.stack);
                                        }),
                                        redisClient.set("decline:" + payload.extension +"@"+ payload.domain, payload.call, { EX: timeout })
                                        .catch(error => {
                                             // Handle the error here
                                               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                               console.error("Stack Trace:", error.stack);
                                        })
                                   ]);

                              }else{                     
                                   await Promise.all([
                                        redisClient.set("decline:" + payload.caller_id+"@"+payload.domain, payload.call, { EX: timeout })
                                        .catch(error => {
                                             // Handle the error here
                                               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                               console.error("Stack Trace:", error.stack);

                                        }),
                                        redisClient.set("decline:" + payload.extension+"@"+payload.domain, payload.call, { EX: timeout })
                                        .catch(error => {
                                             // Handle the error here
                                               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                               console.error("Stack Trace:", error.stack);

                                        })
                                   ]);
                              }
          
                         }
          
                    }
               }
               
          }

     } catch (error) {
          console.error(error.message);
     }
}

async function delExternal(uuid, hostCaller, caller){
     try {
          
          await redisClient.del(hostCaller)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
          await redisClient.set("external-"+hostCaller, 0)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
     } catch (error) {
          console.error("Error data from Redis:", error.message);
     }
}

async function deleteDataRedis(data){

     try {
         
          await redisClient.del(`uuidAnswerQueue${data}`)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
          await redisClient.del(`conference:inbound${data}`)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
          await redisClient.del(`conference:outbound${data}`)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
          await redisClient.del(`invited:conference:number${data}`)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
     } catch (error) {
          console.error(error.message)
     }
}

async function unique_uuidAnswer(data){

     try {
          
          await redisClient.set(`uuidAnswer${data.extensionCaller}@${data.domain}`, JSON.stringify({uuid:data.uuid, date:data.date, callerName:data.callerName, extension: data.extensionCalle}),{ NX: true })
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
     } catch (error) {
          console.error(error.message);
     }
    
}

async function unique_uuidAnswer_outbound(data){

     try {

          
          await redisClient.set(`uuidAnswer${data.extensionCalle}@${data.domain}`, JSON.stringify({uuid:data.uuid, date:data.date, callerName:data.callerName, extension:data.extensionCaller}), { NX: true })
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });
     
          await redisClient.set(`outboundExtensionCaller${data.extensionCalle}@${data.domain}`, data.extensionCaller)
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });

     } catch (error) {
          console.error(error.message);
     }
    
}

async function unique_uuidAnswer_outbound3(data){

     try {
          let caller =  await redisClient.get("parkingOutbound:"+data.origCaller)
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });
          
          if(caller){
               let ext = caller?.split("@");
               
               await redisClient.set(`uuidAnswer${data.extensionCalle}@${data.domain}`, JSON.stringify({uuid:data.uuid, date:data.date}))
               .catch(error => {
                    // Handle the error here
                    console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                    console.error("Stack Trace:", error.stack);

               });
              
          }
     } catch (error) {
          console.error(error.message);
     }

}

async function unique_uuidAnswer_Queue(data){
     
     try {

          await redisClient.set(`uuidAnswerQueue${data.extension}@${data.domain}`, JSON.stringify({uuid: data.uuid, date: data.date}))
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);
          });

     } catch (error) {
          console.error("Error retrieving data from Redis9:", error.message);
     }
    
}

async function internalCall(uuid, host, host1){

     try {
          
          await redisClient.set(`${host}-internalCall`, uuid, { EX: 172800 }) // [perf] TTL
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });

          await redisClient.set(`${host1}-internalCall`, uuid, { EX: 172800 }) // [perf] TTL
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);
          });

          let extension = host?.split("@");
          
          let extension1 = host1?.split("@");
          
          var position = await redisClient.get(extension[0]+"+"+extension[1])
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });
          
          if(position){
               var num = parseInt(position);
               
               var timeout = await redisClient.lRange(`${extension[0]}@${extension[1]}-forward-no-answer-timeout`, num, num)
               .catch(error => {
                    // Handle the error here
                      console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                      console.error("Stack Trace:", error.stack);

               });
               
               if(timeout.length > 0){
                    await Promise.all([
                         redisClient.set("decline:" + host1, uuid, { EX: timeout })
                         .catch(error => {
                              // Handle the error here
                                console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                console.error("Stack Trace:", error.stack);

                         }),
                         redisClient.set("decline:" + host, uuid, { EX: timeout })
                         .catch(error => {
                              // Handle the error here
                                console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                                console.error("Stack Trace:", error.stack);

                         })
                    ]);
               }
               
          }
     } catch (error) {
          console.error(error.message);
     }

}

async function unique_uuidInsertCaller(data) {
     if(data.extensionCaller.length <= 8){
          try {
               await redisClient.set(`attendedTransfer${data.extensionCaller}`, JSON.stringify({uuid:data.uuid, date:data.date}), {EX: 1800})
               .catch(error => {
                    // Handle the error here
                      console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                      console.error("Stack Trace:", error.stack);

               });
           } catch (error) {
               console.error("Error retrieving data from Redis10:", error.message);
           }
     }
}

async function callIdInteruptDelete(host) {
     try {
          await redisClient.del("queInterupt:" + host)
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);
          });
     } catch (error) {
          console.error("Error retrieving data from Redis14:", error.message);
     }
}

async function callId(data, host) {
     try {
          console.log("this is callId", data, host)
          await redisClient.set("que:" + host, data)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
          
          await redisClient.set("queInterupt:" + host, data)
          .catch(error => {
               // Handle the error here
               console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
               console.error("Stack Trace:", error.stack);
          });
     } catch (error) {
          console.error("Error retrieving data from Redis15:", error.message);
     }
}

const callIdQueue = async (callId, channelName, domain) => {

     const channelExactName = await presenceId(channelName);
     const value = await fsRegistration();
     const findCurrentPresence = value.find(x => regMatch(x, channelExactName));
     if(!findCurrentPresence) return;

     const { "Auth-User": authUser } = findCurrentPresence;
     const { rows } = await db2.query(
     "SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
     [authUser, domain]
     );

     if(rows.length){
          const extension = rows[0].extension;
          // console.log(`<👇>`, `que:${extension}@${domain}`, callId);
          await redisClient.set(`que:${extension}@${domain}`, callId);
          await redisClient.set(`que:${authUser}@${domain}`, callId);
     }

}

async function callHangUp(host) {
     
     try {
          var uniqueIds = await redisClient.lRange("inprogress" + "-" + host, 0, -1)
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);
          });

     } catch (error) {
          console.error("Error data from Redis:", error.message);
     }

}

async function waitingCalls(host, extension, agent, contact, domainId, aliasName, deviceUniqueId, callUniqueId, token) {

     try {
          
          let uniqueId2 = await redisClient.get(`${host}${extension}-RingQ`);
          const tokenIos = await redisClient.get(`devicePushtoken-${deviceUniqueId}`);
          const pushOngoingExist = await redisClient.exists(`push-${tokenIos}-sucess`);
          console.log(`EST..`, pushOngoingExist)

          console.log(`❄️`, {
             host, 
             extension, 
             agent, 
             contact, 
             domainId, 
             aliasName,
             deviceUniqueId
          }, uniqueId2);
          const callExist = await new Promise((resolve) => {
               conn.api(`uuid_exists ${uniqueId2}`, ({body}) => {
                    resolve(body);
               });
          });
          
          console.log(`PRESENCE REG`, uniqueId2, extension, host);
          if (uniqueId2 && callExist === 'true') {
               conn.api(`uuid_dump ${uniqueId2} JSON`, async ({ body }) => {
               if (body === '-ERR No such channel!\n') return;

               const data = JSON.parse(body);

               // 1. Run independent async calls in parallel
               const [callerPresence, registrations] = await Promise.all([
                    presenceId(data['Channel-Name']),
                    fsRegistration(),
               ]);

               const findCurrentPresence = registrations.find(
                    x => regMatch(x, callerPresence)
               );

               console.log(`UNIQUE ID2`, findCurrentPresence, callerPresence, agent);
               if (!findCurrentPresence){

                    const sipContact = contact?.split('sip:')[1].replace('>', '');
                    const deviceId = agent?.split(' - ')[1];
                    const extContact = contact?.split(';')[0]?.split('sip:')[1];

                    // 3. Fire-and-forget Redis writes (nothing depends on them)
                    redisClient.set(`devicePresence-${extension}@${host}`, extContact);
                    redisClient.set(`mobileSIP-${extContact}@${host}`, extContact);

                    // 4. Batch setvar into a single round-trip
                    conn.api(`uuid_setvar_multi ${uniqueId2} agent_alias=${aliasName};agent_subdomain=${host};sip_uri_presence=${sipContact};deviceId=${deviceId}`);

                    if (!agent.includes('RingQ iOS')) return;

                    const aliasNamePresence = `${aliasName}@${host}`;
                    const fullClosed = await redisClient.get(`iosFullyClose-${extension}@${host}-${deviceId}`);
                    const label = fullClosed ? 'FULLY IOS ORIGINATE' : 'FULLY IOS ORIGINATE2';


                    conn.bgapi(
                         `originate {deviceId=${deviceId},domain_uuid=${domainId},presence_id=${aliasNamePresence},accountcode=${host},domain_name=${host},origination_number=${callerPresence},origination_caller_id_name='${callerPresence}',origination_caller_id_number=${callerPresence}}sofia/internal/sip:${sipContact} &park()`,
                         ({ body }) => {
                              console.log(label, body);
                              const iosCallId = body.split(' ')[1];
                              console.log(`🍻`, uniqueId2, iosCallId);
                              conn.api(`uuid_setvar ${uniqueId2} ios_token ${token}`);
                              conn.api(`uuid_bridge ${uniqueId2} ${iosCallId}`);

                         }
                    );

                    return;
               } 
               const { 'Auth-User': authUser } = findCurrentPresence;

               // 2. DB query (depends on authUser, can't parallelize further)
               const { rows } = await db2.query(
                    'SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2',
                    [authUser, host]
               );

               const sipContact = contact?.split('sip:')[1].replace('>', '');
               const deviceId = agent?.split(' - ')[1];
               const extContact = contact?.split(';')[0]?.split('sip:')[1];

               // 3. Fire-and-forget Redis writes (nothing depends on them)
               redisClient.set(`devicePresence-${extension}@${host}`, extContact);
               redisClient.set(`mobileSIP-${extContact}@${host}`, extContact);

               // 4. Batch setvar into a single round-trip
               conn.api(
                    `uuid_setvar_multi ${uniqueId2} agent_alias=${aliasName};agent_subdomain=${host};sip_uri_presence=${sipContact};deviceId=${deviceId}`
               );

               console.log(`🍻2`, uniqueId2);

               if (!agent.includes('RingQ iOS')) return;

               const aliasNamePresence = `${aliasName}@${host}`;
               const fullClosed = await redisClient.get(`iosFullyClose-${extension}@${host}-${deviceId}`);

               // 5. Bug fix: choose caller IDs based on whether DB row was found
               const hasRow = rows.length > 0;
               const extensionOrig = hasRow ? rows[0].extension : callerPresence;
               const callerIdName  = hasRow ? rows[0].effective_caller_id_name : callerPresence;
               const callerIdNum   = hasRow ? extensionOrig : callerPresence;

               // 6. fullClosed vs background path had identical logic — unified into one
               const label = fullClosed ? 'FULLY IOS ORIGINATE' : 'FULLY IOS ORIGINATE2';

               conn.bgapi(
                    `originate {deviceId=${deviceId},domain_uuid=${domainId},presence_id=${aliasNamePresence},accountcode=${host},domain_name=${host},origination_number=${extensionOrig},origination_caller_id_name='${callerIdName}',origination_caller_id_number=${callerIdNum}}sofia/internal/sip:${sipContact} &park()`,
                    ({ body }) => {
                    console.log(label, body);
                    const iosCallId = body.split(' ')[1];
                    console.log(`🍻3`, uniqueId2, iosCallId);
                    conn.api(`uuid_setvar ${uniqueId2} ios_token ${token}`);
                    conn.api(`uuid_bridge ${uniqueId2} ${iosCallId}`);
                    }
               );
               });
          }

          if(uniqueId2 && !pushOngoingExist){

               try {
                    const result = await fs_command(`uuid_dump ${uniqueId2} JSON`);
                    console.log("HAVE ANSWER ID:", result);
                    if(result.body !== '-ERR No such channel!\n'){

                         const data = JSON.parse(result.body);
                         console.log(`DUMP CALL`, data);                  
                         const caller = await presenceId(data['Channel-Name']);;
                         const aliasNamePresence = `${aliasName}@${host}`
                         const exists2 = await promisifiedApiSync(`uuid_exists ${uniqueId2}`);

                         if (exists2.body == 'true') {

                              const extContact = contact?.split(';')[0]?.split('sip:')[1];
                              await redisClient.set(`devicePresence-${extension}@${host}`, extContact);
                              await redisClient.set(`mobileSIP-${extContact}@${host}`, extContact);

                              // The Android app used to take the call here: a REGISTER during a ringing
                              // call moved it to bridge_to_agent for that phone alone, cancelling the web
                              // app and desk phones. RingQ Call Services (mod_ringq) now waits for the app
                              // and rings every device together, so the call is left alone.
                                   
                         }else{
                              console.log("Error to transfer");
                         }
                        
                    }
               } catch (err) {
                    console.error('Error checking UUID:', err);
               }

          }else{
               console.log("NO ANSWER ID:");
          }

     } catch (error) {
          console.error(error.message);
     }

}

async function conferencePushDel(data){
     try {
          await redisClient.del(`conferencePush${data}`)
          .catch(error => {
               // Handle the error here
                 console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                 console.error("Stack Trace:", error.stack);

          });
      } catch (error) {
          console.error("Error data from Redis:", error.message);
      }
}

async function cacheCalls(host, extension, callId) {

     try {
          if(host){
               if (host.length != 0 && extension.length != 0 && callId.length != 0) {
                    
                    await redisClient.set(host + extension, callId)
                    .catch(error => {
                         // Handle the error here
                         console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                         console.error("Stack Trace:", error.stack);
                    });

                    await redisClient.expire(host + extension, 65)
                    .catch(error => {
                         // Handle the error here
                         console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                         console.error("Stack Trace:", error.stack);
                    });
               }
          }
     } catch (error) {
          console.error(error.message);
     }
     
}


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

async function getToken(payload) {

  console.log(`<!-- getTokenHere`, payload);
  return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push

  try {
    const { call, extension, domain, status } = payload;
    const key = `${extension}@${domain}`;
    if (!['decline', 'missed', 'answered', 'answered2'].includes(call) && await pushBlocked(extension, domain)) { console.log('[ext-access] push blocked', key); return; }

    const allKeys =  await redisClient.sMembers(`deviceOff-${key}`);
    const deviceOff = (
    await Promise.all(
          allKeys.map(async (token) => {
          const exist = await redisClient.exists(`androidTokenActive-${key}:${token}`);
          if(!exist){
               await redisClient.sRem(`deviceOff-${key}`, token);
          }
          return exist ? token : null;
    }))).filter(Boolean);

    const deviceOn = await redisClient.sMembers(`deviceOn-${key}`);
    console.log(`༄This is called here༄`, deviceOff);
    const hasAndroidOffline = deviceOff.some(d => !deviceOn.includes(d));
    if (!deviceOff.length) return;
    if (!hasAndroidOffline) return;

    console.log('📲 ', payload);

    const invalidStates = ['decline', 'missed', 'answered', 'answered2'];
    if (!invalidStates.includes(call)){
          await redisClient.set(`${domain}${extension}`, call);
          await redisClient.set(`${domain}${extension}-RingQ`, call);
          const exists = await promisifiedApiSync(`uuid_exists ${call}`);
          console.log('📲 CALL EXISTS 📲', exists);
          if (exists.body !== 'true') return;
          socketClient2.emit('push-call', payload, async (offlineSockets = []) => {
               console.log('⛔ Offline sockets:', offlineSockets, call);
               redisClient.set(`missedPayload-${call}`, JSON.stringify(payload), { EX: 172800 }); // [perf] TTL
               console.log("saved data missed", `missedPayload-${call}`, JSON.stringify(payload));
          });
          console.log('📲 EMIT PUSH CALL', payload);
          //Changed 09-14-26



          ///Changed 09-14-26

    }else{
          console.log('📲 EMIT PUSHBACK', payload);
          socketClient2.emit('push-call', payload);
    }

  } catch (err) {
    console.error('Unhandled getToken:', err.message);
  }
}

async function getTokenIOS(payload, arg = null, token = null,) {
  console.log(`PAYLOAD IOS`, payload, arg);
  return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push

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
  // [devices] "Calls off" / deleted devices get no new-call push (stop-ringing pushes still go out)
  if (!['decline', 'missed', 'answered', 'answered2'].includes(call) && await pushBlocked(extension, domain)) pTokens.length = 0; // [ext-access]
  if (!['decline', 'missed', 'answered', 'answered2'].includes(call)) {
    const muted = await redisClient.sMembers(`devMuted-${extension}@${domain}`).catch(() => []);
    if (muted.length) for (let i = pTokens.length - 1; i >= 0; i--) if (muted.includes(pTokens[i])) pTokens.splice(i, 1);
  }

  if (!pTokens.length) return; // early exit — nothing to push to

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

async function getTokenMultipleDevice(payload, cause = null, token = null) {
  return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push
  const { call, extension, domain } = payload;
  
  // --- Validate and clean up android offline tokens ---
  const allKeys = await redisClient.sMembers(`deviceOff-${extension}@${domain}`);
  const androidDeviceOff = (
    await Promise.all(
      allKeys.map(async (token) => {
        const exist = await redisClient.exists(`androidTokenActive-${extension}@${domain}:${token}`);
        if (!exist) await redisClient.sRem(`deviceOff-${extension}@${domain}`, token);
        return exist ? token : null;
      })
    )
  ).filter(Boolean);

  // --- Validate and clean up iOS tokens ---
  const allIosKeys = await redisClient.sMembers(`iosDevicePushToken-${extension}@${domain}`);
//   const pTokens = (
//     await Promise.all(
//       allIosKeys.map(async (token) => {
//      //    const exist = await redisClient.exists(`iosTokenActive-${extension}@${domain}:${token}`);
//      //    if (!exist) await redisClient.sRem(`iosDevicePushToken-${extension}@${domain}`, token);
//      //    return exist ? token : null;
//           return token
//       })
//     )
//   ).filter(Boolean);

  const pTokens = allIosKeys.filter((key) => {
     if(key !== token){
          return key;
     }
  })

  const [androidDeviceOn, activeCalls] = await Promise.all([
    redisClient.sMembers(`deviceOn-${extension}@${domain}`),
    redisClient.sMembers(`activeCall-${extension}@${domain}`),
  ]);

  const availableTokens = pTokens.filter((t) => !activeCalls.includes(t));
  const androidStatus = androidDeviceOff.some((val) => !androidDeviceOn.includes(val));

  if (!androidDeviceOff.length || !availableTokens.length) return; // early exit

  console.log('[MultiDevice] Android + iOS device flow');

  const TERMINAL_CALLS = new Set(['decline', 'missed', 'answered', 'answered2']);

  // --- Resolve avatar once, shared across all branches ---
  const avatarKey = await redisClient.get(`${payload.caller_id}:${domain}`);
  const avatar = avatarKey
    ? `https://${domain}:8443/avr/${avatarKey}`
    : `https://${domain}:8443/img/ringq-square.png`;

  // --- Incoming call ---
  if (!TERMINAL_CALLS.has(call)) {
    await redisClient.set(`${domain}${extension}`, call);

    const pushPayload = { ...payload, avatar };

    if (availableTokens.length > 1) {
      pushVoipMutipleDevice(pushPayload, availableTokens);
    }

    if(availableTokens.length == 1){
      pushVoipMutipleDevice(pushPayload, availableTokens);
    }

    if (androidStatus) {
      const exists2 = await promisifiedApiSync(`uuid_exists ${call}`);
      if (exists2.body === 'true') {
        await Promise.all([
          redisClient.set(`${domain}${extension}-RingQ`, call),
          redisClient.set(`${domain}${extension}`, call),
        ]);

        setTimeout(() => {
          socketClient2.emit('push-call', pushPayload, (offlineSockets) => {
            console.log('[MultiDevice] Offline sockets:', offlineSockets);
          });
        }, 2000);
      }
    }
    return;
  }

  // --- Answered on another device ---
  if (call === 'answered2') {
    const normalizedPayload = { ...payload, call: 'answered' };
    socketClient2.emit('push-call', normalizedPayload);

    const activeCall = await redisClient.get(`${domain}${extension}-RingQ`);
    if (!activeCall) return;

    // Small delay to let active call state settle
    await new Promise((r) => setTimeout(r, 2000));

    const activeCalls2 = await redisClient.sMembers(`activeCall-${extension}@${domain}`);
    const remainingTokens = pTokens.filter((t) => !activeCalls2.includes(t));

    if (!remainingTokens.length) return;

    if (remainingTokens.length > 1) {
      pushVoipMutipleDevice(normalizedPayload, remainingTokens);
    }

    if (remainingTokens.length == 1) {
      pushVoipMutipleDevice(normalizedPayload, remainingTokens);
    }
    return;
  }

  // --- Decline ---
  if (call === 'decline') {
    socketClient2.emit('push-call', payload);

    const activeCalls2 = await redisClient.sMembers(`activeCall-${extension}@${domain}`);
    const remainingTokens = pTokens.filter((t) => !activeCalls2.includes(t));
    if (!remainingTokens.length) return;

    const results = await Promise.all(
      remainingTokens.map(async (token) => {
        const active = await redisClient.get(`activeCallEnd@${domain}-${token}`);
        return active ? null : token;
      })
    );
    const finalTokens = results.filter(Boolean);
    if (!finalTokens.length) return;

    if (finalTokens.length > 1) {
      pushVoipMutipleDevice(payload, finalTokens);
    }

    if (finalTokens.length == 1) {
      pushVoipMutipleDevice(payload, finalTokens);
    }

  }
}

// async function pushWeb(payload){


//           const webTokens = await redisClient.sMembers(`deviceWebToken-${payload.extension}@${payload.domain}`);
//           console.log(`💸 Web push This is called here`, payload, webTokens);
//           if(webTokens){

//                const web_ws = new WebSocket('ws://157.245.60.143:7770');
//                web_ws.on('open', () => {
//                     console.log('Connected to web push server');
//                     const message = {
//                          webTokens: webTokens,
//                          payload: payload,
//                          status: 'single',
//                     };
//                     web_ws.send(JSON.stringify(message));
//                });

//                web_ws.on('message', async (data) => {
//                     console.log('SUCCESS WEB PUSH!');
//                });

//                web_ws.on('close', () => {
//                     console.log('Connection closed by web push server');
//                });

//                web_ws.on('error', (error) => {
//                     console.error('WebSocket web push error:', error);
//                });

//           }else{
//                console.log('NO WEB TOKEN!');
//           }

// }

async function pushQueue(agentlist, payload, agentExt = null) {

     return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push
     try {

          console.log('QUEUE ORIG PAYLOAD', payload);

          // ADDED EMAN 08-27-26 START
          if (payload.nameCaller) {
            const nameStr = String(payload.nameCaller);
            const callerIdStr = payload.caller_id ? String(payload.caller_id) : '';

            if (callerIdStr) {
              payload.nameCaller = nameStr.includes(callerIdStr) 
                ? nameStr 
                : `${nameStr} ${callerIdStr}`.trim();
            } else {
              payload.nameCaller = nameStr;
            }
          }
          // ADDED EMAN 08-27-26 END


          
          if(payload.call === 'answered'){
               
               const validStates = ['In a queue call', 'Idle'];
               const containerTokenAnswered = await Promise.all(
                    agentlist
                    .filter(element =>{            

                         console.log('QueuePush Answered Android', element);
                         return validStates.includes(element.state) && element.status === 'Available'

                    }).map(async (element) => {

                         const channelExtension = element.contact?.split('user/')[1];
                         const [extension, subdomain] = channelExtension?.split('@');
                         console.log('QueuePush Answered Android3', extension, subdomain);
                         if(extension){

                              const validExtensionOrig =  async(extension) => {
                                   const validExtension = isValidNumber(extension)
                                   if(!validExtension){
                                        const { rows } = await db2.query(
                                        `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                        [extension, subdomain]
                                        );
                                        console.log('Answered ROW', rows);
                                        const extensionOrig = rows[0].extension;
                                        return extensionOrig
                                   }
                                   return extension
                              }
                              const extensionOrig = await validExtensionOrig(extension)
                              const deviceId = await redisClient.get(`deviceId-${extensionOrig}@${subdomain}`); 
                              const androidPresence = await redisClient.get(`androidPresence-${deviceId}-${extensionOrig}@${subdomain}`);
                              if(deviceId && androidPresence){
                                   redisClient.set(`${subdomain}${extensionOrig}-RingQ`, payload.call)
                                   return extensionOrig;
                              }

                         }
                         
                    })
               );
               containerTokenAnswered.forEach((x) => {
                    console.log('Android Answered Queue Agent2:', x);
               });

               const validTokens = containerTokenAnswered.filter(token => token !== undefined);
               const uniqueTokens = [...new Set(validTokens)];
               console.log('All push tokens Queue Android2:', uniqueTokens, payload, containerTokenAnswered);



               const newQueueData = {...payload, agents: uniqueTokens}
               socketClient2.emit('push-queue', newQueueData);
          }

          const validStates = ['Waiting', 'Receiving'];
          const containerToken = await Promise.all(
               agentlist
               .filter(element =>{
                    
                    console.log('QueuePush Android', element);
                    return validStates.includes(element.state) && element.status === 'Available'

               }).map(async (element) => {

                    const channelExtension = element.contact?.split('user/')[1];
                    const [extension, subdomain] = channelExtension?.split('@');
                    console.log('QueuePush Android2', element);
                    if(extension){

                         const validExtensionOrig =  async(extension) => {
                              const validExtension = isValidNumber(extension)
                              if(!validExtension){
                                   const { rows } = await db2.query(
                                   `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                   [extension, subdomain]
                                   );
                                   console.log('Answered ROW', rows);
                                   const extensionOrig = rows[0].extension;
                                   return extensionOrig
                              }
                              return extension
                         }

                         const extensionOrig = await validExtensionOrig(extension)
                         const deviceId = await redisClient.get(`deviceId-${extensionOrig}@${subdomain}`); 
                         const androidPresence = await redisClient.get(`androidPresence-${deviceId}-${extensionOrig}@${subdomain}`);

                         if(deviceId && androidPresence){
                              redisClient.set(`${subdomain}${extensionOrig}-RingQ`, payload.call)
                              return extensionOrig;
                         }

                    }
                    
               })
          );
          containerToken.forEach((x) => {
               console.log('Android Queue Agent:', x);
          });

          const validTokens = containerToken.filter(token => token !== undefined);
          const uniqueTokens = [...new Set(validTokens)];
          const newQueueData = {...payload, agents: uniqueTokens}
          console.log('All push tokens Queue Android:', uniqueTokens, payload, newQueueData);

          //Added 08-17-26
          console.log("setqueuedata", `retriveMissedQueueData-${payload.caller_id}`, JSON.stringify(newQueueData));
          redisClient.set(`retriveMissedQueueData-${payload.caller_id}`, JSON.stringify(newQueueData));
          console.log("setQqeueAgentlist", `retriveMissedQueueAgentlist-${payload.caller_id}`, JSON.stringify(newQueueData));
          redisClient.set(`retriveMissedQueueAgentlist-${payload.caller_id}`, JSON.stringify(agentlist));
          redisClient.set(`queueAgentTrack:${payload.call}@${payload.domain}`, JSON.stringify(agentlist));






          ////Added 08-17-26

          socketClient2.emit('push-queue', newQueueData);
          await Promise.all(
               agentlist
               .filter(element => validStates.includes(element.state) && element.status === 'Available')
               .map(async (element) => {
                         
                         const channelExtension = element.contact?.split('user/')[1];
                         const [extension, subdomain] = channelExtension?.split('@');
                         const validExtensionOrig =  async(extension) => {
                              const validExtension = isValidNumber(extension)
                              if(!validExtension){
                                   const { rows } = await db2.query(
                                   `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                   [extension, subdomain]
                                   );
                                   console.log('Answered ROW', rows);
                                   const extensionOrig = rows[0]?.extension;
                                   if(extensionOrig){

                                        await redisClient.set(`${payload.domain}${payload.extension}-RingQ`, payload.call)
                                        return extensionOrig

                                   }
                                   return extension
                              }
                              return extension
                         }

                         const extensionOrig = await validExtensionOrig(extension)
                         const validateCallId = ['missed','answered'];
                         if(!validateCallId.includes(payload.call)){
                              await redisClient.set(`${subdomain}${extensionOrig}-RingQ`, payload.call);
                         }

                         const pTokens = await redisClient.sMembers(`iosDevicePushToken-${extensionOrig}@${subdomain}`);
                         const activeCalls = await redisClient.sMembers(`activeCall-${extensionOrig}@${subdomain}`);
                         console.log(`Queue Active Calls - ${extensionOrig}@${subdomain}`, pTokens, activeCalls);
                         const mutedQ = ['decline', 'missed', 'answered', 'answered2'].includes(payload.call) ? [] : await redisClient.sMembers(`devMuted-${extensionOrig}@${subdomain}`).catch(() => []); // [devices]
                         const blockedQ = !['decline', 'missed', 'answered', 'answered2'].includes(payload.call) && await pushBlocked(extensionOrig, subdomain); // [ext-access]
                         const pTokens2 = blockedQ ? [] : pTokens.filter(item => !activeCalls.includes(item) && !mutedQ.includes(item));
                         if(pTokens2.length){
                              console.log(`ORIG PAYLOAD Q => ${JSON.stringify(payload)}`);
                              return pTokens2;
                         }

               })
          );

          // newQueueData.call = "missed";

          // setTimeout(()=>{
          //    socketClient2.emit('push-queue', newQueueData);
          //    console.log("should emit missed")
          // }, 30000);


     } catch (error) {
     console.error(error.message);
     }
}

async function pushQueueIOS(agentlist, payload, agentExt = null) {

     console.log("pushQueueIOS -- payload", payload);
     return; // [old-push] retired: ring push (mod_ringq + ringPush.js) is the only call push
     
     if (payload.status === 'QueueBridge' && payload.call2 == null) {
       console.log(`Skipping notification for extension: ${payload.extension}, status: ${payload.status}`);
       return;
     }

     if (payload.extension === 'bridge_to_agent') {
       console.log(`Skipping notification for extension: ${payload.extension}, status: ${payload.status}`);
       return;
     }
     // ADDED EMAN 08-27-26 END

     // [push-stop] the 'answered' push must also reach the agent who answered (now
     // 'In a queue call'), so its iPhone stops ringing when the call was taken on web/desktop.
     // Same states the Android path (pushQueue) already uses for 'answered'.
     const validStates = payload.call === 'answered'
          ? ['Waiting', 'Receiving', 'Idle', 'In a queue call']
          : ['Waiting', 'Receiving', 'Idle'];
     const containerToken = await Promise.all(
          agentlist
          .filter(element =>{
               
               console.log('QueuePush IOS', element);
               return validStates.includes(element.state) && element.status === 'Available'

          }).map(async (element) => {

               const channelExtension = element.contact?.split('user/')[1];
               const [extension, subdomain] = channelExtension?.split('@');
               console.log('QueuePush IOS2', extension, subdomain);
               if(extension){

                    const validExtensionOrig =  async(extension) => {
                         const validExtension = isValidNumber(extension)
                         if(!validExtension){
                              const { rows } = await db2.query(
                              `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                              [extension, subdomain]
                              );
                              if(!rows.length){
                                   return extension
                              }
                              const extensionOrig = rows[0].extension;
                              return extensionOrig
                         }
                         return extension
                    }

                    const extensionOrig = await validExtensionOrig(extension)
                    return extensionOrig

               }
          })
     );
     if(!payload) return
     
     else{
              
     console.log("containerTokenIOS", containerToken, payload);
     console.log('Single Device QUEUE IOS');

     for (const ext of containerToken) {
          
          const { domain, call} = payload;
          const pTokens = await redisClient.sMembers(`iosDevicePushToken-${ext}@${domain}`);
          const activeCalls = await redisClient.sMembers(`activeCall-${ext}@${domain}`);
          const mutedQ = ['decline', 'missed', 'answered', 'answered2'].includes(call) ? [] : await redisClient.sMembers(`devMuted-${ext}@${domain}`).catch(() => []); // [devices]
          const blockedQ2 = !['decline', 'missed', 'answered', 'answered2'].includes(call) && await pushBlocked(ext, domain); // [ext-access]
          const pTokens2 = blockedQ2 ? [] : pTokens.filter(item => !activeCalls.includes(item) && !mutedQ.includes(item));
          console.log(`TOKEN CALLS IOS GET TOKEN QUEUE - ${ext}`, {pTokens, activeCalls, pTokens2});
          const validation =  ['decline', 'missed', 'answered', 'answered2'];
          if(pTokens2.length){
               
               // ADDED EMAN 08-27-26 START
               if(!payload) return
               else if(!payload.nameCaller) return
               else if(!payload.caller_id) return
               else{
                payload.nameCaller = payload.nameCaller.includes(payload.caller_id) ? payload.nameCaller : `${payload.nameCaller} ${payload.caller_id} `.trim();
               // ADDED EMAN 08-27-26 END

               if(!validation.includes(call)){
                    
                    redisClient.set(`${domain}${ext}`, call);
                    // [push-stop] remember this device was rung, so the later 'answered' push is sent
                    redisClient.set(`${domain}${ext}-RingQ`, call);
                    if (pTokens2.length === 1) {
                         const payload2 = {...payload, 'pushToken': pTokens2[0]}
                         pushVoip(payload2, pTokens2[0])
                    }

                    if (pTokens2.length > 1) {
                         pushVoipMutipleDevice(payload, pTokens)
                    }

               }

               if(call === 'answered'){

                    const activeCall = await redisClient.get(`${domain}${ext}-RingQ`);
                    if(activeCall){

                         const activeCalls2 = await redisClient.sMembers(`activeCall-${ext}@${domain}`);
                         const pTokens3 = pTokens.filter(item => !activeCalls2.includes(item));

                         if(pTokens3.length){

                              if (pTokens3.length === 1) {
                                   pushVoip(payload, pTokens3[0])
                              }

                              if (pTokens3.length > 1) {
                                   pushVoipMutipleDevice(payload, pTokens3)
                              }
                         }
                    }
               
               }

             if(call === 'missed'){
                    setTimeout( async () => {
                         // ADDED EMAN 08-27-26 START
                         const payloadNew = {...payload, call: 'missed'}
                         // const payloadNew = {...payload, call: 'decline'}
                         // ADDED EMAN 08-27-26 START

                         const activeCalls2 = await redisClient.sMembers(`activeCall-${ext}@${domain}`);
                         const pTokens3 = pTokens.filter(item => !activeCalls2.includes(item));
                         if(pTokens3.length){

                              const results = await Promise.all(
                                   pTokens3.map(async (item) => {
                                        const active = await redisClient.get(
                                             `activeCallEnd@${domain}-${item}`
                                        );
                                        return { item, active };
                                   })
                              );
                              const pTokens4 = results
                              .filter(r => !r.active)
                              .map(r => r.item); 

                              if(pTokens4.length){

                                   if (pTokens4.length === 1) {
                                        pushVoip(payloadNew, pTokens4[0]);
                                   }
                                   if (pTokens4.length > 1) {
                                        pushVoipMutipleDevice(payloadNew, pTokens4)
                                   }

                              }

                         }
                    }, 3000)
               }
               }


          }
     }
     }



}

async function pushQueueWeb(agentlist, payload){

    console.log('Queue WEB Agent:', agentlist);
    
    try {

     const validStates = ['Waiting', 'Receiving'];
     const containerWebToken = await Promise.all(
          agentlist
          .filter(element => validStates.includes(element.state) && element.status === 'Available')
          .map(async (element) => {

               const channelExtension = element.contact?.split('user/')[1];
               const extensionFunction = async (alias) => {
                    const checkValid = isValidNumber(alias)
                    if(!checkValid){

                         const { rows } = await db2.query(
                              `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                              [alias, payload.domain]
                         );
                         if(rows.length){
                              const extensionOrig = rows[0].extension;
                              return extensionOrig 
                         }
                         return alias
                    }
                    return alias
               }
               const extensionOrig = await extensionFunction(channelExtension?.split('@')[0])
               const subdomain = channelExtension?.split('@')[1];
               const tokenWeb = await redisClient.get(extensionOrig + "@@@" + subdomain);
               return tokenWeb ? tokenWeb : null;
          })
     );

     const validWebTokens = containerWebToken.filter(token => token !== null);
     const uniqueTokens = [...new Set(validWebTokens)];
     if(uniqueTokens.length){
          const registrationTokens = uniqueTokens;
          const message = {
               data: payload,
               tokens: registrationTokens
          };

          console.log('All push tokens WEB Queue:', registrationTokens);

          admin.messaging().sendEachForMulticast(message)
          .then((response) => {
               console.log('Multicast notification sent:', response);
               if (response.failureCount > 0) {
                    const failedTokens = [];
                    response.responses.forEach((resp, idx) => {
                      if (!resp.success) {
                        failedTokens.push(registrationTokens[idx]);
                      }
                    });
                    console.log('List of tokens that caused failures:', failedTokens);
               }
          })
          .catch((error) => {
               //console.error('Error sending multicast notification:', error);
          });
     }

    } catch (error) {
      console.error(error.message)
    }
}

// --- Shared WebSocket connection (module-level, outside the function) ---
let sharedWs = null;
const pendingCallbacks = new Map(); // apnsId -> { resolve, reject, timer }

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

// ADDED EMAN 08-27-26 START
function isDeclinedInternalExtension(payload) {
  if (!payload || typeof payload !== 'object') return false;

  const ext = parseInt(payload.extension, 10);
  const isDeclined = payload.call === 'decline';
  const isInRange = !isNaN(ext) && ext >= 9000 && ext <= 9999;

  return isDeclined && isInRange;
}
// ADDED EMAN 08-27-26 END

// ADDED EMAN 09-29-26 START
function extractUuid(rawString) {
  const uuidRegExp = /[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/;
  const match = rawString ? rawString.match(uuidRegExp) : null;
  return match ? match[0] : '';
}
// ADDED EMAN 09-29-26 END

// [apns-direct] the bookkeeping the relay reply used to trigger (waitingCalls() reads
// <subdomain><ext>-RingQ to bridge the woken app to the parked call)
async function iosPushDelivered(message) {
  try {
    // missed / '' (cancel) are stops too: don't record them as the call being rung
    const isTerminal = IOS_STOP_CALLS.has(message.call);
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
            redisClient.set(`IOS-${message.subdomain}@${message.extension}`, message.call, { EX: 60 }),
          ]
    );
  } catch (err) {
    console.error('[apns] redis after push:', err.message);
  }
}

// [apns-direct] the app ends a ringing call only if a stop push names that call (its `id`). Several
// missed-call paths build the stop with a fresh uuidv4(), so the phone kept ringing. Remember the
// call each extension's iPhones are ringing and put that id on every stop push for the extension.
const IOS_STOP_CALLS = new Set(['answered', 'answered2', 'decline', 'missed', '']);
const IOS_RING_TTL = 180;

async function ringingCallFor(payload) {
  const key = `iosRinging-${payload.extension}@${payload.domain}`;
  if (!IOS_STOP_CALLS.has(payload.call)) {
    const id = extractUuid(payload.call) || extractUuid(payload.id);
    if (id) {
      await Promise.all([
        redisClient.set(key, id, { EX: IOS_RING_TTL }),
        redisClient.set(`iosRang-${id}`, 1, { EX: IOS_RING_TTL }),
      ]).catch(() => {});
    }
    return payload;
  }
  const ringing = await redisClient.get(key).catch(() => null);
  const own = extractUuid(payload.id);
  const ownIsRing = own ? await redisClient.exists(`iosRang-${own}`).catch(() => 0) : 0;
  return ringing && !ownIsRing ? { ...payload, id: ringing } : payload;
}

// [apns-direct] send straight to Apple when /etc/ringq/.env has the APNs key; null = use the relay
async function pushVoipDirect(payload, tokens) {
  if (!apns.enabled() || !tokens.length) return null;
  payload = await ringingCallFor(payload);
  const results = await apns.sendVoipMany(tokens, payload, payload.id);
  for (const r of results) {
    if (r.ok) await iosPushDelivered({ token: r.token, call: payload.call, extension: payload.extension, subdomain: payload.domain });
  }
  // keyed by the call's own uuid (queue rings carry a fresh uuidv4 in `id`, the call in `call`)
  const ringKey = `iosRingCall:${extractUuid(payload.call) || payload.id}`;
  if (IOS_STOP_CALLS.has(payload.call)) {
    redisClient.del(`iosRinging-${payload.extension}@${payload.domain}`).catch(() => {});
    redisClient.del(ringKey).catch(() => {});
  } else {
    // remembered so the stop can be sent when this call ends, whatever route rang it
    const sent = results.filter((r) => r.ok).map((r) => r.token);
    if (sent.length) {
      redisClient.set(ringKey, JSON.stringify({ ...payload, tokens: sent }), { EX: IOS_RING_TTL }).catch(() => {});
    }
  }
  return results;
}

// [apns-direct] Not every route sends an iOS stop push (e.g. a parked app-to-app call the caller
// hangs up only notifies Android), so the iPhone kept ringing. When a call we rang an iPhone for
// hangs up, send the stop ourselves. An app that already answered ignores it.
async function iosStopOnHangup(ev) {
  try {
    if (!apns.enabled()) return;
    const ids = [ev.getHeader('Unique-ID'), ev.getHeader('Channel-Call-UUID')].filter(Boolean);
    for (const id of [...new Set(ids)]) {
      const raw = await redisClient.get(`iosRingCall:${id}`);
      if (!raw) continue;
      await redisClient.del(`iosRingCall:${id}`);
      const ring = JSON.parse(raw);
      // answered = the call was connected to someone. Not billsec: a parked app-to-app call is
      // answered by FreeSWITCH itself (fake ringback), so it always has billsec.
      const answered = !!(ev.getHeader('variable_bridge_uuid') || ev.getHeader('variable_last_bridge_to'))
        || ev.getHeader('Hangup-Cause') === 'LOSE_RACE' || ev.getHeader('Hangup-Cause') === 'PICKED_OFF';
      const { tokens, ...payload } = ring;
      console.log('[apns] call ended, stopping iOS ring', id, payload.extension, payload.domain, ev.getHeader('Hangup-Cause'));
      await pushVoipDirect({ ...payload, call: answered ? 'answered' : 'missed', id }, tokens);
    }
  } catch (err) {
    console.error('[apns] stop on hangup:', err.message);
  }
}

// --- The actual push function ---
async function pushVoip(payload, token, timeoutMs = 5000) {
     // ADDED EMAN 08-27-26 START
     if (isDeclinedInternalExtension(payload)) return;
     // ADDED EMAN 08-27-26 END

  console.log('[pushVoip] Sending to token:', token, payload);

  // ADDED EMAN 09-29-26 START
  payload.id = extractUuid(payload.id);
  // ADDED EMAN 09-29-26 END

  const direct = await pushVoipDirect(payload, [token]); // [apns-direct]
  if (direct) return direct[0];

  const message = {
    token,
    payload,
    device: 'ios',
    status: 'single',
  };

  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      pendingCallbacks.delete(token);
      // reject(new Error(`pushVoip timed out for token ${token}`));
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


async function pushVoipMutipleDevice(payload, tokens) {
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

     if (await pushVoipDirect(payload, tokens)) return; // [apns-direct]

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

async function queueCallCenterRating(subdomain, domain_uuid, sip_call_id, callcenter_queue_id, cc_queue, hangup_cause){
     
     if(subdomain && domain_uuid && 
     sip_call_id && callcenter_queue_id && 
     cc_queue && hangup_cause){
          try {
               
               let splitChannel = cc_queue?.split('@');
               const dataSave = [domain_uuid, sip_call_id, callcenter_queue_id, splitChannel[1], 'callcenter', splitChannel[0], hangup_cause];

               await redisClient.lPush(`queueCallcenter-${subdomain}`, dataSave)
               .catch(error => {
                    // Handle the error here
                    console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                    console.error("Stack Trace:", error.stack);
               });
          } catch (error) {
               console.error(error.message);
          }
     }

}

async function queueCallCenterAnswer(uuid, domain, digit){

     try {
          conn.api(`uuid_dump ${uuid} JSON`, async function (res) {
               if(res.body === '-ERR No such channel!\n') return
               let val = JSON.parse(res.body);
               const dataArray = [
                    uuid,
                    val['variable_domain_uuid'], 
                    val['variable_orig_sip_call_id'], 
                    val['variable_call_center_queue_uuid'], 
                    val['variable_cc_queue'], 
                    val['variable_current_application'], 
                    val['variable_current_application_data'], 
                    digit
               ]
               await redisClient.lPush(`queueCallcenterRatings-${val['variable_domain_name']}`, dataArray)
               .catch(error => {
                    // Handle the error here
                    console.error(`An error occurred at line: ${getLineNumber(error)} - ${error.message}`);
                    console.error("Stack Trace:", error.stack);
               });
          }); 
     } catch (error) {
          console.error(error.message);
     }        

}

function getLineNumber(error) {
     const stackLines = error.stack?.split('\n');
     return stackLines[1].trim();
}

const isValidNumber = (input) =>  {
     const regex = /^\+?\d+$/;
     return regex.test(input);
}

const queueListQueuery = async (subdomain, queueExtension) => {
     try {
          const query = `SELECT callcenter.queue_extension, queue_strategy 
               FROM v_domains AS domain 
               LEFT JOIN v_call_center_queues AS callcenter 
               ON domain.domain_uuid = callcenter.domain_uuid 
               WHERE domain.domain_name = $1 
               AND callcenter.queue_extension = $2  
               AND CAST(callcenter.queue_extension AS INTEGER) >= 9000 
               AND CAST(callcenter.queue_extension AS INTEGER) <= 9995
          `;
          const response = await db2.query(query, [subdomain, queueExtension]);
          // [ringq-strategy] "Skill Based Ring All" rings everyone like Ring All (tier levels on),
          // so the push/notification logic below must treat it as 'ring-all'.
          return response.rows.map((r) => (r && r.queue_strategy === 'skill-based-ring-all' ? { ...r, queue_strategy: 'ring-all' } : r))
     } catch (error) {
          console.error('Error Queue Waiting', error);
          throw error;
     }
     
}

async function internalActive(data){
     try {
          const changePresence = data.map(async (x) => {
              const [p_extesnion, p_subdomain] = x.presence_id?.split('@');
              const [b_extension, b_subdomain] = x.b_presence_id?.split('@');
              const valid = isValidNumber(p_extesnion);
              const valid2 = isValidNumber(b_extension);
              let objectData = { ...x };
  
              if (!valid) {
                  const response = await redisClient.get(`extension-${p_extesnion}@${p_subdomain}`);
                  if (response) {
                      const { extension } = JSON.parse(response);
                      objectData = { ...objectData, presence_id: `${extension}@${p_subdomain}` };
                  }
              }
  
              if (!valid2) {
                  const response = await redisClient.get(`extension-${b_extension}@${b_subdomain}`);
                  if (response) {
                      const { extension } = JSON.parse(response);
                      objectData = { ...objectData, b_presence_id: `${extension}@${b_subdomain}` };
                  }
              }
  
              return objectData;
          });
          return await Promise.all(changePresence);
      } catch (err) {
          console.log('❌ Error:', err);
          // throw err; // Rethrow the error for better error handling.
      }
}

const queueListQueuery2 = async (subdomain) => {
     try {
          const query = `SELECT callcenter.queue_extension
               FROM v_domains AS domain 
               LEFT JOIN v_call_center_queues AS callcenter 
               ON domain.domain_uuid = callcenter.domain_uuid 
               WHERE domain.domain_name = $1 
               AND CAST(callcenter.queue_extension AS INTEGER) >= 9000 
               AND CAST(callcenter.queue_extension AS INTEGER) <= 9995
          `;
          const response = await db2.query(query, [subdomain]);
          return response.rows
     } catch (error) {
          console.error('Error Queue Waiting', error);
          throw error;
     }
     
}

const promisifiedApi = (command) => {
     return new Promise((resolve, reject) => {
     conn.bgapi(command, (res) => {
          if (res.error) reject(res.error);
          else resolve(res);
          });
     });
};

/* ================================================================
 * [call-journey] (2026-10-02) record each call's path for Call Status:
 * landed (DID/rule/route) -> routed -> queue -> agents rung -> answered ->
 * transfers -> who hung up. Stored in Redis via ./callJourney.js and
 * attached to the Previous Calls rows by enrichCallRow().
 * ================================================================ */
const jCache = new Map();
async function jCached(key, ttlMs, fn) {
     const c = jCache.get(key);
     if (c && c.exp > Date.now()) return c.v;
     let v = null;
     try { v = await fn(); } catch (e) { v = null; }
     jCache.set(key, { v, exp: Date.now() + ttlMs });
     return v;
}
const jAgent = (agentUuid) => jCached(`agent:${agentUuid}`, 300000, async () => {
     const { rows } = await db2.query(
          `SELECT a.agent_name, e.extension, e.effective_caller_id_name
             FROM v_call_center_agents a
             LEFT JOIN v_extensions e ON e.domain_uuid = a.domain_uuid AND (e.number_alias = a.agent_id OR e.extension = a.agent_id)
            WHERE a.call_center_agent_uuid::text = $1 LIMIT 1`, [String(agentUuid)]);
     const r = rows[0];
     return r ? { ext: r.extension || '', name: r.effective_caller_id_name || r.agent_name || '' } : { ext: '', name: '' };
});
const jQueueName = (qext, domainName) => jCached(`q:${qext}@${domainName}`, 300000, async () => {
     const { rows } = await db2.query(
          `SELECT q.queue_name FROM v_call_center_queues q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
            WHERE q.queue_extension = $1 AND d.domain_name = $2 LIMIT 1`, [String(qext), String(domainName)]);
     return rows[0] ? String(rows[0].queue_name || '').trim() : '';
});
const jDid = (did) => jCached(`did:${did}`, 300000, async () => {
     const d = String(did || '').replace(/\D/g, '');
     if (d.length < 5) return null;
     const { rows } = await db2.query(
          `SELECT name, sip_trunk_name, extension FROM q_dids
            WHERE regexp_replace(did_number, '\D', '', 'g') <> ''
              AND ($1 LIKE '%' || regexp_replace(did_number, '\D', '', 'g') OR regexp_replace(did_number, '\D', '', 'g') LIKE '%' || $1)
            ORDER BY length(did_number) DESC LIMIT 1`, [d]);
     return rows[0] || null;
});
const jFaxNumber = (did) => jCached(`fax:${did}`, 300000, async () => {
     const d = String(did || '').replace(/\D/g, '');
     if (d.length < 5) return false;
     const { rows } = await db2.query(
          `SELECT 1 FROM fax_numbers WHERE COALESCE(enabled::text, '1') NOT IN ('0', 'false')
              AND regexp_replace(number, '\D', '', 'g') <> ''
              AND ($1 LIKE '%' || regexp_replace(number, '\D', '', 'g') OR regexp_replace(number, '\D', '', 'g') LIKE '%' || $1)
            LIMIT 1`, [d]);
     return rows.length > 0;
});
const jExt = (idOrAlias) => jCached(`ext:${idOrAlias}`, 300000, async () => {
     const { rows } = await db2.query(
          `SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 LIMIT 1`, [String(idOrAlias)]);
     return rows[0] || null;
});
const jExtNumber = (num) => jCached(`extnum:${num}`, 300000, async () => {
     if (!num) return false;
     const { rows } = await db2.query(`SELECT 1 FROM v_extensions WHERE extension = $1 LIMIT 1`, [String(num)]);
     return rows.length > 0;
});
const jExtName = (ext, domainUuid) => jCached(`extname:${ext}@${domainUuid}`, 300000, async () => {
     if (!domainUuid) return '';
     const { rows } = await db2.query(
          `SELECT effective_caller_id_name FROM v_extensions WHERE extension = $1 AND domain_uuid = $2 LIMIT 1`, [String(ext), String(domainUuid)]);
     return rows[0] ? String(rows[0].effective_caller_id_name || '').trim() : '';
});
const jIsRoot = async (uuid) =>
     journey.isUuid(uuid) && (await redisClient.exists(`journey:${uuid}`)) === 1 && !(await redisClient.exists(`journeyRoot:${uuid}`));

async function journeyOnEvent(ev) {
     journey.init(redisClient);
     const evName = ev.getHeader('Event-Name');
     const uuid = ev.getHeader('Unique-ID');
     switch (evName) {
     case 'CHANNEL_CREATE': {
          if (ev.getHeader('Call-Direction') !== 'inbound') return;
          const chan = String(ev.getHeader('Channel-Name') || '');
          if (!chan.startsWith('sofia/')) return;
          const from = ev.getHeader('Caller-Caller-ID-Number') || '';
          const did = ev.getHeader('Caller-Destination-Number') || '';
          // Carrier calls normally arrive on the external profile, but trunks that send to the internal
          // profile (IP-authenticated carriers) land in the "public" context, and a caller that is not one
          // of our extensions dialling one of our DIDs is inbound too. Missing this showed inbound DID calls
          // as "Outbound from <caller> to <DID>" on Call Status.
          let external = chan.startsWith('sofia/external/') || ev.getHeader('Caller-Context') === 'public';
          let fromExt = null;
          if (!external) {
               fromExt = await jExt(from);
               if (!fromExt && !(await jExtNumber(from)) && (await jDid(did))) external = true;
          }
          const step = { from, did, external };
          if (external) {
               const r = await jDid(did);
               if (r) { step.rule = r.name || ''; step.route = r.sip_trunk_name || ''; }
               if (await jFaxNumber(did)) step.fax = true;
          } else {
               const e = fromExt;
               if (e) { step.from_ext = e.extension; step.from_name = e.effective_caller_id_name || ''; }
          }
          await journey.add(uuid, 'landed', step, { isRoot: true });
          return;
     }
     case 'CHANNEL_EXECUTE': {
          const app = ev.getHeader('Application');
          if (app === 'rxfax' || app === 'txfax') {
               if (!(await journey.has(uuid))) {
                    // outbound fax legs are originated by the fax service: start their journey here
                    await journey.add(uuid, 'landed', {
                         from: ev.getHeader('Caller-Caller-ID-Number') || '',
                         did: ev.getHeader('Caller-Destination-Number') || '',
                         external: false, fax: true,
                    }, { isRoot: true });
               }
               await journey.add(uuid, 'fax', { direction: app === 'rxfax' ? 'in' : 'out' });
               return;
          }
          if (app !== 'transfer') return;
          const to = String(ev.getHeader('Application-Data') || '').trim().split(/\s+/)[0];
          if (!to || to.startsWith('-')) return;
          if (!(await jIsRoot(uuid))) return;
          // A registered gateway (extension 100000-199999) sends its inbound calls as itself, so the call
          // landed as "<gateway> dialled <DID>". The gateway-inbound dialplan sets the real caller on the
          // channel and hands the call to the public context: show it as an inbound call from that caller.
          // CHANNEL_EXECUTE carries no variable_* headers (only verbose events do), so the hand-over is
          // recognised by its target context and the caller is read from the (already rewritten) profile.
          const xferCtx = String(ev.getHeader('Application-Data') || '').trim().split(/\s+/)[2] || '';
          if (xferCtx === 'public') {
               const key = `journey:${uuid}`; // this channel is the journey root (checked above)
               const raw = await redisClient.lIndex(key, 0);
               const first = raw ? JSON.parse(raw) : null;
               const gw = first ? String(first.from || '') : '';
               if (first && first.step === 'landed' && !first.external && /^1\d{5}$/.test(gw)) {
                    const caller = ev.getHeader('Caller-Caller-ID-Number') || '';
                    const fix = { ...first, external: true, did: to, gateway: gw };
                    delete fix.from_ext; delete fix.from_name;
                    if (caller && caller !== gw) fix.from = caller;
                    const r = await jDid(to);
                    if (r) { fix.rule = r.name || ''; fix.route = r.sip_trunk_name || ''; }
                    await redisClient.lSet(key, 0, JSON.stringify(fix));
               }
          }
          await journey.add(uuid, 'routed', { to }, { isRoot: true });
          return;
     }
     case 'CHANNEL_BRIDGE': {
          // direct (non-queue) calls: record who picked up; queue answers come from callcenter events
          if (!(await jIsRoot(uuid))) return;
          const { steps } = await journey.get(uuid);
          if (steps.some((x) => x.step === 'queue_enter' || x.step === 'answered')) return;
          const other = ev.getHeader('Other-Leg-Unique-ID');
          // Web/app phones answer with a random SIP contact user (e.g. "5ale8mdi") as their callee ID, and
          // app phones woken by push (mod_ringq) also carry it as the leg's destination. Take the first
          // value that looks like an extension (digits or a 20-hex alias): the answering leg's callee ID,
          // its destination, then the extension this call dialled.
          const isExtId = (v) => /^\+?\d+$/.test(v) || /^[0-9a-f]{20}$/i.test(v);
          const calleeNum = ev.getHeader('Other-Leg-Callee-ID-Number') || '';
          let name = ev.getHeader('Other-Leg-Callee-ID-Name') || '';
          let ext = [calleeNum, ev.getHeader('Other-Leg-Destination-Number'), ev.getHeader('variable_dialed_extension'),
               ev.getHeader('Caller-Destination-Number')].map((v) => String(v || '').trim()).find(isExtId) || calleeNum;
          if (ext !== calleeNum && (name === calleeNum || !isExtId(calleeNum))) name = '';
          const e = /^[0-9a-f]{20}$/i.test(ext) ? await jExt(ext) : null;
          if (e) { ext = e.extension; name = e.effective_caller_id_name || name; }
          if (!name && /^\d+$/.test(ext)) name = (await jExtName(ext, ev.getHeader('variable_domain_uuid'))) || '';
          await journey.add(uuid, 'answered', { agent_ext: ext, agent_name: name === ext ? '' : name, via: 'direct' }, { isRoot: true });
          if (journey.isUuid(other)) {
               await journey.link(other, uuid);
               if (ext) await redisClient.set(`journeyLegExt:${other}`, ext, { EX: 172800 });
          }
          return;
     }
     case 'CUSTOM': {
          if (ev.getHeader('Event-Subclass') !== 'callcenter::info') return;
          const act = ev.getHeader('CC-Action');
          const member = ev.getHeader('CC-Member-Session-UUID');
          if (!journey.isUuid(member)) return;
          const queue = String(ev.getHeader('CC-Queue') || '');
          const [qext, qdom] = queue.split('@');
          if (act === 'member-queue-start') {
               await journey.add(member, 'queue_enter', { queue_ext: qext, queue_name: await jQueueName(qext, qdom) });
          } else if (act === 'agent-offering') {
               const a = await jAgent(ev.getHeader('CC-Agent'));
               await journey.add(member, 'agent_ringing', { agent_ext: a.ext, agent_name: a.name });
          } else if (act === 'bridge-agent-start') {
               const a = await jAgent(ev.getHeader('CC-Agent'));
               const leg = ev.getHeader('CC-Agent-UUID');
               await journey.add(member, 'answered', { agent_ext: a.ext, agent_name: a.name, via: 'queue' });
               if (journey.isUuid(leg)) {
                    await journey.link(leg, member);
                    if (a.ext) await redisClient.set(`journeyLegExt:${leg}`, a.ext, { EX: 172800 });
               }
          } else if (act === 'bridge-agent-fail') {
               const a = await jAgent(ev.getHeader('CC-Agent'));
               const failCause = ev.getHeader('CC-Hangup-Cause') || '';
               await journey.add(member, 'agent_no_answer', { agent_ext: a.ext, agent_name: a.name, cause: failCause });
               // [wallboard-missed] Agent Details "Missed": the agent's phone rang for this queue call and
               // they did not pick up. Not counted: another agent answered first (LOSE_RACE), phone not
               // registered, agent busy on another call. Once per call per agent.
               const agentUuid = ev.getHeader('CC-Agent');
               if (agentUuid && ['NO_ANSWER', 'NO_USER_RESPONSE', 'ORIGINATOR_CANCEL', 'CALL_REJECTED'].includes(failCause)) {
                    const firstMiss = await redisClient.set(`wb-missed-${member}-${agentUuid}`, '1', { NX: true, EX: 86400 });
                    if (firstMiss) {
                         await db2.query('UPDATE agent_details SET no_answer_count = COALESCE(no_answer_count, 0) + 1 WHERE name = $1', [agentUuid]);
                    }
               }
          } else if (act === 'member-queue-end') {
               await journey.add(member, 'queue_exit', { cause: ev.getHeader('CC-Cause') || '', reason: ev.getHeader('CC-Cancel-Reason') || '' });
          }
          return;
     }
     case 'API': {
          if (ev.getHeader('API-Command') !== 'uuid_transfer') return;
          const args = String(ev.getHeader('API-Command-Argument') || '').trim().split(/\s+/);
          const target = args[0];
          const dest = args[1] && args[1].startsWith('-') ? args[2] : args[1];
          if (!journey.isUuid(target) || !dest || /park/i.test(dest)) return;
          if (await redisClient.get(`journeySkipXfer:${target}`)) return;
          if (!(await jIsRoot(target))) return; // consult legs are covered by the attended-transfer steps
          const { steps } = await journey.get(target);
          const lastAns = [...steps].reverse().find((x) => x.step === 'answered');
          if (!lastAns) return; // routing before anyone answered, not a transfer
          if (lastAns.agent_ext && lastAns.agent_ext === dest) {
               await journey.add(target, 'returned_to_agent', { to: dest }, { isRoot: true });
               return;
          }
          const lastQ = [...steps].reverse().find((x) => x.step === 'queue_enter');
          if (lastQ && lastQ.queue_ext === dest) return;
          await journey.add(target, 'blind_transfer', { to: dest, by: lastAns.agent_ext || '' }, { isRoot: true });
          return;
     }
     case 'CHANNEL_HANGUP_COMPLETE': {
          if (!journey.isUuid(uuid)) return;
          const disp = String(ev.getHeader('variable_sip_hangup_disposition') || '');
          const cause = ev.getHeader('Hangup-Cause') || '';
          if (await jIsRoot(uuid)) {
               let by = disp.startsWith('recv_') ? 'caller' : 'system';
               if (by !== 'caller' && (await redisClient.get(`journeyAgentLeft:${uuid}`))) by = 'agent';
               const faxInfo = {};
               if (ev.getHeader('variable_fax_success') != null) {
                    faxInfo.fax_success = ev.getHeader('variable_fax_success') === '1';
                    faxInfo.fax_pages = Number(ev.getHeader('variable_fax_document_transferred_pages') || 0);
                    faxInfo.fax_result = ev.getHeader('variable_fax_result_text') || '';
               }
               await journey.add(uuid, 'ended', { cause, by, disposition: disp, ...faxInfo }, { isRoot: true });
               // save permanently for reports once trailing events (queue exit, agent leg) have landed
               const domainUuid = ev.getHeader('variable_domain_uuid');
               setTimeout(() => { journey.persist(db2, uuid, domainUuid).catch(() => {}); }, 4000);
               return;
          }
          const root = await redisClient.get(`journeyRoot:${uuid}`);
          if (root && disp.startsWith('recv_')) {
               const ext = (await redisClient.get(`journeyLegExt:${uuid}`)) || '';
               await journey.add(root, 'agent_left', { agent_ext: ext, cause }, { isRoot: true });
               await redisClient.set(`journeyAgentLeft:${root}`, '1', { EX: 30 });
          }
          return;
     }
     default:
          return;
     }
}

/** Attach queue / answered-by / plain status / timeline to a Previous Calls row. */
async function enrichCallRow(row) {
     try {
          journey.init(redisClient);
          await new Promise((r) => setTimeout(r, 400)); // let the hang-up events land first
          let root = null;
          for (const u of [row.uuid, row.b_uuid, row.call_uuid]) {
               if (journey.isUuid(u) && (await journey.has(u))) { root = await journey.rootOf(u); break; }
          }
          if (!root) return row;
          const { steps } = await journey.get(root);
          const sum = journey.summarize(steps);
          Object.assign(row, {
               journey_root: root,
               queue_ext: sum.queue_ext, queue_name: sum.queue_name,
               answered_by: sum.answered_by, answered_name: sum.answered_name,
               wait_sec: sum.wait_sec, talk_sec: sum.talk_sec,
               outcome: sum.outcome, status_text: sum.status_text, call_type: sum.call_type,
               did: sum.did, from: sum.from,
               timeline: journey.timeline(steps),
          });
          if (!row.rule && sum.rule) row.rule = sum.rule;
          if (!row.route && sum.route) row.route = sum.route;
     } catch (e) {
          console.error('[call-journey] enrich', e?.message || e);
     }
     return row;
}
/* ================= end [call-journey] ================= */

/* ================================================================
 * [att-recover] Attended-transfer consult recovery  (2026-10-02)
 * Attended transfer state lives in Redis:
 *   attendedSent-<ext>@<domain> = {"parkCall":<customer uuid>,"transferCall":<agent consult uuid>}
 * Recovers the held customer when the consult fails in ways the existing
 * *99 handler (handleAPI) does not see:
 *   1. consult leg falls into the target's voicemail via "lua app.lua voicemail"
 *      (WebRTC branch of local_extension) -> reconnect agent to customer
 *   2. agent's consult leg hangs up before the transfer completed
 *      -> customer back to its queue (cc_queue) or hang it up
 * Only acts when the customer is idle on hold (current_application park/playback)
 * and not bridged, so completed or in-progress transfers are never touched.
 * ================================================================ */
const ATT_LOG = '[att-recover]';
const attApi = async (cmd) => {
     try {
          const r = await promisifiedApiSync(cmd);
          return String(r?.body ?? '').trim();
     } catch (e) {
          console.error(ATT_LOG, cmd, e?.message || e);
          return '';
     }
};
const attAlive = async (uuid) => !!uuid && (await attApi(`uuid_exists ${uuid}`)) === 'true';
const attBridged = async (uuid) => {
     try {
          const rows = JSON.parse(await attApi('show bridged_calls as json')).rows || [];
          return rows.some((r) => r.uuid === uuid || r.b_uuid === uuid);
     } catch (e) {
          return true; // unknown -> be safe, treat as bridged (do nothing)
     }
};
// [perf] the attended transfer that has <uuid> as its consult leg, via attIdx:<uuid> (set by
// attended.js) -- no KEYS scan over the whole Redis keyspace on every bridge / hangup.
async function attFindByTransferCall(uuid) {
     if (!uuid) return null;
     const key = await redisClient.get(`attIdx:${uuid}`);
     if (!key) return null;
     const raw = await redisClient.get(key);
     if (!raw) return null;
     try {
          const v = JSON.parse(raw);
          if (v.transferCall === uuid && v.parkCall && v.parkCall !== uuid) return { key, parkCall: v.parkCall };
     } catch (e) { /* ignore malformed */ }
     return null;
}
const attInProgress = new Set();
async function attRecover(transferCall, reason, notify) {
     if (!transferCall || attInProgress.has(transferCall)) return false;
     attInProgress.add(transferCall);
     try {
          const att = await attFindByTransferCall(transferCall);
          if (!att) return false;
          const { key, parkCall } = att;
          if (!(await attAlive(parkCall))) {
               await redisClient.del(key);
               return false;
          }
          if (await attBridged(parkCall)) return false; // transfer completed / customer talking
          const app = await attApi(`uuid_getvar ${parkCall} current_application`);
          if (!['park', 'playback'].includes(app)) {
               console.log(ATT_LOG, reason, `customer ${parkCall} busy in '${app}', leaving it`);
               return false;
          }
          await redisClient.del(key);
          journey.init(redisClient);
          if (await attAlive(transferCall)) {
               await journey.add(parkCall, 'attended_returned', { to: notify ? String(notify).split('@')[0] : '', reason: reason.includes('voicemail') ? 'not answered, reached voicemail' : reason }); // [call-journey]
               console.log(ATT_LOG, reason, `-> reconnecting agent ${transferCall} to customer ${parkCall}`);
               await attApi(`uuid_bridge ${parkCall} ${transferCall}`); // [att-retry] caller first, so a retry holds the caller
          } else {
               // [att-return] consult was not answered (no answer / voicemail) but the consult leg is
               // already gone: ring the agent who started the transfer, not the whole queue again.
               const agent = String(key).match(/^attendedSent-(\d+)@([\w.-]+)$/);
               const q = await attApi(`uuid_getvar ${parkCall} cc_queue`);
               const m = q.match(/^([\w*]+)@([\w.-]+)$/);
               if (agent && /voicemail|not answered/.test(reason)) {
                    console.log(ATT_LOG, reason, `-> consult leg gone, customer ${parkCall} back to agent ${agent[1]}`);
                    await redisClient.set(`journeySkipXfer:${parkCall}`, '1', { EX: 10 }); // [call-journey]
                    await journey.add(parkCall, 'returned_to_agent', { to: agent[1] });
                    await attApi(`uuid_transfer ${parkCall} ${agent[1]} XML ${agent[2]}`);
               } else if (m) {
                    console.log(ATT_LOG, reason, `-> agent gone, customer ${parkCall} back to queue ${q}`);
                    await redisClient.set(`journeySkipXfer:${parkCall}`, '1', { EX: 10 }); // [call-journey]
                    await journey.add(parkCall, 'requeued', { to: m[1] });
                    await attApi(`uuid_transfer ${parkCall} ${m[1]} XML ${m[2]}`);
               } else {
                    console.log(ATT_LOG, reason, `-> agent gone, hanging up customer ${parkCall}`);
                    await attApi(`uuid_kill ${parkCall} NORMAL_CLEARING`);
               }
          }
          if (notify) socketClient.emit('declineAttendedTransfer', notify);
          return true;
     } catch (e) {
          console.error(ATT_LOG, 'recover error', e);
          return false;
     } finally {
          attInProgress.delete(transferCall);
     }
}
// [att-vm] Consult target did not answer and has voicemail: the agent (consult leg) hears the
// target's voicemail and can leave a message, while the customer stays on hold music. The agent
// stays on the "Attended Transfer" screen and can Retrieve at any time (signal.js
// parkAttendedTransfer bridges the consult leg back to the customer, even mid-message); when the
// voicemail ends, saved or not, the agent is bridged back to the customer automatically.
async function attVoicemailEnabled(ext, domain) {
     if (!/^\d{2,10}$/.test(String(ext || '')) || !domain) return false;
     try {
          const { rows } = await db2.query(
               `SELECT v.voicemail_enabled FROM v_voicemails v
                  JOIN v_domains d ON d.domain_uuid = v.domain_uuid
                 WHERE d.domain_name = $1 AND v.voicemail_id = $2 LIMIT 1`, [domain, String(ext)]);
          return rows.length > 0 && String(rows[0].voicemail_enabled) === 'true';
     } catch (e) {
          console.error(ATT_LOG, 'voicemail lookup', e?.message || e);
          return false;
     }
}
async function attSendToVoicemail(transferCall, target, domain) {
     if (!transferCall || attInProgress.has(transferCall)) return false;
     attInProgress.add(transferCall);
     try {
          const att = await attFindByTransferCall(transferCall);
          if (!att) return false;
          const { parkCall } = att;
          if (!(await attAlive(parkCall)) || (await attBridged(parkCall))) return false;
          const app = await attApi(`uuid_getvar ${parkCall} current_application`);
          if (!['park', 'playback'].includes(app)) return false;
          if (!(await attVoicemailEnabled(target, domain))) return false;
          if (!fs.existsSync(ATT_VM_SCRIPT)) return false; // no script: reconnect the agent instead
          if (!(await attAlive(transferCall))) return false;
          const agent = String(att.key).match(/^attendedSent-(\d+)@/);
          const agentExt = agent ? agent[1] : '';
          await redisClient.set(`attVm:${parkCall}`, JSON.stringify({ transferCall, target, domain, key: att.key, agentExt }), { EX: 3600 });
          journey.init(redisClient);
          await journey.add(parkCall, 'attended_voicemail', { to: target, by: agentExt }); // [call-journey]
          console.log(ATT_LOG, `consult to ${target} not answered -> agent ${transferCall} to ${target}'s voicemail, customer ${parkCall} stays on hold music (agent can retrieve)`);
          // The agent's consult leg (not the customer) goes into the target's voicemail; the customer
          // keeps hearing hold music. When the voicemail ends (saved or not) ringq_att_voicemail.lua
          // bridges the agent back to the customer. Replaces the consult leg's own dialplan
          // (answer/sleep/plain voicemail that would hang the agent up).
          for (const [k, v] of [['voicemail_action', 'save'], ['voicemail_id', target], ['voicemail_profile', 'default'],
               ['send_to_voicemail', 'true'], ['rq_att_agent_leg', parkCall]]) {
               await attApi(`uuid_setvar ${transferCall} ${k} ${v}`);
          }
          await attApi(`uuid_transfer ${transferCall} lua:ringq_att_voicemail.lua inline`);
          return true;
     } catch (e) {
          console.error(ATT_LOG, 'send to voicemail error', e);
          return false;
     } finally {
          attInProgress.delete(transferCall);
     }
}
// [att-vm] customer hung up while on hold: forget the voicemail state (an agent still on hold music is ended)
const ATT_VM_SCRIPT = '/usr/share/freeswitch/scripts/ringq_att_voicemail.lua';
const attVmGet = async (parkCall) => {
     const raw = parkCall ? await redisClient.get(`attVm:${parkCall}`) : null;
     if (!raw) return null;
     try { return JSON.parse(raw); } catch (e) { return { transferCall: raw }; }
};
async function attVoicemailCleanup(uuid) {
     if (!uuid) return;
     const vm = await attVmGet(uuid);
     if (!vm) return;
     const transferCall = vm.transferCall;
     await redisClient.del(`attVm:${uuid}`);
     if (vm.key) await redisClient.del(vm.key);
     if (!(await attAlive(transferCall)) || (await attBridged(transferCall))) return; // retrieved / already gone
     const app = await attApi(`uuid_getvar ${transferCall} current_application`);
     if (app !== 'playback') return;
     console.log(ATT_LOG, `customer ${uuid} left voicemail -> ending agent hold leg ${transferCall}`);
     await attApi(`uuid_kill ${transferCall} NORMAL_CLEARING`);
}
// [att-vm] customer and agent bridged again (voicemail finished, or the agent pressed Retrieve):
// the transfer is over -- tell the agent's dialer (it goes back to the normal call screen).
// [att-answered] the consult leg got bridged to the transfer target: tell the agent's dialer, so
// "Complete Transfer" is only enabled once the target has answered.
async function attHandleBridge(ev) {
     const a = ev.getHeader('Bridge-A-Unique-ID');
     const b = ev.getHeader('Bridge-B-Unique-ID');
     if (!a || !b) return;
     for (const [x, y] of [[a, b], [b, a]]) {
          const vm = await attVmGet(x);
          if (vm && vm.transferCall === y) {
               await redisClient.del(`attVm:${x}`);
               if (vm.key) await redisClient.del(vm.key);
               await attApi(`uuid_setvar ${x} rq_att_agent_leg`); // the voicemail script must not bridge again
               await attApi(`uuid_setvar ${y} rq_att_agent_leg`);
               journey.init(redisClient);
               await journey.add(x, 'returned_to_agent', { to: vm.agentExt || '' }); // [call-journey]
               console.log(ATT_LOG, `customer ${x} back with agent ${y} after voicemail/retrieve`);
               if (vm.target && vm.domain) socketClient.emit('declineAttendedTransfer', `${vm.target}@${vm.domain}`);
               return;
          }
     }
     for (const [t, other] of [[a, b], [b, a]]) {
          const att = await attFindByTransferCall(t);
          if (!att || other === att.parkCall) continue;
          const m = String(att.key).match(/^attendedSent-(.+)@([\w.-]+)$/);
          if (!m) continue;
          console.log(ATT_LOG, `consult ${t} answered by ${other}`);
          socketClient.emit('attendedConsultAnswered', `${m[1]}@${m[2]}`);
          return;
     }
}
async function handleAttendedConsultExecute(ev) {
     if (ev.getHeader('Application') !== 'lua') return;
     const appData = String(ev.getHeader('Application-Data') || '');
     // [att-return] the consult target did not answer: ringq_call_control.lua failover runs on the
     // consult leg while it is still up (before answer/sleep/voicemail), so reconnect the agent now.
     if (appData.includes('ringq_call_control.lua failover')) {
          const disp = String(ev.getHeader('variable_originate_disposition') || '');
          if (['SUCCESS', 'ANSWER'].includes(disp)) return;
          const target = ev.getHeader('Caller-Destination-Number');
          const domain = ev.getHeader('variable_domain_name');
          const uuid = ev.getHeader('Unique-ID');
          if (await attSendToVoicemail(uuid, target, domain)) return; // [att-vm]
          await attRecover(uuid, `consult to ${target} not answered (${disp || 'no answer'})`, target && domain ? `${target}@${domain}` : null);
          return;
     }
     if (!appData.includes('app.lua voicemail')) return;
     if (ev.getHeader('variable_voicemail_action') === 'check') return; // user checking own mailbox
     const uuid = ev.getHeader('Unique-ID');
     const target = ev.getHeader('Caller-Destination-Number');
     const domain = ev.getHeader('variable_domain_name');
     await attRecover(uuid, `consult to ${target} reached voicemail`, target && domain ? `${target}@${domain}` : null);
}
function handleAttendedConsultHangup(ev) {
     const uuid = ev.getHeader('Unique-ID');
     if (!uuid) return;
     attVoicemailCleanup(uuid).catch(() => {}); // [att-vm]
     // small delay so a transfer that is being completed right now can bridge first
     setTimeout(() => {
          attRecover(uuid, 'agent hung up during consult', null).catch(() => {});
     }, 1500);
}
/* ================= end [att-recover] ================= */

// [cc-owner] When the RingQ Call Control dialplan (ringq_call_control.lua) is installed it sets
// rq_cc=ok on the caller's channel and itself handles No Answer / Relay / On Busy for extensions
// that have a registered phone or app, per call profile and Internal/External. In that case this
// file must NOT also schedule its own sched_transfer at call_timeout, or the caller is transferred
// twice (or Relay is cut off). The push-only path (no registered device -> park + push) is unchanged.
async function luaCallControlActive(uuid) {
     try {
          await new Promise((r) => setTimeout(r, 700)); // the dialplan sets rq_cc within ms of creation
          const res = await promisifiedApiSync(`uuid_getvar ${uuid} rq_cc`);
          return String((res && res.body) || '').trim() === 'ok';
     } catch (e) {
          return false;
     }
}

// [cc-owner] Call Control decided this call will not ring the extension (Always forward is on
// for this direction/profile, or Do Not Disturb) -> don't wake the mobile app with a ringing push.
async function callNotRingingExtension(uuid) {
     try {
          const get = async (v) => String(((await promisifiedApiSync(`uuid_getvar ${uuid} ${v}`)) || {}).body || '').trim();
          if ((await get('rq_cc')) !== 'ok') return false; // Call Control not installed: old behaviour
          const dir = await get('rq_cc_dir');
          const all = dir === 'external' ? await get('forward_all_enabled2') : await get('forward_all_enabled');
          return all === 'true' || (await get('do_not_disturb')) === 'true';
     } catch (e) {
          return false;
     }
}

const promisifiedApiSync = (command) => {
     return new Promise((resolve, reject) => {
     conn.api(command, (res) => {
          if (res.error) reject(res.error);
          else resolve(res);
          });
     });
};

function recordingFilePath(uuid, subdomain, unix){

     const newNumber = Number(String(unix).slice(0, -3));
     const timeStamp= parseInt(newNumber);
     const options = { month: "short", day: "2-digit", year: "numeric" };
     const dateEvent = new Date(timeStamp).toLocaleDateString('en-US', options).replace(/ /g, '/');
     const cleanedDate = dateEvent.replace(/,/g, '');
     const arrDate = cleanedDate?.split("/");
     return `/var/lib/freeswitch/recordings/${subdomain}/archive/${arrDate[2]}/${arrDate[0]}/${arrDate[1]}`
}

function getSipUser(ev) {
     return new Promise((resolve, reject) => {
       db2.query(
         `SELECT * FROM registrations WHERE realm = $1`,
         [ev.getHeader('variable_domain_name')],
         (err, result) => {
           
           if (err) {
             console.error(err);
             return reject(err);
           }

           if(result.rows){
               const rows = result.rows
               if(rows.length){
                    const sipProfile = ev.getHeader('variable_bridge_channel')?.split('/')[2];
                    const bridgeAgent = rows
                    .map(el => el.url.includes(sipProfile) ? el : null)
                    .filter(fil => fil !== null);
                    if (bridgeAgent.length > 0) {
                    const { reg_user } = bridgeAgent[0];
                         return resolve(reg_user);
                    } else {
                         return resolve(null);
                    }
               }
           }

         }
       );
     });
}

async function callLogsData(params) {

     const {ev, type, state} = params;
     console.log(`🧩CALL LOGS RAWS🧩`, params);
     // if(ev.getHeader('Call-Direction') === 'inbound'){

     //      const hangupCause = [
     //           'NO_USER_RESPONSE',
     //           'SERVICE_UNAVAILABLE',
     //           'USER_BUSY',
     //           'OUTGOING_CALL_BARRED',
     //           'NORMAL_UNSPECIFIED',
     //           'UNALLOCATED_NUMBER',
     //           'ORIGINATOR_CANCEL',
     //           'SUBSCRIBER_ABSENT'
     //      ];
     //      const originateFailedCause = ['CALL_REJECTED', 'NO_USER_RESPONSE', 'UNALLOCATED_NUMBER', 'GATEWAY_DOWN'];
     //      if(
     //           ev.getHeader('variable_domain_name') && ev.getHeader('variable_voicemail_answer_stamp') && ev.getHeader('variable_hangup_cause') !== 'NO_ANSWER' ||
     //           hangupCause.includes(ev.getHeader('variable_hangup_cause')) ||
     //           ev.getHeader('variable_originate_failed_cause') && originateFailedCause.includes(ev.getHeader('variable_originate_failed_cause'))
     //      ){
     //          db2.query(
     //           `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
     //           [ev.getHeader('variable_domain_uuid')],
     //           async (err, result) => {
     //                if (err) {
     //                     console.error("Error executing agents query:", err.message);
     //                     return;
     //                }
     //                let timezone = result.rows.length > 0
     //                ? result.rows[0].user_setting_value
     //                : Intl.DateTimeFormat().resolvedOptions().timeZone;
     //                console.log("Extracted TIMEZONE2:", timezone);
     //                processCallLogs(ev, type, state, timezone);

     //           });
     //      }


     //      const queueReasons = ['TIMEOUT', 'BREAK_OUT']
     //      if(
     //         queueReasons.includes(ev.getHeader('variable_cc_cancel_reason'))
     //      ){
     //           db2.query(
     //           `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
     //           [ev.getHeader('variable_domain_uuid')],
     //           async (err, result) => {
     //                if (err) {
     //                     console.error("Error executing agents query:", err.message);
     //                     return;
     //                }
     //                let timezone = result.rows.length > 0
     //                ? result.rows[0].user_setting_value
     //                : Intl.DateTimeFormat().resolvedOptions().timeZone;
     //                processCallLogs(ev, type, state, timezone);
     //           });

     //      }


     //      const aiResponse = ev.getHeader('variable_api_result')
     //      const aiCauseReason = ['NORMAL_CLEARING']
     //      if(
     //           aiResponse && aiResponse.includes('Success') && aiCauseReason.includes(ev.getHeader('variable_hangup_cause'))
     //      ){
     //           if(ev.getHeader('variable_domain_name')){
     //                db2.query(
     //                `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
     //                [ev.getHeader('variable_domain_uuid')],
     //                async (err, result) => {
     //                     if (err) {
     //                          console.error("Error executing agents query:", err.message);
     //                          return;
     //                     }
     //                     let timezone = result.rows.length > 0
     //                     ? result.rows[0].user_setting_value
     //                     : Intl.DateTimeFormat().resolvedOptions().timeZone;
     //                     console.log("Extracted TIMEZONE2:", timezone);
     //                     processCallLogs(ev, type, state, timezone);
     //                });
     //           }
     //      }

     // }

     // if(ev.getHeader('Call-Direction') === 'outbound'){

     //      const allowedCauses = ['NORMAL_CLEARING', 'NO_ANSWER'];
     //      const hangupCause = ev.getHeader('variable_hangup_cause');
     //      const transferTo = ev.getHeader('variable_transfer_to');
     //      if( 
     //        allowedCauses.includes(hangupCause) ||
     //        (transferTo && transferTo.includes('blind'))
     //      ){
     //           if( ev.getHeader('variable_cc_queue') ){
     //                if(!ev.getHeader('variable_sip_invite_failure_phrase')){

     //                     if(ev.getHeader('variable_domain_name')){
     //                          db2.query(
     //                          `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
     //                          [ev.getHeader('variable_domain_uuid')],
     //                          async (err, result) => {
     //                               if (err) {
     //                                    console.error("Error executing agents query:", err.message);
     //                                    return;
     //                               }
     //                               let timezone = result.rows.length > 0
     //                               ? result.rows[0].user_setting_value
     //                               : Intl.DateTimeFormat().resolvedOptions().timeZone;
     //                               console.log("Extracted Outbound TIMEZONE1:", timezone);
     //                               outboundProcessCallLogs(ev, type, state, timezone);
     //                          });
     //                     }

     //                }
     //                return
     //           }

     //           if(ev.getHeader('variable_domain_name')){
     //                db2.query(
     //                `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
     //                [ev.getHeader('variable_domain_uuid')],
     //                async (err, result) => {
     //                     if (err) {
     //                          console.error("Error executing agents query:", err.message);
     //                          return;
     //                     }
     //                     let timezone = result.rows.length > 0
     //                     ? result.rows[0].user_setting_value
     //                     : Intl.DateTimeFormat().resolvedOptions().timeZone;
     //                     console.log("Extracted TIMEZONE2:", timezone);
     //                     outboundProcessCallLogs(ev, type, state, timezone);
     //                });
     //           }
     //      }

     //      const parallelHangupCause = ['ORIGINATOR_CANCEL', 'CALL_REJECTED'];
     //      if(parallelHangupCause.includes(ev.getHeader('variable_hangup_cause'))){
     //           const { rows } = await db2.query(
     //                `SELECT * FROM q_call_behaviors WHERE mobile_number = $1`,
     //                [ev.getHeader('Caller-Destination-Number')]
     //           );
     //           if(rows.length){
     //                if(ev.getHeader('variable_domain_name')){
     //                     db2.query(
     //                     `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
     //                     [ev.getHeader('variable_domain_uuid')],
     //                     async (err, result) => {
     //                          if (err) {
     //                               console.error("Error executing agents query:", err.message);
     //                               return;
     //                          }
     //                          let timezone = result.rows.length > 0
     //                          ? result.rows[0].user_setting_value
     //                          : Intl.DateTimeFormat().resolvedOptions().timeZone;
                              
     //                          if(
     //                            ev.getHeader('variable_domain_name') && 
     //                            ev.getHeader('variable_sip_full_from')
     //                          ){
     //                               const fromSip = ev.getHeader('variable_sip_full_from');
     //                               const match = fromSip.match(/<sip:([^@<>]+@[^;<>]+)[^>]*>/);
     //                               const callerNumberOrig = match ? match[1] : null;
     //                               parallelCallLogs(ev, type, state, timezone, callerNumberOrig);
     //                               return;
     //                          }
                              
     //                          if(
     //                               ev.getHeader('variable_domain_name') && 
     //                               ev.getHeader('Other-Leg-Channel-Name')
     //                          ){
     //                               const from = ev.getHeader('Other-Leg-Channel-Name');
     //                               const match = from.match(/\/(\d+@[\w.-]+)/);
     //                               const callerNumberOrig = match ? match[1] : null;
     //                               parallelCallLogs(ev, type, state, timezone, callerNumberOrig);
     //                               return;
     //                          }

     //                     });
     //                }
     //           }
     //      }

     // }

}

function bgapiPromise(command) {
     return new Promise((resolve, reject) => {
          conn.bgapi(command, (res) => {
          if (res){
               const {status, response} =  JSON.parse(res.body);
               if(status === 'success'){
               resolve(response)
               }
          } else reject(new Error('No response from bgapi'));
          });
     });
}

const setAgentsToWaiting2 = async (names) => {
     console.log('Agent Name', names);
};

async function fileExists(folderPath, fileName) {
     const filePath = path.join(folderPath, fileName);
     try {
        fs.access(filePath);
       return true; // File exists
     } catch (err) {
       return false; // File does not exist
     }
}

async function sipOrigNumber(presence) {
     const getSipPresence2 = await redisClient.get(`contactSIP-${presence}`);
     if(getSipPresence2){
          const extNum = getSipPresence2?.split('@')[0]
          return extNum;
     }else{
          return null;
     }
}

async function sipUsername(presence){
     const getSipUsername = await redisClient.get(`extension-${presence}`);
     if(getSipUsername){
          const {extension, effectiveName} = JSON.parse(getSipUsername)
          return extension;
     }else{
          return null;
     }
}

const getCallerExtValid = async (callerExt, callerNumberOrig, ev) => {
     return await sipOrigNumber(callerNumberOrig) 
     || await sipUsername(callerNumberOrig)
     || await vgateWays(callerExt, ev.getHeader('variable_domain_uuid'), ev.getHeader('Caller-Caller-ID-Number'))
     || callerExt;
};

const getDestExtValid = async (callerExt, callerNumberOrig, ev) => {
     return await sipOrigNumber(callerNumberOrig) 
     || await sipUsername(callerNumberOrig) 
     || await vgateWays(callerExt, ev.getHeader('variable_domain_uuid'), ev.getHeader('Caller-Caller-ID-Number'))
     || callerExt;
};

function isValidJSON_FS(str) {
     try {
          JSON.parse(str);
          return true;
     } catch (e) {
          return false;
     }
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

const fs_command = (command) => {
    return new Promise((resolve, reject) => {
        conn.api(command, (res) => {
            if (res) {
                resolve(res);
            } else {
                reject(new Error("No response from FreeSWITCH"));
            }
        });
    });
}

function formatDateWithOffset(date, timeZone) {
    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });

    const parts = formatter.formatToParts(date);
    const map = {};
    for (const part of parts) {
        if (part.type !== 'literal') {
            map[part.type] = part.value;
        }
    }

    // Create a valid date from map
    let year = parseInt(map.year, 10);
    let month = parseInt(map.month, 10);
    let day = parseInt(map.day, 10);
    let hour = parseInt(map.hour, 10);
    let minute = map.minute;
    let second = map.second;

    if (hour === 24) {
        hour = 0;
        const d = new Date(year, month - 1, day);
        d.setDate(d.getDate() + 1); // next day
        year = d.getFullYear();
        month = d.getMonth() + 1;
        day = d.getDate();
    }

    const utcDate = new Date(date.toLocaleString('en-US', { timeZone: 'UTC' }));
    const tzDate = new Date(date.toLocaleString('en-US', { timeZone }));

    const offsetMinutes = (tzDate - utcDate) / 60000;
    const offsetHours = String(Math.floor(Math.abs(offsetMinutes) / 60)).padStart(2, '0');
    const offsetSign = offsetMinutes >= 0 ? '+' : '-';
    const offset = `${offsetSign}${offsetHours}`;

    return `${year.toString().padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')} ${String(hour).padStart(2, '0')}:${minute}:${second}${offset}`;
}

(async() => {

    cron.schedule('* * * * *', async () => {
          if(!eslReady) return;
          await gatewayRegStatus(), 
          {
               timezone: "Asia/Manila"
          }
    });

    cron.schedule('* * * * *', async () => {
          if(!eslReady) return;
          await activeQueueReload(), {
               timezone: "Asia/Manila"
          }
    });

    setInterval(async () => {
          if (!eslReady) return;
          try {
               const watching = await redisClient.exists(GATEWAY_WATCH_KEY);
               if (!watching) return;
               await gatewayRegStatus();
          } catch (error) {
               console.error('\u274c Error gatewayStatusWatchTick:', error);
          }
    }, GATEWAY_WATCH_INTERVAL_MS);

})()

function parseGatewayRegLines(bodyText) {
  const lines = (bodyText || '').split('\n');
  const parsed = [];
  for (const line of lines) {
     // Start JY 9/30/26/1:130PM
    if (!line.includes('::')) continue;
    const match = line.match(
      /^([^\s:]+)::([a-f0-9\-]+)\s+(.+?)\s+(NOREG|REGED|TRYING|FAIL_WAIT|REGISTER|UNREGED)\s+([\d.]+)\s+(\d+\/\d+)\s+(\d+\/\d+)/
    );
    if (!match) continue;
    const [_, profile, uuid, uri, state, ping, ib, ob] = match;
    // End JY 9/30/26/1:130PM
    const [ibFail, ibTotal] = ib.split('/').map(Number);
    const [obFail, obTotal] = ob.split('/').map(Number);
    const retryMatch = line.match(/\(retry:\s*([^)]+)\)/);
    parsed.push({
     // Start JY 9/30/26/1:130PM
      profile,
      // End JY 9/30/26/1:130PM
      uuid,
      uri,
      state,
      ping_time: parseFloat(ping),
      ib_calls: { failed: ibFail, total: ibTotal },
      ob_calls: { failed: obFail, total: obTotal },
      retry: retryMatch ? retryMatch[1].trim() : '',
    });
  }
  return parsed;
}

// Start JY 9/30/26/1:130PM
function pickGatewayEntries(parsed, profileByUuid) {
  const chosen = new Map();
  for (const entry of parsed) {
    const current = chosen.get(entry.uuid);
    if (!current) {
      chosen.set(entry.uuid, entry);
      continue;
    }
    const wanted = String((profileByUuid && profileByUuid.get(entry.uuid)) || '').trim();
    if (wanted && current.profile !== wanted && entry.profile === wanted) {
      chosen.set(entry.uuid, entry);
    }
  }
  return [...chosen.values()];
}
// End JY 9/30/26/1:130PM

function classifyGatewayState(sofiaState, registerFlag) {
  const registers = String(registerFlag == null ? '' : registerFlag).trim().toLowerCase() === 'true';
  switch (sofiaState) {
    case 'REGED':
      return 'registered';
    case 'TRYING':
    case 'REGISTER':
      return 'trying';
    case 'NOREG':
      return registers ? 'down' : 'not_registering';
    default:
      return 'down';
  }
}

async function gatewayTenantMap(uuids) {
  const map = new Map();
  if (!uuids.length) return map;
  try {
     // Start JY 9/30/26/1:130PM
    const { rows } = await db2.query(
      'SELECT g.gateway_uuid, g.gateway, g.register, g.profile, d.domain_name FROM v_gateways g JOIN v_domains d ON d.domain_uuid = g.domain_uuid WHERE g.gateway_uuid::text = ANY($1)',
      [uuids]
    );
    for (const row of rows) {
      map.set(String(row.gateway_uuid), {
        gateway: row.gateway,
        register: row.register,
        profile: row.profile,
        subdomain: row.domain_name,
      });
    }
    // End JY 9/30/26/1:130PM
  } catch (error) {
    console.error('\u274c Error gatewayTenantMap:', error);
  }
  return map;
}

async function storeGatewayStatus(bodyText) {
  // Start JY 9/30/26/1:130PM
  const lines = parseGatewayRegLines(bodyText);
  if (!lines.length) return [];
  const tenants = await gatewayTenantMap([...new Set(lines.map((entry) => entry.uuid))]);
  const profileByUuid = new Map([...tenants].map(([uuid, tenant]) => [uuid, tenant.profile]));
  const parsed = pickGatewayEntries(lines, profileByUuid);
  const observedAt = Math.floor(Date.now() / 1000);
  const entries = [];
  // End JY 9/30/26/1:130PM
  for (const entry of parsed) {
    const tenant = tenants.get(entry.uuid);
    const record = {
      ...entry,
      sofia_state: entry.state,
      register: tenant ? String(tenant.register == null ? '' : tenant.register).trim() : '',
      state: classifyGatewayState(entry.state, tenant ? tenant.register : ''),
      gateway: tenant ? tenant.gateway : '',
      subdomain: tenant ? tenant.subdomain : '',
      observed_at: observedAt,
    };
    try {
      await redisClient.set(`gatewayStatus-${record.uuid}`, JSON.stringify(record), {
        EX: GATEWAY_STATUS_TTL_SECONDS,
      });
    } catch (error) {
      console.error('\u274c Error storeGatewayStatus write:', error);
    }
    entries.push(record);
  }
  return entries;
}

async function pushGatewayStatusChanges(entries) {
  if (!entries || !entries.length) return;
  const byTenant = new Map();
  console.log("gatewaystatuspush 1")
  for (const entry of entries) {
    if (!entry.subdomain) continue;
    const previous = gatewayStatusPrevious.get(entry.uuid);
    gatewayStatusPrevious.set(entry.uuid, entry.state);
    if (previous === entry.state) continue;
    if (!byTenant.has(entry.subdomain)) byTenant.set(entry.subdomain, []);
    byTenant.get(entry.subdomain).push(entry);
  }
  for (const [subdomain, changed] of byTenant) {
    try {
      socketClient.emit('gatewaystatuspush', { subdomain, gateways: changed });
    } catch (error) {
      console.error('\u274c Error pushGatewayStatusChanges:', error);
    }
  }
}

async function gatewayRegStatus() {
  if (gatewayRegStatusRunning) return;
  gatewayRegStatusRunning = true;
  try {
    const commandEsl = await safeApi('sofia status reg');
    if (!commandEsl || !commandEsl.body) return;
    const entries = await storeGatewayStatus(commandEsl.body);
    await pushGatewayStatusChanges(entries);
  } catch (error) {
    console.error(`\u274c Error gatewayRegStatus:`, error);
  } finally {
    gatewayRegStatusRunning = false;
  }
}

async function processCallLogs(ev, type, state, timezone) {

     const callerExtensionPresence = await (async () => {
          return ev.getHeader('variable_effective_caller_id_number') ? ev.getHeader('variable_effective_caller_id_number') : ev.getHeader('variable_caller_id_number');
     })();

     const effectiveCallerName = await (async () => {

          const effectiveName = await redisClient.get (`effectiveName-${callerExtensionPresence}@${ev.getHeader('variable_domain_name')}`)
          if(effectiveName){
               return effectiveName;
          }
          return callerExtensionPresence;

     })();

     const destinationRes = await (async () => {
          const prevExt = ev.getHeader('variable_previous_call_extension');
          if (prevExt) {
               const [destExt] = prevExt?.split('@');
               return await getDestExtValid(destExt, prevExt, ev);
          } else {
               return state === 'bridge'
               ? await getSipUser(ev)
               : ev.getHeader('variable_dialed_extension') || ev.getHeader('variable_dialed_user');
          }
     })();

     const destination = destinationRes ? destinationRes : ev.getHeader('Caller-Destination-Number');
     const recordingPath = recordingFilePath(ev.getHeader('Channel-Call-UUID'), ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));

     const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/').pop() : ev.getHeader('Channel-Call-UUID');
     const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;
     
     const originate_uuid = await (async() => {
          if(ev.getHeader('variable_cc_agent_uuid')){
               return ev.getHeader('variable_cc_agent_uuid');
          }else{
               return ev.getHeader('Unique-ID');
          }
     })();

     const legTransfer = await (async() => {
          if(
               ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
               ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
          ){
               return 'a'
          }
     })();

     const lastArg =  await (async() => {
          if(ev.getHeader('variable_callFeatures') === 'Attended_Transfer'){
               return 'attended_transfer'
          }
          if(ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'){
               return 'blind_transfer'
          }
     })();


    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'long'
    });

    const startTime = new Date(Number(ev.getHeader('variable_process_start_epoch') || ev.getHeader('variable_start_epoch')) * 1000);
    const answeredTime = new Date(Number(ev.getHeader('variable_process_answer_epoch') || ev.getHeader('variable_answer_epoch')) * 1000);
    const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);
    const newStartEpoch = Math.floor(startTime.getTime() / 1000);
    const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
    const newEndEpoch = Math.floor(endTime.getTime() / 1000);

    console.log({
        "Argentina Time Start": formatter.format(startTime),
        "Argentina Time Answered:": formatter.format(answeredTime),
        "Argentina Time: End": formatter.format(endTime),
        newStartEpoch,
        newAnsweredEpoch,
        newEndEpoch,
        "Start": formatDateWithOffset(startTime, timezone),
        "Answered": formatDateWithOffset(answeredTime, timezone),
        "End": formatDateWithOffset(endTime, timezone)
    });

    const hangUpCause = () => {
          if(ev.getHeader('variable_cc_cause') === 'cancel' || ev.getHeader('variable_DIALSTATUS') === 'CANCEL'){
               return 'ORIGINATOR_CANCEL'
          }
          return ev.getHeader('variable_hangup_cause')
    }

    const voicemail_message = !!ev.getHeader('variable_voicemail_message_seconds');
    if (effectiveCallerName && callerExtensionPresence) {
        const callLogsPayload2 = {
            call_id: ev.getHeader('Channel-Call-UUID'),
            direction: ev.getHeader('variable_call_direction'),
            extension_uuid: ev.getHeader('variable_extension_uuid'),
            call_center_queue_uuid: null,
            start_stamp: formatDateWithOffset(startTime, timezone),
            answer_stamp: formatDateWithOffset(answeredTime, timezone),
            start_epoch: newStartEpoch,
            answer_epoch: newAnsweredEpoch,
            end_stamp: formatDateWithOffset(endTime, timezone),
            end_epoch: newEndEpoch,
            caller_id_name: effectiveCallerName,
            caller_id_number: callerExtensionPresence,
            caller_destination: destination,
            destination_number: destination,
            duration: ev.getHeader('variable_duration'),
            voicemail_message,
            missed_call: true,
            billsec: ev.getHeader('variable_billsec'),
            waitsec: ev.getHeader('variable_waitsec'),
            hangup_cause: hangUpCause(),
            source_number: ev.getHeader('variable_number_alias') || ev.getHeader('variable_caller_id_number'),
            record_name: fileName,
            record_path: filePath,
            domain_uuid: ev.getHeader('variable_domain_uuid'),
            sip_call_id: ev.getHeader('variable_orig_sip_call_id'),
            hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
            sip_hangup_disposition: ev.getHeader('variable_sip_hangup_disposition'),
            cc_queue: ev.getHeader('variable_cc_queue'),
            cc_agent_uuid: ev.getHeader('variable_cc_agent_uuid'),
            cc_agent: ev.getHeader('variable_cc_agent'),
            cc_cause: ev.getHeader('variable_cc_cause'),
            cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch'),
            record_length: ev.getHeader('variable_record_seconds'),
            type,
            originating_leg_uuid: ev.getHeader('variable_originating_leg_uuid') || originate_uuid,
            leg: legTransfer,
            last_arg: lastArg
        };
        if(destination !== 'bridge_to_agent'){
            console.log(`CALL LOGS INBOUND:`, callLogsPayload2);
            redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));
            console.log("this is where it save inbound", `callLogs-${ev.getHeader('variable_domain_name')}`);
        }
    }

}

// async function outboundProcessCallLogs(ev, type, state, timezone){
//      if(ev.getHeader('variable_domain_name') && ev.getHeader('variable_sip_full_from')){


//           const fromSip = ev.getHeader('variable_sip_full_from');
//           const match = fromSip.match(/<sip:([^@<>]+@[^;<>]+)[^>]*>/);
//           const callerNumberOrig = match ? match[1] : null;
//           if(callerNumberOrig){

//                const [callerExt, callerSubdomainExt] = callerNumberOrig?.split('@');
//                const toSip = ev.getHeader('variable_sip_full_to');
//                const match2 = toSip.match(/<sip:([^@<>]+@[^;<>]+)[^>]*>/);
//                const destinationNumberOrig = match2 ? match2[1] : null;
//                const [destExt, destSubdomainExt] = destinationNumberOrig?.split('@');
//                const destRes = await getDestExtValid(destExt, destinationNumberOrig, ev);

//                const destExtValid = async () => {

//                     const presene = ev.getHeader('Channel-Presence-ID')
//                     if(presene){

//                          const extensionPresence = presene?.split('@')[0]
//                          const valid = isValidNumber(extensionPresence);
//                          if(!valid){
//                              return await getDestExtValid(extensionPresence, presene, ev);
//                          }else{
//                               return extensionPresence
//                          }

//                     }else{

//                          const contact = ev.getHeader('Caller-Destination-Number');
//                          function getRegistrationMatch(){
//                               return new Promise((resolve, reject) => {
//                                    conn.api(`sofia status profile internal reg`, (reg) => {
//                                         try {
//                                              function parseLog(log) {
//                                                   const blocks = log.trim()?.split(/\n\s*\n/); // split by empty line
//                                                   const registrations = [];
//                                                   let total = 0;

//                                                   for (const block of blocks) {
//                                                   if (block.startsWith("Total items")) {
//                                                        total = parseInt(block.replace(/\D+/g, ""), 10);
//                                                        continue;
//                                                   }

//                                                   const entry = {};
//                                                   block?.split("\n").forEach(line => {
//                                                        const match = line.match(/^(.*?):\s*\t?(.*)$/);
//                                                        if (match) {
//                                                             const key = match[1].trim();
//                                                             const value = match[2].trim();
//                                                             entry[key] = value;
//                                                        }
//                                                   });
//                                                   registrations.push(entry);
//                                                   }

//                                                   return { Registrations: registrations, Total: total };
//                                              }

//                                              const result = parseLog(reg.body);

//                                              // ✅ use find instead of forEach
//                                              const match = result.Registrations.find(
//                                                   (x) => x.Contact.includes(contact)
//                                              );

//                                              if (match) {
//                                                   resolve(match); // return the match properly
//                                              } else {
//                                                   resolve(null); // no match found
//                                              }

//                                         } catch (err) {
//                                              reject(err);
//                                         }
//                                    });
//                               });
//                          }
//                          const value = await getRegistrationMatch();
//                          if(value){
//                               const [alias, subdomain] = value['User']?.split('@')
//                               const extensionFunction = async () => {
//                                    const checkValid = isValidNumber(alias)
//                                    if(!checkValid){
//                                         const { rows } = await db2.query(
//                                              `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
//                                              [alias, subdomain]
//                                         );
//                                         if(rows.length){
//                                             return rows[0].extension; 
//                                         }
//                                    }
//                                    return alias
//                               }
//                               return await extensionFunction()
//                          }
//                          return ev.getHeader('Caller-Destination-Number');

//                     }
                    
//                }

//                const recordingPath = recordingFilePath(ev.getHeader('variable_bridge_uuid'), ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));
//                const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/').pop() : ev.getHeader('variable_bridge_uuid');
//                const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;

//                const callerExtensionPresence = await (async () => {

//                     const currentExt = ev.getHeader('variable_current_call_extension');
//                     if (currentExt) {

//                          const [callerExt2] = currentExt?.split('@');
//                          return await getCallerExtValid(callerExt2, currentExt, ev);

//                     } else {

//                          const extensionOrig = await getCallerExtValid(callerExt, callerNumberOrig, ev);
//                          const destinationNumber = await destExtValid()
//                          if(extensionOrig && destinationNumber){

//                               const bridgeChannel = ev.getHeader('variable_bridge_channel')
//                               if(bridgeChannel){
//                                    return await presenceId(bridgeChannel)
//                               }
//                               if( destinationNumber !== extensionOrig){
//                                    return extensionOrig
//                               }

//                          }
//                     }

//                })();

//                const effectiveCallerName = await (async () => {
                    
//                     const effectiveName = await redisClient.get (`effectiveName-${callerExtensionPresence}@${ev.getHeader('variable_domain_name')}`)
//                     if(effectiveName){
//                          return effectiveName;
//                     }
//                     return callerExtensionPresence;

//                })();

//                const validcallerExtensionPresence = () => {

//                     if(ev.getHeader('variable_origination_caller_id_number')){
//                          const click2TalkName =  `${ev.getHeader('variable_origination_caller_id_number')?.split('@')[0]} (Click2Talk)`
//                          return ev.getHeader('variable_original_caller_id_name') === '100000' ?  
//                          click2TalkName : 
//                          callerExtensionPresence
//                     }else{
//                          return callerExtensionPresence;
//                     }

//                }

//                const validEffectiveCallerName = () => {

//                     if(ev.getHeader('variable_origination_caller_id_number')){
//                          const click2TalkName =  `${ev.getHeader('variable_origination_caller_id_number')?.split('@')[0]} (Click2Talk)`
//                          return ev.getHeader('variable_original_caller_id_name') === '100000' ?  
//                          click2TalkName : 
//                          effectiveCallerName
//                     }else{
//                          return effectiveCallerName;
//                     }

//                }

//                const originate_uuid = await (async() => {
//                     if(ev.getHeader('variable_cc_agent_uuid')){
//                          return ev.getHeader('variable_cc_agent_uuid');
//                     }else{
//                          return ev.getHeader('Channel-Call-UUID');
//                     }
//                })();

//                const legTransfer = await (async() => {
//                     if(
//                          ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
//                          ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
//                     ){
//                          return 'b'
//                     }
//                })();

//                const lastArg =  await (async() => {
//                     if(ev.getHeader('variable_callFeatures') === 'Attended_Transfer'){
//                          return 'attended_transfer'
//                     }
//                     if(ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'){
//                          return 'blind_transfer'
//                     }
//                })();

//                const formatter = new Intl.DateTimeFormat('en-US', {
//                     timeZone: timezone,
//                     dateStyle: 'full',
//                     timeStyle: 'long'
//                });

//                const startTime = new Date(Number(ev.getHeader('variable_process_start_epoch') || ev.getHeader('variable_start_epoch')) * 1000);
//                const answeredTime = new Date(Number(ev.getHeader('variable_process_answer_epoch') || ev.getHeader('variable_answer_epoch')) * 1000);
//                const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);

//                const newStartEpoch = Math.floor(startTime.getTime() / 1000);
//                const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
//                const newEndEpoch = Math.floor(endTime.getTime() / 1000);

//                console.log({
//                     "Argentina Time Start": formatter.format(startTime),
//                     "Argentina Time Answered:": formatter.format(answeredTime),
//                     "Argentina Time: End": formatter.format(endTime),
//                     newStartEpoch,
//                     newAnsweredEpoch,
//                     newEndEpoch,
//                     "Start": formatDateWithOffset(startTime, timezone),
//                     "Answered": formatDateWithOffset(answeredTime, timezone),
//                     "End": formatDateWithOffset(endTime, timezone)
//                });

//                const dialStatus2 = () => !(
//                     (ev.getHeader('variable_transfer_to') && ev.getHeader('variable_transfer_to').includes('blind')) ||
//                     ev.getHeader('variable_DIALSTATUS') === 'SUCCESS' ||
//                     ev.getHeader('variable_hangup_cause') === 'NORMAL_CLEARING' || 
//                     ev.getHeader('variable_cc_cause') === 'answered'
//                );

//                const voicemail_message = ev.getHeader('variable_voicemail_message_seconds') ? true : false;
//                const queueCause = () => {
//                     if(ev.getHeader('variable_endpoint_disposition') === 'ANSWER'){
//                          return 'answered'
//                     }
//                     return ev.getHeader('variable_cc_cause')
//                }
//                const uniqueCallId = ev.getHeader('variable_bridge_uuid') || uuidv4();
//                const result = await db2.query(
//                'SELECT domain_uuid FROM v_domains WHERE domain_name = $1',
//                [ev.getHeader('variable_domain_name')]
//                );
//                const domainId = result.rows[0]?.domain_uuid;

//                const origDomainId = ev.getHeader('variable_domain_uuid') || domainId;
//                const presenceExtensionOrig = async () => {

//                     if(ev.getHeader('Channel-Presence-ID')){
//                          return ev.getHeader('Channel-Presence-ID')?.split('@')[0]
//                     }

//                     if(
//                          ev.getHeader('variable_bridge_channel') && 
//                          ev.getHeader('variable_bridge_channel').includes('internal')
//                     ){
//                          const presence = await sipProfileURL(ev.getHeader('variable_bridge_channel'));
//                          const extensionPresence = presence?.split('@')[0];
//                          const valid = isValidNumber(extensionPresence);
//                          if(!valid){
//                              return await getDestExtValid(extensionPresence, presence, ev);
//                          }else{
//                              return extensionPresence
//                          }
//                     }
//                     return;
//                }

//                const presenceExtension = await presenceExtensionOrig(ev);
//                const result2 = await db2.query(
//                'SELECT extension_uuid FROM v_extensions WHERE domain_uuid = $1 AND extension = $2',
//                [origDomainId, presenceExtension]
//                );
//                const extensionId = result2.rows[0]?.extension_uuid;

//                const callDirection = async () => {
//                     const dest = await destExtValid()
//                     if(classifyNumber(Number(dest)) === 'ExternalNumber'){
//                          return 'outbound'
//                     }
//                     return 'inbound'
//                }

//                const sipCallOrig = async () => {
//                     if(ev.getHeader('variable_cc_export_vars') && ev.getHeader('variable_cc_export_vars').includes('sip_h_X-Original-Call-ID')){
//                          return ev.getHeader('variable_cc_export_vars')?.split('sip_h_X-Original-Call-ID=')[1];
//                     }
//                     return null
//                }

//                const callLogsPayload2 = {
//                     call_id: uniqueCallId,
//                     direction: await callDirection(),
//                     extension_uuid: ev.getHeader('variable_extension_uuid') || extensionId,
//                     call_center_queue_uuid: null, 
//                     start_stamp: formatDateWithOffset(startTime, timezone),
//                     answer_stamp: formatDateWithOffset(answeredTime, timezone),
//                     start_epoch: newStartEpoch,
//                     answer_epoch: newAnsweredEpoch,
//                     end_stamp: formatDateWithOffset(endTime, timezone),
//                     end_epoch: newEndEpoch,
//                     caller_id_name: await validEffectiveCallerName(), 
//                     caller_id_number: await validcallerExtensionPresence(), 
//                     caller_destination: await destExtValid(), 
//                     destination_number: await destExtValid(), 
//                     duration: ev.getHeader('variable_duration'),
//                     voicemail_message : voicemail_message, 
//                     missed_call: dialStatus2(), 
//                     waitsec: ev.getHeader('variable_waitsec'), 
//                     hangup_cause: ev.getHeader('variable_hangup_cause'), 
//                     source_number: ev.getHeader('variable_number_alias') || ev.getHeader('variable_caller_id_number') || ev.getHeader('Caller-Caller-ID-Name'), 
//                     record_name: fileName,
//                     record_path: filePath,
//                     domain_uuid: ev.getHeader('variable_domain_uuid') || domainId,
//                     sip_call_id: ev.getHeader('variable_sip_h_X-Original-Call-ID') || await sipCallOrig(),
//                     hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
//                     sip_hangup_disposition : ev.getHeader('variable_sip_hangup_disposition'),
//                     cc_queue : ev.getHeader('variable_cc_queue'),
//                     cc_agent_uuid : ev.getHeader('variable_cc_agent_uuid'),
//                     cc_agent: ev.getHeader('variable_cc_agent'),
//                     cc_cause: queueCause(),
//                     cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch') || ev.getHeader('variable_answer_epoch'),
//                     record_length: ev.getHeader('variable_record_seconds') || ev.getHeader('variable_billsec'),
//                     type: type,
//                     originating_leg_uuid: ev.getHeader('Unique-ID'),
//                     leg: legTransfer,
//                     last_arg: lastArg
//                }
//                console.log(`CALL LOGS OUTBOUND:`, callLogsPayload2);
//                redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));
//           }
//      }
// }

const parallelCallLogs = async(ev, type, state, timezone, callerNumberOrig) => {

     if(callerNumberOrig){

               const [callerExt, callerSubdomainExt] = callerNumberOrig?.split('@');
               const result = await db2.query(
               'SELECT domain_uuid FROM v_domains WHERE domain_name = $1',
               [ev.getHeader('variable_domain_name')]
               );
               const domainId = result.rows[0]?.domain_uuid;

               const uniqueCallId = ev.getHeader('variable_bridge_uuid') || uuidv4();
               const origDomainId = ev.getHeader('variable_domain_uuid') || domainId;

               const result2 = await db2.query(
               'SELECT extension_uuid FROM v_extensions WHERE domain_uuid = $1 AND extension = $2',
               [origDomainId, callerExt]
               );
               const extensionId = result2.rows[0]?.extension_uuid;
               const callerExtensionPresence = await (async () => {

                    const currentExt = ev.getHeader('variable_current_call_extension');
                    if (currentExt) {

                         const [callerExt2] = currentExt?.split('@');
                         return await getCallerExtValid(callerExt2, currentExt, ev);

                    } else {

                         const extensionOrig = await getCallerExtValid(callerExt, callerNumberOrig, ev);
                         const destinationNumber = ev.getHeader('Caller-Destination-Number')
                         if(extensionOrig && destinationNumber){

                              const bridgeChannel = ev.getHeader('variable_bridge_channel')
                              if(bridgeChannel){
                                  return await presenceId(bridgeChannel)
                              }

                              if( destinationNumber !== extensionOrig){
                                   return extensionOrig
                              }
                         }
                    }

               })();

               const effectiveCallerName = await (async () => {

                    const effectiveName = await redisClient.get (`effectiveName-${callerNumberOrig}`);
                    const presenceExtensionNew = `${callerExtensionPresence}@${ev.getHeader('variable_domain_name')}`
                    const effectiveName2 = await redisClient.get (`effectiveName-${presenceExtensionNew}`);

                    if(effectiveName){
                         return effectiveName;
                    }
                    if(effectiveName2){
                         return effectiveName2;
                    }
                    return callerExtensionPresence;
               })();

               const voicemail_message = ev.getHeader('variable_voicemail_message_seconds') ? true : false;
               const dialStatus2 = () => !(
                    (ev.getHeader('variable_transfer_to') && ev.getHeader('variable_transfer_to').includes('blind')) ||
                    ev.getHeader('variable_DIALSTATUS') === 'SUCCESS' ||
                    ev.getHeader('variable_hangup_cause') === 'NORMAL_CLEARING' || 
                    ev.getHeader('variable_cc_cause') === 'answered'
               );

               const sipCallOrig = async () => {
                    if(ev.getHeader('variable_cc_export_vars') && ev.getHeader('variable_cc_export_vars').includes('sip_h_X-Original-Call-ID')){
                         return ev.getHeader('variable_cc_export_vars')?.split('sip_h_X-Original-Call-ID=')[1];
                    }
                    return null
               }

               const queueCause = () => {
                    if(ev.getHeader('variable_endpoint_disposition') === 'ANSWER'){
                         return 'answered'
                    }
                    return ev.getHeader('variable_cc_cause')
               }

               const legTransfer = await (async() => {
                    if(
                         ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
                         ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
                    ){
                         return 'b'
                    }
               })();

               const lastArg =  await (async() => {
                    if(ev.getHeader('variable_callFeatures') === 'Attended_Transfer'){
                         return 'attended_transfer'
                    }
                    if(ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'){
                         return 'blind_transfer'
                    }
               })();

               /** Recordings **/
               const recordingPath = recordingFilePath(ev.getHeader('variable_bridge_uuid'), ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));
               const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/').pop() : ev.getHeader('variable_bridge_uuid');
               const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;

               /** Time Epoch and Timestamp */
               const startTime = new Date(Number(ev.getHeader('variable_process_start_epoch') || ev.getHeader('variable_start_epoch')) * 1000);
               const answeredTime = new Date(Number(ev.getHeader('variable_process_answer_epoch') || ev.getHeader('variable_answer_epoch')) * 1000);
               const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);

               const newStartEpoch = Math.floor(startTime.getTime() / 1000);
               const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
               const newEndEpoch = Math.floor(endTime.getTime() / 1000);

               if(callerExtensionPresence < 10000){

                    const callLogsPayload2 = {
                         call_id: uniqueCallId,
                         direction: 'outbound',
                         extension_uuid: ev.getHeader('variable_extension_uuid') || extensionId,
                         call_center_queue_uuid: null, 
                         start_stamp: formatDateWithOffset(startTime, timezone),
                         answer_stamp: formatDateWithOffset(answeredTime, timezone),
                         start_epoch: newStartEpoch,
                         answer_epoch: newAnsweredEpoch,
                         end_stamp: formatDateWithOffset(endTime, timezone),
                         end_epoch: newEndEpoch,
                         caller_id_name: effectiveCallerName, 
                         caller_id_number: callerExtensionPresence, 
                         caller_destination: ev.getHeader('Caller-Destination-Number'), 
                         destination_number: ev.getHeader('Caller-Destination-Number'),
                         duration: ev.getHeader('variable_duration'),
                         voicemail_message : voicemail_message, 
                         missed_call: dialStatus2(), 
                         waitsec: ev.getHeader('variable_waitsec'), 
                         billsec: ev.getHeader('variable_billsec'), 
                         hangup_cause: ev.getHeader('variable_hangup_cause'), 
                         source_number: ev.getHeader('variable_number_alias') || ev.getHeader('variable_caller_id_number') || ev.getHeader('Caller-Caller-ID-Name'), 
                         record_name: fileName,
                         record_path: filePath,
                         domain_uuid: ev.getHeader('variable_domain_uuid') || domainId,
                         sip_call_id: ev.getHeader('variable_sip_h_X-Original-Call-ID') || await sipCallOrig(),
                         hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
                         sip_hangup_disposition : ev.getHeader('variable_sip_hangup_disposition'),
                         cc_queue : ev.getHeader('variable_cc_queue'),
                         cc_agent_uuid : ev.getHeader('variable_cc_agent_uuid'),
                         cc_agent: ev.getHeader('variable_cc_agent'),
                         cc_cause: queueCause(),
                         cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch') || ev.getHeader('variable_answer_epoch'),
                         record_length: ev.getHeader('variable_record_seconds') || ev.getHeader('variable_billsec'),
                         type: type,
                         originating_leg_uuid: ev.getHeader('Unique-ID'),
                         leg: legTransfer,
                         last_arg: lastArg
                    }
                    console.log(`CALL LOGS PARALLEL:`, callLogsPayload2);
                    redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));

               }
     }

}
  
const vgateWays = async(num, domainId, extensionNum) => {
     
     console.log('VGATEWAYS: ', num, domainId, extensionNum)
     const result = await db2.query(
     'SELECT * FROM v_gateways WHERE domain_uuid  = $1 AND username = $2',
     [domainId, num]);
     if(result.rows.length){
          return extensionNum
     }
}

const queueDataReload = async(subdomain) => {

     /** Emit Wallboard Call Status */
     
     const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${subdomain}`, 0, -1);
     if (queueCallBoardList) {

          if(queueCallBoardList.length){
               const newContainer = queueCallBoardList.map(el => JSON.parse(el));
               for (const [index, data] of newContainer.entries()) {
                    const element = JSON.stringify(data);
                    const res = conn.api(`uuid_exist ${data.uuid}`);
                    console.log(`💥1💥`, res);
                    if(res){
                         const { body } = res;
                         if (body === 'false') {
                               console.log("qcallboard check 2", `queueCallBoard-${subdomain}`, index, element)
                              await redisClient.lRem(`queueCallBoard-${subdomain}`, index, element);
                         }
                    }
               }
          }
          
          const queueCallBoardList2 = await redisClient.lRange(`queueCallBoard-${subdomain}`, 0, -1);
          const newContainer2 = queueCallBoardList2.map(el => JSON.parse(el));
          // await redisClient.set(`${subdomain}:queueWallboardCallStatus`, JSON.stringify(newContainer2));
          // console.log(`💥💥wallboardCallStatus💥💥`, JSON.stringify(newContainer2));
          // socketClient.emit('wallboardCallStatus', { 
          //      subdomain, 
          //      data: JSON.stringify(newContainer2) 
          // });


          //cid_name
          console.log("queuecallboard check list 1", `${queueCallBoardList2}`);
          
          //Added 08-18-26
          const parseCallBoard = queueCallBoardList.map(el => JSON.parse(el)).filter(Boolean);
          const callBoardUUID = await redisClient.get(`queueAnsweredCallboardUUID-${subdomain}`);
          if(queueCallBoardList2[0] && queueCallBoardList2[0].cid_name){
               console.log("this is the queueCallboardList cid", queueCallBoardList2[0].cid_name);
          }
          //Issue `answeredCCAgent:${findCallBoard.queue}
          const findCallBoard = parseCallBoard.find(item => item.uuid === callBoardUUID);
          
               if (findCallBoard) {
                    const servingAgent = callBoardUUID
                    // if(findCallBoard.session_uuid == ""){
                    //    findCallBoard.session_uuid = callBoardUUID
                    //   console.log("serving agent", findCallBoard.session_uuid )
                    // }

                    let getAnsweredCC = await redisClient.get(`answeredCCAgent:${findCallBoard.queue}`);
                    console.log("this is getAnsweredCC", getAnsweredCC)
                    console.log("this is callUUID", callBoardUUID);

                    if(!getAnsweredCC){
                         getAnsweredCC = servingAgent
                         console.log("no answered cc then", servingAgent);
                    }

                    console.log("answeredCC", `answeredCCAgent:${findCallBoard.queue}`)
                    const findCallBoardAnswered = {...findCallBoard, 'session_uuid': servingAgent, 'state': 'Answered', 'serving_agent' : getAnsweredCC, }
                    // redisClient.del(`answeredCCAgent:${findCallBoard.queue}`);
                    const findIndexCallBoard = parseCallBoard.findIndex(item => item.uuid === callBoardUUID);
                    console.log("has callboard 1.4", `queueCallBoard-${subdomain}`, findIndexCallBoard, JSON.stringify(findCallBoardAnswered))
                    await redisClient.lSet(`queueCallBoard-${subdomain}`, findIndexCallBoard, JSON.stringify(findCallBoardAnswered));
                    const data = [];
                    data.push(findCallBoardAnswered);

                    console.log("this is the data now for answered", findCallBoardAnswered);
                    redisClient.set(`wallboardAddAnswer-${subdomain}`, JSON.stringify(data));
                    redisClient.del(`queueAnsweredCallboardUUID-${subdomain}`);
                    // socketClient.emit('wallboardCallStatus', {subdomain, data: JSON.stringify(data)} )
                    console.log("sending to signal again...")
                    console.log("deleting ....", `queueAnsweredCallboardUUID-${subdomain}`);
                    console.log("adding ....", `wallboardAddAnswer-${subdomain}`, JSON.stringify(data));
                    //socketServerSignal.emit('wallboardCallStatus', { subdomain, data: JSON.stringify([]) });
               }
          ///Added 08-18-26


     }

     /** AgentStatus  */
     const agentStatus =  await redisClient.get(`${subdomain}:queueWallboardAgentBoard2`);
     if(!agentStatus) return
     const parseAgentStatus = JSON.parse(agentStatus);
     if(parseAgentStatus.length){
          // console.log(`💡`, parseAgentStatus);
          // socketClient.emit('wallboardAgentStatus', {
          //      subdomain: subdomain, 
          //      data: JSON.stringify(parseAgentStatus)
          // });
     }

     /** ConfigStatus */
     const configStatus =  await redisClient.get(`${subdomain}:queueWallboardConfigBoard`);
     if(!configStatus) return
     const parseConfigStatus = JSON.parse(configStatus)
     socketClient.emit('wallboardConfigStatus', {
          subdomain: subdomain, 
          data: JSON.stringify(parseConfigStatus)
     });

}

const activeQueueReload = async() => {

     /** Emit Wallboard Call Status */
     const subDomains = await redisClient.sMembers(`allSubdomain`);
     console.log(`💥`, subDomains);

     if(subDomains.length){

          for (const domain of subDomains) {
               const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${domain}`, 0, -1);
               // console.log("queuecallboard...");
               if (queueCallBoardList) {

                    if(queueCallBoardList.length){
                         const newContainer = queueCallBoardList.map(el => JSON.parse(el));
                         for (const [index, data] of newContainer.entries()) {
                              const element = JSON.stringify(data);
                              const res = conn.api(`uuid_exist ${data.uuid}`);
                              // console.log("queuecallboard check list 2.3", `${queueCallBoardList}`);
                              if(res){
                                   const { body } = res;
                                   if (body === 'false') {
                                         console.log("queuecallboard check list 2.31", `${queueCallBoardList}`);
                                        await redisClient.lRem(`queueCallBoard-${domain}`, index, element);
                                   }
                              }
                         }
                    }

                    // const queueCallBoardList2 = await redisClient.lRange(`queueCallBoard-${domain}`, 0, -1);
                    // const newContainer2 = queueCallBoardList2.map(el => JSON.parse(el));
                    // await redisClient.set(`${domain}:queueWallboardCallStatus`, JSON.stringify(newContainer2));

                    // socketClient.emit('wallboardCallStatus', { 
                    //      domain, 
                    //      data: JSON.stringify(queueCallBoardList2)
                    // });

               }
          }

     }

}

const safeApi =  async(cmd) => {
     if (!conn || !eslReady) {
          console.log('⚠️ ESL not ready, skipping:', cmd);
          return;
     }
     try {
          return await new Promise((resolve, reject) => {
               try {
                    conn.api(cmd, (res) => {
                         resolve(res);
                    });
               } catch (err) {
                    reject(err);
               }
          });
     } catch (error) {
           console.error('ESL API error', error);
     }
}

function classifyNumber(num) {
  const rule = extensionRules.find(r => num >= r.min && num <= r.max);
  return rule ? rule.type : 'InternalExtension';
}

const extensionAlias = async(presenceExtension, subDomain) => {
     const { rows } = await db2.query(
          `SELECT number_alias FROM v_extensions WHERE extension = $1 AND accountcode = $2`,
          [presenceExtension, subDomain]
     );
     if(rows.length){
          return rows[0].number_alias;
     }
     return null
}

const handleDelSchedule = async (ev, bd, conn) => {
     if(ev.getHeader('Task-Desc') === 'switch_ivr_schedule_transfer'){
          conn.api(`uuid_dump ${ev.getHeader('Task-Group')} JSON`, async ({body}) => {
               if(body === '-ERR No such channel!\n') return
               const data = JSON.parse(body);
               console.log(`🌲`, data);
               if(data['variable_transfer_source'] && data['variable_current_application'] !== 'bridge'){
                    console.log("not bridge");
                    const extensionNumber = async (extension) => {
                         const { rows } = await db2.query(
                              `SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                              [extension, data['variable_domain_name']]
                         );
                         if(rows.length){
                              return rows[0];
                         }
                         return extension
                    }
                    

                   
                    const destinationNumber = await extensionNumber(data['Caller-RDNIS']);
                    // if(ev.getHeader('variable_current_application') === 'ivr'){
                    //      destinationNumber = await extensionNumber(data['variable_caller_destination']);
                    //      console.log("this is extension now", destinationNumber);
                    // }
                    console.log("extensionnum", destinationNumber)
                    const destinationOrigExtension = destinationNumber;

                    //To be continue 08-28-26
                   const iosPushMissed = await redisClient.get(`pushIosIVRmissed--${destinationNumber}@${data['variable_domain_name']}`);

                    if(classifyNumber(Number(destinationOrigExtension)) === 'InternalExtension'){

                         const value = await fsRegistration();

                         if(data['Channel-Name'].includes('internal')){
                              const presence = await presenceId(data['Channel-Name']);
                              const findPresenceExt = value.filter(element =>
                                   (
                                        element['Auth-User'] === presence || 
                                        element['Contact'].includes(presence)
                                   ) &&
                                   element['Auth-Realm'] === data['variable_domain_name']
                              )
                              .map((e => e['Auth-User']))[0];
                              console.log(`🌲🌲`, presence, findPresenceExt);

                              if(findPresenceExt){
                                   const callerNumber = await extensionNumber(findPresenceExt);
                                   const payload = {
                                        domain: data['variable_domain_name'],
                                        extension: destinationOrigExtension,
                                        time: data['Event-Date-Timestamp'],
                                        id: uuidv4(),
                                        call: 'missed',
                                        caller_id: callerNumber.extension,
                                        nameCaller: callerNumber.effective_caller_id_name, 
                                        time2: data['Event-Date-Timestamp']
                                   };
                                   getToken(payload);
                                   getTokenMultipleDevice(payload);
                              }

                              //Added 08-25-26
                              

                              /// Added 08-25-26

                         }

                         if(data['Channel-Name'].includes('external')){
                              const presence = data['Channel-Name'].split('external/')[1].split('@')[0];
                              const payload = {
                                   domain: data['variable_domain_name'],
                                   extension: destinationNumber.extension || destinationNumber,
                                   time: data['Event-Date-Timestamp'],
                                   id: uuidv4(),
                                   call: 'missed',
                                   caller_id: presence,
                                   nameCaller: presence, 
                                   time2: data['Event-Date-Timestamp']
                              };
                              getToken(payload);
                              getTokenMultipleDevice(payload);
                         }

                    }
                    //To be continue 08-28-26

                    else if(classifyNumber(Number(destinationOrigExtension)) === 'IVR' && iosPushMissed){
                         console.log("6000 -> 6500");
                         console.log("this is IVR");


                         redisClient.del(`pushIosIVRmissed--${destinationNumber}@${data['variable_domain_name']}`);
                         //Added 09-01-26



                         ////Added 09-01-26

                         if(data['Channel-Name'].includes('external')){
                              const presence = data['Channel-Name'].split('external/')[1].split('@')[0];
                              const payload = {
                                   domain: data['variable_domain_name'],
                                   extension: destinationNumber.extension || destinationNumber,
                                   time: data['Event-Date-Timestamp'],
                                   id: uuidv4(),
                                   call: 'missed',
                                   caller_id: presence,
                                   nameCaller: presence, 
                                   time2: data['Event-Date-Timestamp']
                              };
                              getToken(payload);
                              getTokenMultipleDevice(payload);
                         }
                    }
                    //To be continue 08-28-26
               }
               //For IVR only

             
          });
     }
}

const handleCustom = async (ev, bd, conn) => {

     console.log(`✅️ CUSTOM`,  util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true }));

     if(ev.getHeader("Event-Subclass") == 'sofia::register'){

          console.log(`✅️ REGISTER`, util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true }));

          const callId =  ev.getHeader('call-id');
          const username = ev.getHeader('username');
          const alias = ev.getHeader('from-user');
          const domain = ev.getHeader("from-host");
          const domainId = ev.getHeader('domain_uuid');
          const contact = ev.getHeader('contact');
          const agent = ev.getHeader('user-agent');
          const extensionName  = ev.getHeader('effective_caller_id_name');
          const extensionNumber = ev.getHeader('effective_caller_id_number');
          const [devicePlatform , deviceUID] =  agent.split(' - ');
          const tokenIos = await redisClient.get(`devicePushtoken-${deviceUID}`);
          await showcallsEventRefresh(domain);
          

          const match = contact?.match(/sip:([a-z0-9]+)@([a-z0-9]+)\.invalid/i);
          const contactId = match ? `${match[1]}@${match[2]}` : null;
          if(contactId){
               console.log(`✅️ contactID`, contactId);
               if(agent.includes('iOS')){
                    if(tokenIos){
                         await redisClient.set(`tkn-${contactId}@${domain}`, tokenIos);
                    }
               }
          }

          if(ev.getHeader('call_timeout2') && ev.getHeader('accountcode') && ev.getHeader('Caller-Destination-Number')){
               console.log("setting time for call 3cx", `${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('accountcode')}`, ev.getHeader('call_timeout2'))
              await redisClient.set(`getTimeout-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('accountcode')}`, ev.getHeader('call_timeout2'))
          }

          if(deviceUID){
               await redisClient.set(`${domain}-${deviceUID.trim()}-platform`, devicePlatform.trim());
          }

          const extensionPresence = `${extensionNumber}@${domain}`

          const callActive = await redisClient.get(`${domain}${extensionNumber}-RingQ`);
          const getPushToken = await redisClient.get(`deviceIdIOS-${callActive}`);
          const activeEnd = await redisClient.get(`activeCallEnd@${domain}-${getPushToken}`);
          conn.api(`uuid_exists ${callActive}`, async ({ body }) => {
               console.log(`❄️BODY❄️`, body)
               if(body !== 'true' && !activeEnd){
                    if(getPushToken){
                         await redisClient.sRem(`iosDevicePushToken-${extensionPresence}`, getPushToken);
                    }
               }
          });

          //checker for Idle

          conn.api(`show calls as json`, async ({body}) => {
            const { row_count } = JSON.parse(body);
            if(row_count){

                conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, ({ body }) => {
                const { status, response} = JSON.parse(body);
                    if(status === 'success'){

                        const agentNames = response.filter(el => el.name.includes(ev.getHeader('domain_name')));
                        agentNames.forEach( ({ name }) => {

                            conn.api(`json {"command": "callcenter_config","data": {"arguments": "queue list agents","queue_name": "${name}"}}`, ({ body }) => {
                                const { status :status2, response :response2 } = JSON.parse(body)
                                if(status2 === 'success' && response2.length){
                                    const findAgent = response2.find(x => x.contact.includes(ev.getHeader('username')) && x.state === 'Idle');
                                    if(findAgent){
                                        console.log('✅️--SHOW CALLS--✅️4', findAgent);
                                        const {name : agentCallcenterName} = findAgent;

                                        console.log("set waiting 1");
                                        conn.api(`callcenter_config agent set state ${agentCallcenterName} Waiting`);
                                        socketClient.emit("agenDetailsWallboard", ev.getHeader('domain_name'));
                                    }
                                }
                            })
                        });

                    }     
                });
                
            }
          });

          const presenceAnsweredUId = await redisClient.get(`answered-active-${extensionNumber}@${domain}`);
          if(presenceAnsweredUId){
               conn.api(`uuid_exists ${presenceAnsweredUId}`, async ({body}) => {
                    if(body=== 'false'){
                         await redisClient.del(`answered-active-${extensionNumber}@${domain}`)
                    }
               });
          }

          /** QueueDataReload */
          queueDataReload(domain)
          redisClient.sAdd(`allSubdomain`, `${domain}`);
          /**  */

          const devices = ["android", "iOS", "Webapp"];
          const valid = devices.some((x) => agent.includes(x));
          if(valid){

               const extensionOrig = await extensionFunction(alias, domain);
               const sipContact = contact?.split('sip:')[1].replace('>', '');
               const sipContactId = sipContact?.split(';')[0];
               await redisClient.set(`ChannelSipURI-${sipContactId}`, ev.getHeader('from-user'), { EX: 604800 }); // [perf] TTL
               await redisClient.set(`ConferenceData-${sipContactId}`, ev.getHeader('from-user'), { EX: 604800 }); // [perf] TTL
               
               await redisClient.set(`${ev.getHeader('call-id')}-contact`, ev.getHeader('contact'), { EX: 604800 }); // [perf] TTL

               const [deviceVersion, deviceId] = ev.getHeader('user-agent')?.split(' - ');
               if(!deviceId) return;

               waitingCalls(domain, extensionOrig, agent, contact, domainId, alias, deviceId, callId, tokenIos);
               await redisClient.set(`${ev.getHeader("from-host")}-${deviceId}`, deviceVersion);
               await redisClient.set(`${ev.getHeader("from-host")}-${deviceId}-${extensionOrig}-contact`, ev.getHeader('contact'), { EX: 604800 }); // [perf] TTL
               await redisClient.set(`${ev.getHeader('call-id')}-deviceIds`, deviceId, { EX: 604800 }); // [perf] TTL
               await redisClient.set(`deviceUID-${deviceId}`, ev.getHeader('call-id'), { EX: 604800 }); // [perf] TTL

               if(ev.getHeader('user-agent').includes('iOS')){
                    const extContact = ev.getHeader('contact')?.split(';')[0]?.split('sip:')[1];
                    await redisClient.set(`${extContact}-IOS-deviceId`, deviceId);
                    console.log(`✳️`, extContact, deviceId);

               }

               const deviceToken =  await redisClient.get(`devicePushtoken-${deviceId}`);
               if(deviceToken){
                    const extContact = ev.getHeader('contact')?.split(';')[0]?.split('sip:')[1];
                    await redisClient.set(`uniquePresence-${domain}-${extContact}`, deviceToken);
                    await redisClient.set(`sipUniqueId-${deviceToken}`, callId);
               }

               if(extensionName  && extensionNumber){
                    /** Username */
                    redisClient.set(`extension-${alias}@${domain}`, JSON.stringify({'extension': extensionNumber, 'effectiveName': extensionName}));

                    /** Extension properties */
                    redisClient.set(`effectiveName-${extensionNumber}@${domain}`, extensionName);
                    redisClient.set(`username-${extensionNumber}@${domain}`, alias);
               }

               if(ev.getHeader('sip_alias')){
                    redisClient.set(`sipAlias:${extensionOrig}@${ev.getHeader('from-host')}`, ev.getHeader('sip_alias'));
               }else{
                    const sipAliasExists = await redisClient.exists(`sipAlias:${extensionOrig}@${ev.getHeader('from-host')}`);
                    if(sipAliasExists){
                         redisClient.del(`sipAlias:${extensionOrig}@${ev.getHeader('from-host')}`);
                    }
               }

               if(agent.includes('android')){

                    const sipContact = contact?.split('sip:')[1].replace('>', '');
                    const sipContactId = sipContact?.split(';')[0];
                    await redisClient.set(`contactSIP-${sipContactId}`, `${extensionOrig}@${domain}`, { EX: 604800 }); // [perf] TTL
                    await redisClient.set(`SIP-${extensionOrig}@${domain}`, `${sipContactId}`);
                    await redisClient.sAdd(`ChannelName-${extensionOrig}@${domain}`, `${sipContactId}`);

                    await redisClient.sAdd(`deviceOn-${extensionOrig}@${domain}`, deviceId);
                    await redisClient.sRem(`deviceOff-${extensionOrig}@${domain}`, deviceId);
                    const devicePresence = `androidPresence-${deviceId}-${extensionOrig}@${domain}`;
                    const deviceId2 = `deviceId-${extensionOrig}@${ev.getHeader('from-host')}`;

                    const key = `sofia-${callId}-${extensionOrig}@${domain}`;
                    redisClient.set(key, agent);
                    redisClient.set(deviceId2, deviceId);
                    redisClient.set(`device-${deviceId}`, agent);
                    redisClient.del(devicePresence);

               }

               if(agent.includes('iOS')){

                    const sipContact = contact?.split('sip:')[1].replace('>', '');
                    const sipContactId = sipContact?.split(';')[0];
                    redisClient.set(`contactSIP-${sipContactId}`, `${extensionOrig}@${domain}`, { EX: 604800 }); // [perf] TTL
                    redisClient.set(`SIP-${extensionOrig}@${domain}`, `${sipContactId}`);
                    redisClient.sAdd(`ChannelName-${extensionOrig}@${domain}`, `${sipContactId}`);
                    redisClient.set(`deviceSIP-${deviceId}`, sipContactId);
                    const key = `sofia-${callId}-${extensionOrig}@${domain}`;
                    redisClient.set(key, agent);
                    redisClient.set(`deviceIdInfo-${deviceId}`, agent);

               }

               if(agent.includes('Webapp')){
                    const sipContact = contact?.split('sip:')[1].replace('>', '');
                    const sipContactId = sipContact?.split(';')[0];
                    redisClient.set(`contactSIP-${sipContactId}`, `${extensionOrig}@${domain}`);
                    redisClient.set(`SIP-${extensionOrig}@${domain}`, `${sipContactId}`);
                    redisClient.sAdd(`ChannelName-${extensionOrig}@${domain}`, `${sipContactId}`);
               }

               conn.api('sofia status reg', async (reg) => {
                    const entries = await storeGatewayStatus(reg.body);
                    await pushGatewayStatusChanges(entries);
               });

               // EMAN ADDED 10-07-25 START
               await new Promise(resolve => setTimeout(resolve, 1500));
               // check if numeric username
               if (/^[0-9]+$/.test(String(username))) {
                    conn.api('sofia profile internal flush_inbound_reg '+ callId);
                    db2.query(
                    "DELETE FROM registrations WHERE token = $1",
                    [callId],
                    (err) => {
                         if (err) {
                              console.error("Database error:", err);
                              return;
                         }
                    });
                    // BEARERCAPABILITY_NOTAUTH
               }    
               // EMAN ADDED 10-07-25 END

               const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, 0, -1);
               console.log("queuecallboard check list 3", `${queueCallBoardList}`);
               console.log('🚧 STUCK 🚧', queueCallBoardList);
               // queueCallBoardList.forEach((element, index) => {
               //      console.log('🚧 STUCK 🚧', element);
               // })

               // const findCallBoard = parseCallBoard.find(
               //      item => item.uuid === ev.getHeader('Channel-Call-UUID')
               // );
               // await redisClient.lRem(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, findIndexCallBoard, JSON.stringify(findCallBoard));
               
          }

     }

     if(ev.getHeader("Event-Subclass") == "sofia::unregister"){

          console.log(`✅️ UNREG`, 
               util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
          );

          conn.api(`show calls as json`, ({body}) => {
               //Kill Active CAlls
               const dataParse = JSON.parse(body);
               const {row_count, rows} = dataParse;
               if(row_count){
                    const presence = `${ev.getHeader('from-user')}@${ev.getHeader('from-host')}`;                    
                    for (const { uuid, name, b_name, presence_id, b_presence_id, b_state, b_callstate} of rows) {
                         console.log('✅️ CALL UNREG2', { uuid, name, b_name, b_state, b_callstate})
                         if(b_callstate === 'ACTIVE' && b_state === 'CS_EXCHANGE_MEDIA'){

                              // if(name.includes(presence) || b_name.includes(presence)){
                              //      conn.api(`uuid_kill ${uuid} NORMAL_CLEARING`);
                              // }else if(presence_id.includes(presence) || b_presence_id.includes(presence)){
                              //      conn.api(`uuid_kill ${uuid} NORMAL_CLEARING`)
                              // }

                         }
                    }
                    console.log('✅️ CALL UNREG', rows)
               }
              

          });

          const callId =  ev.getHeader('call-id');
          const alias = ev.getHeader('from-user');
          const domain = ev.getHeader("from-host");

          const extensionOrig = await extensionFunction(alias, domain);
          const keyCallId = `sofia-${callId}-${extensionOrig}@${domain}`;
          const presenceKeyCallId = await redisClient.get(keyCallId);
          const deviceId = presenceKeyCallId?.split(' - ')[1].trim();

          console.log(`BUG🔧`, presenceKeyCallId, extensionOrig, domain, deviceId);

          if(presenceKeyCallId?.includes('android')){

               await redisClient.sAdd(`deviceOff-${extensionOrig}@${domain}`, deviceId);
               await redisClient.set(`androidTokenActive-${extensionOrig}@${domain}:${deviceId}`, 1, { EX: 172800 });
               await redisClient.sRem(`deviceOn-${extensionOrig}@${domain}`, deviceId)
               const devicePresence = `androidPresence-${deviceId}-${extensionOrig}@${domain}`;
               redisClient.set(devicePresence, 'push', { EX: 7200 });
               redisClient.del(keyCallId);

          }

          const contactURL = await redisClient.get(`${deviceId}-contact`);
          console.log(`⛔`, contactURL);

          if(contactURL){

               const extContact = contactURL?.split(';')[0]?.split('sip:')[1];
               const sipContact = contactURL?.split('sip:')[1]?.replace('>', '');
               const sipContactId = sipContact?.split(';')[0];    
                               
               await redisClient.sRem(`ChannelName-${extensionOrig}@${domain}`, `${sipContactId}`);
               await redisClient.expire(`${extContact}-IOS-deviceId`, 300000);
               await redisClient.expire(`ChannelSipURI-${sipContactId}`, 10000);
               await redisClient.expire(`ConferenceData-${sipContactId}`, 10000);

          }

          const deviceToken =  await redisClient.get(`devicePushtoken-${deviceId}`);
          if(deviceToken && presenceKeyCallId?.includes('iOS')){                              
               if (deviceToken.length === 64) {

                    const presenceExtension = `${extensionOrig}@${domain}`;
                    console.log(`🌍`, deviceToken);
                    if(ev.getHeader('reason') && ev.getHeader('reason') === 'socket-disconnection'){
                         await redisClient.set(`iosFullyClose-${presenceExtension}-${deviceId}`, deviceToken, {EX:1800});
                    }

               }
          }
          await redisClient.del(`${callId}-deviceId`)

     }

     if (ev.getHeader("Event-Subclass") == "ai_ivr_recording2") {
          conn.api("uuid_audio_stream "+ ev.getHeader("Caller-uuid") + " start ws://127.0.0.1:7777 mono 16k " + ev.getHeader("Caller-uuid"));
          conn.api("uuid_park "+ ev.getHeader("Caller-uuid"));
     }

     if (ev.getHeader("Event-Subclass") == "ai_ivr_recording3") {
          conn.api("uuid_audio_stream "+ ev.getHeader("Caller-uuid") + " start ws://127.0.0.1:3333 mono 16k " + ev.getHeader("Caller-uuid"));
          conn.api("uuid_park "+ ev.getHeader("Caller-uuid"));
     }

     // console.log("this is handleCustom check");
     // console.log(`✅️ HANDLE CUSTOM`, 
     //           util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     // );
     if(ev.getHeader('Event-Subclass') == 'callcenter::info'){


          // console.log("this is handleCustom check 1");
          

          if(ev.getHeader('CC-Action') === 'bridge-agent-start'){
               

               console.log(`✅️ CALLCENTER::INFO`, 
                    util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
               );

               await redisClient.set(`queueActive${ev.getHeader('CC-Member-Session-UUID')}`, 1, {EX: 500});
               const [extensionQueue, extentionSubdomain] = ev.getHeader('CC-Queue').split('@');
               const agentName = ev.getHeader('CC-Agent');
               
               console.log(`🌡️🌡️`, { 
                    uuid: ev.getHeader('CC-Member-Session-UUID'), 
                    extension: extensionQueue, 
                    callerName: ev.getHeader('CC-Member-CID-Name'),
                    callerNumber: ev.getHeader('CC-Member-CID-Number'), 
                    channel: extentionSubdomain, 
                    command: "answered",
                    cc_agent: agentName,
                    call_center_queue_uuid: ev.getHeader('CC-Member-Session-UUID'),
                    cc_member_uuid: ev.getHeader('CC-Member-UUID'),
                    waitingSeconds: null,
                    answeredSeconds: null
               });
               
               socketClientQueue.emit("broadcast", { 
                    uuid: ev.getHeader('CC-Member-Session-UUID'), 
                    extension: extensionQueue, 
                    callerName: ev.getHeader('CC-Member-CID-Name'),
                    callerNumber: ev.getHeader('CC-Member-CID-Number'), 
                    channel: extentionSubdomain, 
                    command: "answered",
                    cc_agent: agentName,
                    call_center_queue_uuid: ev.getHeader('CC-Member-Session-UUID'),
                    cc_member_uuid: ev.getHeader('CC-Member-UUID'),
                    waitingSeconds: null,
                    answeredSeconds: null
               });

          }

          if(ev.getHeader('CC-Action') == 'agent-offering'){
          
               const dom = ev.getHeader('CC-Queue')?.split('@')[1]
               console.log(`🚚`, `queueKey:${ev.getHeader('CC-Member-Session-UUID')}:${dom}`, ev.getHeader('CC-Queue'))
               await redisClient.set(`queueKey:${ev.getHeader('CC-Member-Session-UUID')}:${dom}`, ev.getHeader('CC-Queue'));

               const [ queueExtension, subDomain] = ev.getHeader('CC-Queue')?.split('@');
               const queueList = await queueListQueuery(subDomain, queueExtension);
                console.log("queue here 3");
               const payload = {
                    domain: subDomain,
                    extension: queueExtension,
                    time: (Date.now() * 1000).toString(),
                    id: uuidv4(),
                    call: ev.getHeader("CC-Member-Session-UUID"),
                    caller_id: ev.getHeader('CC-Member-CID-Number'),
                    nameCaller: ev.getHeader('CC-Member-CID-Name'),
                    status: 'Queue',
                    time2: ev.getHeader("Event-Date-Timestamp")
               }
               const queueAgents = await new Promise((resolve, reject) => {
                    try {
                         conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ev.getHeader('CC-Queue')}"}}`, (res) => {
                              const { response } = JSON.parse(res.body);
                              resolve(response);
                         })   
                    } catch (error) {
                         reject(error);    
                    }
               });

               if (queueList[0].queue_strategy !== 'ring-all') {
                    const findAgent = queueAgents.filter(x => x.name == ev.getHeader('CC-Agent'));
                    pushQueueWeb(findAgent, payload)
                    // [apns-direct] random / longest-idle / round-robin / ... offer the call to one agent
                    // at a time; only ring-all used to push the mobile apps, so the agent's phone never rang
                    pushQueue(findAgent, payload);
                    pushQueueIOS(findAgent, payload);
               }

               conn.api(`uuid_dump ${ev.getHeader('CC-Member-Session-UUID')} JSON`, async (res) => {
                    if(res.body === '-ERR No such channel!\n') return
                    console.log(`✅️ CALLCENTER::INFO`, 
                         util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
                    );
                    const callDataQueue = JSON.parse(res.body);
                    const data = await new Promise((resolve, reject) => {
                         try {
                              conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ev.getHeader('CC-Queue')}"}}`, (res) => {
                                   const { response } = JSON.parse(res.body);
                                   resolve(response);
                              })   
                         } catch (error) {
                              reject(error);    
                         }
                    });
                    console.log('➙', callDataQueue, data);

                    //ISSUEE
                    const getQueueAnsweredUUID = await redisClient.get(`queueAnsweredCallboardUUID-${callDataQueue['variable_domain_name']}-${callDataQueue['variable_caller_destination']}`);

                    if(!getQueueAnsweredUUID){
                        redisClient.set(`queueAnsweredCallboardUUID-${callDataQueue['variable_domain_name']}-${callDataQueue['variable_caller_destination']}`,`${callDataQueue['Channel-Call-UUID']}`);
                         console.log("setting callboard answered",`queueAnsweredCallboardUUID-${callDataQueue['variable_domain_name']}-${callDataQueue['variable_caller_destination']}`, `${callDataQueue['Channel-Call-UUID']}` )
                    }
                    
                   
               });

          }

          if(ev.getHeader('Channel-Call-State') == 'ACTIVE'){
               
               if(ev.getHeader('CC-Action') == 'member-queue-start'){
                         
                         const queueAgents = await new Promise((resolve, reject) => {
                              try {
                                   conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ev.getHeader('CC-Queue')}"}}`, (res) => {
                                        const { response } = JSON.parse(res.body);
                                        resolve(response);
                                   })   
                              } catch (error) {
                                   reject(error);    
                              }
                         });

                         const [queueExtension, subdomain2] = ev.getHeader('CC-Queue')?.split('@');
                         const valid = isValidNumber(ev.getHeader('variable_caller_id_number'));
                         const timeFormatStart = Math.floor(Number(ev.getHeader('Caller-Channel-Answered-Time')) / 1_000_000);
                         const timeFormatAnswered =  Math.floor(Number(ev.getHeader('Caller-Channel-Answered-Time')) / 1_000_000);
                         const queueList = await queueListQueuery(ev.getHeader('variable_domain_name'), queueExtension);
                         
                         if(!valid){
                              console.log("queue here 0");
                              const response = await redisClient.get(`extension-${ev.getHeader('variable_caller_id_number')}@${ev.getHeader('variable_domain_name')}`);
                              if(response){
                                   const { extension } = JSON.parse(response);
                                   const payloadQueueCall = {
                                        queue: ev.getHeader('variable_cc_queue'),
                                        instance_id: ev.getHeader('FreeSWITCH-Hostname'),
                                        uuid: ev.getHeader('Channel-Call-UUID'),
                                        session_uuid: '',
                                        cid_number: extension,
                                        cid_name:  ev.getHeader('variable_caller_id_name'),
                                        system_epoch: timeFormatStart,
                                        joined_epoch: ev.getHeader('variable_cc_queue_joined_epoch'),
                                        rejoined_epoch: '0',
                                        bridge_epoch: timeFormatAnswered,
                                        abandoned_epoch: '0',
                                        base_score: '0',
                                        skill_score: '0',
                                        serving_agent: `${queueList[0].queue_strategy}`,
                                        serving_system: 'single_box',
                                        state: 'Trying',
                                        score: '0'
                                   }
                                    console.log("queuecallboard check 4", `queueCallBoard-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payloadQueueCall))
                                   redisClient.lPush(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payloadQueueCall))
                              }
                         }else{
                              console.log("queue here 01");

                              const payloadQueueCall = {
                                   queue: ev.getHeader('variable_cc_queue'),
                                   instance_id: ev.getHeader('FreeSWITCH-Hostname'),
                                   uuid: ev.getHeader('Channel-Call-UUID'),
                                   session_uuid: '',
                                   cid_number: ev.getHeader('variable_caller_id_number'),
                                   cid_name:  ev.getHeader('variable_caller_id_name'),
                                   system_epoch: timeFormatStart,
                                   joined_epoch: ev.getHeader('variable_cc_queue_joined_epoch'),
                                   rejoined_epoch: '0',
                                   bridge_epoch: timeFormatAnswered,
                                   abandoned_epoch: '0',
                                   base_score: '0',
                                   skill_score: '0',
                                   serving_agent: `${queueList[0].queue_strategy}`,
                                   serving_system: 'single_box',
                                   state: 'Trying',
                                   score: '0'
                              }


                              // redisClient.set(`queueCallTracking-${}`);


                              console.log("queuecallboard check 4.5", `queueCallBoard-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payloadQueueCall))
                              redisClient.lPush(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payloadQueueCall));
                         }

                    
                         switch (queueList[0].queue_strategy) {
                              case 'ring-all':

                                   const valid = isValidNumber(ev.getHeader('CC-Member-CID-Number'));
                                   console.log("queue here 1 && this is ev", ev);
                                   console.log("to be continue")


                                   if(!valid){
                                         console.log("queue here 5");
                                        const response = await redisClient.get(`extension-${ev.getHeader('CC-Member-CID-Number')}@${subdomain2}`);
                                        if(response){
                                             const { extension } = JSON.parse(response);
                                             const payload2 = {
                                                  domain: subdomain2,
                                                  time: (Date.now() * 1000).toString(),
                                                  extension: queueExtension,
                                                  id: uuidv4(),
                                                  call: ev.getHeader("Channel-Call-UUID"),
                                                  caller_id: extension,
                                                  nameCaller: ev.getHeader('CC-Member-CID-Name'),
                                                  time2: ev.getHeader("Event-Date-Timestamp")
                                             }
                                             pushQueueWeb(queueAgents, payload2);

                                             const payload = {
                                                  domain: subdomain2,
                                                  extension: queueExtension,
                                                  time: (Date.now() * 1000).toString(),
                                                  id: uuidv4(),
                                                  call: ev.getHeader("Channel-Call-UUID"),
                                                  caller_id: extension,
                                                  nameCaller: ev.getHeader('CC-Member-CID-Name'),
                                                  status: 'Queue',
                                             }
                                             pushQueue(queueAgents, payload);
                                             pushQueueIOS(queueAgents, payload);
                                        }
                                   }else{

                                        console.log("this is ev queue", ev, bd);

                                        console.log("queue here 4");
                                        const payload = {
                                             domain: subdomain2,
                                             extension: queueExtension,
                                             time: (Date.now() * 1000).toString(),
                                             id: uuidv4(),
                                             call: ev.getHeader("Channel-Call-UUID"),
                                             // call: "missed",
                                             caller_id: ev.getHeader('CC-Member-CID-Number'),
                                             nameCaller: ev.getHeader('CC-Member-CID-Name'),
                                             status: 'Queue',
                                        }
            
                                        const callUUID = ev.getHeader("Channel-Call-UUID");

                                        const trackKey =
                                        `queueCallTrack:${callUUID}@${subdomain2}`;

                                        const previousQueueExtensionData =
                                        await redisClient.get(trackKey);

                                        if (
                                        previousQueueExtensionData &&
                                        previousQueueExtensionData !== queueExtension
                                        ) {
                                        console.log(
                                             "Queue changed:",
                                             previousQueueExtensionData,
                                             "→",
                                             queueExtension
                                        );

                                        const getAgent = await redisClient.get(
                                             `queueAgentTrack:${payload.call}@${payload.domain}`
                                        );

                                        if (getAgent) {
                                             try {
                                                  const dataAgent = JSON.parse(getAgent);
                                                  const data = JSON.parse(previousQueueExtensionData)
                                                  const newPayload = {
                                                       ...payload,
                                                       call: "missed",
                                                       nameCaller: `(${data.extension})`,
                                                       extension: data.extension,
                                                       id: data.id,
                                                  };
                                                  
                                                  pushQueue(dataAgent, newPayload);
                                                  pushQueueIOS(dataAgent, newPayload);
                                                  pushQueueWeb(dataAgent, newPayload);
                                                  
                                             } catch (error) {
                                                  console.log(
                                                       "Invalid queueAgentTrack JSON:",
                                                       error
                                                  );
                                             }
                                        }
                                        }

                                        if (!previousQueueExtensionData) {
                                             console.log(
                                             "First queue extension:",
                                             queueExtension
                                             );
                                        }

                                        const data = {
                                             extension: queueExtension,
                                             id: payload.id
                                        }

                                        // Store current queue
                                        await redisClient.set(
                                        trackKey,
                                        JSON.stringify(data)
                                        );
                                 

                                        pushQueue(queueAgents, payload);
                                        pushQueueIOS(queueAgents, payload);
                                        pushQueueWeb(queueAgents, payload);

                                   }
                                   
                                   break;
                                                                                
                              default:
                                   break;
                         }

                         console.log(`This is called AAA`, queueAgents)
                         
                         

                         socketClientQueue.emit("broadcast", {
                              'uuid': ev.getHeader('Channel-Call-UUID'), 
                              'extension': ev.getHeader('Caller-Destination-Number'), 
                              'callerName': ev.getHeader('Caller-Orig-Caller-ID-Name'),
                              'callerNumber': ev.getHeader('Caller-Orig-Caller-ID-Number'), 
                              'channel': ev.getHeader('variable_domain_name'), 
                              'command': 'waiting',
                              'cc_agent': ev.getHeader('variable_cc_agent') ? ev.getHeader('variable_cc_agent') : null
                         });

               }





               if(
                    ev.getHeader('CC-Action') == 'member-queue-end' && 
                    ev.getHeader('Caller-Destination-Number') !== 'bridge_to_agent'
               ){
                          console.log("this is handleCustom check 2");
                         const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, 0, -1);
                         console.log("queuecallboard check 4.6", `${queueCallBoardList}`);
                         const parseCallBoard = queueCallBoardList.map(el => JSON.parse(el));

                         const findCallBoard = parseCallBoard.find(item => item.uuid === ev.getHeader('Channel-Call-UUID') && item.state !== 'Answered');
                         if(findCallBoard){
                               console.log("this is handleCustom check 3");
                              const findIndexCallBoard = parseCallBoard.findIndex(item => item.uuid  === ev.getHeader('Channel-Call-UUID') && item.state !== 'Answered');                             
                              let preventDuplicate = '';
                              switch (ev.getHeader('CC-Cancel-Reason')) {
                                   case 'TIMEOUT':
                                        if (preventDuplicate != ev.getHeader('Channel-Call-UUID')) {

                                             const avgWaitingTotal = Number(ev.getHeader('CC-Member-Leaving-Time')) - Number(ev.getHeader('CC-Member-Joined-Time'));
                                             const redisKeyWaiting = `avgWaiting-${ev.getHeader('variable_cc_queue')}`;
                                             const avgWaitingCurrentValue = await redisClient.get(redisKeyWaiting);

                                             if (avgWaitingCurrentValue) {
                                                  const avgWaitingNewCurrentValue = avgWaitingTotal + Number(avgWaitingCurrentValue);
                                                  await redisClient.set(redisKeyWaiting, avgWaitingNewCurrentValue);
                                             } else {
                                                  await redisClient.set(redisKeyWaiting, avgWaitingTotal);
                                             }

                                             const queueExtension = ev.getHeader('CC-Queue')?.split('@')[0];
                                             await redisClient.lRem(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, findIndexCallBoard, JSON.stringify(findCallBoard));
                                             const waitingSeconds = Number(ev.getHeader('CC-Member-Leaving-Time')) - Number(ev.getHeader('CC-Member-Joined-Time'))

                                             socketClientQueue.emit("broadcast", { 
                                                  'uuid': ev.getHeader('Channel-Call-UUID'), 
                                                  'extension': queueExtension, 
                                                  'callerName': ev.getHeader('Caller-Orig-Caller-ID-Name'),
                                                  'callerNumber': ev.getHeader('Caller-Orig-Caller-ID-Number'), 
                                                  'channel': ev.getHeader('variable_domain_name'), 
                                                  'command': "NO_ANSWER",
                                                  'cc_agent': ev.getHeader('variable_cc_agent') ? ev.getHeader('variable_cc_agent') : null,
                                                  'waitingSeconds': waitingSeconds,
                                                  'answeredSeconds': null,
                                                  answeredSeconds: null,
                                                  lastBridgeStart: null,
                                                  lastBridgeEnd: null,
                                                  lastOfferedCall: ev.getHeader('CC-Member-Leaving-Time')
                                             });
                                             preventDuplicate = ev.getHeader('Channel-Call-UUID');


                                             // const uniqueId =  await redisClient.get(`noEvent-${ev.getHeader('variable_cc_member_session_uuid')}@${ev.getHeader('variable_dialed_domain')}`);
                                             
                                              console.log("this is handleCustom check 4");
                                             if(ev.getHeader('CC-Member-UUID')){
                                                   console.log("this is handleCustom check 5");
                                                  console.log('🫠🫠🫠');
                                                  /** QUEUE MISSED **/

                                                  const timestampCreated = ev.getHeader('Caller-Profile-Created-Time');
                                                  const converted = Math.floor(Number(timestampCreated) / 1000000);
                                                  const value = await fsRegistration();

                                                  const extensionId = async (ext) => {
                                                       const findCurrentPresence = value.find(x => regMatch(x, ext));
                                                       if(findCurrentPresence){
                                                            const { "Auth-User": authUser , "Auth-Realm": authRealm} = findCurrentPresence;
                                                            const { rows } = await db2.query(
                                                            "SELECT extension_uuid, extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                                                            [authUser, authRealm]
                                                            );
                                                            return rows.length ? 
                                                            {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUID: rows[0].extension_uuid} 
                                                            : {cid_name: ext, cid_num: ext, extensionUID: null};
                                                       }else{
                                                            const { rows } = await db2.query(
                                                            "SELECT extension_uuid, extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                                                            [ext, ev.getHeader('variable_domain_name')]
                                                            );
                                                            return rows.length ? 
                                                            {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUID: rows[0].extension_uuid} 
                                                            : {cid_name: ext, cid_num: ext, extensionUID: null};
                                                       }
                                                  
                                                  }
                                                  
                                                  const channelExactName = await presenceId(ev.getHeader('Channel-Name'));
                                                  const callerExtension = await extensionId(channelExactName);
                                                  const {cid_name, cid_num, extensionUID} = callerExtension;
                                                  const [queueExt, queueSubdomain] = ev.getHeader('variable_cc_queue').split('@');
                                                  const durationQueue = (Number(ev.getHeader('CC-Member-Leaving-Time')) - Number(ev.getHeader('CC-Member-Joined-Time'))).toString()

                                                  if(cid_num && queueExt){
                                                        console.log("this is handleCustom check 6");

                                                       const payload = {
                                                            uuid: ev.getHeader('CC-Member-UUID'),
                                                            direction: ev.getHeader('Caller-Direction'),
                                                            created: ev.getHeader('variable_start_stamp'),
                                                            created_epoch: converted,
                                                            name: ev.getHeader('Caller-Channel-Name'),
                                                            state: 'CS_EXECUTE',
                                                            cid_name: cid_name,
                                                            cid_num: cid_num,
                                                            ip_addr: ev.getHeader('Caller-Network-Addr'),
                                                            dest: queueExt,
                                                            presence_id: ev.getHeader('variable_presence_id'),
                                                            presence_data: '',
                                                            accountcode: ev.getHeader('variable_dialed_domain'),
                                                            callstate: '',
                                                            callee_name: '',
                                                            callee_num: '',
                                                            callee_direction: '',
                                                            call_uuid: '',
                                                            hostname: '',
                                                            sent_callee_name: '',
                                                            sent_callee_num: '',
                                                            b_uuid: '',
                                                            b_direction: '',
                                                            b_created: '',
                                                            b_created_epoch: '',
                                                            b_name: '',
                                                            b_state: '',
                                                            b_cid_name: '',
                                                            b_cid_num: '',
                                                            b_ip_addr: '',
                                                            b_dest: '',
                                                            b_presence_id: '',
                                                            b_presence_data: '',
                                                            b_accountcode: '',
                                                            b_callstate: '',
                                                            b_callee_name: '',
                                                            b_callee_num: '',
                                                            b_callee_direction: '',
                                                            b_sent_callee_name: '',
                                                            b_sent_callee_num: '',
                                                            call_created_epoch: '',
                                                            caller_id: '',
                                                            duration: durationQueue,
                                                            hangUpCause: ev.getHeader('CC-Cancel-Reason'),
                                                            status: 'Queue Missed',
                                                            gateway: ev.getHeader('variable_sip_gateway_name') || '',
                                                            rule: ev.getHeader('variable_rule') || ''
                                                       }
                                                       console.log(`🏝️🏝️🏝️`, ev.getHeader('CC-Cancel-Reason'), payload);
                                                       console.log("baka dito handle custom lastactive1", `lastActiveCall-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payload))
                                                       const redisKey = `lastActiveCall-${ev.getHeader('variable_domain_name')}`;
                                                       await enrichCallRow(payload); // [call-journey]
                                                       await redisClient.lPush(redisKey, JSON.stringify(payload));
                                                       await redisClient.lTrim(redisKey, 0, 49); // [call-journey] keep last 50
                                                       await new Promise(resolve => setTimeout(resolve, 1000));
                                                       showcallsEventRefresh(ev.getHeader('variable_domain_name'));
                                                       socketClient.emit("lastActive", ev.getHeader('variable_domain_name'));
                                                       const state = 'normal'
                                                       const hangupCause = ev.getHeader('CC-Cancel-Reason')
                                                       const queueId = ev.getHeader('variable_call_center_queue_uuid')
                                                       callLogsTimeoutQueue(ev, cid_name, cid_num, queueExt, state, 'Queue Missed', extensionUID, hangupCause, durationQueue, queueId);
                                                       
                                                       // Added 08-17-26
                                                       // Missed calls on queue push notification retrieve
                                                       const retriveMissedQueueData = await redisClient.get(`retriveMissedQueueData-${payload.cid_num}`);
                                                       const retriveMissedQueueAgentlist = await redisClient.get(`retriveMissedQueueAgentlist-${payload.cid_num}`);
                                                       console.log("this is retriveMissedQueueData", `retriveMissedQueueData-${payload.cid_num}`, `retriveMissedQueueAgentlist-${payload.cid_num}`)
                                                       if(retriveMissedQueueData && retriveMissedQueueAgentlist){

                                                             console.log("this is handleCustom check 7");
                                                            console.log("has data and retriveMissedQueueData payload use only for missed queue signle", payload, retriveMissedQueueAgentlist)
                                                            const payload2 = JSON.parse(retriveMissedQueueData);
                                                            console.log("this is payload 2", payload2);
                                                            payload2.call_uuid = payload2.call;
                                                            payload2.call = "missed";
                                                            const queueAgents = JSON.parse(retriveMissedQueueAgentlist);
                                                            
                                                            pushQueue(queueAgents, payload2);

                                                            // socketClient2.emit('push-queue', payload2);
                                                       }
                                                    

                                                       //// Added 08-17-26
                                                       // redisClient.set(`setForMissed-${}`, );
                                                  }

                                             }

                                        }
                                        break;
                                   case 'BREAK_OUT':

                                        if(ev.getHeader('CC-Cause') == 'Cancel' && ev.getHeader('Answer-State') === 'hangup'){
                                             await redisClient.lRem(`queueCallBoard-${ev.getHeader('variable_domain_name')}`, findIndexCallBoard, JSON.stringify(findCallBoard));
                                             console.log(`queuecallboard check list 4.7 queueCallBoard-${ev.getHeader('variable_domain_name')}`, findIndexCallBoard, JSON.stringify(findCallBoard))
                                        }
                                        break

                                   default:
                                        break;
                              }

                         }
               }
               
          }

     }







     if(ev.getHeader('Event-Subclass') == 'avmd::beep'){
          console.log(`✅️ BEEP`);
          // conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/endCallBeep.wav`);
          // setTimeout(()=>{
          //      conn.api(`uuid_kill ${ev.getHeader('Unique-ID')}`);
          // }, 1000);
     }

     if(ev.getHeader('Event-Subclass') == 'menu::enter'){
          if(ev.getHeader('variable_current_application') === 'ivr'){
               const { rows } = await db2.query(
                    "SELECT ivr_menu_extension , ivr_menu_timeout, ivr_menu_exit_app, ivr_menu_exit_data FROM v_ivr_menus WHERE ivr_menu_uuid = $1",
                    [ev.getHeader('variable_ivr_menu_uuid')]
               );
               if(rows.length){
                    console.log("this is rows", rows);
                    const {
                    ivr_menu_extension,
                    ivr_menu_timeout,
                    ivr_menu_exit_app,
                    ivr_menu_exit_data
                    } = rows[0];

                    console.log("this are :", ivr_menu_extension, ivr_menu_timeout, ivr_menu_exit_app, ivr_menu_exit_data)
                    if(!(ivr_menu_extension >= 6000 &&  ivr_menu_extension < 7000)) return;
                    console.log("correct")
                    const timeoutIvr =  Number(ivr_menu_timeout) / 1000;
                    const destinationIvr = ivr_menu_exit_app == 'hangup' ? ivr_menu_exit_app : ivr_menu_exit_data
                    // Added 08-25-26
                    redisClient.set(`ivrTimeoutTimer-${ivr_menu_extension}`, timeoutIvr);
                    //// Added 08-25-26
                    conn.api(`sched_transfer +${timeoutIvr} ${ev.getHeader('Caller-Unique-ID')} ${destinationIvr}`);


               }
               //  ivr_menu_extension: '6000',
               // 29|server  |     ivr_menu_timeout: '30000',
               // 29|server  |     ivr_menu_exit_app: 'hangup',
               // 29|server  |     ivr_menu_exit_data: ''
               // redisClient.set(`ivrTimeoutTimer-${}`)
               console.log('IVR OPTIONS', rows);
          }
     }

}

const handleChanneBridge = async (ev, bd, conn) => {
     conn.api(`uuid_break ${ev.getHeader('Channel-Call-UUID')} all`)




     console.log(`✅️ BRIDGE`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );

     if(ev.getHeader('Call-Direction') === 'inbound' && ev.getHeader('variable_call_method') === 'directdial') {
          conn.api(`uuid_setvar ${ev.getHeader('Bridge-B-Unique-ID')} call_method ${ev.getHeader('variable_call_method')}`);
     }

     if(ev.getHeader('variable_call_method') === 'directdial' && ev.getHeader('variable_agent_first')){

          conn.api(`uuid_broadcast ${ev.getHeader('Bridge-B-Unique-ID')} ${mohStream(ev.getHeader('variable_domain_name'))} aleg`);
          conn.bgapi(`originate {sip_from_uri=${ev.getHeader('variable_sip_from_uri')},sip_cid_type=rpid,origination_caller_id_name=${ev.getHeader('variable_original_caller_id_name')},origination_caller_id_number=${ev.getHeader('variable_original_caller_id_number')},origination_privacy=screen+hide_name+hide_number,ignore_early_media=true,call_timeout=30,hangup_after_bridge=true,domain_uuid=${ev.getHeader('variable_domain_uuid')},domain_name=${ev.getHeader('variable_domain_name')}}sofia/gateway/${ev.getHeader('variable_gateway')}/${ev.getHeader('variable_outbound_destination')} &intercept(${ev.getHeader('Bridge-B-Unique-ID')})`);
          setTimeout(()=> {
               conn.api(`uuid_dump ${ev.getHeader('Bridge-B-Unique-ID')} JSON`, async (res) => {
                    if(res.body === '-ERR No such channel!\n') return
                    const dataJSON = JSON.parse(res.body);
                    console.log(`DIRECT DIAL B-DUMP CALL`, dataJSON);
               });
          }, 2000);

     }
     //variable_cc_queue
     if(ev.getHeader('variable_cc_agent_push') && ev.getHeader('variable_cc_queue')){
          console.log("must have then qq", ev.getHeader('variable_cc_agent_push') , ev.getHeader('variable_cc_queue'))

          conn.api(`callcenter_config agent set state ${ev.getHeader('variable_cc_agent_push')} Waiting`);
          
          redisClient.set(`answeredCCAgent:${ev.getHeader('variable_cc_queue')}`, ev.getHeader('variable_cc_agent_push'))
          console.log("answeredCC", `answeredCCAgent:${ev.getHeader('variable_cc_queue')}`, ev.getHeader('variable_cc_agent_push'))
     }

     if(ev.getHeader('variable_blindFrom')){
          conn.api(`uuid_setvar ${ev.getHeader('Bridge-B-Unique-ID')} blindFrom ${ev.getHeader('variable_blindFrom')}`);
     }

     if(ev.getHeader('variable_attendedFrom')){
          conn.api(`uuid_setvar ${ev.getHeader('Bridge-B-Unique-ID')} attendedFrom ${ev.getHeader('variable_attendedFrom')}`);
     }
     
     if(ev.getHeader('Bridge-B-Unique-ID')){ 
          conn.api(`uuid_dump ${ev.getHeader('Bridge-B-Unique-ID')} JSON`, async (res) => {

               if(res.body === '-ERR No such channel!\n') return
               const dataJSON = JSON.parse(res.body);
               console.log(`B-DUMP CALL`, dataJSON);
               const lastCallee =  ev.getHeader('variable_last_sent_callee_id_number') || dataJSON['variable_last_sent_callee_id_number'];

               if(
                    (
                    ev.getHeader('variable_cc_queue') ||
                    dataJSON["variable_cc_queue"] || 
                    classifyNumber(Number(dataJSON["Caller-RDNIS"])) === 'Queue') &&
                    !ev.getHeader('variable_callFeatures')
               ){

                    const attendedActive =  await redisClient.exists(`attendedActive-${ev.getHeader('variable_last_bridge_to')}`);
                    if(attendedActive) return;
                    const extUsername = await redisClient.get(`ChannelSipURI-${dataJSON["variable_sip_to_uri"]}`);
                    const agentName = async (extUsername) => {
                         const { rows } = await db2.query(
                              `SELECT name FROM agent_details WHERE contact LIKE $1`,
                              [`%${extUsername}@${dataJSON["variable_domain_name"]}%`]
                         );
                         const agentName = rows[0]?.name;
                         return agentName;
                    }

                    const queueId = async (agentQueueName) => {
                         const { rows } = await db2.query(
                              `SELECT call_center_queue_uuid FROM v_call_center_tiers WHERE call_center_agent_uuid = $1`,
                              [agentQueueName]
                         );
                         const queueId = rows[0]?.call_center_queue_uuid;
                         return queueId;
                    }

                    const agentQueueName = dataJSON['variable_cc_agent'] || await agentName(extUsername);
                    const callcenterQueueId = await queueId(agentQueueName);
                    console.log(`✏️`, extUsername, agentQueueName, callcenterQueueId);
                    if(agentQueueName){

                         const queuePresence =  ev.getHeader('variable_cc_queue') 
                            || dataJSON["variable_cc_queue"] 
                            || (dataJSON["Caller-RDNIS"] && dataJSON["variable_domain_name"]
                            ? `${dataJSON["Caller-RDNIS"]}@${dataJSON["variable_domain_name"]}`
                            : null);

                         const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${dataJSON['variable_domain_name']}`, 0, -1);
                         console.log("queuecallboard check 5", `${queueCallBoardList}`)
                         const parseCallBoard = queueCallBoardList.map(el => JSON.parse(el)).filter(Boolean);
                         const findCallBoard = parseCallBoard.find(item => item.uuid === dataJSON['Channel-Call-UUID']);
                         if (findCallBoard) {

                              console.log("This is test", )
                              const servingAgent = dataJSON['variable_cc_agent'] || ev.getHeader('variable_cc_agent_push')
                              const findCallBoardAnswered = {...findCallBoard, 'serving_agent': servingAgent, 'state': 'Answered'}
                              const findIndexCallBoard = parseCallBoard.findIndex(item => item.uuid === dataJSON['Channel-Call-UUID']);
                              console.log("has callboard 1", `queueCallBoard-${dataJSON['variable_domain_name']}`, findIndexCallBoard, JSON.stringify(findCallBoardAnswered))
                              await redisClient.lSet(`queueCallBoard-${dataJSON['variable_domain_name']}`, findIndexCallBoard, JSON.stringify(findCallBoardAnswered));
                         }

                         const [queueExtension, subdomain2] = queuePresence?.split('@');
                         const queueList = await queueListQueuery(dataJSON['variable_domain_name'], queueExtension);
                         const queueList2 = await queueListQueuery2(dataJSON['variable_domain_name']);

                         const [allQueueData, allQueueData2] = await Promise.all([
                              await Promise.all(
                                queueList.map((x) => {
                                    const queueExtension2 = x.queue_extension;
                                    const command = `json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${queueExtension2}@${dataJSON['variable_domain_name']}"}}`;
                                    return bgapiPromise(command);
                                })
                              ),
                              await Promise.all(
                                queueList2.map((x) => {
                                    const queueExtension2 = x.queue_extension;
                                    const command = `json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${queueExtension2}@${dataJSON['variable_domain_name']}"}}`;
                                    return bgapiPromise(command);
                                })
                              )
                         ]);

                         const receivingCall = allQueueData.flatMap(group =>
                              group
                              .filter(agent => agent.state === `In a queue call`)
                              .map(agent => agent.contact?.split('user/')[1])
                         );

                         if(receivingCall.length){
                              const state = ['Idle', 'In a queue call']
                              const namesWaiting = receivingCall.map(presence => {
                                   const names = allQueueData2.flatMap(group =>
                                        group
                                        .filter(agent => agent.contact.includes(presence) && !state.includes(agent.state))
                                        .map(agent => agent.name)
                                   );
                                   return names
                              });
                              setAgentsToWaiting2(namesWaiting);
                         }
                         
                         const timeStampStart = dataJSON['Event-Date-Timestamp'];
                         const seconds = Math.floor(parseInt(timeStampStart) / 1000000);
                         const agentName =  dataJSON['variable_cc_agent'] || ev.getHeader('variable_cc_agent_push')|| agentQueueName || null;
                         db2.query(`UPDATE agent_details SET last_bridge_start = $1 WHERE name = $2`, [seconds, agentName], (err) => {
                            if(err) console.error(err);
                         });


                         //Changed 09-22-26
                         if(ev.getHeader('variable_cc_agent_push')){

                               if(ev.getHeader('variable_last_bridge_to')){
                                   return
                              }
                              //variable_caller_id_name:
                              redisClient.set(`queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`, `${ev.getHeader('variable_cc_agent_push')}`);
                              console.log("setting this queue", `queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`, `${ev.getHeader('variable_cc_agent_push')}`)
                              // if(!ev.getHeader('variable_last_bridge_to')){
                                   //For the meantime
                                    conn.api(`callcenter_config agent set state ${ev.getHeader('variable_cc_agent_push')} 'In a queue call'`);
                              // }

                           
                              const queueAgents = await new Promise((resolve, reject) => {
                                   try {
                                        conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${queuePresence}"}}`, (res) => {
                                             const { response } = JSON.parse(res.body);
                                             resolve(response);
                                        })   
                                   } catch (error) {
                                        reject(error);    
                                   }
                              });
                              const agentToWaiting = queueAgents.map((x) => {
                                   if(x.state === 'Waiting'){
                                        return x.name
                                   }
                              })
                              if(agentToWaiting){

                                   await db2.query(`UPDATE agent_details SET state = $1 WHERE name = ANY($2)`, ['Waiting', agentToWaiting]);
                                   
                                   console.log(`🌡️`, { 
                                        uuid: dataJSON['Channel-Call-UUID'], 
                                        extension: queueExtension, 
                                        callerName: dataJSON['Caller-Orig-Caller-ID-Name'],
                                        callerNumber: dataJSON['Caller-Orig-Caller-ID-Number'], 
                                        channel: dataJSON['variable_domain_name'], 
                                        command: "answered",
                                        cc_agent: agentName,
                                        call_center_queue_uuid: dataJSON['Channel-Call-UUID'],
                                        waitingSeconds: null,
                                        answeredSeconds: null,
                                        member_uuid: ev.getHeader('variable_cc_member_uuid'),
                                        cc_member_uuid: ev.getHeader('variable_cc_member_uuid')
                                   });
                                   
                                   // Added 08-17-26
                                   const addedQueue = `${queueExtension}@${dataJSON['variable_domain_name']}`
                                   redisClient.set(`queueCallBoardAnsweredAgent-${addedQueue}`, ev.getHeader('variable_cc_member_uuid'));
                                   redisClient.set(`queueCallBoardAnsweredCall-${addedQueue}`, agentName);
                                   //// Added 08-17-26 
                                   
                                   console.log({
                                        "uuid": `${ev.getHeader('variable_cc_member_uuid')}`,
                                        "serving_agent": `${agentName}`,
                                        "serving_system":"single_box",
                                        "state":"Answered",
                                   });

                                   socketClientQueue.emit("broadcast", { 
                                        uuid: dataJSON['Channel-Call-UUID'], 
                                        extension: queueExtension, 
                                        callerName: dataJSON['Caller-Orig-Caller-ID-Name'],
                                        callerNumber: dataJSON['Caller-Orig-Caller-ID-Number'], 
                                        channel: dataJSON['variable_domain_name'], 
                                        command: "answered",
                                        cc_agent: agentName,
                                        call_center_queue_uuid: dataJSON['Channel-Call-UUID'],
                                        waitingSeconds: null,
                                        answeredSeconds: null,
                                        cc_member_uuid: ev.getHeader('variable_cc_member_uuid')
                                   });

                                   callIdQueue(
                                        ev.getHeader("Channel-Call-UUID"), 
                                        dataJSON['Channel-Name'],
                                        dataJSON['variable_domain_name']
                                   );
                                   await redisClient.sAdd(`activeQueue-${dataJSON['variable_domain_name']}`, dataJSON['Channel-Call-UUID']);
                              }

                         }
                         //Add 09-22-26
                         if(!ev.getHeader('variable_cc_agent_push') && ev.getHeader('variable_agent_alias') && ev.getHeader('variable_cc_queue')){
                              console.log("works only on ios");

                              // if(ev.getHeader('variable_last_bridge_to')){
                              //      return
                              // }

                              const queue = ev.getHeader('variable_cc_queue');
                              const agentPush = ev.getHeader('variable_agent_alias');



                              conn.api(
                              `callcenter_config queue list agents ${queue}`,
                              (res) => {

                                   const agentsString =
                                        res?.body ||
                                        res?._body ||
                                        '';

                                   console.log("QUEUE AGENTS:", agentsString);

                                   const matchedRow = agentsString
                                        .split('\n')
                                        .map(row => row.trim())
                                        .find(row => {
                                             if (!row.includes('|')) {
                                                  return false;
                                             }
                                             const columns = row.split('|');
                                             const contact = columns[4] || '';

                                             return contact.includes(
                                                  `user/${agentPush}@`
                                             );
                                        });

                                   if (!matchedRow) {
                                        console.log(
                                             `No callcenter agent found for alias: ${agentPush}`
                                        );
                                        return;
                                   }
                                   const agentName = matchedRow.split('|')[0];

                                   console.log("Matched agent:", agentName);

                                   conn.api(
                                        `callcenter_config agent set state ${agentName} 'In a queue call'`,
                                        (result) => {
                                             console.log(
                                                  "Set state result:",
                                                  result?.body || result?._body
                                             );
                                        }
                                   );

                              }
                              );

                              const queueAgents = await new Promise((resolve, reject) => {
                                   try {
                                        conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${queuePresence}"}}`, (res) => {
                                             const { response } = JSON.parse(res.body);
                                             resolve(response);
                                        })   
                                   } catch (error) {
                                        reject(error);    
                                   }
                              });
                              const agentToWaiting = queueAgents.map((x) => {
                                   if(x.state === 'Waiting'){
                                        return x.name
                                   }
                              })
                              if(agentToWaiting){

                                   await db2.query(`UPDATE agent_details SET state = $1 WHERE name = ANY($2)`, ['Waiting', agentToWaiting]);
                                   
                                   console.log(`🌡️`, { 
                                        uuid: dataJSON['Channel-Call-UUID'], 
                                        extension: queueExtension, 
                                        callerName: dataJSON['Caller-Orig-Caller-ID-Name'],
                                        callerNumber: dataJSON['Caller-Orig-Caller-ID-Number'], 
                                        channel: dataJSON['variable_domain_name'], 
                                        command: "answered",
                                        cc_agent: agentName,
                                        call_center_queue_uuid: dataJSON['Channel-Call-UUID'],
                                        waitingSeconds: null,
                                        answeredSeconds: null,
                                        member_uuid: ev.getHeader('variable_cc_member_uuid'),
                                        cc_member_uuid: ev.getHeader('variable_cc_member_uuid')
                                   });
                                   
                                   // Added 08-17-26
                                   const addedQueue = `${queueExtension}@${dataJSON['variable_domain_name']}`
                                   redisClient.set(`queueCallBoardAnsweredAgent-${addedQueue}`, ev.getHeader('variable_cc_member_uuid'));
                                   redisClient.set(`queueCallBoardAnsweredCall-${addedQueue}`, agentName);
                                   //// Added 08-17-26 
                                   
                                   console.log({
                                        "uuid": `${ev.getHeader('variable_cc_member_uuid')}`,
                                        "serving_agent": `${agentName}`,
                                        "serving_system":"single_box",
                                        "state":"Answered",
                                   });

                                   socketClientQueue.emit("broadcast", { 
                                        uuid: dataJSON['Channel-Call-UUID'], 
                                        extension: queueExtension, 
                                        callerName: dataJSON['Caller-Orig-Caller-ID-Name'],
                                        callerNumber: dataJSON['Caller-Orig-Caller-ID-Number'], 
                                        channel: dataJSON['variable_domain_name'], 
                                        command: "answered",
                                        cc_agent: agentName,
                                        call_center_queue_uuid: dataJSON['Channel-Call-UUID'],
                                        waitingSeconds: null,
                                        answeredSeconds: null,
                                        cc_member_uuid: ev.getHeader('variable_cc_member_uuid')
                                   });

                                   callIdQueue(
                                        ev.getHeader("Channel-Call-UUID"), 
                                        dataJSON['Channel-Name'],
                                        dataJSON['variable_domain_name']
                                   );
                                   await redisClient.sAdd(`activeQueue-${dataJSON['variable_domain_name']}`, dataJSON['Channel-Call-UUID']);
                              }


                         }
                         ////Add 09-22-26

                    }

               }

               if(dataJSON['Channel-Name'].includes('internal')){

                    const channelExactName = await presenceId(dataJSON['Channel-Name']);
                    if(channelExactName){
                         const subDomain = dataJSON['variable_domain_name'];
                         const uniqueId = dataJSON['Unique-ID']
                         const { rows } = await db2.query(
                         `
                         SELECT EXISTS (
                         SELECT 1
                         FROM v_extensions
                         WHERE number_alias = $1
                              AND accountcode = $2
                         ) AS exists
                         `,
                         [channelExactName, subDomain]
                         );
                         const exists = rows[0].exists;
                         if (exists) {
                              await redisClient.set(`${dataJSON['variable_domain_name']}:incall:${channelExactName}`, uniqueId);
                              return;
                         }
                         const channelAlias = dataJSON['Channel-Presence-ID'].split('@')[0];
                         await redisClient.set(`${dataJSON['variable_domain_name']}:incall:${channelAlias}`, uniqueId);
                    }        

               }

          });
     }

     if(ev.getHeader('Call-Direction') === 'inbound' && ev.getHeader('Bridge-B-Unique-ID')){
          conn.api(`uuid_dump ${ev.getHeader('Bridge-B-Unique-ID')} JSON`, async (res) => {

               if(res.body === '-ERR No such channel!\n') return
               const dataParse = JSON.parse(res.body);
               if(dataParse['Channel-Name'] && dataParse['Channel-Name'].includes('internal')){

                    const sipPresence = await sipProfileURL(dataParse['Channel-Name']); 
                    const deviceId = await redisClient.get(`${sipPresence}-IOS-deviceId`);
                    if(deviceId){
                         const devicePushToken = await redisClient.get(`devicePushtoken-${deviceId}`);
                         if(devicePushToken){

                              const channelPresence = dataParse['Channel-Presence-ID']?.split('@')[0];
                              const extensionFunction = async (alias) => {
                                   const checkValid = isValidNumber(alias)
                                   if(!checkValid){
                                        const { rows } = await db2.query(
                                             `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                             [alias, dataParse['variable_domain_name']]
                                        );
                                        if(rows.length){
                                             return rows[0].extension;
                                        }
                                   }
                                   return alias
                              }
                              const extensionOrig = await extensionFunction(channelPresence);

                              console.log('This is active CALL ACTIVE CALL 🔥');
                              await redisClient.sAdd(`activeCall-${extensionOrig}@${dataParse['variable_domain_name']}`, devicePushToken);
                         }
                    }

               }
               if(ev.getHeader('variable_cc_queue')){

                    /** Agent List */
                    await new Promise((resolve, reject) => {
                         try {
                              conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ev.getHeader('CC-Queue')}"}}`, (res) => {
                                   const { response } = JSON.parse(res.body);
                                   resolve(response);
                              })   
                         } catch (error) {
                              reject(error);    
                         }
                    });
               
               }

          });
     }

     if(ev.getHeader('variable_cc_queue')){


          conn.api(`uuid_dump ${ev.getHeader('Bridge-B-Unique-ID')} JSON`, async (res) => {

               if(res.body === '-ERR No such channel!\n') return
               const dataParse = JSON.parse(res.body);
               console.log(`👑+`, dataParse);

               if(dataParse['Channel-Name'] && dataParse['Channel-Name'].includes('internal')){
                    const bPresence = await presenceId(dataParse['Channel-Name']);
                    if(!bPresence) return;
                    const domain = ev.getHeader('variable_domain_name')
                    const agentAlias = ev.getHeader('variable_agent_alias') || await getAlias(bPresence, domain);
                    const queueAgents = await new Promise((resolve, reject) => {
                         try {
                              conn.bgapi(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ev.getHeader('variable_cc_queue')}"}}`, (res) => {
                                   const { response } = JSON.parse(res.body);
                                   resolve(response);
                              })   
                         } catch (error) {
                              reject(error);    
                         }
                    });

                    const extensionNumberAgent = async (alias) => {
                         const { rows } = await db2.query(
                              `SELECT extension FROM v_extensions WHERE number_alias = $1 AND domain_uuid = $2`,
                              [alias, ev.getHeader('variable_domain_uuid')]
                         );
                         if(rows.length){
                              return rows[0].extension;
                         }
                    }

                    console.log("👑👑👑👑", util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true }))

                    const agentOrigExtension = await extensionNumberAgent(agentAlias)
                    const payload = {
                         domain: domain,
                         extension: ev.getHeader('variable_queue_extension'),
                         time: (Date.now() * 1000).toString(),
                         id: uuidv4(),
                         call: 'answered',
                         call2: 'answered',
                         caller_id: ev.getHeader('variable_caller_id_number'),
                         nameCaller: ev.getHeader('variable_caller_id_name'),
                         status: 'QueueBridge',
                    }

                    console.log(`👑`,payload);
                    pushQueue(queueAgents, payload, agentOrigExtension);

                    // ADDED EMAN 08-27-26 START
                    // pushQueueIOS(queueAgents, payload, agentOrigExtension);
                    pushQueueIOS(queueAgents, payload, agentOrigExtension);
                    // ADDED EMAN 08-27-26 END

               }
          })
     }

     const origValidExtension = async () => {

          const aliasNamePresence = ev.getHeader('variable_dialed_user') ? ev.getHeader('variable_dialed_user') : ev.getHeader('Channel-Presence-ID')?.split('@')[0];
          if(aliasNamePresence){
               const validExtension = isValidNumber(aliasNamePresence)
               if(!validExtension){
                    const { rows } = await db2.query(
                    `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                    [aliasNamePresence, ev.getHeader('variable_dialed_domain'),]
                    );
                    if(rows.length){
                         return rows[0].extension;
                    }
               }
               return ev.getHeader('variable_dialed_user');
          }

     }

     const extension = await origValidExtension();

     console.log("this is caller-context",  ev.getHeader('Caller-Context'), "this is variable_dialed_dom", ev.getHeader('variable_dialed_domain'));
     const realDomain = ev.getHeader('variable_dialed_domain') || ev.getHeader('Caller-Context');
     const realExtension = extension || ev.getHeader('Caller-Destination-Number');
      console.log("check", realExtension);
      
     const payload = {
          domain: realDomain,
          extension: realExtension,
          time: (Date.now() * 1000).toString(),
          id: uuidv4(),
          call: 'answered2',
          caller_id: ev.getHeader('Caller-Caller-ID-Number'),
          nameCaller: ev.getHeader('Caller-Caller-ID-Name'),
     };

     async function getPayloadWithExtension(payload) {
          if (classifyNumber(Number(payload.extension)) === 'InternalExtension') {
               const origExtension = await redisClient.get(`sipIp:${payload.extension}@${payload.domain}`);
               const callerSipAlias = ev.getHeader('variable_sip_alias')
               if (origExtension) {
                    return { ...payload, 
                    extension: origExtension,
                    nameCaller: `${payload.nameCaller}.`,
                    caller_id: callerSipAlias ? callerSipAlias : payload.caller_id,
                    };
               }
          }
          return payload;
     }

     const contactId =  String(ev.getHeader('variable_sip_contact_uri') || '').split('.')[0] // [audit] null on trunk/loopback legs
     const tokenIos = await redisClient.get(`tkn-${contactId}@${ev.getHeader('variable_deviceId')}`);
     console.log(`✅️ BRIDGE CALLING`, tokenIos);
     const payload2 = await getPayloadWithExtension(payload);

     if(!tokenIos){
          // redisClient.sMembers(`iosDevicePushToken-${extension}@${domain}`),
          // const tokenIos2 = await redisClient.get(`devicePushtoken-${ev.getHeader('variable_deviceId')}`);
          // await redisClient.sRem(`iosDevicePushToken-${extension}@${ev.getHeader('variable_dialed_domain')}`, tokenIos2);
          console.log("before getTokenIos 8");
          getTokenIOS({...payload2, 'call':''}, 'bridge' , ev.getHeader('variable_ios_token'));
          getTokenMultipleDevice({...payload2, 'call':''}, 'bridge', ev.getHeader('variable_ios_token'));
     }
     // console.log(`✅️ BRIDGE CALLING2`, payload2, tokenIos);
     internalPush2(payload2);

}

const handleChannelCreate = async (ev, bd, conn) => {
     
     console.log(`✅${ev.getHeader('Event-Name')} yowyow`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );
     
     if(
          ev.getHeader('Call-Direction') === 'outbound' &&
          ev.getHeader('variable_customer_first')
     ){
          // console.log('AVMD started on channel:', util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true }));
          // conn.execute('set', 'enforce_vbead=true', ev.getHeader('Unique-ID'));
          // conn.execute('avmd', 'start', ev.getHeader('Unique-ID'));
          // conn.execute('avmd', 'start inbound', ev.getHeader('Unique-ID'));
     }

     if(ev.getHeader('variable_cc_queue') && ev.getHeader('variable_cc_member_session_uuid') && ev.getHeader('variable_cc_agent')){
          console.log("this is for queue onlyyy");

          // const setData = {
          //      session_uuid: ev.getHeader('variable_cc_member_session_uuid'),
          //      serving_agent: ev.getHeader('variable_cc_agent')
          // }
          redisClient.set(`queueSessionUUID@${ev.getHeader('variable_cc_queue')}`, ev.getHeader('variable_cc_agent'))
          console.log("set 4q", `queueSessionUUID@${ev.getHeader('variable_cc_queue')}`, ev.getHeader('variable_cc_agent'));

     }

     const registrations = await fsRegistration();
     const channelExactNameTest = await presenceId(ev.getHeader('Caller-Channel-Name'));
     const domain = ev.getHeader('variable_domain_name');

     const extensionIdTest = async (ext) => {
          const findCurrentPresence = registrations.find(x => regMatch(x, ext));
          if(!findCurrentPresence) return {cid_name: ext, cid_num: ext};
          const { "Auth-User": authUser , "Auth-Realm": authRealm} = findCurrentPresence;
          const { rows } = await db2.query(
               "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
               [authUser, authRealm]
          );
          return rows.length ? {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUUID: rows[0].extension_uuid} : {cid_name: ext, cid_num: ext};
     }

     const callerTeamsTest = await extensionIdTest(channelExactNameTest)
     const { cid_name, cid_num } = callerTeamsTest;
     console.log(`This is called here TEST`, cid_num, cid_name);

     //For conference only

     


     
     const x = await redisClient.exists(`${ev.getHeader('variable_cc_member_session_uuid')}-${cid_num}`);


     // 1️⃣ Get channel name safely
     const channelName = ev.getHeader('Channel-Name') || '';
     const match = channelName.match(/(?:internal|external)\/([^@]+)/);
     if (!match) return;
     const channelExactName = match[1];

     // 3️⃣ Convert to Map for O(1) lookup

     const extensionId = async (ext) => {

          const findCurrentPresence = registrations.find(x => regMatch(x, ext));
          if(!findCurrentPresence) return ext;
          return findCurrentPresence;
     }

     const currentPresence = await extensionId(channelExactName);
     if (!currentPresence) return;

     // console.log('3️⃣', currentPresence, registrations, channelExactName);

     const authUser = currentPresence['Auth-User'];
     const authRealm = currentPresence['Auth-Realm'];

     if (authUser || authRealm){

          const [agentBody, tierBody] = await Promise.all([
               new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
               new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
          ]);

          const agentList = JSON.parse(agentBody.body).response;
          const tierList  = JSON.parse(tierBody.body).response;
          const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

          const matched = tierList.reduce((acc, tier) => {
               const agent = agentMap[tier.agent];
               if (
                    agent && 
                    agent.state === 'Waiting' && 
                    agent.contact.includes(ev.getHeader('variable_domain_name')) &&
                    agent.contact.includes(authUser)
               ) acc.push(agent);
               return acc;
          }, []);

          console.log("✅ matched:", matched);

          if(matched.length){
               await Promise.all(
                    matched.map( data => conn.api(`callcenter_config agent set state ${data.name} Idle`))
               );
          }
          
          // conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, async ({body}) => {
          //      if(body){
          //           const { status, response } = JSON.parse(body);
          //           if(status === 'success'){

          //                const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

          //                const { matched, unmatched } = tierList.reduce((acc, tier) => {
          //                const agent = agentMap[tier.agent];
          //                agent ? acc.matched.push(agent) : acc.unmatched.push(tier.agent);
          //                return acc;
          //                }, { matched: [], unmatched: [] });

          //                console.log("✅ matched:", matched);
          //                console.log("❌ unmatched:", unmatched);

          //           //    await Promise.all(
          //           //      agentNames.map(name => conn.api(`callcenter_config agent set state ${name} Idle`))
          //           //    );
          //              console.log("🌞 Agents set to Idle:", response, agentNames);
          //           }
          //      }
          // });

     }


     // setTimeout(()=>{
     // EMAN MODIFIED 09-17-26 START
     conn.api(`sched_hangup +3600 ${ev.getHeader('Unique-ID')} PROGRESS_TIMEOUT`);
     // EMAN MODIFIED 09-17-26 END
     // }, 4000) ;
     console.log("this is hangupin", ev.getHeader('Unique-ID'))
     await redisClient.set(`lastActiveUid-${ev.getHeader('Unique-ID')}`, 1, { EX: 172800 }); // [perf] TTL

     if(ev.getHeader('Call-Direction') === 'inbound'){

          if(classifyNumber(ev.getHeader("Caller-Destination-Number")) === 'Teams'){

               const value = await fsRegistration();
               const channelExactName = await presenceId(ev.getHeader('Caller-Channel-Name'));
               const domain = ev.getHeader('variable_domain_name');

               const extensionId = async (ext) => {
                    const findCurrentPresence = value.find(x => regMatch(x, ext));
                    if(findCurrentPresence){
                         const { "Auth-User": authUser , "Auth-Realm": authRealm} = findCurrentPresence;
                         const { rows } = await db2.query(
                         "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                         [authUser, authRealm]
                         );
                         return rows.length ? {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUUID: rows[0].extension_uuid} : {cid_name: ext, cid_num: ext};
                    }else{
                         const { rows } = await db2.query(
                         "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                         [ext, domain]
                         );
                         return rows.length ? {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUUID: rows[0].extension_uuid} : {cid_name: ext, cid_num: ext};
                    }
                  
               }

               const callerTeams = await extensionId(channelExactName)
               if(callerTeams){
                    const { cid_name, cid_num, extensionUUID } = callerTeams;
                    const payloadTeams = {
                         cid_num,
                         cid_name,
                         extensionUUID,
                         teamsExtension: ev.getHeader("Caller-Destination-Number"),
                    }
                    conn.api(`uuid_setvar ${ev.getHeader('Unique-ID')} teams_info_series ${JSON.stringify(payloadTeams)}`);
                    await redisClient.set(`teams-${ev.getHeader('Unique-ID')}-${domain}`, JSON.stringify(payloadTeams));
               }

          }

          const channelName = ev.getHeader('Channel-Name');
          if(channelName.includes('internal') && !ev.getHeader('variable_sip_gateway')){

               const domain = ev.getHeader('variable_domain_name');
               const uuid = ev.getHeader('Unique-ID');
               const channelPresenceName = await presenceId(channelName);
               if(!domain) return
               await redisClient.set(`${domain}:incall:${channelPresenceName}`, uuid);
               // Get destination alias
               const extension = ev.getHeader("Caller-Destination-Number");
               const ext = Number(extension);
               if(extension && domain){

                    const siCallId = async (domainId, dest) => {
                         const { rows } = await db2.query(`SELECT extension FROM q_call_behaviors WHERE domain_uuid = $1 AND force_ping = $2`, [domainId, dest]);
                         console.log(`🤝`, rows, domainId, dest);
                         return rows.length ? rows[0].extension : dest;
                    }
                    const aliasNameExtension = async (destination) => {
                         const { rows } = await db2.query(
                         `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                         [domain, destination]
                         );
                         return rows[0]?.number_alias || destination;
                    };
                    const origExtension = await siCallId(ev.getHeader('variable_domain_uuid'), extension)

                    // [ring-push] in "wake" mode the dialplan rings user/<ext> and ringq_ring.lua pushes and
                    // waits for the app; parking the call here would take it off that path
                    if (classifyNumber(ext) === 'InternalExtension' && await ringPush.owns(domain)) {
                         console.log('[ring-push] wake mode: leaving', `${origExtension}@${domain}`, 'to the dialplan');
                    } else if (classifyNumber(ext) === 'InternalExtension') {

                         /** NO ANSWER CONTROL */
                         const callControlPosition = await redisClient.get(`${origExtension}+${domain}`);
                         console.log("hzz")
                         if (callControlPosition) {
                               console.log("hzz 1")
                              const positionNum = Number(callControlPosition);
                              const callControlTimeOutEnabled = await redisClient.lRange(`${origExtension}@${domain}-forward-no-answer-enabled`, positionNum, positionNum);
                              if (callControlTimeOutEnabled[0] === 'true') {
                                      console.log("hzz 2")
                                   const callControlTimeOutDestination = await redisClient.lRange(`${origExtension}@${domain}-forward-no-answer-destination`, positionNum, positionNum);
                                   await redisClient.set(`${domain}${origExtension}-RingQ`, uuid);
                                   const destinationAlias = await aliasNameExtension(origExtension);
                                   conn.api(`sofia_count_reg internal/${destinationAlias}@${domain}`, async({ body }) => {
                                           console.log("hzz 3")
                                       //Changed 09-11-26

                                        if (Number(body) > 0){
                                        if (await luaCallControlActive(uuid)) return; // [cc-owner]

                                                console.log("hzz 4");


                                                //"iosDevicePushToken-1004@multi.flowersconradplc.ringq.cloud"



                                        //      //Main issue uuid_park 09-15-26
                                        //      // conn.api(`sched_del ${uuid}`);
                                        //      // console.log("deleting,,", `sched_del ${uuid}`)
                                        //      // await new Promise((r) => setTimeout(r, 2000));

                                        // //     conn.api(`uuid_park ${uuid}`);
                                        //      console.log("falsering 6.1")
                                        //      // conn.api(`uuid_broadcast ${uuid} playback::misc/falsering.wav aleg`);
                                        //     conn.api(`sched_api +10 none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                        const iosOnly = `iosDevicePushToken-${origExtension}@${domain}`;
                                        console.log("iosOnly", iosOnly);
                                        const androidOnly = `deviceOff-${origExtension}@${domain}`;
                                        //    const androidDeviceOff = await redisClient.sMembers(`deviceOff-${payload.arg}@${payload.subdomain}`);
                                        const getIosOnly = await redisClient.exists(iosOnly);
                                        const getAndroidOnly = await redisClient.exists(androidOnly);
                                        if(getIosOnly){
                                             console.log("must be ios only but multple");
                                             if (callControlTimeOutDestination.length) {
                                             console.log("check this first");
                                             console.log("no answer 4", callControlTimeOutDestination[0]);
                                             
                                             // conn.api(`sched_del +0 ${uuid}`);
                                             //  await new Promise((r) => setTimeout(r, 2000));
                                             console.log(`sched_api 1 ${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                             //conn.api(`sched_api +${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                             conn.api(`sched_transfer +${ev.getHeader('variable_call_timeout')} ${uuid} ${callControlTimeOutDestination[0]} XML ${domain}`);
                                        
                                             } else {
                                                  console.log("check this first")
                                                  console.log("no answer 4.2")
                                                  console.log(`sched_api 2 ${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                                  //conn.api(`sched_api +${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                                  conn.api(`sched_transfer +${ev.getHeader('variable_call_timeout')} ${uuid} *99${origExtension} XML ${domain}`);
                                                  console.log("156")
                                             }
                                        }
                                        else if(!getIosOnly){

                                             if(getAndroidOnly){
                                                   console.log("must be android only but multple");
                                                  // return
                                             }
                                             else{
                                                  console.log("must be webapp only");
                                                  if (callControlTimeOutDestination.length) {
                                                  console.log("check this first");
                                                  console.log("no answer 4", callControlTimeOutDestination[0]);
                                                  // conn.api(`sched_del +0 ${uuid}`);
                                                  //  await new Promise((r) => setTimeout(r, 2000));
                                                  console.log(`sched_transfer 1.1 +${ev.getHeader('variable_call_timeout')} ${uuid} ${callControlTimeOutDestination[0]} XML ${domain}`);
                                                  //conn.api(`sched_api +${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                                  conn.api(`sched_transfer +${ev.getHeader('variable_call_timeout')} ${uuid} ${callControlTimeOutDestination[0]} XML ${domain}`);
                                             
                                                  } else {
                                                       console.log("check this first")
                                                       console.log("no answer 4.2")
                                                       console.log(`sched_api 2 ${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                                       //conn.api(`sched_api +${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                                       conn.api(`sched_transfer +${ev.getHeader('variable_call_timeout')} ${uuid} *99${origExtension} XML ${domain}`);
                                                       console.log("156")
                                                  }
                                             }
                                             
                                            
                                             


                                       
                                        }

                                     

                                        


                                        console.log("hmm")
                                             return
                                        }
                                        else{
                                                console.log("hzz 5")
                                              const key = `${origExtension}@${domain}`;
                                              const deviceOff = await redisClient.sMembers(`deviceOff-${key}`);
                                        
                                        // EMAN ADDED 08-19-26 START
                                        // if(deviceOff.length){
                                        // }
                                        // EMAN ADDED 08-19-26 END
                                        // const schedGroup = `none_${uuid}`
                                        if (callControlTimeOutDestination.length) {
                                             console.log("check this first");
                                             console.log("no answer 4");
          
                                             console.log(`sched_api 1 ${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                             conn.api(`sched_api +${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                             conn.api(`sched_transfer +${ev.getHeader('variable_call_timeout')} ${uuid} ${callControlTimeOutDestination[0]} XML ${domain}`);
                                        } else {
                                             console.log("check this first")
                                              console.log("no answer 4.2")
                                             console.log(`sched_api 2 ${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                             conn.api(`sched_api +${ev.getHeader('variable_call_timeout')} none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                             conn.api(`sched_transfer +${ev.getHeader('variable_call_timeout')} ${uuid} *99${origExtension} XML ${domain}`);
                                             console.log("156")
                                        }
                                        } 

                                        ///Changed 09-11-26

                                   });

                              }
                         }

                         /** PUSH NOTIFICATION */
                         const nameCallerOrig = async () => {
                              const { rows } = await db2.query(
                              `SELECT effective_caller_id_name FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                              [ev.getHeader('variable_domain_uuid'), ev.getHeader("variable_effective_caller_id_number")]
                              );
                              return rows[0]?.effective_caller_id_name;
                         };

                         const destAliasHandler = async (extension2) => {
                              const { rows } = await db2.query(
                              `SELECT number_alias FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                              [ev.getHeader('variable_domain_uuid'), extension2]
                              );
                              return rows[0]?.number_alias;
                         };

                         const effectiveCallerName = await nameCallerOrig();
                         const destAlias = await destAliasHandler(origExtension);


                         const payload = {
                              domain: ev.getHeader('variable_user_context'),
                              extension: origExtension,
                              time: ev.getHeader("Event-Date-Timestamp"),
                              id: ev.getHeader("Channel-Call-UUID"), // uuidv4() -- 09-29-26 EMAN MODIFIED
                              call: ev.getHeader("Channel-Call-UUID"),
                              caller_id: ev.getHeader("variable_effective_caller_id_number") || '',
                              nameCaller: effectiveCallerName,
                              time2: ev.getHeader("Event-Date-Timestamp")
                         };

                         
                         
                         await new Promise(resolve => setTimeout(resolve, 1000));
                         if (await callNotRingingExtension(uuid)) return; // [cc-owner] forwarded / DND: no ringing push
                         console.log("before getTokenIos 7");
                         getToken(payload);
                         getTokenIOS(payload, destAlias);
                         getTokenMultipleDevice(payload);
                         // pushWeb(payload);
                         
                    }
                    if(classifyNumber(ext) === 'Queue'){
                         //find Queue agent -> set busy
                         const ccQueue = `${origExtension}@${domain}`
                         conn.api(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ccQueue}"}}`, async (res) => {
                              const { status, response } = JSON.parse(res.body)
                              if(status === 'success'){
                                   const activeName = response.map(({contact, status, state}) => {
                                        if(status === 'Available' && (state === 'Waiting' || state === 'Receiving')){
                                             return contact.split('user/')[1];
                                        }
                                   });
                                   console.log(`🌾Queue🌾`, activeName);
                              }
                         })

                    }
               }
               showcallsEventRefresh(domain);

          }

          if (channelName.includes('external') || ev.getHeader('variable_sip_gateway')) {
               conn.api(`uuid_dump ${ev.getHeader('Unique-ID')} JSON`, async (res) => {
               if (res.body === '-ERR No such channel!\n') return;

               const data = JSON.parse(res.body);
               const uuid = ev.getHeader('Unique-ID');
               const externalDomain = data['variable_domain_name'];
               const extension = data['Caller-Destination-Number'];

               // Fire-and-forget — no need to await UI refresh
               showcallsEventRefresh(externalDomain);

               if (classifyNumber(Number(extension)) !== 'InternalExtension') return;

               // ─── 1. Parallel DB + Redis reads ────────────────────────────────────
               const [aliasRow, callerNameRow, callControlPosition] = await Promise.all([
                    // Replaces both aliasNameExtension() AND extensionAlias() — same query
                    db2.query(
                    `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                    [externalDomain, extension]
                    ),
                    // Replaces nameCallerOrig()
                    db2.query(
                    `SELECT effective_caller_id_name FROM v_extensions
                    WHERE domain_uuid = $1 AND extension = $2 LIMIT 1`,
                    [data['variable_domain_uuid'], data['variable_effective_caller_id_number']]
                    ),
                    redisClient.get(`${extension}+${externalDomain}`),
               ]);

               const destinationAlias = aliasRow.rows[0]?.number_alias || extension;
               const effectiveCallerName =
                    callerNameRow.rows[0]?.effective_caller_id_name || data['Caller-Caller-ID-Name'];

               // ─── 2. Parallel Redis write + exists check ───────────────────────────
               const incallKey = `${externalDomain}:incall:${destinationAlias}`;
               const [exists2] = await Promise.all([
                    redisClient.exists(incallKey),
                    redisClient.set(incallKey, uuid, { EX: 7200 }),
               ]);

               console.log(`🎲 External Call Here!`, destinationAlias, exists2, incallKey);

               // ─── 3. No-answer call control ────────────────────────────────────────
               if (callControlPosition) {
                    const callerAlias = destinationAlias; // same extension, reuse alias
                    console.log("has callControllPosition");
                    const positionNum = Number(callControlPosition);
                    const noAnswerKey = `${extension}@${externalDomain}-forward-no-answer`;

                    // Fetch enabled flag + destination in parallel
                    const [enabledArr, destinationArr] = await Promise.all([
                    redisClient.lRange(`${noAnswerKey}-enabled2`, positionNum, positionNum),
                    redisClient.lRange(`${noAnswerKey}-destination2`, positionNum, positionNum),
                    ]);

                    if (enabledArr[0] === 'true') {
                         console.log("is true")
                    // Write RingQ key while checking registration — no need to await
                    redisClient.set(`${externalDomain}${extension}-RingQ`, uuid);

                    conn.api(`sofia_count_reg internal/${callerAlias}@${externalDomain}`, async ({ body }) => {
             
                         if ((Number(body) > 0)){
                              if (await luaCallControlActive(uuid)) return; // [cc-owner]
                          //1146 sched_transfer +null 01e84b6c-3415-4b92-870f-9b63e3d54bd1 1146 XML multi.flowersconradplc.ringq.cloud

                                   //  conn.api(`uuid_park ${uuid}`);
                                   // console.log("falsering 5.1")
                                   // conn.api(`uuid_broadcast ${uuid} playback::misc/falsering.wav aleg`);

                               const transferTarget =
                                   destinationArr[0] ? destinationArr[0] : `*99${extension}`;
                                   // await redisClient.set(`getTimeout-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('accountcode')}`, ev.getHeader('call_timeout2'))
                                   const getTimeoutOrig = await redisClient.get(`getTimeout-${ev.getHeader('Caller-Destination-Number')}@${externalDomain}`);
                                   console.log("getTimeoutOrig", `getTimeout-${ev.getHeader('Caller-Destination-Number')}@${externalDomain}`, getTimeoutOrig )
                                   const callTimeout = ev.getHeader('variable_call_timeout') || getTimeoutOrig ||  30
                                      console.log("here 222222", transferTarget, `sched_transfer +${callTimeout} ${uuid} ${transferTarget} XML ${externalDomain}`)
                                   // conn.api(`sched_api +30 none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                   conn.api(`sched_transfer +${callTimeout} ${uuid} ${transferTarget} XML ${externalDomain}`);
                                  // conn.api(`sched_transfer +5 ${uuid} 1146 XML ${externalDomain}`);
                              return;
                         } 
                         else {
                                 console.log("here 11111")
                                   const deviceOff = await redisClient.sMembers(`deviceOff-${extension}@${externalDomain}`);

                                   const transferTarget =
                                   destinationArr[0] ? destinationArr[0] : `*99${extension}`;
                                   console.log("here 111113", transferTarget);
                                   console.log("no answer 4.1")

                                   console.log(`sched_api 10 +30 none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`)
                                   conn.api(`sched_api +30 none uuid_setvar ${uuid} my_hangup_cause NO_ANSWER`);
                                   conn.api(`sched_transfer +30 ${uuid} ${transferTarget} XML ${externalDomain}`);
                         }

                      
                    });  
                    }
               }


               const destAliasHandler = async (extension2) => {
                    const { rows } = await db2.query(
                    `SELECT number_alias FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                    [ev.getHeader('variable_domain_uuid'), extension2]
                    );
                    return rows[0]?.number_alias;
               };

               const destAlias = await destAliasHandler(extension);

               // ─── 4. Push notifications — fire all in parallel ─────────────────────
               const payload = {
                    domain:     externalDomain,
                    extension,
                    time:       data['Event-Date-Timestamp'],
                    id:         data['Channel-Call-UUID'], // uuidv4() 09-29-26 EMAN MODIFIED 
                    call:       data['Channel-Call-UUID'],
                    caller_id:  data['variable_effective_caller_id_number'] || data['Caller-Caller-ID-Number'],
                    nameCaller: effectiveCallerName,
                    time2:      data['Event-Date-Timestamp'],
               };
               await new Promise((r) => setTimeout(r, 300)); // [cc-owner] let the dialplan resolve Call Control
               if (await callNotRingingExtension(uuid)) return; // [cc-owner] forwarded / DND: no ringing push
               console.log("before getTokenIos 6");
               // All push channels fire simultaneously
               await Promise.allSettled([
                    getTokenIOS(payload, destAlias),
                    getTokenMultipleDevice(payload),
                    getToken(payload),
                    // pushWeb(payload),
               ]);
               });
          }

     }

     if (ev.getHeader('Caller-Direction') == 'inbound' && ev.getHeader('Channel-State') == 'CS_INIT') {
          conn.api('show calls count', async (res) => {
               let currentCalls = parseInt(res.getBody().trim());
               let maxCalls = await redisClient.get(ev.getHeader('FreeSWITCH-IPv4') +"-con");
               if (maxCalls != null && maxCalls.toString() != "-1") {

                    if (ev.getHeader('Caller-Direction') == 'inbound') {
                         if (ev.getHeader('Caller-Destination-Number') && ev.getHeader('Caller-Destination-Number').length == 4) {
                              currentCalls = currentCalls + 1;
                         }
                    }

                    if (currentCalls >= parseInt(maxCalls.toString())) {

                         if (ev.getHeader('Caller-Direction') == 'inbound') {
                              if (ev.getHeader('Caller-Destination-Number').length == 4) {
                                   conn.api("uuid_park " + ev.getHeader("Unique-ID"));
                              }
                              if (!ev.getHeader('Channel-Name').includes("internal")) {
                                    console.log("transfer 3S")
                                   console.log('🍿 EXTERNAL LIMIT EXCEEDED', currentCalls, maxCalls)
                                   conn.api("uuid_answer " + ev.getHeader("Unique-ID"));
                                   conn.api("uuid_transfer " + ev.getHeader("Unique-ID") + " external_limit_exceeded");
                              }else{

                                   console.log('🍿 LIMIT EXCEEDED', currentCalls, maxCalls)
                                   console.log("transfer 4S")
                                   conn.api("uuid_transfer " + ev.getHeader("Unique-ID") + " limit_exceeded");
                                   const existLimitExceeded =  await redisClient.exists(`limit-exceeded-${ev.getHeader('variable_domain_name')}`);
                                   if (!existLimitExceeded) {
                                        if(!ev.getHeader('variable_domain_name')) return
                                        await redisClient.lPush(`limit-exceeded`, `${ev.getHeader('variable_domain_name')}`);
                                        await redisClient.set(`limit-exceeded-${ev.getHeader('variable_domain_name')}`, 1);
                                        await redisClient.expire(`limit-exceeded-${ev.getHeader('variable_domain_name')}`, 3600);
                                   }else{
                                        await redisClient.set(`limit-exceeded-${ev.getHeader('variable_domain_name')}`, 1);
                                        await redisClient.expire(`limit-exceeded-${ev.getHeader('variable_domain_name')}`, 3600);
                                   }
                              }
                         }
                         // conn.api("sched_api +6 none uuid_kill " + ev.getHeader("Unique-ID"));
                    }

               }
          });               
     }

     
     // EMAN ADDED 06-26-25 START // 
     if(ev.getHeader('Caller-Direction') == 'inbound' && 
     ev.getHeader('Channel-State') == 'CS_INIT' && ev.getHeader('Channel-Call-State') == 'DOWN'){

          switch (ev.getHeader('variable_sofia_profile_name')) {
               case 'internal':

                    setTimeout( async () => {
                         const res = await promisifiedApi('show calls as json');
                         if(!res) return;
                         const { row_count, rows } = JSON.parse(res.body);
                         if(row_count){
                              const activeCall = rows.filter((x) => 
                                   x.callstate === 'ACTIVE' && x.b_callstate === 'ACTIVE'
                              );
                              const filterCall = await internalActive(activeCall)
                              if(filterCall){

                                   const aliasNameExtension = async () => {
                                        const { rows } = await db2.query(
                                             `SELECT number_alias FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                                             [ev.getHeader('variable_domain_uuid'), ev.getHeader('Caller-Destination-Number')]
                                        );
                                        const extensionOrigName = rows[0]?.number_alias;
                                        return extensionOrigName
                                   }

                                   const destinationPresence = `${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('variable_accountcode')}`
                                   const destinationAlias = await aliasNameExtension();
                                   const nameCallerOrig = async () => {
                                        const { rows } = await db2.query(
                                             `SELECT effective_caller_id_name FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                                             [ev.getHeader('variable_domain_uuid'), ev.getHeader("variable_effective_caller_id_number")]
                                        );
                                        const extensionOrigName = rows[0]?.effective_caller_id_name;
                                        return extensionOrigName
                                   }

                                   const effectiveCallerName = await nameCallerOrig()
                                   const payload = {
                                        domain: ev.getHeader('variable_user_context'),
                                        extension: ev.getHeader("Caller-Destination-Number"),
                                        time: ev.getHeader("Event-Date-Timestamp"),
                                        id: ev.getHeader("Channel-Call-UUID"), // uuidv4(), 09-29-26 EMAN MODIFIED
                                        call: ev.getHeader("Channel-Call-UUID"),
                                        caller_id: ev.getHeader("variable_effective_caller_id_number") ?  ev.getHeader("variable_effective_caller_id_number") : '',
                                        nameCaller: effectiveCallerName, 
                                        time2: ev.getHeader("Event-Date-Timestamp")
                                   };

                                   async function getPayloadWithExtension(payload) {
                                        if (classifyNumber(Number(payload.extension)) === 'InternalExtension') {
                                             const callerSipAlias = ev.getHeader('variable_sip_alias')
                                             if (callerSipAlias) {
                                                  return { ...payload, 
                                                       caller_id: callerSipAlias ? callerSipAlias : payload.caller_id,
                                                  };
                                             }
                                        }
                                        return payload;
                                   }

                                   const payload2 = await getPayloadWithExtension(payload);
                                   // getToken(payload2);                                   

                              }
                         }
                    }, 1000)
                    break;

               default:
                    break;
          }  
          
     }

     if(!x && !ev.getHeader('variable_channel_name').includes('loopback')){

          if(ev.getHeader('variable_cc_member_session_uuid')){
               await redisClient.set(`${ev.getHeader('variable_cc_member_session_uuid')}-${cid_num}`, 1, {EX:8});
          }

          if(ev.getHeader('Channel-Name').includes('internal')){
               // conn.api(`limit_usage db ${domain} ${cid_num}`, async (res) => {
               //      const usage = parseInt(res.getBody().trim()) || 0;
               //      console.log(`Usage for ${cid_num}: ${usage}`);
               //      if (usage === 0) {
               //           await redisClient.lPush(`limitUsage-${cid_num}@${domain}`, ev.getHeader('Unique-ID'));
               //           console.log(`uuid_limit ${ev.getHeader('Unique-ID')} db ${domain} ${cid_num} 1`)
               //           conn.api(`uuid_limit ${ev.getHeader('Unique-ID')} db ${domain} ${cid_num} 1`);
               //      }
               // });
          }
          
     }

}

const handleChannelCallState = async (ev, bd, conn) => {
     if(ev.getHeader('Channel-Call-State') === 'RINGING'){

          console.log(`✅${ev.getHeader('Event-Name')}`, 
               util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
          );
          if(ev.getHeader('Call-Direction') === 'inbound'){

               if(!ev.getHeader('Channel-Name').includes('internal')){

                    
                    conn.api(`uuid_dump ${ev.getHeader('Unique-ID')} JSON`, async ({ body }) => {
                         console.log("must have call");
                         if(body === '-ERR No such channel!\n') return
                         const data = JSON.parse(body);
                         console.log(`⚽ internal`, data);

                         const previousDestination = data['Caller-RDNIS'];
                         
                           //"deviceOff-1135@multi.flowersconradplc.ringq.cloud"
                       //Added 08-25-26
                       const deviceOn = `deviceOff-${data['Caller-Destination-Number']}@${data['variable_domain_name']}`
                       ////Added 08-25-26
                       const getDeviceOn = await redisClient.exists(deviceOn);
                       //"iosDevicePushToken-1204@multi.flowersconradplc.ringq.cloud"

                       // Added 08-28-26
                       const iosDeviceOn = `iosDevicePushToken-${data['Caller-Destination-Number']}@${data['variable_domain_name']}`;
                       const getIosDeviceOn = await redisClient.exists(iosDeviceOn);
                       // Added 08-28-26

                       if( 
                              classifyNumber(Number(previousDestination)) === 'InternalExtension'
                         ){
                              const validEntry = ['NO_ANSWER', 'USER_NOT_REGISTERED2'];
                              if(validEntry.includes(data['variable_originate_failed_cause'])){
                                   const callControlPosition = await redisClient.get(`${previousDestination}+${data['variable_domain_name']}`);
                                   if(callControlPosition){
                                        const positionNum = Number(callControlPosition)
                                        const callControlTimeOutDestination2 = await redisClient.lRange(`${previousDestination}@${data['variable_domain_name']}-forward-no-answer-destination2`, positionNum, positionNum);
                                        if(callControlTimeOutDestination2[0] == data['Caller-Destination-Number']){

                                             /** NO ANSWER CONTROL */
                                             const aliasNameExtension = async (destination) => {
                                                  const { rows } = await db2.query(
                                                       `SELECT number_alias FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                                                       [ev.getHeader('variable_domain_uuid'), destination]
                                                  );
                                                  const extensionOrigName = rows[0]?.number_alias;
                                                  return extensionOrigName
                                             }
                                             const destinationAlias = await aliasNameExtension(data['Caller-Destination-Number']);
                                             const callControlPosition = await redisClient.get(`${data['Caller-Destination-Number']}+${ev.getHeader('variable_domain_name')}`);
                                             if(callControlPosition){
                                                  const positionNum = Number(callControlPosition)
                                                  const callControlTimeOutEnabled = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-enabled2`, positionNum, positionNum)
                                                  if(callControlTimeOutEnabled[0] === 'true'){
                                                       const callControlTimeOutDestination = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-destination2`, positionNum, positionNum);
                                                       conn.api(`sofia_count_reg internal/${destinationAlias}@${data['variable_domain_name']}`, ({ body }) => {
                                                            console.log(`This is called here external-transfer!💡`);
                                                            if(Number(body) > 0) return;
                                                            console.log(`This is called here external-transfer!💡💡`);
                                                            if(callControlTimeOutDestination.length){
                                                                 console.log("no asnwer 5.1")
                                                                 conn.api(`sched_transfer +30 ${data['Unique-ID']} ${callControlTimeOutDestination[0]} XML ${data['variable_domain_name']}`);
                                                            }else{
                                                                 console.log("no asnwer 5")
                                                                  console.log(`sched_api 9 +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                 conn.api(`sched_api +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                 conn.api(`sched_transfer +30 ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);
                                                            }
                                                       });
                                                  }
                                             }
                                             /** PUSH */
                                             const presence = await presenceId(ev.getHeader('Channel-Name')); 
                                             const payload = {
                                                  domain: data['variable_domain_name'],
                                                  extension: data['Caller-Destination-Number'],
                                                  time: data['Event-Date-Timestamp'],
                                                  id: data['Unique-ID'], // uuidv4() -- 09-29-26 EMAN MODIFIED  [audit] was 'ata' (ReferenceError)
                                                  call: data['Unique-ID'],
                                                  caller_id: presence,
                                                  nameCaller: presence, 
                                                  time2: data['Event-Date-Timestamp']
                                             };
                                             console.log("before getTokenIos 5");
                                             getTokenIOS(payload);
                                             getTokenMultipleDevice(payload);
                                             getToken(payload);
                                             // pushWeb(payload);

                                        }
                                        
                                   }
                              }
                         }
                         //Added 08-28-26
                 
                         else if(classifyNumber(Number(previousDestination)) === 'IVR' && (getDeviceOn || getIosDeviceOn)){

                                        console.log("this is IVR 11....");
                                             /** NO ANSWER CONTROL */
                                             const aliasNameExtension = async (destination) => {
                                                  const { rows } = await db2.query(
                                                       `SELECT number_alias FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                                                       [ev.getHeader('variable_domain_uuid'), destination]
                                                  );
                                                  const extensionOrigName = rows[0]?.number_alias;
                                                  return extensionOrigName
                                             }
                                             const destinationAlias = await aliasNameExtension(data['Caller-Destination-Number']);
                                             const callControlPosition = await redisClient.get(`${data['Caller-Destination-Number']}+${data['variable_domain_name']}`);
                                             if(callControlPosition){
                                                  console.log(`ey ${data['Caller-Destination-Number']}+${ev.getHeader('variable_domain_name')}`)
                                                  const positionNum = Number(callControlPosition)
                                                  const callControlTimeOutEnabled = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-enabled2`, positionNum, positionNum)
                                                  console.log("eyy", `${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-enabled2`, positionNum, positionNum)
                                                  if(callControlTimeOutEnabled[0] === 'true'){
                                                       console.log("true means")
                                                       const callControlTimeOutDestination = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-destination2`, positionNum, positionNum);
                                                       const getTimeout = await redisClient.get(`ivrTimeoutTimer-${previousDestination}`);
                                                       conn.api(`sofia_count_reg internal/${destinationAlias}@${data['variable_domain_name']}`, ({ body }) => {
                                                            console.log(`This is called here external-transfer!💡`);
                                                            if(Number(body) > 0) return;
                                                            console.log(`This is called here external-transfer!💡💡`);
                                                            // conn.api(`uuid_park ${data['Unique-ID']}`);
                                                            // console.log("falsering 4")
                                                            // conn.api(`uuid_broadcast ${data['Unique-ID']} playback::misc/falsering.wav aleg`);
                                                          
                                                            if(getTimeout){
                                                                 console.log("get timeout", `ivrTimeoutTimer-${previousDestination}`, getTimeout)
                                                              if(callControlTimeOutDestination.length){
                                                                      console.log("1💡💡")
                                                                      conn.api(`sched_transfer +${getTimeout} ${data['Unique-ID']} ${callControlTimeOutDestination[0]} XML ${data['variable_domain_name']}`);
                                                                 } else{
                                                                      console.log("2💡💡")
                                                                       console.log(`sched_api 8 +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                      conn.api(`sched_api +${getTimeout} none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                      conn.api(`sched_transfer +${getTimeout} ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);
                                                                 }
                                                            }
                                                            else{
                                                                 console.log("no get timeout", `ivrTimeoutTimer-${previousDestination}`)
                                                                 if(callControlTimeOutDestination.length){
                                                                      console.log("3💡💡")
                                                                           conn.api(`sched_transfer +30 ${data['Unique-ID']} ${callControlTimeOutDestination[0]} XML ${data['variable_domain_name']}`);
                                                                 } else{
                                                                      console.log("4💡💡")
                                                                        console.log(`sched_api 7 +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                      conn.api(`sched_api +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                      conn.api(`sched_transfer +30 ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);
                                                                 }
                                                            }
                                             
                                                       });
                                                  }
                                                  else{
                                                       console.log("false means")
                                                  }
                                             }
                                             else{
                                                  console.log("no asnwer 8")
                                                  console.log("wrong", `${data['Caller-Destination-Number']}+${data['variable_domain_name']}`);
                                                    console.log(`sched_api 6 +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                   conn.api(`sched_api +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                   conn.api(`sched_transfer +30 ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);

                                             }
                                             /** PUSH */
                                      console.log("this is IVR....");
                                        const presence = await presenceId(ev.getHeader('Channel-Name')); 
                                        const payload = {
                                             domain: data['variable_domain_name'],
                                             extension: data['Caller-Destination-Number'],
                                             time: data['Event-Date-Timestamp'],
                                             id: data['Unique-ID'], // uuidv4(), -- 09-29-26 EMAN MODEIFIED
                                             call: data['Unique-ID'],
                                             caller_id: presence,
                                             nameCaller: presence, 
                                             time2: data['Event-Date-Timestamp']
                                        };
                                        //Changed 08-28-26
                                        setTimeout(()=>{
                                             console.log("before getToken/ios IVR", payload);
                                             redisClient.set(`declinePayload-${payload.extension}@${payload.domain}`, JSON.stringify(payload));
                                             console.log("set to", `declinePayload-${payload.extension}@${payload.domain}:`, JSON.stringify(payload));
                                             if(getIosDeviceOn){
                                                  console.log("ivr ios")
                                                   getTokenIOS(payload);
                                                   redisClient.set(`pushIosIVRmissed--${payload.extension}@${payload.domain}`, JSON.stringify(payload));
                                                   console.log("set to 11", `pushIosIVRmissed--${payload.extension}@${payload.domain}`, JSON.stringify(payload));
                                             }
                                             if(getDeviceOn){
                                                   console.log("ivr android")
                                                   getToken(payload);
                                             }
                                             if (getIosDeviceOn && getDeviceOn) {
                                                  console.log("ivr both")
                                                  getTokenMultipleDevice(payload);
                                             }
                                            

                                        }, 2000)
                                         ////Changed 08-28-26
                                   

                         }

                         else {
                              console.log("checking", previousDestination);
                              const th = classifyNumber(Number(previousDestination))
                              console.log("th", th);

                              //
                         }
                         ////Added 08-28-26
                    });



               }

               if(ev.getHeader('Channel-Name').includes('internal')){

                    conn.api(`uuid_dump ${ev.getHeader('Unique-ID')} JSON`, async ({ body }) => {
                         if(body === '-ERR No such channel!\n') return
                         const data = JSON.parse(body);
                         console.log(`⚽ internal`, data);
                         const domain = data['variable_domain_name'];
                         console.log("this is internal domain", domain)
                         const previousDestination = data['Caller-RDNIS'];
                         if( 
                              classifyNumber(Number(previousDestination)) === 'InternalExtension'
                         ){
                              console.log("here??")
                              const validEntry = ['NO_ANSWER', 'USER_NOT_REGISTERED2'];
                              if(validEntry.includes(data['variable_originate_failed_cause'])){
                                   const callControlPosition = await redisClient.get(`${previousDestination}+${data['variable_domain_name']}`);
                                   if(callControlPosition){
                                        const positionNum = Number(callControlPosition)
                                        const callControlTimeOutDestination2 = await redisClient.lRange(`${previousDestination}@${data['variable_domain_name']}-forward-no-answer-destination`, positionNum, positionNum);
                                        if(callControlTimeOutDestination2[0] == data['Caller-Destination-Number']){

                                             /** NO ANSWER CONTROL */
                                             const aliasNameExtension = async (destination) => {
                                                  const { rows } = await db2.query(
                                                       `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                                                       [domain, destination]
                                                  );
                                                  const extensionOrigName = rows[0]?.number_alias;
                                                  return extensionOrigName
                                             }
                                             const destinationAlias = await aliasNameExtension(data['Caller-Destination-Number']);

                                             const callControlPosition = await redisClient.get(`${data['Caller-Destination-Number']}+${ev.getHeader('variable_domain_name')}`);
                                             if(callControlPosition){
                                                  const positionNum = Number(callControlPosition)
                                                  const callControlTimeOutEnabled = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-enabled`, positionNum, positionNum)
                                                  if(callControlTimeOutEnabled[0] === 'true'){
                                                       const callControlTimeOutDestination = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-destination`, positionNum, positionNum);
                                                       conn.api(`sofia_count_reg internal/${destinationAlias}@${data['variable_domain_name']}`, ({ body }) => {
                                                            console.log(`This is called here external-transfer!💡`);
                                                            if(Number(body) > 0) return;
                                                            console.log(`This is called here external-transfer!💡💡`);
                                                            console.log("no asnwer 9")
                                                            if(callControlTimeOutDestination.length){
                                                                 conn.api(`sched_transfer +30 ${data['Unique-ID']} ${callControlTimeOutDestination[0]} XML ${data['variable_domain_name']}`);
                                                            }else{
                                                                 //test 09-14-26
                                                                console.log(`sched_api 5 +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`)
                                                                conn.api(`sched_api +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                conn.api(`sched_transfer +30 ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);
                                                            }
                                                       });
                                                  }
                                             }

                                             /** PUSH */
                                             const presence = await presenceId(ev.getHeader('Channel-Name'));    
                                             const payload = {
                                                  domain: data['variable_domain_name'],
                                                  extension: data['Caller-Destination-Number'],
                                                  time: data['Event-Date-Timestamp'],
                                                  id: data['Unique-ID'], // uuidv4(), -- 09-29-26 EMAN MODIFIED
                                                  call: data['Unique-ID'],
                                                  caller_id: previousDestination,
                                                  nameCaller: presence, 
                                                  time2: data['Event-Date-Timestamp']
                                             };
                                             console.log("before getTokenIos 4");
                                             getTokenIOS(payload);
                                             getTokenMultipleDevice(payload);
                                             getToken(payload);
                                             // pushWeb(payload);

                                        }
                                        
                                   }
                              }

                         }
                    })  

               }

          }

          if(ev.getHeader('Call-Direction') === 'outbound'){

               if(!ev.getHeader('Channel-Name').includes('internal')){
                    conn.api(`uuid_dump ${ev.getHeader('Unique-ID')} JSON`, async ({ body }) => {
                         if(body === '-ERR No such channel!\n') return
                         const data = JSON.parse(body);
                         console.log(`⚽`, data);
                         const previousDestination = data['Caller-RDNIS']
                    
                         if( 
                              classifyNumber(Number(previousDestination)) === 'InternalExtension'
                         ){

                              const validEntry = ['NO_ANSWER', 'USER_NOT_REGISTERED2'];
                              if(validEntry.includes(data['variable_originate_failed_cause'])){
                                   const callControlPosition = await redisClient.get(`${previousDestination}+${data['variable_domain_name']}`);
                                   if(callControlPosition){
                                        const positionNum = Number(callControlPosition)
                                        const callControlTimeOutDestination2 = await redisClient.lRange(`${previousDestination}@${data['variable_domain_name']}-forward-no-answer-destination2`, positionNum, positionNum);
                                        if(callControlTimeOutDestination2[0] == data['Caller-Destination-Number']){

                                             /** NO ANSWER CONTROL */
                                             const aliasNameExtension = async (destination) => {
                                                  const { rows } = await db2.query(
                                                       `SELECT number_alias FROM v_extensions WHERE domain_uuid = $1 AND extension = $2`,
                                                       [ev.getHeader('variable_domain_uuid'), destination]
                                                  );
                                                  const extensionOrigName = rows[0]?.number_alias;
                                                  return extensionOrigName
                                             }
                                             const destinationAlias = await aliasNameExtension(data['Caller-Destination-Number']);
                                             const callControlPosition = await redisClient.get(`${data['Caller-Destination-Number']}+${ev.getHeader('variable_domain_name')}`);
                                             if(callControlPosition){
                                                  const positionNum = Number(callControlPosition)
                                                  const callControlTimeOutEnabled = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-enabled2`, positionNum, positionNum)
                                                  if(callControlTimeOutEnabled[0] === 'true'){
                                                       const callControlTimeOutDestination = await redisClient.lRange(`${data['Caller-Destination-Number']}@${data['variable_domain_name']}-forward-no-answer-destination2`, positionNum, positionNum);
                                                       conn.api(`sofia_count_reg internal/${destinationAlias}@${data['variable_domain_name']}`, ({ body }) => {
                                                            console.log(`This is called here external-transfer!💡`);
                                                            if(Number(body) > 0) return;
                                                            console.log(`This is called here external-transfer!💡💡`);
                                                            if(callControlTimeOutDestination.length){
                                                                 conn.api(`sched_transfer +30 ${data['Unique-ID']} ${callControlTimeOutDestination[0]} XML ${data['variable_domain_name']}`);
                                                            }else{
                                                                 console.log("no answer 1")
                                                                 console.log(`sched_api 4 +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`)
                                                                 conn.api(`sched_api +30 none uuid_setvar ${data['Unique-ID']} my_hangup_cause NO_ANSWER`);
                                                                 conn.api(`sched_transfer +30 ${data['Unique-ID']} *99${data['Caller-Destination-Number']} XML ${data['variable_domain_name']}`);
                                                            }
                                                       });
                                                  }
                                             }
                                             /** PUSH */
                                             const presence = await presenceId(ev.getHeader('Channel-Name'));    
                                             const payload = {
                                                  domain: data['variable_domain_name'],
                                                  extension: data['Caller-Destination-Number'],
                                                  time: data['Event-Date-Timestamp'],
                                                  id: data['Unique-ID'], // uuidv4(), -- 09-29-26 EMAN MODIFIED
                                                  call: data['Unique-ID'],
                                                  caller_id: presence,
                                                  nameCaller: presence, 
                                                  time2: data['Event-Date-Timestamp']
                                             };
                                             console.log("before getTokenIos 2");
                                             getTokenIOS(payload);
                                             getTokenMultipleDevice(payload);
                                             getToken(payload);
                                             // pushWeb(payload);

                                        }
                                        
                                   }
                              }
                         }
                    })  
               }

          }

     }
}

const handleAPI = async (ev, bd, conn) => {
     if(
          ev.getHeader('API-Command') === 'uuid_transfer' &&
          ev.getHeader('API-Command-Argument')
     ){
          const transferUuid = ev.getHeader('API-Command-Argument')?.split(' ')[0];
          const uuid = transferUuid.trim();
          const res2 = await promisifiedApi(`uuid_dump ${uuid} JSON`);
          if(res2.body !== '-ERR No such channel!\n'){
               console.log("*99 1")
               const data = JSON.parse(res2.body);
               if(data){
                      console.log("*99 2")
                    if(!data['Caller-Destination-Number'].includes('*99')) return;

                    if(
                         data['Caller-Destination-Number'].includes('*99') &&
                         data['variable_callFeatures'] === 'Attended_Transfer'
                    ){
                         setTimeout( async () => {
                              
                              const callerNum = data['variable_effective_caller_id_number'];
                              const subDomain = data['variable_domain_name'];
                              const attendedPresence = await redisClient.get(`attendedSent-${callerNum}@${subDomain}`);
                              const attendedProcess = await redisClient.get(`attendedProcess-${callerNum}@${subDomain}`);

                              if(attendedPresence && !attendedProcess){
                                   console.log("not yet processed")
                                   const { parkCall , transferCall} = JSON.parse(attendedPresence);
                                   conn.api(`uuid_exists ${parkCall}`, (res) => {
                                        if(res.body === 'true'){

                                             conn.api(`uuid_bridge ${parkCall} ${transferCall}`); // [att-retry] caller first, so a retry after *99 holds the caller
                                             redisClient.del(`attendedSent-${callerNum}@${subDomain}`);
                                             socketClient.emit(`declineAttendedTransfer`, 
                                                  `${data['variable_dialed_user']}@${data['variable_dialed_domain']}`
                                             )

                                        }else{
                                             redisClient.del(`attendedSent-${callerNum}@${subDomain}`)
                                        }
                                   })
                              }

                         }, 500)
                    }
               }
          }    
     }     
}

const handleRecordStart = async (ev, bd, conn) => {
     redisClient.set("uuid_audio-" + ev.getHeader('Channel-Call-UUID'), 1);
}

const handleRecordStop = async (ev, bd, conn) => {
    redisClient.set("uuid_audio-" + ev.getHeader('Channel-Call-UUID'), 2);
}

const handleChannelState = async (ev, bd, conn) => {
     conn.api(`uuid_dump ${ev.getHeader('Channel-Call-UUID')} JSON`, async (res) => {
          if(res.body != '-ERR No such channel!\n'){
               const {
                    variable_effective_caller_id_number,
                    "Caller-Destination-Number": callerDestinationNumber,
                    "Caller-Context": subdomain,
                    "Channel-Call-UUID": callId,
                    "Event-Date-Timestamp": timeNow
                    } = JSON.parse(res.body);

               if(variable_effective_caller_id_number == 100000){
                    const { callerName, callerEmail } = await redisClient.hGetAll(`click2call-${callerDestinationNumber}-${subdomain}`);

                    const result = await new Promise((resolve, reject) => {

                         let callerIdValue = '';
                         if (callerName && callerEmail) {
                              callerIdValue = `${callerName}-${callerEmail} (Click2Talk)`;
                         } else if (callerName) {
                              callerIdValue = `(Click2Talk) ${callerName}`;
                         } else if (callerEmail) {
                              callerIdValue = `(Click2Talk) ${callerEmail}`;
                         } else {
                              callerIdValue = ' ';
                         }
                         
                         conn.api(`uuid_setvar ${callId} effective_caller_id_number '${callerIdValue}'`, (res) => {
                              if (res.body !== '+OK\n') {
                                   console.error('Error setting effective_caller_id_number:', res.body);
                                   reject(new Error(res.body)); // [audit] 'err' was undefined
                              } else {
                                   resolve(callerIdValue);
                              }
                         });

                    });

                    if(result){
                         const payload = {
                              domain: subdomain,
                              extension: callerDestinationNumber,
                              time: timeNow,
                              id: uuidv4(),
                              call: callId,
                              caller_id: variable_effective_caller_id_number,
                              nameCaller: result,
                         };
                         
                         /** CLick to Talk **/
                         internalPush2(payload);
                    }

               }
          }
     })
}

const handlePresenceIn = async (ev, bd, conn) => {

      if(ev.getHeader("Channel-Call-State") == "RINGING" && ev.getHeader("Answer-State") == "ringing" && ev.getHeader("event_count") == "0"){
          let host = ev.getHeader('Caller-Destination-Number')+"@"+ev.getHeader('Caller-Context');
          let host1 = ev.getHeader('Caller-Orig-Caller-ID-Number')+"@"+ev.getHeader('Caller-Context');
          internalCall(ev.getHeader('Channel-Call-UUID'), host, host1);
     }

     if (ev.getHeader("Channel-Call-State") == "DOWN" && ev.getHeader("Answer-State") == "ringing") {
          if(ev.getHeader("Channel-Presence-ID")){
               if(ev.getHeader("Channel-Presence-ID").includes('@')){
                    socketClient.emit("ongoing-call", { "channel-ongoing-call": ev.getHeader("Channel-Presence-ID"), "caller-context": ev.getHeader("Caller-Context"), "caller-unique-id": ev.getHeader("Caller-Unique-ID") });           
               }
          }              
     } 
}

const handleAnswer = async (ev, bd, conn) => {



     if(ev.getHeader('Call-Direction') === 'outbound'){
          console.log('AVMD started on channel:', util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true }));
          // conn.execute('set', 'enforce_vbead=true', ev.getHeader('Unique-ID'));
          // conn.execute('avmd', 'start', ev.getHeader('Unique-ID'));
          // conn.execute('avmd', 'start inbound', ev.getHeader('Unique-ID'));

          if(ev.getHeader('Caller-Caller-ID-Name') == "Take-Over"){
               console.log("this is the transferrr");
               console.log("chck the takeover");
               // conn.bgapi(`uuid_setvar ${legA} hangup_after_bridge false`);
               // conn.bgapi(`uuid_setvar ${legB} hangup_after_bridge false`);
               // // Remove B from the existing A-B bridge but keep B alive.
               // conn.bgapi(`uuid_transfer ${legB} park inline`);
               // // C should also be free/parked before bridging.
               // conn.bgapi(`uuid_transfer ${legC} park inline`);
               // conn.bgapi(`uuid_bridge ${legA} ${legC}`);
          }

     }

     const value = await fsRegistration();
     const channelExactName = await presenceId(ev.getHeader('Caller-Channel-Name'));
     const domain = ev.getHeader('variable_domain_name') || null

     const extensionId = async (ext) => {
          const findCurrentPresence = value.find(x => regMatch(x, ext));
          if(findCurrentPresence){
               const { "Auth-User": authUser , "Auth-Realm": authRealm} = findCurrentPresence;
               const { rows } = await db2.query(
               "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
               [authUser, authRealm]
               );
               return rows.length ? rows[0].extension : ext;
          }else{
               const { rows } = await db2.query(
               "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
               [ext, domain]
               );
               return rows.length ? rows[0].extension : ext;
          }
     }

     const presenceAnswered = await extensionId(channelExactName)
     if(presenceAnswered){
          await redisClient.set(`answered-presence-${presenceAnswered}@${domain}`, ev.getHeader('Unique-ID'));
          await redisClient.set(`answered-active-${presenceAnswered}@${domain}`, ev.getHeader('Unique-ID'));
     }

     if(
          ev.getHeader('Event-Calling-Function') == 'switch_channel_perform_mark_answered'
     ){

          console.log('🇨🇭INTERNAL CALLS RECORDING🇨🇭')

          /** RECORDING START */
          let newNumber = Number(String(ev.getHeader('Event-Date-Timestamp')).slice(0, -3));
          let timeStamp= parseInt(newNumber);
          let options = { month: "short", day: "2-digit", year: "numeric" };
          let dateEvent = new Date(timeStamp).toLocaleDateString('en-US', options).replace(/ /g, '/');
          let cleanedDate = dateEvent.replace(/,/g, '');
          let arrDate = cleanedDate?.split("/");
          if(ev.getHeader('Channel-Presence-ID')){

               const value = await fsRegistration();
               const channelExactName = await presenceId(ev.getHeader('Caller-Channel-Name'));
               const domain = ev.getHeader('variable_domain_name');

               const extensionId = async (ext) => {
                    const findCurrentPresence = value.find(x => regMatch(x, ext));
                    if(findCurrentPresence){
                         const { "Auth-User": authUser , "Auth-Realm": authRealm} = findCurrentPresence;
                         const { rows } = await db2.query(
                         "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                         [authUser, authRealm]
                         );
                         return rows.length ? {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUUID: rows[0].extension_uuid} : {cid_name: ext, cid_num: ext};
                    }else{
                         const { rows } = await db2.query(
                         "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                         [ext, domain]
                         );
                         return rows.length ? {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUUID: rows[0].extension_uuid} : {cid_name: ext, cid_num: ext};
                    }
                  
               }

               const callRec = await extensionId(channelExactName)
               if(callRec){
                    const { cid_name, cid_num, extensionUUID } = callRec;
                    if(
                         classifyNumber(Number(cid_num)) === 'InternalExtension'
                    ){
                         db2.query('SELECT extension, user_context, user_record FROM v_extensions WHERE user_context = $1 AND extension = $2', [domain, cid_num]).then( async (res) => {
                              console.log(`🇨🇭`, res);


                              if(res.rows.length > 0){
                                   console.log(`🇨🇭🇨🇭`, res.rows[0].user_record);

                                   const uuidRecordId = ev.getHeader('variable_originate_signal_bond') || ev.getHeader('Unique-ID')

                                   // A queue's own Call Recording setting wins over the extension's:
                                   // "1" always records, "2" never does, unset falls back to user_record.
                                   const ccQueue = ev.getHeader('variable_cc_queue');
                                   const queueRecord = ccQueue ? await redisClient.get(`${ccQueue}-queueRecord`) : null;
                                   const extensionRecords = res.rows[0].user_record == 'All' || res.rows[0].user_record == 'Inbound' || res.rows[0].user_record == 'Outbound';

                                   if(queueRecord === '1' || (queueRecord !== '2' && extensionRecords)){
                                        const exists = await fileExists(`/var/lib/freeswitch/recordings/${domain}/archive/${arrDate[2]}/${arrDate[0]}/${arrDate[1]}/`, `${uuidRecordId}.wav`);
                                        console.log(`🇨🇭🇨🇭🇨🇭`, exists);
                                        if(exists) return
                                        conn.api("uuid_record "+ uuidRecordId +" start /var/lib/freeswitch/recordings/"+ domain +"/archive/"+arrDate[2]+"/"+arrDate[0]+"/"+arrDate[1]+"/"+ uuidRecordId +".wav");
                                        console.log('🇨🇭🇨🇭🇨🇭🇨🇭', "uuid_record "+ uuidRecordId +" start /var/lib/freeswitch/recordings/"+ domain +"/archive/"+arrDate[2]+"/"+arrDate[0]+"/"+arrDate[1]+"/"+ uuidRecordId +".wav")
                                   }
                              }
                         })
                    }
               }                             
          }        

     }
     
     if(ev.getHeader('Channel-Name').includes("internal")){
          const channelAlias = ev.getHeader('Channel-Presence-ID')?.split('@')[0];
          const channelExactName = await presenceId(ev.getHeader('Channel-Name'));
          if(channelExactName){
               const { rows } = await db2.query(
               `
               SELECT EXISTS (
               SELECT 1
               FROM v_extensions
               WHERE number_alias = $1
                    AND accountcode = $2
               ) AS exists
               `,
               [channelExactName, domain]
               );

               const exists = rows[0].exists;
               if (exists) {
                    await redisClient.set(`${ev.getHeader('variable_domain_name')}:answered:${channelExactName}`, ev.getHeader('Unique-ID'));
               }else{
                    if(channelAlias){
                         await redisClient.set(`${ev.getHeader('variable_domain_name')}:answered:${channelAlias}`, ev.getHeader('Unique-ID'));
                    }
               }
          }

                             // EMAN ADDED 08-14-26
          // [ring-push] A leg mod_ringq dialled is bridged by mod_ringq itself as soon as it is
          // answered. Force-bridging it here as well raced that bridge: the module's dial was
          // cancelled (LOSE_RACE) and the call had no audio and no ringback.
          const res = ev.getHeader('variable_ringq_ring_id') ? null : await promisifiedApi('show calls as json');
          const { row_count, rows } = res ? JSON.parse(res.body) : { row_count: 0, rows: [] };
          if (row_count) {
              // 1. Fully bridged active calls
              const activeCall = rows.filter((x) => 
                  x.callstate === 'ACTIVE' && x.b_callstate === 'ACTIVE'
              );
              const filterCall = await internalActive(activeCall);
              if (filterCall) {
                  // Handle normal active calls logic
              }
              // 2. Find unbridged call pairs (missing b_uuid) with ACTIVE outbound and EARLY inbound
              const unbridgedInbound = rows.find((x) => 
                  x.direction === 'inbound' && 
                  x.callstate === 'EARLY' && 
                  (!x.b_uuid || x.b_uuid === '')
              );
              const unbridgedOutbound = rows.find((x) => 
                  x.direction === 'outbound' && 
                  x.callstate === 'ACTIVE' && 
                  (!x.b_uuid || x.b_uuid === '')
              );
              // Ensure both legs belong to the same call (outbound leg's call_uuid matches inbound leg's uuid)
              if (
                  unbridgedInbound && 
                  unbridgedOutbound && 
                  unbridgedOutbound.call_uuid === unbridgedInbound.uuid
              ) {
                  const aLegUuid = unbridgedInbound.uuid;
                  const bLegUuid = unbridgedOutbound.uuid;

                  // Force-bridge the two channels via FreeSWITCH ESL
                  await promisifiedApi(`uuid_bridge ${aLegUuid} ${bLegUuid}`);
              }
          }
          // EMAN ADDED 08-14-26



         
         
     }    

     console.log(`✅${ev.getHeader('Event-Name')}`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );

     console.log("this is the EV", ev);
     const conferenceNumberCheck = ev.getHeader('Caller-Callee-ID-Number');

//      251|server      |   'Caller-Caller-ID-Name': '1002',
// 251|server      |   'Caller-Caller-ID-Number': 'f6e66649f054f5071333',
// 251|server      |   'Caller-Orig-Caller-ID-Name': '1002',
//variable_extension_caller_id_name: 'Se Phone RingQ',
     if(ev.getHeader('variable_extension_caller_id_name')){
          console.log("this is your name", `getOriginateName-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('Caller-Context')}`, ev.getHeader('variable_extension_caller_id_name'))
          redisClient.set(`getOriginateName-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('Caller-Context')}`, ev.getHeader('variable_extension_caller_id_name'))
     }
     

     // 0994
     if(conferenceNumberCheck && ev.getHeader('Caller-Orig-Caller-ID-Name') === "Conference" ){
          console.log("pasokzz")
          const redisConferenceNumber = await redisClient.get(`conferenceEavesdropValue@${conferenceNumberCheck}`);
          
          if(conferenceNumberCheck.length !== 4){
               console.log("4 digits")
               if(redisConferenceNumber){         
               console.log("this is break uuid only for conference external? //e.g cloudpinas 099", redisConferenceNumber);
               conn.api(`uuid_exists ${redisConferenceNumber}`, (res) => {
                    if(res.body === 'true'){
                    conn.api(`uuid_break ${redisConferenceNumber}`);
                    redisClient.del(`conferenceEavesdropValue@${conferenceNumberCheck}`)
                    }
                    else{
                         console.log("not existing external");
                    }
                 })
               }
               else{
               console.log("o dito yung internal")
               console.log("more 4 digits")
               const redisConferenceNumber1 = await redisClient.get(`conferenceEavesdropValue@${ev.getHeader('Caller-Caller-ID-Number')}`)
              
                    conn.api(`uuid_exists ${redisConferenceNumber1}`, (res) => {
                    if(res.body === 'true'){
                         console.log("must go here for internal");
                         conn.api(`uuid_break ${redisConferenceNumber1}`);
                         console.log("this is it", `conferenceEavesdropValue@${ev.getHeader('Caller-Caller-ID-Number')}`)
                         redisClient.del(`conferenceEavesdropValue@${ev.getHeader('Caller-Caller-ID-Number')}`);
                    }
                    else{
                         console.log("this is break uuid only for conference external? //e.g 3cx 6563", redisConferenceNumber);
                         conn.api(`uuid_exists ${redisConferenceNumber}`, (res) => {
                         if(res.body === 'true'){
                              conn.api(`uuid_break ${redisConferenceNumber}`);
                              redisClient.del(`conferenceEavesdropValue@${conferenceNumberCheck}`)
                         }
                         else{
                              console.log("not existing external or internal");
                           }
                         })
                    }
                 })
               }
          
          
          }
          else {
               console.log("dito ba internal o hindi?")
               //This is for internal
               const ValueInternalConverter = ev.getHeader('Caller-Destination-Number');
               const getInternalEavesdropValue = await redisClient.get(`conferenceEavesdropValue@${ValueInternalConverter}`)
               
               conn.api(`uuid_exists ${getInternalEavesdropValue}`, (res) => {
               if(res.body === 'true'){
               console.log("this is gumagana");
               conn.api(`uuid_break ${getInternalEavesdropValue}`);
               redisClient.del(`conferenceEavesdropValue@${ValueInternalConverter}`);
               }
               else{
                    console.log("not gumagana")
               }
               })

          }
     }
    

     showcallsEventRefresh(domain);

     if(ev.getHeader('variable_current_application_data') !== 'app.lua voicemail'){
          console.log("sched_del 1", `${ev.getHeader('Channel-Call-UUID')}`)
          conn.api(`sched_del ${ev.getHeader('Channel-Call-UUID')}`);
     }

     if(ev.getHeader("Channel-Call-State") == "EARLY" && ev.getHeader('Channel-State') == 'CS_CONSUME_MEDIA'){
          console.log("handleanswer with consume media");
          const orig_uuid = ev.getHeader('Other-Leg-Unique-ID') || "";
          const uniqueID = ev.getHeader('Caller-Unique-ID');
          const callerExt = ev.getHeader('Caller-Caller-ID-Number');
          const calleExt = ev.getHeader('Caller-Destination-Number');
          const callerName = ev.getHeader('Caller-Caller-ID-Name');
          conferenceUUID(orig_uuid, uniqueID, callerExt, calleExt, domain, callerName);
     }

     if(ev.getHeader('variable_current_application') === 'bridge'){
          const attendedActive = await redisClient.exists(`attendedSent-${ev.getHeader('Caller-Caller-ID-Number')}@${domain}`);
          if(attendedActive){
               redisClient.del(`attendedSent-${ev.getHeader('Caller-Caller-ID-Number')}@${domain}`);
          }
     }

     if(ev.getHeader('variable_sip_from_display') == 'Conference'){
          console.log("GOT HERE?", ev.getHeader('Channel-Presence-ID'));
          if(ev.getHeader('Channel-Presence-ID')){
               if(ev.getHeader('Channel-Presence-ID').includes('@')){
                    const [extension, domain] = ev.getHeader('Channel-Presence-ID')?.split('@')
                    const variable_domain = ev.getHeader('variable_domain_name');
                    socketClient.emit('conferenceExtensionAnswer', {extension:extension, domain})
               }
          }
          else {
               if(ev.getHeader('variable_sip_destination_url')){
                    
                    // const extension = ev.getHeader('Caller-Destination-Number');
                    const sipDestinationUrl = ev.getHeader('variable_sip_destination_url');
                    const [userPart, hostPart] = sipDestinationUrl.replace(/^sip:/, "").split("@");
                    const match = sipDestinationUrl.match(
                    /^sip:([^@]+)@(?:[^.]+\.)?([^.]+)\.[^:]+(?::\d+)?$/);
                    const fullNumber = match[1];
                    const provider = hostPart.split(":")[0].split(".")[1];
                    // console.log(numberPrefix); // 0065691
                    console.log(provider);
                    console.log("Check before retrieve", `retrieveConferenceModified@${fullNumber}`);
                    const originalNumber = await redisClient.get(`retrieveConferenceModified@${fullNumber}`);
                    const variable_domain = ev.getHeader('variable_domain_name');
                    // if(!caller.length !== 4) return
                    // const caller = ev.getHeader('Caller-Caller-ID-Number');
                    // Caller-Caller-ID-Number
                    socketClient.emit('conferenceExtensionAnswer', {extension: originalNumber, domain:variable_domain});

                    
               }
          }
     }

     console.log("must go here first answer takeover", ev.getHeader("Channel-Presence-ID"), ev.getHeader("variable_cc_queue"));

    // 'Caller-Destination-Number'@'Caller-Context'
     const variableCc = ev.getHeader("variable_cc_queue") || ev.getHeader('Caller-RDNIS')+'@'+ ev.getHeader('Caller-Context') || "9005@multi1.flowersconradplc.ringq.cloud";

     console.log("this is variableCc", variableCc);
     if(ev.getHeader("Channel-Presence-ID") && variableCc){

          console.log("this is the channel presence", ev.getHeader("Channel-Presence-ID"), variableCc)
          //Added 09-14-26
          if(ev.getHeader("Channel-Presence-ID").includes('@')){
               let array = variableCc?.split("@");
               let array2 = ev.getHeader("Channel-Presence-ID")?.split("@");
               console.log("go 0")
               if (classifyNumber(Number(array[0])) === 'Queue') {
                    console.log("go 1")
                    unique_uuidAnswer_Queue({
                         uuid: ev.getHeader("Channel-Call-UUID"), 
                         extension: array2[0], 
                         domain: array2[1], 
                         date: ev.getHeader('Event-Date-Timestamp')
                    });
                    console.log("go 2");
                    callId(
                         ev.getHeader("Channel-Call-UUID"), 
                         variableCc
                    );

               }
          }
          ////Added 09-14-26

     }

     if(ev.getHeader('Call-Direction') == 'inbound'){

               if(
                    ev.getHeader('Caller-Destination-Number').includes('*99') || 
                    ev.getHeader('variable_current_application_data') === 'app.lua voicemail'
               ){
                    conn.api(`uuid_dump ${ev.getHeader('Unique-ID')} JSON`, (res) => {

                         const { body } = res;
                         if(body === '-ERR No such channel!\n') return
                         const dataParse =  JSON.parse(body);
                         const callNum = dataParse['Caller-Destination-Number'];
                         const destination = callNum.includes('*99') ? callNum.split('*99')[1] : callNum;
                         console.log("sched_del 2", `${ev.getHeader('Channel-Call-UUID')}`)
                         conn.api(`sched_del ${ev.getHeader('Unique-ID')}`);

                      
                         if(ev.getHeader('variable_current_application_data') !== 'app.lua voicemail'){
                               const payload = {
                                   domain: dataParse['variable_domain_name'],
                                   extension: destination,
                                   time: dataParse['Event-Date-Timestamp'],
                                   id: uuidv4(),
                                   call: "decline",
                                   caller_id: ev.getHeader("variable_effective_caller_id_number") || ev.getHeader("Caller-Caller-ID-Number"),
                                   nameCaller: ev.getHeader("variable_effective_caller_id_name") || ev.getHeader("Caller-Caller-ID-Name"),
                                   time2: ev.getHeader("Event-Date-Timestamp")
                              }
                                 console.log("before getTokenIos 0");
                              getToken(payload);
                              // getTokenIOS(payload);
                              getTokenMultipleDevice(payload);
                              console.log(`UUID_DUMP`, dataParse);
                         }
                         
                    });

               }
               
     }

     if(ev.getHeader('Call-Direction') == 'outbound'){

          if(ev.getHeader('Channel-Name') && ev.getHeader('Channel-Name').includes('internal')){

                    setTimeout( async() => {
                         await redisClient.set(`channel-${ev.getHeader('Channel-Presence-ID')}`, 
                         ev.getHeader('variable_uuid'));
                    }, 2000);

                    const sipPresence = await sipProfileURL(ev.getHeader('Channel-Name'));
                    const deviceId = await redisClient.get(`${sipPresence}-IOS-deviceId`);
                    if(deviceId){
                         const devicePushToken = await redisClient.get(`devicePushtoken-${deviceId}`);
                         if(devicePushToken){

                              const channelPresence = ev.getHeader('Channel-Presence-ID')?.split('@')[0];
                              const extensionFunction = async (alias) => {
                                   const checkValid = isValidNumber(alias)
                                   if(!checkValid){
                                        const { rows } = await db2.query(
                                             `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                             [alias, ev.getHeader('variable_domain_name')]
                                        );
                                        if(rows.length){
                                             return rows[0].extension;
                                        }
                                   }
                                   return alias
                              }
                              const extensionOrig = await extensionFunction(channelPresence);

                              console.log('This is active CALL ACTIVE CALL 🔥');
                              await redisClient.sAdd(`activeCall-${extensionOrig}@${ev.getHeader('variable_domain_name')}`, devicePushToken);
                         }
                    }

          }

     }





     if(ev.getHeader('variable_park_pickup')){
          if(
               classifyNumber(Number(String(ev.getHeader('Channel-Presence-ID') || '').split('@')[0])) === 'InternalExtension' // [audit] channelPresence was out of scope
          ){
               socketClient.emit("parkAnswer", 
                    ev.getHeader('Channel-Presence-ID')
               );
          }  
     }

}


// [queue-callback] Caller pressed 0 then * in a queue: ring the queue's AVAILABLE agents, and when
// one answers, call the customer back from the queue number. Fixes: agents that were not available
// became "user/undefined" in the dial string; with nobody available the request was lost; outside
// callers were dialled through variable_sip_gateway, which is empty on calls that came in from a
// trunk (-> sofia/gateway/undefined). Now: a registered gateway from the outbound rules, available
// agents only, and a retry every 30 s for up to 10 minutes while nobody is free.
async function queueCallbackGateway(conn, domainUuid, preferred) {
     const body = await new Promise((resolve) => conn.api(`sofia status gateway`, (res) => resolve((res && res.body) || '')));
     const lines = String(body).split('\n');
     const reged = [];
     for (const line of lines) {
          const m = line.trim().match(/^(?:external::)?([^\s]+)\s+(\S+)\s+(REGED|NOREG|UP)\b/);
          if (m) reged.push({ id: m[1], sip: m[2], state: m[3] });
     }
     if (preferred && reged.some((g) => g.id === preferred)) return preferred;
     const { rows } = await db2.query(
          `SELECT action FROM q_outbound_rules WHERE domain_uuid = $1 AND priority IS NOT NULL ORDER BY dial_plan_order ASC`,
          [domainUuid]);
     for (const row of rows) {
          const match = reged.find((gw) => row.action && (gw.sip.includes(row.action) || gw.id === row.action));
          if (match) return match.id;
     }
     return preferred || (reged[0] && reged[0].id) || '';
}

async function queueCallback(conn, { queue, queueExtension, domain, domainUuid, number, external, gateway }, attempt = 1) {
     const MAX_ATTEMPTS = 20, RETRY_MS = 30000;
     const agents = await new Promise((resolve) => {
          conn.api(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${queue}"}}`, (res) => {
               try {
                    const { status, response } = JSON.parse(res.body);
                    if (status !== 'success' || !Array.isArray(response)) return resolve([]);
                    resolve(response
                         .filter(({ status: st, state }) => st === 'Available' && (state === 'Waiting' || state === 'Receiving'))
                         .map(({ contact }) => String(contact || '').split('user/')[1])
                         .filter(Boolean));
               } catch (e) { resolve([]); }
          });
     });
     if (!agents.length) {
          if (attempt >= MAX_ATTEMPTS) { console.log('[queue-callback] gave up, no agent available', queue, number); return; }
          console.log('[queue-callback] no agent available, retrying in 30 s', queue, number, `attempt ${attempt}`);
          setTimeout(() => queueCallback(conn, { queue, queueExtension, domain, domainUuid, number, external, gateway }, attempt + 1)
               .catch((e) => console.log('[queue-callback]', e && e.message)), RETRY_MS);
          return;
     }
     let target;
     if (external) {
          const gw = await queueCallbackGateway(conn, domainUuid, gateway);
          if (!gw) { console.log('[queue-callback] no registered gateway', queue, number); return; }
          target = `sofia/gateway/${gw}/${number}`;
     } else {
          target = `user/${number}@${domain}`;
     }
     const dialString = agents.map((a) => `user/${a}`).join(',');
     const vars = `originate_timeout=60,ignore_early_media=true,origination_caller_id_name='Callback ${number}',origination_caller_id_number=${number},toll_allow=local,absolute_codec_string=PCMU,PCMA,G722,OPUS,return_ring_ready=true`;
     const cmd = `originate {${vars}}${dialString} &bridge({origination_caller_id_number=${queueExtension}}${target})`;
     console.log('[queue-callback] placing', cmd);
     conn.bgapi(cmd);
}

const handleDTMF = async (ev, bd, conn) => {
     console.log("✅️ DTMF", 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );
     const destination = ev.getHeader('Caller-Destination-Number');
     if(Number(destination) >= 6000 && Number(ev.getHeader('Caller-Destination-Number')) < 7000){
          console.log("sched_del 3 ", `${ev.getHeader('Unique-ID')}`)
          conn.api(`sched_del ${ev.getHeader('Unique-ID')}`);
     }

     const callInfo = await new Promise((resolve, reject) => {

          try {
               conn.api(`uuid_dump ${ev.getHeader('Unique-ID')} JSON`, (res) => {
                    const { body } = res
                    if(body === '-ERR No such channel!\n') resolve(null);  
                    resolve(JSON.parse(res.body));
               })   
          } catch (error) {
               reject(error);    
          }

     });

     if(callInfo){
          console.log(`CALL INFO DTMF`, callInfo);
          if(callInfo['variable_cc_queue']){

               const callBackEnabled = await redisClient.get(`${callInfo['variable_cc_queue']}-callBack`)
               if(callBackEnabled){

                    const currentPresenceExtension = async () => {
                         if(ev.getHeader('Channel-Name').includes('internal')){

                              const presenceExt = await presenceId(ev.getHeader('Channel-Name'));
                              const extensionFunction = async (presence) => {
                                   const { rows } = await db2.query(
                                        `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                        [presence, callInfo['variable_domain_name']]
                                   );
                                   if(rows.length){
                                        return rows[0].extension; 
                                   }
                                   return presence
                              }
                              return  await extensionFunction(presenceExt);
                              
                         }

                         if(!ev.getHeader('Channel-Name').includes('internal')){
                              return await presenceId(ev.getHeader('Channel-Name'));
                         }
                    }
                    const currentPresence = await currentPresenceExtension();

                    const customActive = await redisClient.exists(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`);
                    if(customActive){
                         const notValid = ['*', '#'];
                         if(!notValid.includes(ev.getHeader("DTMF-Digit"))){
                              await redisClient.append(`dtmfCustom:${ev.getHeader('Unique-ID')}`, ev.getHeader("DTMF-Digit"));
                         }
                         
                         if(ev.getHeader("DTMF-Digit") == '#'){
                              const customNumber = await redisClient.get(`dtmfCustom:${ev.getHeader('Unique-ID')}`);
                              console.log(`🩸`, customNumber);
                              if(!customNumber){
                                   throw new Error('No Custom Number!');
                              }
                              conn.api(`uuid_break ${ev.getHeader('Unique-ID')}`);
                              await new Promise(resolve => setTimeout(resolve, 500));
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/you_have_requested_a.wav aleg`);
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} say::en\\snumber\\siterated\\s${customNumber} aleg`);
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/press_star_to_confirm.wav aleg`);
                              await redisClient.del(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`);
                              return
                         }
                    }

                    if(ev.getHeader("DTMF-Digit") == 0){

                         const customActive =  await redisClient.exists(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`);
                         if(customActive) return

                         const activeCallBack = await redisClient.exists(`callBackActive-${ev.getHeader('Unique-ID')}`);
                         if(!activeCallBack){
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/you_have_requested_a.wav aleg`);
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} say::en\\snumber\\siterated\\s${currentPresence} aleg`);
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/press_star_to_confirm.wav aleg`);
                              await redisClient.set(`callBackActive-${ev.getHeader('Unique-ID')}`, 1);
                              return
                         }
                         conn.api(`uuid_break ${ev.getHeader('Unique-ID')}`);
                         await new Promise(resolve => setTimeout(resolve, 500));
                         conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/canceled.wav`);
                         await redisClient.del(`callBackActive-${ev.getHeader('Unique-ID')}`);
                         await redisClient.del(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`);
                         await redisClient.del(`dtmfCustom:${ev.getHeader('Unique-ID')}`);
                         return
                    }

                    if(ev.getHeader("DTMF-Digit") == '*'){

                         const customActive =  await redisClient.exists(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`);
                         if(customActive) return

                         const activeCallBack = await redisClient.exists(`callBackActive-${ev.getHeader('Unique-ID')}`);
                         if(activeCallBack){

                              conn.api(`uuid_break ${ev.getHeader('Unique-ID')}`);
                              await new Promise(resolve => setTimeout(resolve, 500));
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/contact_you_shortly.wav`);
                              await new Promise(resolve => setTimeout(resolve, 3000));
                              const value = await fsRegistration();

                              conn.api(`uuid_kill ${ev.getHeader('Unique-ID')}`);

                              const destinationCallback = await redisClient.get(`dtmfCustom:${ev.getHeader('Unique-ID')}`) || currentPresence;
                              const destinationCallbackFiltered = destinationCallback.replace(/\+(?=\d)/g, "");
                              const queueExtension = callInfo['variable_cc_queue'].split('@')[0]

                              // [queue-callback] one path for outside and internal callers (see queueCallback)
                              const isExternal = Number(String(destinationCallbackFiltered).replace(/\D/g, '')) > 10000;
                              queueCallback(conn, {
                                   queue: callInfo['variable_cc_queue'],
                                   queueExtension,
                                   domain: callInfo['variable_domain_name'],
                                   domainUuid: callInfo['variable_domain_uuid'],
                                   number: destinationCallbackFiltered,
                                   external: isExternal,
                                   gateway: callInfo['variable_sip_gateway'] || callInfo['variable_sip_gateway_name'] || '',
                              }).catch((e) => console.log('[queue-callback]', e && e.message));
                              console.log(`DTMF VAL`, {currentPresence, value});

                         }

                    }

                    if(ev.getHeader("DTMF-Digit") == '#'){
                         const customCallBackEnabled = await redisClient.exists(`${callInfo['variable_cc_queue']}-callBackCustom`);
                         if(!customCallBackEnabled) return;

                         const customActive =  await redisClient.exists(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`);
                         if(customActive) return
                         const activeCallBack = await redisClient.exists(`callBackActive-${ev.getHeader('Unique-ID')}`);
                         if(activeCallBack){
                              conn.api(`uuid_break ${ev.getHeader('Unique-ID')}`);
                              await new Promise(resolve => setTimeout(resolve, 500));
                              conn.api(`uuid_broadcast ${ev.getHeader('Unique-ID')} misc/please_enter_number.wav aleg`);
                              await redisClient.del(`dtmfCustom:${ev.getHeader('Unique-ID')}`);
                              await redisClient.set(`callBackActiveCustom-${ev.getHeader('Unique-ID')}`, 1);
                              return
                         }
                    }       

               }

          }
     }

     const ivrNumber = ev.getHeader("Other-Leg-Orig-Caller-ID-Number") || ev.getHeader('Caller-Destination-Number')
     if(classifyNumber(Number(ivrNumber)) === 'SpecialIVR'){
          console.log("SpecialIVP only");
          if(
               classifyNumber(Number(ev.getHeader('Caller-RDNIS')) === 'Queue')
          ){
               console.log("SpecialIVP only 1");
               if(ev.getHeader('DTMF-Digit') >= 1 && ev.getHeader('DTMF-Digit') < 6){
                    /** For queue call ratings **/
                    queueCallCenterAnswer(ev.getHeader('Channel-Call-UUID'), ev.getHeader('Caller-Context'), ev.getHeader('DTMF-Digit'))
               }
          }
     }

     if(Number(ev.getHeader('Caller-Destination-Number')) === 5555 &&
               ev.getHeader('Channel-Presence-ID') &&
               ev.getHeader('Channel-Presence-ID').includes('@')
     ){
          let subdomain = ev.getHeader('Channel-Presence-ID')?.split("@")[1];
          if(subdomain === ev.getHeader('Caller-Context')){
               conn.api(`uuid_break ${ev.getHeader('Channel-Call-UUID')}`);
          }
     }


}

const handlePark = async (ev, bd, conn) => {
     try {
          if(ev.getHeader('variable_originated_legs')){
               if(ev.getHeader('variable_originated_legs').length > 1){
                    let extChannel = ev.getHeader('Caller-Destination-Number')+'@'+ev.getHeader('Caller-Context');
                    redisClient.set(`attendedTransferPark:${extChannel}`, ev.getHeader('Channel-Call-UUID'))
               }
          }
     } catch (error) {
          console.error(error.message);
     }
}

const handleHangUp = async (ev, bd, conn) => {


     const callState = ev.getHeader("Channel-Call-State");
     const state = ev.getHeader("Channel-State");
     const hangUpCause = ev.getHeader("Hangup-Cause");
     const domain = ev.getHeader("variable_domain_name");

     console.log(`✅️ CHANNEL_HANGUP`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     ); 

     console.log({
     hangupCause: ev.getHeader("Hangup-Cause"),
     q850Cause: ev.getHeader("Hangup-Cause-Q850"),
     sipStatus: ev.getHeader("variable_sip_term_status"),
     sipPhrase: ev.getHeader("variable_sip_term_cause"),
     sipDisposition: ev.getHeader("variable_sip_hangup_disposition"),
     originateDisposition: ev.getHeader("variable_originate_disposition"),
     endpointDisposition: ev.getHeader("variable_endpoint_disposition"),
     });

     console.log("This is callstate", callState);

     if(callState == "DOWN"){
          if(hangUpCause == "NORMAL_TEMPORARY_FAILURE"){
               if(ev.getHeader('Other-Leg-Unique-ID')){
                    console.log("this is the caller unique id", ev.getHeader('Other-Leg-Unique-ID'));
                     conn.api(`uuid_exists ${ev.getHeader('Other-Leg-Unique-ID')}`, async ({body}) => {
                               console.log(`❄️BODY TEMP FAILURE❄️`, body);
                               if(body == 'true'){
                                    conn.api(`uuid_kill ${ev.getHeader('Other-Leg-Unique-ID')}`);
                                    console.log("killing uuid", ev.getHeader('Other-Leg-Unique-ID'));
                               }
                     });
                  // callcenter_config agent set state a305fb35-4193-434f-ae07-587a59ab5ff4 Waiting

                    // conn.api(`callcenter_config agent set state  Waiting`)
              
             }

             if(ev.getHeader('Unique-ID')){
                    console.log("this is the caller unique id", ev.getHeader('Other-Leg-Unique-ID'));
                     conn.api(`uuid_exists ${ev.getHeader('Other-Leg-Unique-ID')}`, async ({body}) => {
                               console.log(`❄️BODY TEMP FAILURE 2❄️`, body);
                               if(body == 'true'){
                                    conn.api(`uuid_kill ${ev.getHeader('Unique-ID')}`);
                                    console.log("killing uuid", ev.getHeader('Unique-ID'));
                               }
                         });

            const extension = ev.getHeader('Other-Leg-RDNIS')
            const getQueueAgentExtenstion = await redisClient.exists(`queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`);
            console.log("getting 2", `queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`)
            if(getQueueAgentExtenstion){
               redisClient.del(`queueCallCenterSetStatus-${extension}@${ev.getHeader('Channel-Presence-ID')}`);
                conn.api(`callcenter_config agent set state ${getQueueAgentExtenstion} Waiting`) 
                console.log("making this agent waiting after temp failure", `callcenter_config agent set state ${getQueueAgentExtenstion} Waiting`)
            }
          //    console.log('')
          //    conn.api(`callcenter_config agent set state  Waiting`) 
             }


                    
          }
     }

     if(callState == "RINGING"){

          if(hangUpCause == "CALL_REJECTED" || hangUpCause == "USER_BUSY"){
               if(ev.getHeader('variable_park_pickup') && ev.getHeader('Channel-Presence-ID')){
                    if(ev.getHeader('Channel-Presence-ID').includes('@')){
                         let ext = ev.getHeader('Channel-Presence-ID')?.split("@");
                         socketClient.emit("parkCancel2", {
                              uuid: ev.getHeader('Channel-Call-UUID'), 
                              extension:ext[0], 
                              extension2:ev.getHeader('Caller-Caller-ID-Number'), 
                              subdomain:ev.getHeader('Caller-Context')
                         });
                    }
               } 
          }
          
          if(hangUpCause == "CALL_REJECTED" && ev.getHeader('Call-Direction') === 'outbound'){
               console.log("KILL1");
               conn.api(`uuid_answer ${ev.getHeader('Channel-Call-UUID')}`)
               conn.api(`uuid_kill ${ev.getHeader('Channel-Call-UUID')} CALL_REJECTED`);
          }

          if(hangUpCause == "NO_ANSWER" && ev.getHeader('Call-Direction') === 'outbound'){
           const callId = ev.getHeader('Channel-Call-UUID');
          const savedMissedPush = await redisClient.get(`missedPayload-${callId}`);
          if (savedMissedPush) {
          const getMissedPush = JSON.parse(savedMissedPush);
          getMissedPush.call = "missed";
          console.log("Missed push payload:", getMissedPush);
          getToken(getMissedPush);
          }
          }
     }

 
     
     if(callState == "ACTIVE"){

          if(state == "CS_EXECUTE"){
               
               if(hangUpCause == 'NORMAL_CLEARING'){
                    if(ev.getHeader('Caller-Orig-Caller-ID-Name') == 'Conference'){
                         console.log("for conference");
                         if(ev.getHeader('Channel-Presence-ID') && ev.getHeader('Channel-Presence-ID').includes('@')){
                              console.log("this is for channel-presence", ev.getHeader('Channel-Presence-ID'), ev.getHeader('Channel-Presence-ID').includes('@'))
                              const [extension, context] = ev.getHeader('Channel-Presence-ID')?.split('@');
                              const isPureNumber = /^\d+$/.test(extension);
                              if (isPureNumber){               
                                   console.log("is pure number")
                                   socketClient.emit("conferenceExtensionRemove", ev.getHeader('Channel-Presence-ID'))
                              } else {
                                   console.log("isnt pure number");
                                   console.log(`extension-${ev.getHeader('Channel-Presence-ID')}`);
                                   const userPresence = await redisClient.get(`extension-${ev.getHeader('Channel-Presence-ID')}`);
                                   console.log(`extension-${ev.getHeader('Channel-Presence-ID')}`);
                                   if (userPresence) {
                                        const { extension } = JSON.parse(userPresence);
                                         console.log("userPresence", userPresence, "extension:", extension);
                                        if (`${extension}@${context}`) {
                                             console.log("whats this", `${extension}@${context}`);
                                             socketClient.emit("conferenceExtensionRemove", `${extension}@${context}`)
                                        }
                                   }
                              }
                              socketClient.emit("conferenceExtensionRemove", ev.getHeader('Channel-Presence-ID'))

                         }else{
                         console.log("this is for channel-presence 2")
                              if(ev.getHeader('variable_domain')){
                              
                                   let host = `${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('variable_domain')}`;
                                   console.log("conference check handle hangup")
                                   socketClient.emit("conferenceExtensionRemove", host)     

                              }else{
                                   console.log("conference check handle hangup2");
                                   console.log("is not pure number")
                                   const isPureNumber = /^\d+$/.test(ev.getHeader('Caller-Destination-Number'));
                                   if (isPureNumber) {
                                        let channel;
                                        const retrieveOriginalNumber = await redisClient.get(`retrieveConferenceModified@${ev.getHeader('Caller-Destination-Number')}`)
                                        console.log("is pure number", `retrieveConferenceModified@${'Caller-Destination-Number'}`, retrieveOriginalNumber)
                                        if(retrieveOriginalNumber){
                                             // for 3cx or any has prepend
                                           console.log("has orig num", retrieveOriginalNumber);
                                           channel = `${retrieveOriginalNumber}@${ev.getHeader('variable_domain_name')}`
                                        }
                                        else{
                                             // for external calls eg. 0998
                                          console.log("has no orig num", `${ev.getHeader('Caller-Destination-Number')}`);
                                          channel = `${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('variable_domain_name')}`
                                        }
                                        socketClient.emit("conferenceExtensionRemove", channel);

                                   } else {
                                        console.log("is not pure number")
                                        if(ev.getHeader('variable_sip_to_uri')){
                                             const channel = await redisClient.get(`contactSIP-${ev.getHeader('variable_sip_to_uri')}`);
                                             if(channel){
                                                  socketClient.emit("conferenceExtensionRemove", channel)  
                                             }
                                        }
                                   }

                              }
                                                  
                         }
                    }

                    if(ev.getHeader('variable_cc_member_uuid') && ev.getHeader('variable_cc_queue') && ev.getHeader('variable_cc_agent_push')){
                         console.log("this is for queue onlyy hangup", `${ev.getHeader('variable_cc_agent_push')}`);
                         
                         conn.api(`callcenter_config agent set state ${ev.getHeader('variable_cc_agent_push')} Waiting`);
                        
                    }
               }
          }

     }

     if(callState == "ACTIVE" && ev.getHeader('variable_ivr_menu_uuid')){
          //for push ivr only
          console.log("this is the push ivr only")
         const originateAbandon = `declinePayload-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('variable_domain_name')}`
         const abandonData = await redisClient.get(originateAbandon);
         if(!abandonData) return
          console.log("has data");
          const getParaedData = JSON.parse(abandonData);
          console.log("this is the data", getParaedData);
          console.log("deleting", `declinePayload-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('variable_domain_name')}`)
          redisClient.del(`declinePayload-${ev.getHeader('Caller-Destination-Number')}@${ev.getHeader('variable_domain_name')}`)
          
          const payload = {
               domain: getParaedData.domain,
               extension: getParaedData.extension,
               time: getParaedData.time,
               id: getParaedData.id,
               call: "decline",
               caller_id: getParaedData.caller_id,
               nameCaller:  getParaedData.nameCaller,
               time2: getParaedData.time2,
               // action: "decline"
          }
          console.log("this is new data", payload);
          getToken(payload);
          
     }


     if(ev.getHeader('variable_cc_queue')){

          if(ev.getHeader('Hangup-Cause') == 'NORMAL_CLEARING'){

               if(ev.getHeader('variable_cc_cause') == 'answered'){
                    if(ev.getHeader('Call-Direction') == 'inbound'){
                         queueCallCenterRating(domain, ev.getHeader('variable_domain_uuid'), ev.getHeader('variable_orig_sip_call_id'), ev.getHeader('variable_call_center_queue_uuid'), ev.getHeader('variable_cc_queue'), ev.getHeader('Hangup-Cause'))
                    }
               }

          }else{

               if(ev.getHeader('Call-Direction') == 'outbound'){
                    queueCallCenterRating(domain, ev.getHeader('variable_domain_uuid'), ev.getHeader('variable_orig_sip_call_id'), ev.getHeader('variable_call_center_queue_uuid'), ev.getHeader('variable_cc_queue'), ev.getHeader('Hangup-Cause'))      
               }

          }

     }

     if(ev.getHeader('Channel-State') == 'CS_PARK' && ev.getHeader('Channel-Call-State') == 'ACTIVE' && ev.getHeader('Hangup-Cause') == 'NORMAL_CLEARING'){
          socketClient.emit("parkCancel", {callerExt: ev.getHeader('Caller-Caller-ID-Number'), calleeExt:ev.getHeader('Caller-Destination-Number'), subdomain:domain});
     }

}

const addSchedule =  async (ev, bd, conn) => {
     console.log(`✅️ ADD_SCHEDULE`, util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })); 
}

const handleDestroy = async (ev, bd, conn) => {

     console.log(`✅️ CHANNEL_DESTROY`, JSON.parse(bd));
     // ─── Helpers ────────────────────────────────────────────────────────────────
 
     /** Wrap conn.api in a Promise */
     const apiPromise = (conn, command) =>
     new Promise((resolve, reject) =>
     conn.api(command, (res) => (res ? resolve(res) : reject(new Error(`API no result: ${command}`))))
     );
     
     /** Resolve a SIP contact/alias to a DB extension row */
     const resolveExtension = async (ext, domain, registrations, fields = 'extension, effective_caller_id_name') => {
          if(!ext || !domain || !registrations) return { reg: null, row: null };
          const reg = registrations.find(x => regMatch(x, ext));
          if (reg) {
               const { rows } = await db2.query(
                    `SELECT ${fields} FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                    [reg['Auth-User'], reg['Auth-Realm']]
               );
               if (rows.length) return { reg, row: rows[0] };
          }
          const { rows } = await db2.query(
               `SELECT ${fields} FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
               [ext, domain]
          );
          return { reg, row: rows[0] || null };
     };

     /** Build the standard "last active call" payload */
     const buildPayload = (ev, overrides = {}) => ({
     uuid:               ev.getHeader('Caller-Unique-ID'),
     direction:          ev.getHeader('Caller-Direction'),
     created:            ev.getHeader('variable_start_stamp'),
     created_epoch:      Math.floor(Number(ev.getHeader('Caller-Profile-Created-Time')) / 1_000_000),
     name:               ev.getHeader('Caller-Channel-Name'),
     state:              'CS_EXECUTE',
     ip_addr:            ev.getHeader('Caller-Network-Addr'),
     presence_id:        ev.getHeader('variable_presence_id'),
     presence_data:      '',
     accountcode:        ev.getHeader('variable_dialed_domain'),
     callstate: '', 
     callee_name: '', 
     callee_num: '', 
     callee_direction: '',
     call_uuid: '', 
     hostname: '', 
     sent_callee_name: '', 
     sent_callee_num: '',
     b_uuid: '', 
     b_direction: '', 
     b_created: '', 
     b_created_epoch: '',
     b_name: '', 
     b_state: '', 
     b_cid_name: '', 
     b_cid_num: '',
     b_ip_addr: '', 
     b_dest: '', 
     b_presence_id: '', 
     b_presence_data: '',
     b_accountcode: '', 
     b_callstate: '', 
     b_callee_name: '', 
     b_callee_num: '',
     b_callee_direction: '', 
     b_sent_callee_name: '', 
     b_sent_callee_num: '',
     call_created_epoch: '', 
     caller_id: '',
     duration:           ev.getHeader('variable_duration'),
     hangUpCause:        ev.getHeader('variable_hangup_cause'),
     gateway:            ev.getHeader('variable_sip_gateway_name') || '',
     rule:               ev.getHeader('variable_rule') || '',
     route: '',
     ...overrides,
     });

     console.log("pasok sa server")
     /** Push payload to the lastActiveCall Redis list and broadcast */
     const pushLastActive = async (ev, payload, delayMs = 1000) => {
          if(payload.dest !== 'bridge_to_agent'){

               const sipDIDs = async () => {
                    const { rows } = await db2.query(
                         `SELECT sip_trunk_name, name FROM q_dids WHERE domain_uuid = $1 AND extension = $2`,
                         [ev.getHeader('variable_domain_uuid'), payload.dest]
                    );
                    if(!rows.length) return {route: '', rule: ''};
                    return {route: rows[0]?.sip_trunk_name, rule: rows[0]?.name};
               }
               const {route, rule} = await sipDIDs();
               const payload2 = {...payload, rule: rule || payload.rule || '', route: route || payload.route || ''} // [call-journey] keep existing values
               // [outbound-pin] "outbound-pin" -> "O-pin/<outbound rule name>"
               payload2.rule = await outboundPin.pinRuleLabel(db2, {
                    domainUuid: ev.getHeader('variable_domain_uuid'), rule: payload2.rule,
                    dest: payload.dest, caller: payload.cid_num, pinUsed: !!ev.getHeader('variable_pin_number'),
               });



                console.log("baka dito handle destory lastactive1", `lastActiveCall-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payload))

               const key = `lastActiveCall-${ev.getHeader('variable_domain_name')}`;
               // [call-journey] payload2 carries the DID rule/route lookup (it was computed but never saved)
               const rowOut = await enrichCallRow(payload2);

               // [call-journey] Outbound calls (dest > 4 digits) used to be dropped here entirely, so
               // they never showed in Previous Calls. Show them, once per call: both legs of an
               // outbound call reach this point, so de-duplicate on the call's journey root.
               if (String(payload.dest || '').length > 4) {
                    const dedupeKey = `lastActiveOutbound:${rowOut.journey_root || payload.uuid}`;
                    const first = await redisClient.set(dedupeKey, '1', { NX: true, EX: 300 });
                    if (!first) {
                         console.log("outbound row already shown for this call", dedupeKey);
                         return;
                    }
               }
               await redisClient.lPush(key, JSON.stringify(rowOut));
               await redisClient.lTrim(key, 0, 49); // keep last 50

               await new Promise(r => setTimeout(r, delayMs));
               showcallsEventRefresh(ev.getHeader('variable_domain_name'));
               socketClient.emit('lastActive', ev.getHeader('variable_domain_name'));
          }
     };

     /** Also push to failedActiveCall and emit failedActive2 */
     const pushFailed = async (ev, payload) => {
     console.log("baka dito faield active call", `lastActiveCall-${ev.getHeader('variable_domain_name')}`, JSON.stringify(payload));
     const key = `failedActiveCall-${ev.getHeader('variable_domain_name')}`;
     await redisClient.lPush(key, JSON.stringify(payload));
     await redisClient.lTrim(key, 0, 9);
     socketClient.emit('failedActive2', ev.getHeader('variable_domain_name'));
     };

     /** Derive a status string for missed / failed / abandoned calls */
     const statusForDest = (dest, map) => {
          const kind = classifyNumber(Number(dest));
          return map[kind] ?? null;
     };

     /** Remove incall Redis keys after verifying no live call remains */
     const safeDelIncall = (conn, subDomain, alias) => {
     if (!alias) return;
     conn.api('show calls as json', async ({ body }) => {
     const { row_count, rows } = JSON.parse(body);
     const stillActive = row_count && rows.some(
          ({ name = '', presence_id = '', b_presence_id = '' }) =>
          name.includes(alias) || presence_id.includes(alias) || b_presence_id.includes(alias)
     );
     if (!stillActive) await redisClient.del(`${subDomain}:incall:${alias}`);
     });
     };

     const channelNameIncludes = (ev, str) => (ev.getHeader('Channel-Name') || '').includes(str);



     const domain      = ev.getHeader('variable_domain_name');
     const uniqueId    = ev.getHeader('Unique-ID');
     const callUUID    = ev.getHeader('Channel-Call-UUID');
     const direction   = ev.getHeader('Call-Direction');
     const hangupCause = ev.getHeader('variable_hangup_cause');
     const channelName = ev.getHeader('Channel-Name') || '';
     const isInternal  = channelName.includes('internal');

     // ── 1. Shared Redis cleanup ───────────────────────────────────────────────
     await redisClient.sRem(`activeQueue-${domain}`, uniqueId);
     await redisClient.del(`lastActiveUid-${uniqueId}`);
     await redisClient.del(`callBackActive-${uniqueId}`);
     await redisClient.del(`callBackActiveCustom-${uniqueId}`);
     await redisClient.del(`dtmfCustom:${uniqueId}`);
     await redisClient.del(`uuid_audio-${callUUID}`);          // replaces the exists() + del() at the bottom

     const bridgeUUID = ev.getHeader('variable_bridge_uuid');
     if (bridgeUUID && ev.getHeader('variable_sip_term_status') === '200') {
          // [xfer-kill] a phone that hangs up its own leg after an attended transfer (Snom) must not
          // take the caller with it: kill the partner only if it is still bridged to this leg
          conn.api(`uuid_getvar ${bridgeUUID} bridge_uuid`, (res) => {
               const now = String((res && res.body) || '').trim();
               if (now && !now.startsWith('-ERR') && now !== '_undef_' && now !== uniqueId) return;
               conn.api(`uuid_kill ${bridgeUUID}`);
          });
     }
     
     // const bridgeUUID = ev.getHeader('variable_bridge_uuid');
     // if (bridgeUUID && ev.getHeader('variable_sip_term_status') === '200') {
     // console.log("KILL");

     // if(ev.getHeader('Caller-Transfer-Source')){
     //      conn.api(`uuid_transfer ${bridgeUUID} ${ev.getHeader('variable_pre_transfer_caller_id_number')} XML default`);
     //      console.log("means transfer this uuid", `${bridgeUUID}: ${ev.getHeader('')} to ${ev.getHeader('variable_pre_transfer_caller_id_number')}:${ev.getHeader('variable_originating_leg_uuid')} ${ev.getHeader('variable_dialed_domain')}`)
         
     //      console.log("test");
     //      conn.api(`uuid_kill ${bridgeUUID}`);
     // }
     // else{
     //      console.log("papasok dito pag di transfer outbound")
     //      conn.api(`uuid_kill ${bridgeUUID}`);
     // }
     // }

     // ── 2. Restore agent state after call ends ────────────────────────────────
     const registrations = await fsRegistration();
     const channelMatch  = channelName.match(/(?:internal|external)\/([^@]+)/);
     
     if (channelMatch) {
          const channelExactName = channelMatch[1];
          const { reg: currentPresence } = await resolveExtension(channelExactName, domain, registrations);
          if (currentPresence) {
               const authUser  = currentPresence['Auth-User'];
               const authRealm = currentPresence['Auth-Realm'];
          
               if (authUser || authRealm) {
               const [agentBody, tierBody] = await Promise.all([
                    apiPromise(conn, `json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`),
                    apiPromise(conn, `json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`),
               ]);
          
               const agentList = JSON.parse(agentBody.body).response;
               const tierList  = JSON.parse(tierBody.body).response;
               const agentMap  = Object.fromEntries(agentList.map(a => [a.name, a]));
          
               const matchAgents = (requiredState) =>
                    tierList.reduce((acc, tier) => {
                    const agent = agentMap[tier.agent];
                    if (
                    agent?.state === requiredState &&
                    agent.contact.includes(domain) &&
                    agent.contact.includes(authUser)
                    ) acc.push(agent);
                    return acc;
                    }, []);
          
               const setWaiting = (agents) =>
                    Promise.all(agents.map(a => conn.api(`callcenter_config agent set state ${a.name} Waiting`)));
               const extensionQueue = redisClient.exists(`queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`);
               console.log("getting 1", `queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`)
               if(extensionQueue){
                    redisClient.del(`queueCallCenterSetStatus-${ev.getHeader('Channel-Presence-ID')}`)
               }
               // redisClient.del(`queueCallCenterSetStatus-${ev.getHeader('Other-Leg-RDNIS')}@${ev.getHeader('Other-Leg-Context')}`)
                console.log("set waiting 2 waiting");
          
               await setWaiting(matchAgents('Idle'));
               if (bridgeUUID) await setWaiting(matchAgents('In a queue call'));
               }
          }
     }

     // ── 3. Clear answered-active key ──────────────────────────────────────────
     if (hangupCause !== 'LOSE_RACE') {
          const presenceName = await presenceId(ev.getHeader('Caller-Channel-Name'));
          if(!presenceName || !domain || !registrations) return
          const { row } = await resolveExtension(presenceName, domain, registrations,'extension, effective_caller_id_name');
          const ext = row?.extension ?? presenceName;
          await redisClient.del(`answered-active-${ext}@${domain}`, 1);    
     }

     // ── 4. Attended transfer bookkeeping ─────────────────────────────────────
     if (ev.getHeader('variable_callFeatures') === 'Attended_Transfer') {
          await redisClient.set(`attendedActive-${uniqueId}`, 1, { EX: 2 });
          const ccAgent = ev.getHeader('variable_cc_agent');
          const memberSessionUUID = ev.getHeader('variable_cc_member_session_uuid');
          if (ccAgent) await redisClient.set(`previousAgent-${memberSessionUUID}`, ccAgent);
     }

     // ── 5. Blind transfer handling ────────────────────────────────────────────
     if (ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER' && isInternal) {
          await handleBlindTransfer(ev, conn, domain, registrations);
     }

     // ── 6. incall Redis cleanup (internal channels) ───────────────────────────
     if (isInternal) {
          console.log("oh dito?")
     await handleIncallCleanup(ev, conn, domain);
     }
     if (domain) showcallsEventRefresh(domain);

     // ── 7. Queue LOSE_RACE broadcast ─────────────────────────────────────────
     if (direction === 'outbound' && ev.getHeader('variable_cc_queue') && hangupCause === 'LOSE_RACE') {
          const [qExtension, qSubdomain] = ev.getHeader('variable_cc_queue').split('@');
          socketClientQueue.emit('broadcast', {
               uuid: callUUID,
               extension: qExtension,
               channel: qSubdomain,
               callerName: ev.getHeader('variable_effective_caller_id_name'),
               callerNumber: ev.getHeader('variable_effective_caller_id_number'),
               command: 'lose_race',
               cc_agent: ev.getHeader('variable_cc_agent') || null,
               lastBridgeStart: null,
               lastBridgeEnd: null,
               lastOfferedCall: ev.getHeader('variable_end_epoch'),
          });
     }

     // ── 8. Outbound call logging ──────────────────────────────────────────────
     const callMethod = ev.getHeader('variable_call_method');
     if (direction === 'outbound') {
          console.log("baka")
          await handleOutboundDestroy(ev, conn, domain, registrations, !!callMethod);
          if(ev.getHeader('Hangup-Cause') === 'CALL_REJECTED' && ev.getHeader('Channel-Name').includes('internal')){
               
               const channelName = ev.getHeader('Channel-Name');
               const callerChannelName = ev.getHeader('Other-Leg-Channel-Name');

               //  'Other-Leg-Channel-Name': 'sofia/internal/28bee7d8bf2cd95496a9@multi.flowersconradplc.ringq.cloud:7443',
               const registrations = await fsRegistration();
               const match = channelName.match(/(?:internal)\/([^@]+)/);

               if (!match) return;
               const channelExactName = match[1];
               const callerChannelExactName = await presenceId(callerChannelName)

               const extensionInfoHandler = async (ext) => {
                    const findCurrentPresence = registrations.find(x => regMatch(x, ext));
                    if(!findCurrentPresence) return {cid_name: ext, cid_num: ext};
                    const { "Auth-User": authUser , "Auth-Realm": authRealm} = findCurrentPresence;
                    const { rows } = await db2.query(
                         "SELECT extension, effective_caller_id_name FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
                         [authUser, authRealm]
                    );
                    return rows.length ? {cid_name: rows[0].effective_caller_id_name, cid_num: rows[0].extension, extensionUUID: rows[0].extension_uuid} : {cid_name: ext, cid_num: ext};
               }

               const [extensionInfo, extensionInfo2] = await Promise.all([
                     extensionInfoHandler(channelExactName),
                     extensionInfoHandler(callerChannelExactName)
               ]);

               const { cid_name, cid_num } = extensionInfo;
               const {cid_name : cid_name2, cid_num : cid_num2} = extensionInfo2;

               console.log(`🅿️`, cid_num, cid_name, cid_name2, cid_num2);
               const payload = {
                    domain: ev.getHeader('variable_domain_name'),
                    extension: cid_num,
                    time: ev.getHeader('Event-Date-Timestamp'),
                    id: uuidv4(),
                    call: '',
                    caller_id: cid_num2,
                    nameCaller: cid_name2, 
                    time2: ev.getHeader('Event-Date-Timestamp')
               };
               console.log("before getTokenIos 1");
               getToken({...payload, call:'decline'});
               getTokenIOS(payload);
          }
     }
     
     // console.log("what is callmethod", callMethod);
     // ── 9. Inbound call logging ───────────────────────────────────────────────
     if (direction === 'inbound' && !callMethod) {
          console.log("dito inbound destroy")
     await handleInboundDestroy(ev, conn, domain, registrations);
     }
     
     // ── 10. Inbound queue board / missed push ────────────────────────────────
     if (direction === 'inbound') {
          console.log("or else")
     await handleInboundQueue(ev, conn, domain);
     }

     // ── 11. Presence / avg-waiting ───────────────────────────────────────────
     if (
     (ev.getHeader('Channel-Presence-ID')?.includes('@') ||
          ev.getHeader('variable_sofia_profile_name') === 'external') &&
     hangupCause !== 'LOSE_RACE' &&
     ev.getHeader('variable_cc_queue') &&
     ev.getHeader('variable_cc_cancel_reason') === 'BREAK_OUT' &&
     direction === 'inbound'
     ) {
     const avgTotal = Number(ev.getHeader('variable_cc_queue_canceled_epoch')) -
                         Number(ev.getHeader('variable_cc_queue_joined_epoch'));
     const rk = `avgWaiting-${ev.getHeader('variable_cc_queue')}`;
     const cur = await redisClient.get(rk);
     await redisClient.set(rk, avgTotal + (cur ? Number(cur) : 0));
     }
 
     // ── 12. IOS end-call notification (internal outbound) ────────────────────
     if (isInternal) {
     await handleIOSEndCall(ev, domain);
     }


     // ─── Sub-handlers ────────────────────────────────────────────────────────────
     async function handleBlindTransfer(ev, conn, domain, registrations) {
     const blindEnd         = await presenceId(ev.getHeader('Channel-Name'));
     const blindNumber      = await presenceId(ev.getHeader('variable_bridge_channel'));
     const blindDestination = ev.getHeader('variable_transfer_destination').split('blind:')[1];
     const callId           = ev.getHeader('variable_bridge_uuid');
     
     // Queue broadcast for blind transfer
     if (ev.getHeader('variable_cc_queue')) {
     const [queueExt, queueSubdomain] = ev.getHeader('variable_cc_queue').split('@');
     const endEpoch        = Number(ev.getHeader('variable_end_epoch'));
     const waitingSeconds  = Number(ev.getHeader('variable_bridge_epoch')) - Number(ev.getHeader('variable_cc_queue_joined_epoch'));
     const answeredSeconds = endEpoch - Number(ev.getHeader('variable_bridge_epoch'));
     
     const broadcastData = {
          uuid:             ev.getHeader('variable_cc_member_session_uuid'),
          extension:        queueExt,
          callerName:       ev.getHeader('variable_origination_caller_id_name'),
          callerNumber:     ev.getHeader('variable_origination_caller_id_number'),
          channel:          queueSubdomain,
          command:          'ended', cc_member_uuid: ev.getHeader('variable_cc_member_uuid') || undefined, /* [wallboard-dedupe] */
          cc_agent:         ev.getHeader('variable_cc_agent'),
          call_center_queue_uuid: ev.getHeader('variable_call_center_queue_uuid'),
          waitingSeconds,
          answeredSeconds,
          lastBridgeStart:  Number(ev.getHeader('variable_bridge_epoch')),
          lastBridgeEnd:    endEpoch,
          lastOfferedCall:  endEpoch,
     };
     console.log('BLIND EMIT', broadcastData);
     socketClientQueue.emit('broadcast', broadcastData);
     }
     
     // Resolve extensions via sofia registration
     const getRegistration = (caller) =>
     new Promise((resolve, reject) => {
          conn.api('sofia status profile internal reg', async (reg) => {
          try {
               const blocks = reg.body.trim().split(/\n\s*\n/);
               const regs   = [];
               for (const block of blocks) {
               if (block.startsWith('Total items')) continue;
               const entry = {};
               block.split('\n').forEach(line => {
               const m = line.match(/^(.*?):\s*\t?(.*)$/);
               if (m) entry[m[1].trim()] = m[2].trim();
               });
               regs.push(entry);
               }
     
               const match = regs.find(x => x.Contact?.includes(caller));
               const [alias, realm] = match
               ? [match['Auth-User'], match['Auth-Realm']]
               : [caller, domain];
     
               const { rows } = await db2.query(
               'SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2',
               [alias, realm]
               );
               resolve(rows.length ? rows[0].extension : caller);
          } catch (err) { reject(err); }
          });
     });
     
     const [blindEndExtension, callerExtension] = await Promise.all([
     getRegistration(blindEnd),
     getRegistration(blindNumber),
     ]);
     
     if (classifyNumber(blindDestination) === 'InternalExtension') {
     const aliasName = async (ext) => {
          const { rows } = await db2.query(
          'SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2',
          [domain, ext]
          );
          return rows[0]?.number_alias || ext;
     };
     
     const [endAlias, callerAlias, destinationAlias] = await Promise.all([
          aliasName(blindEndExtension),
          aliasName(callerExtension),
          aliasName(blindDestination),
     ]);
     
     if (endAlias) await redisClient.del(`${domain}:incall:${endAlias}`);
     
     // No-answer control
     const callControlPosition = await redisClient.get(`${blindDestination}+${domain}`);
     if (callControlPosition) {
          const pos = Number(callControlPosition);
          const [enabled] = await redisClient.lRange(`${blindDestination}@${domain}-forward-no-answer-enabled`, pos, pos);
          if (enabled === 'true') {
          const [dest] = await redisClient.lRange(`${blindDestination}@${domain}-forward-no-answer-destination`, pos, pos);
          await redisClient.set(`${domain}${blindDestination}-RingQ`, callId);
     
          conn.api(`sofia_count_reg internal/${destinationAlias}@${domain}`, async ({ body }) => {
               if (Number(body) > 0) return;
               const deviceOff = await redisClient.sMembers(`deviceOff-${blindDestination}@${domain}`);
               const target = dest || `*99${blindDestination}`;
                console.log("no answer 2")

                 console.log(`sched_api 3 +30 none uuid_setvar ${callId} my_hangup_cause NO_ANSWER`);
               conn.api(`sched_api +30 none uuid_setvar ${callId} my_hangup_cause NO_ANSWER`);
               conn.api(`sched_transfer +30 ${callId} ${target} XML ${domain}`);
          });
          }
     }
     
     // Push notification
     const { rows: nameRows } = await db2.query(
          'SELECT effective_caller_id_name FROM v_extensions WHERE accountcode = $1 AND extension = $2',
          [domain, callerExtension]
     );
     const payload = {
          domain,
          extension:   blindDestination,
          time:        ev.getHeader('Event-Date-Timestamp'),
          id:          uuidv4(),
          call:        callId,
          caller_id:   callerExtension,
          nameCaller:  nameRows[0]?.effective_caller_id_name || '',
          time2:       ev.getHeader('Event-Date-Timestamp'),
     };
     console.log("before getTokenIos 10");
     getTokenIOS(payload);
     getTokenMultipleDevice(payload);
     getToken(payload);
     // pushWeb(payload);
     }
     
     if (classifyNumber(blindDestination) === 'Queue') {
     const ccQueue = `${blindDestination}@${domain}`;
     conn.api(
          `json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ccQueue}"}}`,
          (res) => {
          const { status, response } = JSON.parse(res.body);
          if (status === 'success') {
               const active = response
               .filter(({ status, state }) => status === 'Available' && (state === 'Waiting' || state === 'Receiving'))
               .map(({ contact }) => contact.split('user/')[1]);
               console.log('🌾Queue🌾', active);
          }
          }
     );
     }
     }
     
     async function handleIncallCleanup(ev, conn, domain) {
     const dest      = ev.getHeader('Caller-Destination-Number');
     const direction = ev.getHeader('Call-Direction');
     
     if (direction === 'inbound') {
          const callerExt = await presenceId(ev.getHeader('Channel-Name'));
          const { rows }  = await db2.query(
               'SELECT extension FROM q_call_behaviors WHERE domain_uuid = $1 AND force_ping = $2',
               [ev.getHeader('variable_domain_uuid'), dest]
          );
          console.log("incall??");
          const origExtension = rows[0]?.extension ?? dest;
          const callerAlias   = await getAlias(callerExt, domain);
          const existAlias    = await getAlias(origExtension, domain);
          safeDelIncall(conn, domain, existAlias);
          safeDelIncall(conn, domain, callerAlias);
     }
     
     if (direction === 'outbound') {
          console.log("outbound incall?")
          const ccQueue = ev.getHeader('variable_cc_queue');
          if (ccQueue) {
               // Queue-specific cleanup
               const channelAlias    = ev.getHeader('Channel-Presence-ID').split('@')[0];
               const channelExactName = await presenceId(ev.getHeader('Channel-Name'));
               const { rows } = await db2.query(
               'SELECT EXISTS (SELECT 1 FROM v_extensions WHERE number_alias = $1 AND accountcode = $2) AS exists',
               [channelExactName, domain]
               );
               const alias     = rows[0].exists ? channelExactName : channelAlias;
               const hangup    = ev.getHeader('Hangup-Cause');
               const answeredKey = `${domain}:answered:${alias}`;
               const incallKey   = `${domain}:incall:${alias}`;
          
               if (hangup === 'LOSE_RACE') {
               const active = await redisClient.exists(answeredKey);
               if (!active) {
                    await redisClient.del(incallKey);
                    await redisClient.del(answeredKey);
               }
               } else {
               await redisClient.del(incallKey);
               await redisClient.del(answeredKey);
               }
          } else {
               console.log("incall2??")
               const callerExt  = await presenceId(ev.getHeader('Channel-Name'));
               const callerAlias = await getAlias(callerExt, domain);
               const existAlias  = await getAlias(dest, domain);
               safeDelIncall(conn, domain, existAlias);
               if (callerAlias) {
               conn.api('show calls as json', async ({ body }) => {
                    const { row_count, rows } = JSON.parse(body);
                    const still = row_count && rows.some(({ name = '', presence_id = '', b_presence_id = '' }) =>
                    name.includes(callerAlias) || presence_id.includes(callerAlias) || b_presence_id.includes(callerAlias)
                    );
                    if (!still) {
                    await redisClient.del(`${domain}:incall:${callerAlias}`);
                    await redisClient.del(`${domain}:answered:${callerAlias}`);
                    }
               });
               }
          }
     }

     else{
          console.log("inbound inbound?")
     }
     }
     
     async function handleOutboundDestroy(ev, conn, domain, registrations, isAutoBroadcast) {
     const { body } = await apiPromise(conn, `uuid_dump ${ev.getHeader('Channel-Call-UUID')} JSON`);
     const parsed   = body.includes('-ERR No such channel!') ? null : JSON.parse(body);
     const attendedState = parsed?.variable_callFeatures ?? null;
     const cause = ev.getHeader('variable_hangup_cause');
     
     // Resolve the "previous attended" agent once
     const attendedPreviousAgent = await resolvePreviousAttendedAgent(ev, domain, registrations);
     
     // ── Missed calls (NO_ANSWER) ──
     const MISSED = ['NO_ANSWER'];
     if (MISSED.includes(cause) && !ev.getHeader('variable_cc_queue') || Number(ev.getHeader('variable_voicemail_answer_epoch')) > 0) {
     await processOutboundMissed(ev, conn, domain, registrations, attendedState, attendedPreviousAgent, isAutoBroadcast);
     }
     
     // ── Rejected / Forbidden ──
     if (cause === 'CALL_REJECTED' && !ev.getHeader('variable_cc_queue')) {
          if(ev.getHeader('variable_sip_invite_failure_phrase') === 'Forbidden'){
               await processOutboundFailed(ev, conn, domain, registrations, attendedState, attendedPreviousAgent, isAutoBroadcast);
          }else{
               await processOutboundBusy(ev, conn, domain, registrations, attendedState, attendedPreviousAgent, isAutoBroadcast);
          }
     }
     // ── Answered calls ──
     if (
     Number(ev.getHeader('variable_billsec')) &&
     ev.getHeader('variable_last_bridge_to') &&
     ev.getHeader('variable_hangup_cause') === 'NORMAL_CLEARING' &&
     !ev.getHeader('Caller-Destination-Number').includes('*99')
     ) {
          await processOutboundAnswered(ev, conn, domain, registrations, attendedPreviousAgent, isAutoBroadcast);
     }
     
     // ── CDR log routing ──
     if (!isAutoBroadcast) {
     if (ev.getHeader('variable_last_bridge_to')) callLogsData({ type: 4, ev, state: 'bridge' });
     else if (ev.getHeader('variable_transfer_source') && !ev.getHeader('variable_transfer_source').includes('uuid_br')) callLogsData({ type: 4, ev, state: 'transfer' });
     else if (ev.getHeader('variable_transfer_to')?.includes('blind')) callLogsData({ type: 4, ev, state: 'transfer' });
     else callLogsData({
          type: ev.getHeader('variable_ivr_menu_status') ? 3 : ev.getHeader('variable_cc_cause') ? 2 : 1,
          ev, state: 'normal',
     });
     
          // IOS end-call notification
          if (channelNameIncludes(ev, 'internal')) await handleIOSEndCall(ev, domain);
          }
     }
     
     /** Shared logic for outbound missed (NO_ANSWER) — regular and auto-broadcast paths */
async function processOutboundMissed(ev, conn, domain, registrations, attendedState, attendedPreviousAgent, skipPush) {
  const channelExactName = await presenceId(ev.getHeader('Other-Leg-Channel-Name'));
  const converted = Math.floor(Number(ev.getHeader('Caller-Profile-Created-Time')) / 1_000_000);

  const resolveExt = async (ext) => {
    const { reg, row } = await resolveExtension(
      ext,
      domain,
      registrations,
      'extension_uuid, extension, effective_caller_id_name'
    );

    if (row) {
      return {
        cid_name: row.effective_caller_id_name,
        cid_num: row.extension,
        extensionUID: row.extension_uuid
      };
    }

    return {
      cid_name: ext,
      cid_num: ext,
      extensionUID: null
    };
  };

  const [caller, destination] = await Promise.all([
    resolveExt(channelExactName),
    resolveExt(ev.getHeader('Other-Leg-Destination-Number')),
  ]);

  const { cid_name, cid_num, extensionUID } = caller;
  const origDest = destination.cid_num;

  if (!cid_num || !origDest || origDest === 'bridge_to_agent') return;

  const missedMap = {
    InternalExtension: attendedState ? 'Attended Transfer Missed' : 'Inbound Missed',
    Queue: attendedState ? 'Attended Transfer Missed' : 'Queue Missed',
    ExternalNumber: attendedState ? 'Attended Transfer Missed' : 'Outbound Missed',
  };

  const statusState = statusForDest(origDest, missedMap);

  /**
   * Duplicate protection based on:
   * cid_name + cid_num + origDest + statusState
   */
  const dedupeKey = `processOutboundMissed:${domain}:${cid_name}:${cid_num}:${origDest}:${statusState}`;

  const isFirstProcess = await redisClient.set(dedupeKey, '1', {
    NX: true,
    EX: 15
  });

  if (isFirstProcess !== 'OK') {
    console.log('⛔ Duplicate processOutboundMissed skipped', {
      dedupeKey,
      cid_name,
      cid_num,
      origDest,
      statusState,
      uniqueId: ev.getHeader('Unique-ID'),
      sipCallId: ev.getHeader('sip_call_id') || ev.getHeader('variable_sip_call_id')
    });

    return;
  }

  const payload = buildPayload(ev, {
    created_epoch: converted,
    cid_name,
    cid_num,
    dest: origDest,
    status: statusState,
    duration: ev.getHeader('variable_duration'),
  });

  if (!skipPush) {
    console.log("skip pushhh", cid_name, cid_num, origDest, statusState);

    await pushLastActive(ev, payload);

    await callLogsOutboundCall(
      ev,
      cid_name,
      cid_num,
      origDest,
      'normal',
      statusState,
      extensionUID,
      ev.getHeader('variable_hangup_cause'),
      attendedPreviousAgent
    );
  } else {
    console.log(`🏝️`, ev.getHeader('variable_hangup_cause'), payload);
  }

  await handleTeamsMissed(
    ev,
    domain,
    registrations,
    origDest,
    attendedPreviousAgent,
    skipPush
  );
}
     
     /** Shared logic for outboundForbidden */
     async function processOutboundFailed(ev, conn, domain, registrations, attendedState, attendedPreviousAgent, skipPush) {
     const channelExactName = await presenceId(ev.getHeader('Other-Leg-Channel-Name'));
     const converted        = Math.floor(Number(ev.getHeader('Caller-Profile-Created-Time')) / 1_000_000);
     
     const resolveExt = async (ext) => {
     const { reg, row } = await resolveExtension(ext, domain, registrations,
          'extension_uuid, extension, effective_caller_id_name');
     if (row) return { cid_name: row.effective_caller_id_name, cid_num: row.extension, extensionUID: row.extension_uuid };
     return { cid_name: ext, cid_num: ext, extensionUID: null };
     };
     
     const [caller, destination] = await Promise.all([
     resolveExt(channelExactName),
     resolveExt(ev.getHeader('Other-Leg-Destination-Number')),
     ]);
     const { cid_name, cid_num, extensionUID } = caller;
     const origDest = destination.cid_num;
     
     if (!cid_num || !origDest || origDest === 'bridge_to_agent') return;
     
     const failedMap = {
     InternalExtension: attendedState ? 'Attended Transfer Failed' : 'Inbound Failed',
     Queue:             attendedState ? 'Attended Transfer Failed' : 'Queue Failed',
     ExternalNumber:    attendedState ? 'Attended Transfer Failed' : 'Outbound Failed',
     };
     const statusState = statusForDest(origDest, failedMap);
     
     const payload = buildPayload(ev, {
     created_epoch: converted, cid_name, cid_num, dest: origDest, status: statusState,
     duration: ev.getHeader('variable_duration'),
     });
     
     if (!skipPush) {

     console.log("outbound1")

     await pushLastActive(ev, payload);
     await pushFailed(ev, payload);
     callLogsOutboundCall(ev, cid_name, cid_num, origDest, 'normal', statusState, extensionUID,
          ev.getHeader('variable_hangup_cause'), attendedPreviousAgent);
     } else {
     console.log(`🏝️`, ev.getHeader('variable_hangup_cause'), payload);
     }
     }

     /** Shared logic for outbound CALL_REJECTED */
     async function processOutboundBusy(ev, conn, domain, registrations, attendedState, attendedPreviousAgent, skipPush) {
     const channelExactName = await presenceId(ev.getHeader('Other-Leg-Channel-Name'));
     const converted        = Math.floor(Number(ev.getHeader('Caller-Profile-Created-Time')) / 1_000_000);
     
     const resolveExt = async (ext) => {
     const { reg, row } = await resolveExtension(ext, domain, registrations,
          'extension_uuid, extension, effective_caller_id_name');
     if (row) return { cid_name: row.effective_caller_id_name, cid_num: row.extension, extensionUID: row.extension_uuid };
     return { cid_name: ext, cid_num: ext, extensionUID: null };
     };
     
     const [caller, destination] = await Promise.all([
     resolveExt(channelExactName),
     resolveExt(ev.getHeader('Other-Leg-Destination-Number')),
     ]);
     const { cid_name, cid_num, extensionUID } = caller;
     const origDest = destination.cid_num;
     
     if (!cid_num || !origDest || origDest === 'bridge_to_agent') return;
     
     const failedMap = {
     InternalExtension: attendedState ? 'Attended Transfer Callee Busy' : 'Inbound Callee Busy',
     Queue:             attendedState ? 'Attended Transfer Callee Busy' : 'Queue Callee Busy',
     ExternalNumber:    attendedState ? 'Attended Transfer Callee Busy' : 'Outbound Callee Busy',
     };
     const statusState = statusForDest(origDest, failedMap);
     
     const payload = buildPayload(ev, {
     created_epoch: converted, cid_name, cid_num, dest: origDest, status: statusState,
     duration: ev.getHeader('variable_duration'),
     });
     
     if (!skipPush) {
     await pushLastActive(ev, payload);
     await pushFailed(ev, payload);
      console.log("outbound2", statusState)

     callLogsOutboundCall(ev, cid_name, cid_num, origDest, 'normal', statusState, extensionUID,
          ev.getHeader('variable_hangup_cause'), attendedPreviousAgent);
     } else {
     console.log(`🏝️`, ev.getHeader('variable_hangup_cause'), payload);
     }
     }
     
     /** Answered outbound call logging + transfer entries */
     async function processOutboundAnswered(ev, conn, domain, registrations, attendedPreviousAgent, skipPush) {
          //Added 08-20-26
          //// Added 08-20-26

          const feature    = (ev.getHeader('variable_callFeatures') || '').toLowerCase();
          const converted  = Math.floor(Number(ev.getHeader('Caller-Profile-Created-Time')) / 1_000_000);
     
          const resolveExt = async (ext) => {
          const { row } = await resolveExtension(ext, domain, registrations,
               'extension_uuid, extension, effective_caller_id_name');
          if (row) return { cid_name: row.effective_caller_id_name, cid_num: row.extension, extensionUID: row.extension_uuid };
          return { cid_name: ext, cid_num: ext, extensionUID: null };
          };
          
          const rawCaller = feature === 'attended_transfer'
          ? ev.getHeader('Channel-Name')
          : ev.getHeader('variable_bridge_channel');
          const rawDest   = feature === 'attended_transfer'
          ? ev.getHeader('Other-Leg-Channel-Name')
          : ev.getHeader('Channel-Name');
          
          const [callerPresence, destPresence] = await Promise.all([
          presenceId(rawCaller).then(resolveExt),
          presenceId(rawDest).then(resolveExt),
          ]);
          
          const { cid_name, cid_num, extensionUID } = callerPresence;
          const origDest  = destPresence.cid_num || ev.getHeader('Caller-Destination-Number');
          if (origDest === 'bridge_to_agent') return;
          
          const statusState = await resolveAnsweredStatus(ev, domain, feature, origDest);
          const durationOrig = await redisClient.get(`duration-${ev.getHeader('variable_bridge_uuid')}`);
          
          const payload = buildPayload(ev, {
          created_epoch: converted,
          cid_name:  attendedPreviousAgent?.cid_name ?? cid_name,
          cid_num:   attendedPreviousAgent?.cid_num  ?? cid_num,
          dest:      attendedPreviousAgent ? cid_num : origDest,
          status:    statusState,
          duration:  durationOrig || ev.getHeader('variable_billsec'),
          });

          console.log("ano ang feature?", feature, "at ano ang dispo", ev.getHeader('variable_endpoint_disposition'));
          
          if (!skipPush) {
          await pushLastActive(ev, payload);
            console.log("outbound5", statusState)
          callLogsOutboundCall(ev, cid_name, cid_num, origDest, 'normal', statusState, extensionUID,
               ev.getHeader('variable_hangup_cause'), attendedPreviousAgent, durationOrig);
          
          // Blind transfer secondary entry
          if (ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER') {
               await pushBlindTransferEntry(ev, conn, domain, registrations, resolveExt, extensionUID);
          }
          // Attended transfer secondary entry
          if (feature === 'attended_transfer') {
               await pushAttendedTransferEntry(ev, conn, domain, registrations, resolveExt, extensionUID, converted, durationOrig);
          }
          } else {
          console.log(`🏝️`, ev.getHeader('variable_hangup_cause'), payload);
          // Still set blindFrom / attendedFrom even in auto-broadcast mode
          if (ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER') {
               const bName = await presenceId(ev.getHeader('Channel-Name'));
               const bExt  = await resolveExt(bName);
               const blindCallId = ev.getHeader('variable_transfer_to').split(':')[1];
               conn.api(`uuid_setvar ${blindCallId} blindFrom ${bExt.cid_num}`);
          }
          if (feature === 'attended_transfer') {
               const aName = await presenceId(ev.getHeader('Channel-Name'));
               const aExt  = await resolveExt(aName);
               conn.api(`uuid_setvar ${ev.getHeader('variable_bridge_uuid')} attendedFrom ${aExt.cid_num}`);
          }
          }
     }
     
     async function pushBlindTransferEntry(ev, conn, domain, registrations, resolveExt, extensionUID) {
     const blindName    = await presenceId(ev.getHeader('Channel-Name'));
     const blindPresence = await resolveExt(blindName);
     const { cid_name: blind_name, cid_num: blind_extension } = blindPresence;
     const blindDest    = ev.getHeader('variable_transfer_destination').split(':')[1];
     const blindCallId  = ev.getHeader('variable_transfer_to').split(':')[1];
     const blindConverted = Math.floor(Number(ev.getHeader('Caller-Channel-Hangup-Time')) / 1_000_000);
     
     if (Number(blindDest) >= 3000 && Number(blindDest) < 4000) return;
     
     conn.api(`uuid_setvar ${blindCallId} blindFrom ${blind_extension}`);
     
     const payloadBlind = buildPayload(ev, {
     uuid:          uuidv4(),
     created:       ev.getHeader('variable_end_stamp'),
     created_epoch: blindConverted,
     cid_name:      blind_name,
     cid_num:       blind_extension,
     dest:          blindDest,
     duration:      '0',
     status:        'Blind Transfer',
     });
     
     await pushLastActive(ev, payloadBlind, 2000);
     callLogsOutboundCallBlind(ev, blind_name, blind_extension, blindDest, 'normal',
     'Blind Transfer', extensionUID, payloadBlind.uuid);
     }
     
     async function pushAttendedTransferEntry(ev, conn, domain, registrations, resolveExt, extensionUID, converted, durationOrig) {
     const attendedName    = await presenceId(ev.getHeader('Channel-Name'));
     const attendedPresence = await resolveExt(attendedName);
     const { cid_name: attended_name, cid_num: attended_extension } = attendedPresence;
     const attendedDest2   = await resolveExt(ev.getHeader('Caller-Destination-Number'));
     let attendedDest    = attendedDest2.cid_num;
     
     if(attendedDest == "false"){
          attendedDest = ev.getHeader('Caller-Orig-Caller-ID-Name');
          console.log("making it", attendedDest)
     }
     if(attendedDest == "bridge_to_agent"){
          attendedDest = ev.getHeader('Caller-RDNIS');
           console.log("making it1", attendedDest, ev.getHeader('Caller-RDNIS'))
     }

     conn.api(`uuid_setvar ${ev.getHeader('variable_bridge_uuid')} attendedFrom ${attended_extension}`);
     
     const payloadAttended = buildPayload(ev, {
     uuid:          uuidv4(),
     created_epoch: converted,
     cid_name:      attended_name,
     cid_num:       attended_extension,
     dest:          attendedDest,
     duration:      durationOrig || ev.getHeader('variable_billsec'),
     status:        'Attended Transfer',
     });

     console.log("this is payload attended", payloadAttended);
//      51|server  |   cid_name: 'Boboy2 Dev Test',
// 51|server  |   cid_num: '1135',
// 51|server  |   dest: 'false',
// 51|server  |   status: 'Attended Transfer'
     await pushLastActive(ev, payloadAttended, 3000);
     console.log("AT 1", payloadAttended);
     callLogsOutboundCallAttended(ev, attended_name, attended_extension, attendedDest, 'normal',
     'Attended Transfer', extensionUID, payloadAttended.uuid);
     }
     
     /** Derive answered status string */
     async function resolveAnsweredStatus(ev, domain, feature, origDest) {
     const endpoint            = ev.getHeader('variable_endpoint_disposition');
     const endpointDestination = ev.getHeader('variable_transfer_destination')?.split(':')[1];
     const ccQueue             = ev.getHeader('variable_cc_queue');
     const queueExtension      = ccQueue?.split('@')[0];
     const teamsInfo           = await redisClient.get(`teams-${ev.getHeader('variable_last_bridge_to')}-${domain}`);
     const prevDest            = ev.getHeader('Other-Leg-Destination-Number');
     
     const endpointState = classifyNumber(Number(endpointDestination)) === 'SpecialIVR'
     ? `${queueExtension} - Queue Answered`
     : queueExtension ? `${queueExtension} - Queue Answered` : 'Attended Answered';
     
     if (teamsInfo) {
     const { teamsExtension } = typeof teamsInfo === 'string' ? JSON.parse(teamsInfo) : teamsInfo;
     if (classifyNumber(Number(origDest)) === 'InternalExtension') {
          return feature === 'attended_transfer'
          ? ccQueue ? 'Queue Attended' : `${origDest} - Inbound Attended Answered`
          : endpoint === 'BLIND_TRANSFER' ? `${endpointDestination} - Inbound Blind`
          : teamsExtension ? `${teamsExtension} - Team Answered` : 'Inbound Answered';
     }
     }
     
     if (classifyNumber(Number(prevDest)) === 'Queue') {
     return feature === 'attended_transfer'
          ? `${origDest} - Queue Attended Answered`
          : endpoint === 'BLIND_TRANSFER' ? endpointState
          : ev.getHeader('variable_blindFrom') ? `${ev.getHeader('variable_blindFrom')} - Queue Call Blind`
          : ev.getHeader('variable_attendedFrom') ? `${ev.getHeader('variable_attendedFrom')} - Queue Call Attended`
          : `${prevDest} - Queue Answered`;
     }
     
     if (classifyNumber(Number(origDest)) === 'InternalExtension') {
     const queuePresence = await redisClient.get(`queueKey:${ev.getHeader('variable_bridge_uuid')}:${domain}`);
     const [queueExt]    = queuePresence ? queuePresence.split('@') : [null];
     
     if (feature === 'attended_transfer') {
          if (queueExt) return 'Queue Attended';
          if (ccQueue)  return `${queueExtension} - Queue Answered`;
          if (origDest == "false"){
               const newOrigDest = ev.getHeader('variable_caller_id_name');
               console.log("returninggg", `${newOrigDest} - Inbound Attended Answered`);
               return `${newOrigDest} - Inbound Attended Answered`
          }
          return `${origDest} - Inbound Attended Answered`;
     }
     if (endpoint === 'BLIND_TRANSFER') return `${endpointDestination} - Inbound Blind`;
     if (queueExt) {
          return ev.getHeader('variable_blindFrom')
          ? `${ev.getHeader('variable_blindFrom')} - Queue Call Blind`
          : `${queueExt} - Queue Answered`;
     }
     return 'Inbound Answered';
     }
     
     if (classifyNumber(Number(origDest)) === 'ExternalNumber') {
     return feature === 'attended_transfer'
          ? `${origDest} - Outbound Attended Answered`
          : endpoint === 'BLIND_TRANSFER' ? 'Outbound Blind' : 'Outbound Answered';
     }
     }
     
     async function resolvePreviousAttendedAgent(ev, domain, registrations) {
     const prevCall = ev.getHeader('variable_attendedPreviousCall');
     if (!prevCall) return null;
     const channelExactName = await presenceId(prevCall);
     const { reg, row }     = await resolveExtension(channelExactName, domain, registrations);
     if (row) return { cid_name: row.effective_caller_id_name, cid_num: row.extension };
     return { cid_name: channelExactName, cid_num: channelExactName };
     }
     
     async function handleTeamsMissed(ev, domain, registrations, origDest, attendedPreviousAgent, skipPush) {
     if (origDest) return; // only for the no-cid_num/origDest fallback path
     const originateLeg = ev.getHeader('variable_originating_leg_uuid');
     if (!originateLeg) return;
     const dataTeams = await redisClient.get(`teams-${originateLeg}-${domain}`);
     if (!dataTeams) return;
     
     const { cid_num, cid_name, extensionUUID, teamsExtension } = JSON.parse(dataTeams);
     const TeamAgent  = await presenceId(ev.getHeader('Channel-Name'));
     const { row }    = await resolveExtension(TeamAgent, domain, registrations);
     const team_cid_num = row?.extension ?? TeamAgent;
     const statusState  = classifyNumber(Number(teamsExtension)) === 'Teams'
     ? `Teams Missed ( ${team_cid_num} )` : null;
     
     if (!statusState) return;
     const payload = buildPayload(ev, {
     cid_name, cid_num, dest: teamsExtension,
     duration: ev.getHeader('variable_duration'), status: statusState,
     });
     
     if (!skipPush) {
     await pushLastActive(ev, payload, 2000);

     console.log("outbound4", statusState)
     callLogsOutboundCall(ev, cid_name, cid_num, teamsExtension, 'normal', statusState,
          extensionUUID, ev.getHeader('variable_hangup_cause'), attendedPreviousAgent);
     await redisClient.del(`teams-${originateLeg}-${domain}`);
     }
     }
     
     async function handleInboundDestroy(ev, conn, domain, registrations) {
          await redisClient.set(`duration-${ev.getHeader('Unique-ID')}`, ev.getHeader('variable_duration'), { EX: 172800 }); // [perf] TTL

          // Decline push notification
          // const validHangup = ['ORIGINATOR_CANCEL', 'CALL_REJECTED'];

          const validHangup = ['ORIGINATOR_CANCEL'];
          const validationHangup2 =  (ev.getHeader('variable_sip_hangup_disposition') === 'recv_bye' && Number(ev.getHeader('variable_billsec')) < 2)

          if (validHangup.includes(ev.getHeader('variable_hangup_cause')) || validationHangup2) {
               console.log("???")
               const rawExt  = ev.getHeader('variable_dialed_user')?.trim()
                              || ev.getHeader('variable_dialed_extension')?.trim()
                              || ev.getHeader('Caller-Destination-Number')?.trim();
               const validExt = isValidNumber(rawExt) ? rawExt : await (async () => {
                    const { rows } = await db2.query(
                    'SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2',
                    [rawExt, domain]
                    );
                    return rows[0]?.extension || null;
               })();
               
               if (validExt && classifyNumber(Number(validExt)) === 'InternalExtension') {
                    console.log("????")
                    const getPrevIdIOS = await redisClient.get(`IOS-${ev.getHeader('Caller-Context')}@${validExt}`);
                    const notifyPayload = {
                         domain, extension: validExt,
                         time: ev.getHeader('Event-Date-Timestamp'), 
                         id: uuidv4(), 
                         call: 'decline',
                         caller_id:  ev.getHeader('variable_effective_caller_id_number') || ev.getHeader('Caller-Caller-ID-Number'),
                         nameCaller: ev.getHeader('variable_effective_caller_id_name')   || ev.getHeader('Caller-Caller-ID-Name'),
                         time2: ev.getHeader('Event-Date-Timestamp')
                    };
               
                    const origExtension = await redisClient.get(`sipIp:${validExt}@${domain}`);
                    const callerSipAlias = ev.getHeader('variable_sip_alias');
                    const payload2 = origExtension
                    ? { 
                         ...notifyPayload, 
                         extension: origExtension, 
                         nameCaller: `${notifyPayload.nameCaller}.`,
                         caller_id: callerSipAlias || notifyPayload.caller_id 
                    }
                    : notifyPayload;
                    
                    await new Promise((r) => setTimeout(r, 3000));
                    getToken(payload2, ev.getHeader('Hangup-Cause'));
                    console.log("before getTokenIos 9");
                    if (getPrevIdIOS === ev.getHeader('Unique-ID')) getTokenIOS(payload2, ev.getHeader('Hangup-Cause'));
                    getTokenMultipleDevice(payload2, ev.getHeader('Hangup-Cause'));
               }
          }


     
          const gatewayInbound = (() => {
          const data = ev.getHeader('variable_current_application_data') || '';
          return data.includes('gateway') ? data.split('gateway/')[1]?.split('/')[0] : '';
          })();
     
          const presenceName = await presenceId(ev.getHeader('Caller-Channel-Name'));
          const rawDest      = ev.getHeader('Caller-Destination-Number') || '';
          const origDestination = rawDest.includes('*99') ? rawDest.split('*99')[1] : rawDest;
          console.log("this is origDestination", origDestination);
          const resolveExt = async (ext) => {
               const { row } = await resolveExtension(ext, domain, registrations);
               if (row) return { cid_name: row.effective_caller_id_name, cid_num: row.extension };
               return { cid_name: ext, cid_num: ext };
          };
          
          const [caller, destination] = await Promise.all([
          resolveExt(presenceName),
          resolveExt(origDestination),
          ]);
          const { cid_name, cid_num } = caller;
          const origDest = destination.cid_num;
     
          const hangupCause = ev.getHeader('variable_cc_cancel_reason') || ev.getHeader('variable_hangup_cause');
          console.log("?????", ev.getHeader('variable_hangup_cause'));
          const ABANDONED = ['ORIGINATOR_CANCEL', 'BREAK_OUT'];
          const BUSY      = ['NORMAL_CLEARING']
          const FAILED    = ['UNALLOCATED_NUMBER','NORMAL_UNSPECIFIED','OUTGOING_CALL_BARRED','INCOMPATIBLE_DESTINATION','SERVICE_UNAVAILABLE','SUBSCRIBER_ABSENT','NO_USER_RESPONSE'];
          const phrase    = ['Not Found', 'Forbidden'];
     
          const pushInbound = async (statusState, delayMs = 2000, alsoFailed = false) => {
          const payload = buildPayload(ev, {
               cid_name, cid_num, dest: origDest, status: statusState,
               gateway: ev.getHeader('variable_sip_gateway_name') || gatewayInbound,
          });
          await pushLastActive(ev, payload, delayMs);
          if (alsoFailed) {
               await pushFailed(ev, payload);
            console.log("failedd")
          }
          callLogsInboundCall(ev, cid_name, cid_num, origDest, 'normal', statusState);
          };
     
     const abandonedMap = {
     Teams:             'Teams Caller Abandoned',
     InternalExtension: 'Inbound Caller Abandoned',
     Queue:             'Queue Caller Abandoned',
     ExternalNumber:    'Outbound Caller Abandoned',
     };

     if (
          (ABANDONED.includes(hangupCause) ||
          (ev.getHeader('variable_pin_number') && ABANDONED.includes(ev.getHeader('variable_originate_failed_cause'))) ||
          (BUSY.includes(ev.getHeader('variable_hangup_cause')) && ev.getHeader('variable_last_arg') === 'pin_number.lua')) &&
          !phrase.includes(ev.getHeader('variable_sip_hangup_phrase'))
     ){
          const statusState = statusForDest(origDest, abandonedMap);
          console.log("failedd111")
          if (statusState) await pushInbound(statusState);
     }
     
     if (
          BUSY.includes(ev.getHeader('variable_hangup_cause')) &&
          ev.getHeader('variable_send_to_voicemail') === 'true' &&
          ev.getHeader('variable_originate_failed_cause') !== 'NO_ANSWER'
     ) {
          console.log("failedd1111")
          const missedMap = {
               InternalExtension: 'Inbound Missed', Queue: 'Queue Missed',
               ExternalNumber: 'Outbound Missed', Teams: 'Teams Missed',
          };

          const statusState = statusForDest(origDest, missedMap);
          if (statusState) {
               socketClient.emit('lastActive', domain);
               callLogsInboundCall(ev, cid_name, cid_num, origDest, 'normal', statusState);
          }
     }
     
     // Ratings / SpecialIVR
     if (BUSY.includes(ev.getHeader('variable_hangup_cause'))) {
     const kind = classifyNumber(Number(origDest));
     if (kind === 'SpecialIVR') {
          console.log("SpecialIVP only 1.1");
          const label = (Number(origDest) >= 3000 && Number(origDest) < 4000)
          ? '3000 - Inbound Ratings' : `${origDest} - Inbound Ratings`;
          const ratingPresence = await presenceId(ev.getHeader('variable_bridge_channel'));
          const { row: agentRow } = await resolveExtension(ratingPresence, domain, registrations);
          const prevAgent = agentRow?.extension ?? ratingPresence;
          const ratingPayload = buildPayload(ev, {
          cid_name, cid_num, dest: prevAgent, status: label,
          gateway: ev.getHeader('variable_sip_gateway_name') || gatewayInbound,
          });
          await pushLastActive(ev, ratingPayload, 2000);
          callLogsInboundCall(ev, cid_name, cid_num, prevAgent, 'normal', label);
     } else if (kind === 'AI' || kind === 'IVR') {
          const status = kind === 'AI' ? 'AI Answered' : 'IVR Answered';
          await pushInbound(status, 2000);
     }
     }
     
     // Failed calls
     const failedCause =  ev.getHeader('variable_last_bridge_hangup_cause') !== 'NORMAL_CLEARING' ? ev.getHeader('variable_last_bridge_hangup_cause') : ev.getHeader('variable_hangup_cause');
     const validateFailed = ev.getHeader('variable_originate_failed_cause') === 'USER_NOT_REGISTERED' && ev.getHeader('variable_voicemail_action') === 'save'
     if (FAILED.includes(failedCause) || validateFailed) {
          const failedMap = {
               InternalExtension: 'Inbound Failed', Queue: 'Queue Failed',
               ExternalNumber: 'Outbound Failed', AI: 'AI Failed',
               IVR: 'IVR Failed', Teams: 'Teams Failed',
          };
          console.log("failedd11111")
          const statusState = statusForDest(origDest, failedMap);
          if (statusState) await pushInbound(statusState, 2000, true);
     }

     if(ev.getHeader('variable_hangup_cause') === 'USER_BUSY' && ev.getHeader('variable_current_application') === 'limit'){
          const busyMap = {
               InternalExtension: 'Inbound Busy', Queue: 'Queue Busy',
               ExternalNumber: 'Outbound Buys', AI: 'AI Busy',
               IVR: 'IVR Busy', Teams: 'Teams Busy',
          };
          const statusState = statusForDest(origDest, busyMap);
          if (statusState) await pushInbound(statusState, 2000, true);
     }
     
     // CDR logging
     const transferSource = ev.getHeader('variable_transfer_source');
     if (transferSource) {
     if (transferSource.includes('uuid_br') && ev.getHeader('Channel-Call-UUID')) {
          const tk = `transfer-${ev.getHeader('Channel-Call-UUID')}-${domain}`;
          if (!(await redisClient.exists(tk))) await redisClient.set(tk, 1, { EX: 3000 });
     } else {
          callLogsData({ type: 4, ev, state: 'transfer' });
     }
     } else {
     callLogsData({
          type: ev.getHeader('variable_ivr_menu_status') ? 3 : ev.getHeader('variable_cc_cause') ? 2 : 1,
          ev, state: 'normal',
     });
     }
     
     
     }
     
     
     async function handleInboundQueue(ev, conn, domain) {
     const ccQueue = ev.getHeader('variable_cc_queue');
     if (!ccQueue) {


          console.log("there's no ccQueue", ev.getHeader('variable_cc_queue'))
          //      const [queueExtension, subdomain2] = ccQueue.split('@');

          // const queueAgents = await new Promise((resolve, reject) => {
          //           try {
          //           conn.bgapi(
          //                `json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ccQueue}"}}`,
          //                (res) => resolve(JSON.parse(res.body).response)
          //           );
          //           } catch (e) { reject(e); }
          //      });

          //           const callerNumber = ev.getHeader('variable_caller_id_number');
          //           const valid        = isValidNumber(callerNumber);


          //      const missedPayload = async (caller_id, nameCaller) => ({
          //           domain: domain, extension: queueExtension,
          //           time: (Date.now() * 1000).toString(), id: uuidv4(),
          //           extension: ev.getHeader('Caller-Destination-Number'),
          //           call: isValidCalleeEnd() ? 'decline' : 'missed', caller_id, nameCaller,
          //      });

          //      console.log("what is this isValidCalleeEnd?", isValidCalleeEnd() ? 'decline': 'missed');
          //      // ADDED EMAN 08-27-26 END
               
          //      if (!valid) {
          //           console.log("look1")
          //           const response = await redisClient.get(`extension-${callerNumber}@${subdomain2}`);
          //           if (response) {
          //           const { extension } = JSON.parse(response);
          //           console.log("look3")
          //           pushQueue(queueAgents, await missedPayload(extension, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          //           // ADDED EMAN 08-27-26 START
          //           pushQueueIOS(queueAgents, await missedPayload(extension, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          //           // ADDED EMAN 08-27-26 END
          //           }
          //      } else {
          //           //Usable for originator caller queue
          //           console.log("look2")
          //           pushQueue(queueAgents, await missedPayload(callerNumber, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          //           // ADDED EMAN 08-27-26 START
          //           pushQueueIOS(queueAgents, await missedPayload(callerNumber, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          //           // ADDED EMAN 08-27-26 END
          //      }


     }

     else {
     
     const boardList    = await redisClient.lRange(`queueCallBoard-${domain}`, 0, -1);
     console.log("has callboard 2", boardList)
     //0327123106
     redisClient.set(`readyForDecline-${ev.getHeader('Caller-Orig-Caller-ID-Number')}`, `1`);
     const parseBoard   = boardList.map(el => JSON.parse(el));
     const findCallBoard = parseBoard.find(i => i.uuid === ev.getHeader('Channel-Call-UUID'));
     const hangupCause  = ev.getHeader('variable_hangup_cause');
     const cancelReason = ev.getHeader('variable_cc_cancel_reason');
     
     if (
     ev.getHeader('variable_bridge_channel') &&
     hangupCause === 'NORMAL_CLEARING' &&
     !ev.getHeader('variable_attended_process')
     ) {
     if (ev.getHeader('variable_cc_agent_push')) {
          const { cid_number, cid_name } = findCallBoard || {};
          const [qExtension, qSubDomain] = ccQueue.split('@');
          const endEpoch        = Number(ev.getHeader('variable_end_epoch'));
          const waitingSeconds  = Number(ev.getHeader('variable_bridge_epoch')) - Number(ev.getHeader('variable_cc_queue_joined_epoch'));
          const answeredSeconds = endEpoch - Number(ev.getHeader('variable_bridge_epoch'));
          socketClientQueue.emit('broadcast', {
          uuid: ev.getHeader('Channel-Call-UUID'), extension: qExtension, channel: qSubDomain,
          callerName: cid_name, callerNumber: cid_number, command: 'ended', cc_member_uuid: ev.getHeader('variable_cc_member_uuid') || undefined, /* [wallboard-dedupe] */
          cc_agent: ev.getHeader('variable_cc_agent_push') || null,
          waitingSeconds, answeredSeconds,
          lastBridgeStart: Number(ev.getHeader('variable_bridge_epoch')), lastBridgeEnd: endEpoch, lastOfferedCall: endEpoch,
          });
     } else if (findCallBoard) {
          const { serving_agent, cid_number, cid_name } = findCallBoard;
          const [qExtension, qSubDomain] = ccQueue.split('@');
          const waitingSeconds  = Number(ev.getHeader('variable_cc_queue_answered_epoch')) - Number(ev.getHeader('variable_cc_queue_joined_epoch'));
          const answeredSeconds = Number(ev.getHeader('variable_cc_queue_terminated_epoch')) - Number(ev.getHeader('variable_cc_queue_answered_epoch'));
          socketClientQueue.emit('broadcast', {
          uuid: ev.getHeader('Channel-Call-UUID'), extension: qExtension, channel: qSubDomain,
          callerName: cid_name, callerNumber: cid_number, command: 'ended', cc_member_uuid: ev.getHeader('variable_cc_member_uuid') || undefined, /* [wallboard-dedupe] */
          cc_agent: serving_agent || null, waitingSeconds, answeredSeconds,
          lastBridgeStart: Number(ev.getHeader('variable_cc_queue_answered_epoch')),
          lastBridgeEnd: Number(ev.getHeader('variable_cc_queue_terminated_epoch')),
          lastOfferedCall: Number(ev.getHeader('variable_cc_queue_terminated_epoch')),
          });
     } else {
          const queueAgent = await redisClient.get(`previousAgent-${ev.getHeader('Channel-Call-UUID')}`);
          if (!queueAgent) return;
          const [qExtension, qSubDomain] = ccQueue.split('@');
          const waitingSeconds  = Number(ev.getHeader('variable_cc_queue_answered_epoch')) - Number(ev.getHeader('variable_cc_queue_joined_epoch'));
          const answeredSeconds = Number(ev.getHeader('variable_cc_queue_terminated_epoch')) - Number(ev.getHeader('variable_cc_queue_answered_epoch'));
          socketClientQueue.emit('broadcast', {
          uuid: ev.getHeader('Channel-Call-UUID'), extension: qExtension, channel: qSubDomain,
          callerName: null, callerNumber: null, command: 'ended', cc_member_uuid: ev.getHeader('variable_cc_member_uuid') || undefined, /* [wallboard-dedupe] */
          cc_agent: queueAgent, waitingSeconds, answeredSeconds,
          lastBridgeStart: Number(ev.getHeader('variable_cc_queue_answered_epoch')),
          lastBridgeEnd: Number(ev.getHeader('variable_cc_queue_terminated_epoch')),
          lastOfferedCall: Number(ev.getHeader('variable_cc_queue_terminated_epoch')),
          });
          await redisClient.del(`previousAgent-${ev.getHeader('Channel-Call-UUID')}`);
     }
     }
     
     if (!ev.getHeader('variable_bridge_channel') && cancelReason === 'BREAK_OUT') {
     const [qExtension, qSubDomain] = ccQueue.split('@');
     const waitingSeconds = Number(ev.getHeader('variable_cc_queue_canceled_epoch')) - Number(ev.getHeader('variable_cc_queue_joined_epoch'));

     console.log('<!--- This is called here CANCEL QUEUE', ev.getHeader('Event-Name'), ev.getHeader('Call-Direction'));
     
     socketClientQueue.emit('broadcast', {
          uuid: ev.getHeader('Channel-Call-UUID'), extension: qExtension, channel: qSubDomain,
          callerName: ev.getHeader('variable_effective_caller_id_name'),
          callerNumber: ev.getHeader('variable_effective_caller_id_number'),
          command: 'ORIGINATOR_CANCEL', cc_agent: null,
          waitingSeconds, answeredSeconds: null,
          lastBridgeStart: null, lastBridgeEnd: null,
          lastOfferedCall: ev.getHeader('variable_cc_queue_canceled_epoch'),
          cc_member_uuid: ev.getHeader('variable_cc_member_uuid')
     });
     }
     
     if (findCallBoard) {
     const idx = parseBoard.findIndex(i => i.uuid === ev.getHeader('Channel-Call-UUID'));
     // CHECK removing queueCallboard
     console.log("qcallboard check 1", `queueCallBoard-${domain}`, idx, JSON.stringify(findCallBoard))
     await redisClient.lRem(`queueCallBoard-${domain}`, idx, JSON.stringify(findCallBoard));
     }
     
     // Queue missed — push notification to agents
     if (cancelReason === 'BREAK_OUT') {
     const [queueExtension, subdomain2] = ccQueue.split('@');
     const { rows: agentRows } = await db2.query(
          'SELECT agent_contact FROM v_call_center_agents WHERE queue_extension = $1 AND agent_contact LIKE $2',
          [queueExtension, `%${subdomain2}%`]
     );
     
     const aliasNames = agentRows.map(r => r.agent_contact.match(/\/([^@]+)@/)?.[1]).filter(Boolean);
     const extensionAgents = await Promise.all(
          aliasNames.map(async alias => {
          const { rows } = await db2.query(
               'SELECT extension FROM v_extensions WHERE number_alias = $1 AND domain_uuid = $2',
               [alias, ev.getHeader('variable_domain_uuid')]
          );
          return rows[0]?.extension || null;
          })
     );
     const uniqueExtensions = [...new Set(extensionAgents.filter(Boolean))];
     
     const queueAgents = await new Promise((resolve, reject) => {
          try {
          conn.bgapi(
               `json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${ccQueue}"}}`,
               (res) => resolve(JSON.parse(res.body).response)
          );
          } catch (e) { reject(e); }
     });
     
     const callerNumber = ev.getHeader('variable_caller_id_number');
     const valid        = isValidNumber(callerNumber);
     
     // ADDED EMAN 08-27-26 START
     console.log("✅️ This is Ev checking last_arg", util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true }))
     
     function isValidCalleeEnd() {
          const rawDest = ev.getHeader('Caller-Destination-Number') || ev.getHeader('variable_caller_destination');
          const dest = parseInt(rawDest, 10);
          const hangupCause = ev.getHeader('variable_last_bridge_proto_specific_hangup_cause');
          // 1. Destination range validation
          if (isNaN(dest) || dest < 9000 || dest > 9999) {
               return false;
          }
          // 2. Treat standard 200 OK as false (not a decline)
          if (!hangupCause || hangupCause === 'sip:200') {
               return false;
          }
          // 3. Flag non-200 SIP hangup causes as true ('decline')
          return true;
     }

     const missedPayload = async (caller_id, nameCaller) => ({
          domain: subdomain2, extension: queueExtension,
          time: (Date.now() * 1000).toString(), id: uuidv4(),
          extension: ev.getHeader('Caller-Destination-Number'),
          call: isValidCalleeEnd() ? 'decline' : 'missed', caller_id, nameCaller,
     });

     console.log("what is this isValidCalleeEnd?", isValidCalleeEnd() ? 'decline': 'missed');
     // ADDED EMAN 08-27-26 END
     
     if (!valid) {
          console.log("look1")
          const response = await redisClient.get(`extension-${callerNumber}@${subdomain2}`);
          if (response) {
          const { extension } = JSON.parse(response);
          console.log("look3")
          pushQueue(queueAgents, await missedPayload(extension, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          // ADDED EMAN 08-27-26 START
          pushQueueIOS(queueAgents, await missedPayload(extension, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          // ADDED EMAN 08-27-26 END
          }
     } else {


          //Usable for originator caller queue
          console.log("look2")
          pushQueue(queueAgents, await missedPayload(callerNumber, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          // ADDED EMAN 08-27-26 START
          pushQueueIOS(queueAgents, await missedPayload(callerNumber, ev.getHeader('variable_caller_id_name')), uniqueExtensions);
          // ADDED EMAN 08-27-26 END
     }
     }
     
     prevActiveCallsRefresh(domain, ev.getHeader('variable_duration'), ev.getHeader('Unique-ID'));
          }
     }
          


     
     async function handleIOSEndCall(ev, domain) {
          const channelName = ev.getHeader('Channel-Name') || '';
          if (!channelName.includes('internal')) return;
          
          const extContact = await sipProfileURL(channelName);
          const deviceId   = await redisClient.get(`${extContact}-IOS-deviceId`);
          if (!deviceId) return;
          
          const devicePushToken = await redisClient.get(`devicePushtoken-${deviceId}`);
          if (!devicePushToken) return;
          
          const channelPresence = ev.getHeader('Channel-Presence-ID')?.split('@')[0];
          let extensionOrig     = channelPresence;
          
          if (!isValidNumber(channelPresence)) {
          const { rows } = await db2.query(
               'SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2',
               [channelPresence, domain]
          );
          if (rows.length) extensionOrig = rows[0].extension;
          }
          
          await redisClient.set(`activeCallEnd@${domain}-${devicePushToken}`, devicePushToken, { EX: 5 });
          setTimeout(async () => {
               await redisClient.sRem(`activeCall-${extensionOrig}@${domain}`, devicePushToken);
          }, 1000);
     }
     
}

const handleHeartbeat = async (ev, bd, conn) => {
     // console.log(`✅️ CHANNEL_HEARTBEAT`, 
     //      util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     // );
}

const handleReSched = async (ev, bd, conn) => {
     // console.log(`✅️ CHANNEL_RESCHED`, 
     //      util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     // );
}

const extensionFunction = async (alias, domain) => {
     const checkValid = isValidNumber(alias)
     if(!checkValid){
          const { rows } = await db2.query(
               `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
               [alias , domain]
          );
          if(rows.length){
               const extensionOrig = rows[0].extension;
               return extensionOrig 
          }
          return alias
     }
     return alias
}

const getAlias = async(presence, domain) => {
     return new Promise((resolve, reject) => {
          conn.api(`sofia status profile internal reg`, async (reg) => {
               try {

                    function parseLog(log) {
                         const blocks = log.trim()?.split(/\n\s*\n/); // split by empty line
                         const registrations = [];
                         let total = 0;

                         for (const block of blocks) {
                         if (block.startsWith("Total items")) {
                              total = parseInt(block.replace(/\D+/g, ""), 10);
                              continue;
                         }

                         const entry = {};
                         block?.split("\n").forEach(line => {
                              const match = line.match(/^(.*?):\s*\t?(.*)$/);
                              if (match) {
                                   const key = match[1].trim();
                                   const value = match[2].trim();
                                   entry[key] = value;
                              }
                         });
                         registrations.push(entry);
                         }

                         return { Registrations: registrations, Total: total };
                    }
                    const result = parseLog(reg.body);
                    const match = result.Registrations.find(
                         (x) => regInDomain(x, domain) && regMatch(x, presence) // [reg-match] [tenant-reg]
                    );

                    if (match) {

                         const {'Auth-User': alias} = match;
                         resolve(alias)

                    } else {

                         const { rows } = await db2.query(
                              `SELECT number_alias FROM v_extensions WHERE extension = $1 AND accountcode = $2`,
                              [presence, domain]
                         );
                         console.log(`📌📌`, rows, presence)
                         if(rows.length){
                              resolve(rows[0].number_alias)
                         }
                         resolve(presence);

                    }

               } catch (err) {
                    reject(err);
               }
          });
     });
}


function fsRegistration(){
return new Promise((resolve, reject) => {
     conn.api(`sofia status profile internal reg`, (reg) => {
     try {
               function parseLog(log) {
               const blocks = log.trim().split(/\n\s*\n/); // split by empty line
               const registrations = [];
               let total = 0;

               for (const block of blocks) {
               if (block.startsWith("Total items")) {
                         total = parseInt(block.replace(/\D+/g, ""), 10);
                         continue;
               }

               const entry = {};
               block.split("\n").forEach(line => {
                         const match = line.match(/^(.*?):\s*\t?(.*)$/);
                         if (match) {
                         const key = match[1].trim();
                         const value = match[2].trim();
                         entry[key] = value;
                         }
               });
               registrations.push(entry);
               }

               return { Registrations: registrations, Total: total };
               }

               const result = parseLog(reg.body);
               if (result) {
               resolve(result.Registrations); // return the match properly
               } else {
               resolve(null); // no match found
               }

     } catch (err) {
               reject(err);
     }
     });
});
}


async function callLogsInboundCall(ev, cidName, cidNum, destination, type, status){

     const timeZoneDB = async (domainId) => {
          db2.query(
          `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
          [domainId],
          async (err, result) => {
               if (err) {
                    console.error("Error executing agents query:", err.message);
                    return;
               }
               return result.rows.length > 0
               ? result.rows[0].user_setting_value
               : Intl.DateTimeFormat().resolvedOptions().timeZone;
          });
     }

     const timezone = await timeZoneDB(ev.getHeader('variable_domain_uuid'))
     const recordingPath = recordingFilePath(ev.getHeader('Channel-Call-UUID'), ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));
     const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/').pop() : ev.getHeader('Unique-ID');
     const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;
     
     const legTransfer = await (async() => {
          if(
               ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
               ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
          ){
               return 'a'
          }
     })();

     const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'long'
     });

     const startTime = new Date(Number(ev.getHeader('variable_process_start_epoch') || ev.getHeader('variable_start_epoch')) * 1000);
     const answeredTime = new Date(Number(ev.getHeader('variable_process_answer_epoch') || ev.getHeader('variable_answer_epoch')) * 1000);
     const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);

     const newStartEpoch = Math.floor(startTime.getTime() / 1000);
     const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
     const newEndEpoch = Math.floor(endTime.getTime() / 1000);

     const newStartTime = ev.getHeader('variable_process_start_epoch')  || ev.getHeader('variable_start_epoch');
     const newAnsweredTime = ev.getHeader('variable_process_answer_epoch') || ev.getHeader('variable_answer_epoch');
     const newEndTime = ev.getHeader('variable_end_epoch');

     console.log({
        "Argentina Time Start": formatter.format(startTime),
        "Argentina Time Answered:": formatter.format(answeredTime),
        "Argentina Time: End": formatter.format(endTime),
        newStartEpoch,
        newAnsweredEpoch,
        newEndEpoch,
        "Start": utcTime(newStartTime),
        "Answered": utcTime(newAnsweredTime),
        "End": utcTime(newEndTime)
    });

    const newWaitSeconds = newStartTime === newAnsweredTime ? newEndTime - newStartTime : newAnsweredTime - newStartTime;


    const voicemail_message = !!ev.getHeader('variable_voicemail_message_seconds');

    const callLogsPayload2 = {
            call_id: ev.getHeader('Unique-ID'),
            direction: ev.getHeader('variable_call_direction'),
            extension_uuid: ev.getHeader('variable_extension_uuid'),
            call_center_queue_uuid: null,
            start_stamp: utcTime(newStartTime),
            answer_stamp: utcTime(newAnsweredTime),
            start_epoch: newStartEpoch,
            answer_epoch: newAnsweredEpoch,
            end_stamp: utcTime(newEndTime),
            end_epoch: newEndEpoch,
            caller_id_name: cidName,
            caller_id_number: cidNum,
            caller_destination: destination,
            destination_number: destination,
            duration: ev.getHeader('variable_duration'),
            voicemail_message,
            missed_call: true,
            waitsec: ev.getHeader('variable_waitsec'),
            billsec: ev.getHeader('variable_billsec'), 
            hangup_cause: ev.getHeader('variable_hangup_cause'),
            source_number: ev.getHeader('variable_number_alias') || ev.getHeader('variable_caller_id_number'),
            record_name: fileName,
            record_path: filePath,
            domain_uuid: ev.getHeader('variable_domain_uuid'),
            sip_call_id: ev.getHeader('variable_orig_sip_call_id'),
            hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
            sip_hangup_disposition: ev.getHeader('variable_sip_hangup_disposition'),
            cc_queue: ev.getHeader('variable_cc_queue'),
            cc_agent_uuid: ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('variable_cc_agent'),
            cc_agent: ev.getHeader('variable_cc_agent'),
            cc_cause: ev.getHeader('variable_cc_cause'),
            cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch'),
            record_length: ev.getHeader('variable_record_seconds') || 0,
            type,
            originating_leg_uuid: ev.getHeader('variable_originating_leg_uuid') || ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('Unique-ID'),
            leg: legTransfer,
            last_arg: status
    };

    if(destination !== 'bridge_to_agent'){
    console.log(`🌳 Inbound Report`, callLogsPayload2);
    redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));
             console.log("this is where it save in rep", `callLogs-${ev.getHeader('variable_domain_name')}`);
    }

}

async function callLogsOutboundCall(ev, cidName, cidNum, destination, type, status, extensionId, hangupCause, attendedPrev, durationOrig){

     
     const timeZoneDB = async (domainId) => {
          db2.query(
          `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
          [domainId],
          async (err, result) => {
               if (err) {
                    console.error("Error executing agents query:", err.message);
                    return;
               }
               return result.rows.length > 0
               ? result.rows[0].user_setting_value
               : Intl.DateTimeFormat().resolvedOptions().timeZone;
          });
     }

     const timezone = await timeZoneDB(ev.getHeader('variable_domain_uuid'))
     const recordingPath = recordingFilePath(ev.getHeader('variable_bridge_uuid'), ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));
     const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/')?.pop() : ev.getHeader('Unique-ID');
     const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;
     
     const legTransfer = await (async() => {
          if(
               ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
               ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER' ||
               ev.getHeader('variable_transferType' === "Attended")
          ){
               return 'b'
          }
     })();

     const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'long'
     });

     const startTime = new Date(Number(ev.getHeader('variable_process_start_epoch') || ev.getHeader('variable_start_epoch')) * 1000);
     const answeredTime = new Date(Number(ev.getHeader('variable_process_answer_epoch') 
     || Number(ev.getHeader('variable_answer_epoch')) > 0 ? ev.getHeader('variable_answer_epoch') : ev.getHeader('variable_start_epoch')) * 1000);
     const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);

     const newStartEpoch = Math.floor(startTime.getTime() / 1000);
     const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
     const newEndEpoch = Math.floor(endTime.getTime() / 1000);

     const newStartTime = ev.getHeader('variable_process_start_epoch')  || ev.getHeader('variable_start_epoch');
     const newAnsweredT = Number(ev.getHeader('variable_answer_epoch')) > 0 ? ev.getHeader('variable_answer_epoch') : ev.getHeader('variable_start_epoch')
     const newAnsweredTime = ev.getHeader('variable_process_answer_epoch') || newAnsweredT;
     const newEndTime = ev.getHeader('variable_end_epoch');

     const uniqueCallId = ev.getHeader('Unique-ID') || uuidv4();

     const callDirection = async () => {
          if(classifyNumber(Number(destination)) === 'ExternalNumber'){
               return 'outbound'
          }
          return 'inbound'
     }

     console.log({
        "Argentina Time Start": formatter.format(startTime),
        "Argentina Time Answered:": formatter.format(answeredTime),
        "Argentina Time: End": formatter.format(endTime),
        newStartEpoch,
        newAnsweredEpoch,
        newEndEpoch,
        "Start": utcTime(newStartTime),
        "Answered": utcTime(newAnsweredTime),
        "End": utcTime(newEndTime)
     });

     const newWaitSeconds = newStartTime === newAnsweredTime ? newEndTime - newStartTime : newAnsweredTime - newStartTime;

     const voicemail_message = hangupCause === 'NO_ANSWER';
     const callLogsPayload2 = {
          call_id: uniqueCallId,
          direction: await callDirection(),
          extension_uuid: extensionId,
          call_center_queue_uuid: null,
          start_stamp: utcTime(newStartTime),
          answer_stamp: utcTime(newAnsweredTime),
          start_epoch: newStartEpoch,
          answer_epoch: newAnsweredEpoch,
          end_stamp: utcTime(newEndTime),
          end_epoch: newEndEpoch,
          caller_id_name: attendedPrev ? attendedPrev.cid_name : cidName,
          caller_id_number: attendedPrev ? attendedPrev.cid_num : cidNum,
          caller_destination: attendedPrev ? cidNum : destination || ev.getHeader('Caller-Destination-Number'),
          destination_number: attendedPrev ? cidNum : destination || ev.getHeader('Caller-Destination-Number'),
          duration: durationOrig ? durationOrig : ev.getHeader('variable_billsec') ? 
          ( Number(ev.getHeader('variable_billsec')) > 0 ? ev.getHeader('variable_billsec') : ev.getHeader('variable_duration')) 
          : ev.getHeader('variable_duration'),
          voicemail_message,
          missed_call: voicemail_message,
          waitsec: ev.getHeader('variable_waitsec'),
          billsec: ev.getHeader('variable_billsec'), 
          hangup_cause: ev.getHeader('variable_hangup_cause'),
          source_number: cidNum,
          record_name: fileName,
          record_path: filePath,
          domain_uuid: ev.getHeader('variable_domain_uuid') || '66a1e46a-11d8-4263-873c-8932a7a9fce3',
          sip_call_id: ev.getHeader('variable_orig_sip_call_id'),
          hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
          sip_hangup_disposition: ev.getHeader('variable_sip_hangup_disposition'),
          cc_queue: ev.getHeader('variable_cc_queue'),
          cc_agent_uuid: ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('variable_cc_agent'),
          cc_agent: ev.getHeader('variable_cc_agent'),
          cc_cause: ev.getHeader('variable_cc_cause'),
          cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch'),
          record_length: ev.getHeader('variable_record_seconds'),
          type,
          originating_leg_uuid: ev.getHeader('variable_originating_leg_uuid') || ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('Unique-ID'),
          leg: legTransfer,
          last_arg: status
          
     };


       if(callLogsPayload2.last_arg.includes("false")){
    // last_arg: 'false - Inbound Attended Answered'
         const setExtension = callLogsPayload2.last_arg.split('-');
         const newLast_arg = `${callLogsPayload2.caller_destination} - ${setExtension[1]}`;

         callLogsPayload2.last_arg = newLast_arg;
         console.log("this is now the newLastarg", newLast_arg, setExtension, callLogsPayload2.last_arg);
    }

     
     
          if (destination !== 'bridge_to_agent') {
          const domain = ev.getHeader('variable_domain_name') || callLogsPayload2.domain_uuid || 'unknown-domain';

          const sipCallId =
          callLogsPayload2.sip_call_id ||
          ev.getHeader('sip_call_id') ||
          ev.getHeader('variable_sip_call_id');

          const destinationNumber =
          callLogsPayload2.destination_number ||
          callLogsPayload2.caller_destination ||
          ev.getHeader('Caller-Destination-Number') ||
          destination;

          const lastArg = callLogsPayload2.last_arg || 'unknown';

          if (!sipCallId || !destinationNumber) {
          console.log("Missing sipCallId or destinationNumber, cannot dedupe safely", {
               sipCallId,
               destinationNumber,
               call_id: callLogsPayload2.call_id
          });

          return;
          }

          const dedupeKey = `callLogPushed:${domain}:${sipCallId}:${destinationNumber}:${lastArg}`;

          const wasSet = await redisClient.set(dedupeKey, '1', {
               NX: true,
               EX: 15
          });

          if (wasSet !== 'OK') {
          console.log("Duplicate call log skipped", {
               dedupeKey,
               call_id: callLogsPayload2.call_id,
               sipCallId,
               destinationNumber,
               lastArg
          });

          return;
          }
          console.log("rPushed", `callLogs-${domain}`);


          await redisClient.rPush(
          `callLogs-${domain}`,
          JSON.stringify(callLogsPayload2)
          );

          const logsRpush = await redisClient.lRange(`callLogs-${domain}`, 0, -1);

          console.log("rpush logs", logsRpush);   
          console.log(`🌳 Outbound Report`, callLogsPayload2, hangupCause, {
          AID: ev.getHeader('Unique-ID'),
          BID: uniqueCallId
          });
          }

}

async function callLogsOutboundCallBlind(ev, cidName, cidNum, destination, type, status, extensionId, uniqueId) {

     const timeZoneDB = async (domainId) => {
          db2.query(
          `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
          [domainId],
          async (err, result) => {
               if (err) {
                    console.error("Error executing agents query:", err.message);
                    return;
               }
               return result.rows.length > 0
               ? result.rows[0].user_setting_value
               : Intl.DateTimeFormat().resolvedOptions().timeZone;
          });
     }

     const timezone = await timeZoneDB(ev.getHeader('variable_domain_uuid'))
     const recordingPath = recordingFilePath(uniqueId, ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));

     const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/')?.pop() : uniqueId;

     const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;

      const legTransfer = await (async() => {
          if(
               ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
               ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
          ){
               return 'a'
          }
     })();

     const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'long'
     });

     const startTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);
     const answeredTime = 0;
     const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);

     const newStartEpoch = Math.floor(endTime.getTime() / 1000);
     const newAnsweredEpoch = 0;
     const newEndEpoch = Math.floor(endTime.getTime() / 1000);

     const newStartTime = ev.getHeader('variable_end_epoch');
     const newAnsweredTime = '0';
     const newEndTime = ev.getHeader('variable_end_epoch');

     console.log({
        "Argentina Time Start": formatter.format(startTime),
        "Argentina Time Answered:": formatter.format(answeredTime),
        "Argentina Time: End": formatter.format(endTime),
        newStartEpoch,
        newAnsweredEpoch,
        newEndEpoch,
        "Start": utcTime(newStartTime),
        "Answered": utcTime(newAnsweredTime),
        "End": utcTime(newEndTime)
    });

    const newWaitSeconds = newStartTime === newAnsweredTime ? newEndTime - newStartTime : newAnsweredTime - newStartTime;
    const voicemail_message = !ev.getHeader('variable_voicemail_message_seconds');
    const callLogsPayload2 = {
            call_id: uniqueId,
            direction: 'inbound',
            extension_uuid: ev.getHeader('variable_extension_uuid'),
            call_center_queue_uuid: null,
            start_stamp: utcTime(newStartTime),
            answer_stamp: utcTime(newAnsweredTime),
            start_epoch: newStartEpoch,
            answer_epoch: newAnsweredEpoch,
            end_stamp: utcTime(newEndTime),
            end_epoch: newEndEpoch,
            caller_id_name: cidName,
            caller_id_number: cidNum,
            caller_destination: destination,
            destination_number: destination,
            duration: '0',
            voicemail_message,
            missed_call: true,
            waitsec: ev.getHeader('variable_waitsec'),
            billsec: ev.getHeader('variable_billsec'), 
            hangup_cause: ev.getHeader('variable_hangup_cause'),
            source_number: ev.getHeader('variable_number_alias') || ev.getHeader('variable_caller_id_number'),
            record_name: fileName,
            record_path: filePath,
            domain_uuid: ev.getHeader('variable_domain_uuid'),
            sip_call_id: ev.getHeader('variable_orig_sip_call_id'),
            hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
            sip_hangup_disposition: ev.getHeader('variable_sip_hangup_disposition'),
            cc_queue: ev.getHeader('variable_cc_queue'),
            cc_agent_uuid: ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('variable_cc_agent'),
            cc_agent: ev.getHeader('variable_cc_agent'),
            cc_cause: ev.getHeader('variable_cc_cause'),
            cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch'),
            record_length: '0',
            type,
            originating_leg_uuid: uniqueId,
            leg: legTransfer,
            last_arg: status
    };

    if(destination !== 'bridge_to_agent'){
          console.log(`🌳 Blind Transfer Report`, callLogsPayload2);
          redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));
          console.log("this is where it save Blind Transfer", `callLogs-${ev.getHeader('variable_domain_name')}`);
    }


}


async function callLogsOutboundCallAttended(ev, cidName, cidNum, destination, type, status, extensionId, uniqueId) {

     const timeZoneDB = async (domainId) => {
          db2.query(
          `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
          [domainId],
          async (err, result) => {
               if (err) {
                    console.error("Error executing agents query:", err.message);
                    return;
               }
               return result.rows.length > 0
               ? result.rows[0].user_setting_value
               : Intl.DateTimeFormat().resolvedOptions().timeZone;
          });
     }

     const timezone = await timeZoneDB(ev.getHeader('variable_domain_uuid'))
     const recordingPath = recordingFilePath(uniqueId, ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));
     const fileName = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename')?.split('/')?.pop() : uniqueId;
     const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;

      const legTransfer = await (async() => {
          if(
               ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
               ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
          ){
               return 'a'
          }
     })();

     const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'long'
     });

     const startTime = new Date(Number(ev.getHeader('variable_start_epoch')) * 1000);
     const answeredTime = new Date(Number(ev.getHeader('variable_answer_epoch')) * 1000);;
     const endTime = new Date(Number(ev.getHeader('variable_end_epoch')) * 1000);

     const newStartEpoch = Math.floor(startTime.getTime() / 1000);
     const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
     const newEndEpoch = Math.floor(endTime.getTime() / 1000);

     const newStartTime = ev.getHeader('variable_start_epoch');
     const newAnsweredTime = ev.getHeader('variable_answer_epoch');
     const newEndTime = ev.getHeader('variable_end_epoch');

     console.log({
        "Argentina Time Start": formatter.format(startTime),
        "Argentina Time Answered:": formatter.format(answeredTime),
        "Argentina Time: End": formatter.format(endTime),
        newStartEpoch,
        newAnsweredEpoch,
        newEndEpoch,
        "Start": utcTime(newStartTime),
        "Answered": utcTime(newAnsweredTime),
        "End": utcTime(newEndTime)
    });

    const newWaitSeconds = newStartTime === newAnsweredTime ? newEndTime - newStartTime : newAnsweredTime - newStartTime;
    const voicemail_message = !ev.getHeader('variable_voicemail_message_seconds');
    const callLogsPayload2 = {
            call_id: uniqueId,
            direction: 'inbound',
            extension_uuid: ev.getHeader('variable_extension_uuid'),
            call_center_queue_uuid: null,
            start_stamp: utcTime(newStartTime),
            answer_stamp: utcTime(newAnsweredTime),
            start_epoch: newStartEpoch,
            answer_epoch: newAnsweredEpoch,
            end_stamp: utcTime(newEndTime),
            end_epoch: newEndEpoch,
            caller_id_name: cidName,
            caller_id_number: cidNum,
            caller_destination: destination,
            destination_number: destination,
            duration:  Number(ev.getHeader('variable_billsec')) > 0 ? ev.getHeader('variable_billsec') : ev.getHeader('variable_duration'),
            voicemail_message,
            missed_call: true,
            waitsec: ev.getHeader('variable_waitsec'),
            billsec: ev.getHeader('variable_billsec'), 
            hangup_cause: ev.getHeader('variable_hangup_cause'),
            source_number: ev.getHeader('variable_number_alias') || ev.getHeader('variable_caller_id_number'),
            record_name: fileName,
            record_path: filePath,
            domain_uuid: ev.getHeader('variable_domain_uuid'),
            sip_call_id: ev.getHeader('variable_orig_sip_call_id'),
            hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
            sip_hangup_disposition: ev.getHeader('variable_sip_hangup_disposition'),
            cc_queue: ev.getHeader('variable_cc_queue'),
            cc_agent_uuid: ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('variable_cc_agent'),
            cc_agent: ev.getHeader('variable_cc_agent'),
            cc_cause: ev.getHeader('variable_cc_cause'),
            cc_queue_answered_epoch: ev.getHeader('variable_cc_queue_answered_epoch'),
            record_length: ev.getHeader('variable_record_seconds') || '0',
            type,
            originating_leg_uuid: uniqueId,
            leg: legTransfer,
            last_arg: status
    };

    if(callLogsPayload2.last_arg.includes("false")){
    // last_arg: 'false - Inbound Attended Answered'
         const setExtension = callLogsPayload2.last_arg.split('-');
         const newLast_arg = `${callLogsPayload2.caller_destination} - ${setExtension[1]}`;

         callLogsPayload2.last_arg = newLast_arg;
         console.log("this is now the newLastarg", newLast_arg, setExtension, callLogsPayload2.last_arg);
    }

    if(destination !== 'bridge_to_agent'){

          // if(callLogsPayload2.last_arg == "Attended Transfer"){
          //      callLogsPayload2.last_arg = `${callLogsPayload2.caller_id_number} - Attended Transfer`
          // }

//           51|server      |   variable_effective_caller_id_name: '"Boboy Outlook"',
// 51|server      |   variable_effective_caller_id_number: '"1146"',

          if(callLogsPayload2.caller_destination == "false"){
               console.log("this?")
              callLogsPayload2.caller_destination = ev.getHeader('Caller-Orig-Caller-ID-Name');
          }
          if(callLogsPayload2.destination_number == "false"){
                console.log("this?1")
               callLogsPayload2.caller_destination = ev.getHeader('Caller-Orig-Caller-ID-Name')
          }

          console.log(`🌳 Attended Transfer Report`, callLogsPayload2);
          redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));
                   console.log("this is where it save att transfer", `callLogs-${ev.getHeader('variable_domain_name')}`);
    }

}

async function callLogsTimeoutQueue(ev, cidName, cidNum, destination, type, status, extensionId, hangupCause, durationQueue, queueId) {
     

     const timeZoneDB = async (domainId) => {
          db2.query(
          `SELECT user_setting_value FROM v_user_settings WHERE domain_uuid = $1`,
          [domainId],
          async (err, result) => {
               if (err) {
                    console.error("Error executing agents query:", err.message);
                    return;
               }
               return result.rows.length > 0
               ? result.rows[0].user_setting_value
               : Intl.DateTimeFormat().resolvedOptions().timeZone;
          });
     }
     const timezone = await timeZoneDB(ev.getHeader('variable_domain_uuid'));

     const recordingPath = recordingFilePath(ev.getHeader('CC-Member-UUID'), ev.getHeader('variable_domain_name'), ev.getHeader('Event-Date-Timestamp'));
     const fileName = ev.getHeader('variable_cc_record_filename')?.split('/')?.pop() || ev.getHeader('CC-Member-UUID');
     const filePath = ev.getHeader('variable_cc_record_filename') ? ev.getHeader('variable_cc_record_filename').substring(0, ev.getHeader('variable_cc_record_filename').lastIndexOf('/')) : recordingPath;
     
     const legTransfer = await (async() => {
          if(
               ev.getHeader('variable_callFeatures') === 'Attended_Transfer' || 
               ev.getHeader('variable_endpoint_disposition') === 'BLIND_TRANSFER'
          ){
               return 'b'
          }
     })();

     const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'long'
     });

     const startTime = new Date(Number(ev.getHeader('CC-Member-Joined-Time')) * 1000);
     const answeredTime = new Date(Number(ev.getHeader('CC-Member-Leaving-Time')) * 1000);
     const endTime = new Date(Number(ev.getHeader('CC-Member-Leaving-Time')) * 1000);

     const newStartEpoch = Math.floor(startTime.getTime() / 1000);
     const newAnsweredEpoch = Math.floor(answeredTime.getTime() / 1000);
     const newEndEpoch = Math.floor(endTime.getTime() / 1000);

     const newStartTime = ev.getHeader('CC-Member-Joined-Time');
     const newAnsweredTime = ev.getHeader('CC-Member-Leaving-Time');
     const newEndTime = ev.getHeader('CC-Member-Leaving-Time');

     const uniqueCallId = ev.getHeader('CC-Member-UUID') || uuidv4();

     const callDirection = async () => {
          if(classifyNumber(Number(destination)) === 'ExternalNumber'){
               return 'outbound'
          }
          return 'inbound'
     }

     console.log({
        "Argentina Time Start": formatter.format(startTime),
        "Argentina Time Answered:": formatter.format(answeredTime),
        "Argentina Time: End": formatter.format(endTime),
        newStartEpoch,
        newAnsweredEpoch,
        newEndEpoch,
        "Start": utcTime(newStartTime),
        "Answered": utcTime(newAnsweredTime),
        "End": utcTime(newEndTime)
     });

     const newWaitSeconds = newStartTime === newAnsweredTime ? Number(newEndTime) - Number(newStartTime).toString() : (Number(newAnsweredTime) - Number(newStartTime)).toString();
     const voicemail_message = hangupCause === 'NO_ANSWER';

      const callLogsPayload2 = {
          call_id: uniqueCallId,
          direction: await callDirection(),
          extension_uuid: extensionId,
          call_center_queue_uuid: queueId,
          start_stamp: utcTime(newStartTime),
          answer_stamp: utcTime(newAnsweredTime),
          start_epoch: newStartEpoch,
          answer_epoch: newAnsweredEpoch,
          end_stamp: utcTime(newEndTime),
          end_epoch: newEndEpoch,
          caller_id_name: cidName,
          caller_id_number: cidNum,
          caller_destination: destination,
          destination_number: destination ,
          duration: durationQueue,
          voicemail_message,
          missed_call: voicemail_message,
          waitsec: ev.getHeader('variable_waitsec'),
          billsec: ev.getHeader('variable_billsec'), 
          hangup_cause: hangupCause,
          source_number: cidNum,
          record_name: fileName,
          record_path: filePath,
          domain_uuid: ev.getHeader('variable_domain_uuid'),
          sip_call_id: ev.getHeader('variable_orig_sip_call_id'),
          hangup_cause_q850: ev.getHeader('variable_hangup_cause_q850'),
          sip_hangup_disposition: ev.getHeader('variable_sip_hangup_disposition'),
          cc_queue: ev.getHeader('variable_cc_queue'),
          cc_agent_uuid: ev.getHeader('variable_cc_agent_uuid') || ev.getHeader('variable_cc_agent'),
          cc_agent: ev.getHeader('variable_cc_agent'),
          cc_cause: ev.getHeader('variable_cc_cause'),
          cc_queue_answered_epoch: '0',
          record_length: '0',
          type,
          originating_leg_uuid: ev.getHeader('CC-Member-Session-UUID'),
          leg: legTransfer,
          last_arg: status
     };
     
     if(destination == 'bridge_to_agent') return;

     console.log(`🌳 Queue TimeOut Report`, callLogsPayload2, hangupCause, {
          AID: ev.getHeader('Unique-ID'),
          BID: uniqueCallId
     });
     redisClient.rPush(`callLogs-${ev.getHeader('variable_domain_name')}`, JSON.stringify(callLogsPayload2));
         console.log("this is where it save timeout rep", `callLogs-${ev.getHeader('variable_domain_name')}`);
}


const utcTime = (epoch) => {
     const formatted = new Date(epoch * 1000)
     .toISOString()
     .replace("T", " ")
     .replace(".000Z", "+00:00");
     return formatted;     
}

const playbackStart = async (ev, bd, conn) => {
     console.log(`✅️ BACKGROUND_JOB`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );

   const jobCommandArg = ev.getHeader("Job-Command-Arg") || "";

     if (jobCommandArg.includes("eavesdrop")) {
     console.log("this is only for conference only");
          // const match = jobCommandArg.match(
          // /eavesdrop\(\s*([0-9a-f-]{36})\s*\)/i
          // );

          // THIS IS FOR GETTING THE 0999
          //This is for external
          const match = jobCommandArg.match(/sofia\/gateway\/[^/]+\/(\d+)/);
          const phoneNumber = match?.[1] || null;
          console.log(phoneNumber);
               // 09391809011
          const conferenceValue = await redisClient.get(`conferenceEavesdropValue@${phoneNumber}`) || null;
          if (conferenceValue){
               console.log("this is the conferenceValue", conferenceValue);
          }

          if(!phoneNumber){
               // This is for internal
               console.log("This is for internal")
               const param = jobCommandArg.match(/eavesdrop\(\s*([0-9a-f-]{36})\s*\)/i);
               if (param) {
               const uuid = param[1];

                conn.api(`uuid_exists ${uuid}`, (res) => {
                    if(res.body === 'true'){
                    conn.api(`uuid_break ${uuid}`);
                    
                    }
                    else{
                         console.log("not existing");
                         }
                    })
                 
               const presenceMatch = jobCommandArg.match(/presence_id=([^,}\s]+)/);
               const presenceId = presenceMatch?.[1] || null;
               console.log("Presence ID:", presenceId);

               await redisClient.set(`conferenceEavesdropValueInternalConverter@${presenceId}`, `${uuid}`);
               console.log("BACKGROUND_JOB saved to: ", `conferenceEavesdropValueInternalConverter@${presenceId} with value" ${uuid}`);
          } 
          }
          
        
          // const coreUUID = ev.getHeader("Core-UUID") || "";
          // redisClient.set(`conferenceEavesdropValue@${uuid}`, uuid, {EX: 3600});



          // const body = ev.getBody()?.trim() || "";

          // const match = body.match(
          // /^\+OK\s+([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i
          // );

          // if (match) {
          // const uuid = match[1];

          // console.log("UUID FOR redis:", uuid);
          // } else {
          // console.error("Invalid response:", body);
          // }

          // if (match) {
          // const uuid = match[1];

          // console.log("Eavesdrop target UUID:", uuid);

          // } else {
          // console.log("Unable to extract eavesdrop UUID:", jobCommandArg);
          // }
     }

     

}

const presenceId = async (presence) => {
  
  if(!presence) return
  const result = presence.match(/\/([^/@]+)@/)?.[1];
  const result2 = presence.match(/sofia\/\w+\/(\+?\d+)/)?.[1];
  const dest = result || result2;
  return dest;

};

const sipProfileURL = async (presence) => {

  if (!presence || typeof presence !== 'string') return null;
  const match = presence.match(/\/([^/]+)$/);
  return match ? match[1] : null;

};

const channelOutgoing = async (ev, bd, conn) => {
     console.log(`✅️ ${ev.getHeader('Event-Name')}`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );
}


const channelHangupComplete = async (ev, bd, conn) => {
     console.log(`✅️ ${ev.getHeader('Event-Name')}`, 
          util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
     );
}

