<?php
declare(strict_types=1);

use Phalcon\Db\Column;
use Phalcon\Db\Enum;
use ElephantIO\Client;

/**
 * Boutique Hotel (Hotel PBX) guest sign-in.
 *
 * The whole PBX has one QR code. It encodes https://{tenant}/hotel/?k={token}:
 * a guest scans it with the RingQ app or the phone camera, types their last
 * name and room number, and is signed in to that room's extension. Only
 * guests the front desk has checked in can sign in (the last name must match
 * q_hotel_checkins), and only extensions in the Hotel PBX Chameleon mode
 * count as rooms.
 *
 * Rotating the token makes every printed copy of the old QR stop working.
 * It can rotate by hand or automatically (daily / weekly); automatic rotation
 * happens lazily, the next time anything reads the settings.
 *
 * A browser guest is given the room's SIP identity plus a guest token; the
 * guest page keeps checking the token, and checkout (or a new check-in on the
 * room) ends it. A RingQ app guest gets an ordinary app session for the
 * room, which checkout signs out (see signOutRoom).
 */
class HotelPbx
{
    /** Chameleon mode of a hotel room (lib/realtime/chameleon.ts CHAMELEON.hotel). */
    const ROOM_MODE = "3";

    const ROTATIONS = ["off" => 0, "daily" => 86400, "weekly" => 604800];

    /** A browser guest token outlives any normal stay; checkout ends it sooner. */
    const GUEST_TTL = 86400 * 30;

