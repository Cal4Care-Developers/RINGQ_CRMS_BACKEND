const fs = require("fs");
const path = require("path");
const esl = require("modesl");
const redis = require("redis");
const { Pool } = require("pg");
const { Server } = require("socket.io");
const { io } = require("socket.io-client");
const util = require('util');
const WebSocket = require('ws');
require('dotenv').config({ path: '/etc/ringq/.env' });


const db = new Pool({
     user: process.env.PostgreSQL_DB_USER,
     host: process.env.PostgreSQL_DB_HOST,
     database: process.env.PostgreSQL_DB_NAME,
     password: process.env.PostgreSQL_DB_PASSWORD,
     port: Number(process.env.PostgreSQL_DB_PORT),
});
db.connect((err, client, release) => {
     if (err) {
          console.error('Error acquiring client', err.stack);  
          return;
     }else{
          console.log('Postgress Database connected successfully!');
     }
});

let redisClient
(async () => {
     redisClient = redis.createClient({
          socket: {
               path: '/var/run/redis/redis-server.sock'
          }
     });
     redisClient.on("error", (error) => console.error(`Error : ${error}`));
     await redisClient.connect();
})();

const socketClient = new io("ws://127.0.0.1:3000", {
     autoConnect: true,
     reconnectionDelayMax: 1000,
});
socketClient.connect();

const socketClientQueue = new io("ws://127.0.0.1:4072", {
     autoConnect: true,
     reconnectionDelayMax: 1000,
});
socketClientQueue.connect();


const conn = new esl.Connection("127.0.0.1", 8021, "ClueCon");
const connCmd = new esl.Connection("127.0.0.1", 8021, "ClueCon");


