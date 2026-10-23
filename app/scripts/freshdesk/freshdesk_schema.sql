-- freshdesk CRM integration: complete table structure (PostgreSQL, database ringq)
-- Taken from sgringq100 on 2026-10-23 with pg_dump --schema-only --no-owner --no-privileges.
-- Structure only: no rows, no credentials. Identical to running this folder's migration scripts in order.
-- For a new server, prefer the migration scripts (they are safe to run again); this file documents the result.

--
-- PostgreSQL database dump
--


-- Dumped from database version 18.4 (Debian 18.4-1.pgdg12+1)
-- Dumped by pg_dump version 18.4 (Debian 18.4-1.pgdg12+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: q_freshdesk_call_journals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.q_freshdesk_call_journals (
    journal_uuid uuid NOT NULL,
    domain_uuid uuid NOT NULL,
    local_call_id character varying(64) NOT NULL,
    user_uuid character varying(64),
    agent_extension character varying(32),
    agent_name character varying(120),
    call_direction character varying(8) NOT NULL,
    call_origin character varying(16) DEFAULT 'dialer'::character varying NOT NULL,
    caller_number character varying(32),
    destination_number character varying(32),
    call_status character varying(12) DEFAULT 'ringing'::character varying NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    answered_at timestamp with time zone,
    ended_at timestamp with time zone,
    duration integer DEFAULT 0 NOT NULL,
    talk_duration integer DEFAULT 0 NOT NULL,
    notes text,
    disposition character varying(40),
    notes_dirty boolean DEFAULT false NOT NULL,
    notes_note_id character varying(32),
    freshdesk_contact_id character varying(32),
    freshdesk_ticket_id character varying(32),
    create_attempted_at timestamp with time zone,
    xml_cdr_uuid uuid,
    recording_status character varying(12) DEFAULT 'none'::character varying NOT NULL,
    recording_note_id character varying(32),
    recording_attempted_at timestamp with time zone,
    sync_status character varying(10) DEFAULT 'pending'::character varying NOT NULL,
    retry_count integer DEFAULT 0 NOT NULL,
    next_attempt_at timestamp with time zone,
    sync_locked_at timestamp with time zone,
    synced_at timestamp with time zone,
    last_error character varying(500),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT q_freshdesk_call_journals_call_direction_check CHECK (((call_direction)::text = ANY ((ARRAY['inbound'::character varying, 'outbound'::character varying])::text[]))),
    CONSTRAINT q_freshdesk_call_journals_call_origin_check CHECK (((call_origin)::text = ANY ((ARRAY['dialer'::character varying, 'freshdesk_cti'::character varying])::text[]))),
    CONSTRAINT q_freshdesk_call_journals_call_status_check CHECK (((call_status)::text = ANY ((ARRAY['ringing'::character varying, 'answered'::character varying, 'completed'::character varying, 'missed'::character varying, 'rejected'::character varying, 'busy'::character varying, 'no_answer'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT q_freshdesk_call_journals_recording_status_check CHECK (((recording_status)::text = ANY ((ARRAY['none'::character varying, 'waiting'::character varying, 'linked'::character varying, 'uploaded'::character varying, 'link_only'::character varying, 'unavailable'::character varying])::text[]))),
    CONSTRAINT q_freshdesk_call_journals_sync_status_check CHECK (((sync_status)::text = ANY ((ARRAY['pending'::character varying, 'synced'::character varying, 'failed'::character varying, 'skipped'::character varying])::text[])))
);


--
-- Name: q_freshdesk_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.q_freshdesk_config (
    domain_uuid uuid NOT NULL,
    enabled boolean DEFAULT false NOT NULL,
    fd_domain character varying(120),
    api_key_enc text,
    key_last4 character varying(8),
    fd_agent_id character varying(32),
    fd_agent_name character varying(120),
    fd_account_name character varying(120),
    ticket_field_warnings jsonb,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    connection_status character varying(20) DEFAULT 'not_configured'::character varying NOT NULL,
    last_tested_at timestamp with time zone,
    last_sync_at timestamp with time zone,
    last_error character varying(500),
    link_salt character varying(32) NOT NULL,
    updated_by character varying(64),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT q_freshdesk_config_connection_status_check CHECK (((connection_status)::text = ANY ((ARRAY['not_configured'::character varying, 'connected'::character varying, 'error'::character varying, 'disconnected'::character varying])::text[])))
);


--
-- Name: q_freshdesk_cti_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.q_freshdesk_cti_tokens (
    token_hash character(64) NOT NULL,
    domain_uuid uuid NOT NULL,
    user_uuid character varying(64) NOT NULL,
    extension character varying(32) NOT NULL,
    agent_name character varying(120),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    last_used_at timestamp with time zone,
    expires_at timestamp with time zone NOT NULL,
    revoked_at timestamp with time zone
);


--
-- Name: q_freshdesk_call_journals q_freshdesk_call_journals_call_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.q_freshdesk_call_journals
    ADD CONSTRAINT q_freshdesk_call_journals_call_uq UNIQUE (domain_uuid, local_call_id);


--
-- Name: q_freshdesk_call_journals q_freshdesk_call_journals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.q_freshdesk_call_journals
    ADD CONSTRAINT q_freshdesk_call_journals_pkey PRIMARY KEY (journal_uuid);


--
-- Name: q_freshdesk_config q_freshdesk_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.q_freshdesk_config
    ADD CONSTRAINT q_freshdesk_config_pkey PRIMARY KEY (domain_uuid);


--
-- Name: q_freshdesk_cti_tokens q_freshdesk_cti_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.q_freshdesk_cti_tokens
    ADD CONSTRAINT q_freshdesk_cti_tokens_pkey PRIMARY KEY (token_hash);


--
-- Name: q_freshdesk_call_journals_due_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX q_freshdesk_call_journals_due_idx ON public.q_freshdesk_call_journals USING btree (next_attempt_at) WHERE (next_attempt_at IS NOT NULL);


--
-- Name: q_freshdesk_call_journals_started_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX q_freshdesk_call_journals_started_idx ON public.q_freshdesk_call_journals USING btree (domain_uuid, started_at DESC);


--
-- Name: q_freshdesk_call_journals_ticket_uq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX q_freshdesk_call_journals_ticket_uq ON public.q_freshdesk_call_journals USING btree (domain_uuid, freshdesk_ticket_id) WHERE (freshdesk_ticket_id IS NOT NULL);


--
-- Name: q_freshdesk_call_journals_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX q_freshdesk_call_journals_user_idx ON public.q_freshdesk_call_journals USING btree (domain_uuid, user_uuid, started_at DESC);


--
-- Name: q_freshdesk_cti_tokens_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX q_freshdesk_cti_tokens_user_idx ON public.q_freshdesk_cti_tokens USING btree (domain_uuid, user_uuid);


--
-- PostgreSQL database dump complete
--


