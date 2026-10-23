<?php
declare(strict_types=1);

// MR hotel front desk update - 2026-09-12 - starts
// Dedicated controller for the Hotel Front Desk feature (guest check-in/
// check-out and wake-up call scheduling), kept separate from
// AccountsController on purpose rather than added to that already-enormous
// controller.

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

require_once __DIR__ . "/../library/HotelPbx.php";

class HotelController extends ControllerBase
{
    /**
     * Common auth guard for every action here. Returns the session array on
     * success; on failure it has already written the error response, so the
     * caller should just `return $this->response;`.
     */
    private function requireAuth()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        if (!$this->session->has("auth")) {
            $this->response->setJsonContent([
                "result" => "error",
                "message" => "Disallowed request.",
            ]);
            return null;
        }
        return $this->session->get("auth");
    }

    /**
     * Pure-PHP UUID v4 - no shelling out to the `uuidgen` binary (unlike
     * IndexController::makeCallAction()'s `trim(shell_exec('uuidgen'))`,
     * which this originally copied): if shell_exec is disabled or uuidgen
     * isn't installed, that returns null, and trim(null) throws a TypeError
     * under this file's declare(strict_types=1) - exactly the kind of
     * failure safely() below now catches, but better to not depend on an
     * external process for this at all.
     */
    /**
     * Tells every front desk of this tenant that a room changed, so they
     * re-fetch it instead of waiting for their next hotel/rooms poll.
     * signal.js relays "hotelRooms" to the tenant room. Best-effort: a
     * socket failure must never fail the check-in or wake-up change itself.
     */
    private function notifyRoomChanged($session, $extension): void
    {
        try {
            $client = new \ElephantIO\Client(\ElephantIO\Client::engine(\ElephantIO\Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                "persistent" => false,
                "timeout" => 2,
            ]));
            $client->initialize();
            $client->emit("hotelRooms", [
                "subdomain" => (string) ($session["subdomain"] ?? ""),
                "extension" => (string) $extension,
            ]);
            $client->close();
        } catch (\Throwable $e) {
            error_log("hotelRooms notify failed: " . $e->getMessage());
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Every action's body runs through here so a DB hiccup (e.g. a table
     * that failed to self-heal because the app's DB user lacks CREATE
     * TABLE rights) always comes back as a clean JSON error instead of a
     * bare 500 - catches \Throwable, not just \Exception, since a PHP
     * \Error (calling a method on null, a TypeError, etc.) would otherwise
     * still escape as an uncaught fatal.
     */
    private function safely(string $errorCode, callable $body)
    {
        try {
            return $body();
        } catch (\Throwable $e) {
            error_log("HotelController error ({$errorCode}): " . get_class($e) . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            $this->response->setJsonContent([
                "result" => "error",
                "message" => "Something went wrong. Please try again. (Code {$errorCode})",
            ]);
            return $this->response;
        }
    }

    /**
     * Self-healing schema for both hotel tables, idempotent and cheap after
     * the first run. Called from every action that touches either one -
     * q_hotel_wakeup_calls' own self-heal lives in MainTask's cron, but that
     * only runs once a minute, so an interactive wakeupSet/wakeupClear
     * request right after a fresh deploy could otherwise hit it before the
     * cron ever has (same class of bug q_hotel_checkins would have had if
     * it only self-healed from a background job). If the app's DB user
     * can't run DDL at all, this logs and the caller's own try/catch around
     * the subsequent SELECT/INSERT still prevents a bare 500.
     */
    private function ensureTables()
    {
        try {
            $this->db->execute("CREATE TABLE IF NOT EXISTS public.q_hotel_checkins (
                hotel_checkin_uuid CHAR(36) PRIMARY KEY,
                domain_uuid CHAR(36) NOT NULL,
                extension_uuid CHAR(36) NOT NULL,
                extension VARCHAR(32) NOT NULL,
                guest_firstname VARCHAR(255) NOT NULL,
                guest_lastname VARCHAR(255) NOT NULL,
                insert_user CHAR(36),
                insert_date TIMESTAMP DEFAULT now()
            );");
            $this->db->execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_hotel_checkins_ext ON public.q_hotel_checkins (domain_uuid, extension_uuid);");

            $this->db->execute("CREATE TABLE IF NOT EXISTS public.q_hotel_wakeup_calls (
                hotel_wakeup_call_uuid CHAR(36) PRIMARY KEY,
                domain_uuid CHAR(36) NOT NULL,
                extension_uuid CHAR(36) NOT NULL,
                extension VARCHAR(32) NOT NULL,
                wake_hour INT NOT NULL,
                wake_minute INT NOT NULL,
                wake_ampm VARCHAR(2) NOT NULL,
                wake_datetime TIMESTAMP NOT NULL,
                status INT NOT NULL DEFAULT 1,
                attempts INT NOT NULL DEFAULT 0,
                insert_user CHAR(36),
                insert_date TIMESTAMP DEFAULT now()
            );");
            $this->db->execute("CREATE INDEX IF NOT EXISTS idx_hotel_wakeup_due ON public.q_hotel_wakeup_calls (status, wake_datetime);");
            $this->db->execute("CREATE INDEX IF NOT EXISTS idx_hotel_wakeup_ext ON public.q_hotel_wakeup_calls (domain_uuid, extension_uuid);");
        } catch (\Throwable $e) {
            error_log("HotelController ensureTables failed: " . $e->getMessage());
        }
    }

    /**
     * List every extension on the domain as a "room," with its guest name
     * (from q_hotel_checkins - a row existing there IS the occupied signal,
     * never inferred from the extension's own real directory name, which
     * every extension already has from normal account setup) and any
     * pending wake-up call. A small, dedicated join (no VUserGroups) rather
     * than reusing AccountsController's extensionAction list query - that
     * query's VUserGroups join fans out into duplicate rows per extension
     * when a user belongs to more than one group, which this page doesn't
     * need to inherit.
     */
    public function roomsAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1000", function () use ($session) {
            $this->ensureTables();

            $builder = $this->modelsManager->createBuilder()
                ->from(['v_users' => VUsers::class])
                ->leftJoin(VExtensionUsers::class, 'v_extension_users.user_uuid = v_users.user_uuid', 'v_extension_users')
                ->leftJoin(VExtensions::class, 'v_extensions.extension_uuid = v_extension_users.extension_uuid', 'v_extensions')
                ->columns([
                    'v_extensions_extension' => 'v_extensions.extension',
                    'v_extensions_uuid'      => 'v_extensions.extension_uuid',
                ])
                ->where('v_users.domain_uuid = :domain_uuid:', [
                    'domain_uuid' => $session["domain_uuid"],
                ])
                ->orderBy('v_extensions.extension ASC');

            $rows = $builder->getQuery()->execute();
            $content = [];
            foreach ($rows as $row) {
                if (empty($row->v_extensions_extension) || !is_numeric($row->v_extensions_extension)) {
                    continue;
                }
                // A table that failed to self-heal must degrade this one
                // row to "vacant, no wake-up call" rather than take down
                // the whole list.
                $checkin = null;
                try {
                    $checkin = QHotelCheckins::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                        "bind" => [
                            "domain_uuid" => $session["domain_uuid"],
                            "extension_uuid" => $row->v_extensions_uuid,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]);
                } catch (\Throwable $e) {
                    error_log("HotelController roomsAction checkin lookup failed: " . $e->getMessage());
                }
                $wake = null;
                try {
                    $wake = QHotelWakeupCalls::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid: AND status = :status:",
                        "bind" => [
                            "domain_uuid" => $session["domain_uuid"],
                            "extension_uuid" => $row->v_extensions_uuid,
                            "status" => 1,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                    ]);
                } catch (\Throwable $e) {
                    error_log("HotelController roomsAction wakeup lookup failed: " . $e->getMessage());
                }
                // "SIP UID / Room" is the same "SIP ID" field the Extensions
                // page's own edit form shows (QCallBehaviors.force_ping) -
                // NOT v_users.username, which is an unrelated login name.
                $sipId = "";
                try {
                    $qCallBehavior = QCallBehaviors::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                        "bind" => [
                            "domain_uuid" => $session["domain_uuid"],
                            "extension_uuid" => $row->v_extensions_uuid,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]);
                    $sipId = $qCallBehavior ? (string)$qCallBehavior->force_ping : "";
                } catch (\Throwable $e) {
                    error_log("HotelController roomsAction SIP ID lookup failed: " . $e->getMessage());
                }
                $content[] = [
                    "extension" => $row->v_extensions_extension,
                    "username" => $sipId,
                    "firstname" => $checkin ? $checkin->guest_firstname : "",
                    "lastname" => $checkin ? $checkin->guest_lastname : "",
                    "wake_hour" => $wake ? intval($wake->wake_hour) : null,
                    "wake_minute" => $wake ? intval($wake->wake_minute) : null,
                    "wake_ampm" => $wake ? $wake->wake_ampm : null,
                    "wake_datetime" => $wake ? $wake->wake_datetime : null,
                ];
            }

            $this->response->setJsonContent([
                "result" => "success",
                "content" => $content,
                "count" => count($content),
            ]);
            return $this->response;
        });
    }

    /**
     * Mirrors a name into the extension's real directory entry
     * (v_extensions/q_users) and busts the caches that read it, so a
     * phone's directory/CID lookup shows the same name as the front desk.
     * Shared by checkinAction() (the guest's name) and checkoutAction()
     * (back to XXX/YYY) - the only two places allowed to touch that record.
     *
     * MR hotel front desk update - 2026-09-15: also writes
     * v_extensions.effective_caller_id_name - ApiController::indexAction()
     * (the login/session-refresh payload that feeds the header/sidebar
     * profile name) derives firstname/lastname by splitting THAT field, not
     * from q_users, so without this the profile name reverted to its old
     * value on every browser refresh even though q_users/the Extensions
     * list had the new name. Also busts the 'vExtensions-<subdomain>' redis
     * cache that same action reads before hitting the DB again.
     */
    private function syncDirectoryName($vExtension, $session, string $firstName, string $lastName): void
    {
        // MR hotel front desk update - 2026-09-15 - starts
        $vExtension->effective_caller_id_name = trim("$firstName $lastName");
        $vExtension->update();
        $this->redis->del("vExtensions-" . $session["subdomain"]);
        // MR hotel front desk update - 2026-09-15 - ends

        if (!$vExtension->vExtensionUsers) {
            return;
        }
        $qUser = QUsers::findFirst([
            "conditions" => "user_uuid = :user_uuid:",
            "bind" => ["user_uuid" => $vExtension->vExtensionUsers->user_uuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        if (!$qUser) {
            return;
        }
        $qUser->first_name = $firstName;
        $qUser->last_name = $lastName;
        $qUser->update();

        if (file_exists("/var/cache/fusionpbx/directory." . $vExtension->extension . "@" . $session["subdomain"])) {
            unlink("/var/cache/fusionpbx/directory." . $vExtension->extension . "@" . $session["subdomain"]);
        }
        if (file_exists("/var/cache/ringq/directory." . $vExtension->extension . "@" . $session["subdomain"])) {
            unlink("/var/cache/ringq/directory." . $vExtension->extension . "@" . $session["subdomain"]);
        }
        if (!empty($vExtension->number_alias) && file_exists("/var/cache/ringq/directory." . $vExtension->number_alias . "@" . $session["subdomain"])) {
            unlink("/var/cache/ringq/directory." . $vExtension->number_alias . "@" . $session["subdomain"]);
        }
        $this->redis->del($session["domain_uuid"] . "-extensions-cache");
    }

    /**
     * Check in a guest, or edit the name of one already checked in. Writes
     * to q_hotel_checkins (the occupied/vacant signal for roomsAction) AND
     * mirrors the same name onto the extension's real directory entry via
     * syncDirectoryName() - so the guest's name shows up anywhere that
     * directory is read from (phone directory, CID, extension list), not
     * just this page.
     */
    public function checkinAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1003", function () use ($session) {
            $this->ensureTables();
            $parameters = $this->request->getJsonRawBody(true);

            $vExtension = VExtensions::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension" => intval($parameters["extension"] ?? 0),
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if (!$vExtension) {
                $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Extension not found. (Code HF1001)",
                ]);
                return $this->response;
            }

            $checkin = QHotelCheckins::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension_uuid" => $vExtension->extension_uuid,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if (!$checkin) {
                $checkin = new QHotelCheckins();
                $checkin->hotel_checkin_uuid = $this->generateUuid();
                $checkin->domain_uuid = $session["domain_uuid"];
                $checkin->extension_uuid = $vExtension->extension_uuid;
                $checkin->extension = $vExtension->extension;
                $checkin->insert_user = $session["user_uuid"];
                $checkin->insert_date = date("Y-m-d H:i:s");
            }
            $checkin->guest_firstname = trim((string)($parameters["firstname"] ?? ""));
            $checkin->guest_lastname = trim((string)($parameters["lastname"] ?? ""));
            if (!$checkin->save()) {
                $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Could not update guest details. (Code HF1003)",
                ]);
                return $this->response;
            }

            $this->syncDirectoryName($vExtension, $session, $checkin->guest_firstname, $checkin->guest_lastname);
            $this->notifyRoomChanged($session, $vExtension->extension);

            $this->response->setJsonContent([
                "result" => "success",
                "message" => "Guest details updated.",
            ]);
            return $this->response;
        });
    }

    /**
     * Check out a room: removes the q_hotel_checkins row (so the room shows
     * vacant again) and, only here, resets the extension's real directory
     * name to XXX/YYY so the departed guest's name stops showing there too.
     */
    public function checkoutAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1004", function () use ($session) {
            $this->ensureTables();
            $parameters = $this->request->getJsonRawBody(true);

            $vExtension = VExtensions::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension" => intval($parameters["extension"] ?? 0),
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if (!$vExtension) {
                $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Extension not found. (Code HF1004)",
                ]);
                return $this->response;
            }

            $checkin = QHotelCheckins::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension_uuid" => $vExtension->extension_uuid,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if ($checkin) {
                $checkin->delete();
            }

            // A wake-up call left pending for the outgoing guest would
            // otherwise still fire (and reach whoever checks in next).
            $wake = QHotelWakeupCalls::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid: AND status = :status:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension_uuid" => $vExtension->extension_uuid,
                    "status" => 1,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if ($wake) {
                $wake->delete();
            }

            // Best-effort: an extension with no linked user still gets
            // checked out of q_hotel_checkins above even if there's no
            // directory name to reset.
            $this->syncDirectoryName($vExtension, $session, "XXX", "YYY");
            $this->notifyRoomChanged($session, $vExtension->extension);

            // Boutique Hotel: the outgoing guest's phone and browser stop
            // being the room (library/HotelPbx.php).
            $vDomain = VDomains::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $session["domain_uuid"]],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            HotelPbx::signOutRoom($this->getDI(), (string) $session["domain_uuid"], $vExtension, $vDomain ? (string) $vDomain->domain_name : "");

            $this->response->setJsonContent([
                "result" => "success",
                "message" => "Guest checked out.",
            ]);
            return $this->response;
        });
    }

    /**
     * Builds a fixed-offset zone from the minutes the *client* reports for
     * its own `DateTime.now().timeZoneOffset` (e.g. 330 for IST, -240 for
     * EDT). Wake-up calls can't be scheduled from any server-side notion of
     * "local" - this box's OS/PHP timezone is wherever it happens to be
     * hosted (confirmed Asia/Shanghai on at least one live box, see
     * worker.php's CLINIKO_DEFAULT_TIMEZONE comment), which has nothing to
     * do with the hotel's actual timezone. A guest picking "10:30 PM"
     * needs that interpreted against the front desk device's own clock,
     * not the server's - otherwise the call silently fires hours off
     * (crossing midnight for evening times), landing "tomorrow" instead of
     * minutes away.
     */
    private function offsetTimezone($offsetMinutes): DateTimeZone
    {
        $minutes = intval($offsetMinutes);
        $sign = $minutes < 0 ? "-" : "+";
        $abs = abs($minutes);
        $hh = str_pad((string) intdiv($abs, 60), 2, "0", STR_PAD_LEFT);
        $mm = str_pad((string) ($abs % 60), 2, "0", STR_PAD_LEFT);
        try {
            return new DateTimeZone($sign . $hh . ":" . $mm);
        } catch (\Throwable $e) {
            return new DateTimeZone("UTC");
        }
    }

    /**
     * Set/update a one-time wake-up call for an extension. Computes the
     * next absolute occurrence of the given wall-clock time (today if still
     * in the future, else tomorrow) and upserts the pending row.
     */
    public function wakeupSetAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1005", function () use ($session) {
            $this->ensureTables();
            $parameters = $this->request->getJsonRawBody(true);

            $vExtension = VExtensions::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension" => intval($parameters["extension"] ?? 0),
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if (!$vExtension) {
                $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Extension not found. (Code HF1004)",
                ]);
                return $this->response;
            }

            $wakeHour = intval($parameters["hour"] ?? 0);
            $wakeMinute = intval($parameters["minute"] ?? 0);
            $wakeAmpm = strtoupper((string)($parameters["ampm"] ?? "AM")) == "PM" ? "PM" : "AM";
            $hour24 = ($wakeHour % 12) + ($wakeAmpm == "PM" ? 12 : 0);
            $tz = $this->offsetTimezone($parameters["utc_offset_minutes"] ?? 0);
            $now = new DateTime("now", $tz);
            $wakeDatetime = new DateTime($now->format("Y-m-d") . " " . sprintf("%02d:%02d:00", $hour24, $wakeMinute), $tz);
            if ($wakeDatetime <= $now) {
                $wakeDatetime->modify("+1 day");
            }
            // Store as an absolute UTC instant so it means the same thing
            // no matter what timezone this PHP process/server happens to
            // default to when MainTask's cron later compares against it.
            $wakeDatetime->setTimezone(new DateTimeZone("UTC"));

            $wake = QHotelWakeupCalls::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid: AND status = :status:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension_uuid" => $vExtension->extension_uuid,
                    "status" => 1,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if (!$wake) {
                $wake = new QHotelWakeupCalls();
                $wake->hotel_wakeup_call_uuid = $this->generateUuid();
                $wake->domain_uuid = $session["domain_uuid"];
                $wake->extension_uuid = $vExtension->extension_uuid;
                $wake->extension = $vExtension->extension;
                $wake->insert_user = $session["user_uuid"];
                $wake->insert_date = date("Y-m-d H:i:s");
            }
            $wake->wake_hour = $wakeHour;
            $wake->wake_minute = $wakeMinute;
            $wake->wake_ampm = $wakeAmpm;
            $wake->wake_datetime = $wakeDatetime->format("Y-m-d H:i:s");
            $wake->status = 1;
            $wake->attempts = 0;
            if (!$wake->save()) {
                $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Could not schedule wake-up call. (Code HF1005)",
                ]);
                return $this->response;
            }

            $this->notifyRoomChanged($session, $vExtension->extension);

            $this->response->setJsonContent([
                "result" => "success",
                "message" => "Wake-up call scheduled.",
            ]);
            return $this->response;
        });
    }

    /** Clears a pending wake-up call for an extension. */
    public function wakeupClearAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1006", function () use ($session) {
            $this->ensureTables();
            $parameters = $this->request->getJsonRawBody(true);

            $vExtension = VExtensions::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "extension" => intval($parameters["extension"] ?? 0),
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if ($vExtension) {
                $wake = QHotelWakeupCalls::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid: AND status = :status:",
                    "bind" => [
                        "domain_uuid" => $session["domain_uuid"],
                        "extension_uuid" => $vExtension->extension_uuid,
                        "status" => 1,
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                ]);
                if ($wake) {
                    $wake->delete();
                }
                $this->notifyRoomChanged($session, $vExtension->extension);
            }

            $this->response->setJsonContent([
                "result" => "success",
                "message" => "Wake-up call cleared.",
            ]);
            return $this->response;
        });
    }

    /**
     * GET/set the domain's wake-up IVR destination - a plain IVR extension
     * number (not a UUID). Either picked from the existing IVR list or
     * typed in directly as a custom number; either way the cron just
     * transfers the answered call into the dialplan for that number, so no
     * IVR-menu record needs to exist for a custom number to work.
     */
    public function ivrSettingsAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1007", function () use ($session) {
            $redisKey = "landing:" . $session["subdomain"] . "-wakeup-ivr-extension";

            if ($this->request->isGet()) {
                $this->response->setJsonContent([
                    "result" => "success",
                    "selected_ivr_extension" => $this->redis->get($redisKey) ?: "",
                ]);
                return $this->response;
            }

            $parameters = $this->request->getJsonRawBody(true);
            $ivrExtension = trim((string)($parameters["ivr_extension"] ?? ""));
            if ($ivrExtension === "") {
                $this->redis->del($redisKey);
                $this->response->setJsonContent([
                    "result" => "success",
                    "message" => "Wake-up call IVR cleared.",
                ]);
                return $this->response;
            }
            if (!ctype_digit($ivrExtension)) {
                $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Enter a numeric IVR extension.",
                ]);
                return $this->response;
            }
            $this->redis->set($redisKey, $ivrExtension);
            $this->response->setJsonContent([
                "result" => "success",
                "message" => "Wake-up call IVR updated.",
            ]);
            return $this->response;
        });
    }

    // MR hotel front desk update - 2026-09-12 - starts
    /**
     * GET/set whether the "Hotel Front Desk" item shows in the side menu at
     * all - a per-domain on/off switch surfaced from Chameleon's Hotel PBX
     * settings screen. Stored in redis the same way as ivrSettingsAction()
     * above rather than a new table, since it's a single flag. Off by
     * default (no key set) so a domain that's never touched this feature
     * doesn't suddenly get a new, unconfigured menu item.
     */
    public function frontDeskMenuAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1008", function () use ($session) {
            $redisKey = "landing:" . $session["subdomain"] . "-hotel-frontdesk-menu-enabled";

            if ($this->request->isGet()) {
                // No-store so a browser/proxy never serves a stale answer
                // for this same GET URL after the toggle is flipped and the
                // page is refreshed.
                $this->response->setHeader("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0");
                $this->response->setHeader("Pragma", "no-cache");
                $this->response->setJsonContent([
                    "result" => "success",
                    "enabled" => $this->redis->get($redisKey) === "1",
                ]);
                return $this->response;
            }

            $parameters = $this->request->getJsonRawBody(true);
            $enabled = !empty($parameters["enabled"]);
            if ($enabled) {
                $this->redis->set($redisKey, "1");
            } else {
                $this->redis->del($redisKey);
            }
            $this->response->setJsonContent([
                "result" => "success",
                "message" => $enabled ? "Hotel Front Desk menu enabled." : "Hotel Front Desk menu disabled.",
            ]);
            return $this->response;
        });
    }
    // MR hotel front desk update - 2026-09-12 - ends

    // MR hotel room number mode - 2026-10-22 - starts
    /**
     * GET/set whether rooms are presented by their extension number or by
     * their SIP UID (the extension's SIP username) - a per-domain choice
     * surfaced from Chameleon's Hotel PBX settings screen and read by the
     * web dialer's Hotel PBX room screen. Stored in redis the same way as
     * frontDeskMenuAction() above since it's a single flag; no key set
     * means "extension", the long-standing presentation, so domains that
     * never touch this keep exactly what they had.
     */
    public function roomNumberModeAction()
    {
        $session = $this->requireAuth();
        if (!$session) {
            return $this->response;
        }
        return $this->safely("HF1009", function () use ($session) {
            $redisKey = "landing:" . $session["subdomain"] . "-hotel-room-number-mode";

            if ($this->request->isGet()) {
                // The caller's own SIP ID (the Extensions page's "SIP ID",
                // q_call_behaviors.force_ping - the same field Front Desk
                // shows as "SIP UID / Room" and guest sign-in accepts, NOT
                // v_users.username) so the dialer can present it as the
                // room number without another endpoint.
                $sipUid = "";
                try {
                    $vExtensionUser = VExtensionUsers::findFirst([
                        "conditions" => "user_uuid = :u:",
                        "bind" => ["u" => $session["user_uuid"]],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]);
                    if ($vExtensionUser) {
                        $qCallBehavior = QCallBehaviors::findFirst([
                            "conditions" => "domain_uuid = :d: AND extension_uuid = :x:",
                            "bind" => ["d" => $session["domain_uuid"], "x" => $vExtensionUser->extension_uuid],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]);
                        $sipUid = $qCallBehavior ? (string) $qCallBehavior->force_ping : "";
                    }
                } catch (\Throwable $e) {
                    error_log("HotelController roomNumberModeAction SIP ID lookup failed: " . $e->getMessage());
                }
                $this->response->setHeader("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0");
                $this->response->setHeader("Pragma", "no-cache");
                $this->response->setJsonContent([
                    "result" => "success",
                    "mode" => $this->redis->get($redisKey) === "sipuid" ? "sipuid" : "extension",
                    "sip_uid" => $sipUid,
                ]);
                return $this->response;
            }

            $parameters = $this->request->getJsonRawBody(true);
            $mode = isset($parameters["mode"]) && $parameters["mode"] === "sipuid" ? "sipuid" : "extension";
            if ($mode === "sipuid") {
                $this->redis->set($redisKey, "sipuid");
            } else {
                $this->redis->del($redisKey);
            }
            $this->response->setJsonContent([
                "result" => "success",
                "message" => $mode === "sipuid" ? "Rooms now show the SIP UID." : "Rooms now show the extension number.",
            ]);
            return $this->response;
        });
    }
    // MR hotel room number mode - 2026-10-22 - ends
}
// MR hotel front desk update - 2026-09-12 - ends