conn.on("esl::ready", function (arg) {
     conn.events("json", "all", function () {
          console.log("Subscribed to selected events", arguments);
     });
}).on("error", function () {
     console.log("**** CONNECTION ERROR, WILL RETRY **** ", arguments);
})
.on("esl::event::**", async function (ev, h, bd) {

    var event = ev.getHeader("Event-Name");
    if (!bd || !isValidJSON_FS(bd)) {
           console.log("FS not ready...");
    } else {
           if (event == "CUSTOM") {

               if(ev.getHeader("Event-Subclass") == "sofia::register"){

                    console.log(`✅️ REGISTER`, 
                         util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
                    );
                    // EMAN ADDED 10-07-25 START
                    // check if numeric username
                    if (!String(ev.getHeader('user-agent') || '').includes('RingQ')) {
                         console.log("<!-- THIS IS IP PHONE");
                         if (/^[0-9]+$/.test(String(ev.getHeader('username')))) {
                              console.log("<!-- THIS WILL TRIGGER UNREGISTERED");
                              // ev.getHeader('network-port');
                              let callID = ev.getHeader('call-id');
                              setTimeout(() => {
                                   connCmd.api('sofia profile internal flush_inbound_reg '+ ev.getHeader('call-id'));
                                   db.query(
                                   "DELETE FROM registrations WHERE token = $1",
                                   [callID],
                                   (err, result) => {
                                   if (err) {
                                        console.error("Database error:", err);
                                        return;
                                   }
                                   console.log(
                                        `Deleted registration for USER: ${ev.getHeader('username')}`,
                                        result
                                   );
                                   }
                                   );
                              }, 1500);
                              // BEARERCAPABILITY_NOTAUTH
                         }    
                    }
                    // EMAN ADDED 10-07-25 END

                    const extensionFunction = async () => {
                         const from = ev.getHeader('from-user');
                         const checkValid = isValidNumber(from)
                         if(!checkValid){

                              const { rows } = await db.query(
                                   `SELECT extension FROM v_extensions WHERE number_alias = $1`,
                                   [from]
                              );
                              const extensionOrig = rows[0].extension;
                              return extensionOrig
                         }
                         return from
                    }

                    const extensionOrig = await extensionFunction()

                    waitingCalls(
                         ev.getHeader("from-host"), 
                         extensionOrig, 
                         ev.getHeader('user-agent'), 
                         ev.getHeader('contact'),
                         ev.getHeader('domain_uuid'),
                         ev.getHeader('from-user')
                    );

                    conn.api('sofia status reg', (reg) => {

                         const lines = reg.body.split('\n');
                         const gatewayData = [];

                         for (const line of lines) {

                         if (line.includes('external::')) {

                              const match = line.match(/^external::([a-f0-9\-]+)\s+(.+?)\s+(NOREG|REGED|TRYING|FAIL_WAIT|REGISTER)\s+([\d.]+)\s+(\d+\/\d+)\s+(\d+\/\d+)/);

                              if (match) {

                                   const [_, uuid, uri, state, ping, ib, ob] = match;
                                   const [ibFail, ibTotal] = ib.split('/').map(Number);
                                   const [obFail, obTotal] = ob.split('/').map(Number);
                                   gatewayData.push({
                                        uuid,
                                        uri,
                                        state,
                                        ping_time: parseFloat(ping),
                                        ib_calls: { failed: ibFail, total: ibTotal },
                                        ob_calls: { failed: obFail, total: obTotal }
                                   });
                              }

                         }

                         }

                         const gateway = JSON.parse(JSON.stringify(gatewayData)); // No need for pretty-print here
                         gateway.forEach( async (element) => {
                              await redisClient.set(`gatewayStatus-${element.uuid}`, JSON.stringify(element))
                         });

                    });

                    if(ev.getHeader('user-agent') && ev.getHeader('user-agent').includes(' - ')){
                         const deviceId = ev.getHeader('user-agent').split(' - ')[1].trim();
                         await redisClient.set(`deviceUID-${deviceId}`, ev.getHeader('call-id'), { EX: 604800 }); // [perf] TTL
                    }

                    showcallsEventRefresh(ev.getHeader("from-host"));
                    if(ev.getHeader('sip_alias')){
                         redisClient.set(`sipAlias:${extensionOrig}@${ev.getHeader('from-host')}`, ev.getHeader('sip_alias'));
                    }else{
                         const sipAliasExists = await redisClient.exists(`sipAlias:${extensionOrig}@${ev.getHeader('from-host')}`);
                         if(sipAliasExists){
                              redisClient.del(`sipAlias:${extensionOrig}@${ev.getHeader('from-host')}`);
                         }
                    }

                    socketClientQueue.emit(`registeredExt`, `${extensionOrig}@${ev.getHeader('from-host')}`)

                    if(ev.getHeader('presence_id')){

                         const extensionNum = async (alias) => {
                              const { rows } = await db.query(
                                   `SELECT extension FROM v_extensions WHERE domain_uuid = $1 AND number_alias = $2`,
                                   [ev.getHeader('variable_domain_uuid'), alias]
                              );
                              const extensionOrigName = rows[0]?.extension;
                              return extensionOrigName
                         }
                         const [encryptUsername,  subDomain] = ev.getHeader('presence_id').split('@');

                         const extensionPresence = await extensionNum(encryptUsername)
                         const valid = isValidNumber(extensionPresence)
                         if(extensionPresence && !valid){
                              const extensionName  = ev.getHeader('effective_caller_id_name');
                              const extensionNumber = ev.getHeader('effective_caller_id_number')
                              if(extensionName  && extensionNumber){
                                   /** Username */
                                   redisClient.set(`extension-${extensionPresence}@${subDomain}`, JSON.stringify({'extension': extensionNumber, 'effectiveName': extensionName}));
                                   /** Extension properties */
                                   redisClient.set(`effectiveName-${extensionNumber}@${subDomain}`, extensionName);
                                   redisClient.set(`username-${extensionNumber}@${subDomain}`, extensionPresence);
                              }
                         }
                    }

                    if( 
                         ev.getHeader('user-agent').includes(' - ') && 
                         ev.getHeader('user-agent').includes('Webapp')
                    ){

                         const sipContact = ev.getHeader('contact').split('sip:')[1].replace('>', '');
                         const sipContactId = sipContact.split(';')[0];
                         redisClient.set(`contactSIP-${sipContactId}`, `${extensionOrig}@${ev.getHeader("from-host")}`, { EX: 604800 }); // [perf] TTL
                         redisClient.set(`SIPWEB-${extensionOrig}@${ev.getHeader("from-host")}`, `${sipContactId}`); 

                    }

                    if(
                         ev.getHeader('user-agent').includes(' - ') && 
                         ev.getHeader('user-agent').includes('android')
                    ){

                         const sipContact = ev.getHeader('contact').split('sip:')[1].replace('>', '');
                         const sipContactId = sipContact.split(';')[0];
                         redisClient.set(`contactSIP-${sipContactId}`, `${extensionOrig}@${ev.getHeader("from-host")}`, { EX: 604800 }); // [perf] TTL
                         redisClient.set(`SIP-${extensionOrig}@${ev.getHeader("from-host")}`, `${sipContactId}`);
                         
                         const device_uuid = ev.getHeader('user-agent').split(' - ')[1].trim();
                         console.log(`deviceId`, device_uuid);

                         await redisClient.sAdd(`deviceOn-${extensionOrig}@${ev.getHeader('from-host')}`, device_uuid)
                         await redisClient.sRem(`deviceOff-${extensionOrig}@${ev.getHeader('from-host')}`, device_uuid)

                         const key = `sofia-${ev.getHeader('call-id')}-${extensionOrig}@${ev.getHeader('from-host')}`;
                         const devicePresence = `androidPresence-${device_uuid}-${extensionOrig}@${ev.getHeader('from-host')}`;
                         const deviceId = `deviceId-${extensionOrig}@${ev.getHeader('from-host')}`;

                         redisClient.set(key, ev.getHeader('user-agent'));
                         redisClient.set(deviceId, device_uuid);
                         redisClient.set(`device-${deviceId}`, ev.getHeader('user-agent'));
                         redisClient.del(devicePresence);
                         
                    }

                    if(
                         ev.getHeader('user-agent').includes(' - ') && 
                         ev.getHeader('user-agent').includes('iOS')
                    ){

                         const sipContact = ev.getHeader('contact').split('sip:')[1].replace('>', '');
                         const sipContactId = sipContact.split(';')[0];
                         redisClient.set(`contactSIP-${sipContactId}`, `${extensionOrig}@${ev.getHeader("from-host")}`, { EX: 604800 }); // [perf] TTL
                         redisClient.set(`SIP-${extensionOrig}@${ev.getHeader("from-host")}`, `${sipContactId}`);
                         
                         const device_uuid = ev.getHeader('user-agent').split(' - ')[1].trim();
                         const extension = extensionOrig;
                         const channel = ev.getHeader('from-host');

                         redisClient.set(`deviceSIP-${device_uuid}`, sipContactId)
                         const key = `sofia-${ev.getHeader('call-id')}-${extensionOrig}@${ev.getHeader('from-host')}`;
                         redisClient.set(key, ev.getHeader('user-agent'));
                         redisClient.set(`deviceIdInfo-${device_uuid}`, ev.getHeader('user-agent'));

                         const containerExt = await redisClient.sMembers(`${extension}@${channel}`);
                         if(containerExt){
                              const deviceToken = containerExt.find((token) => token.includes(device_uuid));
                              if(deviceToken){
                                   await redisClient.sAdd(`iosDeviceOn-${extension}@${channel}`, device_uuid);
                                   await redisClient.sRem(`iosDeviceOff-${extension}@${channel}`, device_uuid);
                              }
                         }

                         if(device_uuid){
                              
                              const containerExt = await redisClient.sMembers(`${extension}@${channel}`);
                              if(containerExt){
                                   const deviceToken = containerExt.find((token) => token.includes(device_uuid));
                                   if(deviceToken){
                                        const token = deviceToken.split(':')[0]
                                        if(token){
                                             const deviceTokenFilter = containerExt.filter((token) => !token.includes(device_uuid) && token.includes(token));
                                             redisClient.sRem(`${extension}@${channel}`, token);
                                             deviceTokenFilter.forEach(x => {
                                                  redisClient.sRem(`${extension}@${channel}`, x);
                                             })
                                        }
                                   }
                              }                      

                         }
                         
                    }
                    // socketClient.emit("queueList", {ext: '000', channel: ev.getHeader('from-host')});

               }

               if(ev.getHeader("Event-Subclass") == "sofia::unregister"){

                    console.log(`✅️ UNREG`, 
                         util.inspect(JSON.parse(bd),{ showHidden: true, depth: null, colors: true })
                    );

                    const extensionFunction = async () => {
                         const from = ev.getHeader('from-user');
                         const checkValid = isValidNumber(from)
                         if(!checkValid){

                              const { rows } = await db.query(
                                   `SELECT extension FROM v_extensions WHERE number_alias = $1`,
                                   [from]
                              );
                              const extensionOrig = rows[0].extension;
                              return extensionOrig
                         }
                         return from
                    }

                    const extensionOrig = await extensionFunction()
                    if(ev.getHeader('reason') === 'socket-disconnection' || ev.getHeader('contact').includes('null')){
                         
                         /** Key */
                         const key = `sofia-${ev.getHeader('call-id')}-${extensionOrig}@${ev.getHeader('from-host')}`;
                         const presence = await redisClient.get(key);
                         /** ---- */
                         if(presence && presence.includes('android')){
                              /** Device */
                              console.log('This is called! ✅️ UNREG ANDROID');
                              const device_uuid = presence.split(' - ')[1].trim();
                              await redisClient.sAdd(`deviceOff-${extensionOrig}@${ev.getHeader('from-host')}`, device_uuid);
                              /** Expire Device Presence*/
                              await redisClient.set(`deviceActive-${device_uuid}`, 'active', {EX: 3600});
                              const deviceId = await redisClient.sMembers(`deviceOff-${extensionOrig}@${ev.getHeader('from-host')}`)
                              if(deviceId){
                                   deviceId.forEach( async (Id) => {
                                        const active = await redisClient.get(`deviceActive-${Id}`);
                                        if(active){
                                             console.log(`This device Id : ${Id} is active`)
                                        }else{
                                             console.log(`This device Id : ${Id} is expired`);
                                             await redisClient.sRem(`deviceOff-${extensionOrig}@${ev.getHeader('from-host')}`, Id)
                                        }
                                   });
                              }
                              /**  End */
                              await redisClient.sRem(`deviceOn-${extensionOrig}@${ev.getHeader('from-host')}`, device_uuid)
                              const devicePresence = `androidPresence-${device_uuid}-${extensionOrig}@${ev.getHeader('from-host')}`;
                              /** ---- */
                              redisClient.del(key);
                              redisClient.set(devicePresence, 'push');
                         }

                    }

                    if(ev.getHeader('reason') === 'socket-disconnection'){
                         const key = `sofia-${ev.getHeader('call-id')}-${extensionOrig}@${ev.getHeader('from-host')}`;
                         const presence = await redisClient.get(key);
                         if(presence && presence.includes('iOS')){
                              const device_uuid = presence.split(' - ')[1].trim();
                              const presenceExt = `${extensionOrig}@${ev.getHeader('from-host')}` 
                              redisClient.sRem(`iosDeviceOn-${presenceExt}`, device_uuid);
                              redisClient.sAdd(`iosDeviceOff-${presenceExt}`, device_uuid); 
                         }
                         return
                    }
               }      
               
           }
    }

})

