<?php
declare(strict_types=1);

use Phalcon\Cli\Task;

/**
 * RingQ Meet — durable schema for the tenant's own `ringq` database.
 *
 *   php /var/www/ringq/app/cli.php meetSchema up      # create / bring up to date
 *   php /var/www/ringq/app/cli.php meetSchema check   # report only, changes nothing
 *
 * Design decisions (docs/MEET-DATA-MODEL.md):
 *
 *  - Lives in the tenant's EXISTING `ringq` database. There is deliberately no
 *    separate `meet` database: these rows must be joinable to users, extensions
 *    and domain_uuid, and a second database is what made the previous meet-api
 *    single-tenant.
 *  - Every table carries `domain_uuid NOT NULL`. The legacy meet had no tenant
 *    column at all, which let one FusionPBX domain read another's rooms.
 *  - Tables are prefixed `meet_` because they join a FusionPBX schema where a
 *    bare `meetings` or `recordings` would be asking for a future collision.
 *  - Redis still holds LIVE state (presence, waiting room). These tables are the
 *    durable audit trail, so losing Redis ends in-flight meetings and nothing else.
 *  - `webinar_registrations` from the salvaged schema is intentionally NOT ported:
 *    it had no meeting_id and PHP's webinarRegisterAction already owns that data.
 *
 * Idempotent: every statement is IF NOT EXISTS / guarded, so re-running is safe.
 * Follows the MainTask::testdbAction precedent for schema management.
 */