    public static function ensureTable($db): void
    {
        try {
            $db->execute("CREATE TABLE IF NOT EXISTS public.q_hotel_pbx (
                domain_uuid CHAR(36) PRIMARY KEY,
                enabled BOOLEAN NOT NULL DEFAULT false,
                qr_token VARCHAR(64) NOT NULL,
                rotate VARCHAR(10) NOT NULL DEFAULT 'off',
                rotated_at TIMESTAMP NOT NULL DEFAULT now(),
                hotel_name VARCHAR(120) NOT NULL DEFAULT '',
                update_date TIMESTAMP DEFAULT now()
            );");
            $db->execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_hotel_pbx_token ON public.q_hotel_pbx (qr_token);");
        } catch (\Throwable $e) {
            error_log("HotelPbx ensureTable failed: " . $e->getMessage());
        }
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), "+/", "-_"), "=");
    }

    /** The domain's row, created (disabled) on first read, rotated if its period is up. */
    public static function settings($db, string $domainUuid): array
    {
        self::ensureTable($db);
        $row = $db->fetchOne(
            "SELECT domain_uuid, enabled, qr_token, rotate, rotated_at, hotel_name FROM q_hotel_pbx WHERE domain_uuid = :d",
            Enum::FETCH_ASSOC,
            ["d" => $domainUuid]
        );
        if (!$row) {
            $db->execute(
                "INSERT INTO q_hotel_pbx (domain_uuid, enabled, qr_token, rotate, rotated_at, hotel_name) VALUES (:d, false, :t, 'off', now(), '') ON CONFLICT (domain_uuid) DO NOTHING",
                ["d" => $domainUuid, "t" => self::newToken()]
            );
            return self::settings($db, $domainUuid);
        }
        $period = self::ROTATIONS[$row["rotate"]] ?? 0;
        if ($period > 0 && strtotime((string) $row["rotated_at"]) + $period <= time()) {
            self::rotate($db, $domainUuid);
            return self::settings($db, $domainUuid);
        }
        return self::describe($row);
    }

    public static function rotate($db, string $domainUuid): void
    {
        $db->execute(
            "UPDATE q_hotel_pbx SET qr_token = :t, rotated_at = now(), update_date = now() WHERE domain_uuid = :d",
            ["t" => self::newToken(), "d" => $domainUuid]
        );
    }

    public static function save($db, string $domainUuid, bool $enabled, string $rotate, string $hotelName): void
    {
        self::settings($db, $domainUuid);
        $db->execute(
            "UPDATE q_hotel_pbx SET enabled = :e, rotate = :r, hotel_name = :n, update_date = now() WHERE domain_uuid = :d",
            ["e" => $enabled ? "true" : "false", "r" => $rotate, "n" => $hotelName, "d" => $domainUuid]
        );
    }

    /** The domain a scanned token belongs to, or null when unknown, rotated away or switched off. */
    public static function domainForToken($db, string $token): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            return null;
        }
        self::ensureTable($db);
        $row = $db->fetchOne(
            "SELECT domain_uuid FROM q_hotel_pbx WHERE qr_token = :t",
            Enum::FETCH_ASSOC,
            ["t" => $token]
        );
        if (!$row) {
            return null;
        }
        // Re-read through settings() so an overdue automatic rotation lands
        // now and the old token stops working on time.
        $settings = self::settings($db, (string) $row["domain_uuid"]);
        if (!$settings["enabled"] || !hash_equals($settings["token"], $token)) {
            return null;
        }
        return (string) $row["domain_uuid"];
    }

    private static function describe(array $row): array
    {
        $period = self::ROTATIONS[$row["rotate"]] ?? 0;
        $rotatedAt = strtotime((string) $row["rotated_at"]);
        return [
            "enabled" => $row["enabled"] === true || $row["enabled"] === "t" || $row["enabled"] === "true" || $row["enabled"] === 1,
            "token" => (string) $row["qr_token"],
            "rotate" => isset(self::ROTATIONS[$row["rotate"]]) ? (string) $row["rotate"] : "off",
            "rotatedAt" => $rotatedAt ? gmdate("c", $rotatedAt) : "",
            "nextRotation" => $period > 0 && $rotatedAt ? gmdate("c", $rotatedAt + $period) : "",
            "hotelName" => (string) $row["hotel_name"],
        ];
    }

    /**
     * The service buttons and keypad switch the Hotel PBX skin shows, in the
     * bootstrap's own `hotelParameters` shape (ApiController::indexAction).
     */
    public static function hotelParameters($redis, string $domainUuid, string $subdomain): array
    {
        $params = [];
        $qHotels = QHotel::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "bind" => ["domain_uuid" => $domainUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        foreach ($qHotels as $qHotel) {
            $slot = intval($qHotel->slot);
            if ($slot >= 7 && $slot <= 10) {
                // Banner images, served from /img/ on the API port. Only ones
                // the hotel uploaded: a guest's room phone never shows
                // Chameleon's generic fallback artwork.
                $image = trim((string) $qHotel->image);
                if ($image !== "") {
                    $params[] = [$slot => ["image" => $image]];
                }
                continue;
            }
            if ($slot < 1 || $slot > 6) {
                continue;
            }
            $params[] = [$slot => ["name" => $qHotel->button, "extension" => $qHotel->extension]];
        }
        $showDialer = $redis->get("hotel-" . $subdomain . "-showDialer");
        if ($showDialer == "0" || $showDialer == "1") {
            $params[] = [11 => $showDialer == "1"];
        }
        return $params;
    }

    /**
     * An untouched Hotel PBX skin's buttons all dial 1000 (Chameleon's
     * defaults); they are only offered to a guest if 1000 really exists.
     */
    public static function defaultsReady(string $domainUuid): bool
    {
        return (bool) VExtensions::findFirst([
            "conditions" => "domain_uuid = :d: AND extension = '1000'",
            "bind" => ["d" => $domainUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
    }

    /**
     * Ends every guest sign-in on a room: browser guest tokens, and the RingQ
     * app session (its remember-me token, its push registrations, and the
     * open app, which is told over the socket and signed out on its next
     * request the same way as when another phone takes the extension).
     * Desk phones are not touched. Best-effort: never fails a checkout.
     */
    public static function signOutRoom($di, string $domainUuid, $vExtension, string $domainName): void
    {
        $redis = $di->getShared("redis");
        $extensionUuid = (string) $vExtension->extension_uuid;
        $extension = (string) $vExtension->extension;
        try {
            foreach ((array) $redis->smembers("hotelguest-room:" . $extensionUuid) as $token) {
                $redis->del("hotelguest:" . $token);
            }
            $redis->del("hotelguest-room:" . $extensionUuid);
        } catch (\Throwable $e) {
            error_log("HotelPbx signOutRoom (browser) failed: " . $e->getMessage());
        }

        $domainNames = array_values(array_unique(array_filter([$domainName, (string) $vExtension->accountcode])));
        try {
            $link = VExtensionUsers::findFirst([
                "conditions" => "extension_uuid = :e:",
                "bind" => ["e" => $extensionUuid],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($link) {
                foreach (QTokens::find([
                    "conditions" => "user_uuid = :u:",
                    "bind" => ["u" => (string) $link->user_uuid],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]) as $qToken) {
                    if (ControllerBase::isMobileAppUserAgent((string) $qToken->user_agent)) {
                        $qToken->delete();
                    }
                }
            }
            // A new owner nobody holds: the open app's next request is refused
            // (ControllerBase::mobileReplaced) and it signs out.
            $nobody = bin2hex(random_bytes(32));
            foreach ($domainNames as $d) {
                $redis->set("mobileOwner-" . $extension . "@" . $d, $nobody);
            }
            // The guest's phone must stop ringing for the room's calls.
            foreach (QDevices::find([
                "conditions" => "domain_uuid = :d: AND extension = :e: AND device_type IN (1, 2)",
                "bind" => ["d" => $domainUuid, "e" => $extension],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]) as $device) {
                $pushToken = trim((string) $device->push_token);
                if ($pushToken !== "") {
                    foreach ($domainNames as $d) {
                        $base = $extension . "@" . $d;
                        $redis->srem("iosDevicePushToken-" . $base, $pushToken);
                        $redis->srem("iosSucessPushToken-" . $base, $pushToken);
                        foreach ((array) $redis->smembers($base) as $member) {
                            $member = (string) $member;
                            if ($member === $pushToken || strpos($member, $pushToken . ":") === 0) {
                                $redis->srem($base, $member);
                            }
                        }
                    }
                }
                $device->delete();
            }
        } catch (\Throwable $e) {
            error_log("HotelPbx signOutRoom (app) failed: " . $e->getMessage());
        }
        try {
            $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", ["persistent" => false, "timeout" => 2]));
            $client->initialize();
            foreach ($domainNames as $d) {
                $client->emit("mobileReplaced", ["extension" => $extension, "subdomain" => $d]);
            }
            $client->close();
        } catch (\Throwable $e) {
            error_log("HotelPbx signOutRoom notify failed: " . $e->getMessage());
        }
        error_log("[hotel-pbx] room " . $extension . "@" . implode(",", $domainNames) . " signed out at checkout");
    }
}
