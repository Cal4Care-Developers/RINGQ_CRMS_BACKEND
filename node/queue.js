require('./quietlog'); // [perf] summarise big objects in console.log
require('./servermode')('queue'); // [server-mode] production/debug logging, see servermode.js
const esl = require("modesl");
const redis = require("redis");
const { Server } = require("socket.io");
const { io } = require("socket.io-client");
require('dotenv').config({ path:'/etc/ringq/.env' });

const pg = require("pg");
const { Pool, Client } = pg;

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

const conn = new esl.Connection("127.0.0.1", 8021, "ClueCon");

const socketServerQueue = new Server({
  maxHttpBufferSize: 6e6
});

const socketServerSignal = new io("ws://127.0.0.1:3000", {
  autoConnect: true,
  reconnectionDelayMax: 1000,
});

let redisClient

(async () => {

  try {
    redisClient = redis.createClient({
      socket: {
        path: '/var/run/redis/redis-server.sock'
      }
    });

    redisClient.on('error', (error) => console.error(`Redis Error: ${error}`));

    redisClient.on('connect', () => {
      console.log('Redis socket connected.');
    });

    redisClient.on('ready', () => {
      console.log('Redis client ready to use.');
    });

    await redisClient.connect();
    
  } catch (err) {
    console.error('Failed to connect to Redis:', err);
  }
  
})();

socketServerQueue.listen(4072);

