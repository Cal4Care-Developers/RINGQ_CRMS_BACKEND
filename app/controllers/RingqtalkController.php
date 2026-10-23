<?php

use Phalcon\Mvc\View;

require_once __DIR__ . '/../library/Click2Talk.php';

/**
 * The RingQ Talk (Click2Talk) API.
 *
 * A Click2Talk link is `https://{tenant}/click2talk/{id}` and carries nothing
 * else. These two actions turn that id into, first, what to render, and then —
 * only when a visitor actually starts a call — the SIP identity to place it
 * with.
 *
 * INDEPENDENT OF calllogAction, ON PURPOSE.
 *
 * `RegisterController::calllogAction` stays exactly as it is for its existing
 * callers (call logging, Salesforce and Zendesk journaling, the dialer).
 * Nothing here calls it, requires it, or is required by it. That endpoint also
 * still answers the legacy `?username=&type=&share=` links already pasted into
 * customer websites, which is why it is not being retired.
 *
 * WHY THERE ARE TWO ACTIONS RATHER THAN ONE.
 *
 * The older path returned display data and the SIP password from one call, so
 * merely rendering the page handed out a credential to anyone who opened the
 * link. `config` carries no credential; `session` issues one, and only after a
 * visitor has completed the form.
 *
 * REFUSALS ARE UNIFORM.
 *
 * Unknown id, malformed id, disabled link, another tenant's link and a
 * destination that has since been deleted all answer identically. Telling them
 * apart would turn the id into an oracle for which links exist.
 */
class RingqtalkController extends ControllerBase
{
    /** One message for every refusal. See the class comment. */
    const REFUSAL = 'This Click2Talk link is not available.';

    /**
     * GET /ringqtalk/config/{id}
     *
     * What to draw. Never a credential.
     */
    public function configAction($id = '')
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setHeader('Cache-Control', 'no-store');

        if (!$this->request->isGet()) {
            return $this->refuse();
        }

        $resolved = $this->resolveLink($id);
        if ($resolved === null) {
            return $this->refuse();
        }