class MeetSchemaTask extends Task
{
    /** Ordered DDL. Parents before children so the FKs resolve. */
    private function statements(): array
    {
        return [
            // gen_random_uuid() lives in pgcrypto on PG < 13 and is built in from 13;
            // creating the extension is harmless either way and makes the defaults safe.
            "CREATE EXTENSION IF NOT EXISTS pgcrypto;",

            "CREATE TABLE IF NOT EXISTS public.meet_meetings (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                domain_uuid uuid NOT NULL,
                slug text,
                external_reference text,
                owner_key text NOT NULL,
                owner_extension_uuid uuid,
                owner_user_uuid uuid,
                title text,
                meet_type text NOT NULL DEFAULT 'instant',
                status text NOT NULL DEFAULT 'created',
                scheduled_at timestamptz,
                started_at timestamptz,
                ended_at timestamptz,
                settings jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_meetings_meet_type_check
                    CHECK (meet_type IN ('instant','scheduled')),
                CONSTRAINT meet_meetings_status_check
                    CHECK (status IN ('created','live','ended','missed'))
            );",

            "ALTER TABLE public.meet_meetings
                ADD COLUMN IF NOT EXISTS external_reference text;",
            "ALTER TABLE public.meet_meetings
                ADD COLUMN IF NOT EXISTS end_reason text;",
            "ALTER TABLE public.meet_meetings
                ADD COLUMN IF NOT EXISTS ended_by_ref text;",
            "ALTER TABLE public.meet_meetings
                ADD COLUMN IF NOT EXISTS review_nonce uuid NOT NULL DEFAULT gen_random_uuid();",

            // A slug is a shareable code, so it must be unique per domain — but
            // only where present, hence the partial unique index.
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_meetings_domain_slug_uk
                ON public.meet_meetings (domain_uuid, slug) WHERE slug IS NOT NULL;",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_meetings_domain_external_ref_uk
                ON public.meet_meetings (domain_uuid, external_reference)
                WHERE external_reference IS NOT NULL;",

            // Every index leads with domain_uuid for the same reason every query
            // must: tenant scoping is not optional.
            "CREATE INDEX IF NOT EXISTS meet_meetings_domain_status_idx
                ON public.meet_meetings (domain_uuid, status);",
            "CREATE INDEX IF NOT EXISTS meet_meetings_domain_owner_idx
                ON public.meet_meetings (domain_uuid, owner_key);",
            // Replaces the legacy lrange('rooms',0,-1) history scan.
            "CREATE INDEX IF NOT EXISTS meet_meetings_domain_created_idx
                ON public.meet_meetings (domain_uuid, created_at DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_participants (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                participant_ref text,
                display_name text NOT NULL,
                email text,
                role text NOT NULL DEFAULT 'participant',
                joined_at timestamptz,
                left_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_participants_role_check
                    CHECK (role IN ('host','cohost','participant'))
            );",
            "CREATE INDEX IF NOT EXISTS meet_participants_meeting_idx
                ON public.meet_participants (meeting_id);",
            // Presence webhooks arrive keyed by the LiveKit identity, so this is
            // the lookup that closes a participant's row on participant_left.
            "CREATE INDEX IF NOT EXISTS meet_participants_ref_idx
                ON public.meet_participants (meeting_id, participant_ref);",

            "CREATE TABLE IF NOT EXISTS public.meet_recordings (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                egress_id text,
                file_path text,
                kind text NOT NULL DEFAULT 'audio',
                status text NOT NULL DEFAULT 'starting',
                started_at timestamptz,
                ended_at timestamptz,
                CONSTRAINT meet_recordings_kind_check CHECK (kind IN ('audio','video')),
                CONSTRAINT meet_recordings_status_check
                    CHECK (status IN ('starting','active','complete','failed'))
            );",
            "CREATE INDEX IF NOT EXISTS meet_recordings_meeting_idx
                ON public.meet_recordings (meeting_id);",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS client_action_id uuid;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS request_hash char(64);",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS version bigint NOT NULL DEFAULT 1;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS storage_path text;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS original_name text;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS sha256 char(64);",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS bytes bigint;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS mime text;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS failure_code text;",
            "ALTER TABLE public.meet_recordings ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();",
            "ALTER TABLE public.meet_recordings DROP CONSTRAINT IF EXISTS meet_recordings_status_check;",
            "ALTER TABLE public.meet_recordings ADD CONSTRAINT meet_recordings_status_check
                CHECK (status IN ('starting','active','stopping','complete','failed','revoked'));",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_recordings_idempotency_uk
                ON public.meet_recordings (domain_uuid, meeting_id, client_action_id)
                WHERE client_action_id IS NOT NULL;",
            "CREATE INDEX IF NOT EXISTS meet_recordings_domain_status_idx
                ON public.meet_recordings (domain_uuid, meeting_id, status, created_at DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_transcripts (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                participant text,
                text text NOT NULL,
                at timestamptz NOT NULL DEFAULT now()
            );",
            // Expect high row counts — always read as a time-ordered slice.
            "CREATE INDEX IF NOT EXISTS meet_transcripts_meeting_at_idx
                ON public.meet_transcripts (meeting_id, at);",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS segment_id uuid;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS participant_ref text;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS participant_name text;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS track_sid text;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS sequence bigint;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS start_ms bigint;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS end_ms bigint;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS language text;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS confidence double precision;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS is_final boolean NOT NULL DEFAULT true;",
            "ALTER TABLE public.meet_transcripts ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_transcripts_segment_uk
                ON public.meet_transcripts (domain_uuid, meeting_id, track_sid, segment_id)
                WHERE segment_id IS NOT NULL;",
            "CREATE INDEX IF NOT EXISTS meet_transcripts_domain_sequence_idx
                ON public.meet_transcripts (domain_uuid, meeting_id, sequence);",

            "CREATE TABLE IF NOT EXISTS public.meet_summaries (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                html text NOT NULL,
                emailed_to jsonb NOT NULL DEFAULT '[]'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE INDEX IF NOT EXISTS meet_summaries_meeting_idx
                ON public.meet_summaries (meeting_id);",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'pending';",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS structured jsonb NOT NULL DEFAULT '{}'::jsonb;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS plain_text text;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS provider text;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS model text;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 1;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS failure_code text;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS attempts integer NOT NULL DEFAULT 0;",
            "ALTER TABLE public.meet_summaries ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();",
            "ALTER TABLE public.meet_summaries DROP CONSTRAINT IF EXISTS meet_summaries_status_check;",
            "ALTER TABLE public.meet_summaries ADD CONSTRAINT meet_summaries_status_check
                CHECK (status IN ('pending','processing','ready','failed','unavailable'));",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_summaries_domain_meeting_uk
                ON public.meet_summaries (domain_uuid, meeting_id);",

            "CREATE TABLE IF NOT EXISTS public.meet_capture_states (
                meeting_id uuid PRIMARY KEY REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                version bigint NOT NULL DEFAULT 0,
                recording_id uuid REFERENCES public.meet_recordings(id) ON DELETE SET NULL,
                recording_status text NOT NULL DEFAULT 'idle',
                transcription_status text NOT NULL DEFAULT 'idle',
                transcription_language text NOT NULL DEFAULT 'auto',
                transcription_started_by_recording boolean NOT NULL DEFAULT false,
                keep_transcription boolean NOT NULL DEFAULT false,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_capture_recording_status_check CHECK
                    (recording_status IN ('idle','starting','active','stopping','complete','failed')),
                CONSTRAINT meet_capture_transcription_status_check CHECK
                    (transcription_status IN ('idle','starting','active','stopping','complete','failed','unavailable'))
            );",
            // The language the host pinned the transcription to, or 'auto' for
            // detection. Deliberately not constrained to a fixed code list: the
            // control plane validates against the set the worker accepts, and a
            // CHECK here would have to be migrated in lockstep with the model.
            "ALTER TABLE public.meet_capture_states
                ADD COLUMN IF NOT EXISTS transcription_language text NOT NULL DEFAULT 'auto';",
            // Retired: recording no longer starts or stops a transcription, so
            // nothing writes these. Kept so existing rows and any older release
            // rolled back to still satisfy their NOT NULL.
            "ALTER TABLE public.meet_capture_states
                ADD COLUMN IF NOT EXISTS transcription_started_by_recording boolean NOT NULL DEFAULT false;",
            "ALTER TABLE public.meet_capture_states
                ADD COLUMN IF NOT EXISTS keep_transcription boolean NOT NULL DEFAULT false;",
            "CREATE INDEX IF NOT EXISTS meet_capture_states_domain_status_idx
                ON public.meet_capture_states (domain_uuid, recording_status, transcription_status);",
            "CREATE TABLE IF NOT EXISTS public.meet_capture_actions (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                actor_ref text NOT NULL,
                client_action_id uuid NOT NULL,
                kind text NOT NULL,
                request_hash char(64) NOT NULL,
                resulting_version bigint NOT NULL,
                result jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_capture_actions_kind_check CHECK
                    (kind IN ('recording_start','recording_stop','transcription_start','transcription_stop','acknowledge'))
            );",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_capture_actions_idempotency_uk
                ON public.meet_capture_actions (domain_uuid, meeting_id, actor_ref, client_action_id);",
            "CREATE INDEX IF NOT EXISTS meet_capture_actions_domain_meeting_idx
                ON public.meet_capture_actions (domain_uuid, meeting_id, created_at DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_chat_messages (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                participant_ref text,
                sender_name text NOT NULL,
                body text NOT NULL DEFAULT '',
                files jsonb NOT NULL DEFAULT '[]'::jsonb,
                sent_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE INDEX IF NOT EXISTS meet_chat_messages_meeting_sent_idx
                ON public.meet_chat_messages (meeting_id, sent_at);",

            "CREATE SEQUENCE IF NOT EXISTS public.meet_chat_event_seq;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS client_message_id uuid;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS request_hash char(64);",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS reply_to_id uuid
                    REFERENCES public.meet_chat_messages(id) ON DELETE SET NULL;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS created_sequence bigint;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS event_sequence bigint;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 1;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS edited_at timestamptz;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS deleted_at timestamptz;",
            "ALTER TABLE public.meet_chat_messages
                ADD COLUMN IF NOT EXISTS deleted_by_ref text;",
            "UPDATE public.meet_chat_messages
                SET created_sequence = nextval('public.meet_chat_event_seq')
                WHERE created_sequence IS NULL;",
            "UPDATE public.meet_chat_messages
                SET event_sequence = created_sequence
                WHERE event_sequence IS NULL;",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_chat_messages_idempotency_uk
                ON public.meet_chat_messages (meeting_id, participant_ref, client_message_id)
                WHERE client_message_id IS NOT NULL;",
            "CREATE INDEX IF NOT EXISTS meet_chat_messages_domain_created_seq_idx
                ON public.meet_chat_messages (domain_uuid, meeting_id, created_sequence DESC);",
            "CREATE INDEX IF NOT EXISTS meet_chat_messages_domain_event_seq_idx
                ON public.meet_chat_messages (domain_uuid, meeting_id, event_sequence);",

            "CREATE TABLE IF NOT EXISTS public.meet_attachments (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                stored_name text NOT NULL,
                original_name text NOT NULL,
                mime text NOT NULL,
                bytes integer NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE INDEX IF NOT EXISTS meet_attachments_meeting_idx
                ON public.meet_attachments (meeting_id);",

            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS message_id uuid
                    REFERENCES public.meet_chat_messages(id) ON DELETE CASCADE;",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS uploader_ref text;",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS storage_path text;",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS sha256 char(64);",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS declared_mime text;",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS detected_mime text;",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'ready';",
            "ALTER TABLE public.meet_attachments
                ADD COLUMN IF NOT EXISTS expires_at timestamptz;",
            "ALTER TABLE public.meet_attachments
                ALTER COLUMN bytes TYPE bigint;",
            "CREATE INDEX IF NOT EXISTS meet_attachments_domain_message_idx
                ON public.meet_attachments (domain_uuid, meeting_id, message_id);",
            "CREATE INDEX IF NOT EXISTS meet_attachments_orphan_expiry_idx
                ON public.meet_attachments (expires_at)
                WHERE message_id IS NULL AND status = 'ready';",

            "DO \$\$ BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'meet_attachments_status_check'
                ) THEN
                    ALTER TABLE public.meet_attachments
                        ADD CONSTRAINT meet_attachments_status_check
                        CHECK (status IN ('quarantine','scanning','ready','attached','revoked','rejected'));
                END IF;
            END \$\$;",

            "CREATE TABLE IF NOT EXISTS public.meet_whiteboard_snapshots (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                png_path text NOT NULL,
                saved_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE INDEX IF NOT EXISTS meet_whiteboard_meeting_idx
                ON public.meet_whiteboard_snapshots (meeting_id);",

            "CREATE TABLE IF NOT EXISTS public.meet_whiteboard_boards (
                meeting_id uuid PRIMARY KEY
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                version bigint NOT NULL DEFAULT 0,
                last_sequence bigint NOT NULL DEFAULT 0,
                locked boolean NOT NULL DEFAULT false,
                locked_by_ref text,
                last_checkpoint_sequence bigint NOT NULL DEFAULT 0,
                last_checkpoint_at timestamptz,
                updated_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE INDEX IF NOT EXISTS meet_whiteboard_boards_domain_idx
                ON public.meet_whiteboard_boards (domain_uuid, meeting_id);",

            // Room-wide whiteboard presentation. Additive and defaulting to
            // false, so an older release rolled back onto this database simply
            // ignores the columns and every board reads as not presented.
            "ALTER TABLE public.meet_whiteboard_boards
                ADD COLUMN IF NOT EXISTS presenting boolean NOT NULL DEFAULT false;",
            "ALTER TABLE public.meet_whiteboard_boards
                ADD COLUMN IF NOT EXISTS presenting_by_ref text;",
            // Stored rather than resolved on read: the roster is live, and a
            // presenter who has left should still be named on the board that is
            // still up.
            "ALTER TABLE public.meet_whiteboard_boards
                ADD COLUMN IF NOT EXISTS presenting_by_name text;",

            "CREATE TABLE IF NOT EXISTS public.meet_whiteboard_batches (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                sequence bigint NOT NULL,
                version bigint NOT NULL,
                actor_ref text NOT NULL,
                client_batch_id uuid NOT NULL,
                kind text NOT NULL DEFAULT 'operations',
                operations jsonb NOT NULL DEFAULT '[]'::jsonb,
                inverse_operations jsonb NOT NULL DEFAULT '[]'::jsonb,
                target_batch_id uuid,
                undone boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_whiteboard_batches_kind_check
                    CHECK (kind IN ('operations','undo','redo','clear','lock','present'))
            );",
            // Widened for 'present'. CREATE TABLE IF NOT EXISTS leaves an
            // existing constraint alone, so an established database needs the
            // drop-and-recreate or every present action fails its INSERT.
            "ALTER TABLE public.meet_whiteboard_batches
                DROP CONSTRAINT IF EXISTS meet_whiteboard_batches_kind_check;",
            "ALTER TABLE public.meet_whiteboard_batches
                ADD CONSTRAINT meet_whiteboard_batches_kind_check
                CHECK (kind IN ('operations','undo','redo','clear','lock','present'));",