socketServerQueue.use(async (socket, next) => {
  if (socket.handshake.headers["user-agent"] == "node-XMLHttpRequest") {
    next();
  } else {
    next();
  }
}).on("connection", (socket) => {
    
    socket.on("wallBoardButton", async (subdomain) => {
      console.log(`wallBoardButton`);
      await wallBoardDisplay(subdomain);
    });

    socket.on("broadcast", async (arg, callback) => {

        console.log(`BROADCAST TRIGGER`, arg);
        const {channel , extension} = arg
        const channelPresence = `${extension}@${channel}`
        const queueWallboardExist = await redisClient.exists(`walldata:${channel}`);


      if (arg.command === "answered") {
        const channelQueue = `${arg.extension}@${arg.channel}`;
        // console.log
        const dataAnswered = {

          //means push only
          session_uuid: arg.call_center_queue_uuid,
          serving_agent: arg.cc_agent,
          // queue: channelQueue,
          // answered_at: Date.now()
        };
        console.log("set this", dataAnswered, "to: ", `broadcastAnsweredQueue:${channelQueue}`)
        // redisClient.set(
        //   `broadcastAnsweredQueue:${channelQueue}`,
        //   JSON.stringify(dataAnswered),
        // );



        if(arg.callerName.includes(arg.extension)){
          // console.log("this is only for open");
          // redisClient.set(`broadcastAnsweredQueue:${channelQueue}:type`, "open")
        }
        else{
          console.log("this is only for push");
          redisClient.set(`broadcastAnsweredQueue:${channelQueue}:type`, "push");
          redisClient.set(`broadcastAnsweredQueue:${channelQueue}`, JSON.stringify(dataAnswered),
        );
       let getAnswered = await redisClient.get(`answeredActiveCount:${channelQueue}`)

        const newActive = Number(getAnswered) + 1;
        console.log(`answeredActiveCount:${channelQueue}`, newActive);

        redisClient.set(`answeredActiveCount:${channelQueue}`, newActive);
        redisClient.set(`getActiveCallPush`, 1);
        }
      }

        if(!queueWallboardExist){
          await redisClient.hSet(`walldata:${channel}`, 
            'total_wallboard', 0, 
            'answered_wallboard', 0, 
            'waiting_wallboard', 0, 
            'abandoned_wallboard', 0, 
            'missed_calls', 0, 
            'answered_calls', 0,
            'total', 0, 
            'active', 0, 
            'waiting', 0, 
            'abandoned', 0, 
          );
        }else{

          // ─── Helpers (defined once, outside the switch) ──────────────────────────────

          const connApi = (cmd) =>
            new Promise((resolve) => conn.api(cmd, resolve));

          const getMemberList = () =>
            connApi(`json {"command": "callcenter_config","data": {"arguments":"member list"}}`);

          const getQueueTiers = (queueName) =>
            connApi(`json {"command": "callcenter_config", "data": {"arguments":"queue list tiers", "queue_name":"${queueName}"}}`);

          /** For All Members */
          const getMemberCounts = async () => {
            const { body } = await getMemberList();
            const { status, response } = JSON.parse(body);
            if (status !== 'success') return null;
            const tryingCount  = response.filter(i => i.queue.includes(channel) && i.state === 'Trying').length;
            const answeredCount = response.filter(i => i.queue.includes(channel) && i.state === 'Answered').length;
            return { tryingCount, answeredCount };
          };

          /** For Specific Queue */
          const getMemberCounts2 = async () => {
            const { body } = await getMemberList();
            const { status, response } = JSON.parse(body);

            console.log("getMember", response)
            if (status !== 'success') return null;

            const { tryingCount, answeredCount } = response.reduce((acc, i) => {
              if (i.queue === channelPresence) {
                if (i.state === 'Trying') acc.tryingCount++;
                if (i.state === 'Answered') acc.answeredCount++;
              }
              return acc;
            }, { tryingCount: 0, answeredCount: 0 });
            console.log("this is tryingCount", tryingCount, "answeredCount", answeredCount);
            console.log("this is channelPresence", channelPresence);
            return { tryingCount, answeredCount };
          };

          /** For Specific Member */
          // [wb-talk-epoch] a call duration in seconds, or 0 when it is not one: an epoch that was 0
          // ("end - 0" = a whole unix timestamp), negative, or longer than a day
          const wbSecs = (n) => {
            const v = Number(n);
            return Number.isFinite(v) && v > 0 && v <= 86400 ? v : 0;
          };
           const getMemberWaiting = async (state = 0) => {
            const { body } = await getMemberList();
            const { status, response } = JSON.parse(body);
            if (status !== 'success') return null;

            const subsEpoch = response.reduce((acc, e) => {
              if(e.uuid !== arg.cc_member_uuid) return acc
              console.log("payloadd", e);
              let stateEpoch = state ? Number(e.abandoned_epoch) : Number(e.bridge_epoch);
              // Added 08-17-26
              console.log("this is stateEpoch", stateEpoch, Number(e.joined_epoch), "diff is", stateEpoch - Number(e.joined_epoch))
              if(stateEpoch == 0){
                stateEpoch = Number(e.abandoned_epoch);
                console.log("this is now stateepoch", stateEpoch)
              }
     
              console.log("this is now stateepoch1", stateEpoch)
                        //// Added 08-17-26
              if (!(stateEpoch > 0) || !(Number(e.joined_epoch) > 0)) return acc; // [wb-talk-epoch]
              return wbSecs(stateEpoch - Number(e.joined_epoch))
            } , 0)
            console.log("this is subsEpoch", subsEpoch)
            return subsEpoch;
          };
          

          const syncWallboardCounts = async (tryingCount, answeredCount) => {
            const activeQueue = await redisClient.sMembers(`activeQueue-${channel}`);
            const newAnsweredCount = (activeQueue.length || 0) + answeredCount;
            await Promise.all([
              redisClient.hSet(`walldata:${channel}`, 'waiting_wallboard', tryingCount),
              redisClient.hSet(`walldata:${channel}`, 'waiting', tryingCount),
              redisClient.hSet(`walldata:${channel}`, 'answered_wallboard', newAnsweredCount),
              redisClient.hSet(`walldata:${channel}`, 'active', newAnsweredCount),
            ]);
            console.log("newAnssyncWallboardCounts weredCount", newAnsweredCount)
            return newAnsweredCount;
          };

          const syncTotalCalls = async () => {
            const { rows } = await db2.query(
              `SELECT COALESCE(SUM(answered_calls), 0) +
                      COALESCE(SUM(missed_calls), 0) +
                      COALESCE(SUM(abandoned_wallboard), 0) AS total_calls
              FROM member_board WHERE queue LIKE $1`,
              [`%${channel}%`]
            );
            const totalCalls = Number(rows[0].total_calls) || 0;
            await Promise.all([
              redisClient.hSet(`walldata:${channel}`, 'total_wallboard', totalCalls),
              redisClient.hSet(`walldata:${channel}`, 'total',           totalCalls),
            ]);
            return totalCalls;
          };

          // Atomic dedup: returns true if this is the first time we've seen this uuid event
          const claimUuidEvent = async (label) => {
            const key = `${arg.channel}-${label}-uuid-${arg.uuid}`;
            const set = await redisClient.set(key, 1, { NX: true, EX: 1 });
            return set !== null;
          };
          // [wallboard-dedupe] One queue call = one count. The same call reaches here several times
          // (callcenter bridge-agent-start, the push/re-originate legs used by attended transfer,
          // reconnects), with different channel uuids, seconds apart -- the 1 s uuid lock above let
          // every one of them through, inflating answered calls, agent counts and talk time.
          // cc_member_uuid is stable for the whole queue call, so lock on it until the daily reset.
          const callKey = arg.cc_member_uuid || arg.member_uuid || arg.uuid || '';
          const claimCall = async (label, extra = '') => {
            if (!callKey) return true;
            const set = await redisClient.set(`${arg.channel}-wb-${label}-${callKey}${extra ? `-${extra}` : ''}`, 1, { NX: true, EX: 86400 });
            return set !== null;
          };
          // talk time: count the longest leg of a call once (legs of one call overlap)
          const talkDelta = async (agentPart, seconds) => {
            let secs = wbSecs(seconds);
            const bridgeStart = Number(arg.lastBridgeStart);
            const bridgeEnd = Number(arg.lastBridgeEnd);
            if (arg.lastBridgeStart !== undefined && !(bridgeStart > 0)) secs = 0;
            else if (bridgeStart > 0 && bridgeEnd >= bridgeStart) secs = Math.min(secs, bridgeEnd - bridgeStart);
            if (!callKey) return secs;
            const k = `${arg.channel}-wb-talk-${callKey}-${agentPart || 'queue'}`;
            const prev = Number(await redisClient.get(k)) || 0;
            if (secs <= prev) return 0;
            await redisClient.set(k, secs, { EX: 86400 });
            return secs - prev;
          };

        // ─── Switch ───────────────────────────────────────────────────────────────────

        switch (arg.command) {

          case 'answered': {
            try {
              // 1. Update agent state in DB
              const tiers = JSON.parse((await getQueueTiers(channelPresence)).body).response;
              const match  = tiers.find(x => x.agent === arg.cc_agent);
              const firstForAgent = await claimCall('answered-agent', arg.cc_agent || '');
              if (match && firstForAgent) {
                const { rows } = await db2.query(
                  'SELECT calls_answered FROM agent_details WHERE name = $1',
                  [arg.cc_agent]
                );
                const total = Number(rows[0]?.calls_answered ?? 0) + 1;
                await db2.query(
                  'UPDATE agent_details SET state = $1, calls_answered = $2 WHERE name = $3',
                  ['In a queue call', total, arg.cc_agent]
                );
              }

              // 2. Sync wallboard counts
              const { tryingCount, answeredCount } = await getMemberCounts2();
              const counts = await getMemberCounts();
              if (!counts) break;
              await syncWallboardCounts(counts.tryingCount, counts.answeredCount);

              // 3. Update agent redis answered count

              const agentRaw = await redisClient.get(`agent-${arg.cc_agent}`);
              if (agentRaw && firstForAgent) {
                const agent = JSON.parse(agentRaw);
                await redisClient.set(
                  `agent-${arg.cc_agent}`,
                  JSON.stringify({ ...agent, answered: Number(agent.answered) + 1 })
                );
              }

              // 4. Dedup guard
              if (!(await claimUuidEvent('answered'))) break;
              if (!(await claimCall('answered-queue'))) break; // [wallboard-dedupe] queue totals once per call

              // 6. Sync no_answer_count
              const noAnswerRaw = await redisClient.get(`queueAgent-${channel}-${arg.cc_agent}`);
              const noAnswerCount = parseInt(noAnswerRaw);
              if (!isNaN(noAnswerCount)) {
                await Promise.all([
                  db2.query('UPDATE q_call_queues SET answered_call = $1 WHERE queue_uuid = $2', [noAnswerCount, arg.cc_agent]),
                  db2.query('UPDATE agents SET no_answer_count = $1 WHERE name = $2',           [noAnswerCount, arg.cc_agent]),
                ]);
              }

              // 7. Update member_board and trigger queueAction
              const { rows: boardRows } = await db2.query(
                'SELECT waiting_seconds, waiting_wallboard, answered_wallboard FROM member_board WHERE queue = $1',
                [channelPresence]
              );
              const board = boardRows[0];
              if (board?.waiting_seconds == null) break;

              // cc_member_uuid
              const WaitingSeconds = await getMemberWaiting();

              // Added 08-17-26
              const totalWaitingSec  = WaitingSeconds + Number(board.waiting_seconds);
              const getTotalAnswered = await redisClient.get(`totalAnsweredQueue@${channelPresence}`);

              redisClient.set(`totalAnsweredQueue@${channelPresence}`, Number(getTotalAnswered) + 1);
              console.log("total answered", Number(getTotalAnswered) + 1);
              console.log("ans")
              //// Added 08-17-26
              console.log(`Waiting Seconds before answered`, WaitingSeconds, totalWaitingSec);

              let trueAnsweredCount = Number(answeredCount);

                if (Number(answeredCount) === 0) {
                const answeredCountPush = await redisClient.get(
                    `answeredActiveCount:${channelPresence}`
                );

                const parsedAnsweredCount = parseInt(answeredCountPush, 10);

                trueAnsweredCount = Number.isNaN(parsedAnsweredCount)
                    ? 0
                    : parsedAnsweredCount;

                console.log("this is real count", trueAnsweredCount);
            }

            await db2.query(
                `UPDATE member_board
                SET answered_calls = GREATEST(COALESCE(answered_calls, 0) + 1, 0),
                    waiting_wallboard = $1,
                    answered_wallboard = $2,
                    waiting_seconds = $3
                WHERE queue = $4`,
                [
                    Number(tryingCount),
                    trueAnsweredCount,
                    totalWaitingSec,
                    channelPresence
                ]
            );

              
               console.log("Queue Update 2", Number(tryingCount), Number(trueAnsweredCount), totalWaitingSec, channelPresence)

              queueAction(
                arg.channel, arg.command, arg.callerNumber, arg.cc_agent,
                channelPresence, Number(board.waiting_seconds), arg.member_uuid, arg.callerName, arg.uuid
              );

            } catch (err) {
              console.error('Error handling answered:', err);
            }
            break;
          }

          case 'ended': {
            try {
              // 1. Update agent talk time
              const { rows } = await db2.query(
                'SELECT talk_time FROM agent_details WHERE name = $1',
                [arg.cc_agent]
              );
              const talkTime = rows[0]?.talk_time;
              const agentTalkAdd = await talkDelta(arg.cc_agent, arg.answeredSeconds); // [wallboard-dedupe]
              if (talkTime != null) {
                await db2.query(
                  `UPDATE agent_details
                  SET state = $1, talk_time = $2, last_bridge_start = COALESCE($3, last_bridge_start),
                      last_bridge_end = $4, last_offered_call = $5
                  WHERE name = $6`,
                  ['Waiting', agentTalkAdd + Number(talkTime),
                  Number(arg.lastBridgeStart) > 0 ? arg.lastBridgeStart : null, arg.lastBridgeEnd, arg.lastOfferedCall, arg.cc_agent]
                );
              }

              // 2. Sync wallboard counts
              const counts = await getMemberCounts();
              if (!counts) break;
              await syncWallboardCounts(counts.tryingCount, counts.answeredCount);

              // 3. Dedup guard
              if (!(await claimUuidEvent('ended'))) break;
              const queueTalkAdd = await talkDelta('queue', arg.answeredSeconds); // [wallboard-dedupe]
              const firstEnd = await claimCall('ended-queue');

              // 4. Update member_board
              const { rows: boardRows } = await db2.query(
                'SELECT answered_wallboard, total_wallboard, waiting_seconds, talk_time FROM member_board WHERE queue = $1',
                [channelPresence]
              );
              const board = boardRows[0];
              if (board?.waiting_seconds == null || board?.talk_time == null) break;

              const totalTalk     = queueTalkAdd + Number(board.talk_time);
              const totalWall     = Number(board.total_wallboard) + (firstEnd ? 1 : 0);
              let answeredWall  = Number(board.answered_wallboard) - 1;

              const existPush = redisClient.exists(`getActiveCallPush`);

              if(existPush){

               const getCountAnswered = redisClient.get(`answeredActiveCount:${channelPresence}`);
                console.log("means push only");

                if(getCountAnswered){
                  // answeredWall = answeredWall 
                  redisClient.set(`answeredActiveCount:${channelPresence}`, Number(getCountAnswered) - 1)
                }
                redisClient.del(`getActiveCallPush`)
              }


              if(answeredWall < 0){

              //  const getAnsweredCount = redisClient.get(`answeredActiveCount:${channelPresence}`);

              //  if(getAnsweredCount){

              //  }
                answeredWall = 0
                console.log("transform to 0");
              }

              

            const queries = [
              db2.query(
                `UPDATE member_board
                SET answered_wallboard = $1, total_wallboard = $2 WHERE queue = $3`,
                [answeredWall, totalWall, channelPresence]
              )
            ];

            console.log("Queue Update 1", answeredWall, totalWall, channelPresence)

            if (arg.cc_agent) {
              queries.push(
                db2.query(
                  `UPDATE member_board
                  SET talk_time = $1
                  WHERE queue = $2`,
                  [totalTalk, channelPresence]
                )
              );
              
            }

            await Promise.all(queries);
              

              // 5. Sync total calls and trigger queueAction
              await syncTotalCalls();
              queueAction(arg.channel, arg.command, arg.callerNumber, arg.cc_agent, channelPresence);

            } catch (err) {
              console.error('Error handling ended:', err);
            }
            break;
          }

          case 'waiting': {
            try {
              // 1. Set Receiving state on Offering agents

              const { tryingCount, answeredCount } = await getMemberCounts2();

              console.log('👇WAITING QUEUE', tryingCount, answeredCount)

              const tiers = JSON.parse((await getQueueTiers(channelPresence)).body).response;
              const offeringAgents = tiers.filter(x => x.state === 'Offering');
              await Promise.all(
                offeringAgents.map(x =>
                  db2.query('UPDATE agent_details SET state = $1 WHERE name = $2', ['Receiving', x.agent])
                )
              );

              // 2. Sync waiting count
              const counts = await getMemberCounts();
              if (!counts) break;
              await Promise.all([
                redisClient.hSet(`walldata:${channel}`, 'waiting_wallboard', counts.tryingCount),
                redisClient.hSet(`walldata:${channel}`, 'waiting', counts.tryingCount),
              ]);

              // 3. Dedup guard
              if (!(await claimUuidEvent('waiting'))) break;

              // 4. Update member_board and trigger queueAction
              const { rows } = await db2.query(
                'SELECT waiting_seconds, waiting_wallboard FROM member_board WHERE queue = $1',
                [channelPresence]
              );
              const board = rows[0];
              if (!board?.waiting_seconds) break;

              await db2.query(
                'UPDATE member_board SET waiting_wallboard = $1 WHERE queue = $2',
                [Number(tryingCount), channelPresence]
              );
              queueAction(arg.channel, arg.command, arg.callerNumber, arg.cc_agent, channelPresence, Number(board.waiting_seconds));

            } catch (err) {
              console.error('Error handling waiting:', err);
            }
            break;
          }

          case 'ORIGINATOR_CANCEL': {
            try {
              // 1. Update Ready agents in DB
              const tiers = JSON.parse((await getQueueTiers(channelPresence)).body).response;
              const readyAgents = tiers.filter(x => x.state === 'Ready');
              await Promise.all(
                readyAgents.map(async (x) => {
                  const { rows } = await db2.query(
                    'SELECT no_answer_count FROM agent_details WHERE name = $1', [x.agent]
                  );
                  if (rows[0]?.no_answer_count != null) {
                    await db2.query(
                      'UPDATE agent_details SET state = $1, last_offered_call = $2 WHERE name = $3',
                      ['Waiting', arg.lastOfferedCall, x.agent]
                    );
                  }
                })
              );

              // 2. Sync waiting + increment abandoned
              const counts = await getMemberCounts();
              if (!counts) break;
              await Promise.all([
                redisClient.hSet(`walldata:${channel}`, 'waiting_wallboard', counts.tryingCount),
                redisClient.hSet(`walldata:${channel}`, 'waiting',           counts.tryingCount),
                redisClient.hIncrBy(`walldata:${channel}`, 'abandoned_wallboard', 1),
                redisClient.hIncrBy(`walldata:${channel}`, 'abandoned', 1),
              ]);

              // 3. Dedup guard
              if (!(await claimUuidEvent('cancelled'))) break;

              // 4. Update member_board
              const { rows } = await db2.query(
                'SELECT waiting_seconds, waiting_wallboard, total_wallboard FROM member_board WHERE queue = $1',
                [channelPresence]
              );
              const board = rows[0];
              if (board?.waiting_seconds == null || board?.total_wallboard == null) break;


              const WaitingSeconds = await getMemberWaiting('Abandoned');
              const totalWaitingSec  = WaitingSeconds + Number(board.waiting_seconds);
              console.log(`Waiting Seconds before abandoned`, WaitingSeconds, totalWaitingSec);

              await db2.query(
                `UPDATE member_board
                SET waiting_wallboard = $1,
                    total_wallboard = $2,
                    abandoned_wallboard  = GREATEST(COALESCE(abandoned_wallboard, 0) + 1, 0),
                    waiting_seconds = $3
                WHERE queue = $4`,
                [Number(board.waiting_wallboard) - 1, Number(board.total_wallboard) + 1,
                totalWaitingSec, channelPresence]
              );

              // 5. Sync total calls and trigger queueAction
              await syncTotalCalls();
              queueAction(arg.channel, arg.command, arg.callerNumber, arg.cc_agent,
              channelPresence, totalWaitingSec);

            } catch (err) {
              console.error('Error handling ORIGINATOR_CANCEL:', err);
            }
            break;
          }

          case 'NO_ANSWER': {
            try {
              // 1. Update all agents in the tier
              const tiers = JSON.parse((await getQueueTiers(channelPresence)).body).response;
              await Promise.all(
                tiers.map(async (x) => {
                  const { rows } = await db2.query(
                    'SELECT no_answer_count FROM agent_details WHERE name = $1', [x.agent]
                  );
                  const missed = rows[0]?.no_answer_count;
                  if (missed != null) {
                    // [wallboard-missed] no longer +1 for every agent in the queue on a queue timeout
                    // (offline agents and agents whose phone never rang were counted too); the
                    // per-agent miss is counted from callcenter bridge-agent-fail in server.js.
                    await db2.query(
                      `UPDATE agent_details
                      SET state = $1, last_offered_call = $2
                      WHERE name = $3`,
                      ['Waiting', arg.lastOfferedCall, x.agent]
                    );
                  }
                })
              );

              // 2. Sync waiting count
              const counts = await getMemberCounts();
              if (!counts) break;
              await Promise.all([
                redisClient.hSet(`walldata:${channel}`, 'waiting_wallboard', counts.tryingCount),
                redisClient.hSet(`walldata:${channel}`, 'waiting',           counts.tryingCount),
              ]);

              // 3. Dedup guard
              if (!(await claimUuidEvent('noanswer'))) break;

              // 4. Cache no_answer_counts for all queue agents
              const agentNames = tiers.map(x => x.agent);
              if (agentNames.length) {
                const placeholders = agentNames.map((_, i) => `$${i + 1}`).join(', ');
                const { rows: agentRows } = await db2.query(
                  `SELECT name, no_answer_count FROM agents WHERE name IN (${placeholders})`,
                  agentNames
                );
                await Promise.all(
                  agentRows.map(el => Promise.all([
                    redisClient.set(`queueAgent-${channel}-${el.name}`, el.no_answer_count),
                    redisClient.expire(`queueAgent-${channel}-${el.name}`, 86400),
                  ]))
                );
              }

              // 5. Decrement waiting + increment missed
              const waitingVal = Number(await redisClient.hGet(`walldata:${channel}`, 'waiting_wallboard')) || 0;
              await Promise.all([
                ...(waitingVal > 0 ? [
                  redisClient.hIncrBy(`walldata:${channel}`, 'waiting_wallboard', -1),
                  redisClient.hIncrBy(`walldata:${channel}`, 'waiting', -1),
                ] : []),
                redisClient.hIncrBy(`walldata:${channel}`, 'missed_calls', 1),
              ]);

              // 6. Update member_board
              const { rows } = await db2.query(
                'SELECT waiting_wallboard, waiting_seconds, total_wallboard FROM member_board WHERE queue = $1',
                [channelPresence]
              );
              const board = rows[0];
              if (board?.waiting_seconds == null || board?.total_wallboard == null) break;

              const totalWaiting = wbSecs(arg.waitingSeconds) + Number(board.waiting_seconds); // [wb-talk-epoch]
              await db2.query(
                `UPDATE member_board
                SET waiting_wallboard = $1,
                    total_wallboard = $2,
                    waiting_seconds = $3,
                    missed_calls = GREATEST(COALESCE(missed_calls, 0) + 1, 0)
                WHERE queue = $4`,
                [Number(board.waiting_wallboard) - 1, Number(board.total_wallboard) + 1,
                totalWaiting, channelPresence]
              );

              // 7. Sync total calls and trigger queueAction
              await syncTotalCalls();
              queueAction(arg.channel, arg.command, arg.callerNumber, arg.cc_agent, channelPresence, arg.waitingSeconds, totalWaiting);

            } catch (err) {
              console.error('Error handling NO_ANSWER:', err);
            }
            break;
          }

          case 'tryingQUEUE': {
            try {
              const { rows } = await db2.query(
                'SELECT waiting_seconds FROM member_board WHERE queue = $1',
                [channelPresence]
              );
              const waitingSec = rows[0]?.waiting_seconds;
              if (waitingSec != null) {
                queueAction(arg.channel, arg.command, arg.callerNumber, arg.cc_agent,
                channelPresence, Number(waitingSec));
              }
            } catch (err) {
              console.error('Error handling tryingQUEUE:', err);
            }
            break;
          }

          case 'lose_race': {
            try {
              await db2.query(
                'UPDATE agent_details SET state = $1 WHERE name = $2',
                ['Waiting', arg.cc_agent]
              );
            } catch (err) {
              console.error('Error handling lose_race:', err);
            }
            break;
          }

          default:
            break;
        }
          
        }

    });

});


