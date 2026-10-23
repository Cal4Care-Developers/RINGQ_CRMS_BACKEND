/**
 * T1.3 verification benchmark — proves the new key schema is O(1) and quantifies
 * what the legacy list model costs.
 *
 * Requires a real redis and the real node-redis v4 client (the FakeRedis double
 * used by run.js cannot demonstrate wire-level latency):
 *
 *   redis-server --port 6399 --save '' --appendonly no --daemonize yes
 *   REDIS_URL=redis://127.0.0.1:6399 NODE_PATH=<dir with node_modules> \
 *     node ringq/node/meet/tests/bench.js
 *
 * It seeds the SAME data both ways and measures room lookup as the room count
 * grows. The new path must stay flat; the legacy path is expected to grow with n.
 */

const path = require("path");
const rooms = require("../rooms.js");
const tenantMod = require("../tenant.js");

const REDIS_URL = process.env.REDIS_URL || "redis://127.0.0.1:6399";
const PARTICIPANTS_PER_ROOM = 20;
const SCALES = [10, 50, 200];
const SAMPLES = 200;

const TENANT = Object.freeze({
  subdomain: "bench.ringq.ai",
  domainUuid: "00000000-0000-0000-0000-0000000000bb",
  tenantKey: tenantMod.deriveTenantKey("bench.ringq.ai", "00000000-0000-0000-0000-0000000000bb"),
  keyPrefix: null, // set below
});
const TENANT_FIXED = Object.freeze(
  Object.assign({}, TENANT, { keyPrefix: tenantMod.keyPrefix(TENANT.tenantKey) })
);

function percentile(sorted, p) {
  const idx = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length));
  return sorted[idx];
}

async function timeIt(fn) {
  const samples = [];
  for (let i = 0; i < SAMPLES; i++) {
    const t0 = process.hrtime.bigint();
    await fn(i);
    const t1 = process.hrtime.bigint();
    samples.push(Number(t1 - t0) / 1e6); // ms
  }
  samples.sort(function (a, b) {
    return a - b;
  });
  return {
    p50: percentile(samples, 50),
    p95: percentile(samples, 95),
  };
}

