<?php

/**
 * Click2Talk / RingQ Talk resolution.
 *
 * A Click2Talk link is `https://{tenant}/click2talk/{click2talk_uuid}` and
 * nothing else. This class turns that id into a destination and, separately,
 * into the guest SIP identity used to place the call.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It never infers a destination from the shape of a string. The old path read
 * a value containing "-" as a queue, because a uuid has hyphens, and anything
 * else as an agent; `destination_type` is now a stored column. A guard forbids
 * that inference from reappearing here.
 *
 * It is also independent of `RegisterController::calllogAction`. That endpoint
 * stays exactly as it is for its existing callers (call logging, Salesforce and
 * Zendesk journaling, the dialer); nothing here calls it and nothing here is
 * required by it.
 *
 * TENANCY
 *
 * The tenant is resolved from the REQUEST HOST, and the row must belong to it.
 * An id alone is never sufficient, or the id becomes an oracle for other
 * tenants' records. Every refusal — unknown, malformed, disabled, wrong tenant
 * — returns null and is reported identically, so a caller cannot learn which
 * ids exist.
 */
class Click2Talk
{
    /**
     * The shared per-tenant guest SIP account.
     *
     * A Click2Talk visitor does not have an account, so the widget registers as
     * this one and dials the destination. It is identified by `number_alias`,
     * not by `extension`: directory.lua emits
     * `<user id="{number_alias}" number-alias="{extension}">`, so a REGISTER
     * using the extension value is answered 403 by `sip-forbid-register` —
     * which reads exactly like a wrong password.
     */
    const GUEST_NUMBER_ALIAS = '100000';

    /** Refusal codes. All of them are reported to the caller identically. */
    const REFUSED = 'not_found';