async function queueAction(subdomain, status, callerNumber, cc_agent, queuePresenceOrig, waitingSeconds, memberId, callerName, callId){

  try {

      const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${subdomain}`, 0, -1);
      const newContainer = queueCallBoardList.map(el => JSON.parse(el));
      console.log('CALL DETAILS', queueCallBoardList, status)

      /** Emit Wallboard Call Status */
      if (['waiting', 'answered', 'ended', 'NO_ANSWER', 'ORIGINATOR_CANCEL', 'tryingQUEUE', 'lose_race'].includes(status)) {

        conn.api(`json {"command": "callcenter_config","data": {"arguments":"member list"}}`, async ({ body }) => {
        const {status, response} =  JSON.parse(body)
            console.log(`STATUS CALLS`, response)
            if(status === "success"){

                if(response.length){


                  const epoch = Math.floor(Date.now() / 1000);
                  // const payloadPushAnswered = {
                  //       "queue":`${queuePresenceOrig}` || '',
                  //       "instance_id":"",
                  //       "uuid":`${memberId}` || '',
                  //       "session_uuid":`${callId}` || '',
                  //       "cid_number":`${callerNumber}` || '',
                  //       "cid_name":`${callerName}` || '',
                  //       "system_epoch":`${epoch}` || '',
                  //       "joined_epoch":`${epoch}` || '',
                  //       "rejoined_epoch":"0",
                  //       "bridge_epoch":`${epoch}` || '',
                  //       "abandoned_epoch":"0",
                  //       "base_score":"0",
                  //       "skill_score":"0",
                  //       "serving_agent":cc_agent || '',
                  //       "serving_system":"single_box",
                  //       "state":"Answered",
                  //       "score":"0"
                  // }

                    const stateCall = ['Trying', 'Answered'];
                    const mapped = await Promise.all(
                        response.map(async (x) => {
                            const { rows } = await db2.query(
                                `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
                                [x.cid_number, subdomain]
                            );
                            if (!rows.length) return x;
                            return { ...x, cid_number: rows[0].extension };
                        })
                    );

                    // if (memberId) {
                    //     const existingIndex = mapped.findIndex((x) => x.uuid === memberId);
                    //     if (existingIndex === -1) {
                    //         mapped.push(payloadPushAnswered);
                    //     } else {
                    //         mapped[existingIndex] = { ...mapped[existingIndex], ...payloadPushAnswered };
                    //     }
                    // }

                    console.log(`QQQQQQ`, memberId, mapped)

                    const filterQueue = mapped.filter(
                        (x) => x.queue.includes(subdomain) && stateCall.includes(x.state)
                    );

                    if(filterQueue){

                        //Test
                        // filterQueue.serving_agent = '26fd0be1-7ec8-4ced-a251-dc569a5b2e43';

                        console.log("Queue 1")
                        await redisClient.set(`${subdomain}:queueWallboardCallStatus`, JSON.stringify(filterQueue));
                        socketServerSignal.emit('wallboardCallStatus', { subdomain, data: JSON.stringify(filterQueue) });
                        console.log('resp', filterQueue);

                        
                    }else{
                       console.log("Queue 2")
                        await redisClient.set(`${subdomain}:queueWallboardCallStatus`, JSON.stringify([]));
                        socketServerSignal.emit('wallboardCallStatus', { subdomain, data: JSON.stringify([]) });
                        console.log('resp2', filterQueue);
                    }

                }else{
                  console.log("queue3")
                    await redisClient.set(`${subdomain}:queueWallboardCallStatus`, JSON.stringify([]));
                    socketServerSignal.emit('wallboardCallStatus', { subdomain, data: JSON.stringify([]) });
                }

                console.log("this is checking the redisSet");
                const redisDataSet = await redisClient.get(`${subdomain}:queueWallboardCallStatus`);
                console.log("redsDataSet", redisDataSet);
                
            }
        })

        // await redisClient.set(`${subdomain}:queueWallboardCallStatus`, JSON.stringify(newContainer));
        // socketServerSignal.emit('wallboardCallStatus', { subdomain, data: JSON.stringify(newContainer) });
      }

      /** Emit Wallboard Agent Status */

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
                agent.contact.includes(subdomain)
            ) acc.push(agent);
            return acc;
        }, []);
        console.log("✅ matched:", matched);

        if(matched.length){

            const names = matched.map(e => e.name);
            const { rows } = await db2.query(
                `SELECT 
                    ad.name,
                    ad.contact,
                    ad.max_no_answer,
                    ad.wrap_up_time,
                    ad.reject_delay_time,
                    ad.busy_delay_time,
                    ad.no_answer_delay_time,
                    ad.last_bridge_start,
                    ad.last_bridge_end,
                    ad.last_offered_call,
                    ad.last_status_change,
                    ad.no_answer_count,
                    ad.calls_answered, 
                    ad.talk_time,
                    ad.ready_time,
                    ad.external_calls_count,
                    ve.extension
                FROM agent_details ad
                LEFT JOIN v_extensions ve 
                    ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                WHERE ad.name = ANY($1)`,
                [names]
            );

            // Map results by name for O(1) lookup
            const dbMap = rows.reduce((acc, row) => {
                acc[row.name] = row;
                return acc;
            }, {});

            const newPayload = matched.map(element => {
                const data = dbMap[element.name];
                if(data){
                    const { 
                        contact,
                        max_no_answer,
                        wrap_up_time,
                        reject_delay_time,
                        busy_delay_time,
                        no_answer_delay_time,
                        last_bridge_start,
                        last_bridge_end,
                        last_offered_call,
                        last_status_change,
                        no_answer_count,
                        calls_answered, 
                        talk_time,
                        ready_time,
                        external_calls_count,
                        extension 
                    } = data;
                    console.log('👉🏻NAMES', JSON.stringify(names));
                    const newContact = extension ? updateContact(contact, extension) : contact;
                    return {
                        ...element,
                        contact: newContact,
                        max_no_answer: Number(max_no_answer),
                        wrap_up_time: Number(wrap_up_time),
                        reject_delay_time: Number(reject_delay_time),
                        busy_delay_time: Number(busy_delay_time),
                        no_answer_delay_time: Number(no_answer_delay_time),
                        last_bridge_start: Number(last_bridge_start),
                        last_bridge_end: Number(last_bridge_end),
                        last_offered_call: Number(last_offered_call),
                        last_status_change: Number(last_status_change),
                        no_answer_count: Number(no_answer_count),
                        calls_answered: Number(calls_answered),
                        talk_time: Number(talk_time),
                        ready_time: Number(ready_time),
                        external_calls_count: Number(external_calls_count)
                    };
                }
            });
            
            const filteredAgent = newPayload.filter(Boolean);
            console.log('👉🏻👉🏻', JSON.stringify(filteredAgent));
            await redisClient.set(`${subdomain}:queueWallboardAgentBoard2`, JSON.stringify(filteredAgent));
            socketServerSignal.emit("wallboardAgentStatus", { subdomain, data: JSON.stringify(filteredAgent)});
           
        }

      /** End agent */
      
      /** Emit Wallboard Config Status */
      
      conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, async ({ body }) => {
        const { status, response } = JSON.parse(body);
        console.log(`STATUS CALLS`, response);

        if (status === "success") {
          if (response.length) {
            const names = response
              .filter(e => e.name.includes(subdomain))
              .map(e => e.name);

            if (!names.length) return;

            db2.query(
              `SELECT queue, answered_seconds, abandoned_seconds, talk_time, answered_calls,
              missed_calls, waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
              FROM member_board WHERE queue = ANY($1)`,
              [names],
              async (err, result) => {
                if (err) {
                  console.error("Error fetching data:", err.message);
                  return;
                }
                const rows = result.rows;
                await redisClient.set(
                  `${subdomain}:queueWallboardConfigBoard`,
                  JSON.stringify(rows)
                );
                socketServerSignal.emit("wallboardConfigStatus", { subdomain, data: JSON.stringify(rows) });
                const wallboardData2 = await redisClient.hGetAll(`walldata:${subdomain}`);
                socketServerSignal.emit("wallboardStatus", { subdomain, data: wallboardData2 });
              }
            );
          }
        }
      });

      
      /** End config */ 

      /** Total Answered */
      //ok
      db2.query(
        `SELECT SUM(answered_calls ) AS total_answered
        FROM member_board
        WHERE queue LIKE $1`,
        [`%${subdomain}%`],
        async (err, result) => {
          if (err) {
            console.error("Error executing answered calls query:", err.message);
            return;
          }
          console.log(`IP PHONE`, result, subdomain);
          const totalAnswered = result.rows[0].total_answered || 0;
          await redisClient.hSet(`walldata:${subdomain}`, 'answered_calls', totalAnswered);
        }
      );
      /** End answered */


  } catch (error) {
      console.error("Error in QueueAction:", error.message);
  }
   
}