async function main() {
  const redisLib = require("redis");
  const client = redisLib.createClient({ url: REDIS_URL });
  client.on("error", function (e) {
    process.stdout.write("redis error: " + e.message + "\n");
  });
  await client.connect();
  await client.flushDb();

  process.stdout.write("RingQ Meet — T1.3 room-lookup benchmark\n");
  process.stdout.write("node " + process.version + " · redis client 4.x · " + REDIS_URL + "\n");
  process.stdout.write(
    PARTICIPANTS_PER_ROOM + " participants/room · " + SAMPLES + " samples per measurement\n\n"
  );

  const store = new rooms.RoomStore({ redis: client, tenant: TENANT_FIXED });
  const results = [];

  for (const scale of SCALES) {
    await client.flushDb();

    const ids = [];
    // ── seed the NEW model ──
    for (let r = 0; r < scale; r++) {
      const id = "room-" + r;
      ids.push(id);
      await store.createRoom({
        meetingId: id,
        ownerKey: "owner" + r,
        slug: "slug" + r,
        title: "Room " + r,
      });
      for (let p = 0; p < PARTICIPANTS_PER_ROOM; p++) {
        await store.addParticipant(id, "p-" + r + "-" + p, {
          displayName: "User " + p,
          email: "u" + p + "@e.co",
        });
      }
    }

    // ── seed the LEGACY model: two global lists of JSON blobs ──
    const legacyRooms = "bench:legacy:rooms";
    const legacyParts = "bench:legacy:connected_participants";
    for (let r = 0; r < scale; r++) {
      await client.rPush(
        legacyRooms,
        JSON.stringify({ owner: "owner" + r, room_name: "room-" + r, meet_type: "instant" })
      );
      for (let p = 0; p < PARTICIPANTS_PER_ROOM; p++) {
        await client.rPush(
          legacyParts,
          JSON.stringify({
            socket_id: "s" + r + p,
            session_id: "sess" + r + p,
            room_id: "owner" + r,
            display_name: "User " + p,
            email: "u" + p + "@e.co",
            audio_status: true,
            video_status: false,
            mirror_video: false,
            blur_video: false,
            background_video: false,
            background_image: "",
            raisehand_status: false,
            join_status: "joined",
            user_media: "{}",
            display_media: "{}",
          })
        );
      }
    }

    // NEW: resolve a room id + read its participants (what an API call does)
    const newTiming = await timeIt(async function (i) {
      const id = ids[i % ids.length];
      const resolved = await store.resolveMeetingId(id);
      await store.getRoom(resolved);
      await store.listParticipants(resolved);
    });

    // LEGACY: exactly what MeetController.php does — lrange both lists, scan
    const legacyTiming = await timeIt(async function (i) {
      const target = "room-" + (i % scale);
      const roomsJson = await client.lRange(legacyRooms, 0, -1);
      const partsJson = await client.lRange(legacyParts, 0, -1);
      let found = null;
      for (let k = 0; k < roomsJson.length; k++) {
        const room = JSON.parse(roomsJson[k]);
        if (room.room_name === target || String(room.owner).toLowerCase() === target) {
          found = room;
          break;
        }
      }
      const joined = [];
      for (let k = 0; k < partsJson.length; k++) {
        const p = JSON.parse(partsJson[k]);
        if (found && p.room_id === found.owner) joined.push(p);
      }
      return joined.length;
    });

    results.push({ scale: scale, neo: newTiming, legacy: legacyTiming });
    process.stdout.write(
      "  " +
        String(scale).padStart(4) +
        " rooms (" +
        scale * PARTICIPANTS_PER_ROOM +
        " participants)   new p50 " +
        newTiming.p50.toFixed(3) +
        "ms  p95 " +
        newTiming.p95.toFixed(3) +
        "ms   |   legacy p50 " +
        legacyTiming.p50.toFixed(3) +
        "ms  p95 " +
        legacyTiming.p95.toFixed(3) +
        "ms\n"
    );
  }

  // ── verdict ──
  const first = results[0];
  const last = results[results.length - 1];
  const newGrowth = last.neo.p50 / first.neo.p50;
  const legacyGrowth = last.legacy.p50 / first.legacy.p50;
  const scaleGrowth = last.scale / first.scale;

  process.stdout.write("\n" + "-".repeat(72) + "\n");
  process.stdout.write(
    "room count grew " + scaleGrowth.toFixed(0) + "x  (" + first.scale + " -> " + last.scale + ")\n"
  );
  process.stdout.write("  new schema p50 grew " + newGrowth.toFixed(2) + "x\n");
  process.stdout.write("  legacy     p50 grew " + legacyGrowth.toFixed(2) + "x\n");
  process.stdout.write(
    "  speedup at " + last.scale + " rooms: " + (last.legacy.p50 / last.neo.p50).toFixed(1) + "x\n\n"
  );

  // O(1) means latency must not track the data size. Allow generous headroom for
  // jitter on a laptop: flat is <2x while the data grew 20x.
  const O1_TOLERANCE = 2.0;
  const pass = newGrowth < O1_TOLERANCE;
  process.stdout.write(
    (pass ? "PASS" : "FAIL") +
      ": new-schema lookup is " +
      (pass ? "flat" : "NOT flat") +
      " as room count grows (" +
      newGrowth.toFixed(2) +
      "x < " +
      O1_TOLERANCE +
      "x required)\n"
  );
  process.stdout.write(
    (legacyGrowth > 2 ? "CONFIRMED" : "note") +
      ": legacy lookup grows with total rooms (" +
      legacyGrowth.toFixed(2) +
      "x)\n"
  );

  await client.flushDb();
  await client.quit();
  process.exit(pass ? 0 : 1);
}

main().catch(function (err) {
  process.stdout.write("benchmark failed: " + (err && err.stack ? err.stack : err) + "\n");
  process.exit(1);
});
void path;