        return $this->succeed($resolved['content']);
    }

    /**
     * POST /ringqtalk/session/{id}
     *
     * The visitor filled the form and wants to call. Returns the guest SIP
     * identity plus the destination to dial, in the field names the guest
     * client already reads.
     */
    public function sessionAction($id = '')
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setHeader('Cache-Control', 'no-store');

        if (!$this->request->isPost()) {
            return $this->refuse();
        }

        $resolved = $this->resolveLink($id);
        if ($resolved === null) {
            return $this->refuse();
        }

        $identity = Click2Talk::sipIdentity($resolved['domain']);
        if ($identity === null) {
            // The tenant has no Click2Talk guest account provisioned. Reported
            // as its own condition because, unlike a bad id, this is a server
            // misconfiguration an operator can fix.
            return $this->fail('Click2Talk is not configured on this server.');
        }

        $callerName  = (string) $this->request->getPost('caller_name', 'string', '');
        $callerEmail = (string) $this->request->getPost('caller_email', 'string', '');

        Click2Talk::publishCaller(
            $this->getDI(),
            $resolved['domain'],
            $resolved['content']['destination'],
            $callerName,
            $callerEmail
        );

        $content = $resolved['content'];
        // `access2` is the guest extension's number_alias, which is the only
        // value a Click2Talk guest can REGISTER as; the extension value is
        // answered 403 by sip-forbid-register.
        $content['access']  = $identity['password'];
        $content['access2'] = $identity['username'];

        return $this->succeed($content);
    }

    /**
     * POST /ringqtalk/link
     *
     * Admin-side: the id for a destination, created on first ask.
     *
     * The backfill in `queries.sql` gives every existing extension and queue a
     * row, so this normally just reads one. It creates on miss so a destination
     * added *after* that migration still gets a link the first time an admin
     * opens its RingQ Talk panel, rather than showing an empty box until the
     * next update runs.
     *
     * SESSION-GUARDED IN THE BODY, deliberately. The ACL has to list this
     * controller in the unauthenticated block as well, because `config` and
     * `session` are reached by guests who have no session; that means the ACL
     * cannot be what protects this action, so it checks for itself. The
     * destination is also scoped to the caller's own domain, never to a
     * domain named in the request.
     */
    public function linkAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setHeader('Cache-Control', 'no-store');

        $session = $this->session->get('auth');
        if (!$session) {
            return $this->fail('Unauthorized request. (Code AL1000)');
        }
        if (!$this->request->isPost()) {
            return $this->fail('Unsupported request.');
        }

        $type = (string) $this->request->getPost('destination_type', 'string', '');
        $ext  = trim((string) $this->request->getPost('destination_extension', 'string', ''));

        if (!in_array($type, [QClick2talkLinks::TYPE_EXTENSION, QClick2talkLinks::TYPE_QUEUE], true)) {
            return $this->fail('Unknown destination type.');
        }
        if ($ext === '') {
            return $this->fail('A destination is required.');
        }

        $domainUuid = $session['domain_uuid'];

        $row = QClick2talkLinks::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND destination_type = :type: AND destination_extension = :ext:',
            'bind'       => ['domain_uuid' => $domainUuid, 'type' => $type, 'ext' => $ext],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);

        if (!$row) {
            $destinationUuid = $this->destinationUuidFor($domainUuid, $type, $ext);
            if ($destinationUuid === null) {
                // The destination does not exist in this tenant. Refused rather
                // than minting a link to nothing.
                return $this->fail(self::REFUSAL);
            }
            $row = new QClick2talkLinks();
            $row->click2talk_uuid       = self::uuid4();
            $row->domain_uuid           = $domainUuid;
            $row->destination_type      = $type;
            $row->destination_uuid      = $destinationUuid;
            $row->destination_extension = $ext;
            $row->ask_customer_for      = '1';
            $row->share_contact_details = 1;
            $row->enabled               = 1;
            $row->insert_date           = date('Y-m-d H:i:s');
            $row->insert_user           = $session['user_uuid'] ?? null;
            if (!$row->create()) {
                // A concurrent first-open loses the unique-index race; re-read
                // rather than reporting an error for a row that now exists.
                $row = QClick2talkLinks::findFirst([
                    'conditions' => 'domain_uuid = :domain_uuid: AND destination_type = :type: AND destination_extension = :ext:',
                    'bind'       => ['domain_uuid' => $domainUuid, 'type' => $type, 'ext' => $ext],
                    'bindTypes'  => [
                        \Phalcon\Db\Column::BIND_PARAM_STR,
                        \Phalcon\Db\Column::BIND_PARAM_STR,
                        \Phalcon\Db\Column::BIND_PARAM_STR,
                    ],
                ]);
                if (!$row) {
                    return $this->fail('Could not create the Click2Talk link.');
                }
            }
        }

        // The public page reads this row, so admin changes must update it too.
        // No fields in the request means resolve-only (existing callers).
        if ($this->request->getPost('save', 'string', '') === '1') {
            $mode = (string) $this->request->getPost('askCustomerFor', 'string', '');
            $share = (string) $this->request->getPost('shareContactDetails', 'string', '');
            $text = (string) $this->request->getPost('buttonText', null, '');
            $color = (string) $this->request->getPost('buttonColor', 'string', '');
            if (!in_array($mode, ['1', '2', '3', '4'], true)
                || !in_array($share, ['0', '1'], true)
                || strlen($text) > 200
                || ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $color))) {
                return $this->fail('Invalid RingQ Talk settings.');
            }
            $row->ask_customer_for = $mode;
            $row->share_contact_details = (int) $share;
            // Queue panels do not expose styling: preserve omitted fields.
            if ($this->request->hasPost('buttonText')) $row->button_text = $text;
            if ($this->request->hasPost('buttonColor')) $row->button_color = $color;
            if (!$row->update()) {
                return $this->fail('Could not save RingQ Talk settings.');
            }
        }

        return $this->succeed([
            'id'                  => $row->click2talk_uuid,
            'askCustomerFor'      => Click2Talk::askCustomerFor($row->ask_customer_for),
            'shareContactDetails' => ((int) $row->share_contact_details) === 1,
            'buttonText' => $row->button_text ?? '',
            'buttonColor' => $row->button_color ?? '',
        ]);
    }

    /**
     * The destination's own uuid, or null when it is not this tenant's.
     */
    private function destinationUuidFor($domainUuid, $type, $ext)
    {
        if ($type === QClick2talkLinks::TYPE_QUEUE) {
            $queue = VCallCenterQueues::findFirst([
                'conditions' => 'domain_uuid = :domain_uuid: AND queue_extension = :ext:',
                'bind'       => ['domain_uuid' => $domainUuid, 'ext' => $ext],
                'bindTypes'  => [
                    \Phalcon\Db\Column::BIND_PARAM_STR,
                    \Phalcon\Db\Column::BIND_PARAM_STR,
                ],
            ]);
            return $queue ? $queue->call_center_queue_uuid : null;
        }
        $extension = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND (number_alias = :ext: OR extension = :ext:)',
            'bind'       => ['domain_uuid' => $domainUuid, 'ext' => $ext],
            'bindTypes'  => [
                \Phalcon\Db\Column::BIND_PARAM_STR,
                \Phalcon\Db\Column::BIND_PARAM_STR,
            ],
        ]);
        return $extension ? $extension->extension_uuid : null;
    }

    /** RFC 4122 v4, matching the uuids the rest of the schema stores. */
    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * id -> ['domain' => VDomains, 'row' => QClick2talkLinks, 'content' => array]
     *
     * Null for every refusal, so callers cannot accidentally leak which one.
     */
    private function resolveLink($id)
    {
        if (!is_string($id) || trim($id) === '' || strlen($id) > 128) {
            return null;
        }
        // The tenant comes from the request host, never from the request body:
        // an id on its own must not reach another tenant's row.
        $vDomain = Click2Talk::domainForHost($this->request->getHttpHost());
        if (!$vDomain) {
            return null;
        }
        $row = Click2Talk::link($id, $vDomain->domain_uuid);
        if (!$row) {
            // Fall back to a legacy identifier so links and embed snippets
            // already pasted into customer websites keep resolving. Tried
            // second, so a real link id always wins.
            $row = Click2Talk::legacyLink($id, $vDomain);
        }
        if (!$row) {
            return null;
        }
        $content = Click2Talk::describe($this->getDI(), $row, $vDomain);
        if ($content === null) {
            return null;
        }
        return ['domain' => $vDomain, 'row' => $row, 'content' => $content];
    }

    private function succeed(array $content)
    {
        $this->response->setContent(json_encode([
            'result'  => 'success',
            'message' => '',
            'content' => $content,
        ]));
        return $this->response;
    }

    private function refuse()
    {
        return $this->fail(self::REFUSAL);
    }

    private function fail($message)
    {
        $this->response->setContent(json_encode([
            'result'  => 'error',
            'message' => $message,
            'content' => [],
        ]));
        return $this->response;
    }
}