async function wallBoardDisplay(subdomain){
        console.log("this is trigger wallboard display", subdomain)
    try {
        /** CallStatus */
        const callStatus = await redisClient.get(`${subdomain}:queueWallboardCallStatus`);
        if(callStatus) {
            const parseCallStatus = JSON.parse(callStatus);
            socketServerSignal.emit('wallboardCallStatus', {
                subdomain: subdomain, 
                data: JSON.stringify(parseCallStatus)
            });
            console.log("emit 1 wallboardCallStatus", parseCallStatus)
        }

        /** AgentStatus */
        const agentStatus = await redisClient.get(`${subdomain}:queueWallboardAgentBoard2`);
        if(agentStatus) {
          const parseAgentStatus = JSON.parse(agentStatus);
          socketServerSignal.emit('wallboardAgentStatus', {
              subdomain: subdomain, 
              data: JSON.stringify(parseAgentStatus)
          });

          console.log("emit 2 wallboardAgentStatus", parseAgentStatus)
        }

        /** ConfigStatus */
        const configStatus = await redisClient.get(`${subdomain}:queueWallboardConfigBoard`);
        if(configStatus) {
            const parseConfigStatus = JSON.parse(configStatus);
            socketServerSignal.emit('wallboardConfigStatus', {
                subdomain: subdomain, 
                data: JSON.stringify(parseConfigStatus)
            });
             console.log("emit 3 wallboardConfigStatus", parseConfigStatus);
        }

        /** Wallboard Status */
        const wallboardData2 = await redisClient.hGetAll(`walldata:${subdomain}`);
        if(wallboardData2) {
          socketServerSignal.emit("wallboardStatus", {
              subdomain: subdomain, 
              data: wallboardData2
          });
        }
        
        // [wallboard-refresh] rebuild Agent/Queue details from the DB so an opened
        // wallboard never shows a stale cached snapshot
        await queueAction(subdomain, 'refresh');
    } catch (error) {
        console.error(`Error in wallBoardDisplay for ${subdomain}:`, error);
    }
    
}

function updateContact(contact, extension) {
  return contact.replace(/user\/[^@]+@/, `user/${extension}@`);
}