    /**
     * True when the id is a well-formed uuid.
     *
     * Checked before the database is touched: an id arrives straight off the
     * URL, and a malformed one should cost a string comparison, not a query.
     */
    public static function isValidId($id): bool
    {
        return is_string($id)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /**
     * The tenant that owns the request, from the host header.
     *
     * @return \VDomains|null
     */
    public static function domainForHost($host)
    {
        $host = is_string($host) ? trim(explode(':', $host)[0]) : '';
        if ($host === '') {
            return null;
        }
        return VDomains::findFirst([
            'conditions' => 'domain_name = :domain_name:',
            'bind'       => ['domain_name' => $host],
            'bindTypes'  => [\Phalcon\Db\Column::BIND_PARAM_STR],
        ]);
    }

    /**
     * The link row, scoped to the tenant.
     *
     * @return \QClick2talkLinks|null
     */
    public static function link($id, $domainUuid)
    {
        if (!self::isValidId($id) || empty($domainUuid)) {
            return null;
        }
        $row = QClick2talkLinks::findFirst([
            'conditions' => 'click2talk_uuid = :id: AND domain_uuid = :domain_uuid:',
            'bind'       => ['id' => $id, 'domain_uuid' => $domainUuid],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$row) {
            return null;
        }
        // A disabled link is refused exactly like a missing one.
        if ((int) $row->enabled !== 1) {
            return null;
        }
        return $row;
    }

    /**
     * Resolve a LEGACY identifier onto the same link row.
     *
     * Snippets already pasted into customer websites emit
     * `?username={v_users.username | call_center_queue_uuid}`, and those pages
     * cannot be updated retroactively. Rather than keeping a second resolution
     * path alive, the old identifier is mapped to its destination and then to
     * that destination's row, so everything downstream is the new path.
     *
     * A queue's legacy identifier is itself a uuid, so this is only ever
     * reached after a `click2talk_uuid` lookup has already missed. A minted
     * link id colliding with a queue uuid is not a practical concern, and the
     * ordering makes the new form win regardless.
     *
     * @return \QClick2talkLinks|null
     */
    public static function legacyLink($identifier, $vDomain)
    {
        if (!$vDomain || !is_string($identifier) || trim($identifier) === '') {
            return null;
        }
        $identifier = trim($identifier);

        // A call centre queue, addressed by its own uuid.
        //
        // GATED ON THE UUID SHAPE, and not as an optimisation: comparing a
        // `uuid` column to an arbitrary string makes PostgreSQL raise
        // `invalid input syntax for type uuid`, which Phalcon's exception
        // handler turns into an empty 200. Every legacy AGENT identifier is a
        // 20-hex hash rather than a uuid, so without this gate the entire
        // backward-compatibility path throws before it reaches the lookup
        // that would have answered it.
        $queue = self::isValidId($identifier) ? VCallCenterQueues::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND call_center_queue_uuid = :uuid:',
            'bind'       => ['domain_uuid' => $vDomain->domain_uuid, 'uuid' => $identifier],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]) : null;
        if ($queue) {
            return self::linkForDestination(
                $vDomain->domain_uuid,
                QClick2talkLinks::TYPE_QUEUE,
                (string) $queue->queue_extension
            );
        }

        // An agent, addressed by v_users.username.
        $vUser = VUsers::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND username = :username:',
            'bind'       => ['domain_uuid' => $vDomain->domain_uuid, 'username' => $identifier],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$vUser) {
            return null;
        }
        $vExtensionUser = VExtensionUsers::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:',
            'bind'       => [
                'domain_uuid' => $vDomain->domain_uuid,
                'user_uuid'   => $vUser->user_uuid,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$vExtensionUser) {
            return null;
        }
        $vExtension = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :uuid:',
            'bind'       => [
                'domain_uuid' => $vDomain->domain_uuid,
                'uuid'        => $vExtensionUser->extension_uuid,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$vExtension) {
            return null;
        }
        // The row is keyed on `extension`, the dialable number — NOT on
        // `number_alias`, which on a real tenant holds a 20-hex public
        // identifier (1001 -> 97fe59f3499a0545236f). Looking up by the alias
        // finds nothing and every legacy agent link refuses.
        $ext = trim((string) $vExtension->extension);
        return self::linkForDestination(
            $vDomain->domain_uuid,
            QClick2talkLinks::TYPE_EXTENSION,
            $ext
        );
    }

    /**
     * The enabled link row for one destination, or null.
     *
     * @return \QClick2talkLinks|null
     */
    public static function linkForDestination($domainUuid, $type, $destinationExtension)
    {
        if (empty($domainUuid) || $destinationExtension === '') {
            return null;
        }
        $row = QClick2talkLinks::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND destination_type = :type: AND destination_extension = :ext:',
            'bind'       => [
                'domain_uuid' => $domainUuid,
                'type'        => $type,
                'ext'         => $destinationExtension,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$row || (int) $row->enabled !== 1) {
            return null;
        }
        return $row;
    }

    /**
     * Display data for a link: who the caller is about to reach.
     *
     * Carries NO credential of any kind. Rendering the page must not hand out
     * a SIP password; that is what `sipIdentity()` is for, and it is reached
     * only when a guest actually starts a call.
     *
     * @return array|null
     */
    public static function describe($di, $row, $vDomain)
    {
        if (!$row || !$vDomain) {
            return null;
        }

        $redis     = $di->get('redis');
        $qCompany  = QCompanies::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid:',
            'bind'       => ['domain_uuid' => $vDomain->domain_uuid],
            'bindTypes'  => [\Phalcon\Db\Column::BIND_PARAM_STR],
        ]);
        $companyName = $qCompany ? ucwords($qCompany->company_name) : '';

        $content = [
            'id'                  => $row->click2talk_uuid,
            'destinationType'     => $row->destination_type,
            'destination'         => $row->destination_extension,
            'askCustomerFor'      => self::askCustomerFor($row->ask_customer_for),
            'shareContactDetails' => ((int) $row->share_contact_details) === 1,
            'buttonText'          => $row->button_text ?? '',
            'buttonColor'         => $row->button_color ?? '',
            'companyName'         => $companyName,
            'destinationName'     => '',
            'fullName'            => '',
            'title'               => '',
            'email'               => '',
            'phone'               => '',
            'image'               => '',
            'linkedin'            => '',
            'bookingUrl'          => '',
        ];

        if ($row->destination_type === QClick2talkLinks::TYPE_QUEUE) {
            $queue = VCallCenterQueues::findFirst([
                'conditions' => 'domain_uuid = :domain_uuid: AND call_center_queue_uuid = :uuid:',
                'bind'       => [
                    'domain_uuid' => $vDomain->domain_uuid,
                    'uuid'        => $row->destination_uuid,
                ],
                'bindTypes'  => [
                    \Phalcon\Db\Column::BIND_PARAM_STR,
                    \Phalcon\Db\Column::BIND_PARAM_STR,
                ],
            ]);
            if (!$queue) {
                return null;
            }
            $content['destinationName'] = ucwords($queue->queue_name);
            $content['fullName']        = $content['destinationName'];
            $content['title']           = 'Queue';
            return $content;
        }

        // Extension / agent.
        $vExtension = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :uuid:',
            'bind'       => [
                'domain_uuid' => $vDomain->domain_uuid,
                'uuid'        => $row->destination_uuid,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$vExtension) {
            return null;
        }

        $vExtensionUser = VExtensionUsers::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :uuid:',
            'bind'       => [
                'domain_uuid' => $vDomain->domain_uuid,
                'uuid'        => $vExtension->extension_uuid,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        $vUser = $vExtensionUser ? VUsers::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:',
            'bind'       => [
                'domain_uuid' => $vDomain->domain_uuid,
                'user_uuid'   => $vExtensionUser->user_uuid,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]) : null;

        $qUser = $vUser ? QUsers::findFirst([
            'conditions' => 'user_uuid = :user_uuid:',
            'bind'       => ['user_uuid' => $vUser->user_uuid],
            'bindTypes'  => [\Phalcon\Db\Column::BIND_PARAM_STR],
        ]) : null;

        $first = $qUser ? trim((string) $qUser->first_name) : '';
        $last  = $qUser ? trim((string) $qUser->last_name) : '';
        if ($first !== '' && $last !== '') {
            $content['fullName']        = ucwords($first) . ' ' . ucwords($last);
            $content['destinationName'] = ucwords($first) . ' ' . strtoupper(substr($last, 0, 1)) . '.';
        } elseif ($first !== '') {
            $content['fullName'] = $content['destinationName'] = ucwords($first);
        } elseif ($last !== '') {
            $content['fullName'] = $content['destinationName'] = ucwords($last);
        } else {
            $content['fullName'] = $content['destinationName'] = $vExtension->extension;
        }

        $content['email'] = $vUser ? (string) $vUser->user_email : '';

        $behavior = QCallBehaviors::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :uuid:',
            'bind'       => [
                'domain_uuid' => $vDomain->domain_uuid,
                'uuid'        => $vExtension->extension_uuid,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        $content['phone'] = $behavior ? (string) $behavior->mobile_number : '';

        $accountcode = $vExtension->accountcode;
        // Profile uploads persist on QUsers; the upload cache may be empty.
        $content['image'] = $qUser ? trim((string) $qUser->image) : '';
        if ($content['image'] === '') {
            $content['image'] = (string) $redis->get('avatar:' . $vDomain->domain_name . ':' . $vExtension->extension);
        }
        $content['title']    = (string) $redis->get($vExtension->extension . '@' . $accountcode . '-jobTitle');
        // [profile-company] companyName is the tenant's company (Global Settings > Company) for everyone.
        $content['linkedin'] = (string) $redis->get($vExtension->extension . '@' . $accountcode . '-linkedInProfileURL');

        $linkedIn = $redis->hgetall('linkedin-profile:' . $row->destination_extension . ':' . $vDomain->domain_uuid);
        if (!empty($linkedIn)) {
            $content['linkedInImage']    = $linkedIn['picture'] ?? '';
            $content['linkedInName']     = $linkedIn['name'] ?? '';
            $content['linkedInVerified'] = $linkedIn['verified'] ?? false;
        }

        // Booking and work-hours endpoints resolve number_alias, not the
        // account username (which can be different for the same agent).
        $bookingIdentity = trim((string) $vExtension->number_alias);
        if ($bookingIdentity === '') {
            $bookingIdentity = (string) $vExtension->extension;
        }
        $content['bookingUrl'] = 'https://' . $vDomain->domain_name
            . '/bookings/?bk=' . rawurlencode($vDomain->domain_uuid . $bookingIdentity);

        return $content;
    }

    /**
     * The guest SIP identity the widget registers with.
     *
     * `username` is the guest extension's **number_alias**, taken straight from
     * the row that was found by it. The older path returned `v_users.username`
     * here and relied on a repair script forcing extension, number_alias and
     * username to agree; reading the alias directly is correct whether or not
     * that script has ever run.
     *
     * @return array|null ['username' => string, 'password' => string]
     */
    public static function sipIdentity($vDomain)
    {
        if (!$vDomain) {
            return null;
        }
        $guest = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND number_alias = :number_alias:',
            'bind'       => [
                'domain_uuid'  => $vDomain->domain_uuid,
                'number_alias' => self::GUEST_NUMBER_ALIAS,
            ],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        if (!$guest) {
            return null;
        }
        return [
            'username' => (string) $guest->number_alias,
            'password' => (string) $guest->password,
        ];
    }

    /**
     * Hand the caller's details to the dialplan for this destination.
     *
     * Same key the existing path writes, so FreeSWITCH needs no change.
     */
    public static function publishCaller($di, $vDomain, $destination, $callerName, $callerEmail)
    {
        $di->get('redis')->hmset(
            'click2call-' . $destination . '-' . $vDomain->domain_name,
            ['callerName' => $callerName, 'callerEmail' => $callerEmail]
        );
    }

    /**
     * Normalise the stored "ask customer for" value.
     *
     * Anything unrecognised means name and email, matching what the rest of the
     * product already does with an absent value.
     */
    public static function askCustomerFor($raw): string
    {
        $value = is_null($raw) ? '' : trim((string) $raw);
        return in_array($value, ['1', '2', '3', '4'], true) ? $value : '1';
    }
}