function isValidJSON_FS(str) {
    try { JSON.parse(str); return true; }
    catch { return false; }
}

const isValidNumber = (input) =>  {
     const regex = /^\+?\d+$/;
     return regex.test(input);
}

async function showcallsEventRefresh(subdomain){
     
    //  setTimeout( async () => {
    //       connCmd.api(`show calls as json`, res => {
    //            console.log('RES***', res);
    //            socketClient.emit(`progressRinging`, {data:res.body, subdomain: subdomain});                         
    //       })
    //  }, 4000)

}

async function waitingCalls(host, extension, agent, contact, domainId, aliasName) {

     try {
          
          const uniqueId2 = await redisClient.get(`${host}${extension}-RingQ`);
          console.log('PUSH CALL UNIQUE ID2', uniqueId2)
          
          if(uniqueId2){

               try {
                    const result = await connCmdAwait(`uuid_dump ${uniqueId2} JSON`);
                    console.log(result);
                    if(!result.includes('-ERR No such channel!')){

                         const data = JSON.parse(result);
                         console.log(`DUMP CALL`, data);
                         if(data.variable_conference_key){
                            //   conferencePushAnswer(data['Caller-Caller-ID-Number'],extension, host, uniqueId,uniqueId2,  data.variable_sip_to_params);
                         }else{

                              const aliasNamePresence = `${aliasName}@${host}`
                              console.log('This is called bridge call >>> ', agent);
                              const exists2 = await connCmdAwait(`uuid_exists ${uniqueId2}`);
                              console.log('This is called here', exists2, agent);
                              if (exists2 == 'true') {

                                const extContact = contact.split(';')[0].split('sip:')[1];
                                await redisClient.set(`devicePresence-${extension}@${host}`, extContact);
                                await redisClient.set(`mobileSIP-${extContact}@${host}`, extContact);
                                if(agent.includes('RingQ android')){
                                    const mobileSIP = await redisClient.set(`mobileSIP-${extContact}@${host}`, extContact);
                                    console.log('This is called bridge call2  >>> ', mobileSIP)
                                    if(mobileSIP){

                                            const sipContact = contact.split('sip:')[1].replace('>', '');
                                            const sipContactId = sipContact.split(';')[0];
                                            const extCallerName = data.variable_effective_caller_id_name ? data.variable_effective_caller_id_name : data['Caller-Caller-ID-Name'];
                                            async function getExtCallerExtension(data) {
                                                if (data.variable_sip_alias_value) {
                                                return data.variable_sip_alias_value;
                                                } else if (data.variable_effective_caller_id_number) {
                                                return data.variable_effective_caller_id_number;
                                                } else {
                                                return data['Caller-Caller-ID-Number'];
                                                }
                                            }
                                            const extCallerExtension = await getExtCallerExtension(data);
                                            console.log(`ORIGINATION CODE ANROID 2 uuid_transfer ${uniqueId2} ${extension} XML ${host}`)
                                            connCmd.api(`originate {domain_uuid=${domainId},presence_id=${aliasNamePresence},accountcode=${host},domain_name=${host},nolocal:absolute_codec_string=PCMA,PCMU,G729,inherit_codec=true,hangup_after_bridge=false,origination_number=${extension},origination_caller_id_name='${extCallerName}',origination_caller_id_number=${extCallerExtension}}sofia/internal/sip:${sipContact} &park()`, async (result) => {                         
                                                console.log('`ORIGINATION CODE ANROID 3', result);
                                                const match = result.body.match(/[0-9a-fA-F\-]{36}\\?/);
                                                if(match){
                                                    connCmd.api(`show calls`, (result2) => {
                                                        console.log(`This is called bridge call3 >>>`, result2)
                                                    });
                                                    console.log('NORMAL2-', match[0], uniqueId2);
                                                    connCmd.api(`uuid_bridge ${match[0]} ${uniqueId2}`);
                                                } 
                                            });
                                    }
                                }
                                   
                                if(agent.includes('iOS')){

                                        console.log('IOS in here...');
                                        const sipContact = contact.split('sip:')[1].replace('>', '');
                                        const sipContactId = sipContact.split(';')[0];
                                        const extCallerName = data.variable_effective_caller_id_name ? data.variable_effective_caller_id_name : data['Caller-Caller-ID-Name'];
                                        async function getExtCallerExtension(data) {
                                             if (data.variable_sip_alias_value) {
                                             return data.variable_sip_alias_value;
                                             } else if (data.variable_effective_caller_id_number) {
                                             return data.variable_effective_caller_id_number;
                                             } else {
                                             return data['Caller-Caller-ID-Number'];
                                             }
                                        }
                                        const extCallerExtension = await getExtCallerExtension(data);
                                        // const extContact = contact.split(';')[0].split('sip:')[1];
                                        const deviceId = agent.split(' - ')[1]
                                        const pushTokenPrensence = await redisClient.get(`deviceIdIOS-${deviceId}`);

                                        try {
                                             console.log('IOS in here2...', `originate {domain_uuid=${domainId},presence_id=${aliasNamePresence},accountcode=${host},domain_name=${host},nolocal:absolute_codec_string=PCMA,PCMU,G729,inherit_codec=true,hangup_after_bridge=false,origination_number=${extension},origination_caller_id_name='${extCallerName}',origination_caller_id_number=${extCallerExtension}}sofia/internal/sip:${sipContact} &park()`);
                                             const originateRes = await connCmdAwait(`originate {domain_uuid=${domainId},presence_id=${aliasNamePresence},accountcode=${host},domain_name=${host},nolocal:absolute_codec_string=PCMA,PCMU,G729,inherit_codec=true,hangup_after_bridge=false,origination_number=${extension},origination_caller_id_name='${extCallerName}',origination_caller_id_number=${extCallerExtension}}sofia/internal/sip:${sipContact} &park()`);
                                             console.log('ORIGINATE RESULT', originateRes);
                                             await new Promise(r => setTimeout(r, 500));
                                             const showRes = await connCmdAwait(`show calls as json`);
                                             const callData = JSON.parse(showRes);
                                             console.log('This is called ORIG', callData, sipContactId);
                                             const call = callData.rows?.find(r => r.name === `sofia/internal/${sipContactId}` && r.callstate === 'ACTIVE');
                                             if(call) return
                                
                                             const call2 = callData.rows?.find(r => r.name === `sofia/internal/${sipContactId}`);
                                             if(call2){
                                                  await redisClient.sAdd(`iosActivePushToken-${extension}@${host}`, pushTokenPrensence);
                                                  const showRes = await connCmdAwait(`uuid_bridge ${uniqueId2} ${call2.uuid}`);
                                                  console.log(`BRIDGE CALL`, showRes);
                                             }
                                        } catch (e) {
                                             console.error('ESL ERROR', e);
                                        }
                                        console.log(`ORIGINATION CODE IOS 23 uuid_transfer ${uniqueId2} ${extension} XML ${host}`)
                                       
                                }
                                        
                              }else{
                                console.log("Error to transfer");
                              }

                         }
                    }
               } catch (err) {
                    console.error('Error checking UUID:', err);
               }

          }

     } catch (error) {
          console.error(error.message);
     }

}

const connCmdAwait = async (cmd) => {
  return new Promise((resolve, reject) => {
      conn.api(cmd, (res) => {
      console.log('RESULTS :::>', res)
      resolve(res.getBody());
    });
  });
};