            "CREATE UNIQUE INDEX IF NOT EXISTS meet_whiteboard_batches_sequence_uk
                ON public.meet_whiteboard_batches (meeting_id, sequence);",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_whiteboard_batches_idempotency_uk
                ON public.meet_whiteboard_batches (meeting_id, actor_ref, client_batch_id);",
            "CREATE INDEX IF NOT EXISTS meet_whiteboard_batches_actor_idx
                ON public.meet_whiteboard_batches (domain_uuid, meeting_id, actor_ref, sequence DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_whiteboard_checkpoints (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                through_sequence bigint NOT NULL,
                version bigint NOT NULL,
                document jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_whiteboard_checkpoints_sequence_uk
                ON public.meet_whiteboard_checkpoints (meeting_id, through_sequence);",
            "CREATE INDEX IF NOT EXISTS meet_whiteboard_checkpoints_domain_idx
                ON public.meet_whiteboard_checkpoints (domain_uuid, meeting_id, through_sequence DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_file_assets (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                attachment_id uuid NOT NULL UNIQUE
                    REFERENCES public.meet_attachments(id) ON DELETE CASCADE,
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                purpose text NOT NULL DEFAULT 'preview',
                conversion_status text NOT NULL DEFAULT 'queued',
                page_count integer NOT NULL DEFAULT 0,
                failure_code text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_file_assets_purpose_check
                    CHECK (purpose IN ('preview','presentation')),
                CONSTRAINT meet_file_assets_status_check
                    CHECK (conversion_status IN ('queued','processing','ready','failed')),
                CONSTRAINT meet_file_assets_page_count_check
                    CHECK (page_count >= 0 AND page_count <= 200)
            );",
            "CREATE INDEX IF NOT EXISTS meet_file_assets_domain_meeting_idx
                ON public.meet_file_assets (domain_uuid, meeting_id, created_at DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_file_pages (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                asset_id uuid NOT NULL
                    REFERENCES public.meet_file_assets(id) ON DELETE CASCADE,
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                page_number integer NOT NULL,
                storage_path text NOT NULL,
                mime text NOT NULL DEFAULT 'image/webp',
                bytes bigint NOT NULL,
                sha256 char(64) NOT NULL,
                width integer NOT NULL,
                height integer NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_file_pages_number_check CHECK (page_number BETWEEN 1 AND 200),
                CONSTRAINT meet_file_pages_bytes_check CHECK (bytes > 0)
            );",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_file_pages_asset_number_uk
                ON public.meet_file_pages (asset_id, page_number);",
            "CREATE INDEX IF NOT EXISTS meet_file_pages_domain_meeting_idx
                ON public.meet_file_pages (domain_uuid, meeting_id, asset_id, page_number);",

            "CREATE TABLE IF NOT EXISTS public.meet_file_presentations (
                meeting_id uuid PRIMARY KEY
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                version bigint NOT NULL DEFAULT 0,
                last_sequence bigint NOT NULL DEFAULT 0,
                status text NOT NULL DEFAULT 'idle',
                active_asset_id uuid
                    REFERENCES public.meet_file_assets(id) ON DELETE SET NULL,
                presenter_ref text,
                presenter_name text,
                page_number integer,
                started_at timestamptz,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_file_presentations_status_check
                    CHECK (status IN ('idle','active')),
                CONSTRAINT meet_file_presentations_page_check
                    CHECK (page_number IS NULL OR page_number BETWEEN 1 AND 200)
            );",
            "CREATE INDEX IF NOT EXISTS meet_file_presentations_domain_idx
                ON public.meet_file_presentations (domain_uuid, meeting_id);",

            "CREATE TABLE IF NOT EXISTS public.meet_file_presentation_actions (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                sequence bigint NOT NULL,
                version bigint NOT NULL,
                actor_ref text NOT NULL,
                client_action_id uuid NOT NULL,
                kind text NOT NULL,
                asset_id uuid REFERENCES public.meet_file_assets(id) ON DELETE SET NULL,
                page_number integer,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_file_presentation_actions_kind_check
                    CHECK (kind IN ('start','page','stop'))
            );",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_file_presentation_actions_sequence_uk
                ON public.meet_file_presentation_actions (meeting_id, sequence);",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_file_presentation_actions_idempotency_uk
                ON public.meet_file_presentation_actions (meeting_id, actor_ref, client_action_id);",
            "CREATE INDEX IF NOT EXISTS meet_file_presentation_actions_domain_idx
                ON public.meet_file_presentation_actions (domain_uuid, meeting_id, sequence DESC);",

            // Who may draw on the presented file. Additive and defaulting to
            // the pre-existing behaviour: on a database that predates this,
            // and on an older release rolled back onto this one, a
            // presentation reads as presenter-only, which is what it was.
            "ALTER TABLE public.meet_file_presentations
                ADD COLUMN IF NOT EXISTS annotation_mode text NOT NULL DEFAULT 'presenter';",
            "ALTER TABLE public.meet_file_presentations
                DROP CONSTRAINT IF EXISTS meet_file_presentations_annotation_mode_check;",
            "ALTER TABLE public.meet_file_presentations
                ADD CONSTRAINT meet_file_presentations_annotation_mode_check
                CHECK (annotation_mode IN ('presenter','everyone'));",

            // Widened for the annotation-mode action. CREATE TABLE IF NOT
            // EXISTS leaves an existing constraint alone, so an established
            // database needs the drop-and-recreate or every mode change fails
            // its INSERT -- the same trap the whiteboard 'present' kind hit.
            "ALTER TABLE public.meet_file_presentation_actions
                DROP CONSTRAINT IF EXISTS meet_file_presentation_actions_kind_check;",
            "ALTER TABLE public.meet_file_presentation_actions
                ADD CONSTRAINT meet_file_presentation_actions_kind_check
                CHECK (kind IN ('start','page','stop','mode'));",

            // Ink drawn on a presented page, one row per (meeting, asset,
            // page). The document is materialised on every write rather than
            // folded from history the way the whiteboard is: a page holds far
            // less than a board, so a read is one row and the checkpoint
            // machinery that exists to bound that fold is not needed here.
            "CREATE TABLE IF NOT EXISTS public.meet_file_presentation_annotations (
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                asset_id uuid NOT NULL
                    REFERENCES public.meet_file_assets(id) ON DELETE CASCADE,
                page_number integer NOT NULL,
                version bigint NOT NULL DEFAULT 0,
                last_sequence bigint NOT NULL DEFAULT 0,
                document jsonb NOT NULL DEFAULT '{\"elements\":{}}'::jsonb,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (meeting_id, asset_id, page_number),
                CONSTRAINT meet_file_presentation_annotations_page_check
                    CHECK (page_number BETWEEN 1 AND 200)
            );",
            "CREATE INDEX IF NOT EXISTS meet_file_presentation_annotations_domain_idx
                ON public.meet_file_presentation_annotations (domain_uuid, meeting_id);",

            "CREATE TABLE IF NOT EXISTS public.meet_file_presentation_annotation_batches (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                asset_id uuid NOT NULL,
                page_number integer NOT NULL,
                sequence bigint NOT NULL,
                version bigint NOT NULL,
                actor_ref text NOT NULL,
                client_batch_id uuid NOT NULL,
                kind text NOT NULL DEFAULT 'operations',
                operations jsonb NOT NULL DEFAULT '[]'::jsonb,
                inverse_operations jsonb NOT NULL DEFAULT '[]'::jsonb,
                target_batch_id uuid,
                undone boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_file_presentation_annotation_batches_kind_check
                    CHECK (kind IN ('operations','undo','redo','clear'))
            );",
            // Sequence is per PAGE, not per meeting: two people annotating two
            // pages of the same deck must not collide on a meeting-wide
            // counter, and each page carries its own version.
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_file_presentation_annotation_batches_sequence_uk
                ON public.meet_file_presentation_annotation_batches (meeting_id, asset_id, page_number, sequence);",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_file_presentation_annotation_batches_idempotency_uk
                ON public.meet_file_presentation_annotation_batches (meeting_id, actor_ref, client_batch_id);",
            "CREATE INDEX IF NOT EXISTS meet_file_presentation_annotation_batches_actor_idx
                ON public.meet_file_presentation_annotation_batches
                   (meeting_id, asset_id, page_number, actor_ref, sequence DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_feedback (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                rating integer,
                comment text,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_feedback_rating_check
                    CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5))
            );",
            "CREATE INDEX IF NOT EXISTS meet_feedback_meeting_idx
                ON public.meet_feedback (meeting_id);",
            "ALTER TABLE public.meet_feedback ADD COLUMN IF NOT EXISTS participant_ref text;",
            "ALTER TABLE public.meet_feedback ADD COLUMN IF NOT EXISTS participant_name text;",
            "ALTER TABLE public.meet_feedback ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now();",
            "CREATE UNIQUE INDEX IF NOT EXISTS meet_feedback_participant_uk
                ON public.meet_feedback (domain_uuid, meeting_id, participant_ref)
                WHERE participant_ref IS NOT NULL;",
            "CREATE INDEX IF NOT EXISTS meet_feedback_domain_meeting_idx
                ON public.meet_feedback (domain_uuid, meeting_id, created_at DESC);",

            "CREATE TABLE IF NOT EXISTS public.meet_invitations (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                email text NOT NULL,
                sent_at timestamptz NOT NULL DEFAULT now()
            );",
            "CREATE INDEX IF NOT EXISTS meet_invitations_meeting_idx
                ON public.meet_invitations (meeting_id);",

            "CREATE TABLE IF NOT EXISTS public.meet_audit_events (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                meeting_id uuid NOT NULL
                    REFERENCES public.meet_meetings(id) ON DELETE CASCADE,
                domain_uuid uuid NOT NULL,
                actor_key text NOT NULL,
                action text NOT NULL,
                target_ref text,
                details jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT meet_audit_events_action_check
                    CHECK (action IN ('admit','decline','kick','end'))
            );",
            "CREATE INDEX IF NOT EXISTS meet_audit_events_meeting_created_idx
                ON public.meet_audit_events (meeting_id, created_at DESC);",
            "CREATE INDEX IF NOT EXISTS meet_audit_events_domain_created_idx
                ON public.meet_audit_events (domain_uuid, created_at DESC);",

            // The four media_* actions were allowed by recorder.js from 2026-08-06 but
            // never added here, so every privacy moderation event failed its INSERT and
            // was swallowed as a warning, leaving that feature's audit trail empty.
            "ALTER TABLE public.meet_audit_events
                DROP CONSTRAINT IF EXISTS meet_audit_events_action_check;",
            "ALTER TABLE public.meet_audit_events
                ADD CONSTRAINT meet_audit_events_action_check
                CHECK (action IN (
                    'admit','decline','kick','promote','demote','end','lower_hand',
                    'chat_delete','whiteboard_clear','whiteboard_lock','whiteboard_present',
                    'participant_media_mute','participant_media_request','participant_media_response',
                    'file_presentation_stop'
                    ,'recording_start','recording_stop','transcription_start','transcription_stop',
                    'capture_acknowledge','capture_failure',
                    'media_policy_update','media_grant','media_access_request','media_access_response',
                    'auto_admit'
                ));",
            "DO \$\$
             BEGIN
               IF to_regclass('public.q_storage_retention') IS NOT NULL
                  AND to_regclass('public.v_domains') IS NOT NULL THEN
                 INSERT INTO public.q_storage_retention
                   (domain_uuid, category, scope, enabled, retention_days, archive, insert_date, update_date)
                 SELECT domain_uuid, 'meet_collaboration', 'tenant', 1, 30, 0, now(), now()
                   FROM public.v_domains
                 ON CONFLICT (domain_uuid, category) DO NOTHING;
               END IF;
             END \$\$;",
        ];
    }

    /** Tables this task owns, for reporting. */
    private function tables(): array
    {
        return [
            'meet_meetings',
            'meet_participants',
            'meet_recordings',
            'meet_transcripts',
            'meet_summaries',
            'meet_capture_states',
            'meet_capture_actions',
            'meet_chat_messages',
            'meet_attachments',
            'meet_whiteboard_snapshots',
            'meet_whiteboard_boards',
            'meet_whiteboard_batches',
            'meet_whiteboard_checkpoints',
            'meet_file_assets',
            'meet_file_pages',
            'meet_file_presentations',
            'meet_file_presentation_actions',
            'meet_file_presentation_annotations',
            'meet_file_presentation_annotation_batches',
            'meet_feedback',
            'meet_invitations',
            'meet_audit_events',
        ];
    }

    public function mainAction(): void
    {
        echo "usage: cli.php meetSchema up | check" . PHP_EOL;
    }

    /** Create or update the schema. Safe to re-run. */
    public function upAction(): void
    {
        $applied = 0;
        $failed = 0;
        foreach ($this->statements() as $sql) {
            try {
                $this->db->execute($sql);
                $applied++;
            } catch (\Throwable $e) {
                $failed++;
                echo "FAILED: " . trim(substr($sql, 0, 70)) . "..." . PHP_EOL;
                echo "        " . $e->getMessage() . PHP_EOL;
            }
        }
        echo "meet schema: {$applied} statement(s) applied, {$failed} failed" . PHP_EOL;
        $this->checkAction();
        if ($failed > 0) {
            throw new \RuntimeException("RingQ Meet schema migration failed for {$failed} statement(s).");
        }
    }

    /** Report which tables exist. Changes nothing. */
    public function checkAction(): void
    {
        $failed = 0;
        foreach ($this->tables() as $table) {
            try {
                $row = $this->db->fetchOne(
                    "SELECT to_regclass(:t) AS reg",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ["t" => "public." . $table]
                );
                $exists = !empty($row["reg"]);
                echo ($exists ? "  ok      " : "  MISSING ") . $table . PHP_EOL;
                if (!$exists) {
                    $failed++;
                }
            } catch (\Throwable $e) {
                echo "  ERROR   " . $table . " — " . $e->getMessage() . PHP_EOL;
                $failed++;
            }
        }
        $requiredColumns = [
            'meet_meetings' => ['end_reason', 'ended_by_ref', 'review_nonce'],
            'meet_recordings' => [
                'client_action_id', 'request_hash', 'version', 'storage_path',
                'original_name', 'sha256', 'bytes', 'mime', 'failure_code', 'created_at'
            ],
            'meet_transcripts' => [
                'segment_id', 'participant_ref', 'participant_name', 'track_sid',
                'sequence', 'start_ms', 'end_ms', 'language', 'confidence', 'is_final', 'created_at'
            ],
            'meet_summaries' => [
                'status', 'structured', 'plain_text', 'provider', 'model',
                'version', 'failure_code', 'attempts', 'updated_at'
            ],
            'meet_capture_states' => [
                'version', 'recording_id', 'recording_status', 'transcription_status',
                'transcription_started_by_recording', 'keep_transcription', 'updated_at'
            ],
            'meet_capture_actions' => [
                'actor_ref', 'client_action_id', 'kind', 'request_hash',
                'resulting_version', 'result', 'created_at'
            ],
            'meet_feedback' => ['participant_ref', 'participant_name', 'updated_at'],
            'meet_chat_messages' => [
                'client_message_id', 'request_hash', 'reply_to_id',
                'created_sequence', 'event_sequence', 'version',
                'edited_at', 'deleted_at', 'deleted_by_ref',
            ],
            'meet_attachments' => [
                'message_id', 'uploader_ref', 'storage_path', 'sha256',
                'declared_mime', 'detected_mime', 'status', 'expires_at',
            ],
            'meet_whiteboard_boards' => [
                'version', 'last_sequence', 'locked', 'last_checkpoint_sequence',
            ],
            'meet_file_assets' => [
                'attachment_id', 'purpose', 'conversion_status', 'page_count', 'failure_code',
            ],
            'meet_file_pages' => [
                'asset_id', 'meeting_id', 'domain_uuid', 'page_number',
                'storage_path', 'mime', 'bytes', 'sha256', 'width', 'height',
            ],
            'meet_file_presentations' => [
                'version', 'last_sequence', 'status', 'active_asset_id',
                'presenter_ref', 'page_number',
            ],
            'meet_file_presentation_actions' => [
                'sequence', 'version', 'actor_ref', 'client_action_id',
                'kind', 'asset_id', 'page_number',
            ],
        ];
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    $row = $this->db->fetchOne(
                        "SELECT EXISTS (
                           SELECT 1 FROM information_schema.columns
                            WHERE table_schema = 'public'
                              AND table_name = :table
                              AND column_name = :column
                         ) AS present",
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        ['table' => $table, 'column' => $column]
                    );
                    if (empty($row['present'])) {
                        echo "  MISSING {$table}.{$column}" . PHP_EOL;
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    echo "  ERROR   {$table}.{$column} — " . $e->getMessage() . PHP_EOL;
                    $failed++;
                }
            }
        }
        $requiredIndexes = [
            'meet_recordings_idempotency_uk',
            'meet_recordings_domain_status_idx',
            'meet_transcripts_segment_uk',
            'meet_transcripts_domain_sequence_idx',
            'meet_summaries_domain_meeting_uk',
            'meet_capture_states_domain_status_idx',
            'meet_capture_actions_idempotency_uk',
            'meet_capture_actions_domain_meeting_idx',
            'meet_feedback_participant_uk',
            'meet_feedback_domain_meeting_idx',
            'meet_chat_messages_idempotency_uk',
            'meet_chat_messages_domain_event_seq_idx',
            'meet_attachments_domain_message_idx',
            'meet_attachments_orphan_expiry_idx',
            'meet_whiteboard_batches_sequence_uk',
            'meet_whiteboard_batches_idempotency_uk',
            'meet_whiteboard_checkpoints_domain_idx',
            'meet_file_assets_domain_meeting_idx',
            'meet_file_pages_asset_number_uk',
            'meet_file_pages_domain_meeting_idx',
            'meet_file_presentations_domain_idx',
            'meet_file_presentation_actions_sequence_uk',
            'meet_file_presentation_actions_idempotency_uk',
            'meet_file_presentation_actions_domain_idx',
        ];
        foreach ($requiredIndexes as $index) {
            try {
                $row = $this->db->fetchOne(
                    "SELECT EXISTS (
                       SELECT 1 FROM pg_indexes
                        WHERE schemaname = 'public' AND indexname = :index
                     ) AS present",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['index' => $index]
                );
                if (empty($row['present'])) {
                    echo "  MISSING index {$index}" . PHP_EOL;
                    $failed++;
                }
            } catch (\Throwable $e) {
                echo "  ERROR   index {$index} — " . $e->getMessage() . PHP_EOL;
                $failed++;
            }
        }
        if ($failed > 0) {
            throw new \RuntimeException("RingQ Meet schema check failed for {$failed} table(s).");
        }
    }
}
